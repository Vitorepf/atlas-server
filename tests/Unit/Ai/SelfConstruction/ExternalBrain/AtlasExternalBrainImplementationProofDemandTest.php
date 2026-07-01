<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImplementationProofDemand;
use Tests\TestCase;

final class AtlasExternalBrainImplementationProofDemandTest extends TestCase
{
    private function svc(): AtlasExternalBrainImplementationProofDemand
    {
        return new AtlasExternalBrainImplementationProofDemand;
    }

    private function derive(
        string $risk = 'low',
        string $targetClass = 'logic',
        string $valueMechanism = 'logic',
        bool $isPropertyGated = false,
    ): array {
        return $this->svc()->derive([
            'task' => [
                'risk_level' => $risk,
                'target_class' => $targetClass,
                'value_mechanism' => $valueMechanism,
                'is_property_gated' => $isPropertyGated,
            ],
        ]);
    }

    // ── base proof by target_class ────────────────────────────────────────────

    public function test_logic_target_class_requires_unit_test(): void
    {
        $r = $this->derive(targetClass: 'logic');
        $this->assertContains('unit_test', $r['required_proofs']);
    }

    public function test_command_target_class_requires_command_smoke(): void
    {
        $r = $this->derive(targetClass: 'command');
        $this->assertContains('command_smoke', $r['required_proofs']);
    }

    public function test_queue_target_class_requires_queue_health(): void
    {
        $r = $this->derive(targetClass: 'queue');
        $this->assertContains('queue_health', $r['required_proofs']);
    }

    public function test_doc_target_class_requires_doc_proposal(): void
    {
        $r = $this->derive(targetClass: 'doc');
        $this->assertContains('doc_proposal', $r['required_proofs']);
    }

    public function test_feature_target_class_requires_feature_test(): void
    {
        $r = $this->derive(targetClass: 'feature');
        $this->assertContains('feature_test', $r['required_proofs']);
    }

    // ── risk escalation ───────────────────────────────────────────────────────

    public function test_medium_risk_adds_collision_sweep(): void
    {
        $r = $this->derive(risk: 'medium');
        $this->assertContains('collision_sweep', $r['required_proofs']);
    }

    public function test_high_risk_adds_collision_sweep_and_runtime_receipt(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertContains('collision_sweep', $r['required_proofs']);
        $this->assertContains('runtime_receipt', $r['required_proofs']);
    }

    public function test_low_risk_does_not_add_collision_sweep(): void
    {
        $r = $this->derive(risk: 'low');
        $this->assertNotContains('collision_sweep', $r['required_proofs']);
    }

    // ── implementation_notes_sufficient ──────────────────────────────────────

