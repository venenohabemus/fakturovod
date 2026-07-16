<?php

namespace App\Services\Postar;

/**
 * Delivery status of a previously sent document as reported by the poštár.
 */
class DeliveryStatus
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $sentAt = null,
        public readonly ?string $deliveredAt = null,
        public readonly ?string $invoiceResponseStatus = null,
    ) {
    }

    public function isDelivered(): bool
    {
        return strtoupper($this->status) === 'DELIVERED';
    }

    public function isRejected(): bool
    {
        return strtoupper($this->status) === 'REJECTED';
    }

    public static function fromResponse(string $documentId, array $body): self
    {
        return new self(
            documentId: $documentId,
            status: (string) ($body['status'] ?? 'UNKNOWN'),
            messageId: isset($body['messageId']) ? (string) $body['messageId'] : null,
            sentAt: $body['sentAt'] ?? null,
            deliveredAt: $body['deliveredAt'] ?? null,
            invoiceResponseStatus: $body['invoiceResponseStatus'] ?? null,
        );
    }
}
