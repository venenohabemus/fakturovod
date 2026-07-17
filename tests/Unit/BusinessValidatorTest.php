<?php

namespace Tests\Unit;

use App\Services\Validation\BusinessValidator;
use PHPUnit\Framework\TestCase;

class BusinessValidatorTest extends TestCase
{
    private BusinessValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new BusinessValidator();
    }

    /**
     * A canonical invoice that passes every rule; tests override pieces.
     */
    private function validInvoice(array $overrides = []): array
    {
        return array_replace_recursive([
            'number' => 'FA-2026-0001',
            'issue_date' => '2026-07-15',
            'due_date' => '2026-07-29',
            'currency' => 'EUR',
            'buyer_reference' => 'OBJ-123',
            'supplier' => [
                'name' => 'Dodávateľ s.r.o.',
                'peppol_id' => '0245:0000000001',
                'country' => 'SK',
                'company_id' => '12345678',
                'vat_id' => 'SK2020123457',
            ],
            'customer' => [
                'name' => 'Odberateľ a.s.',
                'peppol_id' => '0245:0000000002',
                'country' => 'SK',
                'vat_id' => 'SK2020654328',
            ],
            'lines' => [
                ['name' => 'Konzultácie', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '23'],
            ],
        ], $overrides);
    }

    public function test_valid_invoice_passes(): void
    {
        $this->assertSame([], $this->validator->validate($this->validInvoice()));
    }

    public function test_invalid_issue_date_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['issue_date' => '2026-02-30']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Dátum vystavenia', $errors[0]);
    }

    public function test_due_date_before_issue_date_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['due_date' => '2026-07-01']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('nemôže byť skôr', $errors[0]);
    }

    public function test_invalid_currency_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['currency' => 'eur']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('ISO 4217', $errors[0]);
    }

    public function test_foreign_currency_invoice_from_sk_supplier_requires_eur_vat_currency(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['currency' => 'CZK']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('musí uvádzať DPH aj v eurách', $errors[0]);
    }

    public function test_foreign_currency_with_eur_vat_currency_and_rate_passes(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'currency' => 'CZK',
            'vat_currency' => 'EUR',
            'vat_exchange_rate' => '0.0397',
        ]));

        $this->assertSame([], $errors);
    }

    public function test_vat_currency_without_exchange_rate_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'currency' => 'CZK',
            'vat_currency' => 'EUR',
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('prepočítací kurz', $errors[0]);
    }

    public function test_negative_prepaid_amount_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['prepaid_amount' => '-10']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('záloha', $errors[0]);
    }

    public function test_valid_prepaid_amount_passes(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate($this->validInvoice(['prepaid_amount' => '150.00']))
        );
    }

    public function test_missing_buyer_reference_is_reported(): void
    {
        $invoice = $this->validInvoice();
        unset($invoice['buyer_reference']);

        $errors = $this->validator->validate($invoice);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('buyer_reference', $errors[0]);
    }

    public function test_missing_and_malformed_peppol_ids_are_reported(): void
    {
        $invoice = $this->validInvoice(['customer' => ['peppol_id' => 'bez-schemy']]);
        unset($invoice['supplier']['peppol_id']);

        $errors = $this->validator->validate($invoice);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Chýba Peppol ID dodávateľa', $errors[0]);
        $this->assertStringContainsString("'bez-schemy'", $errors[1]);
    }

    public function test_sk_vat_id_must_be_divisible_by_eleven(): void
    {
        // 2020123456 % 11 === 10 — a realistic single-digit typo.
        $errors = $this->validator->validate($this->validInvoice([
            'supplier' => ['vat_id' => 'SK2020123456'],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('IČ DPH dodávateľa', $errors[0]);
        $this->assertStringContainsString('preklep', $errors[0]);
    }

    public function test_foreign_vat_id_checks_shape_only(): void
    {
        $valid = $this->validator->validate($this->validInvoice([
            'customer' => ['vat_id' => 'CZ12345678', 'country' => 'CZ'],
        ]));
        $this->assertSame([], $valid);

        $invalid = $this->validator->validate($this->validInvoice([
            'customer' => ['vat_id' => 'C', 'country' => 'CZ'],
        ]));
        $this->assertCount(1, $invalid);
        $this->assertStringContainsString('IČ DPH odberateľa', $invalid[0]);
    }

    public function test_sk_company_id_must_be_eight_digits(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'supplier' => ['company_id' => '1234'],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('IČO dodávateľa', $errors[0]);
    }

    public function test_foreign_company_id_is_not_checked(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'customer' => ['company_id' => 'HRB-9911', 'country' => 'DE', 'vat_id' => 'DE123456789'],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_invalid_sk_vat_rate_is_reported_with_line_position(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                ['name' => 'Konzultácie', 'quantity' => '10', 'unit_price' => '50.00', 'vat_rate' => '23'],
                ['name' => 'Tovar', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '20'],
            ],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('20 % neplatí na Slovensku', $errors[0]);
        $this->assertStringContainsString('položka 2', $errors[0]);
    }

    public function test_foreign_supplier_may_use_other_rates(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'supplier' => ['country' => 'CZ', 'vat_id' => 'CZ12345678'],
            'lines' => [
                ['name' => 'Zboží', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '21'],
            ],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_standard_category_with_zero_rate_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                ['name' => 'Služba', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '0'],
            ],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Kategória DPH 'S'", $errors[0]);
    }

    public function test_zero_rate_category_with_nonzero_rate_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                ['name' => 'Vývoz', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '23', 'vat_category' => 'AE'],
            ],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Kategória DPH 'AE'", $errors[0]);
    }

    public function test_unknown_vat_category_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                ['name' => 'X', 'quantity' => '1', 'unit_price' => '10.00', 'vat_rate' => '23', 'vat_category' => 'Q'],
            ],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Kategória DPH 'Q'", $errors[0]);
    }

    public function test_supplier_without_vat_id_cannot_charge_vat(): void
    {
        $invoice = $this->validInvoice();
        unset($invoice['supplier']['vat_id']);

        $errors = $this->validator->validate($invoice);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('supplier.vat_id', $errors[0]);
    }

    public function test_non_numeric_amounts_are_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                ['name' => 'X', 'quantity' => 'veľa', 'unit_price' => '10,50', 'vat_rate' => '23'],
            ],
        ]));

        $this->assertCount(2, $errors);
        $this->assertStringContainsString("Množstvo 'veľa'", $errors[0]);
        $this->assertStringContainsString("Jednotková cena '10,50'", $errors[1]);
    }

    public function test_empty_lines_are_reported(): void
    {
        $invoice = $this->validInvoice();
        $invoice['lines'] = [];

        $errors = $this->validator->validate($invoice);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('žiadne položky', $errors[0]);
    }

    public function test_unknown_document_type_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['type' => 'proforma']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("Typ dokladu 'proforma'", $errors[0]);
    }

    public function test_credit_note_type_is_accepted(): void
    {
        $errors = $this->validator->validate($this->validInvoice(['type' => 'credit_note']));

        $this->assertSame([], $errors);
    }

    public function test_exempt_line_without_reason_is_reported(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                ['name' => 'Poistenie', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0', 'vat_category' => 'E'],
            ],
        ]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('vyžaduje dôvod oslobodenia', $errors[0]);
    }

    public function test_exempt_line_with_reason_passes(): void
    {
        $errors = $this->validator->validate($this->validInvoice([
            'lines' => [
                [
                    'name' => 'Poistenie',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'vat_rate' => '0',
                    'vat_category' => 'E',
                    'vat_exemption_reason' => '§ 37 zákona o DPH',
                ],
            ],
        ]));

        $this->assertSame([], $errors);
    }

    public function test_all_errors_are_collected_at_once(): void
    {
        $errors = $this->validator->validate([
            'issue_date' => 'niekedy',
            'currency' => '€',
            'supplier' => ['name' => 'A', 'country' => 'Slovensko', 'vat_id' => 'SK123'],
            'customer' => ['name' => 'B', 'country' => 'SK'],
            'lines' => [
                ['name' => 'X', 'quantity' => '?', 'unit_price' => '1', 'vat_rate' => '999'],
            ],
        ]);

        // dátum, mena, buyer_reference, 2× peppol_id, krajina dodávateľa,
        // IČ DPH dodávateľa, množstvo, sadzba — everything in one pass
        $this->assertGreaterThanOrEqual(8, count($errors));
    }
}
