<?php

namespace App\Services\Postar;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * ePošťák (Kaja Solutions) adapter over the Enterprise/Connector API.
 *
 * Auth is OAuth 2.0 client-credentials; access tokens live 15 minutes and are
 * cached so we don't hit the token rate limit. Integrator (sk_int_*) tokens
 * address the managed firm via the X-Firm-Id header.
 *
 * @see https://epostak.sk/api/docs/enterprise
 */
class EpostakConnectorAdapter implements PostarAdapterInterface
{
    private const TOKEN_CACHE_KEY = 'postar:epostak:access_token';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {
    }

    public function sendInvoice(string $ublXml, string $receiverPeppolId, string $idempotencyKey): SendResult
    {
        $response = $this->request()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post('/documents/send', [
                'receiverPeppolId' => $receiverPeppolId,
                'xml' => $ublXml,
            ]);

        return SendResult::fromResponse($this->body($response, 'Odoslanie faktúry zlyhalo'));
    }

    public function getStatus(string $documentId): DeliveryStatus
    {
        $response = $this->request()->get("/documents/{$documentId}/status");

        return DeliveryStatus::fromResponse(
            $documentId,
            $this->body($response, 'Zistenie stavu dokumentu zlyhalo')
        );
    }

    /**
     * Builds an authenticated request with the base URL, firm header and timeout.
     */
    private function request(): PendingRequest
    {
        $request = $this->http
            ->baseUrl(rtrim($this->config['base_url'], '/'))
            ->timeout($this->config['timeout'] ?? 30)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->asJson();

        if (!empty($this->config['firm_id'])) {
            $request = $request->withHeader('X-Firm-Id', $this->config['firm_id']);
        }

        return $request;
    }

    /**
     * Returns a cached access token, fetching a new one when needed.
     */
    private function accessToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        foreach (['client_id', 'client_secret', 'base_url'] as $required) {
            if (empty($this->config[$required])) {
                throw new PostarException(
                    "Chýba konfigurácia poštára '{$required}' — nastavte EPOSTAK_* premenné v .env.",
                    providerCode: 'MISSING_CONFIG',
                );
            }
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim($this->config['base_url'], '/'))
                ->timeout($this->config['timeout'] ?? 30)
                ->asJson()
                ->acceptJson()
                ->post('/auth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                ]);
        } catch (ConnectionException $exception) {
            throw new PostarException(
                'Nepodarilo sa spojiť so serverom poštára.',
                providerCode: 'CONNECTION_FAILED',
                retryable: true,
                previous: $exception,
            );
        }

        $body = $this->body($response, 'Prihlásenie k poštárovi zlyhalo');

        $token = $body['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new PostarException(
                'Poštár nevrátil prístupový token.',
                providerCode: 'MISSING_ACCESS_TOKEN',
            );
        }

        // Refresh a minute before the stated expiry to avoid using a token
        // that dies mid-flight.
        $ttl = max(60, (int) ($body['expires_in'] ?? 900) - 60);
        $this->cache->put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Decodes a successful JSON response or maps the poštár's error envelope
     * to a PostarException with a Slovak message.
     */
    private function body(Response $response, string $context): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error = $response->json('error');
        $code = is_array($error) ? ($error['code'] ?? null) : null;
        $providerMessage = is_array($error) ? ($error['message'] ?? null) : null;
        $retryable = is_array($error)
            ? (bool) ($error['retryable'] ?? $response->serverError())
            : $response->serverError();

        $message = $context.'.'
            .($code !== null ? " Kód: {$code}." : '')
            .($providerMessage !== null ? " Detail: {$providerMessage}." : '')
            .' (HTTP '.$response->status().')';

        throw new PostarException(
            $message,
            providerCode: $code,
            retryable: $retryable,
            httpStatus: $response->status(),
        );
    }
}
