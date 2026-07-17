<?php

namespace App\Services\Validation;

/**
 * Layer 3 of invoice validation: SK business rules over the canonical
 * invoice array, before the UBL document is even built. XSD (layer 1)
 * and schematron (layer 2) catch structural problems; this layer catches
 * the domain problems legacy exports actually have — bad VAT ids, wrong
 * VAT rates, missing Peppol fields — and explains them in Slovak.
 *
 * Like the mapper, it collects ALL errors at once so the client can fix
 * the export in a single pass.
 */
class BusinessValidator
{
    /**
     * Slovak VAT rates valid from 1. 1. 2025 (zákon 278/2024 Z. z.):
     * basic 23 %, reduced 19 % and 5 %. Revisit on every legislative change.
     */
    private const SK_VAT_RATES = ['23', '19', '5'];

    /**
     * UNCL5305 categories the pipeline understands. Categories that mean
     * "no VAT charged" must come with a zero rate on the line.
     */
    private const VAT_CATEGORIES = ['S', 'Z', 'E', 'AE', 'K', 'G', 'O', 'L', 'M'];
    private const ZERO_RATE_CATEGORIES = ['Z', 'E', 'AE', 'K', 'G', 'O'];

    /**
     * @return list<string> Slovak error messages; empty array means the invoice passes
     */
    public function validate(array $invoice): array
    {
        $errors = [];

        $this->checkType($invoice, $errors);
        $this->checkDates($invoice, $errors);
        $this->checkCurrency($invoice, $errors);
        $this->checkVatCurrency($invoice, $errors);
        $this->checkPrepaidAmount($invoice, $errors);
        $this->checkBuyerReference($invoice, $errors);
        $this->checkParty($invoice['supplier'] ?? [], 'dodávateľa', $errors);
        $this->checkParty($invoice['customer'] ?? [], 'odberateľa', $errors);
        $this->checkLines($invoice, $errors);

        return $errors;
    }

    private function checkType(array $invoice, array &$errors): void
    {
        $type = $invoice['type'] ?? 'invoice';
        if (!in_array($type, ['invoice', 'credit_note'], true)) {
            $errors[] = "Typ dokladu '{$type}' nie je podporovaný — očakáva sa 'invoice' (faktúra) alebo 'credit_note' (dobropis).";
        }
    }

    private function checkDates(array $invoice, array &$errors): void
    {
        $issueDate = $invoice['issue_date'] ?? null;
        if (!$this->isValidDate($issueDate)) {
            $errors[] = "Dátum vystavenia '{$issueDate}' nie je platný dátum vo formáte RRRR-MM-DD.";
        }

        $dueDate = $invoice['due_date'] ?? null;
        if ($dueDate === null || $dueDate === '') {
            return;
        }
        if (!$this->isValidDate($dueDate)) {
            $errors[] = "Dátum splatnosti '{$dueDate}' nie je platný dátum vo formáte RRRR-MM-DD.";
        } elseif ($this->isValidDate($issueDate) && $dueDate < $issueDate) {
            $errors[] = "Dátum splatnosti ({$dueDate}) nemôže byť skôr než dátum vystavenia ({$issueDate}).";
        }
    }

