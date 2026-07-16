<?php

namespace Tests\Support;

use App\Services\Postar\DeliveryStatus;
use App\Services\Postar\PostarAdapterInterface;
use App\Services\Postar\PostarException;
use App\Services\Postar\SendResult;

/**
 * Configurable in-memory poštár for pipeline tests.
 */
class FakePostarAdapter implements PostarAdapterInterface
{
    /** @var list<array{xml: string, receiver: string, idempotencyKey: string}> */
    public array $sent = [];

    public bool $failSend = false;

    public string $statusToReturn = 'delivered';

    /** @var list<string> */
    public array $validationErrors = [];

    public function sendInvoice(string $ublXml, string $receiverPeppolId, string $idempotencyKey): SendResult
    {
        if ($this->failSend) {
            throw new PostarException('Simulovaný výpadok poštára.', providerCode: 'TEST_DOWN', retryable: true);
        }

        $this->sent[] = ['xml' => $ublXml, 'receiver' => $receiverPeppolId, 'idempotencyKey' => $idempotencyKey];

        return new SendResult('doc-'.count($this->sent), 'msg-'.count($this->sent), 'SENT');
    }

    public function getStatus(string $documentId): DeliveryStatus
    {
        return new DeliveryStatus(
            documentId: $documentId,
            status: $this->statusToReturn,
            validationErrors: $this->validationErrors,
        );
    }
}
