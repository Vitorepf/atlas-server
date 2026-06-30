<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierOutcomeReplayRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierOutcomeReplayRouterTest extends TestCase
{
    private AtlasExternalBrainAmplifierOutcomeReplayRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasExternalBrainAmplifierOutcomeReplayRouter;
    }

    private function route(array $outcomes): array
    {
        return $this->router->route(['task_outcomes' => $outcomes]);
    }

    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'outcome_id'       => 'o-1',
            'outcome_type'     => 'commit_success',
            'scaffold_variant' => 'v1',
            'model_tier'       => 'fast',
            'evidence_count'   => 5,
        ], $overrides);
    }

    // ── AC2: below MIN_EVIDENCE → ignored with low_evidence ──────────────────

    public function test_outcome_below_min_evidence_is_ignored(): void
    {
        $r = $this->route([
            $this->outcome(['evidence_count' => AtlasExternalBrainAmplifierOutcomeReplayRouter::MIN_EVIDENCE - 1]),
        ]);

        $this->assertEmpty($r['routed_updates']);
        $this->assertCount(1, $r['ignored_outcomes']);
        $this->assertSame('low_evidence', $r['ignored_outcomes'][0]['reason']);
    }

    public function test_outcome_at_exact_min_evidence_is_routed(): void
    {
        $r = $this->route([
            $this->outcome(['evidence_count' => AtlasExternalBrainAmplifierOutcomeReplayRouter::MIN_EVIDENCE]),
        ]);

        $this->assertCount(1, $r['routed_updates']);
        $this->assertEmpty($r['ignored_outcomes']);
    }

    public function test_unknown_outcome_type_is_ignored(): void
    {
        $r = $this->route([
            $this->outcome(['outcome_type' => 'mystery_type', 'evidence_count' => 5]),
        ]);

        $this->assertEmpty($r['routed_updates']);
        $this->assertSame('unknown_outcome_type', $r['ignored_outcomes'][0]['reason']);
    }

    // ── AC3: proxy_success / no_capability_delta → only rollback + heldout ───

    public function test_proxy_success_routes_only_to_rollback_and_heldout(): void
    {
        $r = $this->route([
            $this->outcome(['outcome_type' => 'proxy_success', 'evidence_count' => 5]),
        ]);

        $this->assertCount(1, $r['routed_updates']);
        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('rollback_signal', $sinks);
        $this->assertContains('heldout_benchmark_update', $sinks);
        $this->assertNotContains('scaffold_selection', $sinks);
        $this->assertNotContains('model_tier_routing', $sinks);
    }

    public function test_no_capability_delta_routes_only_to_rollback_and_heldout(): void
    {
        $r = $this->route([
            $this->outcome(['outcome_type' => 'no_capability_delta', 'evidence_count' => 5]),
        ]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('rollback_signal', $sinks);
        $this->assertContains('heldout_benchmark_update', $sinks);
        $this->assertNotContains('scaffold_selection', $sinks);
        $this->assertNotContains('model_tier_routing', $sinks);
    }

    // ── AC4: frontier_candidate / heldout_failure → escalation + metadata ────

    public function test_frontier_candidate_routes_to_escalation_sinks(): void
    {
        $r = $this->route([
            $this->outcome([
                'outcome_type'     => 'frontier_candidate',
                'scaffold_variant' => 'scaffold-v3',
                'model_tier'       => 'slow',
                'evidence_count'   => 4,
            ]),
        ]);

        $update = $r['routed_updates'][0];
        $this->assertContains('frontier_escalation_signal', $update['sinks']);
        $this->assertContains('scaffold_variant_learning', $update['sinks']);
        $this->assertSame('scaffold-v3', $update['scaffold_variant']);
        $this->assertSame('slow', $update['model_tier']);
    }

    public function test_heldout_failure_routes_to_heldout_and_frontier(): void
    {
        $r = $this->route([
            $this->outcome([
                'outcome_type'     => 'heldout_failure',
                'scaffold_variant' => 'scaffold-v2',
                'model_tier'       => 'medium',
                'evidence_count'   => 5,
            ]),
        ]);

        $update = $r['routed_updates'][0];
        $this->assertContains('heldout_benchmark_update', $update['sinks']);
        $this->assertContains('frontier_escalation_signal', $update['sinks']);
        $this->assertSame('scaffold-v2', $update['scaffold_variant']);
        $this->assertSame('medium', $update['model_tier']);
    }

    public function test_affected_scaffold_and_tier_collected(): void
    {
        $r = $this->route([
            $this->outcome(['scaffold_variant' => 'v-alpha', 'model_tier' => 'fast']),
            $this->outcome(['outcome_id' => 'o-2', 'scaffold_variant' => 'v-beta', 'model_tier' => 'slow']),
        ]);

        $this->assertContains('v-alpha', $r['affected_scaffolds']);
        $this->assertContains('v-beta',  $r['affected_scaffolds']);
        $this->assertContains('fast', $r['affected_model_tiers']);
        $this->assertContains('slow', $r['affected_model_tiers']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $outcomes = [
            $this->outcome(['outcome_type' => 'proxy_success']),
        ];

        $this->assertSame(json_encode($this->route($outcomes)), json_encode($this->route($outcomes)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->route([]);

        $this->assertSame(AtlasExternalBrainAmplifierOutcomeReplayRouter::SCHEMA, $r['schema_version']);
    }
}
