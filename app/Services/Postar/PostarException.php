<?php

namespace App\Services\Postar;

use RuntimeException;
use Throwable;

/**
 * A poštár (digital postman) call failed. Carries the provider's error code
 * and whether a retry might succeed, so the pipeline can decide between
 * dead-lettering and backoff. Messages are Slovak — they reach the error queue.
 */
class PostarException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
