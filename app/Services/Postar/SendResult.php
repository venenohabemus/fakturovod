<?php

namespace App\Services\Postar;

/**
 * Outcome of handing a document to the poštár for delivery.
 */
class SendResult
{
    public function __construct(
        public readonly string $documentId,
        public readonly ?string $messageId,
        public readonly string $status,
        public readonly ?string $payloadSha256 = null,
    ) {
    }

    public static function fromResponse(array $body): self
    {
        $documentId = $body['documentId'] ?? $body['submissionId'] ?? null;
        if ($documentId === null) {
            throw new PostarException(
                'Poštár nevrátil identifikátor dokumentu (documentId).',
                providerCode: 'MISSING_DOCUMENT_ID',
            );
        }

        return new self(
            documentId: (string) $documentId,
            messageId: isset($body['messageId']) ? (string) $body['messageId'] : null,
            status: (string) ($body['status'] ?? 'SENT'),
            payloadSha256: $body['payloadSha256'] ?? null,
        );
    }
}
