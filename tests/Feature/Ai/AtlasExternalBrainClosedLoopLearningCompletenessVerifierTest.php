<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
use Tests\TestCase;

final class AtlasExternalBrainClosedLoopLearningCompletenessVerifierTest extends TestCase
{
    private AtlasExternalBrainClosedLoopLearningCompletenessVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new AtlasExternalBrainClosedLoopLearningCompletenessVerifier;
    }

    private function baseCycle(array $overrides = []): array
    {
        return array_merge([
            'cycle_id'              => 'cy-01',
            'origination_receipt'   => ['task_packet_id' => 'task-001'],
            'implementation_result' => ['commit_sha' => 'abc123'],
            'runnable_evidence'     => ['command' => './vendor/bin/phpunit tests/FooTest.php', 'outcome' => 'OK'],
            'learning_update'       => ['pattern_family' => 'spec-quality', 'delta' => 0.10],
            'next_batch_constraint' => ['promoted_rule' => 'require_behavior_proof'],
        ], $overrides);
    }

    // ── AC1: complete=false when outcome lacks route into learner/policy/maestro

    public function test_ac1_success_outcome_without_learner_route_is_incomplete(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['outcome_type' => 'success']),
        ]]);

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_LEARNER_ROUTE,
            $result['missing_links'],
        );
    }

    public function test_ac1_give_back_outcome_without_policy_route_is_incomplete(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['outcome_type' => 'give_back']),
        ]]);

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_POLICY_ROUTE,
            $result['missing_links'],
        );
    }

    public function test_ac1_quarantine_outcome_without_maestro_route_is_incomplete(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['outcome_type' => 'quarantine']),
        ]]);

        $this->assertFalse($result['complete']);
        $this->assertContains(
            AtlasExternalBrainClosedLoopLearningCompletenessVerifier::LINK_MAESTRO_ROUTE,
            $result['missing_links'],
        );
    }

    public function test_ac1_success_with_routed_to_learner_flag_is_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'outcome_type'    => 'success',
                'learning_update' => ['pattern_family' => 'spec-quality', 'routed_to_learner' => true],
            ]),
        ]]);

        $this->assertTrue($result['complete']);
    }

    public function test_ac1_give_back_with_policy_change_in_constraint_is_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'outcome_type'          => 'give_back',
                'next_batch_constraint' => ['policy_change' => 'reduce_ambiguous_specs'],
            ]),
        ]]);

        $this->assertTrue($result['complete']);
    }

    public function test_ac1_quarantine_with_maestro_adjustment_in_constraint_is_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'outcome_type'          => 'quarantine',
                'next_batch_constraint' => ['maestro_adjustment' => 'raise_quarantine_threshold'],
            ]),
        ]]);

        $this->assertTrue($result['complete']);
    }

    // ── AC2: cycle_receipts carry decision_chain linking outcome→lesson→constraint

    public function test_ac2_cycle_receipts_contain_decision_chain(): void
    {
        $result = $this->verifier->verify(['cycles' => [$this->baseCycle()]]);

        $receipt = $result['cycle_receipts'][0];
        $this->assertArrayHasKey('decision_chain', $receipt);

        $chain = $receipt['decision_chain'];
        $this->assertArrayHasKey('outcome_type', $chain);
        $this->assertArrayHasKey('learning_pattern_family', $chain);
        $this->assertArrayHasKey('constraint_influences', $chain);
    }

    public function test_ac2_decision_chain_reflects_learning_pattern_family(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'learning_update' => ['pattern_family' => 'wiring-gap-reduction', 'delta' => 0.20],
            ]),
        ]]);

        $chain = $result['cycle_receipts'][0]['decision_chain'];
        $this->assertSame('wiring-gap-reduction', $chain['learning_pattern_family']);
    }

    public function test_ac2_decision_chain_lists_constraint_influence_keys(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'next_batch_constraint' => [
                    'promoted_rule'  => 'require_behavior_proof',
                    'blocked_family' => 'proxy_tasks',
                ],
            ]),
        ]]);

        $influences = $result['cycle_receipts'][0]['decision_chain']['constraint_influences'];
        $this->assertContains('promoted_rule', $influences);
        $this->assertContains('blocked_family', $influences);
    }

    public function test_ac2_decision_chain_outcome_type_propagates(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'outcome_type'    => 'success',
                'learning_update' => ['pattern_family' => 'test', 'routed_to_learner' => true],
            ]),
        ]]);

        $this->assertSame('success', $result['cycle_receipts'][0]['decision_chain']['outcome_type']);
    }

    // ── AC3: next_repair_task_hint for missing outcome routing links

    public function test_ac3_missing_learner_route_gives_route_success_hint(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['outcome_type' => 'success']),
        ]]);

        $this->assertSame('route_success_outcome_to_outcome_learner', $result['next_repair_task_hint']);
    }

    public function test_ac3_missing_policy_route_gives_route_give_back_hint(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['outcome_type' => 'give_back']),
        ]]);

        $this->assertSame('route_give_back_outcome_to_policy_adjuster', $result['next_repair_task_hint']);
    }

    public function test_ac3_missing_maestro_route_gives_quarantine_hint(): void
    {
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['outcome_type' => 'quarantine']),
        ]]);

        $this->assertSame('route_quarantine_outcome_to_maestro_adjustment', $result['next_repair_task_hint']);
    }

    public function test_ac3_learning_record_missing_beats_routing_hint(): void
    {
        // If the learning record itself is missing, that's higher priority than the route hint.
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle([
                'outcome_type'   => 'success',
                'learning_update' => null,
            ]),
        ]]);

        $this->assertSame('run_outcome_learner_for_cycle', $result['next_repair_task_hint']);
    }

    // ── AC4: single raw receipt alone never completes the cycle

    public function test_ac4_origination_receipt_alone_is_not_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => [[
            'cycle_id'            => 'raw-only',
            'origination_receipt' => ['task_packet_id' => 'task-001'],
        ]]]);

        $this->assertFalse($result['complete']);
        $this->assertNotEmpty($result['missing_links']);
        $this->assertNotNull($result['next_repair_task_hint']);
    }

    public function test_ac4_all_five_links_required_for_complete(): void
    {
        // Four links present, one missing → incomplete.
        $result = $this->verifier->verify(['cycles' => [
            $this->baseCycle(['next_batch_constraint' => null]),
        ]]);

        $this->assertFalse($result['complete']);
    }

    public function test_ac4_all_five_links_present_marks_complete(): void
    {
        $result = $this->verifier->verify(['cycles' => [$this->baseCycle()]]);
        $this->assertTrue($result['complete']);
        $this->assertNull($result['next_repair_task_hint']);
    }
}
