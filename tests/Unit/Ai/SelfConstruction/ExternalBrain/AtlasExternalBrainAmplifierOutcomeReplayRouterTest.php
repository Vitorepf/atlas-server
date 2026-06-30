<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierOutcomeReplayRouter;
use Tests\TestCase;

final class AtlasExternalBrainAmplifierOutcomeReplayRouterTest extends TestCase
{
    private function svc(): AtlasExternalBrainAmplifierOutcomeReplayRouter
    {
        return new AtlasExternalBrainAmplifierOutcomeReplayRouter;
    }

    private function outcome(
        string $id,
        string $type,
        int $evidence = 5,
        string $scaffold = 'v1',
        string $tier = 'small',
    ): array {
        return [
            'outcome_id' => $id,
            'outcome_type' => $type,
            'evidence_count' => $evidence,
            'scaffold_variant' => $scaffold,
            'model_tier' => $tier,
        ];
    }

    private function route(array $outcomes): array
    {
        return $this->svc()->route(['task_outcomes' => $outcomes]);
    }

    // ── routing by type ───────────────────────────────────────────────────────

    public function test_commit_success_routes_to_scaffold_and_tier(): void
    {
        $r = $this->route([$this->outcome('o1', 'commit_success')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('scaffold_selection', $sinks);
        $this->assertContains('model_tier_routing', $sinks);
        $this->assertNotContains('regression_cases', $sinks);
    }

    public function test_give_back_routes_to_scaffold_and_promotion_gates(): void
    {
        $r = $this->route([$this->outcome('o1', 'give_back')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('scaffold_selection', $sinks);
        $this->assertContains('promotion_gates', $sinks);
    }

    public function test_poison_routes_to_promotion_gates_and_regression_cases(): void
    {
        $r = $this->route([$this->outcome('o1', 'poison')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('promotion_gates', $sinks);
        $this->assertContains('regression_cases', $sinks);
    }

    public function test_weak_evidence_routes_to_regression_cases_only(): void
    {
        $r = $this->route([$this->outcome('o1', 'weak_evidence')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('regression_cases', $sinks);
        $this->assertCount(1, $sinks);
    }

    public function test_high_value_routes_to_all_three_sinks(): void
    {
        $r = $this->route([$this->outcome('o1', 'high_value')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('scaffold_selection', $sinks);
        $this->assertContains('model_tier_routing', $sinks);
        $this->assertContains('promotion_gates', $sinks);
    }

    // ── regression_case_candidates ────────────────────────────────────────────

    public function test_poison_outcome_appears_in_regression_candidates(): void
    {
        $r = $this->route([$this->outcome('o1', 'poison')]);

        $this->assertCount(1, $r['regression_case_candidates']);
        $this->assertSame('o1', $r['regression_case_candidates'][0]['outcome_id']);
    }

    public function test_commit_success_not_in_regression_candidates(): void
    {
        $r = $this->route([$this->outcome('o1', 'commit_success')]);

        $this->assertSame([], $r['regression_case_candidates']);
    }

    // ── ignored outcomes ──────────────────────────────────────────────────────

    public function test_low_evidence_outcome_is_ignored(): void
    {
        $r = $this->route([$this->outcome('o1', 'commit_success', evidence: 2)]);

        $this->assertSame([], $r['routed_updates']);
        $this->assertSame('low_evidence', $r['ignored_outcomes'][0]['reason']);
    }

    public function test_exact_min_evidence_is_not_ignored(): void
    {
        $r = $this->route([$this->outcome('o1', 'commit_success', evidence: 3)]);

        $this->assertCount(1, $r['routed_updates']);
        $this->assertSame([], $r['ignored_outcomes']);
    }

    public function test_unknown_outcome_type_is_ignored(): void
    {
        $r = $this->route([$this->outcome('o1', 'mystery_event', evidence: 10)]);

        $this->assertSame([], $r['routed_updates']);
        $this->assertSame('unknown_outcome_type', $r['ignored_outcomes'][0]['reason']);
    }

    // ── affected_scaffolds / affected_model_tiers ─────────────────────────────

    public function test_affected_scaffolds_are_unique(): void
    {
        $r = $this->route([
            $this->outcome('o1', 'commit_success', scaffold: 'v1'),
            $this->outcome('o2', 'commit_success', scaffold: 'v1'),  // same scaffold
            $this->outcome('o3', 'commit_success', scaffold: 'v2'),
        ]);

        $this->assertCount(2, $r['affected_scaffolds']);
        $this->assertContains('v1', $r['affected_scaffolds']);
        $this->assertContains('v2', $r['affected_scaffolds']);
    }

    public function test_affected_model_tiers_are_unique(): void
    {
        $r = $this->route([
            $this->outcome('o1', 'commit_success', tier: 'small'),
            $this->outcome('o2', 'commit_success', tier: 'frontier'),
        ]);

        $this->assertCount(2, $r['affected_model_tiers']);
    }

    // ── learning-loop sinks (proxy / no-delta / heldout-fail / frontier) ─────

    public function test_proxy_success_routes_to_rollback_and_benchmark(): void
    {
        $r = $this->route([$this->outcome('o1', 'proxy_success')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('rollback_signal', $sinks);
        $this->assertContains('heldout_benchmark_update', $sinks);
    }

    public function test_proxy_success_does_not_feed_positive_learning(): void
    {
        $r = $this->route([$this->outcome('o1', 'proxy_success')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertNotContains('scaffold_variant_learning', $sinks);
        $this->assertNotContains('scaffold_selection', $sinks);
    }

    public function test_no_capability_delta_routes_to_rollback_and_benchmark(): void
    {
        $r = $this->route([$this->outcome('o1', 'no_capability_delta')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('rollback_signal', $sinks);
        $this->assertContains('heldout_benchmark_update', $sinks);
    }

    public function test_no_capability_delta_does_not_feed_positive_learning(): void
    {
        $r = $this->route([$this->outcome('o1', 'no_capability_delta')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertNotContains('scaffold_variant_learning', $sinks);
    }

    public function test_heldout_failure_routes_to_benchmark_and_frontier_escalation(): void
    {
        $r = $this->route([$this->outcome('o1', 'heldout_failure')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('heldout_benchmark_update', $sinks);
        $this->assertContains('frontier_escalation_signal', $sinks);
    }

    public function test_frontier_candidate_routes_to_scaffold_variant_and_escalation(): void
    {
        $r = $this->route([$this->outcome('o1', 'frontier_candidate')]);

        $sinks = $r['routed_updates'][0]['sinks'];
        $this->assertContains('scaffold_variant_learning', $sinks);
        $this->assertContains('frontier_escalation_signal', $sinks);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_outcomes_returns_empty_output(): void
    {
        $r = $this->svc()->route([]);

        $this->assertSame([], $r['routed_updates']);
        $this->assertSame([], $r['ignored_outcomes']);
        $this->assertSame([], $r['affected_scaffolds']);
        $this->assertSame([], $r['affected_model_tiers']);
        $this->assertSame([], $r['regression_case_candidates']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->route([]);

        $this->assertSame(AtlasExternalBrainAmplifierOutcomeReplayRouter::SCHEMA, $r['schema_version']);
    }
}
