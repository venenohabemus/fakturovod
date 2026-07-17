<?php

namespace App\Services\Validation;

use RuntimeException;

/**
 * The schematron sidecar could not deliver a verdict (down, misconfigured,
 * unexpected response). Distinct from a rule violation — the pipeline
 * treats this as "skip the layer", never as "invoice invalid".
 */
class SchematronUnavailableException extends RuntimeException
{
}
