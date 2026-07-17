<?php

namespace App\Services\Validation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Layer 2 of invoice validation: EN 16931 CEN schematron + Peppol BIS
 * Billing 3.0 rules, executed by the KoSIT validator running as an HTTP
 * sidecar (`php artisan schematron:serve`).
 *
 * The sidecar is a soft dependency: when it is down, the caller decides
 * what to do (the pipeline records a note and lets the poštár validate
 * instead — errors then arrive later and in English, but nothing breaks).
 */
class SchematronValidator
{
    public const REPORT_NS = 'http://www.xoev.de/de/validator/varl/1';

    /**
     * The layer is off entirely when no sidecar url is configured.
     */
    public function enabled(): bool
    {
        return !empty(config('schematron.url'));
    }

    /**
     * @return list<string> rule violations (empty = document accepted)
     *
     * @throws SchematronUnavailableException when the sidecar cannot be reached
     */
    public function validate(string $xml): array
    {
        $url = config('schematron.url');
        if (empty($url)) {
            throw new SchematronUnavailableException('Schematron validácia je vypnutá (SCHEMATRON_URL).');
        }

        try {
            $response = Http::timeout((int) config('schematron.timeout', 30))
                ->withBody($xml, 'application/xml')
                ->post($url);
        } catch (ConnectionException $exception) {
            throw new SchematronUnavailableException(
                'Schematron služba nie je dostupná: '.$exception->getMessage()
            );
        }

        // 200 = accepted, 406 = rejected with a report; anything else is a
        // sidecar problem, not a verdict about the invoice.
        if (!in_array($response->status(), [200, 406], true)) {
            throw new SchematronUnavailableException(
                "Schematron služba vrátila neočakávanú odpoveď (HTTP {$response->status()})."
            );
        }

        return $this->extractErrors($response->body());
    }

    /**
     * Pulls rule violations with level error/fatal out of the VARL report.
     *
     * @return list<string>
     */
    private function extractErrors(string $reportXml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($reportXml)) {
                throw new SchematronUnavailableException('Schematron report sa nepodarilo prečítať.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $errors = [];
        foreach ($document->getElementsByTagNameNS(self::REPORT_NS, 'message') as $message) {
            $level = $message->getAttribute('level');
            if (!in_array($level, ['error', 'fatal'], true)) {
                continue; // warnings stay out of the error queue
            }

            $code = $message->getAttribute('code');
            $text = trim($message->textContent);

            // The message usually already starts with "[CODE]-…" — avoid doubling it.
            $errors[] = $code !== '' && !str_contains($text, $code)
                ? "[{$code}] {$text}"
                : $text;
        }

        return $errors;
    }
}
