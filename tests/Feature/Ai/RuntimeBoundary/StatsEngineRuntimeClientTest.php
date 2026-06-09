<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\StatsEngineRuntimeClient;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the PHP kernel really invokes the Python stats runtime and gets REAL
 * numpy statistics back through the boundary — not a PHP hand-rolled stand-in.
 * Gated on the runtime being set up (scripts/setup-stats-engine-runtime.sh);
 * when absent the e2e test skips honestly and the anti-fallback test asserts an
 * explicit failure (the boundary violation R7 removed: no silent PHP math).
 */
final class StatsEngineRuntimeClientTest extends TestCase
{
    public function test_php_invokes_real_python_stats_end_to_end(): void
    {
        $client = new StatsEngineRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('stats_engine runtime not set up — honest skip (not a fake).');
        }

        // Known-answer proofs (a hash/stub fails every one):
        // identical samples -> D=0, p=1.
        $ks = $client->ks(range(1, 24), range(1, 24));
        $this->assertSame('ks', $ks['method']);
        $this->assertSame(0.0, $ks['d_statistic']);
        $this->assertSame(1.0, $ks['p_value']);

        // strictly increasing -> Sen's slope exactly 1.0/day, increasing.
        $mk = $client->mannKendall(array_map('floatval', range(1, 15)));
        $this->assertSame('increasing', $mk['direction']);
        $this->assertEqualsWithDelta(1.0, $mk['sens_slope'], 1e-4);

        // 50/50 proportion -> Wilson centred at 0.5.
        $wilson = $client->wilson(50, 100);
        $this->assertEqualsWithDelta(0.50, $wilson['center'], 0.01);
        $this->assertSame('wilson', $wilson['method']);
    }

    public function test_batch_computes_many_stats_in_one_subprocess(): void
    {
        $client = new StatsEngineRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('stats_engine runtime not set up — honest skip (not a fake).');
        }

        $results = $client->computeBatch([
            ['id' => 'ewma:q', 'op' => 'ewma', 'series' => array_map('floatval', range(0, 19))],
            ['id' => 'mk:q', 'op' => 'mann_kendall', 'series' => array_map('floatval', range(1, 15))],
            ['id' => 'cusum:q', 'op' => 'cusum', 'series' => array_fill(0, 20, 50.0)],
        ]);

        $this->assertEqualsCanonicalizing(['ewma:q', 'mk:q', 'cusum:q'], array_keys($results));
        $this->assertSame('increasing', $results['mk:q']['direction']);
        $this->assertTrue($results['cusum:q']['suppressed']);
        $this->assertSame('reference_variance_too_low', $results['cusum:q']['suppression_reason']);
    }

    public function test_runtime_absence_fails_explicitly_never_a_silent_fake(): void
    {
        $client = new StatsEngineRuntimeClient;
        if ($client->available()) {
            // Runtime present: the e2e test covers the anti-fake boundary guard.
            $this->assertTrue(true);

            return;
        }
        $this->expectException(RuntimeException::class);
        $client->wilson(1, 10);
    }
}
