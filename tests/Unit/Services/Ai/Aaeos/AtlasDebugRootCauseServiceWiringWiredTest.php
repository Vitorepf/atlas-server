<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasDebugRootCauseService is wired into a real call path: atlas:aeos:maturity now
 * surfaces a root-cause read whenever the ledger reports over-claim drift. It is no longer an
 * orphan.
 */
final class AtlasDebugRootCauseServiceWiringWiredTest extends TestCase
{
    public function test_debug_root_cause_is_surfaced_when_ledger_reports_drift(): void
    {
        $truth = $this->createMock(AtlasImplementationTruthService::class);
        $truth->method('ledger')->willReturn([
            'summary' => ['evaluated' => 3, 'drift_count' => 1, 'by_computed_state' => []],
            'capabilities' => [],
        ]);
        $this->app->instance(AtlasImplementationTruthService::class, $truth);

        Artisan::call('atlas:aeos:maturity', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('debug_root_cause', $decoded);
        $this->assertSame('analyzed', $decoded['debug_root_cause']['status']);
        $this->assertSame('over_claim_drift', $decoded['debug_root_cause']['root_cause']);
    }

    public function test_debug_root_cause_absent_when_no_drift(): void
    {
        $truth = $this->createMock(AtlasImplementationTruthService::class);
        $truth->method('ledger')->willReturn([
            'summary' => ['evaluated' => 3, 'drift_count' => 0, 'by_computed_state' => []],
            'capabilities' => [],
        ]);
        $this->app->instance(AtlasImplementationTruthService::class, $truth);

        Artisan::call('atlas:aeos:maturity', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('debug_root_cause', $decoded);
    }
}