    private function checkCurrency(array $invoice, array &$errors): void
    {
        $currency = $invoice['currency'] ?? '';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = "Mena '{$currency}' nie je platný ISO 4217 kód — očakáva sa napr. 'EUR'.";
        }
    }

    /**
     * SK VAT act: the tax must be stated in EUR even when the invoice is
     * issued in a foreign currency — a Slovak supplier invoicing in another
     * currency needs vat_currency EUR plus a conversion rate (BT-6/BR-53).
     */
    private function checkVatCurrency(array $invoice, array &$errors): void
    {
        $currency = $invoice['currency'] ?? '';
        $vatCurrency = $invoice['vat_currency'] ?? null;
        $supplierIsSk = ($invoice['supplier']['country'] ?? null) === 'SK';

        if ($supplierIsSk
            && preg_match('/^[A-Z]{3}$/', $currency)
            && $currency !== 'EUR'
            && $vatCurrency !== 'EUR'
        ) {
            $errors[] = "Faktúra v mene {$currency} od slovenského dodávateľa musí uvádzať DPH aj v eurách "
                ."— doplňte menu DPH (vat_currency) 'EUR' a prepočítací kurz (vat_exchange_rate).";
        }

        if ($vatCurrency !== null && $vatCurrency !== '' && $vatCurrency !== $currency) {
            $rate = $invoice['vat_exchange_rate'] ?? null;
            if (!is_numeric($rate) || (float) $rate <= 0) {
                $errors[] = "Pri mene DPH '{$vatCurrency}' chýba platný prepočítací kurz (vat_exchange_rate) "
                    .'— kladné číslo, ktorým sa suma vo fakturačnej mene prepočíta na menu DPH.';
            }
        }
    }

    private function checkPrepaidAmount(array $invoice, array &$errors): void
    {
        $prepaid = $invoice['prepaid_amount'] ?? null;
        if ($prepaid === null || $prepaid === '') {
            return;
        }

        if (!is_numeric($prepaid) || (float) $prepaid < 0) {
            $errors[] = "Odpočítaná záloha '{$prepaid}' (prepaid_amount) nie je platná suma — očakáva sa nezáporné číslo.";
        }
    }

    private function checkBuyerReference(array $invoice, array &$errors): void
    {
        if (empty($invoice['buyer_reference'])) {
            $errors[] = 'Chýba referencia odberateľa (buyer_reference) — Peppol ju vyžaduje, '
                .'zvyčajne číslo objednávky alebo dohodnutý identifikátor.';
        }
    }

    private function checkParty(array $party, string $label, array &$errors): void
    {
        $peppolId = $party['peppol_id'] ?? null;
        if ($peppolId === null || $peppolId === '') {
            $errors[] = "Chýba Peppol ID {$label} (peppol_id) — elektronická adresa v tvare 'schéma:hodnota', napr. '0245:0000000001'.";
        } elseif (!preg_match('/^\d{4}:\S+$/', $peppolId)) {
            $errors[] = "Peppol ID {$label} '{$peppolId}' nemá tvar 'schéma:hodnota' (schéma sú 4 číslice, napr. '0245:0000000001').";
        }

        $country = $party['country'] ?? '';
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $errors[] = "Krajina {$label} '{$country}' nie je platný ISO 3166 kód — očakáva sa napr. 'SK'.";
        }

        $vatId = $party['vat_id'] ?? null;
        if ($vatId !== null && $vatId !== '') {
            $this->checkVatId($vatId, $label, $errors);
        }

        $companyId = $party['company_id'] ?? null;
        if ($companyId !== null && $companyId !== '' && $country === 'SK' && !preg_match('/^\d{8}$/', $companyId)) {
            $errors[] = "IČO {$label} '{$companyId}' nemá platný tvar — slovenské IČO je 8 číslic.";
        }
    }

    private function checkVatId(string $vatId, string $label, array &$errors): void
    {
        if (str_starts_with($vatId, 'SK')) {
            $digits = substr($vatId, 2);
            // SK IČ DPH: exactly 10 digits and the number is divisible by 11.
            if (!preg_match('/^\d{10}$/', $digits) || (int) $digits % 11 !== 0) {
                $errors[] = "IČ DPH {$label} '{$vatId}' nie je platné slovenské IČ DPH "
                    ."(SK + 10 číslic deliteľných 11) — skontrolujte preklep.";
            }

            return;
        }

        if (!preg_match('/^[A-Z]{2}[0-9A-Z+*.]{2,12}$/', $vatId)) {
            $errors[] = "IČ DPH {$label} '{$vatId}' nemá platný tvar — očakáva sa kód krajiny a 2–12 znakov, napr. 'CZ12345678'.";
        }
    }

    private function checkLines(array $invoice, array &$errors): void
    {
        $lines = $invoice['lines'] ?? [];
        if ($lines === []) {
            $errors[] = 'Faktúra neobsahuje žiadne položky.';

            return;
        }

        $supplierIsSk = ($invoice['supplier']['country'] ?? null) === 'SK';
        $hasStandardRatedLine = false;

        foreach (array_values($lines) as $index => $line) {
            $position = 'položka '.($index + 1)
                .(isset($line['name']) ? " „{$line['name']}“" : '');

            foreach (['quantity' => 'Množstvo', 'unit_price' => 'Jednotková cena'] as $field => $fieldLabel) {
                $value = $line[$field] ?? '';
                if (!is_numeric($value)) {
                    $errors[] = "{$fieldLabel} '{$value}' nie je číslo ({$position}).";
                }
            }

            $rate = $line['vat_rate'] ?? '';
            if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                $errors[] = "Sadzba DPH '{$rate}' nie je platné percento 0–100 ({$position}).";
                continue;
            }

            $category = $line['vat_category'] ?? 'S';
            if (!in_array($category, self::VAT_CATEGORIES, true)) {
                $errors[] = "Kategória DPH '{$category}' nie je platný kód — podporované sú "
                    .implode(', ', self::VAT_CATEGORIES)." ({$position}).";
                continue;
            }

            if ($category === 'S') {
                $hasStandardRatedLine = true;
                if ((float) $rate === 0.0) {
                    $errors[] = "Kategória DPH 'S' (štandardná) vyžaduje nenulovú sadzbu — pre 0 % použite kategóriu 'Z', 'E' alebo 'AE' ({$position}).";
                } elseif ($supplierIsSk && !$this->isAllowedSkRate($rate)) {
                    $errors[] = "Sadzba DPH {$rate} % neplatí na Slovensku — platné sadzby sú "
                        .implode(' %, ', self::SK_VAT_RATES).' % ('.$position.').';
                }
            } elseif (in_array($category, self::ZERO_RATE_CATEGORIES, true) && (float) $rate !== 0.0) {
                $errors[] = "Kategória DPH '{$category}' vyžaduje sadzbu 0 %, uvedená je {$rate} % ({$position}).";
            }

            // BR-E-10: exemption needs a stated reason. Reverse charge (AE)
            // gets a default reason in the builder, category E must say why.
            if ($category === 'E' && empty($line['vat_exemption_reason'])) {
                $errors[] = "Kategória DPH 'E' (oslobodené) vyžaduje dôvod oslobodenia (vat_exemption_reason) — napr. '§ 39 zákona o DPH' ({$position}).";
            }
        }

        // BR-S-02: an invoice with standard-rated lines needs the seller's VAT id.
        if ($hasStandardRatedLine && empty($invoice['supplier']['vat_id'])) {
            $errors[] = 'Faktúra účtuje DPH, ale dodávateľ nemá vyplnené IČ DPH (supplier.vat_id).';
        }
    }

    private function isAllowedSkRate(string|int|float $rate): bool
    {
        foreach (self::SK_VAT_RATES as $allowed) {
            if ((float) $rate === (float) $allowed) {
                return true;
            }
        }

        return false;
    }

    private function isValidDate(?string $value): bool
    {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
