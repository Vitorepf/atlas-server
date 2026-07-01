<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainClosedLoopLearningCompletenessVerifierTest extends TestCase
{
    private AtlasExternalBrainClosedLoopLearningCompletenessVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
    }

    private function completeCycle(array $overrides = []): array
    {
        return array_merge([
            'cycle_id'              => 'cycle-1',
            'origination_receipt'   => ['task_packet_id' => 'task-001'],
            'attribution'           => ['owner' => 'worker-1', 'task_class' => 'refactor'],
            'implementation_result' => ['commit_sha' => 'abc123', 'files_committed' => ['app/Foo.php']],
            'runnable_evidence'     => ['command' => './vendor/bin/phpunit tests/FooTest.php', 'outcome' => 'OK (14 tests)'],
            'learning_update'       => ['pattern_family' => 'spec-quality', 'delta' => 0.15],
            'next_batch_constraint' => ['promoted_rule' => 'require_behavior_proof'],
        ], $overrides);
    }

    private function input(array ...$cycles): array
    {
        return ['cycles' => $cycles];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        foreach (['schema', 'complete', 'missing_links', 'cycle_receipts', 'next_repair_task_hint', 'learning_leaks'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainClosedLoopLearningCompletenessVerifier::SCHEMA, $result['schema']);
    }

    // ── AC3: complete cycle passes ────────────────────────────────────────────

    public function test_complete_cycle_passes(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['missing_links']);
        $this->assertNull($result['next_repair_task_hint']);
    }

    public function test_cycle_receipt_reflects_complete_status(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        $this->assertTrue($result['cycle_receipts'][0]['complete']);
        $this->assertSame([], $result['cycle_receipts'][0]['missing_links']);
    }

    // ── AC2: missing value evidence → incomplete ──────────────────────────────

    public function test_missing_runnable_evidence_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['runnable_evidence' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_VALUE_EVIDENCE,
            $result['missing_links'],
        );
    }

    public function test_empty_runnable_evidence_array_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['runnable_evidence' => []])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_VALUE_EVIDENCE,
            $result['missing_links'],
        );
    }

    // ── AC2: missing learning record → incomplete ─────────────────────────────

    public function test_missing_learning_update_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['learning_update' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_LEARNING_RECORD,
            $result['missing_links'],
        );
    }

    // ── AC2: missing next-batch constraint → incomplete ───────────────────────

    public function test_missing_next_batch_constraint_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['next_batch_constraint' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_NEXT_BATCH_CONSTRAINT,
            $result['missing_links'],
        );
    }

    // ── AC: missing attribution → incomplete ──────────────────────────────────

    public function test_missing_attribution_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['attribution' => null])));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_ATTRIBUTION,
            $result['missing_links'],
        );
    }

    public function test_empty_attribution_array_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['attribution' => []])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_ATTRIBUTION,
            $result['missing_links'],
        );
    }

    public function test_attribution_with_only_agent_id_is_sufficient(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['attribution' => ['agent_id' => 'worker-9']])));

        $this->assertNotContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_ATTRIBUTION,
            $result['missing_links'],
        );
    }

    // ── AC: learning leak — commit/give_back with no policy or task-fabric effect ──

    public function test_success_outcome_without_learner_route_is_flagged_as_learning_leak(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['outcome_type' => 'success'])
        ));

        $this->assertSame(['cycle-1'], $result['learning_leaks']);
        $this->assertTrue($result['cycle_receipts'][0]['learning_leak']);
    }

    public function test_give_back_outcome_without_policy_route_is_flagged_as_learning_leak(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['cycle_id' => 'give-back-cycle', 'outcome_type' => 'give_back'])
        ));

        $this->assertSame(['give-back-cycle'], $result['learning_leaks']);
        $this->assertTrue($result['cycle_receipts'][0]['learning_leak']);
    }

    public function test_give_back_outcome_with_policy_change_is_not_a_learning_leak(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle([
                'outcome_type' => 'give_back',
                'next_batch_constraint' => ['policy_change' => 'tighten_scope_gate'],
            ])
        ));

        $this->assertSame([], $result['learning_leaks']);
        $this->assertFalse($result['cycle_receipts'][0]['learning_leak']);
    }

    public function test_quarantine_outcome_without_maestro_route_is_not_a_learning_leak(): void
    {
        // Learning leak is scoped to success/give_back -- quarantine has its own maestro route
        // check (LINK_MAESTRO_ROUTE) but is not classified as a "leak" by this verifier.
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['outcome_type' => 'quarantine'])
        ));

        $this->assertSame([], $result['learning_leaks']);
    }

    public function test_cycle_without_outcome_type_is_not_a_learning_leak(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle()));

        $this->assertSame([], $result['learning_leaks']);
    }

    // ── Other link checks ─────────────────────────────────────────────────────

    public function test_missing_origination_receipt_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['origination_receipt' => null])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_ORIGINATION_RECEIPT,
            $result['missing_links'],
        );
    }

    public function test_missing_implementation_result_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['implementation_result' => null])));

        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_IMPLEMENTATION_RESULT,
            $result['missing_links'],
        );
    }

    // ── repair hint follows chain priority ────────────────────────────────────

    public function test_origination_missing_is_highest_priority_repair_hint(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle([
            'origination_receipt'   => null,
            'next_batch_constraint' => null,
        ])));

        $this->assertSame('emit_origination_receipt_for_cycle', $result['next_repair_task_hint']);
    }

    public function test_evidence_repair_hint_when_only_evidence_missing(): void
    {
        $result = $this->verifier->verify($this->input($this->completeCycle(['runnable_evidence' => null])));

        $this->assertSame('attach_runnable_evidence_to_cycle', $result['next_repair_task_hint']);
    }

    // ── Multiple cycles ───────────────────────────────────────────────────────

    public function test_all_cycles_complete_means_overall_complete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['cycle_id' => 'a']),
            $this->completeCycle(['cycle_id' => 'b']),
        ));

        $this->assertTrue($result['complete']);
        $this->assertCount(2, $result['cycle_receipts']);
    }

    public function test_one_incomplete_cycle_makes_overall_incomplete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['cycle_id' => 'good']),
            $this->completeCycle(['cycle_id' => 'bad', 'learning_update' => null]),
        ));

        $this->assertFalse($result['complete']);
        $this->assertFalse($result['cycle_receipts'][1]['complete']);
        $this->assertTrue($result['cycle_receipts'][0]['complete']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_cycles_is_vacuously_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => []]);

        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['missing_links']);
        $this->assertNull($result['next_repair_task_hint']);
    }

    // ── AC2: generic constraint (no influence keys) → incomplete ──────────────

    public function test_generic_next_batch_constraint_without_influence_keys_is_incomplete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => ['target_entropy_floor' => 0.4, 'required_families' => ['adversarial']]])
        ));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_NEXT_BATCH_CONSTRAINT,
            $result['missing_links'],
        );
    }

    public function test_empty_next_batch_constraint_array_is_incomplete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => []])
        ));

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_NEXT_BATCH_CONSTRAINT,
            $result['missing_links'],
        );
    }

    // ── AC3: each valid influence key makes cycle complete ────────────────────

    public function test_blocked_family_influence_key_makes_cycle_complete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => ['blocked_family' => 'proxy_tasks']])
        ));

        $this->assertTrue($result['complete']);
    }

    public function test_threshold_change_influence_key_makes_cycle_complete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => ['threshold_change' => ['leverage' => 0.7]]])
        ));

        $this->assertTrue($result['complete']);
    }

    public function test_routing_hint_influence_key_makes_cycle_complete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => ['routing_hint' => 'prefer_wiring_tasks']])
        ));

        $this->assertTrue($result['complete']);
    }

    public function test_retired_pattern_influence_key_makes_cycle_complete(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => ['retired_pattern' => 'empty_spec_proxy']])
        ));

        $this->assertTrue($result['complete']);
    }

    // ── Repair hint for no_next_batch_constraint ──────────────────────────────

    public function test_no_op_constraint_gives_correct_repair_hint(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['next_batch_constraint' => ['some_generic_key' => 'value']])
        ));

        $this->assertSame('derive_next_batch_constraint_from_learning_update', $result['next_repair_task_hint']);
    }

    // ── AC1: outcome-type routing ──────────────────────────────────────────

    public function test_success_outcome_without_learner_route_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['outcome_type' => 'success'])
        ));

        $this->assertFalse($result['complete']);
        $this->assertContains('no_learner_route', $result['missing_links']);
    }

    public function test_success_outcome_with_learner_route_via_learning_update_passes(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle([
                'outcome_type' => 'success',
                'learning_update' => ['pattern_family' => 'spec-quality', 'routed_to_learner' => true],
            ])
        ));

        $this->assertTrue($result['complete']);
    }

    public function test_give_back_outcome_without_policy_route_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['outcome_type' => 'give_back'])
        ));

        $this->assertFalse($result['complete']);
        $this->assertContains('no_policy_route', $result['missing_links']);
    }

    public function test_give_back_outcome_with_policy_change_in_constraint_passes(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle([
                'outcome_type' => 'give_back',
                'next_batch_constraint' => ['policy_change' => 'tighten_scope_gate'],
            ])
        ));

        $this->assertTrue($result['complete']);
    }

    public function test_quarantine_outcome_without_maestro_route_is_flagged(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle(['outcome_type' => 'quarantine'])
        ));

        $this->assertFalse($result['complete']);
        $this->assertContains('no_maestro_route', $result['missing_links']);
    }

    public function test_quarantine_outcome_with_maestro_adjustment_in_constraint_passes(): void
    {
        $result = $this->verifier->verify($this->input(
            $this->completeCycle([
                'outcome_type' => 'quarantine',
                'next_batch_constraint' => ['maestro_adjustment' => 'suspend_family'],
            ])
        ));

        $this->assertTrue($result['complete']);
    }
}
