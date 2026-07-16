<?php

namespace App\Services\Postar;

/**
 * Delivery status of a previously sent document as reported by the poštár.
 */
class DeliveryStatus
{
    /**
     * @param list<string> $validationErrors provider-reported Peppol/EN 16931 rule failures
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $status,
        public readonly ?string $messageId = null,
        public readonly ?string $sentAt = null,
        public readonly ?string $deliveredAt = null,
        public readonly ?string $invoiceResponseStatus = null,
        public readonly array $validationErrors = [],
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
        $validationErrors = [];
        foreach ($body['validationResult']['errors'] ?? [] as $error) {
            $validationErrors[] = trim(
                ($error['message'] ?? 'Neznáma validačná chyba')
                .(isset($error['location']) ? ' ['.$error['location'].']' : '')
            );
        }

        return new self(
            documentId: $documentId,
            status: (string) ($body['status'] ?? 'UNKNOWN'),
            messageId: $body['messageId'] ?? $body['peppolMessageId'] ?? null,
            sentAt: $body['sentAt'] ?? null,
            deliveredAt: $body['deliveredAt'] ?? null,
            invoiceResponseStatus: $body['invoiceResponseStatus'] ?? null,
            validationErrors: $validationErrors,
        );
    }
}
