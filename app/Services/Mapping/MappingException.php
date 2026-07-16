<?php

namespace App\Services\Mapping;

use RuntimeException;

/**
 * Mapping/ingest failure. Messages are user-facing and therefore Slovak —
 * they end up in the error queue shown to clients.
 */
class MappingException extends RuntimeException
{
    /**
     * All collected error messages. The error queue shows every problem in
     * the invoice at once, so the client fixes the file in one pass.
     *
     * @var list<string>
     */
    public readonly array $errors;

    /**
     * @param list<string> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors === [] ? [$message] : $errors;
    }

    /**
     * @param non-empty-list<string> $errors
     */
    public static function withErrors(array $errors): self
    {
        $count = count($errors);
        $message = $count === 1
            ? $errors[0]
            : sprintf('Faktúra obsahuje %d %s. Prvá: %s', $count, self::pluralizeErrors($count), $errors[0]);

        return new self($message, $errors);
    }

    /**
     * Slovak plural of "chyba": 1 chyba, 2–4 chyby, 5+ chýb.
     */
    private static function pluralizeErrors(int $count): string
    {
        return match (true) {
            $count === 1 => 'chybu',
            $count >= 2 && $count <= 4 => 'chyby',
            default => 'chýb',
        };
    }
}
