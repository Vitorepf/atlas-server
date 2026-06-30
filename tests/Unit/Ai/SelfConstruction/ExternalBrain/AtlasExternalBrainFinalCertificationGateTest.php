<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalCertificationGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFinalCertificationGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainFinalCertificationGate
    {
        return new AtlasExternalBrainFinalCertificationGate;
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

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.final_certification_gate.v1',
            AtlasExternalBrainFinalCertificationGate::SCHEMA,
        );
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->gate()->certify($this->fullEvidence());

        foreach (['schema', 'verdict', 'passed_count', 'required_count', 'blockers', 'dimension_results', 'evidence_dossier'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainFinalCertificationGate::SCHEMA, $result['schema']);
    }

    public function test_full_evidence_yields_final_95_candidate(): void
    {
        $result = $this->gate()->certify($this->fullEvidence());

        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame($result['required_count'], $result['passed_count']);
    }

    public function test_spec_only_evidence_cannot_reach_final_95_candidate(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'live_cycle_evidence' => ['cycle_count' => 0, 'resolved_task_count' => 0],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
    }

    public function test_queue_counts_only_cannot_reach_final_95_candidate(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'live_cycle_evidence' => ['cycle_count' => 0, 'resolved_task_count' => 0],
            'anti_goodhart'       => ['verdict' => 'unknown'],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);

        $blockerDims = array_column($result['blockers'], 'dimension');
        $this->assertContains('live_cycle_evidence', $blockerDims);
    }

    public function test_anti_goodhart_reject_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'anti_goodhart' => ['verdict' => 'reject'],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('anti_goodhart_pass', array_column($result['blockers'], 'dimension'));
    }

    public function test_no_self_improvement_output_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'self_improvement_cycle' => ['has_output' => false, 'recommendation_count' => 0],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('self_improvement_cycle_output', array_column($result['blockers'], 'dimension'));
    }

    public function test_no_muscle_outcomes_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'muscle_learning' => ['outcome_count' => 0, 'success_rate' => 0.0],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('muscle_outcome_learning', array_column($result['blockers'], 'dimension'));
    }

    public function test_property_gated_path_not_ready_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'property_gated_path' => ['ready' => false, 'blocking_gates' => ['gate_x', 'gate_y']],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('property_gated_path', array_column($result['blockers'], 'dimension'));
    }

    public function test_doc_proposal_not_drafted_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'doc_proposal' => ['drafted' => false, 'certification_blocked' => true],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('doc_proposal_readiness', array_column($result['blockers'], 'dimension'));
    }

    public function test_doc_proposal_drafted_but_cert_blocked_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'doc_proposal' => ['drafted' => true, 'certification_blocked' => true],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('doc_proposal_readiness', array_column($result['blockers'], 'dimension'));
    }

    public function test_human_dependency_in_loop_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'autonomy' => ['human_dependency_in_loop' => true, 'provider_dependency_in_steady_state' => false],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('autonomy_steady_state', array_column($result['blockers'], 'dimension'));
    }

    public function test_provider_dependency_in_steady_state_blocks_certification(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'autonomy' => ['human_dependency_in_loop' => false, 'provider_dependency_in_steady_state' => true],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertContains('autonomy_steady_state', array_column($result['blockers'], 'dimension'));
    }

    public function test_every_missing_dimension_emits_actionable_blocker(): void
    {
        $result = $this->gate()->certify([]);

        $this->assertCount(7, $result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertArrayHasKey('dimension', $blocker);
            $this->assertArrayHasKey('reason', $blocker);
            $this->assertArrayHasKey('action', $blocker);
            $this->assertNotEmpty($blocker['action']);
        }
    }

    public function test_near_final_verdict_when_5_of_7_pass(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'live_cycle_evidence'    => ['cycle_count' => 0, 'resolved_task_count' => 0],
            'self_improvement_cycle' => ['has_output' => false, 'recommendation_count' => 0],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_NEAR_FINAL, $result['verdict']);
        $this->assertSame(5, $result['passed_count']);
    }

    public function test_below_final_verdict_when_fewer_than_5_pass(): void
    {
        $result = $this->gate()->certify([]);

        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_BELOW_FINAL, $result['verdict']);
        $this->assertSame(0, $result['passed_count']);
    }

    public function test_dimension_results_covers_all_seven_dimensions(): void
    {
        $result = $this->gate()->certify($this->fullEvidence());

        $this->assertCount(7, $result['dimension_results']);
        foreach ($result['dimension_results'] as $passed) {
            $this->assertIsBool($passed);
        }
    }

    public function test_partial_evidence_never_overclaims_final_95(): void
    {
        $evidence = array_merge($this->fullEvidence(), [
            'live_cycle_evidence'    => ['cycle_count' => 0, 'resolved_task_count' => 0],
            'anti_goodhart'          => ['verdict' => 'reject'],
            'self_improvement_cycle' => ['has_output' => false, 'recommendation_count' => 0],
            'muscle_learning'        => ['outcome_count' => 0, 'success_rate' => 0.0],
        ]);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
        $this->assertGreaterThan(0, count($result['blockers']));
    }

    // ── AC1: evidence_dossier ─────────────────────────────────────────────────

    public function test_evidence_dossier_covers_all_seven_dimensions(): void
    {
        $result = $this->gate()->certify($this->fullEvidence());

        $this->assertCount(7, $result['evidence_dossier']);
        foreach ($result['evidence_dossier'] as $entry) {
            $this->assertArrayHasKey('dimension',     $entry);
            $this->assertArrayHasKey('passed',        $entry);
            $this->assertArrayHasKey('evidence_refs', $entry);
            $this->assertArrayHasKey('missing_refs',  $entry);
        }
    }

    public function test_evidence_dossier_reflects_provided_refs(): void
    {
        $result = $this->gate()->certify($this->fullEvidence());
        $byDim  = array_column($result['evidence_dossier'], null, 'dimension');

        $this->assertContains('cycle_run_receipt', $byDim['live_cycle_evidence']['evidence_refs']);
        $this->assertContains('audit_report',      $byDim['anti_goodhart_pass']['evidence_refs']);
    }

    // ── AC2: final_95_candidate requires evidence_refs per passing dim ────────

    public function test_final_95_candidate_requires_evidence_refs_for_each_passing_dimension(): void
    {
        // Full evidence WITH refs → should be final_95_candidate.
        $result = $this->gate()->certify($this->fullEvidence());
        $this->assertSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
    }

    public function test_missing_evidence_refs_blocks_final_95_candidate_even_when_all_dims_pass(): void
    {
        // Remove evidence_refs from one passing dimension.
        $evidence = $this->fullEvidence();
        unset($evidence['live_cycle_evidence']['evidence_refs']);

        $result = $this->gate()->certify($evidence);

        $this->assertNotSame(AtlasExternalBrainFinalCertificationGate::VERDICT_FINAL_95, $result['verdict']);
    }

    public function test_evidence_dossier_lists_missing_refs_for_dim_without_refs(): void
    {
        $evidence = $this->fullEvidence();
        unset($evidence['live_cycle_evidence']['evidence_refs']);

        $result = $this->gate()->certify($evidence);
        $byDim  = array_column($result['evidence_dossier'], null, 'dimension');

        $this->assertNotEmpty($byDim['live_cycle_evidence']['missing_refs']);
        $this->assertContains('cycle_run_receipt', $byDim['live_cycle_evidence']['missing_refs']);
    }

    public function test_no_missing_refs_when_all_required_refs_are_provided(): void
    {
        $result = $this->gate()->certify($this->fullEvidence());

        foreach ($result['evidence_dossier'] as $entry) {
            $this->assertSame([], $entry['missing_refs'], "Unexpected missing_refs for {$entry['dimension']}");
        }
    }
}