    public function test_high_risk_implementation_notes_not_sufficient(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_property_gated_flag_makes_notes_insufficient(): void
    {
        $r = $this->derive(risk: 'low', isPropertyGated: true);
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_property_gated_value_mechanism_makes_notes_insufficient(): void
    {
        $r = $this->derive(risk: 'low', valueMechanism: 'property_gated');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_property_gated_adds_runtime_receipt(): void
    {
        $r = $this->derive(risk: 'low', isPropertyGated: true);
        $this->assertContains('runtime_receipt', $r['required_proofs']);
    }

    public function test_low_risk_non_gated_notes_sufficient(): void
    {
        $r = $this->derive(risk: 'low');
        $this->assertTrue($r['implementation_notes_sufficient']);
    }

    public function test_medium_risk_non_gated_notes_sufficient(): void
    {
        $r = $this->derive(risk: 'medium');
        $this->assertTrue($r['implementation_notes_sufficient']);
    }

    // ── deduplication ─────────────────────────────────────────────────────────

    public function test_high_risk_runtime_target_does_not_duplicate_runtime_receipt(): void
    {
        $r = $this->derive(risk: 'high', targetClass: 'runtime');

        $count = count(array_filter($r['required_proofs'],
            static fn (string $p): bool => $p === 'runtime_receipt'));

        $this->assertSame(1, $count);
    }

    // ── rationale ─────────────────────────────────────────────────────────────

    public function test_high_risk_rationale_mentions_runnable_gate(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertStringContainsString('runnable gate mandatory', $r['minimum_proof_rationale']);
    }

    public function test_property_gated_rationale_mentions_runtime_receipt(): void
    {
        $r = $this->derive(isPropertyGated: true);
        $this->assertStringContainsString('runtime receipt mandatory', $r['minimum_proof_rationale']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->derive([]);
        $this->assertSame(AtlasExternalBrainImplementationProofDemand::SCHEMA, $r['schema_version']);
    }

    // ── minimum_required_evidence per task family ────────────────────────────

    public function test_minimum_required_evidence_is_emitted_per_task_family(): void
    {
        $r = $this->derive(targetClass: 'command');

        $this->assertArrayHasKey('minimum_required_evidence', $r);
        $this->assertSame('command', $r['minimum_required_evidence']['task_family']);
        $this->assertArrayHasKey('command_smoke', $r['minimum_required_evidence']['evidence_by_proof_type']);
        $this->assertNotEmpty($r['minimum_required_evidence']['evidence_by_proof_type']['command_smoke']);
    }

    public function test_minimum_required_evidence_covers_every_required_proof(): void
    {
        $r = $this->derive(risk: 'high', targetClass: 'queue');

        $evidenceKeys = array_keys($r['minimum_required_evidence']['evidence_by_proof_type']);
        sort($evidenceKeys);
        $requiredProofs = $r['required_proofs'];
        sort($requiredProofs);

        $this->assertSame($requiredProofs, $evidenceKeys);
    }

    // ── proxy proof rejection ─────────────────────────────────────────────────

    public function test_rejects_class_exists_as_proxy_proof(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_CLASS_EXISTS);

        $this->assertFalse($r['accepted']);
        $this->assertTrue($r['is_proxy']);
    }

    public function test_rejects_schema_only_as_proxy_proof(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_SCHEMA_ONLY);

        $this->assertFalse($r['accepted']);
        $this->assertTrue($r['is_proxy']);
    }

    public function test_rejects_wrapper_exit_zero_as_proxy_proof(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_WRAPPER_EXIT_ZERO);

        $this->assertFalse($r['accepted']);
        $this->assertTrue($r['is_proxy']);
    }

    public function test_rejects_test_presence_as_proxy_proof(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_TEST_PRESENCE);

        $this->assertFalse($r['accepted']);
        $this->assertTrue($r['is_proxy']);
    }

    // ── real proof acceptance ─────────────────────────────────────────────────

    public function test_accepts_behavior_proof(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_BEHAVIOR_PROOF);

