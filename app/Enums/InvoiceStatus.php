<?php

namespace App\Enums;

/**
 * Invoice lifecycle per the project brief:
 *
 *   received → mapped → validated → queued → sent → delivered | rejected
 *
 * plus a `failed` branch reachable from every processing step. `failed`
 * and `rejected` may transition back to `received` (manual retry after
 * the data or mapping is fixed).
 */
enum InvoiceStatus: string
{
    case Received = 'received';
    case Mapped = 'mapped';
    case Validated = 'validated';
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
    case Failed = 'failed';

    /**
     * @return list<InvoiceStatus> statuses this one may transition into
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::Mapped, self::Failed],
            self::Mapped => [self::Validated, self::Failed],
            self::Validated => [self::Queued, self::Failed],
            self::Queued => [self::Sent, self::Failed],
            self::Sent => [self::Delivered, self::Rejected, self::Failed],
            self::Delivered => [],
            self::Rejected => [self::Received],
            self::Failed => [self::Received],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Slovak label shown in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Prijatá',
            self::Mapped => 'Namapovaná',
            self::Validated => 'Zvalidovaná',
            self::Queued => 'V rade',
            self::Sent => 'Odoslaná',
            self::Delivered => 'Doručená',
            self::Rejected => 'Odmietnutá',
            self::Failed => 'Chybná',
        };
    }

    /**
     * Badge style bucket for the dashboard: ok | progress | error.
     */
    public function severity(): string
    {
        return match ($this) {
            self::Delivered => 'ok',
            self::Rejected, self::Failed => 'error',
            default => 'progress',
        };
    }

    /**
     * Statuses shown in the error queue.
     *
     * @return list<string>
     */
    public static function erroneous(): array
    {
        return [self::Failed->value, self::Rejected->value];
    }

    /**
     * Statuses the outbound pipeline still has work to do on.
     *
     * @return list<string>
     */
    public static function pending(): array
    {
        return [
            self::Received->value,
            self::Mapped->value,
            self::Validated->value,
            self::Queued->value,
        ];
    }
}
