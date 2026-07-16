<?php

namespace Tests\Feature;

use App\Services\Postar\EpostakConnectorAdapter;
use App\Services\Postar\PostarException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EpostakConnectorAdapterTest extends TestCase
{
    private array $config = [
        'base_url' => 'https://dev.epostak.sk/api/v1',
        'client_id' => 'demo-client',
        'client_secret' => 'demo-secret',
        'firm_id' => 'firm-123',
        'timeout' => 30,
    ];

    private function adapter(HttpFactory $http): EpostakConnectorAdapter
    {
        return new EpostakConnectorAdapter($http, Cache::store('array'), $this->config);
    }

    public function test_send_invoice_authenticates_then_posts_ubl(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/auth/token' => $http->response([
                'access_token' => 'tok-abc',
                'token_type' => 'Bearer',
                'expires_in' => 900,
            ]),
            '*/documents/send' => $http->response([
                'documentId' => 'doc-1',
                'messageId' => 'msg-1',
                'status' => 'SENT',
                'payloadSha256' => 'abc123',
            ]),
        ]);

        $result = $this->adapter($http)->sendInvoice('<Invoice/>', '0245:0000000002', 'idem-key-1');

        $this->assertSame('doc-1', $result->documentId);
        $this->assertSame('msg-1', $result->messageId);
        $this->assertSame('SENT', $result->status);

        $http->assertSent(function (Request $request) {
            if (!str_ends_with($request->url(), '/documents/send')) {
                return false;
            }

            return $request['receiverPeppolId'] === '0245:0000000002'
                && $request['xml'] === '<Invoice/>'
                && $request->hasHeader('Authorization', 'Bearer tok-abc')
                && $request->hasHeader('Idempotency-Key', 'idem-key-1')
                && $request->hasHeader('X-Firm-Id', 'firm-123');
        });
    }

    public function test_access_token_is_cached_across_calls(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/auth/token' => $http->response(['access_token' => 'tok-abc', 'expires_in' => 900]),
            '*/documents/*/status' => $http->response(['status' => 'DELIVERED', 'deliveredAt' => '2026-07-16T10:00:00Z']),
        ]);

        $adapter = $this->adapter($http);
        $adapter->getStatus('doc-1');
        $adapter->getStatus('doc-2');

        // Only one token request despite two API calls.
        $tokenCalls = 0;
        $http->assertSent(function (Request $request) use (&$tokenCalls) {
            if (str_ends_with($request->url(), '/auth/token')) {
                $tokenCalls++;
            }

            return true;
        });
        $this->assertSame(1, $tokenCalls);
    }

    public function test_get_status_maps_response(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/auth/token' => $http->response(['access_token' => 'tok-abc', 'expires_in' => 900]),
            '*/documents/doc-9/status' => $http->response([
                'status' => 'DELIVERED',
                'messageId' => 'msg-9',
                'deliveredAt' => '2026-07-16T10:00:00Z',
            ]),
        ]);

        $status = $this->adapter($http)->getStatus('doc-9');

        $this->assertTrue($status->isDelivered());
        $this->assertFalse($status->isRejected());
        $this->assertSame('msg-9', $status->messageId);
    }

    public function test_auth_failure_raises_slovak_postar_exception(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/auth/token' => $http->response([
                'error' => [
                    'category' => 'AUTH',
                    'code' => 'SAPI-AUTH-001',
                    'message' => 'Invalid client credentials',
                    'retryable' => false,
                ],
            ], 401),
        ]);

        try {
            $this->adapter($http)->sendInvoice('<Invoice/>', '0245:0000000002', 'idem-key-1');
            $this->fail('Očakávala sa PostarException.');
        } catch (PostarException $exception) {
            $this->assertStringContainsString('Prihlásenie k poštárovi zlyhalo', $exception->getMessage());
            $this->assertStringContainsString('SAPI-AUTH-001', $exception->getMessage());
            $this->assertSame('SAPI-AUTH-001', $exception->providerCode);
            $this->assertFalse($exception->retryable);
            $this->assertSame(401, $exception->httpStatus);
        }
    }

    public function test_validation_error_on_send_is_reported(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/auth/token' => $http->response(['access_token' => 'tok-abc', 'expires_in' => 900]),
            '*/documents/send' => $http->response([
                'error' => ['code' => 'UBL_VALIDATION_ERROR', 'message' => 'BR-CO-10 failed', 'retryable' => false],
            ], 422),
        ]);

        $this->expectException(PostarException::class);
        $this->expectExceptionMessage('UBL_VALIDATION_ERROR');
        $this->adapter($http)->sendInvoice('<Invoice/>', '0245:0000000002', 'idem-key-1');
    }

    public function test_server_error_is_retryable(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/auth/token' => $http->response(['access_token' => 'tok-abc', 'expires_in' => 900]),
            '*/documents/send' => $http->response(['error' => ['code' => 'SERVICE_UNAVAILABLE']], 503),
        ]);

        try {
            $this->adapter($http)->sendInvoice('<Invoice/>', '0245:0000000002', 'idem-key-1');
            $this->fail('Očakávala sa PostarException.');
        } catch (PostarException $exception) {
            $this->assertTrue($exception->retryable);
        }
    }

    public function test_missing_credentials_raises_config_error(): void
    {
        $http = new HttpFactory();
        $http->fake();

        $adapter = new EpostakConnectorAdapter($http, Cache::store('array'), [
            'base_url' => 'https://dev.epostak.sk/api/v1',
            'client_id' => null,
            'client_secret' => null,
        ]);

        $this->expectException(PostarException::class);
        $this->expectExceptionMessage('Chýba konfigurácia poštára');
        $adapter->getStatus('doc-1');
    }
}