        $this->assertTrue($r['accepted']);
        $this->assertFalse($r['is_proxy']);
    }

    public function test_accepts_regression_proof(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_REGRESSION_PROOF);

        $this->assertTrue($r['accepted']);
    }

    public function test_accepts_runtime_decision_change(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_RUNTIME_DECISION_CHANGE);

        $this->assertTrue($r['accepted']);
    }

    public function test_accepts_measurable_queue_quality_improvement(): void
    {
        $r = $this->svc()->verifySubmittedProof(AtlasExternalBrainImplementationProofDemand::PROOF_MEASURABLE_QUEUE_QUALITY_IMPROVEMENT);

        $this->assertTrue($r['accepted']);
    }

    public function test_unrecognized_proof_type_is_not_accepted(): void
    {
        $r = $this->svc()->verifySubmittedProof('some_unknown_proof_type');

        $this->assertFalse($r['accepted']);
        $this->assertFalse($r['is_proxy']);
    }

    // ── AC3: output includes rejected_proxy_proofs and proof_gap_reasons ──────

    public function test_derive_output_includes_rejected_proxy_proofs(): void
    {
        $r = $this->derive();

        $this->assertArrayHasKey('rejected_proxy_proofs', $r);
        foreach (AtlasExternalBrainImplementationProofDemand::REJECTED_PROXY_PROOF_TYPES as $proxy) {
            $this->assertContains($proxy, $r['rejected_proxy_proofs']);
        }
    }

    public function test_derive_output_includes_proof_gap_reasons_for_high_risk(): void
    {
        $r = $this->derive(risk: 'high');

        $this->assertArrayHasKey('proof_gap_reasons', $r);
        $this->assertNotEmpty($r['proof_gap_reasons']);
    }

    public function test_high_risk_requires_runtime_receipt_plus_behavior_delta(): void
    {
        $r = $this->derive(risk: 'high');
        $this->assertContains('runtime_receipt', $r['required_proofs']);
        $this->assertContains('behavior_delta', $r['required_proofs']);
    }

    public function test_property_gated_requires_runtime_receipt_plus_behavior_delta(): void
    {
        $r = $this->derive(isPropertyGated: true);
        $this->assertContains('runtime_receipt', $r['required_proofs']);
        $this->assertContains('behavior_delta', $r['required_proofs']);
    }

    public function test_before_after_evidence_is_accepted_as_real_proof(): void
    {
        $result = $this->svc()->verifySubmittedProof('before_after_evidence');
        $this->assertTrue($result['accepted']);
        $this->assertFalse($result['is_proxy']);
    }

    public function test_worker_continuity_target_class_requires_worker_continuity_proof(): void
    {
        $r = $this->derive(targetClass: 'worker_continuity');
        $this->assertContains('worker_continuity', $r['required_proofs']);
    }

    public function test_queue_target_class_does_not_allow_implementation_notes_alone(): void
    {
        $r = $this->derive(targetClass: 'queue');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_worker_continuity_target_class_does_not_allow_implementation_notes_alone(): void
    {
        $r = $this->derive(targetClass: 'worker_continuity');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_low_risk_pure_logic_task_can_use_unit_test_and_notes(): void
    {
        $r = $this->derive(targetClass: 'logic');
        $this->assertTrue($r['implementation_notes_sufficient']);
        $this->assertContains('unit_test', $r['required_proofs']);
    }

    public function test_derive_output_includes_proof_gap_reasons_for_low_risk(): void
    {
        $r = $this->derive(risk: 'low');

        $this->assertArrayHasKey('proof_gap_reasons', $r);
        $this->assertNotEmpty($r['proof_gap_reasons']);
    }

    // ── AC: value_mechanism-specific proof binding ────────────────────────────

    public function test_autonomy_value_mechanism_adds_autonomy_or_runtime_decision_proof(): void
    {
        $r = $this->derive(valueMechanism: 'autonomy');

        $hasAutonomyProof = in_array('autonomy_steady_state', $r['required_proofs'], true)
            || in_array('runtime_decision_change', $r['required_proofs'], true);
        $this->assertTrue($hasAutonomyProof, 'autonomy value_mechanism must demand autonomy_steady_state or runtime_decision_change');
    }

    public function test_queue_health_value_mechanism_adds_measurable_queue_quality_improvement(): void
    {
        $r = $this->derive(valueMechanism: 'queue_health');

        $this->assertContains('measurable_queue_quality_improvement', $r['required_proofs']);
    }

    public function test_proxy_proof_types_still_never_satisfy_required_proofs(): void
    {
        $r = $this->derive(valueMechanism: 'autonomy');

        foreach (AtlasExternalBrainImplementationProofDemand::REJECTED_PROXY_PROOF_TYPES as $proxyType) {
            $this->assertNotContains($proxyType, $r['required_proofs']);
            $verdict = $this->svc()->verifySubmittedProof($proxyType);
            $this->assertFalse($verdict['accepted']);
            $this->assertTrue($verdict['is_proxy']);
        }
    }

    // ── AC1: refactor-class proof demand ────────────────────────────────────────

    public function test_refactor_target_class_requires_behavior_equivalence_consumer_impact_rollback_and_knowledge_sync(): void
    {
        $r = $this->derive(targetClass: 'refactor');

        $this->assertContains('behavior_equivalence', $r['required_proofs']);
        $this->assertContains('consumer_impact', $r['required_proofs']);
        $this->assertContains('rollback_plan', $r['required_proofs']);
        $this->assertContains('knowledge_sync', $r['required_proofs']);
    }

    public function test_simplification_value_mechanism_requires_refactor_proof_set(): void
    {
        $r = $this->derive(valueMechanism: 'simplification');

        foreach (AtlasExternalBrainImplementationProofDemand::REFACTOR_PROOF_SET as $proof) {
            $this->assertContains($proof, $r['required_proofs']);
        }
    }

    public function test_consolidation_target_class_notes_are_not_sufficient(): void
    {
        $r = $this->derive(targetClass: 'consolidation');
        $this->assertFalse($r['implementation_notes_sufficient']);
    }

    public function test_refactor_proof_types_are_accepted_as_real_proof(): void
    {
        foreach (AtlasExternalBrainImplementationProofDemand::REFACTOR_PROOF_SET as $proof) {
            $verdict = $this->svc()->verifySubmittedProof($proof);
            $this->assertTrue($verdict['accepted'], "{$proof} should be accepted");
            $this->assertFalse($verdict['is_proxy']);
        }
    }

    public function test_refactor_rationale_mentions_behavior_equivalence(): void
    {
        $r = $this->derive(targetClass: 'refactor');
        $this->assertStringContainsString('behavior equivalence', $r['minimum_proof_rationale']);
    }

    // ── AC2/AC3: generic proof rejected for high-risk / refactor-class tasks ───

    public function test_generic_unit_test_rejected_for_high_risk_task(): void
    {
        $verdict = $this->svc()->verifySubmittedProof(
            AtlasExternalBrainImplementationProofDemand::PROOF_UNIT_TEST,
            riskLevel: 'high',
        );

        $this->assertFalse($verdict['accepted']);
        $this->assertFalse($verdict['is_proxy']);
    }

    public function test_generic_feature_test_rejected_for_refactor_class(): void
    {
        $verdict = $this->svc()->verifySubmittedProof(
            AtlasExternalBrainImplementationProofDemand::PROOF_FEATURE_TEST,
            targetClass: 'refactor',
        );

        $this->assertFalse($verdict['accepted']);
        $this->assertFalse($verdict['is_proxy']);
    }

    public function test_generic_unit_test_rejection_reason_is_specific_to_too_generic_not_unrecognized(): void
    {
        $lowRisk = $this->svc()->verifySubmittedProof(
            AtlasExternalBrainImplementationProofDemand::PROOF_UNIT_TEST,
            riskLevel: 'low',
            targetClass: 'logic',
        );
        $highRisk = $this->svc()->verifySubmittedProof(
            AtlasExternalBrainImplementationProofDemand::PROOF_UNIT_TEST,
            riskLevel: 'high',
            targetClass: 'logic',
        );

        $this->assertFalse($lowRisk['accepted']);
        $this->assertFalse($highRisk['accepted']);
        $this->assertStringContainsString('too generic', $highRisk['reason']);
        $this->assertStringNotContainsString('too generic', $lowRisk['reason']);
    }

    public function test_behavior_delta_still_accepted_for_high_risk_task(): void
    {
        // AC3: rejection targets GENERIC proofs, not all proofs — a real, specific proof still passes.
        $verdict = $this->svc()->verifySubmittedProof(
            AtlasExternalBrainImplementationProofDemand::PROOF_BEHAVIOR_DELTA,
            riskLevel: 'high',
        );

        $this->assertTrue($verdict['accepted']);
    }
}
