<?php

namespace Tests\Feature;

use App\Models\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UsageMeterTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_increments_one_counter_per_month(): void
    {
        UsageMeter::record(UsageMeter::DOCUMENTS_SENT);
        UsageMeter::record(UsageMeter::DOCUMENTS_SENT);
        UsageMeter::record(UsageMeter::DOCUMENTS_RECEIVED);

        $this->assertSame(2, UsageMeter::count());
        $this->assertSame(
            2,
            (int) UsageMeter::where('metric', UsageMeter::DOCUMENTS_SENT)->value('count')
        );
        $this->assertSame(
            1,
            (int) UsageMeter::where('metric', UsageMeter::DOCUMENTS_RECEIVED)->value('count')
        );
    }

    public function test_record_separates_calendar_months(): void
    {
        UsageMeter::record(UsageMeter::DOCUMENTS_SENT, Carbon::parse('2026-06-30'));
        UsageMeter::record(UsageMeter::DOCUMENTS_SENT, Carbon::parse('2026-07-01'));

        $this->assertSame(
            ['2026-06' => 1, '2026-07' => 1],
            UsageMeter::orderBy('period')->pluck('count', 'period')
                ->map(fn ($count) => (int) $count)->all()
        );
    }
}
