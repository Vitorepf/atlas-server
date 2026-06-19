<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopObservabilityDigest;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopDeliveryPipeline;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 5 · §K — the observability section reports the real stage funnel + in-flight/parked counts
 * + the top accrued-EV from the Slice-8 pipeline_state, so the operator sees the bottleneck without raw logs.
 */
final class AtlasLoopObservabilityDigestTest extends TestCase
{
    private AtlasLoopDeliveryPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_pipeline_state')) {
            (require base_path('database/migrations/2026_06_18_000100_create_atlas_loop_pipeline_state.php'))->up();
        }
        $this->pipeline = new AtlasLoopDeliveryPipeline();
    }

    public function test_section_reports_the_funnel_in_flight_parked_and_top_ev(): void
    {
        $this->pipeline->dispatchProjection('camp-1', 'obj-1', 1.0);
        $this->pipeline->dispatchProjection('camp-1', 'obj-2', 5.0);
        $this->pipeline->dispatchProjection('camp-1', 'obj-3', 3.0);
        $this->pipeline->dispatchProjection('camp-OTHER', 'x-1', 9.0); // a different campaign — must be excluded

        $this->pipeline->claimNextProjection('camp-1', 'worker-A', 300); // claims obj-2 (highest EV) ⇒ in-flight
        $this->pipeline->park('obj-1', 'oscillation');                   // obj-1 ⇒ parked

        $s = (new AtlasLoopObservabilityDigest())->section('camp-1');

        $this->assertSame(3, $s['total'], 'only this campaign');
        $this->assertSame(2, $s['stage_funnel']['projection'] ?? 0, 'two still in projection');
        $this->assertSame(1, $s['stage_funnel']['parked'] ?? 0, 'one parked');
        $this->assertSame(1, $s['in_flight'], 'the leased projection');
        $this->assertSame(1, $s['parked']);
        $this->assertSame(5.0, $s['max_accrued_ev']);
    }

    public function test_empty_campaign_is_a_wellformed_zero_section(): void
    {
        $s = (new AtlasLoopObservabilityDigest())->section('camp-empty');
        $this->assertSame(0, $s['total']);
        $this->assertSame([], $s['stage_funnel']);
        $this->assertSame(0, $s['in_flight']);
    }
}
