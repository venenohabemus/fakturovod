<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly document counters — the basis for tier billing towards clients
 * and for watching our own poštár API costs (pásma).
 */
class UsageMeter extends Model
{
    public const DOCUMENTS_SENT = 'documents_sent';
    public const DOCUMENTS_RECEIVED = 'documents_received';

    protected $guarded = [];

    /**
     * Atomically bumps a metric for the month of $when (now by default).
     */
    public static function record(string $metric, ?Carbon $when = null): void
    {
        $period = ($when ?? now())->format('Y-m');

        // Upsert keeps the increment race-free under the unique key.
        self::upsert(
            [['period' => $period, 'metric' => $metric, 'count' => 1, 'created_at' => now(), 'updated_at' => now()]],
            ['period', 'metric'],
            ['count' => DB::raw('count + 1'), 'updated_at' => now()],
        );
    }
}
