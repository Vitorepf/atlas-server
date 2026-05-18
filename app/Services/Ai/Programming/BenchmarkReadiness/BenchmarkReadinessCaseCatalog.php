<?php

namespace App\Services\Ai\Programming\BenchmarkReadiness;

/**
 * The 8 canonical readiness cases — one per case_type.
 *
 * Every case is text-only: a prompt + a scoring rubric + the telemetry/evidence
 * footprint expected from Atlas. NO inputs reference rivals, NO outputs claim
 * superiority. The catalog is the only place new cases get added so a single
 * code review reveals the universe of candidate benchmark cases.
 */
final class BenchmarkReadinessCaseCatalog
{
    /**
     * Materialize every canonical case. Order matters: it drives the suite
     * hash. Do not reorder without bumping the suite_id.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cases(): array
    {
        return [
            self::bugFix(),
            self::feature(),
            self::debug(),
            self::review(),
            self::research(),
            self::forgeObra(),
            self::longHorizonContinuation(),
            self::repairLoop(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function bugFix(): array
    {
        return self::case(
            caseId: 'bf-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_BUG_FIX,
            inputPrompt: 'Corrija um bug medio em servico de dominio com teste falhando. Patch deve ser minimo, atomico e auditado por verifier.',
            expectedArtifacts: [
                'patch_proposal',
                'test_impact_receipt',
                'verification_receipt',
            ],
            rubric: [
                'correctness' => 0.30,
                'scope_compliance' => 0.20,
                'test_coverage' => 0.20,
                'evidence_completeness' => 0.15,
                'safety' => 0.15,
            ],
            requiredTelemetry: ['flow_id', 'rag_gate_status', 'patch_verifier_status', 'test_outcome', 'evidence_completeness'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts', 'verification_receipt'],
            toolBundle: 'patch_proposal',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_LOW,
            difficulty: 'L2',
            notes: 'A canonical single-file bug fix with one failing test. Validates patch verifier + test impact analyzer.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function feature(): array
    {
        return self::case(
            caseId: 'ft-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_FEATURE,
            inputPrompt: 'Implemente uma feature pequena com endpoint, validacao, teste de feature e doc canonical atualizada.',
            expectedArtifacts: [
                'patch_proposal',
                'feature_test',
                'updated_canonical_doc',
                'verification_receipt',
            ],
            rubric: [
                'correctness' => 0.25,
                'scope_compliance' => 0.20,
                'test_coverage' => 0.20,
                'evidence_completeness' => 0.15,
                'completion_honesty' => 0.10,
                'safety' => 0.10,
            ],
            requiredTelemetry: ['flow_id', 'route_decision_id', 'rag_gate_status', 'patch_verifier_status', 'test_outcome', 'certification_status'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts', 'verification_receipt', 'evidence_pack'],
            toolBundle: 'patch_proposal',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_MEDIUM,
            difficulty: 'L3',
            notes: 'Validates Dev fast path + canonical doc update gate.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function debug(): array
    {
        return self::case(
            caseId: 'db-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_DEBUG,
            inputPrompt: 'Diagnostique uma falha intermitente em runtime; produza hipotese, reproducao minima e receipt de logs.',
            expectedArtifacts: [
                'failure_hypothesis',
                'minimal_reproduction',
                'test_log_receipt',
            ],
            rubric: [
                'correctness' => 0.25,
                'context_sufficiency' => 0.20,
                'evidence_completeness' => 0.20,
                'completion_honesty' => 0.20,
                'safety' => 0.15,
            ],
            requiredTelemetry: ['flow_id', 'rag_gate_status', 'retrieval_receipt_id', 'evidence_completeness', 'context_sufficiency'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts'],
            toolBundle: 'debug_sandbox',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_MEDIUM,
            difficulty: 'L3',
            notes: 'Validates RAG sufficiency under ambiguous failure signals; honesty over false-positive.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function review(): array
    {
        return self::case(
            caseId: 'rv-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_REVIEW,
            inputPrompt: 'Revise um diff multi-arquivo focando em scope drift, falhas de seguranca obvias e cobertura de teste.',
            expectedArtifacts: [
                'review_findings',
                'scope_compliance_report',
                'safety_findings',
            ],
            rubric: [
                'correctness' => 0.20,
                'scope_compliance' => 0.25,
                'safety' => 0.25,
                'evidence_completeness' => 0.15,
                'completion_honesty' => 0.15,
            ],
            requiredTelemetry: ['flow_id', 'rag_gate_status', 'evidence_completeness', 'certification_status'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts'],
            toolBundle: 'review',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_LOW,
            difficulty: 'L2',
            notes: 'No code mutation. Validates scope guard + review intelligence.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function research(): array
    {
        return self::case(
            caseId: 'rs-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_RESEARCH,
            inputPrompt: 'Resuma a literatura interna sobre uma area do Atlas; cite docs canonicas e classifique evidencias por confianca.',
            expectedArtifacts: [
                'research_summary',
                'cited_doc_refs',
                'confidence_classification',
            ],
            rubric: [
                'correctness' => 0.20,
                'context_sufficiency' => 0.25,
                'evidence_completeness' => 0.25,
                'completion_honesty' => 0.20,
                'safety' => 0.10,
            ],
            requiredTelemetry: ['flow_id', 'rag_gate_status', 'retrieval_receipt_id', 'context_sufficiency'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts'],
            toolBundle: 'research',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_LOW,
            difficulty: 'L2',
            notes: 'Validates RAG retrieval breadth + source attribution.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function forgeObra(): array
    {
        return self::case(
            caseId: 'fo-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_FORGE_OBRA,
            inputPrompt: 'Promova uma Obra Forge a partir de um intake estruturado; produza 5 milestones, work packets e plano de execucao auditado.',
            expectedArtifacts: [
                'forge_intake_payload',
                'milestones_5_canonical',
                'work_packet_manifest',
                'execution_plan',
            ],
            rubric: [
                'correctness' => 0.20,
                'scope_compliance' => 0.20,
                'evidence_completeness' => 0.20,
                'completion_honesty' => 0.15,
                'safety' => 0.15,
                'context_sufficiency' => 0.10,
            ],
            requiredTelemetry: ['flow_id', 'route_decision_id', 'rag_gate_status', 'evidence_completeness', 'certification_status'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts', 'evidence_pack', 'certification'],
            toolBundle: 'forge_obra',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_HIGH,
            difficulty: 'L4',
            notes: 'Validates Forge Intake + 5-milestone canon + work packet composer + execution cycle wiring.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function longHorizonContinuation(): array
    {
        return self::case(
            caseId: 'lh-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_LONG_HORIZON_CONTINUATION,
            inputPrompt: 'Retome uma Obra Forge interrompida em milestone implementation; preserve state hash, complete cycles e avance ate certification.',
            expectedArtifacts: [
                'long_horizon_state_resume',
                'cycle_continuation_log',
                'milestone_advance_receipts',
                'certification_receipt',
            ],
            rubric: [
                'correctness' => 0.20,
                'evidence_completeness' => 0.20,
                'completion_honesty' => 0.20,
                'scope_compliance' => 0.15,
                'repair_efficiency' => 0.15,
                'safety' => 0.10,
            ],
            requiredTelemetry: ['flow_id', 'rag_gate_status', 'evidence_completeness', 'certification_status', 'cycle_hash'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts', 'verification_receipt', 'evidence_pack', 'certification'],
            toolBundle: 'long_horizon',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_HIGH,
            difficulty: 'L5',
            notes: 'Validates long-horizon state determinism + cycle hash continuity across sessions.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function repairLoop(): array
    {
        return self::case(
            caseId: 'rp-001',
            caseType: BenchmarkReadinessCanon::CASE_TYPE_REPAIR_LOOP,
            inputPrompt: 'Conduza um repair loop apos teste vermelho; classifique failure signature, evite same_signature_twice e produza decision honesta.',
            expectedArtifacts: [
                'failure_signature',
                'repair_capsule',
                'repair_attempts_audit',
                'final_decision_receipt',
            ],
            rubric: [
                'correctness' => 0.20,
                'repair_efficiency' => 0.25,
                'completion_honesty' => 0.20,
                'evidence_completeness' => 0.15,
                'safety' => 0.10,
                'scope_compliance' => 0.10,
            ],
            requiredTelemetry: ['flow_id', 'repair_loop_status', 'patch_verifier_status', 'test_outcome', 'evidence_completeness'],
            requiredEvidence: ['plan', 'context_pack', 'work_packet_receipts', 'verification_receipt'],
            toolBundle: 'repair_loop',
            riskBand: BenchmarkReadinessCanon::RISK_BAND_MEDIUM,
            difficulty: 'L4',
            notes: 'Validates failure mode classifier + stop-on-same-signature invariant.',
        );
    }

    /**
     * @param  array<int,string>  $expectedArtifacts
     * @param  array<string,float>  $rubric
     * @param  array<int,string>  $requiredTelemetry
     * @param  array<int,string>  $requiredEvidence
     * @return array<string,mixed>
     */
    private static function case(
        string $caseId,
        string $caseType,
        string $inputPrompt,
        array $expectedArtifacts,
        array $rubric,
        array $requiredTelemetry,
        array $requiredEvidence,
        string $toolBundle,
        string $riskBand,
        string $difficulty,
        string $notes,
    ): array {
        $bundles = BenchmarkReadinessCanon::toolBundles();
        $allowedTools = $bundles[$toolBundle] ?? $bundles['read_only'];

        return [
            'case_id' => $caseId,
            'case_type' => $caseType,
            'input_prompt' => $inputPrompt,
            'expected_artifacts' => $expectedArtifacts,
            'scoring_rubric' => [
                'schema_version' => BenchmarkReadinessCanon::RUBRIC_SCHEMA_VERSION,
                'dimensions' => array_values(array_keys($rubric)),
                'weights' => $rubric,
                'pass_threshold' => 0.70,
                'must_have' => [
                    'work_packet_receipts',
                    'verification_receipt_or_explicit_waiver',
                ],
            ],
            'required_telemetry' => $requiredTelemetry,
            'required_evidence' => $requiredEvidence,
            'allowed_tools' => $allowedTools,
            'tool_bundle' => $toolBundle,
            'provider_slots' => [
                BenchmarkReadinessCanon::PROVIDER_SLOT_ATLAS => 'atlas_runtime_canonical',
                BenchmarkReadinessCanon::PROVIDER_SLOT_DEV => 'atlas_dev_fast_path',
                BenchmarkReadinessCanon::PROVIDER_SLOT_FORGE => 'atlas_forge_runtime',
                BenchmarkReadinessCanon::PROVIDER_SLOT_RIVAL_PLACEHOLDER => 'unbound_no_run',
            ],
            'risk_band' => $riskBand,
            'difficulty' => $difficulty,
            'notes' => $notes,
        ];
    }
}
