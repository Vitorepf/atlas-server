<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalCertificationGate;
use Tests\TestCase;

final class AtlasExternalBrainFinalCertificationGateTest extends TestCase
{
    private AtlasExternalBrainFinalCertificationGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainFinalCertificationGate;
    }

    private function fullEvidence(): array
    {
        return [
            'live_cycle_evidence'    => ['cycle_count' => 3, 'resolved_task_count' => 12, 'evidence_refs' => ['cycle_run_receipt']],
            'anti_goodhart'          => ['verdict' => 'pass', 'evidence_refs' => ['audit_report']],
            'self_improvement_cycle' => ['has_output' => true, 'recommendation_count' => 2, 'evidence_refs' => ['recommendation_receipt']],
            'muscle_learning'        => ['outcome_count' => 10, 'success_rate' => 0.8, 'evidence_refs' => ['outcome_ledger_ref']],
            'property_gated_path'    => ['ready' => true, 'blocking_gates' => [], 'evidence_refs' => ['gate_readiness_cert']],
            'doc_proposal'           => ['drafted' => true, 'certification_blocked' => false, 'evidence_refs' => ['doc_draft_ref']],
            'autonomy'               => ['human_dependency_in_loop' => false, 'provider_dependency_in_steady_state' => false, 'evidence_refs' => ['autonomy_assessment_ref']],
        ];
    }

    // ── AC1: missing evidence in any required finality dimension blocks certification

    public function test_ac1_missing_live_cycle_evidence_blocks_final_95(): void
    {
        $result = $this->gate->certify(array_merge($this->fullEvidence(), [
            'live_cycle_evidence' => ['cycle_count' => 0, 'resolved_task_count' => 0, 'evidence_refs' => []],
        ]));

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $blockerDims = array_column($result['blockers'], 'dimension');
        $this->assertContains('live_cycle_evidence', $blockerDims);
        $this->assertNotEmpty($result['blockers']);
    }

    public function test_ac1_missing_autonomy_proof_blocks_final_95(): void
    {
        $result = $this->gate->certify(array_merge($this->fullEvidence(), [
            'autonomy' => ['human_dependency_in_loop' => true, 'provider_dependency_in_steady_state' => false, 'evidence_refs' => []],
        ]));

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('autonomy_steady_state', array_column($result['blockers'], 'dimension'));
    }

    public function test_ac1_missing_outcome_learning_blocks_final_95(): void
    {
        $result = $this->gate->certify(array_merge($this->fullEvidence(), [
            'muscle_learning' => ['outcome_count' => 0, 'success_rate' => 0.0, 'evidence_refs' => []],
        ]));

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('muscle_outcome_learning', array_column($result['blockers'], 'dimension'));
    }

    public function test_ac1_missing_anti_goodhart_proof_blocks_final_95(): void
    {
        $result = $this->gate->certify(array_merge($this->fullEvidence(), [
            'anti_goodhart' => ['verdict' => 'repair_required', 'evidence_refs' => []],
        ]));

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('anti_goodhart_pass', array_column($result['blockers'], 'dimension'));
    }

    public function test_ac1_each_blocker_has_dimension_reason_and_action(): void
    {
        $result = $this->gate->certify([]);

        $this->assertNotEmpty($result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertArrayHasKey('dimension', $blocker);
            $this->assertArrayHasKey('reason', $blocker);
            $this->assertArrayHasKey('action', $blocker);
            $this->assertNotEmpty($blocker['dimension']);
            $this->assertNotEmpty($blocker['reason']);
            $this->assertNotEmpty($blocker['action']);
        }
    }

    // ── AC2: certification output has per-dimension passed, evidence_refs and missing_refs

    public function test_ac2_evidence_dossier_has_per_dimension_pass_fail(): void
    {
        $result = $this->gate->certify($this->fullEvidence());

        $this->assertArrayHasKey('evidence_dossier', $result);
        $this->assertCount(7, $result['evidence_dossier']);

        foreach ($result['evidence_dossier'] as $entry) {
            $this->assertArrayHasKey('dimension',     $entry);
            $this->assertArrayHasKey('passed',        $entry);
            $this->assertArrayHasKey('evidence_refs', $entry);
            $this->assertArrayHasKey('missing_refs',  $entry);
            $this->assertIsBool($entry['passed']);
            $this->assertIsArray($entry['evidence_refs']);
            $this->assertIsArray($entry['missing_refs']);
        }
    }

    public function test_ac2_evidence_refs_are_populated_from_input(): void
    {
        $result = $this->gate->certify($this->fullEvidence());
        $byDim  = array_column($result['evidence_dossier'], null, 'dimension');

        $this->assertContains('autonomy_assessment_ref',  $byDim['autonomy_steady_state']['evidence_refs']);
        $this->assertContains('outcome_ledger_ref',        $byDim['muscle_outcome_learning']['evidence_refs']);
        $this->assertContains('cycle_run_receipt',         $byDim['live_cycle_evidence']['evidence_refs']);
    }

    public function test_ac2_missing_refs_listed_when_required_ref_absent(): void
    {
        $evidence = $this->fullEvidence();
        unset($evidence['autonomy']['evidence_refs']);

        $result = $this->gate->certify($evidence);
        $byDim  = array_column($result['evidence_dossier'], null, 'dimension');

        $this->assertContains('autonomy_assessment_ref', $byDim['autonomy_steady_state']['missing_refs']);
    }

    public function test_ac2_passing_dimension_with_all_refs_has_empty_missing_refs(): void
    {
        $result = $this->gate->certify($this->fullEvidence());
        $byDim  = array_column($result['evidence_dossier'], null, 'dimension');

        $this->assertSame([], $byDim['autonomy_steady_state']['missing_refs']);
        $this->assertSame([], $byDim['muscle_outcome_learning']['missing_refs']);
    }

    public function test_ac2_failed_dimension_marked_as_not_passed_in_dossier(): void
    {
        $result = $this->gate->certify(array_merge($this->fullEvidence(), [
            'autonomy' => ['human_dependency_in_loop' => true, 'provider_dependency_in_steady_state' => false, 'evidence_refs' => ['autonomy_assessment_ref']],
        ]));
        $byDim = array_column($result['evidence_dossier'], null, 'dimension');

        $this->assertFalse($byDim['autonomy_steady_state']['passed']);
    }

    // ── AC3: high raw score cannot override hard missing autonomy or outcome-learning proof

    public function test_ac3_all_seven_logic_checks_pass_but_missing_autonomy_ref_blocks_final_95(): void
    {
        // All 7 dimensions satisfy their logic checks → would be 7/7 passed.
        // But autonomy has no evidence_refs → missing autonomy_assessment_ref.
        $evidence = $this->fullEvidence();
        $evidence['autonomy']['evidence_refs'] = []; // remove proof

        $result = $this->gate->certify($evidence);

        $this->assertSame(7, $result['passed_count'], 'all 7 logic checks must pass');
        $this->assertNotSame(
            AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95,
            $result['verdict'],
            'missing autonomy ref must block final_95 even when all logic checks pass',
        );
    }

    public function test_ac3_all_seven_logic_checks_pass_but_missing_outcome_learning_ref_blocks_final_95(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['muscle_learning']['evidence_refs'] = []; // remove outcome_ledger_ref

        $result = $this->gate->certify($evidence);

        $this->assertSame(7, $result['passed_count'], 'all 7 logic checks must pass');
        $this->assertNotSame(
            AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95,
            $result['verdict'],
            'missing outcome-learning ref must block final_95 even when all logic checks pass',
        );
    }

    public function test_ac3_verdict_is_near_final_not_below_when_logic_passes_but_ref_missing(): void
    {
        $evidence = $this->fullEvidence();
        $evidence['autonomy']['evidence_refs'] = [];

        $result = $this->gate->certify($evidence);

        // 7/7 logic pass → should not drop to below_final, only final_95 is blocked
        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_NEAR_FINAL, $result['verdict']);
    }

    public function test_ac3_final_95_requires_both_passing_logic_and_complete_evidence_refs(): void
    {
        // The only way to reach final_95 is all 7 pass AND all refs present
        $result = $this->gate->certify($this->fullEvidence());

        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertSame(7, $result['passed_count']);
        $this->assertSame([], $result['blockers']);
        foreach ($result['evidence_dossier'] as $entry) {
            $this->assertSame([], $entry['missing_refs'], "Unexpected missing_refs for {$entry['dimension']}");
        }
    }

    // ── AC4: pure and deterministic

    public function test_ac4_same_input_produces_identical_output(): void
    {
        $evidence = $this->fullEvidence();

        $this->assertSame(
            $this->gate->certify($evidence),
            $this->gate->certify($evidence),
        );
    }

    public function test_ac4_empty_evidence_returns_zero_passed_and_seven_blockers(): void
    {
        $result = $this->gate->certify([]);

        $this->assertSame(0, $result['passed_count']);
        $this->assertSame(7, $result['required_count']);
        $this->assertCount(7, $result['blockers']);
        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_BELOW_FINAL, $result['verdict']);
    }
}
