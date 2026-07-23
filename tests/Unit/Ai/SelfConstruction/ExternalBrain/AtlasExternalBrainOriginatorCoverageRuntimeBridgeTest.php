<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorCoverageRuntimeBridge;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorCoverageRuntimeBridgeTest extends TestCase
{
    private function bridge(): AtlasExternalBrainOriginatorCoverageRuntimeBridge
    {
        return new AtlasExternalBrainOriginatorCoverageRuntimeBridge;
    }

    // ── output shape / backward compatibility ────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $route = $this->bridge()->route([]);

        foreach (['schema', 'pivot_required', 'target_gaps', 'next_theme', 'reasons', 'action', 'target_lane', 'evidence_gap'] as $k) {
            $this->assertArrayHasKey($k, $route);
        }
        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::SCHEMA, $route['schema']);
    }

    public function test_legacy_pivot_fields_unchanged_when_route_to_gaps_true(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => true,
            'reasons' => ['roadmap_gaps_overcovered'],
            'roadmap_coverage' => ['next_batch_should_target' => ['theme_b', 'theme_c']],
        ]);

        $this->assertTrue($route['pivot_required']);
        $this->assertSame(['theme_b', 'theme_c'], $route['target_gaps']);
        $this->assertSame('theme_b', $route['next_theme']);
    }

    public function test_legacy_pivot_fields_unchanged_when_route_to_gaps_false(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => false,
            'roadmap_coverage' => ['next_batch_should_target' => ['theme_b']],
        ]);

        $this->assertFalse($route['pivot_required']);
        $this->assertSame([], $route['target_gaps']);
        $this->assertNull($route['next_theme']);
    }

    // ── AC1: missing high-leverage coverage routes to originate ─────────────────

    public function test_missing_high_leverage_coverage_routes_to_originate_with_lane_and_evidence_gap(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => true,
            'reasons' => ['undercovered_high_priority_gap'],
            'roadmap_coverage' => ['next_batch_should_target' => ['loop_evolution', 'memory_governance']],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_ORIGINATE, $route['action']);
        $this->assertSame('loop_evolution', $route['target_lane']);
        $this->assertNotEmpty($route['evidence_gap']);
        $this->assertStringContainsString('loop_evolution', $route['evidence_gap']);
    }

    public function test_route_to_gaps_true_without_target_gaps_does_not_originate(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => true,
            'reasons' => ['some_reason'],
            'roadmap_coverage' => [],
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_ORIGINATE, $route['action']);
    }

    // ── AC2: overcovered stale areas route to retire_or_refresh ─────────────────

    public function test_backlog_aging_retire_decision_routes_to_retire_or_refresh(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => false,
            'backlog_aging' => ['decision' => 'retire', 'target' => 'stale_theme'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RETIRE_OR_REFRESH, $route['action']);
    }

    public function test_backlog_aging_overcovered_stale_flag_routes_to_retire_or_refresh(): void
    {
        $route = $this->bridge()->route([
            'backlog_aging' => ['overcovered_stale' => true],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RETIRE_OR_REFRESH, $route['action']);
    }

    public function test_retire_or_refresh_takes_priority_over_originate(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => true,
            'roadmap_coverage' => ['next_batch_should_target' => ['some_gap']],
            'backlog_aging' => ['decision' => 'respec'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RETIRE_OR_REFRESH, $route['action']);
    }

    // ── AC3: malformed or poison-heavy coverage routes to self_heal ─────────────

    public function test_high_malformed_rate_routes_to_self_heal(): void
    {
        $route = $this->bridge()->route([
            'queue_health' => ['malformed_rate' => 0.5],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL, $route['action']);
    }

    public function test_high_poison_rate_routes_to_self_heal(): void
    {
        $route = $this->bridge()->route([
            'queue_health' => ['poison_rate' => 0.4],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL, $route['action']);
    }

    public function test_explicit_poison_heavy_flag_routes_to_self_heal(): void
    {
        $route = $this->bridge()->route([
            'queue_health' => ['poison_heavy' => true],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL, $route['action']);
    }

    public function test_self_heal_takes_priority_over_originate_and_retire(): void
    {
        $route = $this->bridge()->route([
            'route_to_gaps' => true,
            'roadmap_coverage' => ['next_batch_should_target' => ['some_gap']],
            'backlog_aging' => ['decision' => 'retire'],
            'queue_health' => ['poison_heavy' => true],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL, $route['action']);
    }

    public function test_low_malformed_rate_does_not_trigger_self_heal(): void
    {
        $route = $this->bridge()->route([
            'queue_health' => ['malformed_rate' => 0.05],
        ]);

        $this->assertNotSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_SELF_HEAL, $route['action']);
    }

    // ── default / research fallback ──────────────────────────────────────────

    public function test_deepen_surface_saturation_verdict_routes_to_research(): void
    {
        $route = $this->bridge()->route([
            'surface_saturation' => ['verdict' => 'deepen'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RESEARCH, $route['action']);
    }

    public function test_no_signals_at_all_defaults_to_research(): void
    {
        $route = $this->bridge()->route([]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_RESEARCH, $route['action']);
    }

    public function test_non_deepen_saturation_verdict_without_other_signals_consolidates(): void
    {
        $route = $this->bridge()->route([
            'surface_saturation' => ['verdict' => 'rotate'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorCoverageRuntimeBridge::ACTION_CONSOLIDATE, $route['action']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_route_is_deterministic(): void
    {
        $input = [
            'route_to_gaps' => true,
            'roadmap_coverage' => ['next_batch_should_target' => ['a', 'b']],
        ];

        $this->assertSame($this->bridge()->route($input), $this->bridge()->route($input));
    }
}
