<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopReplenisherDocGapOracleCoverageSentinel;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the replenisher doc-gap oracle coverage sentinel is live at the operator surface: a packet granting
 * both the app/ and tests/ half RESOLVES the doc gap; an app-only packet still FIRES it — so the oracle has
 * not regressed into a no-op, and the sentinel reports conformant.
 */
final class AtlasLoopReplenisherDocGapSentinelCommandTest extends TestCase
{
    public function test_sentinel_reports_oracle_covers_both_halves(): void
    {
        $exit = Artisan::call('atlas:loop:replenisher-doc-gap-sentinel', ['--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopReplenisherDocGapOracleCoverageSentinel::SCHEMA, $d['schema']);

        // both halves granted ⇒ resolved; app-only ⇒ still fires (oracle is not a no-op)
        $this->assertFalse($d['both_halves']['doc_gap_unresolved'], (string) json_encode($d));
        $this->assertTrue($d['app_only']['doc_gap_unresolved'], (string) json_encode($d));
        $this->assertTrue($d['conformant']);
    }
}
