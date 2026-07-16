<?php

namespace App\Services\Mapping;

use RuntimeException;

/**
 * Mapping/ingest failure. Messages are user-facing and therefore Slovak —
 * they end up in the error queue shown to clients.
 */
class MappingException extends RuntimeException
{
}
