<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopTaxa2DialOverlayService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasLoopTaxa2DialOverlayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'atlas.loop.scenarios_per_task' => 3,
            'atlas.loop.max_scenarios_per_task' => 12,
            'atlas.loop.campaign.queue_low_watermark' => 4,
            'atlas.loop.campaign.refill_batch' => 6,
            'atlas.loop.taxa2_dials.enabled' => true,
            'atlas.loop.taxa2_dials.kernel_sanctioned' => true,
            'atlas.loop.taxa2_dials.max_delta_per_run' => 2,
            'atlas.loop.taxa2_dials.max_queue_low_watermark' => 12,
            'atlas.loop.taxa2_dials.max_refill_batch' => 24,
            'atlas.loop.taxa2_dials.min_certification_rate' => 0.65,
            'atlas.loop.taxa2_dials.min_certified_to_merged' => 0.5,
            'atlas.loop.taxa2_dials.max_canary_failures_24h' => 0,
            'atlas.loop.taxa2_dials.min_impact_receipt_coverage_pct' => 95.0,
            'atlas.loop.taxa2_dials.min_cost_coverage_pct' => 80.0,
        ]);
    }

    public function test_red_outcomes_raise_dials_with_clamps_and_receipt_only(): void
    {
        Storage::fake('local');

        $payload = app(AtlasLoopTaxa2DialOverlayService::class)->evaluate([
            'write_receipt' => true,
            'funnel' => $this->funnel([
                'attempted' => 10,
                'certified' => 2,
                'merged' => 0,
                'pending_tasks' => 0,
                'retired_stale' => 6,
                'certified_to_merged' => 0.0,
            ]),
            'morning_digest' => $this->digest([
                'canary_ran' => 4,
                'canary_failed' => 2,
                'impact_coverage' => 50.0,
                'cost_events' => 8,
                'cost_measured' => 0,
                'cost_coverage' => 0.0,
            ]),
        ]);

        $this->assertSame('atlas.loop.taxa2_dial_overlay.v1', $payload['schema_version']);
        $this->assertSame('adjusted', $payload['status']);
        $this->assertTrue((bool) $payload['changed']);
        $this->assertSame(3, data_get($payload, 'base_dials.scenarios_per_task'));
        $this->assertSame(5, data_get($payload, 'effective_dials.scenarios_per_task'));
        $this->assertSame(6, data_get($payload, 'effective_dials.queue_low_watermark'));
        $this->assertSame(8, data_get($payload, 'effective_dials.refill_batch'));
        $this->assertContains('certification_rate_below_floor', $payload['reasons']);
        $this->assertContains('red_canaries_in_loop_window', $payload['reasons']);
        $this->assertContains('cost_coverage_too_low_for_downshift', $payload['reasons']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.merged_to_main'));

        Storage::disk('local')->assertExists((string) data_get($payload, 'receipt.receipt_path'));
    }

    public function test_operator_scenarios_override_is_preserved_while_queue_dials_adjust(): void
    {
        $payload = app(AtlasLoopTaxa2DialOverlayService::class)->evaluate([
            'explicit_scenarios' => 1,
            'funnel' => $this->funnel([
                'attempted' => 10,
                'certified' => 2,
                'merged' => 0,
                'pending_tasks' => 0,
                'retired_stale' => 6,
                'certified_to_merged' => 0.0,
            ]),
            'morning_digest' => $this->digest([
                'canary_ran' => 4,
                'canary_failed' => 2,
                'impact_coverage' => 50.0,
                'cost_events' => 8,
                'cost_measured' => 0,
                'cost_coverage' => 0.0,
            ]),
        ]);

        $this->assertSame(5, data_get($payload, 'suggested_dials.scenarios_per_task'));
        $this->assertSame(1, data_get($payload, 'effective_dials.scenarios_per_task'));
        $this->assertTrue((bool) data_get($payload, 'adjustments.scenarios_per_task.operator_override'));
        $this->assertSame(6, data_get($payload, 'effective_dials.queue_low_watermark'));
        $this->assertContains('operator_scenarios_override_preserved', $payload['reasons']);
    }

    public function test_disabled_overlay_is_byte_safe_base_dials(): void
    {
        config(['atlas.loop.taxa2_dials.enabled' => false]);

        $payload = app(AtlasLoopTaxa2DialOverlayService::class)->evaluate([
            'funnel' => $this->funnel([
                'attempted' => 10,
                'certified' => 2,
                'merged' => 0,
                'pending_tasks' => 0,
                'retired_stale' => 6,
                'certified_to_merged' => 0.0,
            ]),
            'morning_digest' => $this->digest([
                'canary_ran' => 4,
                'canary_failed' => 2,
                'impact_coverage' => 50.0,
                'cost_events' => 8,
                'cost_measured' => 0,
                'cost_coverage' => 0.0,
            ]),
        ]);

        $this->assertSame('disabled', $payload['status']);
        $this->assertFalse((bool) $payload['changed']);
        $this->assertSame($payload['base_dials'], $payload['effective_dials']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function funnel(array $overrides = []): array
    {
        return [
            'status' => 'ok',
            'stages' => [
                'attempted' => $overrides['attempted'] ?? 0,
                'certified' => $overrides['certified'] ?? 0,
                'merged' => $overrides['merged'] ?? 0,
            ],
            'branches' => [
                'pending_tasks' => $overrides['pending_tasks'] ?? 0,
                'retired_stale' => $overrides['retired_stale'] ?? 0,
            ],
            'conversion' => [
                'certified_to_merged' => $overrides['certified_to_merged'] ?? null,
                'drainable_remaining' => $overrides['drainable_remaining'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function digest(array $overrides = []): array
    {
        return [
            'status' => 'ok',
            'sections' => [
                'canaries' => [
                    'ran_24h' => $overrides['canary_ran'] ?? 0,
                    'failed_24h' => $overrides['canary_failed'] ?? 0,
                ],
                'merges' => [
                    'impact_receipt_coverage_pct_24h' => $overrides['impact_coverage'] ?? 0.0,
                ],
                'cost' => [
                    'events_24h' => $overrides['cost_events'] ?? 0,
                    'measured_events_24h' => $overrides['cost_measured'] ?? 0,
                    'coverage_pct_24h' => $overrides['cost_coverage'] ?? 0.0,
                ],
            ],
        ];
    }
}
