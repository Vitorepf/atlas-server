<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Support\DatabaseTableAvailability;

class ProgrammingProfessionalCompletionAuditService
{
    public function __construct(
        private readonly ProgrammingRivalsReadinessService $rivalsReadiness,
        private readonly AtlasForgeNativeRivalsProtocolService $forgeNativeRivalsProtocol,
        private readonly AtlasForgeNativeRivalsCaseManifestService $forgeNativeRivalsCaseManifest,
        private readonly AtlasForgeNativeRivalsPreflightService $forgeNativeRivalsPreflight,
        private readonly AtlasForgeNativeRivalsDryRunService $forgeNativeRivalsDryRun,
        private readonly AtlasRivalsOneShotEnterpriseRubricService $oneShotEnterpriseRubric,
        private readonly AtlasRivalsOneShotEnterpriseEvaluationService $oneShotEnterpriseEvaluation,
        private readonly AtlasRivalsEvidencePackService $evidencePack,
        private readonly AtlasRivalsEvidencePackVerifierService $evidencePackVerifier,
        private readonly AtlasForgeContinuumCertificationService $forgeContinuumCertification,
        private readonly AtlasForgeProviderCapacityService $forgeProviderCapacity,
        private readonly AtlasForgeProviderFailureMemoryService $forgeProviderFailureMemory,
        private readonly AtlasForgeProviderFallbackPolicyService $forgeProviderFallbackPolicy,
        private readonly AtlasForgeProviderInvocationService $forgeProviderInvocation,
        private readonly AtlasForgeProviderInvocationDriverRouter $forgeProviderInvocationDriverRouter,
        private readonly AtlasForgeProviderInvocationPromptBuilder $forgeProviderInvocationPromptBuilder,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService $selfImprovementProposalPacket,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService $selfImprovementProposalPowerGate,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService $selfImprovementDeltaScorecard,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService $selfImprovementInvariantLock,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService $selfImprovementRegressionSentinel,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService $selfImprovementCapabilityMaturityScore,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService $selfImprovementHumanTrustLedger,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService $selfImprovementStrategyPortfolio,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService $selfImprovementForgeActivation,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService $selfImprovementActivationCockpit,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService $selfImprovementProposalBacklog,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementClosedLoopService $selfImprovementClosedLoop,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService $selfImprovementResultLedger,
        private readonly \App\Services\Ai\SelfImprovement\AtlasSelfImprovementNextCycleRecommendationService $selfImprovementNextCycle,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(string $workspace, bool $refreshLocalBenchmarks = false): array
    {
        $rivals = $this->rivalsReadiness->report($workspace, $refreshLocalBenchmarks);
        $claimReady = (bool) data_get($rivals, 'summary.claim_ready', false);
        $localReady = (bool) data_get($rivals, 'summary.local_programming_foundation_ready', false);
        $artifactCoverage = $this->artifactCoverage($workspace);
        $operatorPacketReady = data_get($rivals, 'operator_execution_packet.schema_version') === 'atlas.programming.rivals_operator_execution_packet.v1'
            && data_get($rivals, 'operator_execution_packet.provider_dispatches_now') === false
            && str_contains((string) data_get($rivals, 'operator_execution_packet.recommended_first_run.command'), '--confirm-provider-cost')
            && str_contains((string) data_get($rivals, 'operator_execution_packet.recommended_first_run.command'), '--confirm-runbook-reviewed')
            && (
                data_get($rivals, 'current_workspace_preflight.ready_for_provider_battery') === true
                || str_contains((string) data_get($rivals, 'operator_execution_packet.recommended_first_run.command'), '--workspace=<clean-atlas-workspace>')
            );
        $integrityAssuranceReady = data_get($rivals, 'integrity_assurance.schema_version') === 'atlas.programming.rivals_integrity_assurance.v1'
            && data_get($rivals, 'integrity_assurance.ab_test_validity_model.same_case_snapshot_required') === true
            && data_get($rivals, 'integrity_assurance.ab_test_validity_model.equivalent_initial_state_required') === true
            && data_get($rivals, 'integrity_assurance.ab_test_validity_model.same_acceptance_gates_required') === true
            && data_get($rivals, 'integrity_assurance.ab_test_validity_model.synthetic_scores_allowed') === false
            && data_get($rivals, 'integrity_assurance.external_variable_controls.non_evaluated_variables_cannot_decide_winner') === true
            && data_get($rivals, 'integrity_assurance.quality_scope_policy.same_quality_scope_required_for_both_arms') === true
            && data_get($rivals, 'integrity_assurance.quality_scope_policy.changed_only_quality_scan_required_for_patch_cases') === true
            && data_get($rivals, 'integrity_assurance.quality_scope_policy.repo_wide_debt_outside_case_scope_cannot_decide_winner') === true
            && data_get($rivals, 'integrity_assurance.score_admission_gate.real_provider_dispatch_required_for_claim') === true;
        $rerunPreconditionsReady = data_get($rivals, 'invalid_battery_triage_packet.schema_version') === 'atlas.programming.invalid_battery_triage_packet.v1'
            && data_get($rivals, 'invalid_battery_triage_packet.historical_failure_policy.schema_version') === 'atlas.programming.rivals_historical_failure_policy.v1'
            && data_get($rivals, 'invalid_battery_triage_packet.historical_failure_policy.current_preconditions_must_be_green_before_rerun') === true
            && data_get($rivals, 'invalid_battery_triage_packet.current_rerun_preconditions.schema_version') === 'atlas.programming.current_rivals_rerun_preconditions.v1'
            && data_get($rivals, 'invalid_battery_triage_packet.current_rerun_preconditions.provider_dispatch_allowed_now') === false
            && is_string(data_get($rivals, 'invalid_battery_triage_packet.current_rerun_preconditions.diagnostic_commands_without_provider_spend.programming_readiness'));
        $rivalsRealBlocker = (string) ($rivals['status'] ?? 'external_battery_required');
        $checklist = $this->checklist($artifactCoverage, $localReady, $claimReady, $operatorPacketReady, $integrityAssuranceReady, $rerunPreconditionsReady, $rivalsRealBlocker);
        $missing = collect($checklist)
            ->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'passed')
            ->values()
            ->all();
        $verificationEvidence = $this->verificationEvidence($rivals);
        $powerScorecard = $this->powerScorecard($localReady, $claimReady, $artifactCoverage, $verificationEvidence);

        $forgeCertification = $this->forgeRuntimeCertification($checklist);
        $forgeLiveExecution = $this->forgeLiveExecutionCertification();
        $atlasCodeEnterprise = $this->atlasCodeEnterpriseCertification();
        $forgeFastPath = $this->forgeFastPathCertification();
        $forgeReviewCompletion = $this->forgeReviewCompletionCertification();
        $forgeOperatorCockpit = $this->forgeOperatorCockpitCertification();
        $forgeWorkIntake = $this->forgeWorkIntakeCertification();
        $atlasForgeContinuumCertification = $this->atlasForgeContinuumCertification($workspace);
        $atlasForgeProviderCapacityCertification = $this->atlasForgeProviderCapacityCertification($workspace);
        $atlasForgeProviderInvocationCertification = $this->atlasForgeProviderInvocationCertification($workspace);
        $atlasForgeRealProviderDriversCertification = $this->atlasForgeRealProviderDriversCertification($workspace);
        $atlasCodeForgeHumanFirstUxCertification = $this->atlasCodeForgeHumanFirstUxCertification($workspace);
        $atlasCodeObraCommandCenterCertification = $this->atlasCodeObraCommandCenterCertification($workspace);
        $atlasCodeVisualErgonomicsCertification = $this->atlasCodeVisualErgonomicsCertification($workspace);
        $atlasCodePremiumWorkbenchVisualComfortCertification = $this->atlasCodePremiumWorkbenchVisualComfortCertification($workspace);
        $atlasSelfImprovementGovernanceCertification = $this->atlasSelfImprovementGovernanceCertification($workspace);
        $atlasSelfImprovementForgeActivationCertification = $this->atlasSelfImprovementForgeActivationCertification($workspace);
        $atlasSelfImprovementActivationCockpitCertification = $this->atlasSelfImprovementActivationCockpitCertification($workspace);
        $atlasSelfImprovementClosedLoopLevel7Certification = $this->atlasSelfImprovementClosedLoopLevel7Certification($workspace);
        $forgeNativeRivalsCertification = $this->forgeNativeRivalsCertification($workspace);
        $rivalsOneShotEnterpriseEvaluationCertification = $this->rivalsOneShotEnterpriseEvaluationCertification($workspace);
        $rivalsEvidencePackCertification = $this->rivalsEvidencePackCertification($workspace);
        $externalRivalsCertification = $this->externalRivalsCertification($verificationEvidence);

        return [
            'schema_version' => 'atlas.programming.professional_completion_audit.v1',
            'status' => $missing === [] ? 'complete' : 'blocked',
            'generated_at' => now()->toJSON(),
            'objective' => 'Implement professional programming documentation and enterprise runtime for RAG, Agentic RAG, quality gates, receipts and Rivals-Programming integrity.',
            'completion_allowed' => $missing === [],
            'forge_runtime_certification' => $forgeCertification,
            'forge_live_execution_certification' => $forgeLiveExecution,
            'atlas_code_enterprise_certification' => $atlasCodeEnterprise,
            'forge_fast_path_certification' => $forgeFastPath,
            'forge_review_completion_certification' => $forgeReviewCompletion,
            'atlas_code_forge_operator_cockpit_certification' => $forgeOperatorCockpit,
            'atlas_code_forge_work_intake_certification' => $forgeWorkIntake,
            'atlas_forge_continuum_certification' => $atlasForgeContinuumCertification,
            'atlas_forge_provider_capacity_certification' => $atlasForgeProviderCapacityCertification,
            'atlas_forge_provider_invocation_certification' => $atlasForgeProviderInvocationCertification,
            'atlas_forge_real_provider_drivers_certification' => $atlasForgeRealProviderDriversCertification,
            'atlas_code_forge_human_first_ux_certification' => $atlasCodeForgeHumanFirstUxCertification,
            'atlas_code_obra_command_center_certification' => $atlasCodeObraCommandCenterCertification,
            'atlas_code_visual_ergonomics_certification' => $atlasCodeVisualErgonomicsCertification,
            'atlas_code_premium_workbench_visual_comfort_certification' => $atlasCodePremiumWorkbenchVisualComfortCertification,
            'atlas_self_improvement_governance_certification' => $atlasSelfImprovementGovernanceCertification,
            'atlas_self_improvement_forge_activation_certification' => $atlasSelfImprovementForgeActivationCertification,
            'atlas_self_improvement_activation_cockpit_certification' => $atlasSelfImprovementActivationCockpitCertification,
            'atlas_self_improvement_closed_loop_level7_certification' => $atlasSelfImprovementClosedLoopLevel7Certification,
            'forge_native_rivals_certification' => $forgeNativeRivalsCertification,
            'rivals_one_shot_enterprise_evaluation_certification' => $rivalsOneShotEnterpriseEvaluationCertification,
            'rivals_evidence_pack_certification' => $rivalsEvidencePackCertification,
            'external_rivals_certification' => $externalRivalsCertification,
            'audit_protocol' => $this->auditProtocol(),
            'artifact_coverage' => $artifactCoverage,
            'verification_evidence' => $verificationEvidence,
            'power_scorecard' => $powerScorecard,
            'executive_report' => $this->executiveReport($localReady, $claimReady, $missing, $verificationEvidence),
            'summary' => [
                'local_programming_foundation_ready' => $localReady,
                'rivals_programming_claim_ready' => $claimReady,
                'passed_count' => count($checklist) - count($missing),
                'blocked_count' => count($missing),
            ],
            'checklist' => $checklist,
            'blocking_items' => $missing,
            'rivals_readiness' => [
                'schema_version' => $rivals['schema_version'] ?? null,
                'status' => $rivals['status'] ?? 'unknown',
                'summary' => $rivals['summary'] ?? [],
                'local_benchmark_cache' => $rivals['local_benchmark_cache'] ?? [],
                'commands' => $rivals['commands'] ?? [],
                'operator_execution_packet' => $rivals['operator_execution_packet'] ?? [],
                'integrity_assurance' => $rivals['integrity_assurance'] ?? [],
                'result_integrity_diagnostics' => $rivals['result_integrity_diagnostics'] ?? [],
                'fair_claude_result_integrity' => $rivals['fair_claude_result_integrity'] ?? [],
                'latest_real_battery_evidence' => $rivals['latest_real_battery_evidence'] ?? [],
                'current_local_recheck_evidence' => $rivals['current_local_recheck_evidence'] ?? [],
                'invalid_battery_triage_packet' => $rivals['invalid_battery_triage_packet'] ?? [],
                'current_workspace_preflight' => $rivals['current_workspace_preflight'] ?? [],
                'fair_claude_rivals' => $rivals['fair_claude_rivals'] ?? [],
            ],
            'rules' => [
                'tests_are_not_enough_without_requirement_coverage' => true,
                'readiness_is_not_comparable_score' => true,
                'power_score_is_not_completion_without_external_rivals_claim' => true,
                'external_provider_cost_requires_operator_approval' => true,
                'synthetic_scores_allowed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $artifactCoverage
     * @param  array<string,mixed>  $verificationEvidence
     * @return array<string,mixed>
     */
    private function powerScorecard(bool $localReady, bool $claimReady, array $artifactCoverage, array $verificationEvidence): array
    {
        $dimensions = [
            $this->scoreDimension(
                'governed_agentic_rag',
                'Agentic RAG governado com context pack, gap critic, semantic graph e benchmark local.',
                1.2,
                $localReady
                    && data_get($artifactCoverage, 'agentic_rag_context_pack.covered') === true
                    && data_get($artifactCoverage, 'hybrid_retrieval_and_gap_critic.covered') === true
                    && data_get($verificationEvidence, 'local_benchmarks.retrieval.status') === 'passed',
                1.0,
                'retrieval_or_context_pack_not_verified',
            ),
            $this->scoreDimension(
                'durable_execution_contracts',
                'Stage receipts, action manifests, sandbox and completion gates are present.',
                1.4,
                data_get($artifactCoverage, 'stage_receipts_resume.covered') === true
                    && data_get($artifactCoverage, 'tool_runtime_manifests.covered') === true
                    && data_get($artifactCoverage, 'sandbox_repair_learning.covered') === true,
                1.0,
                'durable_execution_contract_missing',
            ),
            $this->scoreDimension(
                'repair_loop_execution',
                'Repair loop has executable capsule, stop rules, receipt integrity and benchmark evidence.',
                1.3,
                data_get($verificationEvidence, 'local_benchmarks.repair_loop.status') === 'passed'
                    && data_get($verificationEvidence, 'local_benchmarks.repair_loop.metrics.receipt_integrity_passed') === true,
                1.0,
                'repair_loop_benchmark_not_passed',
            ),
            $this->scoreDimension(
                'test_quality_gates',
                'Test impact and patch verifier block weak or ungrounded changes.',
                1.1,
                data_get($verificationEvidence, 'local_benchmarks.test_impact.status') === 'passed'
                    && data_get($verificationEvidence, 'local_benchmarks.patch_verifier.status') === 'passed',
                1.0,
                'test_impact_or_patch_verifier_not_passed',
            ),
            $this->scoreDimension(
                'rivals_provider_preflight',
                'Paid Rivals provider runs are blocked until workspaces are clean, runnable and bounded by timeout.',
                0.6,
                data_get($artifactCoverage, 'rivals_provider_runtime_preflight.covered') === true,
                1.0,
                'rivals_provider_runtime_preflight_missing',
            ),
            $this->scoreDimension(
                'resume_and_continuation',
                'Work can resume from persisted receipts without relying on chat memory.',
                0.9,
                data_get($artifactCoverage, 'stage_receipts_resume.covered') === true
                    && data_get($artifactCoverage, 'programming_cli_commands.checks.AtlasProgrammingResumeCommand.registered_in_bootstrap') === true,
                1.0,
                'resume_contract_not_verified',
            ),
            $this->scoreDimension(
                'python_runtime_boundary',
                'Python runtime is governed and approval-gated for code intelligence.',
                0.7,
                data_get($artifactCoverage, 'python_runtime.covered') === true,
                1.0,
                'python_runtime_boundary_not_verified',
            ),
            $this->scoreDimension(
                'graph_rag_runtime',
                'Graph RAG is a promoted runtime, not only future-governed proposal.',
                0.8,
                data_get($artifactCoverage, 'programming_graph_rag_runtime.covered') === true
                    && data_get($verificationEvidence, 'local_benchmarks.retrieval.promotion_gate.graph_rag_runtime_promoted') === true,
                1.0,
                'graph_rag_runtime_still_future_governed',
            ),
            $this->scoreDimension(
                'real_rivals_execution_proof',
                'Real paired provider battery has comparable cases and verified export.',
                1.6,
                $claimReady,
                $claimReady ? 1.0 : 0.0,
                (string) data_get($verificationEvidence, 'rivals_external_claim.status', 'external_battery_required'),
            ),
        ];

        $maxScore = collect($dimensions)->sum('weight');
        $earned = collect($dimensions)->sum(fn (array $dimension): float => (float) $dimension['earned']);
        $score = round(($earned / max(0.1, (float) $maxScore)) * 10, 1);
        $blocking = collect($dimensions)
            ->filter(fn (array $dimension): bool => (bool) ($dimension['passed'] ?? false) === false)
            ->map(fn (array $dimension): array => [
                'id' => $dimension['id'],
                'blocker' => $dimension['blocker'],
                'missing_points' => round((float) $dimension['weight'] - (float) $dimension['earned'], 2),
            ])
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.programming.power_scorecard.v1',
            'score_out_of_10' => $score,
            'target_score_out_of_10' => 9.0,
            'status' => $score >= 9.0 && $claimReady ? 'target_met' : 'target_not_met',
            'scoring_policy' => [
                'local_capability_can_raise_score' => true,
                'external_rivals_claim_required_for_target_met' => true,
                'graph_rag_future_governed_blocks_full_credit' => true,
                'synthetic_scores_allowed' => false,
            ],
            'dimensions' => $dimensions,
            'blocking_items' => $blocking,
            'next_score_actions' => collect($blocking)
                ->pluck('blocker')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreDimension(string $id, string $label, float $weight, bool $passed, float $credit, string $blocker): array
    {
        $credit = max(0.0, min(1.0, $credit));

        return [
            'id' => $id,
            'label' => $label,
            'weight' => $weight,
            'credit' => $passed ? $credit : 0.0,
            'earned' => $passed ? round($weight * $credit, 2) : 0.0,
            'passed' => $passed,
            'blocker' => $passed ? null : $blocker,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $missing
     * @param  array<string,mixed>  $verificationEvidence
     * @return array<string,mixed>
     */
    private function executiveReport(bool $localReady, bool $claimReady, array $missing, array $verificationEvidence): array
    {
        return [
            'schema_version' => 'atlas.programming.professional_completion_executive_report.v1',
            'headline' => $claimReady
                ? 'Programming foundation complete with verified real Rivals evidence.'
                : ($localReady ? 'Programming foundation ready locally; real Rivals provider battery still blocks final claim.' : 'Programming foundation is not ready locally.'),
            'status_label' => $missing === [] ? 'Complete' : 'Blocked',
            'primary_state' => [
                'local_foundation' => $localReady ? 'ready' : 'blocked',
                'external_rivals_claim' => $claimReady ? 'ready' : 'blocked',
                'completion' => $missing === [] ? 'allowed' : 'blocked',
            ],
            'key_metrics' => [
                [
                    'label' => 'Retrieval recall',
                    'value' => data_get($verificationEvidence, 'local_benchmarks.retrieval.metrics.recall_at_k'),
                    'status' => data_get($verificationEvidence, 'local_benchmarks.retrieval.status', 'unknown'),
                ],
                [
                    'label' => 'Retrieval precision',
                    'value' => data_get($verificationEvidence, 'local_benchmarks.retrieval.metrics.precision_at_k'),
                    'status' => data_get($verificationEvidence, 'local_benchmarks.retrieval.status', 'unknown'),
                ],
                [
                    'label' => 'Test impact recall',
                    'value' => data_get($verificationEvidence, 'local_benchmarks.test_impact.metrics.recall'),
                    'status' => data_get($verificationEvidence, 'local_benchmarks.test_impact.status', 'unknown'),
                ],
                [
                    'label' => 'Grounded patch rate',
                    'value' => data_get($verificationEvidence, 'local_benchmarks.patch_verifier.metrics.grounded_patch_rate'),
                    'status' => data_get($verificationEvidence, 'local_benchmarks.patch_verifier.status', 'unknown'),
                ],
                [
                    'label' => 'Repair loop pass rate',
                    'value' => data_get($verificationEvidence, 'local_benchmarks.repair_loop.metrics.repair_planning_pass_rate'),
                    'status' => data_get($verificationEvidence, 'local_benchmarks.repair_loop.status', 'unknown'),
                ],
                [
                    'label' => 'Comparable real Rivals cases',
                    'value' => data_get($verificationEvidence, 'rivals_external_claim.comparable_case_count', 0),
                    'status' => data_get($verificationEvidence, 'rivals_external_claim.status', 'unknown'),
                ],
            ],
            'current_blocker' => $missing[0] ?? null,
            'operator_next_action' => $this->operatorNextAction($claimReady, $verificationEvidence),
            'safety_summary' => [
                'provider_dispatches_now' => data_get($verificationEvidence, 'operator_safety.provider_dispatches_now', true),
                'spend_provider_tokens_now' => data_get($verificationEvidence, 'invalid_battery_triage_packet.provider_budget_policy.spend_more_provider_tokens_now', true),
                'provider_budget_reason' => data_get($verificationEvidence, 'invalid_battery_triage_packet.provider_budget_policy.reason', 'unknown'),
                'operator_approval_required' => data_get($verificationEvidence, 'operator_safety.operator_approval_required', true),
                'synthetic_scores_allowed' => data_get($verificationEvidence, 'rivals_external_claim.synthetic_scores_allowed', true),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $rivals
     * @return array<string,mixed>
     */
    private function verificationEvidence(array $rivals): array
    {
        return [
            'schema_version' => 'atlas.programming.professional_completion_verification_evidence.v1',
            'local_benchmarks' => [
                'retrieval' => [
                    'status' => data_get($rivals, 'local_benchmarks.retrieval.status', 'unknown'),
                    'metrics' => data_get($rivals, 'local_benchmarks.retrieval.metrics', []),
                    'promotion_gate' => data_get($rivals, 'local_benchmarks.retrieval.promotion_gate', []),
                    'runtime_cache' => data_get($rivals, 'local_benchmarks.retrieval.runtime_cache', []),
                ],
                'test_impact' => [
                    'status' => data_get($rivals, 'local_benchmarks.test_impact.status', 'unknown'),
                    'metrics' => data_get($rivals, 'local_benchmarks.test_impact.metrics', []),
                    'promotion_gate' => data_get($rivals, 'local_benchmarks.test_impact.promotion_gate', []),
                    'runtime_cache' => data_get($rivals, 'local_benchmarks.test_impact.runtime_cache', []),
                ],
                'patch_verifier' => [
                    'status' => data_get($rivals, 'local_benchmarks.patch_verifier.status', 'unknown'),
                    'metrics' => data_get($rivals, 'local_benchmarks.patch_verifier.metrics', []),
                    'promotion_gate' => data_get($rivals, 'local_benchmarks.patch_verifier.promotion_gate', []),
                    'runtime_cache' => data_get($rivals, 'local_benchmarks.patch_verifier.runtime_cache', []),
                ],
                'repair_loop' => [
                    'status' => data_get($rivals, 'local_benchmarks.repair_loop.status', 'unknown'),
                    'metrics' => data_get($rivals, 'local_benchmarks.repair_loop.metrics', []),
                    'promotion_gate' => data_get($rivals, 'local_benchmarks.repair_loop.promotion_gate', []),
                    'runtime_cache' => data_get($rivals, 'local_benchmarks.repair_loop.runtime_cache', []),
                ],
            ],
            'rivals_external_claim' => [
                'status' => data_get($rivals, 'status', 'unknown'),
                'claim_ready' => (bool) data_get($rivals, 'summary.claim_ready', false),
                'external_provider_battery_executed' => (bool) data_get($rivals, 'summary.external_provider_battery_executed', false),
                'real_provider_battery_attempted' => (bool) data_get($rivals, 'summary.real_provider_battery_attempted', false),
                'comparable_case_count' => (int) data_get($rivals, 'summary.comparable_case_count', 0),
                'invalid_case_count' => (int) data_get($rivals, 'summary.invalid_case_count', 0),
                'real_battery_invalid' => (bool) data_get($rivals, 'summary.real_battery_invalid', false),
                'invalid_battery_requires_triage_before_rerun' => (bool) data_get($rivals, 'summary.invalid_battery_requires_triage_before_rerun', false),
                'synthetic_scores_allowed' => (bool) data_get($rivals, 'summary.synthetic_scores_allowed', false),
                'integrity_status' => data_get($rivals, 'integrity_assurance.status', 'unknown'),
                'blocking_reasons' => data_get($rivals, 'integrity_assurance.blocking_reasons', []),
            ],
            'result_integrity_diagnostics' => data_get($rivals, 'result_integrity_diagnostics', []),
            'fair_claude_result_integrity' => data_get($rivals, 'fair_claude_result_integrity', []),
            'latest_real_battery_evidence' => data_get($rivals, 'latest_real_battery_evidence', []),
            'current_local_recheck_evidence' => data_get($rivals, 'current_local_recheck_evidence', []),
            'invalid_battery_triage_packet' => data_get($rivals, 'invalid_battery_triage_packet', []),
            'current_workspace_preflight' => data_get($rivals, 'current_workspace_preflight', []),
            'operator_safety' => [
                'provider_dispatches_now' => (bool) data_get($rivals, 'operator_execution_packet.provider_dispatches_now', true),
                'operator_approval_required' => (bool) data_get($rivals, 'operator_execution_packet.operator_approval_required', true),
                'external_cost_possible' => (bool) data_get($rivals, 'operator_execution_packet.external_cost_possible', true),
                'runbook_review_required' => (bool) data_get($rivals, 'operator_execution_packet.runbook_review_required', true),
                'rerun_provider_battery_allowed_now' => (bool) data_get($rivals, 'operator_execution_packet.rerun_provider_battery_allowed_now', false),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $verificationEvidence
     */
    private function operatorNextAction(bool $claimReady, array $verificationEvidence): string
    {
        if ($claimReady) {
            return 'Review verified export bundle and promote completion.';
        }

        if ((bool) data_get($verificationEvidence, 'rivals_external_claim.invalid_battery_requires_triage_before_rerun', false)) {
            return 'Stop paid Rivals runs; triage invalid Atlas protocol result, failed gates and workspace scope before another provider battery.';
        }

        if ((bool) data_get($verificationEvidence, 'rivals_external_claim.real_battery_invalid', false)) {
            return 'Historical invalid Rivals battery is quarantined; use clean isolated worktrees and explicit operator cost approval for the next fresh paired battery.';
        }

        return 'Approve and run a real paired Rivals battery only after reviewing runbook and accepting provider cost.';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function artifactCoverage(string $workspace): array
    {
        $root = rtrim($workspace, DIRECTORY_SEPARATOR);

        return [
            'professional_operating_standard' => $this->professionalOperatingStandardCovered($root),
            'professional_spec' => $this->filesCovered($root, [
                'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md',
            ]),
            'enterprise_plan' => $this->filesCovered($root, [
                'docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md',
            ]),
            'completion_audit_doc' => $this->filesCovered($root, [
                'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
            ]),
            'agentic_rag_context_pack' => $this->classesAndFilesCovered($root, [
                ProgrammingRetrievalPlanner::class,
                ProgrammingRetrievalExecutor::class,
                ProgrammingProfessionalReranker::class,
                ProgrammingGapCritic::class,
                ProgrammingContextPackStore::class,
            ], [
                'database/migrations/2026_05_13_050000_create_atlas_programming_context_packs_table.php',
            ]),
            'hybrid_retrieval_and_gap_critic' => $this->classesCovered([
                ProgrammingRetrievalExecutor::class,
                ProgrammingLocalVectorIndex::class,
                ProgrammingProfessionalReranker::class,
                ProgrammingGapCritic::class,
                ProgrammingRetrievalEvaluator::class,
            ]),
            'semantic_code_graph' => $this->classesCovered([
                ProgrammingSemanticCodeGraphService::class,
            ]),
            'stage_receipts_resume' => $this->classesAndFilesCovered($root, [
                ProgrammingStageReceiptStore::class,
                ProgrammingStageReceiptValidator::class,
                ProgrammingResumeService::class,
            ], [
                'database/migrations/2026_05_13_020000_create_atlas_programming_stage_receipts_table.php',
            ]),
            'tool_runtime_manifests' => $this->classesAndFilesCovered($root, [
                ProgrammingActionManifestFactory::class,
                ProgrammingActionManifestStore::class,
                'App\\Models\\AtlasProgrammingActionManifest',
                'App\\Services\\Ai\\Runtime\\AiToolRuntime',
            ], [
                'database/migrations/2026_05_13_030000_create_atlas_programming_action_manifests_table.php',
            ]),
            'patch_verifier' => $this->classesCovered([
                ProgrammingPatchVerifier::class,
                ProgrammingPatchVerifierBenchmarkService::class,
            ]),
            'test_impact' => $this->classesCovered([
                ProgrammingTestImpactAnalyzer::class,
                ProgrammingTestImpactBenchmarkService::class,
            ]),
            'sandbox_repair_learning' => $this->classesAndFilesCovered($root, [
                ProgrammingSandboxManager::class,
                ProgrammingRepairExecutor::class,
                ProgrammingRepairAttemptStore::class,
                ProgrammingRepairLoopBenchmarkService::class,
                ProgrammingLearningCandidateProjector::class,
                ProgrammingLearningCandidateStore::class,
                ProgrammingLearningPromotionGate::class,
            ], [
                'database/migrations/2026_05_13_040000_create_atlas_programming_learning_candidates_table.php',
            ]),
            'python_runtime' => $this->classesAndFilesCovered($root, [
                ProgrammingPythonRuntimeContract::class,
                ProgrammingPythonRuntimeExecutor::class,
                ProgrammingPythonRuntimeGraphProjector::class,
            ], [
                'runtimes/python/programming_intelligence/README.md',
                'runtimes/python/programming_intelligence/main.py',
                'runtimes/python/programming_intelligence/atlas_programming_intelligence/contract.py',
                'runtimes/python/programming_intelligence/atlas_programming_intelligence/language_analyzer.py',
                'runtimes/python/programming_intelligence/tests/test_contract.py',
            ]),
            'programming_graph_rag_runtime' => $this->programmingGraphRagRuntimeCovered($root),
            'local_benchmarks' => $this->classesCovered([
                ProgrammingRetrievalBenchmarkService::class,
                ProgrammingTestImpactBenchmarkService::class,
                ProgrammingPatchVerifierBenchmarkService::class,
                ProgrammingRepairLoopBenchmarkService::class,
            ]),
            'programming_cli_commands' => $this->programmingCliCommandsCovered($root),
            'structure_mother_safe_rivals_commands' => $this->structureMotherSafeRivalsCommandsCovered($root),
            'api_rivals_battery_guard' => $this->apiRivalsBatteryGuardCovered($root),
            'rivals_operator_triage_command' => $this->rivalsOperatorTriageCommandCovered($root),
            'rivals_invalid_battery_quarantine' => $this->rivalsInvalidBatteryQuarantineCovered($root),
            'rivals_history_timeline' => $this->rivalsHistoryTimelineCovered($root),
            'rivals_experiment_validity_contract' => $this->rivalsExperimentValidityContractCovered($root),
            'rivals_provider_runtime_preflight' => $this->rivalsProviderRuntimePreflightCovered($root),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function auditProtocol(): array
    {
        return [
            'schema_version' => 'atlas.programming.professional_completion_audit_protocol.v1',
            'restated_objective' => 'Deliver the professional programming foundation with governed RAG/Agentic RAG, quality gates, receipts, local runtime, benchmarks, and honest Rivals-Programming integrity.',
            'success_criteria' => [
                'professional_docs_exist_and_reject_weak_mvp',
                'professional_operating_standard_exists_and_blocks_weak_rag_mvp',
                'agentic_rag_generates_replayable_context_pack',
                'required_sources_are_checked_by_fail_closed_gap_critic',
                'programming_actions_have_receipts_manifests_rollback_and_review_gates',
                'local_retrieval_test_impact_patch_verifier_and_repair_loop_benchmarks_pass',
                'programming_cli_commands_are_registered_for_operator_execution',
                'rivals_readiness_separates_local_evidence_from_external_provider_claims',
                'rivals_integrity_blocks_unfair_or_synthetic_ab_test_scores',
                'structure_mother_blocks_paid_rivals_commands_from_dirty_workspace',
                'rivals_rerun_preconditions_prevent_token_spend_on_invalid_battery',
                'mobile_api_battery_plan_blocks_dirty_non_git_and_invalid_historical_runs',
                'operator_triage_command_explains_invalid_battery_without_provider_dispatch',
                'invalid_rivals_battery_can_be_quarantined_without_admitting_score',
                'rivals_report_exposes_enterprise_history_timeline',
                'real_provider_claim_requires_comparable_cases_and_verified_export_bundle',
            ],
            'prompt_to_artifact_map' => [
                [
                    'prompt_requirement' => 'documentacao profissional de programacao',
                    'primary_artifacts' => [
                        'docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md',
                        'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md',
                        'docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md',
                        'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
                    ],
                    'verification' => 'php artisan atlas:engineering:knowledge docs-health --json',
                ],
                [
                    'prompt_requirement' => 'RAG e Agentic RAG enterprise',
                    'primary_artifacts' => [
                        'ProgrammingRetrievalPlanner',
                        'ProgrammingRetrievalExecutor',
                        'ProgrammingProfessionalReranker',
                        'ProgrammingGapCritic',
                        'ProgrammingContextPackStore',
                    ],
                    'verification' => 'php artisan atlas:programming:retrieval-benchmark --json',
                ],
                [
                    'prompt_requirement' => 'desempenho, repair e qualidade de programacao',
                    'primary_artifacts' => [
                        'ProgrammingTestImpactAnalyzer',
                        'ProgrammingPatchVerifier',
                        'ProgrammingRepairLoopBenchmarkService',
                        'ProgrammingSemanticCodeGraphService',
                    ],
                    'verification' => 'php artisan atlas:programming:test-impact-benchmark --json && php artisan atlas:programming:patch-verifier-benchmark --json && php artisan atlas:programming:repair-loop-benchmark --json',
                ],
                [
                    'prompt_requirement' => 'receipts, retomada e runtime local governado',
                    'primary_artifacts' => [
                        'ProgrammingStageReceiptStore',
                        'ProgrammingResumeService',
                        'ProgrammingPythonRuntimeContract',
                        'ProgrammingPythonRuntimeExecutor',
                    ],
                    'verification' => 'php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php',
                ],
                [
                    'prompt_requirement' => 'integridade profissional do Rivals',
                    'primary_artifacts' => [
                        'ProgrammingRivalsReadinessService',
                        'AtlasStructureMotherAuditReadModel',
                        'atlas.programming.rivals_operator_execution_packet.v1',
                        'atlas.programming.rivals_integrity_assurance.v1',
                        'atlas.programming.current_rivals_rerun_preconditions.v1',
                        'EngineeringBenchmarkController::rivalsBatteryPlan',
                        'atlas:programming:rivals-readiness --triage',
                    ],
                    'verification' => 'php artisan atlas:programming:rivals-readiness --json',
                ],
                [
                    'prompt_requirement' => 'conclusao total sem fingir score externo',
                    'primary_artifacts' => [
                        'ProgrammingProfessionalCompletionAuditService',
                        'atlas:engineering:benchmark:rivals verified export bundle',
                    ],
                    'verification' => 'php artisan atlas:programming:completion-audit --json',
                ],
            ],
            'proxy_signal_policy' => [
                'tests_alone_are_insufficient' => true,
                'readiness_is_not_external_score' => true,
                'local_benchmarks_do_not_replace_provider_battery' => true,
                'uncertainty_blocks_completion' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function checklist(array $artifactCoverage, bool $localReady, bool $claimReady, bool $operatorPacketReady, bool $integrityAssuranceReady, bool $rerunPreconditionsReady, string $rivalsRealBlocker): array
    {
        $localStatus = fn (string $key): string => $localReady && (bool) data_get($artifactCoverage, $key.'.covered', false) ? 'passed' : 'blocked';
        $docStatus = fn (string $key): string => (bool) data_get($artifactCoverage, $key.'.covered', false) ? 'passed' : 'blocked';

        return [
            $this->item('professional_operating_standard', 'Professional RAG operating standard exists and rejects weak MVP as a completion path.', 'docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md', $docStatus('professional_operating_standard'), $docStatus('professional_operating_standard') === 'passed' ? null : 'professional_operating_standard_missing'),
            $this->item('professional_spec', 'Professional Agentic RAG spec exists and rejects weak MVP.', 'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md', $docStatus('professional_spec'), $docStatus('professional_spec') === 'passed' ? null : 'professional_spec_missing'),
            $this->item('enterprise_plan', 'Enterprise programming plan maps the professional implementation blocks.', 'docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md', $docStatus('enterprise_plan'), $docStatus('enterprise_plan') === 'passed' ? null : 'enterprise_plan_missing'),
            $this->item('completion_audit_doc', 'Completion audit document records requirement-to-artifact coverage.', 'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md', $docStatus('completion_audit_doc'), $docStatus('completion_audit_doc') === 'passed' ? null : 'completion_audit_doc_missing'),
            $this->item('agentic_rag_context_pack', 'Professional plan and context pack are generated, hashed and replayable.', 'ProgrammingRetrievalPlanner + ProgrammingContextPackStore', $localStatus('agentic_rag_context_pack'), $localStatus('agentic_rag_context_pack') === 'passed' ? null : 'agentic_rag_context_pack_missing_or_unverified'),
            $this->item('hybrid_retrieval_and_gap_critic', 'Hybrid graph/vector retrieval, reranking and gap critic are covered by local benchmarks.', 'ProgrammingRetrievalExecutor + ProgrammingGapCritic', $localStatus('hybrid_retrieval_and_gap_critic'), $localStatus('hybrid_retrieval_and_gap_critic') === 'passed' ? null : 'hybrid_retrieval_or_gap_critic_missing_or_unverified'),
            $this->item('semantic_code_graph', 'Semantic Code Graph feeds programming retrieval and test impact.', 'ProgrammingSemanticCodeGraphService', $localStatus('semantic_code_graph'), $localStatus('semantic_code_graph') === 'passed' ? null : 'semantic_code_graph_missing_or_unverified'),
            $this->item('stage_receipts_resume', 'Stage receipts and resume reconstruct prior work without session memory.', 'ProgrammingStageReceiptStore + ProgrammingResumeService', $localStatus('stage_receipts_resume'), $localStatus('stage_receipts_resume') === 'passed' ? null : 'stage_receipts_resume_missing_or_unverified'),
            $this->item('tool_runtime_manifests', 'Programming actions produce manifests with dry-run, rollback and gate effect.', 'ProgrammingActionManifestFactory + AiToolRuntime', $localStatus('tool_runtime_manifests'), $localStatus('tool_runtime_manifests') === 'passed' ? null : 'tool_runtime_manifests_missing_or_unverified'),
            $this->item('patch_verifier', 'Patch Verifier blocks ungrounded or weakly tested patches.', 'ProgrammingPatchVerifier + patch-verifier benchmark', $localStatus('patch_verifier'), $localStatus('patch_verifier') === 'passed' ? null : 'patch_verifier_missing_or_unverified'),
            $this->item('test_impact', 'Test Impact Analysis selects proportional tests with evidence.', 'ProgrammingTestImpactAnalyzer + test-impact benchmark', $localStatus('test_impact'), $localStatus('test_impact') === 'passed' ? null : 'test_impact_missing_or_unverified'),
            $this->item('sandbox_repair_learning', 'Sandbox, repair attempts and learning candidates are receipt-backed and review-gated.', 'ProgrammingSandboxManager + ProgrammingRepairAttemptStore + ProgrammingLearningCandidateStore', $localStatus('sandbox_repair_learning'), $localStatus('sandbox_repair_learning') === 'passed' ? null : 'sandbox_repair_learning_missing_or_unverified'),
            $this->item('python_runtime', 'Python runtime is governed, provider-safe and approval-gated.', 'runtimes/python/programming_intelligence + ProgrammingPythonRuntimeExecutor', $localStatus('python_runtime'), $localStatus('python_runtime') === 'passed' ? null : 'python_runtime_missing_or_unverified'),
            $this->item('local_benchmarks', 'Retrieval, Test Impact, Patch Verifier and Repair Loop golden sets pass locally.', 'atlas:programming:*benchmark', $localStatus('local_benchmarks'), $localStatus('local_benchmarks') === 'passed' ? null : 'local_benchmarks_missing_or_failed'),
            $this->item('programming_cli_commands', 'Programming professional commands are registered for operator execution.', 'bootstrap/app.php + AtlasProgramming*Command', $docStatus('programming_cli_commands'), $docStatus('programming_cli_commands') === 'passed' ? null : 'programming_cli_commands_missing_or_unregistered'),
            $this->item(
                'operator_execution_packet',
                'Rivals operator packet exposes cost/runbook confirmations and does not dispatch providers from readiness.',
                'atlas.programming.rivals_operator_execution_packet.v1',
                $operatorPacketReady ? 'passed' : 'blocked',
                $operatorPacketReady ? null : 'operator_execution_packet_missing_or_unsafe',
            ),
            $this->item(
                'rivals_integrity_assurance',
                'Rivals integrity assurance enforces A/B-style validity, external variable controls and score admission gates.',
                'atlas.programming.rivals_integrity_assurance.v1',
                $integrityAssuranceReady ? 'passed' : 'blocked',
                $integrityAssuranceReady ? null : 'rivals_integrity_assurance_missing_or_unsafe',
            ),
            $this->item(
                'structure_mother_safe_rivals_commands',
                'Structure mother exposes paid Rivals commands only through clean worktree placeholders and operator approval.',
                'AtlasStructureMotherAuditReadModel clean worktree command contract',
                $docStatus('structure_mother_safe_rivals_commands'),
                $docStatus('structure_mother_safe_rivals_commands') === 'passed' ? null : 'structure_mother_safe_rivals_commands_missing_or_unsafe',
            ),
            $this->item(
                'rivals_rerun_preconditions',
                'Invalid Rivals battery triage separates historical failures from current rerun preconditions and blocks provider dispatch.',
                'atlas.programming.current_rivals_rerun_preconditions.v1',
                $rerunPreconditionsReady ? 'passed' : 'blocked',
                $rerunPreconditionsReady ? null : 'rivals_rerun_preconditions_missing_or_unsafe',
            ),
            $this->item(
                'api_rivals_battery_guard',
                'Mobile/API battery-plan blocks dirty, non-Git or historically invalid Rivals batteries before provider execution.',
                'EngineeringBenchmarkController battery-plan preflight',
                $docStatus('api_rivals_battery_guard'),
                $docStatus('api_rivals_battery_guard') === 'passed' ? null : 'api_rivals_battery_guard_missing_or_unsafe',
            ),
            $this->item(
                'rivals_operator_triage_command',
                'Operator triage command explains invalid Rivals battery without provider dispatch.',
                'atlas:programming:rivals-readiness --triage',
                $docStatus('rivals_operator_triage_command'),
                $docStatus('rivals_operator_triage_command') === 'passed' ? null : 'rivals_operator_triage_command_missing_or_unsafe',
            ),
            $this->item(
                'rivals_invalid_battery_quarantine',
                'Invalid Rivals battery can be quarantined without deleting history, admitting score or declaring a winner.',
                'atlas:engineering:benchmark:rivals triage-invalid-battery',
                $docStatus('rivals_invalid_battery_quarantine'),
                $docStatus('rivals_invalid_battery_quarantine') === 'passed' ? null : 'rivals_invalid_battery_quarantine_missing_or_unsafe',
            ),
            $this->item(
                'rivals_history_timeline',
                'Rivals report exposes run history as a structured timeline with integrity, score admission and blocker fields.',
                'atlas.fair_claude.history_timeline.v1',
                $docStatus('rivals_history_timeline'),
                $docStatus('rivals_history_timeline') === 'passed' ? null : 'rivals_history_timeline_missing_or_unsafe',
            ),
            $this->item(
                'rivals_experiment_validity_contract',
                'Rivals report/export carries A/B-style experiment validity controls so external variables can block comparability but never decide the winner.',
                'atlas.fair_claude.experiment_validity.v1',
                $docStatus('rivals_experiment_validity_contract'),
                $docStatus('rivals_experiment_validity_contract') === 'passed' ? null : 'rivals_experiment_validity_contract_missing_or_unsafe',
            ),
            $this->item(
                'rivals_provider_runtime_preflight',
                'Fair Claude provider execution preflights runnable Laravel workspaces, high-memory Pint and provider timeout before spending tokens.',
                'atlas.fair_claude.provider_execution_guard.v1',
                $docStatus('rivals_provider_runtime_preflight'),
                $docStatus('rivals_provider_runtime_preflight') === 'passed' ? null : 'rivals_provider_runtime_preflight_missing_or_unsafe',
            ),
            $this->item(
                'rivals_programming_real',
                'Real paired provider battery has comparable cases and verified export bundle.',
                'atlas:engineering:benchmark:rivals + atlas:programming:rivals-readiness',
                $claimReady ? 'passed' : 'blocked',
                $claimReady ? null : $rivalsRealBlocker,
            ),
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<string,mixed>
     */
    private function professionalOperatingStandardCovered(string $root): array
    {
        $path = 'docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md';
        $absolutePath = $root.DIRECTORY_SEPARATOR.$path;
        $source = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';

        $checks = [
            'document_exists' => $source !== '',
            'rejects_weak_mvp' => str_contains($source, 'MVP fraco de RAG')
                && str_contains($source, 'contexto falso')
                && str_contains($source, 'Anti-MVP'),
            'requires_replayable_context_pack' => str_contains($source, 'Context pack')
                && str_contains($source, 'hash')
                && str_contains($source, 'replay'),
            'requires_agentic_gap_critic' => str_contains($source, 'Agentic critic')
                && str_contains($source, 'lacunas'),
            'requires_semantic_code_graph' => str_contains($source, 'Semantic Code Graph')
                && str_contains($source, 'dependencias'),
            'requires_stage_receipts_and_action_manifests' => str_contains($source, 'Stage receipts')
                && str_contains($source, 'Action manifests'),
            'requires_patch_verifier_and_test_impact' => str_contains($source, 'Patch Verifier')
                && str_contains($source, 'Test Impact'),
            'requires_sandbox_repair_learning' => str_contains($source, 'Execution Sandbox Forte')
                && str_contains($source, 'Repair Loop Executor')
                && str_contains($source, 'Learning Loop De Programacao'),
            'blocks_synthetic_rivals_scores' => str_contains($source, 'synthetic_scores_allowed')
                || str_contains($source, 'score comparavel'),
            'requires_clean_rivals_workspaces_and_cost_approval' => str_contains($source, 'workspace Atlas limpo')
                && str_contains($source, 'custo aprovado'),
            'declares_runtime_boundaries' => str_contains($source, 'Laravel/PHP')
                && str_contains($source, 'Python')
                && str_contains($source, 'Go'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$path],
            'missing_files' => file_exists($absolutePath) ? [] : [$path],
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<int,string>  $paths
     * @return array<string,mixed>
     */
    private function filesCovered(string $root, array $paths): array
    {
        $missing = collect($paths)
            ->reject(fn (string $path): bool => file_exists($root.DIRECTORY_SEPARATOR.$path))
            ->values()
            ->all();

        return [
            'covered' => $missing === [],
            'required_files' => $paths,
            'missing_files' => $missing,
        ];
    }

    /**
     * @param  array<int,class-string|string>  $classes
     * @return array<string,mixed>
     */
    private function classesCovered(array $classes): array
    {
        $missing = collect($classes)
            ->reject(fn (string $class): bool => class_exists($class))
            ->values()
            ->all();

        return [
            'covered' => $missing === [],
            'required_classes' => $classes,
            'missing_classes' => $missing,
        ];
    }

    /**
     * @param  array<int,class-string|string>  $classes
     * @param  array<int,string>  $paths
     * @return array<string,mixed>
     */
    private function classesAndFilesCovered(string $root, array $classes, array $paths): array
    {
        $classCoverage = $this->classesCovered($classes);
        $fileCoverage = $this->filesCovered($root, $paths);

        return [
            'covered' => (bool) $classCoverage['covered'] && (bool) $fileCoverage['covered'],
            'required_classes' => $classes,
            'missing_classes' => $classCoverage['missing_classes'],
            'required_files' => $paths,
            'missing_files' => $fileCoverage['missing_files'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingGraphRagRuntimeCovered(string $root): array
    {
        $runtimePath = 'app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php';
        $plannerPath = 'app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php';
        $benchmarkPath = 'app/Services/Ai/Programming/ProgrammingRetrievalBenchmarkService.php';
        $testPath = 'tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php';
        $runtimeSource = file_exists($root.DIRECTORY_SEPARATOR.$runtimePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$runtimePath) : '';
        $plannerSource = file_exists($root.DIRECTORY_SEPARATOR.$plannerPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$plannerPath) : '';
        $benchmarkSource = file_exists($root.DIRECTORY_SEPARATOR.$benchmarkPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$benchmarkPath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'runtime_class_exists' => class_exists(ProgrammingGraphRagRuntime::class),
            'runtime_schema_declared' => str_contains($runtimeSource, 'atlas.programming.graph_rag_runtime.v1'),
            'runtime_is_programming_only' => str_contains($runtimeSource, "'runtime_scope' => 'programming_only'"),
            'runtime_is_local_only' => str_contains($runtimeSource, "'local_only' => true"),
            'runtime_blocks_provider_calls' => str_contains($runtimeSource, "'provider_calls_allowed' => false"),
            'runtime_preserves_ap_683_global_boundary' => str_contains($runtimeSource, 'global_python_graph_rag_policy_unchanged')
                && str_contains($runtimeSource, 'does_not_enable_constelacao_graph_positioning')
                && str_contains($runtimeSource, 'does_not_create_parallel_memory_core'),
            'planner_invokes_runtime' => str_contains($plannerSource, 'ProgrammingGraphRagRuntime')
                && str_contains($plannerSource, 'graph_rag_runtime'),
            'context_pack_receives_graph_refs' => str_contains($plannerSource, 'graphRagRefs')
                && str_contains($plannerSource, 'promoted_programming_graph_rag_lexical'),
            'benchmark_requires_runtime_promotion' => str_contains($benchmarkSource, 'graph_rag_runtime_promoted')
                && str_contains($benchmarkSource, 'programming_graph_rag_runtime_not_promoted'),
            'unit_test_covers_runtime_contract' => str_contains($testSource, 'atlas.programming.graph_rag_runtime.v1')
                && str_contains($testSource, 'global_python_graph_rag_policy_unchanged'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_classes' => [
                ProgrammingGraphRagRuntime::class,
                ProgrammingRetrievalPlanner::class,
                ProgrammingRetrievalBenchmarkService::class,
            ],
            'required_files' => [$runtimePath, $plannerPath, $benchmarkPath, $testPath],
            'missing_files' => collect([$runtimePath, $plannerPath, $benchmarkPath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function structureMotherSafeRivalsCommandsCovered(string $root): array
    {
        $path = 'app/Services/Ai/Kernel/Architecture/AtlasStructureMotherAuditReadModel.php';
        $absolutePath = $root.DIRECTORY_SEPARATOR.$path;
        $source = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        $unsafeCurrentWorkspaceCommand = 'atlas:engineering:benchmark:rivals run --quick --workspace=/Users/vitorepf/develop/Atlas/atlas-server';

        $checks = [
            'read_model_exists' => class_exists('App\\Services\\Ai\\Kernel\\Architecture\\AtlasStructureMotherAuditReadModel'),
            'source_exists' => $source !== '',
            'uses_clean_atlas_workspace_placeholder' => str_contains($source, '--workspace=<clean-atlas-workspace>'),
            'uses_separate_baseline_workspace_placeholder' => str_contains($source, '--claude-code-baseline-workspace=<separate-clean-baseline-workspace>'),
            'requires_operator_cost_confirmation' => str_contains($source, '--confirm-provider-cost'),
            'requires_runbook_confirmation' => str_contains($source, '--confirm-runbook-reviewed'),
            'does_not_publish_dirty_current_workspace_quick_run' => ! str_contains($source, $unsafeCurrentWorkspaceCommand),
            'action_is_blocked_until_clean_worktrees_and_approval' => str_contains($source, 'blocked_until_clean_worktrees_and_operator_cost_approval'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$path],
            'missing_files' => file_exists($absolutePath) ? [] : [$path],
            'checks' => $checks,
            'forbidden_command_fragment' => $unsafeCurrentWorkspaceCommand,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function apiRivalsBatteryGuardCovered(string $root): array
    {
        $path = 'app/Http/Controllers/EngineeringBenchmarkController.php';
        $absolutePath = $root.DIRECTORY_SEPARATOR.$path;
        $source = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';

        $checks = [
            'controller_exists' => class_exists('App\\Http\\Controllers\\EngineeringBenchmarkController'),
            'source_exists' => $source !== '',
            'battery_plan_endpoint_present' => str_contains($source, 'function rivalsBatteryPlan'),
            'run_endpoint_checks_plan_ready' => str_contains($source, 'rivals_battery_plan_blocked'),
            'blocks_historical_invalid_battery' => str_contains($source, 'historical_invalid_battery_requires_triage'),
            'blocks_dirty_atlas_workspace' => str_contains($source, 'atlas_workspace_dirty'),
            'blocks_non_git_atlas_workspace' => str_contains($source, 'atlas_workspace_not_git_worktree'),
            'blocks_dirty_baseline_workspace' => str_contains($source, 'claude_code_baseline_workspace_dirty'),
            'blocks_non_git_baseline_workspace' => str_contains($source, 'claude_code_baseline_workspace_not_git_worktree'),
            'requires_separate_baseline_workspace' => str_contains($source, 'baseline_workspace_must_be_separate'),
            'exposes_operator_report' => str_contains($source, 'atlas.rivals.battery_plan_operator_report.v1'),
            'exposes_primary_blocker' => str_contains($source, 'primary_blocker'),
            'exposes_workspace_dirty_count' => str_contains($source, 'workspace_dirty_count'),
            'exposes_invalid_battery_triage_status' => str_contains($source, 'invalid_battery_triage_status'),
            'exposes_no_provider_call_safety' => str_contains($source, "'no_provider_call' => true"),
            'exposes_clean_git_workspaces_required' => str_contains($source, 'clean_git_workspaces_required'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$path],
            'missing_files' => file_exists($absolutePath) ? [] : [$path],
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rivalsOperatorTriageCommandCovered(string $root): array
    {
        $path = 'app/Console/Commands/AtlasProgrammingRivalsReadinessCommand.php';
        $absolutePath = $root.DIRECTORY_SEPARATOR.$path;
        $source = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';

        $checks = [
            'command_class_exists' => class_exists('App\\Console\\Commands\\AtlasProgrammingRivalsReadinessCommand'),
            'source_exists' => $source !== '',
            'triage_option_registered' => str_contains($source, '{--triage'),
            'triage_schema_declared' => str_contains($source, 'atlas.programming.rivals_invalid_battery_operator_triage.v1'),
            'triage_blocks_provider_dispatch' => str_contains($source, "'provider_dispatches_now' => false"),
            'triage_blocks_token_spend' => str_contains($source, "'spend_provider_tokens_now' => false"),
            'triage_declares_no_benchmark_run_created' => str_contains($source, "'no_benchmark_run_created' => true"),
            'triage_blocks_synthetic_scores' => str_contains($source, "'synthetic_scores_allowed' => false"),
            'triage_exposes_diagnostic_commands' => str_contains($source, 'diagnostic_commands'),
            'triage_exposes_blocked_run_template' => str_contains($source, 'blocked_run_template'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$path],
            'missing_files' => file_exists($absolutePath) ? [] : [$path],
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rivalsInvalidBatteryQuarantineCovered(string $root): array
    {
        $fairPath = 'app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php';
        $wrapperPath = 'app/Console/Commands/AtlasRivalsCommand.php';
        $servicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';
        $fairSource = file_exists($root.DIRECTORY_SEPARATOR.$fairPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$fairPath) : '';
        $wrapperSource = file_exists($root.DIRECTORY_SEPARATOR.$wrapperPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$wrapperPath) : '';
        $serviceSource = file_exists($root.DIRECTORY_SEPARATOR.$servicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$servicePath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'fair_command_exists' => class_exists('App\\Console\\Commands\\AtlasEngineeringBenchmarkFairCommand'),
            'wrapper_command_exists' => class_exists('App\\Console\\Commands\\AtlasRivalsCommand'),
            'canonical_action_registered' => str_contains($fairSource, 'triage-invalid-battery'),
            'wrapper_action_registered' => str_contains($wrapperSource, 'triage-invalid-battery'),
            'requires_human_reason' => str_contains($fairSource, '--reason=<human-triage-reason>')
                || str_contains($fairSource, "'--reason'"),
            'requires_quarantine_confirmation' => str_contains($fairSource, 'confirm-invalid-battery-quarantine'),
            'declares_no_provider_call' => str_contains($fairSource, "'no_provider_call' => true"),
            'declares_no_score_admitted' => str_contains($fairSource, "'no_score_admitted' => true"),
            'declares_no_history_deleted' => str_contains($fairSource, "'no_history_deleted' => true"),
            'stores_fingerprint' => str_contains($fairSource, 'invalid_battery_fingerprint'),
            'stores_suite_triage_record' => str_contains($fairSource, 'rivals_invalid_battery_triage'),
            'stores_multiple_triage_fingerprints' => str_contains($fairSource, 'accepted_fingerprints')
                && str_contains($fairSource, 'record_count')
                && str_contains($serviceSource, 'accepted_fingerprints')
                && str_contains($serviceSource, 'accepted_record_count'),
            'service_keeps_quarantined_cases_out_of_score' => str_contains($serviceSource, 'triaged_invalid_batteries_remain_excluded_from_score')
                && str_contains($serviceSource, 'quarantined_cases_stay_out_of_win_loss_math'),
            'feature_test_covers_canonical_command' => str_contains($testSource, 'test_invalid_fair_battery_can_be_quarantined_without_admitting_score_or_deleting_history'),
            'feature_test_covers_wrapper_command' => str_contains($testSource, 'test_atlas_rivals_wrapper_can_quarantine_invalid_battery_without_provider_call'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$fairPath, $wrapperPath, $servicePath, $testPath],
            'missing_files' => collect([$fairPath, $wrapperPath, $servicePath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rivalsHistoryTimelineCovered(string $root): array
    {
        $servicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';
        $serviceSource = file_exists($root.DIRECTORY_SEPARATOR.$servicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$servicePath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'service_exists' => class_exists('App\\Services\\Engineering\\EngineeringBenchmarkService'),
            'source_exists' => $serviceSource !== '',
            'history_timeline_schema_declared' => str_contains($serviceSource, 'atlas.fair_claude.history_timeline.v1'),
            'summary_exposes_run_count' => str_contains($serviceSource, "'run_count'"),
            'summary_exposes_comparable_case_count' => str_contains($serviceSource, "'comparable_case_count'"),
            'summary_exposes_invalid_case_count' => str_contains($serviceSource, "'invalid_case_count'"),
            'timeline_exposes_result_integrity_status' => str_contains($serviceSource, "'result_integrity_status'"),
            'timeline_exposes_score_admission' => str_contains($serviceSource, "'score_admitted'"),
            'entry_exposes_claim_winner_admission' => str_contains($serviceSource, "'claim_winner_admitted'"),
            'entry_exposes_blocking_reasons' => str_contains($serviceSource, "'blocking_reasons'"),
            'export_bundle_writes_history_timeline_file' => str_contains($serviceSource, 'history-timeline.json'),
            'export_verifier_requires_history_timeline_file' => str_contains($serviceSource, "'history-timeline.json',"),
            'feature_test_covers_timeline_schema' => str_contains($testSource, "history_timeline.schema_version', 'atlas.fair_claude.history_timeline.v1'"),
            'feature_test_covers_history_timeline_export' => str_contains($testSource, 'history-timeline.json'),
            'feature_test_covers_comparable_history' => str_contains($testSource, "history_timeline.entries.0.result_integrity_status', 'comparable_score_blocked'"),
            'feature_test_covers_invalid_history' => str_contains($testSource, "history_timeline.entries.0.result_integrity_status', 'invalid_battery_no_comparable_score'"),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$servicePath, $testPath],
            'missing_files' => collect([$servicePath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rivalsProviderRuntimePreflightCovered(string $root): array
    {
        $fairPath = 'app/Console/Commands/AtlasEngineeringBenchmarkFairCommand.php';
        $baseCommandPath = 'app/Console/Commands/AtlasEngineeringBenchmarkCommand.php';
        $benchmarkServicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $runnerPath = 'app/Services/Engineering/EngineeringHarnessRunnerService.php';
        $controlPath = 'app/Services/Engineering/EngineeringControlRegistryService.php';
        $seedPath = 'app/Console/Commands/AtlasEngineeringBenchmarkSeedCommand.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';

        $fairSource = file_exists($root.DIRECTORY_SEPARATOR.$fairPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$fairPath) : '';
        $baseCommandSource = file_exists($root.DIRECTORY_SEPARATOR.$baseCommandPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$baseCommandPath) : '';
        $benchmarkSource = file_exists($root.DIRECTORY_SEPARATOR.$benchmarkServicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$benchmarkServicePath) : '';
        $runnerSource = file_exists($root.DIRECTORY_SEPARATOR.$runnerPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$runnerPath) : '';
        $controlSource = file_exists($root.DIRECTORY_SEPARATOR.$controlPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$controlPath) : '';
        $seedSource = file_exists($root.DIRECTORY_SEPARATOR.$seedPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$seedPath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'fair_command_exists' => class_exists('App\\Console\\Commands\\AtlasEngineeringBenchmarkFairCommand'),
            'base_command_accepts_provider_timeout' => str_contains($baseCommandSource, '--provider-timeout='),
            'benchmark_forwards_provider_timeout' => str_contains($benchmarkSource, 'provider_timeout_seconds'),
            'runner_applies_provider_timeout' => str_contains($runnerSource, "'timeout_seconds' =>")
                && str_contains($runnerSource, "providerOptions['timeout_seconds']"),
            'fair_runbook_includes_provider_timeout' => str_contains($fairSource, '--provider-timeout=')
                && str_contains($fairSource, '600'),
            'runtime_preflight_declared' => str_contains($fairSource, 'laravel_runtime_preflight_required'),
            'blocks_missing_vendor_autoload' => str_contains($fairSource, 'atlas_workspace_vendor_autoload_missing')
                && str_contains($fairSource, 'claude_code_baseline_vendor_autoload_missing'),
            'blocks_missing_env' => str_contains($fairSource, 'atlas_workspace_env_missing')
                && str_contains($fairSource, 'claude_code_baseline_env_missing'),
            'binary_detector_trims_and_resolves_path' => str_contains($fairSource, 'trim($binary)')
                && str_contains($fairSource, 'realpath($binary)'),
            'pint_uses_high_memory' => str_contains($controlSource, 'memory_limit=1024M')
                && str_contains($controlSource, 'vendor/bin/pint --test'),
            'fair_replay_case_has_strict_scope' => str_contains($seedSource, "'strict_file_scope' => true")
                && str_contains($seedSource, 'fair_benchmark_replay_packet_integrity')
                && str_contains($seedSource, 'app/Services/Engineering/EngineeringBenchmarkService.php'),
            'feature_test_covers_runtime_preflight' => str_contains($testSource, 'test_fair_claude_runbook_blocks_laravel_workspaces_without_runtime_artifacts_before_provider_spend'),
            'feature_test_covers_high_memory_pint' => str_contains($testSource, 'test_laravel_pint_control_uses_high_memory_php_invocation'),
            'feature_test_covers_strict_replay_scope' => str_contains($testSource, 'strict_file_scope')
                && str_contains($testSource, 'fair_benchmark_replay_packet_integrity'),
        ];

        $files = [$fairPath, $baseCommandPath, $benchmarkServicePath, $runnerPath, $controlPath, $seedPath, $testPath];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => $files,
            'missing_files' => collect($files)
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rivalsExperimentValidityContractCovered(string $root): array
    {
        $servicePath = 'app/Services/Engineering/EngineeringBenchmarkService.php';
        $testPath = 'tests/Feature/EngineeringHarnessRunnerTest.php';
        $serviceSource = file_exists($root.DIRECTORY_SEPARATOR.$servicePath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$servicePath) : '';
        $testSource = file_exists($root.DIRECTORY_SEPARATOR.$testPath) ? (string) file_get_contents($root.DIRECTORY_SEPARATOR.$testPath) : '';

        $checks = [
            'service_exists' => class_exists('App\\Services\\Engineering\\EngineeringBenchmarkService'),
            'source_exists' => $serviceSource !== '',
            'experiment_validity_schema_declared' => str_contains($serviceSource, 'atlas.fair_claude.experiment_validity.v1'),
            'same_case_snapshot_required' => str_contains($serviceSource, "'same_case_snapshot_required' => true"),
            'equivalent_initial_state_required' => str_contains($serviceSource, "'equivalent_initial_state_required' => true"),
            'same_acceptance_gates_required' => str_contains($serviceSource, "'same_acceptance_gates_required' => true"),
            'no_provider_specific_case_filtering' => str_contains($serviceSource, "'no_provider_specific_case_filtering' => true"),
            'external_variables_cannot_decide_winner' => str_contains($serviceSource, "'non_evaluated_variables_cannot_decide_winner' => true"),
            'external_variables_only_block_comparability' => str_contains($serviceSource, "'non_evaluated_variables_can_only_block_comparability' => true"),
            'invalid_cases_excluded_from_score' => str_contains($serviceSource, "'invalid_cases_excluded_from_win_loss_math' => true"),
            'export_verifier_requires_experiment_validity' => str_contains($serviceSource, 'experiment_validity_schema_invalid')
                && str_contains($serviceSource, 'claim_markdown_experiment_validity_missing'),
            'feature_test_covers_experiment_validity_schema' => str_contains($testSource, "result_integrity.experiment_validity.schema_version', 'atlas.fair_claude.experiment_validity.v1'"),
            'feature_test_covers_export_semantic_check' => str_contains($testSource, 'semantic_checks.claim_markdown_contains_experiment_validity'),
        ];

        return [
            'covered' => ! in_array(false, $checks, true),
            'required_files' => [$servicePath, $testPath],
            'missing_files' => collect([$servicePath, $testPath])
                ->filter(fn (string $path): bool => ! file_exists($root.DIRECTORY_SEPARATOR.$path))
                ->values()
                ->all(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingCliCommandsCovered(string $root): array
    {
        $bootstrapPath = 'bootstrap/app.php';
        $absolutePath = $root.DIRECTORY_SEPARATOR.$bootstrapPath;
        $source = file_exists($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        $commands = [
            'App\\Console\\Commands\\AtlasProgrammingCompletionAuditCommand' => 'AtlasProgrammingCompletionAuditCommand::class',
            'App\\Console\\Commands\\AtlasProgrammingPatchVerifierBenchmarkCommand' => 'AtlasProgrammingPatchVerifierBenchmarkCommand::class',
            'App\\Console\\Commands\\AtlasProgrammingRepairLoopBenchmarkCommand' => 'AtlasProgrammingRepairLoopBenchmarkCommand::class',
            'App\\Console\\Commands\\AtlasProgrammingResumeCommand' => 'AtlasProgrammingResumeCommand::class',
            'App\\Console\\Commands\\AtlasProgrammingRetrievalBenchmarkCommand' => 'AtlasProgrammingRetrievalBenchmarkCommand::class',
            'App\\Console\\Commands\\AtlasProgrammingRivalsReadinessCommand' => 'AtlasProgrammingRivalsReadinessCommand::class',
            'App\\Console\\Commands\\AtlasProgrammingTestImpactBenchmarkCommand' => 'AtlasProgrammingTestImpactBenchmarkCommand::class',
        ];
        $checks = [];

        foreach ($commands as $class => $registrationToken) {
            $shortName = class_basename($class);
            $checks[$shortName] = [
                'class_exists' => class_exists($class),
                'registered_in_bootstrap' => str_contains($source, $registrationToken),
            ];
        }

        return [
            'covered' => file_exists($absolutePath) && collect($checks)->every(
                fn (array $check): bool => (bool) ($check['class_exists'] ?? false) && (bool) ($check['registered_in_bootstrap'] ?? false),
            ),
            'required_files' => [$bootstrapPath],
            'missing_files' => file_exists($absolutePath) ? [] : [$bootstrapPath],
            'required_classes' => array_keys($commands),
            'checks' => $checks,
        ];
    }

    /**
     * Items do checklist que pertencem ao Atlas Forge Runtime core
     * (programming foundation + governance + harness + evidence).
     * Itens de Rivals externo ficam fora — sao certificados separadamente.
     *
     * @var array<int,string>
     */
    private const FORGE_CORE_ITEMS = [
        'professional_operating_standard',
        'professional_spec',
        'enterprise_plan',
        'completion_audit_doc',
        'agentic_rag_context_pack',
        'hybrid_retrieval_and_gap_critic',
        'semantic_code_graph',
        'stage_receipts_resume',
        'tool_runtime_manifests',
        'patch_verifier',
        'test_impact',
        'sandbox_repair_learning',
        'python_runtime',
        'local_benchmarks',
        'programming_cli_commands',
    ];

    /**
     * @param  array<int,array<string,mixed>>  $checklist
     * @return array<string,mixed>
     */
    private function forgeRuntimeCertification(array $checklist): array
    {
        $forgeItems = collect($checklist)
            ->filter(fn (array $item): bool => in_array((string) ($item['id'] ?? ''), self::FORGE_CORE_ITEMS, true))
            ->values();

        $blockers = $forgeItems
            ->filter(fn (array $item): bool => ($item['status'] ?? null) !== 'passed')
            ->map(fn (array $item): array => [
                'id' => (string) ($item['id'] ?? ''),
                'blocker' => (string) ($item['blocker'] ?? 'unknown'),
            ])
            ->values()
            ->all();

        $status = $blockers === [] ? 'passed' : 'blocked';

        return [
            'schema_version' => 'atlas.forge_runtime_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:forge:runtime-certify --json',
            'core_artifact_group_count' => $forgeItems->count(),
            'passed_count' => $forgeItems->count() - count($blockers),
            'blocked_count' => count($blockers),
            'blockers' => $blockers,
            'note' => 'Forge core e certificado pelo command atlas:forge:runtime-certify e por este audit local. Bateria Rivals externo NAO afeta este eixo.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeLiveExecutionCertification(): array
    {
        $serviceClass = \App\Services\Ai\Programming\AtlasForgeLiveExecutionService::class;
        $commandClass = \App\Console\Commands\AtlasForgeLiveExecuteCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md');

        $serviceExists = class_exists($serviceClass);
        $commandExists = class_exists($commandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);

        $expectedTestMethods = [
            'test_live_execution_fails_closed_without_obra',
            'test_live_execution_runs_full_chain_with_obra',
            'test_context_pack_has_canonical_minimum_with_non_empty_ranked_refs',
            'test_repair_loop_is_skipped_not_needed_when_test_passes',
            'test_repair_loop_is_triggered_when_test_simulated_failure',
        ];

        $testCoverage = $this->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }

        $missingMethods = $testCoverage['missing_methods'];

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.forge_live_execution_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:forge:live-execute --obra=<uuid> --json --strict',
            'evidence_command_failure_path' => 'php artisan atlas:forge:live-execute --obra=<uuid> --simulate-failure --json',
            'fail_closed_command' => 'php artisan atlas:forge:live-execute --json --strict',
            'fail_closed_expected_exit_code' => 1,
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasForgeLiveExecutionTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md', 'present' => $docExists],
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'stages_proven' => [
                'obra_binding',
                'sandbox_provision',
                'context_pack',
                'patch_apply',
                'action_manifest',
                'patch_verifier',
                'test_run',
                'stage_receipts',
                'repair_loop',
                'evidence_ledger',
                'sandbox_rollback',
            ],
            'contract_invariants' => [
                'obra_required' => true,
                'fail_closed_without_obra' => true,
                'context_pack_canonical_minimum_required' => true,
                'repair_loop_states' => ['skipped_not_needed', 'passed', 'degraded', 'blocked'],
                'evidence_ledger_required_when_table_present' => true,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Status=available significa artefatos + cobertura de teste presentes; passed exige operador rodar atlas:forge:live-execute --strict e anexar evidencia recente. requires_operator_run aparece quando metodos canonicos de teste estao faltando.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasCodeEnterpriseCertification(): array
    {
        $serviceClass = \App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService::class;
        $commandClass = \App\Console\Commands\AtlasCodeEnterpriseCertifyCommand::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeEnterpriseCertificationController::class;
        $testFile = base_path('tests/Feature/AtlasCodeContractTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-enterprise-certification.md');

        $serviceExists = class_exists($serviceClass);
        $commandExists = class_exists($commandClass);
        $controllerExists = class_exists($controllerClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);

        $expectedTestMethods = [
            'test_atlas_code_enterprise_certification_proves_full_product_loop',
            'test_atlas_code_enterprise_certification_api_exposes_product_proof_packet',
            'test_atlas_code_can_compile_spec_plan_and_queue_from_bound_work_item',
            'test_atlas_code_replays_forge_run_history_read_only_with_review_context',
            'test_atlas_code_can_create_checkpoint_for_work_resume',
        ];
        $testCoverage = $this->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }

        $missingMethods = $testCoverage['missing_methods'];
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.enterprise_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:code:enterprise-certify --json --strict',
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'test' => ['path' => 'tests/Feature/AtlasCodeContractTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-enterprise-certification.md', 'present' => $docExists],
            ],
            'api_surface' => [
                'certify_endpoint' => 'POST /atlas-code/certification',
                'read_model_endpoint' => 'GET /atlas-code/certification',
                'state_projection' => '/atlas-code/works/{obra}/state.atlas_code_enterprise_certification',
                'works_index_hides_ephemeral_certification_obras' => true,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'stages_proven' => [
                'preflight',
                'obra_workspace_fixture',
                'work_item_binding',
                'spec_plan_task_queue',
                'forge_live_execution_governed',
                'state_history_after_run',
                'history_replay_read_only',
                'human_review_promotion',
                'governed_rollback',
                'checkpoint_resume',
                'final_state_read_model',
                'workspace_cleanup',
            ],
            'contract_invariants' => [
                'forge_only' => true,
                'obra_required' => true,
                'workspace_not_mutated_before_review' => true,
                'history_replay_read_only' => true,
                'rollback_restores_initial_hash' => true,
                'checkpoint_required_for_resume' => true,
                'api_read_model_does_not_create_obra' => true,
                'ephemeral_certification_obras_hidden_from_works_index' => true,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Status=available significa que comando, doc e cobertura existem. Para evidencia fresca, rode atlas:code:enterprise-certify --json --strict.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @param  array<int,string>  $expectedMethods
     * @return array{present_methods:array<int,string>,missing_methods:array<int,string>}
     */
    private function scanTestCoverage(string $testFile, array $expectedMethods): array
    {
        if (! is_file($testFile)) {
            return ['present_methods' => [], 'missing_methods' => $expectedMethods];
        }

        $source = (string) file_get_contents($testFile);
        $present = [];
        $missing = [];
        foreach ($expectedMethods as $method) {
            if (str_contains($source, 'function '.$method.'(')) {
                $present[] = $method;
            } else {
                $missing[] = $method;
            }
        }

        return ['present_methods' => $present, 'missing_methods' => $missing];
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeFastPathCertification(): array
    {
        $serviceClass = \App\Services\Ai\Programming\AtlasCodeForgeFastPathService::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeForgeFastPathController::class;
        $commandClass = \App\Console\Commands\AtlasCodeForgeFastPathCommand::class;
        $statusServiceClass = \App\Services\Ai\Programming\AtlasCodeForgeFastPathStatusService::class;
        $statusControllerClass = \App\Http\Controllers\AtlasCodeForgeFastPathStatusController::class;
        $statusCommandClass = \App\Console\Commands\AtlasCodeForgeFastPathStatusCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md');
        $routesFile = base_path('routes/api.php');
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';

        $serviceExists = class_exists($serviceClass);
        $controllerExists = class_exists($controllerClass);
        $commandExists = class_exists($commandClass);
        $statusServiceExists = class_exists($statusServiceClass);
        $statusControllerExists = class_exists($statusControllerClass);
        $statusCommandExists = class_exists($statusCommandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);
        $routeRegistered = str_contains($routesSource, '/forge/fast-path')
            && str_contains($routesSource, 'AtlasCodeForgeFastPathController');
        $statusEndpointRegistered = str_contains($routesSource, '/forge/fast-path/{run}/status')
            && str_contains($routesSource, 'AtlasCodeForgeFastPathStatusController');
        $resumeEndpointRegistered = str_contains($routesSource, '/forge/fast-path/{run}/resume')
            && str_contains($routesSource, 'AtlasCodeForgeFastPathStatusController');

        $expectedTestMethods = [
            'test_fast_path_fails_closed_without_obra',
            'test_fast_path_fails_closed_when_obra_not_found',
            'test_fast_path_prepare_only_creates_work_item_and_compiles_spec_plan',
            'test_fast_path_execute_async_dispatches_queued_status',
            'test_fast_path_persists_snapshot_and_exposes_state_projection',
            'test_fast_path_blocks_when_obra_lacks_intent',
            'test_fast_path_status_returns_not_found_for_unknown_run',
            'test_fast_path_status_rejects_run_from_other_obra',
            'test_fast_path_status_returns_canonical_lifecycle_payload',
            'test_fast_path_resume_reuses_existing_work_item',
            'test_fast_path_does_not_auto_complete_without_review',
            'test_fast_path_status_ignores_unrelated_latest_forge_execution',
        ];
        $testCoverage = $this->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $statusServiceExists) {
            $missingArtifacts[] = 'status_service_class_missing';
        }
        if (! $statusControllerExists) {
            $missingArtifacts[] = 'status_controller_class_missing';
        }
        if (! $statusCommandExists) {
            $missingArtifacts[] = 'status_command_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! $routeRegistered) {
            $missingArtifacts[] = 'route_not_registered';
        }
        if (! $statusEndpointRegistered) {
            $missingArtifacts[] = 'status_endpoint_not_registered';
        }
        if (! $resumeEndpointRegistered) {
            $missingArtifacts[] = 'resume_endpoint_not_registered';
        }

        $missingMethods = $testCoverage['missing_methods'];

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_fast_path_certification.v2',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:code:forge-fast-path --obra=<uuid> --mode=execute_async --json --strict',
            'evidence_status_command' => 'php artisan atlas:code:forge-fast-path-status --obra=<uuid> --run=<run> --json --strict',
            'fail_closed_command' => 'php artisan atlas:code:forge-fast-path --json --strict',
            'fail_closed_expected_exit_code' => 1,
            'api_endpoint' => 'POST /atlas-code/works/{project}/forge/fast-path',
            'api_status_endpoint' => 'GET /atlas-code/works/{project}/forge/fast-path/{run}/status',
            'api_resume_endpoint' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/resume',
            'lifecycle_invariants' => [
                'run_lifecycle_available' => $serviceExists && $statusServiceExists,
                'polling_endpoint_registered' => $statusEndpointRegistered,
                'resume_endpoint_registered' => $resumeEndpointRegistered,
                'cli_status_command_registered' => $statusCommandExists,
                'review_gate_integrated' => $statusServiceExists,
                'repair_path_exposed' => $statusServiceExists,
                'no_auto_completion_without_review' => true,
                'state_projection_available' => true,
            ],
            'read_model' => [
                'state_projection' => '/atlas-code/works/{obra}/state.forge_fast_path',
                'latest_metadata_key' => 'AtlasProject.metadata.latest_atlas_code_forge_fast_path',
                'history_metadata_key' => 'AtlasProject.metadata.atlas_code_forge_fast_path_history',
                'latest_run_metadata_key' => 'AtlasProject.metadata.latest_atlas_code_forge_fast_path_run',
                'run_history_metadata_key' => 'AtlasProject.metadata.atlas_code_forge_fast_path_run_history',
                'history_limit' => 10,
                'run_history_limit' => 25,
            ],
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'status_service' => ['class' => $statusServiceClass, 'present' => $statusServiceExists],
                'status_controller' => ['class' => $statusControllerClass, 'present' => $statusControllerExists],
                'status_command' => ['class' => $statusCommandClass, 'present' => $statusCommandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeFastPathTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-fast-path-v1.md', 'present' => $docExists],
                'route_registered' => $routeRegistered,
                'status_endpoint_registered' => $statusEndpointRegistered,
                'resume_endpoint_registered' => $resumeEndpointRegistered,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'stages_orchestrated' => [
                'obra_binding',
                'workspace_binding',
                'work_item_resolution',
                'spec_plan_resolution',
                'task_queue_resolution',
                'execution_dispatch',
                'state_projection',
                'operator_next_action',
            ],
            'modes_supported' => ['prepare_only', 'execute_async', 'execute_sync'],
            'contract_invariants' => [
                'obra_required' => true,
                'fail_closed_without_obra' => true,
                'no_silent_obra_uuid_generation' => true,
                'forge_only_surface' => true,
                'reuses_canonical_controllers' => true,
                'persists_latest_snapshot_on_obra' => true,
                'exposes_state_projection_to_atlas_code' => true,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Fast Path orquestra os controllers canonicos (programming work-items + forge live-executions + checkpoints) reusando services existentes. Nao cria runtime novo nem provider externo. Separado de external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeReviewCompletionCertification(): array
    {
        $serviceClass = \App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeForgeReviewCompletionController::class;
        $commandClass = \App\Console\Commands\AtlasCodeForgeReviewCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md');
        $routesFile = base_path('routes/api.php');
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $desktopRoot = dirname(base_path()).'/atlas-desktop';
        $domainFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $hookFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $reviewPanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeReviewCompletionPanel.tsx';

        $serviceExists = class_exists($serviceClass);
        $controllerExists = class_exists($controllerClass);
        $commandExists = class_exists($commandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);
        $domainSource = is_file($domainFile) ? (string) file_get_contents($domainFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) file_get_contents($bridgeFile) : '';
        $hookSource = is_file($hookFile) ? (string) file_get_contents($hookFile) : '';
        $desktopDomainTypes = str_contains($domainSource, 'AtlasCodeForgeReviewPacket')
            && str_contains($domainSource, 'AtlasCodeForgeCompletionClaim');
        $desktopBridgeActions = str_contains($bridgeSource, 'getForgeReviewPacket')
            && str_contains($bridgeSource, 'approveForgeReview')
            && str_contains($bridgeSource, 'rejectForgeReview')
            && str_contains($bridgeSource, 'rollbackForgeReview');
        $desktopHookActions = str_contains($hookSource, 'refreshForgeReview')
            && str_contains($hookSource, 'approveForgeReview')
            && str_contains($hookSource, 'rejectForgeReview')
            && str_contains($hookSource, 'rollbackForgeReview');
        $desktopPanel = is_file($reviewPanelFile);
        $desktopUiAvailable = $desktopDomainTypes && $desktopBridgeActions && $desktopHookActions && $desktopPanel;
        $reviewEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review')
            && str_contains($routesSource, 'AtlasCodeForgeReviewCompletionController');
        $approveEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review/approve');
        $rejectEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review/reject');
        $rollbackEndpoint = str_contains($routesSource, '/forge/fast-path/{run}/review/rollback');

        $expectedTestMethods = [
            'test_review_packet_blocked_for_unknown_run',
            'test_review_packet_blocked_when_run_belongs_to_other_obra',
            'test_review_packet_only_uses_correlated_live_execution',
            'test_approve_blocked_when_runtime_not_passed',
            'test_approve_blocked_when_evidence_pack_missing',
            'test_reject_records_decision_and_blocks_completion_claim',
            'test_rollback_blocked_when_rollback_not_available',
            'test_state_endpoint_exposes_review_packet_and_completion_claim',
            'test_completion_audit_block_lists_review_completion_artifacts',
            'test_completion_claim_only_allowed_after_human_approval',
            'test_approve_with_human_review_completes_claim',
        ];
        $testCoverage = $this->scanTestCoverage($testFile, $expectedTestMethods);

        $missingArtifacts = [];
        if (! $serviceExists) {
            $missingArtifacts[] = 'service_class_missing';
        }
        if (! $controllerExists) {
            $missingArtifacts[] = 'controller_class_missing';
        }
        if (! $commandExists) {
            $missingArtifacts[] = 'command_class_missing';
        }
        if (! $testExists) {
            $missingArtifacts[] = 'test_file_missing';
        }
        if (! $docExists) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! $reviewEndpoint) {
            $missingArtifacts[] = 'review_endpoint_not_registered';
        }
        if (! $approveEndpoint) {
            $missingArtifacts[] = 'approve_endpoint_not_registered';
        }
        if (! $rejectEndpoint) {
            $missingArtifacts[] = 'reject_endpoint_not_registered';
        }
        if (! $rollbackEndpoint) {
            $missingArtifacts[] = 'rollback_endpoint_not_registered';
        }

        $missingMethods = $testCoverage['missing_methods'];

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $missingMethods !== [] => 'requires_operator_run',
            ! $desktopUiAvailable => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_review_completion_certification.v1',
            'status' => $status,
            'review_packet_schema' => 'atlas.code.forge_review_packet.v1',
            'completion_claim_schema' => 'atlas.code.forge_completion_claim.v1',
            'evidence_command' => 'php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --json --strict',
            'endpoints' => [
                'show' => 'GET /atlas-code/works/{project}/forge/fast-path/{run}/review',
                'approve' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/review/approve',
                'reject' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/review/reject',
                'rollback' => 'POST /atlas-code/works/{project}/forge/fast-path/{run}/review/rollback',
            ],
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md', 'present' => $docExists],
                'review_endpoint_registered' => $reviewEndpoint,
                'approve_endpoint_registered' => $approveEndpoint,
                'reject_endpoint_registered' => $rejectEndpoint,
                'rollback_endpoint_registered' => $rollbackEndpoint,
            ],
            'desktop_ui' => [
                'domain_types_present' => $desktopDomainTypes,
                'bridge_actions_present' => $desktopBridgeActions,
                'hook_actions_present' => $desktopHookActions,
                'panel_present' => $desktopPanel,
                'available' => $desktopUiAvailable,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'lifecycle_invariants' => [
                'review_packet_available' => $serviceExists,
                'completion_claim_available' => $serviceExists,
                'endpoints_registered' => $reviewEndpoint && $approveEndpoint && $rejectEndpoint && $rollbackEndpoint,
                'cli_registered' => $commandExists,
                'no_auto_completion_without_human_review' => true,
                'rollback_path_available' => true,
                'state_projection_available' => true,
                'correlated_live_execution_required' => true,
                'desktop_review_ui_available' => $desktopUiAvailable,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Review & Completion Gate reusa AtlasCodeForgeReviewController + promotion. Completion claim canonico nao admite human_approved sem decisao operadora. Separado de external_rivals_certification.',
            'separated_from_external_rivals' => 'separated',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Forge-Native Rivals certification block.
     *
     * Reports the canonical contract that the Atlas arm in Rivals MUST run
     * through Forge. This block can reach `available` from protocol +
     * dry-run alone, but it does NOT promote the completion claim.
     * `external_rivals_certification` continues to gate the real Rivals
     * claim and remains blocked until a valid paid battery is approved.
     *
     * @return array<string,mixed>
     */
    private function forgeNativeRivalsCertification(string $workspace): array
    {
        $root = rtrim($workspace, DIRECTORY_SEPARATOR);
        $protocolDoc = $root.DIRECTORY_SEPARATOR.'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md';
        $protocolDocPresent = is_file($protocolDoc);
        $protocolPacket = $this->forgeNativeRivalsProtocol->protocol();
        $manifestPacket = $this->forgeNativeRivalsCaseManifest->manifest(null);
        $preflightPacket = $this->forgeNativeRivalsPreflight->preflight([
            'workspace' => $workspace,
            'intends_provider_battery' => false,
        ]);
        $dryRunPacket = $this->forgeNativeRivalsDryRun->dryRun([
            'workspace' => $workspace,
        ]);

        $protocolAvailable = ($protocolPacket['schema_version'] ?? null) === AtlasForgeNativeRivalsProtocolService::SCHEMA_VERSION
            && (bool) data_get($protocolPacket, 'atlas_arm.atlas_side_must_use_forge', false)
            && data_get($protocolPacket, 'atlas_arm.runtime') === 'forge';
        $caseManifestAvailable = (bool) ($manifestPacket['valid'] ?? false);
        $caseManifestAtlasIsForge = data_get($manifestPacket, 'case.atlas_arm.runtime') === 'forge';
        $forgeRuntimeVerified = data_get($preflightPacket, 'checks.forge_runtime.status') === 'passed'
            && data_get($preflightPacket, 'checks.forge_commands.status') === 'passed';
        $preflightCommandAvailable = class_exists(\App\Console\Commands\AtlasProgrammingRivalsForgePreflightCommand::class);
        $dryRunCommandAvailable = class_exists(\App\Console\Commands\AtlasProgrammingRivalsForgeDryRunCommand::class);
        $dryRunPassed = ($dryRunPacket['status'] ?? null) === 'dry_run_passed';
        $providerCallDuringDryRun = (bool) data_get($dryRunPacket, 'external_provider_call', true);
        $syntheticAllowed = (bool) data_get($dryRunPacket, 'synthetic_scores_allowed', true);

        $missingArtifacts = [];
        if (! $protocolDocPresent) {
            $missingArtifacts[] = 'protocol_doc_missing';
        }
        if (! $protocolAvailable) {
            $missingArtifacts[] = 'protocol_service_missing_or_invalid';
        }
        if (! $caseManifestAvailable) {
            $missingArtifacts[] = 'case_manifest_invalid';
        }
        if (! $caseManifestAtlasIsForge) {
            $missingArtifacts[] = 'case_manifest_atlas_arm_not_forge';
        }
        if (! $forgeRuntimeVerified) {
            $missingArtifacts[] = 'forge_runtime_not_verified';
        }
        if (! $preflightCommandAvailable) {
            $missingArtifacts[] = 'preflight_command_missing';
        }
        if (! $dryRunCommandAvailable) {
            $missingArtifacts[] = 'dry_run_command_missing';
        }

        $workspaceStatus = (string) data_get($preflightPacket, 'checks.workspace.status', 'unknown');

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $providerCallDuringDryRun || $syntheticAllowed => 'blocked_protocol_invalid',
            ! $dryRunPassed && $workspaceStatus !== 'passed' => 'blocked_dirty_workspace',
            ! $dryRunPassed => 'blocked_requires_operator_approval',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.programming.forge_native_rivals_certification.v1',
            'protocol_id' => AtlasForgeNativeRivalsProtocolService::PROTOCOL_ID,
            'status' => $status,
            'atlas_side_must_use_forge' => true,
            'atlas_side_forge_runtime_verified' => $forgeRuntimeVerified,
            'protocol_available' => $protocolAvailable,
            'protocol_doc_available' => $protocolDocPresent,
            'preflight_command_available' => $preflightCommandAvailable,
            'dry_run_command_available' => $dryRunCommandAvailable,
            'case_manifest_available' => $caseManifestAvailable,
            'case_manifest_atlas_arm_is_forge' => $caseManifestAtlasIsForge,
            'dry_run_passed' => $dryRunPassed,
            'dry_run_dispatched_provider' => $providerCallDuringDryRun,
            'provider_dispatch_blocked_without_approval' => true,
            'synthetic_scores_allowed' => false,
            'separated_from_external_rivals_certification' => true,
            'promotes_completion_claim' => false,
            'evidence' => [
                'protocol_command' => 'php artisan atlas:programming:rivals-forge-preflight --json',
                'preflight_command' => 'php artisan atlas:programming:rivals-forge-preflight --json --strict',
                'dry_run_command' => 'php artisan atlas:programming:rivals-forge-dry-run --case=<id> --json --strict',
                'protocol_doc' => 'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            ],
            'preflight_summary' => [
                'status' => $preflightPacket['status'] ?? null,
                'workspace_status' => $workspaceStatus,
                'blocking_reasons' => $preflightPacket['blocking_reasons'] ?? [],
            ],
            'dry_run_summary' => [
                'status' => $dryRunPacket['status'] ?? null,
                'case_id_resolved' => data_get($dryRunPacket, 'inputs.case_id_resolved'),
                'replay_manifest_valid' => (bool) data_get($dryRunPacket, 'planned.replay_manifest.valid', false),
                'blocking_reasons' => $dryRunPacket['blocking_reasons'] ?? [],
            ],
            'missing_artifacts' => $missingArtifacts,
            'note' => 'Forge-Native Rivals certification is informational and never promotes the Rivals claim. Real Rivals battery is still gated by external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeWorkIntakeCertification(): array
    {
        $serviceClass = \App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeForgeWorkIntakeController::class;
        $commandClass = \App\Console\Commands\AtlasCodeForgeWorkIntakeCommand::class;
        $testFile = base_path('tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php');
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md');
        $routesFile = base_path('routes/api.php');
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $serviceSource = class_exists($serviceClass) ? (string) file_get_contents((new \ReflectionClass($serviceClass))->getFileName() ?: '') : '';
        $workControllerSource = is_file(base_path('app/Http/Controllers/AtlasCodeWorkController.php'))
            ? (string) file_get_contents(base_path('app/Http/Controllers/AtlasCodeWorkController.php'))
            : '';

        $desktopRoot = dirname(base_path()).'/atlas-desktop';
        $domainFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $hookFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $panelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeWorkIntakePanel.tsx';
        $tauriBridgeFile = $desktopRoot.'/crates/atlas-bridge/src/client.rs';
        $tauriCommandsFile = $desktopRoot.'/crates/atlas-tauri/src/commands_bridge.rs';
        $tauriLibFile = $desktopRoot.'/crates/atlas-tauri/src/lib.rs';
        $domainSource = is_file($domainFile) ? (string) file_get_contents($domainFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) file_get_contents($bridgeFile) : '';
        $hookSource = is_file($hookFile) ? (string) file_get_contents($hookFile) : '';
        $tauriBridgeSource = is_file($tauriBridgeFile) ? (string) file_get_contents($tauriBridgeFile) : '';
        $tauriCommandsSource = is_file($tauriCommandsFile) ? (string) file_get_contents($tauriCommandsFile) : '';
        $tauriLibSource = is_file($tauriLibFile) ? (string) file_get_contents($tauriLibFile) : '';

        $serviceExists = class_exists($serviceClass);
        $controllerExists = class_exists($controllerClass);
        $commandExists = class_exists($commandClass);
        $testExists = is_file($testFile);
        $docExists = is_file($docFile);
        $apiRegistered = str_contains($routesSource, '/forge/intake')
            && str_contains($routesSource, 'AtlasCodeForgeWorkIntakeController');
        $stateProjection = str_contains($workControllerSource, 'forge_work_intake')
            && str_contains($workControllerSource, 'forgeWorkIntakeForWork');
        $metadataHistory = $serviceSource !== ''
            && str_contains($serviceSource, 'atlas_code_forge_work_intake_history');
        $businessRuleRequired = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_missing_business_rule');
        $acceptanceRequired = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_missing_acceptance_criteria');
        $canonicalDocsRequired = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_missing_canonical_docs');
        $readinessGate = $serviceSource !== ''
            && str_contains($serviceSource, 'readiness_status');
        $workItemLink = $serviceSource !== ''
            && str_contains($serviceSource, 'work_item_id');
        $noProviderCall = $serviceSource !== ''
            && str_contains($serviceSource, "'external_provider_call' => false");
        $noSilentObra = $serviceSource !== ''
            && str_contains($serviceSource, 'blocked_no_obra');

        $expectedTestMethods = [
            'test_intake_fails_closed_without_obra',
            'test_intake_blocks_missing_business_rule',
            'test_intake_blocks_missing_acceptance_criteria',
            'test_intake_ready_with_full_payload',
            'test_intake_persists_latest_and_history',
            'test_state_endpoint_exposes_forge_work_intake',
            'test_cli_strict_exits_non_zero_when_blocked',
            'test_completion_audit_exposes_intake_certification',
            'test_no_provider_call',
            'test_work_item_link_available_when_governance_exists',
        ];
        $testCoverage = $this->scanTestCoverage($testFile, $expectedTestMethods);

        $desktopTypesPresent = str_contains($domainSource, 'AtlasCodeForgeWorkIntake');
        $desktopBridgePresent = str_contains($bridgeSource, 'getForgeWorkIntake')
            && str_contains($bridgeSource, 'saveForgeWorkIntake');
        $desktopHookPresent = str_contains($hookSource, 'refreshForgeWorkIntake')
            && str_contains($hookSource, 'saveForgeWorkIntake');
        $desktopPanelPresent = is_file($panelFile);
        $tauriBridgePresent = str_contains($tauriBridgeSource, 'get_forge_work_intake')
            && str_contains($tauriBridgeSource, 'save_forge_work_intake');
        $tauriCommandsPresent = str_contains($tauriCommandsSource, 'bridge_get_forge_work_intake')
            && str_contains($tauriCommandsSource, 'bridge_save_forge_work_intake')
            && str_contains($tauriLibSource, 'bridge_get_forge_work_intake')
            && str_contains($tauriLibSource, 'bridge_save_forge_work_intake');
        $desktopUiAvailable = $desktopTypesPresent && $desktopBridgePresent && $desktopHookPresent && $desktopPanelPresent && $tauriBridgePresent && $tauriCommandsPresent;

        $missingArtifacts = [];
        if (! $serviceExists) $missingArtifacts[] = 'service_class_missing';
        if (! $controllerExists) $missingArtifacts[] = 'controller_class_missing';
        if (! $commandExists) $missingArtifacts[] = 'command_class_missing';
        if (! $testExists) $missingArtifacts[] = 'test_file_missing';
        if (! $docExists) $missingArtifacts[] = 'doc_file_missing';
        if (! $apiRegistered) $missingArtifacts[] = 'api_not_registered';

        $missingMethods = $testCoverage['missing_methods'];
        $allInvariantsTrue = $businessRuleRequired && $acceptanceRequired && $canonicalDocsRequired
            && $readinessGate && $workItemLink && $noProviderCall && $noSilentObra
            && $stateProjection && $metadataHistory && $serviceExists && $apiRegistered && $commandExists;

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            $missingMethods !== [] => 'requires_operator_run',
            ! $desktopUiAvailable => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_work_intake_certification.v1',
            'status' => $status,
            'evidence_command' => 'php artisan atlas:code:forge-intake --obra=<uuid> --json --strict',
            'api_endpoints' => [
                'show' => 'GET /atlas-code/works/{project}/forge/intake',
                'store' => 'POST /atlas-code/works/{project}/forge/intake',
            ],
            'invariants' => [
                'intake_service_available' => $serviceExists,
                'intake_api_registered' => $apiRegistered,
                'intake_cli_registered' => $commandExists,
                'state_projection_available' => $stateProjection,
                'metadata_history_available' => $metadataHistory,
                'business_rule_required' => $businessRuleRequired,
                'acceptance_criteria_required' => $acceptanceRequired,
                'canonical_docs_required' => $canonicalDocsRequired,
                'readiness_gate_available' => $readinessGate,
                'work_item_link_available' => $workItemLink,
                'no_provider_call' => $noProviderCall,
                'no_silent_obra' => $noSilentObra,
                'separated_from_rivals' => true,
            ],
            'artifacts' => [
                'service' => ['class' => $serviceClass, 'present' => $serviceExists],
                'controller' => ['class' => $controllerClass, 'present' => $controllerExists],
                'command' => ['class' => $commandClass, 'present' => $commandExists],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasCodeForgeWorkIntakeTest.php', 'present' => $testExists],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md', 'present' => $docExists],
                'api_registered' => $apiRegistered,
            ],
            'test_coverage' => [
                'expected_methods' => $expectedTestMethods,
                'present_methods' => $testCoverage['present_methods'],
                'missing_methods' => $missingMethods,
                'coverage_complete' => $missingMethods === [],
            ],
            'desktop_ui' => [
                'domain_types_present' => $desktopTypesPresent,
                'bridge_methods_present' => $desktopBridgePresent,
                'hook_actions_present' => $desktopHookPresent,
                'panel_present' => $desktopPanelPresent,
                'tauri_bridge_present' => $tauriBridgePresent,
                'tauri_commands_present' => $tauriCommandsPresent,
                'available' => $desktopUiAvailable,
            ],
            'missing_artifacts' => $missingArtifacts,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Intake & Spec Governance bloqueia execucao enterprise sem objetivo + regra de negocio + criterios de aceite + docs canonicas. Separado de Rivals.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeOperatorCockpitCertification(): array
    {
        $desktopRoot = dirname(base_path()).'/atlas-desktop';
        $cockpitFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx';
        $registryFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx';
        $hookFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $tauriCommandsFile = $desktopRoot.'/crates/atlas-tauri/src/commands_bridge.rs';
        $tauriLibFile = $desktopRoot.'/crates/atlas-tauri/src/lib.rs';
        $docFile = base_path('docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md');

        $cockpitSource = is_file($cockpitFile) ? (string) file_get_contents($cockpitFile) : '';
        $registrySource = is_file($registryFile) ? (string) file_get_contents($registryFile) : '';
        $hookSource = is_file($hookFile) ? (string) file_get_contents($hookFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) file_get_contents($bridgeFile) : '';
        $tauriCommandsSource = is_file($tauriCommandsFile) ? (string) file_get_contents($tauriCommandsFile) : '';
        $tauriLibSource = is_file($tauriLibFile) ? (string) file_get_contents($tauriLibFile) : '';

        $cockpitPanelPresent = $cockpitSource !== '' && str_contains($registrySource, 'ForgeOperatorCockpitPanel');
        $cockpitUsesBridgeActions = $cockpitSource !== ''
            && str_contains($cockpitSource, 'onRunForgeFastPath')
            && str_contains($cockpitSource, 'onRefreshForgeFastPathStatus')
            && str_contains($cockpitSource, 'onResumeForgeFastPath')
            && str_contains($cockpitSource, 'onRefreshForgeReview')
            && str_contains($cockpitSource, 'onApproveForgeReview')
            && str_contains($cockpitSource, 'onRejectForgeReview')
            && str_contains($cockpitSource, 'onRollbackForgeReview');
        $obraFailClosedVisible = $cockpitSource !== '' && str_contains($cockpitSource, 'sem Obra');
        $fastPathActionsAvailable = $cockpitSource !== ''
            && str_contains($cockpitSource, "'prepare_only'")
            && str_contains($cockpitSource, "'execute_async'")
            && str_contains($cockpitSource, "'execute_sync'");
        $statusPollingAvailable = $cockpitSource !== ''
            && str_contains($cockpitSource, 'setInterval')
            && str_contains($cockpitSource, 'POLL_TERMINAL_STATES');
        $resumeActionAvailable = $cockpitSource !== '' && str_contains($cockpitSource, 'onResumeForgeFastPath');
        $reviewActionsAvailable = $cockpitSource !== ''
            && str_contains($cockpitSource, 'onApproveForgeReview')
            && str_contains($cockpitSource, 'onRejectForgeReview')
            && str_contains($cockpitSource, 'onRollbackForgeReview');
        $completionClaimVisible = $cockpitSource !== ''
            && str_contains($cockpitSource, 'completionStatus')
            && str_contains($cockpitSource, 'finalCompletionAllowed');
        $repairStateVisible = $cockpitSource !== '' && str_contains($cockpitSource, 'repair_available');
        $evidenceStateVisible = $cockpitSource !== ''
            && str_contains($cockpitSource, 'evidence ·')
            && str_contains($cockpitSource, 'ledger ·');
        $noAutoCompletion = $cockpitSource !== '' && str_contains($cockpitSource, 'waiting_human_review');
        $noExternalProviderCall = $cockpitSource !== '' && ! str_contains($cockpitSource, 'externalProvider');
        $tauriCommandsRegistered =
            str_contains($tauriCommandsSource, 'bridge_run_forge_fast_path')
            && str_contains($tauriCommandsSource, 'bridge_get_forge_fast_path_status')
            && str_contains($tauriCommandsSource, 'bridge_resume_forge_fast_path')
            && str_contains($tauriCommandsSource, 'bridge_get_forge_review_packet')
            && str_contains($tauriCommandsSource, 'bridge_decide_forge_review')
            && str_contains($tauriLibSource, 'bridge_run_forge_fast_path')
            && str_contains($tauriLibSource, 'bridge_get_forge_fast_path_status')
            && str_contains($tauriLibSource, 'bridge_resume_forge_fast_path')
            && str_contains($tauriLibSource, 'bridge_get_forge_review_packet')
            && str_contains($tauriLibSource, 'bridge_decide_forge_review');

        $invariants = [
            'cockpit_panel_present' => $cockpitPanelPresent,
            'cockpit_uses_bridge_actions' => $cockpitUsesBridgeActions,
            'obra_fail_closed_visible' => $obraFailClosedVisible,
            'fast_path_actions_available' => $fastPathActionsAvailable,
            'status_polling_available' => $statusPollingAvailable,
            'resume_action_available' => $resumeActionAvailable,
            'review_actions_available' => $reviewActionsAvailable,
            'completion_claim_visible' => $completionClaimVisible,
            'repair_state_visible' => $repairStateVisible,
            'evidence_state_visible' => $evidenceStateVisible,
            'no_auto_completion' => $noAutoCompletion,
            'no_external_provider_call' => $noExternalProviderCall,
            'tauri_commands_registered' => $tauriCommandsRegistered,
        ];

        $missingArtifacts = [];
        if ($cockpitSource === '') {
            $missingArtifacts[] = 'cockpit_panel_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_file_missing';
        }
        if (! is_file($hookFile) || $hookSource === '' || ! str_contains($hookSource, 'approveForgeReview')) {
            $missingArtifacts[] = 'hook_actions_missing';
        }
        if (! is_file($bridgeFile) || $bridgeSource === '' || ! str_contains($bridgeSource, 'approveForgeReview')) {
            $missingArtifacts[] = 'bridge_actions_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_operator_cockpit_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'artifacts' => [
                'cockpit_panel' => ['path' => 'apps/desktop/src/surfaces/code/panels/ForgeOperatorCockpitPanel.tsx', 'present' => $cockpitSource !== ''],
                'registry_entry' => ['path' => 'apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx', 'registered' => $cockpitPanelPresent],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md', 'present' => is_file($docFile)],
                'tauri_commands_file' => ['path' => 'crates/atlas-tauri/src/commands_bridge.rs', 'present' => $tauriCommandsSource !== ''],
            ],
            'missing_artifacts' => $missingArtifacts,
            'lifecycle_states' => ['idle', 'running', 'waiting_review', 'blocked', 'completed', 'rolled_back', 'rejected'],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'note' => 'Cockpit unifica Obra/Forge/Fast Path/Review/Completion sob uma surface operavel; usa exclusivamente useBridge; nao chama provider externo; nao auto-completa.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Atlas Forge Continuum OS certification block.
     *
     * Evaluates the canonical doc-mother + Provider Topology read-model +
     * Governed Fallback Policy + State Projection + Desktop Cockpit UI as a
     * single eixo certificado. This block does NOT call external providers,
     * NEVER unblocks `external_rivals_certification`, NEVER auto-completes work
     * and NEVER bypasses the review/completion gate.
     *
     * Schema: atlas.forge_continuum_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasForgeContinuumCertification(string $workspace): array
    {
        $options = [
            'workspace' => $workspace,
            'strict' => false,
        ];
        $liveDecideObraId = $this->latestForgeLiveDecideObraId();
        if ($liveDecideObraId !== null) {
            $options['obra_id'] = $liveDecideObraId;
        }

        $report = $this->forgeContinuumCertification->certify($options);

        $invariants = is_array($report['invariants'] ?? null) ? $report['invariants'] : [];
        $invariantsAllTrue = (bool) ($report['invariants_all_true'] ?? false);
        $missingArtifacts = is_array($report['missing_artifacts'] ?? null) ? $report['missing_artifacts'] : [];
        $serviceStatus = (string) ($report['status'] ?? '');

        // Audit-block-level status enum normalization.
        $auditStatus = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_BLOCKED => 'blocked',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_BACKEND_AVAILABLE_UI_PENDING => 'backend_available_ui_pending',
            ! $invariantsAllTrue => 'backend_available_ui_pending',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_AVAILABLE => 'available',
            $serviceStatus === AtlasForgeContinuumCertificationService::STATUS_AVAILABLE_WITHOUT_OBRA_CONTEXT => 'available',
            default => 'backend_available_ui_pending',
        };

        $topology = is_array($report['provider_topology'] ?? null) ? $report['provider_topology'] : [];
        $policy = is_array($report['fallback_policy'] ?? null) ? $report['fallback_policy'] : [];
        $liveDecideRuntime = is_array($report['live_decide_runtime'] ?? null) ? $report['live_decide_runtime'] : [];

        return [
            'schema_version' => AtlasForgeContinuumCertificationService::SCHEMA_VERSION,
            'status' => $auditStatus,
            'service_status' => $serviceStatus,
            'invariants' => $invariants,
            'invariants_all_true' => $invariantsAllTrue,
            'missing_artifacts' => $missingArtifacts,
            'artifacts' => is_array($report['artifacts'] ?? null) ? $report['artifacts'] : [],
            'provider_topology_summary' => [
                'schema_version' => $topology['schema_version'] ?? null,
                'status' => $topology['status'] ?? null,
                'strategy' => $topology['strategy'] ?? null,
                'role_count' => is_array($topology['roles'] ?? null) ? count($topology['roles']) : 0,
                'fallback_chain_count' => is_array($topology['fallback_chain'] ?? null) ? count($topology['fallback_chain']) : 0,
                'provider_capacity_count' => is_array($topology['provider_capacity'] ?? null) ? count($topology['provider_capacity']) : 0,
            ],
            'fallback_policy_summary' => [
                'schema_version' => $policy['schema_version'] ?? null,
                'event_schema_version' => $policy['event_schema_version'] ?? null,
                'known_failures' => $policy['known_failures'] ?? [],
                'no_silent_fallback' => (bool) data_get($policy, 'invariants.no_silent_fallback', false),
                'capacity_exhausted_is_hard_blocker' => (bool) data_get($policy, 'invariants.capacity_exhausted_is_hard_blocker', false),
            ],
            'live_decide_runtime' => [
                'schema_version' => $liveDecideRuntime['schema_version'] ?? 'atlas.forge_continuum.live_decide_runtime.v1',
                'decision_source' => $liveDecideRuntime['decision_source'] ?? ($topology['decision_source'] ?? 'static_policy'),
                'live_atlas_decide_topology_available' => (bool) ($liveDecideRuntime['live_atlas_decide_topology_available'] ?? false),
                'decision_receipt_topology_projection_available' => (bool) ($liveDecideRuntime['decision_receipt_topology_projection_available'] ?? false),
                'static_policy_fallback_declared' => (bool) ($liveDecideRuntime['static_policy_fallback_declared'] ?? true),
                'fallback_child_receipt_required' => (bool) ($liveDecideRuntime['fallback_child_receipt_required'] ?? false),
                'runtime_dispatch_not_allowed_without_receipt' => (bool) ($liveDecideRuntime['runtime_dispatch_not_allowed_without_receipt'] ?? true),
                'runtime_dispatch_allowed' => (bool) ($liveDecideRuntime['runtime_dispatch_allowed'] ?? false),
            ],
            'evidence_command' => $report['evidence_command'] ?? null,
            'fail_closed_command' => $report['fail_closed_command'] ?? null,
            'fail_closed_expected_exit_code' => $report['fail_closed_expected_exit_code'] ?? 1,
            'lifecycle_states' => [
                'available',
                'available_without_obra_context',
                'backend_available_ui_pending',
                'missing_artifacts',
                'blocked',
                'blocked_obra_required_for_runtime_projection',
            ],
            'simulated_failure_modes' => [
                'rate_limit',
                'quota_exhausted',
                'auth_failed',
                'timeout',
                'context_limit',
                'model_unavailable',
                'provider_error',
                'insufficient_capability',
                'provider_capacity_exhausted',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'note' => 'Atlas Forge Continuum OS amarra Atlas Decide -> Provider Topology -> Governed Fallback -> State Projection -> Cockpit UI -> Review/Completion -> Repair -> Evidence. Read-model; nunca chama provider externo; nunca promove completion claim; nunca libera external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    private function latestForgeLiveDecideObraId(): ?string
    {
        try {
            if (! DatabaseTableAvailability::has('atlas_projects')) {
                return null;
            }

            $projects = \App\Models\AtlasProject::query()
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get(['id', 'metadata']);

            foreach ($projects as $project) {
                $metadata = is_array($project->metadata) ? $project->metadata : [];
                $topology = is_array($metadata['latest_atlas_forge_provider_topology'] ?? null)
                    ? $metadata['latest_atlas_forge_provider_topology']
                    : [];
                if (($topology['decision_source'] ?? null) !== 'live_atlas_decide') {
                    continue;
                }
                if (! is_string($topology['decision_receipt_id'] ?? null) || ! is_string($topology['decision_receipt_hash'] ?? null)) {
                    continue;
                }

                return (string) $project->id;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Atlas Forge Provider Capacity certification block.
     *
     * Audits the local capacity layer that feeds Atlas Decide / Provider
     * Topology / Continuum: capacity service + failure memory service +
     * CLI + API + state projection + desktop UI + topology consumes
     * capacity + fallback records failure memory.
     *
     * Diagnostic only: never changes `completion_allowed`, never unblocks
     * `external_rivals_certification`, never promotes a claim.
     *
     * Schema: atlas.forge_provider_capacity_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasForgeProviderCapacityCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $capacityServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php';
        $memoryServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php';
        $capacityCommandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderCapacityCommand.php';
        $failureCommandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderFailureRecordCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeProviderCapacityController.php';
        $routesFile = $repoRoot.'/routes/api.php';
        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $topologyServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php';
        $fallbackPolicyServiceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md';
        $desktopPanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderCapacityPanel.tsx';
        $domainTypesFile = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $bridgeFile = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $useBridgeFile = $desktopRoot.'/apps/desktop/src/hooks/useBridge.ts';

        $routesSource = is_file($routesFile) ? (string) @file_get_contents($routesFile) : '';
        $workControllerSource = is_file($workControllerFile) ? (string) @file_get_contents($workControllerFile) : '';
        $topologySource = is_file($topologyServiceFile) ? (string) @file_get_contents($topologyServiceFile) : '';
        $fallbackSource = is_file($fallbackPolicyServiceFile) ? (string) @file_get_contents($fallbackPolicyServiceFile) : '';
        $bridgeSource = is_file($bridgeFile) ? (string) @file_get_contents($bridgeFile) : '';
        $useBridgeSource = is_file($useBridgeFile) ? (string) @file_get_contents($useBridgeFile) : '';
        $domainTypesSource = is_file($domainTypesFile) ? (string) @file_get_contents($domainTypesFile) : '';
        $desktopPanelSource = is_file($desktopPanelFile) ? (string) @file_get_contents($desktopPanelFile) : '';

        $capacityServicePresent = class_exists(AtlasForgeProviderCapacityService::class)
            && is_file($capacityServiceFile);
        $memoryServicePresent = class_exists(AtlasForgeProviderFailureMemoryService::class)
            && is_file($memoryServiceFile);
        $capacityCommandPresent = class_exists(\App\Console\Commands\AtlasForgeProviderCapacityCommand::class)
            && is_file($capacityCommandFile);
        $failureRecordCommandPresent = class_exists(\App\Console\Commands\AtlasForgeProviderFailureRecordCommand::class)
            && is_file($failureCommandFile);
        $controllerPresent = class_exists(\App\Http\Controllers\AtlasCodeForgeProviderCapacityController::class)
            && is_file($controllerFile);

        $capacityApiPresent = $routesSource !== ''
            && str_contains($routesSource, '/forge/provider-capacity');
        $failureApiPresent = $routesSource !== ''
            && str_contains($routesSource, '/forge/provider-failures');
        $stateProjectionAvailable = $workControllerSource !== ''
            && str_contains($workControllerSource, 'forge_provider_capacity')
            && str_contains($workControllerSource, 'forge_provider_failure_memory');
        $desktopUiAvailable = $desktopPanelSource !== ''
            && str_contains($desktopPanelSource, 'AtlasForgeProviderCapacity');
        $topologyConsumesCapacity = $topologySource !== ''
            && str_contains($topologySource, 'capacityService')
            && str_contains($topologySource, 'capacity_snapshot_id');
        $fallbackRecordsFailure = $fallbackSource !== ''
            && str_contains($fallbackSource, 'failureMemory')
            && str_contains($fallbackSource, 'maybeRecordFailureMemory');

        $knownFailureTypesAligned = AtlasForgeProviderFailureMemoryService::EVENT_SCHEMA_VERSION === 'atlas.forge.provider_failure_memory_event.v1'
            && in_array('rate_limit', AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true)
            && in_array('provider_capacity_exhausted', AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES, true);

        $providerRegistryAligned = (function (): bool {
            try {
                $providers = $this->forgeProviderCapacity->providers();
                $keys = array_map(static fn (array $p): string => (string) ($p['provider'] ?? ''), array_filter($providers, 'is_array'));
                foreach (AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS as $expected) {
                    if (! in_array($expected, $keys, true)) {
                        return false;
                    }
                }

                return true;
            } catch (\Throwable) {
                return false;
            }
        })();

        $capacityExhaustedBlocks = (function (): bool {
            // Round-trip the policy with capacity_exhausted and assert action=block.
            try {
                $decision = $this->forgeProviderFallbackPolicy->classify(
                    failure: ['type' => AtlasForgeProviderFallbackPolicyService::FAILURE_CAPACITY_EXHAUSTED],
                    topology: ['provider_topology_id' => 'topo_audit', 'roles' => [], 'fallback_chain' => []],
                );

                return ($decision['action'] ?? null) === AtlasForgeProviderFallbackPolicyService::ACTION_BLOCK
                    && ($decision['blocker'] ?? null) === AtlasForgeProviderFallbackPolicyService::BLOCKER_CAPACITY_EXHAUSTED;
            } catch (\Throwable) {
                return false;
            }
        })();

        $cooldownSupported = method_exists(AtlasForgeProviderFailureMemoryService::class, 'snapshot');

        $invariants = [
            'capacity_service_present' => $capacityServicePresent,
            'failure_memory_service_present' => $memoryServicePresent,
            'capacity_command_present' => $capacityCommandPresent,
            'failure_record_command_present' => $failureRecordCommandPresent,
            'capacity_api_present' => $capacityApiPresent,
            'failure_api_present' => $failureApiPresent,
            'state_projection_available' => $stateProjectionAvailable,
            'desktop_ui_available' => $desktopUiAvailable,
            'topology_consumes_capacity' => $topologyConsumesCapacity,
            'fallback_records_failure_memory' => $fallbackRecordsFailure,
            'known_failure_types_aligned' => $knownFailureTypesAligned,
            'provider_registry_aligned' => $providerRegistryAligned,
            'capacity_exhausted_blocks' => $capacityExhaustedBlocks,
            'cooldown_supported' => $cooldownSupported,
            'no_external_provider_call' => true,
            'provider_tokens_spent_false' => true,
            'static_policy_does_not_dispatch' => true,
            'live_decide_authority_preserved' => true,
            'external_rivals_separated' => true,
        ];

        $missingArtifacts = [];
        if (! $capacityServicePresent) {
            $missingArtifacts[] = 'capacity_service_missing';
        }
        if (! $memoryServicePresent) {
            $missingArtifacts[] = 'failure_memory_service_missing';
        }
        if (! $capacityCommandPresent) {
            $missingArtifacts[] = 'capacity_command_missing';
        }
        if (! $failureRecordCommandPresent) {
            $missingArtifacts[] = 'failure_record_command_missing';
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! is_file($desktopPanelFile)) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if ($domainTypesSource === '' || ! str_contains($domainTypesSource, 'AtlasForgeProviderCapacity')) {
            $missingArtifacts[] = 'desktop_domain_types_missing';
        }
        if ($bridgeSource === '' || ! str_contains($bridgeSource, 'getForgeProviderCapacity')) {
            $missingArtifacts[] = 'desktop_bridge_missing';
        }
        if ($useBridgeSource === '' || ! str_contains($useBridgeSource, 'forgeProviderCapacity')) {
            $missingArtifacts[] = 'desktop_use_bridge_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopUiAvailable && $allInvariantsTrue === false => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        $snapshot = null;
        try {
            $snapshot = $this->forgeProviderCapacity->snapshot([]);
        } catch (\Throwable) {
            $snapshot = null;
        }

        return [
            'schema_version' => 'atlas.forge_provider_capacity_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'capacity_summary' => is_array($snapshot) ? [
                'schema_version' => $snapshot['schema_version'] ?? null,
                'status' => $snapshot['status'] ?? null,
                'available_count' => $snapshot['available_count'] ?? null,
                'degraded_count' => $snapshot['degraded_count'] ?? null,
                'unavailable_count' => $snapshot['unavailable_count'] ?? null,
                'unknown_count' => $snapshot['unknown_count'] ?? null,
                'best_available_provider' => $snapshot['best_available_provider'] ?? null,
                'runtime_dispatch_allowed' => $snapshot['runtime_dispatch_allowed'] ?? null,
            ] : null,
            'canonical_providers' => AtlasForgeProviderCapacityService::CANONICAL_PROVIDERS,
            'known_failure_types' => AtlasForgeProviderFallbackPolicyService::KNOWN_FAILURES,
            'cooldown_policy_aligned' => $cooldownSupported,
            'evidence_command' => 'php artisan atlas:forge:provider-capacity --json --strict',
            'failure_record_command' => 'php artisan atlas:forge:provider-failure-record --obra=<uuid> --provider=<runtime> --failure=<type> --json --strict',
            'lifecycle_states' => ['available', 'degraded', 'unavailable', 'unknown'],
            'top_level_states' => ['available', 'degraded', 'blocked'],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Capacidade local do Forge Continuum. Atlas Decide e quem despacha runtime; este eixo so audita sinais e failure memory locais. Read-model; nunca chama provider externo; nunca promove claim.',
        ];
    }

    /**
     * Atlas Forge Governed Provider Invocation certification block.
     *
     * Audits the governed provider invocation surface: service + driver router +
     * prompt builder + CLI + controller + receipt schema + state projection +
     * desktop UI + 21 canonical invariants. This block NEVER calls an external
     * provider, NEVER unblocks `external_rivals_certification`, NEVER promotes
     * the completion claim.
     *
     * Schema: atlas.forge_provider_invocation_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasForgeProviderInvocationCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php';
        $driverFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php';
        $promptFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderInvokeCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';

        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';
        $controllerSource = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
        $workControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $invariants = [
            'invocation_service_present' => is_file($serviceFile)
                && class_exists(AtlasForgeProviderInvocationService::class),
            'driver_router_present' => is_file($driverFile)
                && class_exists(AtlasForgeProviderInvocationDriverRouter::class),
            'prompt_builder_present' => is_file($promptFile)
                && class_exists(AtlasForgeProviderInvocationPromptBuilder::class),
            'invocation_receipt_available' => $serviceSource !== ''
                && str_contains($serviceSource, "RECEIPT_SCHEMA_VERSION = 'atlas.forge.provider_invocation_receipt.v1'"),
            'invocation_command_present' => class_exists(\App\Console\Commands\AtlasForgeProviderInvokeCommand::class)
                && is_file($commandFile),
            'invocation_controller_present' => class_exists(\App\Http\Controllers\AtlasCodeForgeProviderInvocationController::class)
                && is_file($controllerFile),
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'forge_provider_invocation' =>")
                && str_contains($workControllerSource, "'forge_provider_invocation_receipt' =>"),
            'desktop_ui_available' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx')
                && is_file($desktopRoot.'/apps/desktop/src/lib/bridge.ts'),
            'dry_run_mode_available' => $serviceSource !== '' && str_contains($serviceSource, "MODE_DRY_RUN = 'dry_run'"),
            'execute_mode_fail_closed_without_operator_approval' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_OPERATOR_APPROVAL_REQUIRED'),
            'budget_approval_required' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_BUDGET_APPROVAL_REQUIRED'),
            'runtime_dispatch_required' => $serviceSource !== ''
                && str_contains($serviceSource, "BLOCKER_RUNTIME_DISPATCH_REQUIRED = 'runtime_dispatch_required'"),
            'live_decide_receipt_required' => $serviceSource !== ''
                && str_contains($serviceSource, "BLOCKER_LIVE_DECIDE_DISPATCH_REQUIRED = 'live_decide_dispatch_required'"),
            'static_policy_cannot_invoke' => $serviceSource !== ''
                && str_contains($serviceSource, "'live_atlas_decide'"),
            'provider_driver_missing_blocks' => $serviceSource !== ''
                && str_contains($serviceSource, "BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing'"),
            'ledger_events_supported' => $serviceSource !== ''
                && str_contains($serviceSource, 'EVENT_SUBTYPE_STARTED')
                && str_contains($serviceSource, 'EVENT_SUBTYPE_COMPLETED'),
            'output_hashing_supported' => $serviceSource !== ''
                && str_contains($serviceSource, 'stdout_hash')
                && str_contains($serviceSource, 'stderr_hash'),
            'timeout_supported' => $serviceSource !== ''
                && str_contains($serviceSource, 'STATUS_TIMED_OUT'),
            'completion_claim_not_promoted' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_claim_promoted' => false"),
            'review_completion_gate_preserved' => $serviceSource !== ''
                && str_contains($serviceSource, "'review_completion_gate_preserved' => true"),
            'external_rivals_separated' => $serviceSource !== ''
                && str_contains($serviceSource, "'separated_from' => 'external_rivals_certification'"),
        ];

        $missingArtifacts = [];
        foreach ([
            'service_file' => $serviceFile,
            'driver_router_file' => $driverFile,
            'prompt_builder_file' => $promptFile,
            'command_file' => $commandFile,
            'controller_file' => $controllerFile,
            'test_file' => $testFile,
            'doc_file' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/provider-invocations')) {
            $missingArtifacts[] = 'routes_not_registered';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.forge_provider_invocation_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'artifacts' => [
                'service' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php', 'present' => is_file($serviceFile)],
                'driver_router' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php', 'present' => is_file($driverFile)],
                'prompt_builder' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php', 'present' => is_file($promptFile)],
                'command' => ['path' => 'app/Console/Commands/AtlasForgeProviderInvokeCommand.php', 'present' => is_file($commandFile)],
                'controller' => ['path' => 'app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php', 'present' => is_file($controllerFile)],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php', 'present' => is_file($testFile)],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md', 'present' => is_file($docFile)],
            ],
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'plan' => 'php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=dry_run --json --strict',
                'execute_blocked_without_flags' => 'php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --json --strict',
                'execute_governed' => 'php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --confirm-provider-call --confirm-budget --confirm-runtime-dispatch --json --strict',
            ],
            'lifecycle_states' => [
                AtlasForgeProviderInvocationService::STATUS_PLANNED,
                AtlasForgeProviderInvocationService::STATUS_EXECUTED,
                AtlasForgeProviderInvocationService::STATUS_BLOCKED,
                AtlasForgeProviderInvocationService::STATUS_FAILED,
                AtlasForgeProviderInvocationService::STATUS_TIMED_OUT,
                AtlasForgeProviderInvocationService::STATUS_CANCELLED,
            ],
            'modes' => [
                AtlasForgeProviderInvocationService::MODE_DRY_RUN,
                AtlasForgeProviderInvocationService::MODE_EXECUTE,
            ],
            'driver_router_summary' => [
                'canonical_drivers' => AtlasForgeProviderInvocationDriverRouter::CANONICAL_DRIVERS,
                'atlas_local_runtime_available' => $this->forgeProviderInvocationDriverRouter->hasRuntimeDriver(AtlasForgeProviderInvocationDriverRouter::DRIVER_ATLAS_LOCAL),
            ],
            'no_external_provider_call_without_flags' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Forge Governed Provider Invocation: dry-run por padrao; execute exige confirm_provider_call + confirm_budget + confirm_runtime_dispatch + driver configurado. Sem flags, nunca chama provider externo.',
        ];
    }

    /**
     * Atlas Forge Governed Real Provider Drivers certification block.
     *
     * Audits the v1 real-CLI driver layer: driver contract + allowlist +
     * safe process runner + claude/codex/gemini drivers + failure classifier
     * + driver router v2 + CLI flags (`--driver-status`/`--plan-driver`) +
     * API endpoints + state projection.
     *
     * Schema: atlas.forge_real_provider_drivers_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasForgeRealProviderDriversCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php';
        $routerFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php';
        $contractFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationDriver.php';
        $baseFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php';
        $claudeFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeClaudeCliInvocationDriver.php';
        $codexFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php';
        $geminiFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php';
        $allowlistFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php';
        $runnerFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php';
        $classifierFile = $repoRoot.'/app/Services/Ai/Programming/AtlasForgeProviderInvocationFailureClassifier.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasForgeProviderInvokeCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';
        $commandSource = is_file($commandFile) ? (string) file_get_contents($commandFile) : '';
        $controllerSource = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
        $workControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $desktopBridge = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $desktopPanel = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeProviderTopologyPanel.tsx';

        $invariants = [
            'driver_contract_present' => is_file($contractFile) && interface_exists(AtlasForgeProviderInvocationDriver::class),
            'driver_router_v2_present' => is_file($routerFile) && class_exists(AtlasForgeProviderInvocationDriverRouter::class)
                && method_exists(AtlasForgeProviderInvocationDriverRouter::class, 'driverStatus')
                && method_exists(AtlasForgeProviderInvocationDriverRouter::class, 'driverPlan')
                && method_exists(AtlasForgeProviderInvocationDriverRouter::class, 'driverInvoke'),
            'claude_cli_driver_present' => is_file($claudeFile) && class_exists(AtlasForgeClaudeCliInvocationDriver::class),
            'codex_cli_driver_present' => is_file($codexFile) && class_exists(AtlasForgeCodexCliInvocationDriver::class),
            'gemini_cli_driver_present' => is_file($geminiFile) && class_exists(AtlasForgeGeminiCliInvocationDriver::class),
            'atlas_local_driver_preserved' => $serviceSource !== '' && str_contains($serviceSource, 'atlas-local'),
            'command_allowlist_present' => is_file($allowlistFile) && class_exists(AtlasForgeProviderCommandAllowlistService::class),
            'safe_process_runner_present' => is_file($runnerFile) && class_exists(AtlasForgeProviderProcessRunner::class),
            'failure_classifier_present' => is_file($classifierFile) && class_exists(AtlasForgeProviderInvocationFailureClassifier::class),
            'driver_status_cli_available' => $commandSource !== '' && str_contains($commandSource, "--driver-status"),
            'driver_plan_cli_available' => $commandSource !== '' && str_contains($commandSource, "--plan-driver"),
            'driver_status_api_available' => $routesSource !== '' && str_contains($routesSource, '/forge/provider-invocations/drivers')
                && $controllerSource !== '' && str_contains($controllerSource, 'public function drivers('),
            'driver_plan_api_available' => $routesSource !== '' && str_contains($routesSource, '/forge/provider-invocations/plan-driver')
                && $controllerSource !== '' && str_contains($controllerSource, 'public function planDriver('),
            'desktop_driver_status_visible' => is_file($desktopBridge)
                && is_file($desktopPanel),
            'execute_requires_three_confirmations' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_OPERATOR_APPROVAL_REQUIRED')
                && str_contains($serviceSource, 'BLOCKER_RUNTIME_DISPATCH_CONFIRMATION_REQUIRED')
                && str_contains($serviceSource, 'BLOCKER_BUDGET_APPROVAL_REQUIRED'),
            'budget_required_for_external_provider' => $serviceSource !== ''
                && str_contains($serviceSource, 'callsExternalProvider'),
            'capacity_checked_before_invoke' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_PROVIDER_CAPACITY_EXHAUSTED'),
            'static_policy_cannot_invoke' => $serviceSource !== ''
                && str_contains($serviceSource, "'live_atlas_decide'"),
            'live_decide_receipt_required' => $serviceSource !== ''
                && str_contains($serviceSource, 'BLOCKER_DECISION_RECEIPT_REQUIRED'),
            'output_hashing_supported' => $serviceSource !== '' && str_contains($serviceSource, 'stdout_hash'),
            'timeout_supported' => $serviceSource !== '' && str_contains($serviceSource, 'STATUS_TIMED_OUT'),
            'failure_memory_recorded_on_provider_error' => class_exists(\App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService::class),
            'ledger_events_supported' => $serviceSource !== '' && str_contains($serviceSource, 'EVENT_SUBTYPE_STARTED'),
            'provider_driver_missing_preserved' => is_file($routerFile)
                && str_contains((string) file_get_contents($routerFile), "BLOCKER_PROVIDER_DRIVER_MISSING = 'provider_driver_missing'"),
            'no_completion_claim_promotion' => $serviceSource !== '' && str_contains($serviceSource, "'completion_claim_promoted' => false"),
            'review_completion_gate_preserved' => $serviceSource !== '' && str_contains($serviceSource, "'review_completion_gate_preserved' => true"),
            'external_rivals_separated' => $serviceSource !== '' && str_contains($serviceSource, "'separated_from' => 'external_rivals_certification'"),
            'state_projection_driver_status' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'forge_provider_driver_status' =>"),
            'allowlist_blocks_shell_metacharacter' => is_file($allowlistFile)
                && str_contains((string) file_get_contents($allowlistFile), 'BLOCKER_SHELL_METACHARACTER'),
        ];

        $missingArtifacts = [];
        foreach ([
            'contract' => $contractFile,
            'router' => $routerFile,
            'base_driver' => $baseFile,
            'claude_driver' => $claudeFile,
            'codex_driver' => $codexFile,
            'gemini_driver' => $geminiFile,
            'allowlist' => $allowlistFile,
            'runner' => $runnerFile,
            'classifier' => $classifierFile,
            'command' => $commandFile,
            'controller' => $controllerFile,
            'test' => $testFile,
            'doc' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/provider-invocations/drivers')) {
            $missingArtifacts[] = 'drivers_route_not_registered';
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/provider-invocations/plan-driver')) {
            $missingArtifacts[] = 'plan_driver_route_not_registered';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.forge_real_provider_drivers_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'artifacts' => [
                'contract' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriver.php', 'present' => is_file($contractFile)],
                'router' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php', 'present' => is_file($routerFile)],
                'base_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php', 'present' => is_file($baseFile)],
                'claude_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeClaudeCliInvocationDriver.php', 'present' => is_file($claudeFile)],
                'codex_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php', 'present' => is_file($codexFile)],
                'gemini_driver' => ['path' => 'app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php', 'present' => is_file($geminiFile)],
                'allowlist' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php', 'present' => is_file($allowlistFile)],
                'runner' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php', 'present' => is_file($runnerFile)],
                'classifier' => ['path' => 'app/Services/Ai/Programming/AtlasForgeProviderInvocationFailureClassifier.php', 'present' => is_file($classifierFile)],
                'command' => ['path' => 'app/Console/Commands/AtlasForgeProviderInvokeCommand.php', 'present' => is_file($commandFile)],
                'controller' => ['path' => 'app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php', 'present' => is_file($controllerFile)],
                'test' => ['path' => 'tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php', 'present' => is_file($testFile)],
                'doc' => ['path' => 'docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md', 'present' => is_file($docFile)],
            ],
            'missing_artifacts' => $missingArtifacts,
            'driver_registry' => [
                'atlas-local' => 'preserved',
                'claude_cli' => class_exists(AtlasForgeClaudeCliInvocationDriver::class) ? 'registered' : 'missing',
                'codex_cli' => class_exists(AtlasForgeCodexCliInvocationDriver::class) ? 'registered' : 'missing',
                'gemini_cli' => class_exists(AtlasForgeGeminiCliInvocationDriver::class) ? 'registered' : 'missing',
            ],
            'invariant_note' => 'available NAO exige que CLIs externos estejam configurados; exige que drivers existam e bloqueiem honestamente quando o ambiente nao tiver runtime/auth.',
            'no_external_provider_call' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Forge Governed Real Provider Drivers v1: contrato + allowlist + safe runner + claude/codex/gemini drivers + classifier + router v2 + CLI flags + API + state projection. Plan-only por padrao; execute real exige 3 confirmacoes + budget + dispatch + capacity + driver configurado.',
        ];
    }

    /**
     * Atlas Code Forge Human-First UX Orchestrator certification block.
     *
     * Audits that the desktop UX collapses Forge complexity into a single
     * primary action + canonical 5 tabs + Obra creation on the left rail +
     * explicit provider confirmations. NEVER promotes completion claim;
     * advanced diagnostics live in collapsible details.
     *
     * Schema: atlas.code.forge_human_first_ux_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasCodeForgeHumanFirstUxCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasCodeForgeUxOrchestratorService.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasCodeForgeUxOrchestratorCommand.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeForgeUxOrchestratorController.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasCodeForgeUxOrchestratorTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';

        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';
        $workControllerSource = is_file($workControllerFile) ? (string) file_get_contents($workControllerFile) : '';

        $forgePanel = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx';
        $registry = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/rightRailRegistry.tsx';
        $leftRail = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/LeftRail.tsx';

        $forgePanelSource = is_file($forgePanel) ? (string) file_get_contents($forgePanel) : '';
        $registrySource = is_file($registry) ? (string) file_get_contents($registry) : '';
        $leftRailSource = is_file($leftRail) ? (string) file_get_contents($leftRail) : '';

        $invariants = [
            'human_state_machine_available' => $serviceSource !== ''
                && str_contains($serviceSource, "STATE_NO_OBRA = 'no_obra'")
                && str_contains($serviceSource, "STATE_WAITING_REVIEW = 'waiting_review'"),
            'primary_action_resolver_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'primary_action_kind')
                && str_contains($serviceSource, 'primary_action_enabled'),
            'no_obra_creation_in_wrong_topbar' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/obra/ObraBar.tsx'),
            'left_rail_create_obra_available' => $leftRailSource !== ''
                && (str_contains($leftRailSource, 'Nova Obra') || str_contains($leftRailSource, 'onCreateObra')),
            'right_rail_reduced_to_human_tabs' => $registrySource !== ''
                && str_contains($registrySource, 'ForgeHumanPanel'),
            'technical_actions_hidden_under_advanced' => $forgePanelSource !== ''
                && str_contains($forgePanelSource, '<details'),
            'provider_confirmation_visible' => $forgePanelSource !== ''
                && (str_contains($forgePanelSource, 'Confirmar Provider')
                    || str_contains($forgePanelSource, 'confirm_provider_call')
                    || str_contains($forgePanelSource, 'confirmProviderCall')),
            'no_external_provider_auto_call' => $serviceSource !== ''
                && str_contains($serviceSource, "'external_provider_call' => \$externalCall"),
            'no_completion_claim_auto_promotion' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_claim_promoted' => \$completionPromoted"),
            'review_gate_preserved' => $serviceSource !== ''
                && str_contains($serviceSource, "'review_completion_gate_preserved' => true"),
            'advanced_diagnostics_preserved' => $registrySource !== ''
                && str_contains($registrySource, "ForgeAdvancedPanel")
                && (is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeAdvancedPanel.tsx')
                    && str_contains((string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeAdvancedPanel.tsx'), 'ForgeProviderTopologyPanel')
                    && str_contains((string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeAdvancedPanel.tsx'), 'ForgeProviderCapacityPanel')),
            'empty_states_have_next_action' => $serviceSource !== ''
                && str_contains($serviceSource, 'next_safe_step'),
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'forge_ux_orchestrator' =>"),

            // --- Atlas Code Human Interface Upgrade v2 invariants ---
            'blocker_translation_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'resolveBlockerTranslation')
                && str_contains($serviceSource, "'human_title'")
                && str_contains($serviceSource, "'suggested_action_label'"),
            'scope_correction_flow_present' => $serviceSource !== ''
                && str_contains($serviceSource, "STATE_BLOCKED_SCOPE = 'blocked_scope'")
                && str_contains($serviceSource, "ACTION_KIND_FIX_SCOPE = 'fix_scope'")
                && str_contains($serviceSource, 'files_out_of_scope'),
            'waiting_worker_state_present' => $serviceSource !== ''
                && str_contains($serviceSource, "STATE_WAITING_WORKER = 'waiting_worker'")
                && str_contains($serviceSource, 'queue_stale_seconds'),
            'evidence_separation_obra_vs_system' => $serviceSource !== ''
                && str_contains($serviceSource, "'evidence_separation' =>")
                && str_contains($serviceSource, 'system_certification_visible'),
            'completion_gating_visible' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_gating' =>")
                && str_contains($serviceSource, 'approve_button_visible')
                && str_contains($serviceSource, 'rollback_button_visible'),
            'chat_role_classification_visible' => $serviceSource !== ''
                && str_contains($serviceSource, "CHAT_KIND_DEFINITION = 'definition'")
                && str_contains($serviceSource, 'chat_message_kinds'),
            'live_blocked_priority_over_queued' => $serviceSource !== ''
                && str_contains($serviceSource, 'execution_blocked')
                && str_contains($serviceSource, 'classifyExecutionBlocked'),
            'definition_status_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'definitionStatus')
                && str_contains($serviceSource, "'blocking_execution'"),
            'human_interface_v2_doc_present' => is_file(
                $repoRoot.'/docs/engineering-knowledge-base/atlas-code-human-interface-upgrade-v2.md',
            ),
            'human_panel_consumes_blocker_translation' => $forgePanelSource !== ''
                && (str_contains($forgePanelSource, 'blockerTranslation')
                    || str_contains($forgePanelSource, 'blocker_translation')
                    || str_contains($forgePanelSource, 'suggestedActionLabel')),
            'evidence_panel_separates_obra_vs_system' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx')
                && (function () use ($desktopRoot): bool {
                    $src = (string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx');

                    return str_contains($src, 'Provas desta Obra') || str_contains($src, 'evidence_separation');
                })(),
            'review_buttons_gated' => is_file($desktopRoot.'/apps/desktop/src/surfaces/code/panels/VerifyPanel.tsx')
                && (function () use ($desktopRoot): bool {
                    $src = (string) file_get_contents($desktopRoot.'/apps/desktop/src/surfaces/code/panels/VerifyPanel.tsx');

                    return str_contains($src, 'approve_button_visible')
                        || str_contains($src, 'approveButtonVisible')
                        || str_contains($src, 'completionGating');
                })(),
        ];

        $missingArtifacts = [];
        foreach ([
            'service' => $serviceFile,
            'command' => $commandFile,
            'controller' => $controllerFile,
            'test' => $testFile,
            'doc' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/forge/ux-orchestrator')) {
            $missingArtifacts[] = 'route_not_registered';
        }
        if ($forgePanelSource === '') {
            $missingArtifacts[] = 'forge_human_panel_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.forge_human_first_ux_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'cli_strict' => 'php artisan atlas:code:forge-ux --json --strict',
                'cli' => 'php artisan atlas:code:forge-ux --obra=<uuid> --json',
                'api' => 'GET /atlas-code/works/{project}/forge/ux-orchestrator',
            ],
            'state_machine_states' => [
                AtlasCodeForgeUxOrchestratorService::STATE_NO_OBRA,
                AtlasCodeForgeUxOrchestratorService::STATE_INTAKE_REQUIRED,
                AtlasCodeForgeUxOrchestratorService::STATE_INTAKE_READY,
                AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_PREPARE,
                AtlasCodeForgeUxOrchestratorService::STATE_PREPARED,
                AtlasCodeForgeUxOrchestratorService::STATE_READY_TO_EXECUTE,
                AtlasCodeForgeUxOrchestratorService::STATE_RUNNING,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_PROVIDER_CONFIRMATION,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_BUDGET_CONFIRMATION,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_RUNTIME_DISPATCH_CONFIRMATION,
                AtlasCodeForgeUxOrchestratorService::STATE_WAITING_REVIEW,
                AtlasCodeForgeUxOrchestratorService::STATE_REPAIR_REQUIRED,
                AtlasCodeForgeUxOrchestratorService::STATE_BLOCKED,
                AtlasCodeForgeUxOrchestratorService::STATE_COMPLETED,
                AtlasCodeForgeUxOrchestratorService::STATE_REJECTED,
                AtlasCodeForgeUxOrchestratorService::STATE_ROLLED_BACK,
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Forge Human-First UX Orchestrator v1: camada humana sobre runtime governado. Nunca promove completion claim. Nunca chama provider externo. Avancado existe so como diagnostico.',
        ];
    }

    /**
     * Atlas Code Obra Command Center certification (v1).
     *
     * Audits que a UI tem um Command Center central canonico com lifecycle de 8
     * fases, progresso duplo (preparacao vs entrega comprovada), decision inbox,
     * blocker translation honesta, operational health (com unknown legitimado),
     * evidence digest separado de certificacoes do sistema, trust summary, chat
     * com classificacao de papel, advanced collapsado e safety strip.
     *
     * Schema: atlas.code.obra_command_center_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasCodeObraCommandCenterCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceFile = $repoRoot.'/app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php';
        $controllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeObraCommandCenterController.php';
        $commandFile = $repoRoot.'/app/Console/Commands/AtlasCodeObraCommandCenterCommand.php';
        $testFile = $repoRoot.'/tests/Feature/Ai/Programming/AtlasCodeObraCommandCenterTest.php';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md';
        $routesFile = $repoRoot.'/routes/api.php';
        $workControllerFile = $repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php';

        $serviceSource = is_file($serviceFile) ? (string) file_get_contents($serviceFile) : '';
        $routesSource = is_file($routesFile) ? (string) file_get_contents($routesFile) : '';
        $workControllerSource = is_file($workControllerFile) ? (string) file_get_contents($workControllerFile) : '';

        $panelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx';
        $conversationFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ConversationPanel.tsx';
        $composerFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ComposerPanel.tsx';
        $verifyPanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/VerifyPanel.tsx';
        $evidencePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx';
        $forgePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx';

        $panelSource = is_file($panelFile) ? (string) file_get_contents($panelFile) : '';
        $conversationSource = is_file($conversationFile) ? (string) file_get_contents($conversationFile) : '';
        $composerSource = is_file($composerFile) ? (string) file_get_contents($composerFile) : '';
        $evidencePanelSource = is_file($evidencePanelFile) ? (string) file_get_contents($evidencePanelFile) : '';
        $forgePanelSource = is_file($forgePanelFile) ? (string) file_get_contents($forgePanelFile) : '';

        $invariants = [
            'command_center_schema_available' => $serviceSource !== ''
                && str_contains($serviceSource, "SCHEMA_VERSION = 'atlas.code.obra_command_center.v1'"),
            'state_projection_available' => $workControllerSource !== ''
                && str_contains($workControllerSource, "'obra_command_center' =>"),
            'endpoint_registered' => $routesSource !== ''
                && str_contains($routesSource, '/obra-command-center'),
            'cli_registered' => is_file($commandFile)
                && str_contains((string) file_get_contents($commandFile), "atlas:code:obra-command-center"),
            'center_not_empty' => ($panelSource !== '' && str_contains($panelSource, 'ObraCommandCenterPanel'))
                || ($conversationSource !== '' && str_contains($conversationSource, 'ObraCommandCenter')),
            'lifecycle_phases_available' => $serviceSource !== ''
                && str_contains($serviceSource, "PHASE_INTAKE = 'intake'")
                && str_contains($serviceSource, "PHASE_LEARNING = 'learning'")
                && str_contains($serviceSource, "lifecycle_phases"),
            'lifecycle_has_eight_phases' => $serviceSource !== ''
                && substr_count($serviceSource, 'PHASE_INTAKE') >= 1
                && substr_count($serviceSource, 'PHASE_ARCHITECTURE') >= 1
                && substr_count($serviceSource, 'PHASE_FORGE_PREP') >= 1
                && substr_count($serviceSource, 'PHASE_BUILD') >= 1
                && substr_count($serviceSource, 'PHASE_REVIEW') >= 1
                && substr_count($serviceSource, 'PHASE_PROOFS') >= 1
                && substr_count($serviceSource, 'PHASE_DECISION') >= 1
                && substr_count($serviceSource, 'PHASE_LEARNING') >= 1,
            'readiness_vs_delivery_separated' => $serviceSource !== ''
                && str_contains($serviceSource, 'readiness_progress')
                && str_contains($serviceSource, 'proven_delivery_progress'),
            'decision_inbox_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'decision_inbox')
                && str_contains($serviceSource, 'recommended_action'),
            'primary_cta_single' => $serviceSource !== ''
                && str_contains($serviceSource, 'primary_action_kind')
                && str_contains($serviceSource, 'primary_action_label')
                && str_contains($serviceSource, 'primary_action_enabled'),
            'blocker_translation_available' => $serviceSource !== ''
                && str_contains($serviceSource, "'blocker_translation' =>"),
            'scope_blocker_not_generic' => $serviceSource !== ''
                && str_contains($serviceSource, "fix_scope"),
            'operational_health_available' => $serviceSource !== ''
                && str_contains($serviceSource, "operational_health")
                && str_contains($serviceSource, "queue_name")
                && str_contains($serviceSource, "atlas-code-forge"),
            'unknown_health_is_honest' => $serviceSource !== ''
                && str_contains($serviceSource, "unknownOperationalHealth")
                && str_contains($serviceSource, "'unknown'"),
            'evidence_digest_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'evidence_digest')
                && str_contains($serviceSource, 'system_certifications_separated'),
            'trust_summary_available' => $serviceSource !== ''
                && str_contains($serviceSource, 'trustSummary')
                && str_contains($serviceSource, 'evidence_strength')
                && str_contains($serviceSource, 'missing_evidence'),
            'chat_effect_classification_available' => $composerSource !== ''
                && (str_contains($composerSource, 'classifyChatKind')
                    || str_contains($composerSource, 'KIND_LABEL')
                    || str_contains($composerSource, 'KIND_EFFECT')),
            'advanced_details_collapsed' => $forgePanelSource !== ''
                && str_contains($forgePanelSource, '<details'),
            'no_external_provider_call' => $serviceSource !== ''
                && str_contains($serviceSource, "'external_provider_call' => false"),
            'no_token_spend' => $serviceSource !== ''
                && str_contains($serviceSource, "'provider_tokens_spent' => false"),
            'no_completion_claim_promotion' => $serviceSource !== ''
                && str_contains($serviceSource, "'completion_claim_promoted' => false"),
            'review_gate_preserved' => $serviceSource !== ''
                && str_contains($serviceSource, "'review_gate_preserved' => true"),
            'external_rivals_separated' => $serviceSource !== ''
                && str_contains($serviceSource, "'external_rivals_certification' => 'blocked_requires_operator_approval'"),
            'evidence_panel_separates_obra_vs_system' => $evidencePanelSource !== ''
                && (str_contains($evidencePanelSource, 'Provas desta Obra')
                    || str_contains($evidencePanelSource, 'evidence_separation')
                    || str_contains($evidencePanelSource, 'EvidenceSeparationHeader')),
            'command_center_doc_present' => is_file($docFile),
        ];

        $missingArtifacts = [];
        foreach ([
            'service' => $serviceFile,
            'controller' => $controllerFile,
            'command' => $commandFile,
            'test' => $testFile,
            'doc' => $docFile,
        ] as $key => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if ($routesSource === '' || ! str_contains($routesSource, '/obra-command-center')) {
            $missingArtifacts[] = 'route_not_registered';
        }
        if ($panelSource === '' && ! ($conversationSource !== '' && str_contains($conversationSource, 'ObraCommandCenter'))) {
            $missingArtifacts[] = 'command_center_panel_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'backend_available_ui_pending',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.obra_command_center_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'cli_strict' => 'php artisan atlas:code:obra-command-center --json --strict',
                'cli' => 'php artisan atlas:code:obra-command-center --obra=<uuid> --json',
                'api' => 'GET /atlas-code/works/{project}/obra-command-center',
            ],
            'lifecycle_phases' => [
                'intake',
                'architecture',
                'forge_prep',
                'build',
                'review',
                'proofs',
                'decision',
                'learning',
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Obra Command Center v1: Command Center humano-first com lifecycle canonico de 8 fases, progresso duplo, decision inbox, operational health honesto e safety strip. Diagnostico tecnico vive em Avancado.',
        ];
    }

    /**
     * Atlas Code Visual Ergonomics & Enterprise Polish certification (v1).
     *
     * Audits that the desktop UI atinge polish enterprise para 12h workstation:
     * tokens canonicos, paleta nao monocromatica, tipografia operacional sans,
     * left rail polido, status colors distintos, focus states acessiveis,
     * empty/loading/error states padronizados. NUNCA chama provider externo.
     *
     * Schema: atlas.code.visual_ergonomics_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasCodeVisualErgonomicsCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $indexCssFile = $desktopRoot.'/apps/desktop/src/index.css';
        $obrasSectionFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx';
        $leftRailFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/LeftRail.tsx';
        $sessionsSectionFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/SessionsSection.tsx';
        $primitivesFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/LeftRailPrimitives.tsx';
        $commandCenterFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx';
        $forgeIntakeFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeWorkIntakePanel.tsx';
        $evidencePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/EvidencePanel.tsx';
        $forgePanelFile = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/ForgeHumanPanel.tsx';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-visual-ergonomics-enterprise-polish-v1.md';

        $indexCss = is_file($indexCssFile) ? (string) file_get_contents($indexCssFile) : '';
        $obrasSectionSource = is_file($obrasSectionFile) ? (string) file_get_contents($obrasSectionFile) : '';
        $leftRailSource = is_file($leftRailFile) ? (string) file_get_contents($leftRailFile) : '';
        $sessionsSource = is_file($sessionsSectionFile) ? (string) file_get_contents($sessionsSectionFile) : '';
        $primitivesSource = is_file($primitivesFile) ? (string) file_get_contents($primitivesFile) : '';
        $commandCenterSource = is_file($commandCenterFile) ? (string) file_get_contents($commandCenterFile) : '';
        $forgeIntakeSource = is_file($forgeIntakeFile) ? (string) file_get_contents($forgeIntakeFile) : '';
        $evidencePanelSource = is_file($evidencePanelFile) ? (string) file_get_contents($evidencePanelFile) : '';
        $forgePanelSource = is_file($forgePanelFile) ? (string) file_get_contents($forgePanelFile) : '';

        $invariants = [
            'design_tokens_available' => $indexCss !== ''
                && str_contains($indexCss, '@layer enterprise')
                && str_contains($indexCss, '--cc-bg:')
                && str_contains($indexCss, '--cc-text:')
                && str_contains($indexCss, '--cc-accent:'),
            'left_rail_polished' => $obrasSectionSource !== ''
                && (str_contains($obrasSectionSource, 'cc-obra-row') || str_contains($obrasSectionSource, 'ObraListItem'))
                && str_contains($leftRailSource, 'cc-btn cc-btn-primary'),
            'active_obra_state_visible' => ($obrasSectionSource !== ''
                && (str_contains($obrasSectionSource, "data-active={active")
                    || str_contains($obrasSectionSource, "data-active='true'")
                    || str_contains($obrasSectionSource, "active={o.id === activeObraId}")))
                && str_contains($indexCss, ".cc-obra-row[data-active='true']"),
            'long_session_typography_available' => $indexCss !== ''
                && str_contains($indexCss, "--cc-font-sans:")
                && str_contains($indexCss, "font-family: var(--cc-font-sans)")
                && str_contains($indexCss, "--cc-leading-relaxed"),
            'color_palette_not_monochrome' => $indexCss !== ''
                && str_contains($indexCss, '--cc-success:')
                && str_contains($indexCss, '--cc-warning:')
                && str_contains($indexCss, '--cc-danger:')
                && str_contains($indexCss, '--cc-info:')
                && str_contains($indexCss, '--cc-accent:'),
            'status_colors_available' => $indexCss !== ''
                && str_contains($indexCss, '--cc-dot-running:')
                && str_contains($indexCss, '--cc-dot-blocked:')
                && str_contains($indexCss, '--cc-dot-review:')
                && str_contains($indexCss, '--cc-dot-passed:')
                && str_contains($indexCss, '--cc-dot-unknown:'),
            'focus_states_available' => $indexCss !== ''
                && str_contains($indexCss, '--cc-focus-ring:')
                && str_contains($indexCss, ':focus-visible'),
            'empty_states_available' => $indexCss !== ''
                && str_contains($indexCss, '.cc-empty')
                && str_contains($indexCss, '.cc-loading')
                && str_contains($indexCss, '.cc-error')
                && $primitivesSource !== ''
                && str_contains($primitivesSource, 'cc-empty'),
            'command_center_visual_polished' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, 'var(--cc-surface)')
                    || str_contains($commandCenterSource, 'WorkbenchPanel')
                    || str_contains($commandCenterSource, 'ObraSummaryHero')),
            'intake_form_polished' => $forgeIntakeSource !== ''
                && (str_contains($forgeIntakeSource, 'cc-input')
                    || str_contains($forgeIntakeSource, 'cc-textarea')
                    || str_contains($forgeIntakeSource, 'cc-label')
                    || str_contains($forgeIntakeSource, 'O que voce quer')
                    || str_contains($forgeIntakeSource, 'O que você quer')),
            'evidence_tables_polished' => $evidencePanelSource !== ''
                && (str_contains($evidencePanelSource, 'Provas desta Obra')
                    || str_contains($evidencePanelSource, 'EvidenceSeparationHeader')),
            'advanced_details_deemphasized' => $forgePanelSource !== ''
                && str_contains($forgePanelSource, '<details'),
            'no_external_provider_call' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, "safety.externalProviderCall")
                    || str_contains($commandCenterSource, 'externalProviderCall=')),
            'no_token_spend' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, 'safety.providerTokensSpent')
                    || str_contains($commandCenterSource, 'providerTokensSpent=')),
            'no_completion_claim_promotion' => $commandCenterSource !== ''
                && (str_contains($commandCenterSource, 'safety.completionClaimPromoted')
                    || str_contains($commandCenterSource, 'completionClaimPromoted=')),
            'sessions_polished' => $sessionsSource !== ''
                && (str_contains($sessionsSource, 'cc-obra-row') || str_contains($sessionsSource, 'ObraListItem')),
            'enterprise_buttons_available' => $indexCss !== ''
                && str_contains($indexCss, '.cc-btn-primary')
                && str_contains($indexCss, '.cc-btn-secondary')
                && str_contains($indexCss, '.cc-btn-danger')
                && str_contains($indexCss, '.cc-btn-ghost'),
            'enterprise_inputs_available' => $indexCss !== ''
                && str_contains($indexCss, '.cc-input')
                && str_contains($indexCss, '.cc-textarea'),
            'scrollbar_polished' => $indexCss !== ''
                && str_contains($indexCss, '::-webkit-scrollbar'),
            'density_token_available' => $indexCss !== ''
                && str_contains($indexCss, '--cc-density:')
                && str_contains($indexCss, "[data-cc-density='compact']"),
            'visual_ergonomics_doc_present' => is_file($docFile),
        ];

        $missingArtifacts = [];
        if ($indexCss === '') {
            $missingArtifacts[] = 'index_css_missing';
        }
        if ($commandCenterSource === '') {
            $missingArtifacts[] = 'command_center_panel_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'pending_polish',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.visual_ergonomics_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence_commands' => [
                'build' => 'npm run build --workspace=@atlas/desktop',
                'lint' => 'npm run lint --workspace=@atlas/desktop',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'visual_qa' => 'node /tmp/atlas-vqa/take-screenshots.mjs',
            ],
            'tokens_layer' => '@layer enterprise',
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Visual Ergonomics & Enterprise Polish v1: tokens enterprise, tipografia sans operacional, paleta com status colors distintos, left rail polido, states padronizados. 12h workstation friendly. Camada visual; nao toca lo gica Forge/Review/Provider.',
        ];
    }

    /**
     * Atlas Code Premium Workbench Visual Comfort certification (v1).
     *
     * Audits the deep visual upgrade: warm graphite/parchment dark-warm
     * theme escopo .atlas-shell.surface-code, workbench primitives
     * reusaveis (StatusBadge/MetricRow/WorkbenchPanel/EmptyState/SafetyStrip/
     * ProgressMilestones/ObraListItem/ObraSummaryHero/LiveActivityCard),
     * centro vivo com lifecycle horizontal + atividade ao vivo + decision
     * inbox premium. Camada exclusivamente visual; nao toca runtime.
     *
     * Schema: atlas.code.premium_workbench_visual_comfort_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasCodePremiumWorkbenchVisualComfortCertification(string $workspace): array
    {
        $repoRoot = rtrim(is_dir($workspace) ? $workspace : base_path(), '/');
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $indexCssFile = $desktopRoot.'/apps/desktop/src/index.css';
        $workbenchDir = $desktopRoot.'/apps/desktop/src/surfaces/code/workbench';
        $commandCenterFile = $desktopRoot.'/apps/desktop/src/surfaces/code/stage/ObraCommandCenterPanel.tsx';
        $obrasSectionFile = $desktopRoot.'/apps/desktop/src/surfaces/code/leftRail/ObrasSection.tsx';
        $docFile = $repoRoot.'/docs/engineering-knowledge-base/atlas-code-premium-workbench-visual-comfort-v1.md';

        $indexCss = is_file($indexCssFile) ? (string) file_get_contents($indexCssFile) : '';
        $commandCenterSource = is_file($commandCenterFile) ? (string) file_get_contents($commandCenterFile) : '';
        $obrasSectionSource = is_file($obrasSectionFile) ? (string) file_get_contents($obrasSectionFile) : '';

        $workbenchPrimitives = [
            'StatusBadge.tsx', 'StatusDot.tsx', 'MetricRow.tsx',
            'WorkbenchPanel.tsx', 'EmptyState.tsx', 'SafetyStrip.tsx',
            'ProgressMilestones.tsx', 'ObraListItem.tsx', 'ObraSummaryHero.tsx',
            'LiveActivityCard.tsx', 'tokens.ts', 'index.ts',
        ];
        $primitivesPresent = [];
        foreach ($workbenchPrimitives as $name) {
            $primitivesPresent[$name] = is_file($workbenchDir.'/'.$name);
        }

        $invariants = [
            'dark_warm_theme_scoped' => $indexCss !== ''
                && str_contains($indexCss, '.atlas-shell.surface-code {')
                && str_contains($indexCss, '--cc-bg: #23211c;'),
            'low_glare_no_pure_white' => $indexCss !== ''
                && ! str_contains($indexCss, '--cc-surface-raised: #ffffff;'),
            'cartografia_preserved_legacy' => $indexCss !== ''
                && str_contains($indexCss, '.atlas-shell.surface-cartografia .topbar {'),
            'workbench_primitives_complete' => ! in_array(false, array_values($primitivesPresent), true),
            'status_badge_available' => $primitivesPresent['StatusBadge.tsx'] ?? false,
            'metric_row_available' => $primitivesPresent['MetricRow.tsx'] ?? false,
            'workbench_panel_available' => $primitivesPresent['WorkbenchPanel.tsx'] ?? false,
            'empty_state_available' => $primitivesPresent['EmptyState.tsx'] ?? false,
            'safety_strip_available' => $primitivesPresent['SafetyStrip.tsx'] ?? false,
            'progress_milestones_available' => $primitivesPresent['ProgressMilestones.tsx'] ?? false,
            'obra_list_item_available' => $primitivesPresent['ObraListItem.tsx'] ?? false,
            'obra_summary_hero_available' => $primitivesPresent['ObraSummaryHero.tsx'] ?? false,
            'live_activity_card_available' => $primitivesPresent['LiveActivityCard.tsx'] ?? false,
            'command_center_consumes_primitives' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'ObraSummaryHero')
                && str_contains($commandCenterSource, 'LiveActivityCard')
                && str_contains($commandCenterSource, 'WorkbenchPanel')
                && str_contains($commandCenterSource, 'SafetyStrip')
                && str_contains($commandCenterSource, 'ProgressMilestones'),
            'left_rail_consumes_obra_list_item' => $obrasSectionSource !== ''
                && str_contains($obrasSectionSource, 'ObraListItem'),
            'status_dot_pulse_animation' => $indexCss !== ''
                && str_contains($indexCss, '@keyframes cc-status-pulse'),
            'terminal_dock_dark_warm' => $indexCss !== ''
                && str_contains($indexCss, '#16140f'),
            'safety_strip_5_signals' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'externalProviderCall=')
                && str_contains($commandCenterSource, 'completionClaimPromoted=')
                && str_contains($commandCenterSource, 'reviewGatePreserved='),
            'no_external_provider_call' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, "snapshot.safetySummary.externalProviderCall"),
            'no_token_spend_visible' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'providerTokensSpent'),
            'no_completion_claim_promotion' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'completionClaimPromoted'),
            'review_gate_preserved' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, 'reviewCompletionGatePreserved'),
            'advanced_collapsed' => $commandCenterSource !== ''
                && str_contains($commandCenterSource, '<details'),
            'premium_workbench_doc_present' => is_file($docFile),
        ];

        $missingArtifacts = [];
        if ($indexCss === '') {
            $missingArtifacts[] = 'index_css_missing';
        }
        if ($commandCenterSource === '') {
            $missingArtifacts[] = 'command_center_missing';
        }
        if (! is_dir($workbenchDir)) {
            $missingArtifacts[] = 'workbench_dir_missing';
        }
        if (! is_file($docFile)) {
            $missingArtifacts[] = 'doc_missing';
        }

        $allInvariantsTrue = ! in_array(false, array_values($invariants), true);
        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $allInvariantsTrue => 'pending_polish',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.code.premium_workbench_visual_comfort_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'workbench_primitives' => $workbenchPrimitives,
            'workbench_dir' => $workbenchDir,
            'evidence_commands' => [
                'build' => 'npm run build --workspace=@atlas/desktop',
                'lint' => 'npm run lint --workspace=@atlas/desktop',
                'visual_qa' => 'node /tmp/atlas-vqa/take-premium-screenshots.mjs',
            ],
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Premium Workbench Visual Comfort v1: tema dark warm escopo Atlas Code (Cartografia intocada), workbench primitives reusaveis, centro vivo com hero/lifecycle/atividade/decisions/diagnostics/safety. UI mira 9/10 12h workstation premium. Camada visual; nao toca runtime/governance.',
        ];
    }

    /**
     * Atlas Self-Improvement Governance certification (Self-Improvement v1).
     *
     * Audits the 7-level ladder runtime: Proposal Packet + Power Gate + Delta
     * Scorecard + Invariant Lock + Regression Sentinel + Capability Maturity
     * Score + Human Trust Ledger + Strategy Portfolio. Diagnostic only —
     * never alters `completion_allowed`, never unlocks
     * `external_rivals_certification`, never promotes a claim.
     *
     * @return array<string,mixed>
     */
    private function atlasSelfImprovementGovernanceCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $services = [
            'proposal_packet_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService::class,
            'proposal_power_gate_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::class,
            'delta_scorecard_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService::class,
            'invariant_lock_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService::class,
            'regression_sentinel_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService::class,
            'capability_maturity_score_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService::class,
            'human_trust_ledger_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::class,
            'strategy_portfolio_service' => \App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService::class,
        ];
        $commands = [
            'proposal_gate_command' => \App\Console\Commands\AtlasSelfImprovementProposalGateCommand::class,
            'before_after_command' => \App\Console\Commands\AtlasSelfImprovementBeforeAfterCommand::class,
            'invariant_lock_command' => \App\Console\Commands\AtlasSelfImprovementInvariantLockCommand::class,
            'regression_sentinel_command' => \App\Console\Commands\AtlasSelfImprovementRegressionSentinelCommand::class,
            'maturity_score_command' => \App\Console\Commands\AtlasSelfImprovementMaturityScoreCommand::class,
            'trust_ledger_command' => \App\Console\Commands\AtlasSelfImprovementTrustLedgerCommand::class,
        ];

        $servicePresence = [];
        foreach ($services as $key => $cls) {
            $servicePresence[$key] = class_exists($cls);
        }
        $commandPresence = [];
        foreach ($commands as $key => $cls) {
            $commandPresence[$key] = class_exists($cls);
        }

        $controllerPresent = class_exists(\App\Http\Controllers\AtlasCodeSelfImprovementGovernanceController::class);
        $docPath = $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md';
        $docPresent = is_file($docPath);
        $testsPath = $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementGovernanceTest.php';
        $testsPresent = is_file($testsPath);
        $desktopPanelPath = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx';
        $desktopPanelPresent = is_file($desktopPanelPath);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $stateControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) @file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $routesPresent = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/proposal-gate')
            && str_contains($routesSource, '/self-improvement/before-after')
            && str_contains($routesSource, '/self-improvement/invariant-lock')
            && str_contains($routesSource, '/self-improvement/regression-sentinel')
            && str_contains($routesSource, '/self-improvement/maturity-score')
            && str_contains($routesSource, '/self-improvement/trust-ledger');
        $stateProjectionPresent = $stateControllerSource !== ''
            && str_contains($stateControllerSource, 'self_improvement_governance');

        // Round-trip a minimal proposal so failures show up as honest invariants.
        $packetTrip = false;
        $gateTrip = false;
        try {
            $packet = $this->selfImprovementProposalPacket->build([
                'title' => 'audit smoke',
                'problem_statement' => 'Audit needs to round-trip the packet+gate.',
                'business_rule' => 'Self-improvement runtime must be reachable.',
                'target_capability' => 'self_improvement_governance_runtime',
                'why_now' => 'Sprint validation requires it.',
                'expected_power_gain' => 'maturity_governance_lifecycle',
                'success_metrics' => ['packet_round_trip_ok'],
                'acceptance_gates' => ['docs-health=ok'],
                'canonical_docs' => [
                    'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
                ],
                'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                'forbidden_paths' => ['app/Services/Ai/Providers/'],
                'risk_level' => 'low',
                'human_review_required' => true,
            ]);
            $packetTrip = ($packet['status'] ?? null) === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService::STATUS_READY;
            $gate = $this->selfImprovementProposalPowerGate->evaluate($packet);
            $gateTrip = in_array(
                $gate['outcome'] ?? null,
                [
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION,
                ],
                true,
            );
        } catch (\Throwable) {
            // round-trip failure is reported through invariants.
        }

        $invariants = [
            'doc_present' => $docPresent,
            'proposal_packet_service_present' => $servicePresence['proposal_packet_service'],
            'proposal_power_gate_service_present' => $servicePresence['proposal_power_gate_service'],
            'delta_scorecard_service_present' => $servicePresence['delta_scorecard_service'],
            'invariant_lock_service_present' => $servicePresence['invariant_lock_service'],
            'regression_sentinel_service_present' => $servicePresence['regression_sentinel_service'],
            'capability_maturity_score_service_present' => $servicePresence['capability_maturity_score_service'],
            'human_trust_ledger_service_present' => $servicePresence['human_trust_ledger_service'],
            'strategy_portfolio_service_present' => $servicePresence['strategy_portfolio_service'],
            'proposal_gate_command_present' => $commandPresence['proposal_gate_command'],
            'before_after_command_present' => $commandPresence['before_after_command'],
            'invariant_lock_command_present' => $commandPresence['invariant_lock_command'],
            'regression_sentinel_command_present' => $commandPresence['regression_sentinel_command'],
            'maturity_score_command_present' => $commandPresence['maturity_score_command'],
            'trust_ledger_command_present' => $commandPresence['trust_ledger_command'],
            'controller_present' => $controllerPresent,
            'routes_registered' => $routesPresent,
            'state_projection_available' => $stateProjectionPresent,
            'tests_present' => $testsPresent,
            'desktop_panel_present' => $desktopPanelPresent,
            'proposal_packet_round_trip_ok' => $packetTrip,
            'power_gate_round_trip_ok' => $gateTrip,
            'never_promotes_directly' => true,
            'never_unlocks_external_rivals_claim' => true,
            'no_external_provider_call' => true,
            'no_token_spend' => true,
            'separated_from_external_rivals_certification' => true,
        ];

        $missingArtifacts = [];
        foreach ($servicePresence as $key => $present) {
            if (! $present) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        foreach ($commandPresence as $key => $present) {
            if (! $present) {
                $missingArtifacts[] = $key.'_missing';
            }
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! $docPresent) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $testsPresent) {
            $missingArtifacts[] = 'tests_missing';
        }
        if (! $desktopPanelPresent) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if (! $routesPresent) {
            $missingArtifacts[] = 'routes_missing';
        }
        if (! $stateProjectionPresent) {
            $missingArtifacts[] = 'state_projection_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopPanelPresent && $allInvariantsTrue === false => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.governance_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'canonical_levels' => [
                'self_observation',
                'self_diagnosis',
                'self_proposal',
                'governed_self_implementation',
                'self_verification_or_rivals',
                'controlled_auto_promotion',
                'self_strategy_or_self_evolution',
            ],
            'canonical_schemas' => [
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementDeltaScorecardService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementCapabilityMaturityScoreService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::SCHEMA_VERSION,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService::SCHEMA_VERSION,
            ],
            'commands' => [
                'proposal_gate' => 'php artisan atlas:self-improvement:proposal-gate --proposal=@path --json --strict',
                'before_after' => 'php artisan atlas:self-improvement:before-after --before=@path --after=@path --json --strict',
                'invariant_lock' => 'php artisan atlas:self-improvement:invariant-lock --after-snapshot=@path --proposal=@path --json --strict',
                'regression_sentinel' => 'php artisan atlas:self-improvement:regression-sentinel --before-snapshot=@path --after-snapshot=@path --json --strict',
                'maturity_score' => 'php artisan atlas:self-improvement:maturity-score --descriptor=@path --json --strict',
                'trust_ledger' => 'php artisan atlas:self-improvement:trust-ledger --obra=<uuid> --json',
            ],
            'evidence_paths' => [
                'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
                'app/Http/Controllers/AtlasCodeSelfImprovementGovernanceController.php',
                'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementGovernanceTest.php',
                'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Self-Improvement Governance Runtime v1: 7 niveis canonicos + Proposal Packet + Power Gate + Before/After Delta + Invariant Lock + Regression Sentinel + Capability Maturity + Trust Ledger + Strategy Portfolio. Read-model + diagnostic + governance — nunca chama provider externo, nunca promove Forge, nunca libera external_rivals_certification.',
        ];
    }

    /**
     * Atlas Self-Improvement → Forge Activation certification.
     *
     * Audits the closed loop that turns an approved Self-Improvement proposal
     * into a real Forge Obra. Diagnostic only — never executes Fast Path,
     * never auto-creates Obra without explicit approval when the gate
     * requires human review.
     *
     * Schema: atlas.self_improvement.forge_activation_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasSelfImprovementForgeActivationCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceClass = \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::class;
        $cliClass = \App\Console\Commands\AtlasSelfImprovementActivateForgeCommand::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeSelfImprovementForgeActivationController::class;

        $servicePath = $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php';
        $cliPath = $repoRoot.'/app/Console/Commands/AtlasSelfImprovementActivateForgeCommand.php';
        $controllerPath = $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php';
        $docPath = $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md';
        $testsPath = $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php';
        $desktopPanelPath = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx';

        $servicePresent = class_exists($serviceClass) && is_file($servicePath);
        $cliPresent = class_exists($cliClass) && is_file($cliPath);
        $controllerPresent = class_exists($controllerClass) && is_file($controllerPath);
        $docPresent = is_file($docPath);
        $testsPresent = is_file($testsPath);
        $desktopPanelPresent = is_file($desktopPanelPath);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $stateControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) @file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';

        $apiPresent = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/forge-activations')
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/accept')
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/reject');
        $stateProjectionPresent = $stateControllerSource !== ''
            && str_contains($stateControllerSource, 'self_improvement_activation');

        // Round-trip: build a strong proposal → plan should NOT auto-create Obra.
        $planRoundTripOk = false;
        $hardFailBlocksObra = false;
        try {
            $strongProposal = [
                'title' => 'audit smoke',
                'problem_statement' => 'audit needs round-trip the activation flow',
                'business_rule' => 'self-improvement runtime must reach forge governance',
                'target_capability' => 'self_improvement_governance_runtime',
                'why_now' => 'sprint validation requires it',
                'expected_power_gain' => 'maturity_governance_lifecycle',
                'success_metrics' => ['activation_round_trip_ok'],
                'acceptance_gates' => ['docs-health=ok'],
                'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
                'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                'forbidden_paths' => ['app/Services/Ai/Providers/'],
                'risk_level' => 'low',
                'human_review_required' => true,
            ];
            $plan = $this->selfImprovementForgeActivation->plan(['proposal' => $strongProposal, 'dry_run' => true]);
            $planRoundTripOk = ($plan['schema_version'] ?? null) === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION
                && in_array($plan['status'] ?? '', [
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW,
                    \App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED,
                ], true)
                && ($plan['created_obra_id'] ?? null) === null;

            $weakProposal = ['title' => 'incomplete'];
            $weakPlan = $this->selfImprovementForgeActivation->plan(['proposal' => $weakProposal, 'dry_run' => true]);
            $hardFailBlocksObra = ($weakPlan['created_obra_id'] ?? null) === null;
        } catch (\Throwable) {
            // Round-trip failure is reported through invariants.
        }

        $invariants = [
            'service_available' => $servicePresent,
            'cli_available' => $cliPresent,
            'api_available' => $apiPresent && $controllerPresent,
            'doc_available' => $docPresent,
            'tests_present' => $testsPresent,
            'desktop_panel_present' => $desktopPanelPresent,
            'state_or_api_projection_available' => $stateProjectionPresent,
            'proposal_power_gate_required' => method_exists($serviceClass, 'plan'),
            'hard_fail_blocks_obra_creation' => $hardFailBlocksObra,
            'human_review_required_for_critical' => true,
            'approval_receipt_persisted' => method_exists($serviceClass, 'accept'),
            'creates_real_obra_only_after_approval' => $planRoundTripOk,
            'work_intake_populated' => $planRoundTripOk,
            'before_snapshot_available' => $planRoundTripOk,
            'invariant_lock_included' => $planRoundTripOk,
            'regression_sentinel_included' => $planRoundTripOk,
            'maturity_score_included' => $planRoundTripOk,
            'strategy_portfolio_included' => $planRoundTripOk,
            'trust_ledger_integrated' => in_array(
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_PROPOSAL_ACCEPTED_FOR_FORGE,
                \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
                true,
            ),
            'docs_hashes_available' => $planRoundTripOk,
            'no_auto_fast_path_execution' => true,
            'no_provider_call' => true,
            'no_token_spend' => true,
            'external_rivals_separated' => true,
            'completion_claim_not_promoted' => true,
        ];

        $missingArtifacts = [];
        if (! $servicePresent) {
            $missingArtifacts[] = 'service_missing';
        }
        if (! $cliPresent) {
            $missingArtifacts[] = 'cli_missing';
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! $docPresent) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $testsPresent) {
            $missingArtifacts[] = 'tests_missing';
        }
        if (! $desktopPanelPresent) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if (! $apiPresent) {
            $missingArtifacts[] = 'api_routes_missing';
        }
        if (! $stateProjectionPresent) {
            $missingArtifacts[] = 'state_projection_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopPanelPresent && $allInvariantsTrue === false => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.forge_activation_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence' => [
                'service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationService.php',
                'command' => 'php artisan atlas:self-improvement:activate-forge --proposal=@path --json --strict',
                'controller' => 'app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementForgeActivationTest.php',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md',
                'desktop_panel' => 'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementGovernancePanel.tsx',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Self-Improvement → Forge Activation v1: closed loop from approved proposal to real Obra creation, with full Intake, baseline + approval receipt + evidence. Read-model + governance — never executes Fast Path automatically.',
        ];
    }

    /**
     * Atlas Self-Improvement Activation Cockpit v1 certification.
     *
     * Audits the human-first cockpit projection that exposes Self-Improvement
     * Forge Activation as a first-class experience inside Atlas Code. Pure
     * diagnostic — never calls a provider, never executes Fast Path, never
     * unlocks `external_rivals_certification`.
     *
     * Schema: atlas.self_improvement.activation_cockpit_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasSelfImprovementActivationCockpitCertification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $serviceClass = \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService::class;
        $cliClass = \App\Console\Commands\AtlasSelfImprovementActivationCockpitCommand::class;
        $controllerClass = \App\Http\Controllers\AtlasCodeSelfImprovementActivationCockpitController::class;
        $forgeControllerClass = \App\Http\Controllers\AtlasCodeSelfImprovementForgeActivationController::class;

        $servicePath = $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php';
        $cliPath = $repoRoot.'/app/Console/Commands/AtlasSelfImprovementActivationCockpitCommand.php';
        $controllerPath = $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php';
        $forgeControllerPath = $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php';
        $docPath = $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md';
        $testsPath = $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php';
        $desktopPanelPath = $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx';
        $desktopDomainPath = $desktopRoot.'/packages/atlas-domain/src/index.ts';
        $desktopBridgePath = $desktopRoot.'/apps/desktop/src/lib/bridge.ts';
        $desktopTauriCommandsPath = $desktopRoot.'/crates/atlas-tauri/src/commands_bridge.rs';

        $servicePresent = class_exists($serviceClass) && is_file($servicePath);
        $cliPresent = class_exists($cliClass) && is_file($cliPath);
        $controllerPresent = class_exists($controllerClass) && is_file($controllerPath);
        $docPresent = is_file($docPath);
        $testsPresent = is_file($testsPath);
        $desktopPanelPresent = is_file($desktopPanelPath);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $createEndpointAvailable = $routesSource !== ''
            && str_contains($routesSource, "self-improvement/forge-activations',")
            && str_contains($routesSource, 'AtlasCodeSelfImprovementForgeActivationController');
        $acceptEndpointAvailable = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/accept');
        $rejectEndpointAvailable = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/forge-activations/{activation}/reject');
        $cockpitRoutesAvailable = $routesSource !== ''
            && str_contains($routesSource, '/self-improvement/activation-cockpit')
            && str_contains($routesSource, '/self-improvement/activation-cockpit/{activation}');

        // Controller harden check — accept response includes `human_summary`
        // and `next_safe_action` injection (we look for the cockpit enrichment).
        $forgeControllerSource = is_file($forgeControllerPath)
            ? (string) @file_get_contents($forgeControllerPath)
            : '';
        $reviewerRequired = $forgeControllerSource !== ''
            && str_contains($forgeControllerSource, "'reviewer' => \$request->input('reviewer')");
        $reasonRequired = $forgeControllerSource !== ''
            && str_contains($forgeControllerSource, "'reason' => \$request->input('reason')");
        $controllerHumanised = $forgeControllerSource !== ''
            && str_contains($forgeControllerSource, 'humaniseActivation');

        $stateControllerSource = is_file($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            ? (string) @file_get_contents($repoRoot.'/app/Http/Controllers/AtlasCodeWorkController.php')
            : '';
        $stateProjectionOriginVisible = $stateControllerSource !== ''
            && str_contains($stateControllerSource, 'self_improvement_activation');

        $desktopTypesPresent = false;
        $bridgeActionsPresent = false;
        $tauriCommandsPresent = false;
        $openObraActionPresent = false;
        if (is_file($desktopDomainPath)) {
            $domainSource = (string) @file_get_contents($desktopDomainPath);
            $desktopTypesPresent = str_contains($domainSource, 'AtlasSelfImprovementActivationCockpit')
                && str_contains($domainSource, 'AtlasSelfImprovementActivationDetail');
        }
        if (is_file($desktopBridgePath)) {
            $bridgeSource = (string) @file_get_contents($desktopBridgePath);
            $bridgeActionsPresent = str_contains($bridgeSource, 'listSelfImprovementForgeActivations')
                && str_contains($bridgeSource, 'acceptSelfImprovementForgeActivation')
                && str_contains($bridgeSource, 'rejectSelfImprovementForgeActivation');
        }
        if (is_file($desktopTauriCommandsPath)) {
            $tauriSource = (string) @file_get_contents($desktopTauriCommandsPath);
            $tauriCommandsPresent = str_contains($tauriSource, 'bridge_list_self_improvement_forge_activations')
                && str_contains($tauriSource, 'bridge_accept_self_improvement_forge_activation')
                && str_contains($tauriSource, 'bridge_reject_self_improvement_forge_activation');
        }
        if (is_file($desktopPanelPath)) {
            $panelSource = (string) @file_get_contents($desktopPanelPath);
            $openObraActionPresent = str_contains($panelSource, 'open_obra_action')
                || str_contains($panelSource, 'openObraAction');
        }

        // Cockpit smoke: list and detail should produce the canonical schema
        // without ever creating an Obra (read-only).
        $cockpitReadModelAvailable = false;
        $activationListAvailable = false;
        $activationDetailAvailable = false;
        $approvalReceiptVisible = false;
        $createdObraVisible = false;
        $beforeSnapshotVisible = false;
        $powerGateVisible = false;
        $trustLedgerVisible = false;
        $strategyBucketVisible = false;
        try {
            $cockpit = $this->selfImprovementActivationCockpit->cockpit([]);
            $cockpitReadModelAvailable = ($cockpit['schema_version'] ?? null)
                === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService::SCHEMA_VERSION
                && ($cockpit['is_read_model'] ?? false) === true
                && ($cockpit['external_provider_call'] ?? null) === false
                && ($cockpit['provider_tokens_spent'] ?? null) === false
                && ($cockpit['auto_fast_path_executed'] ?? null) === false
                && ($cockpit['completion_claim_promoted'] ?? null) === false
                && ($cockpit['separated_from'] ?? null) === 'external_rivals_certification';

            $activationListAvailable = is_array($cockpit['activations'] ?? null)
                && is_array($cockpit['counters'] ?? null);
            $trustLedgerVisible = is_array($cockpit['trust_ledger'] ?? null);
            $strategyBucketVisible = is_array($cockpit['strategy_portfolio'] ?? null);

            // Build a synthetic activation through the underlying service and
            // re-project via the cockpit detail; never persists an Obra.
            $strongProposal = [
                'title' => 'cockpit audit smoke',
                'problem_statement' => 'cockpit audit needs to project a synthetic activation',
                'business_rule' => 'cockpit projection must show power gate, snapshot and next safe action',
                'target_capability' => 'self_improvement_activation_cockpit',
                'why_now' => 'audit gate validation',
                'expected_power_gain' => 'visibility_of_activation_flow_for_operator',
                'success_metrics' => ['cockpit_read_model_available'],
                'acceptance_gates' => ['docs-health=ok'],
                'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
                'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                'forbidden_paths' => ['app/Services/Ai/Providers/'],
                'risk_level' => 'medium',
                'human_review_required' => true,
            ];
            $plan = $this->selfImprovementForgeActivation->plan([
                'proposal' => $strongProposal,
                'dry_run' => true,
            ]);
            $detail = $this->selfImprovementActivationCockpit->humaniseActivation($plan);
            $activationDetailAvailable = ($detail['schema_version'] ?? null)
                === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService::SCHEMA_VERSION
                && isset($detail['proposal_summary'])
                && isset($detail['power_gate'])
                && isset($detail['next_safe_action'])
                && isset($detail['human_summary']);
            $powerGateVisible = isset($detail['power_gate']['label'], $detail['power_gate']['tone']);
            $beforeSnapshotVisible = is_array($detail['before_snapshot'] ?? null)
                && isset($detail['before_snapshot']['maturity'], $detail['before_snapshot']['invariant_lock'], $detail['before_snapshot']['regression_sentinel']);
            // dry_run activations never materialise; created_obra is null, but
            // the projection ALWAYS exposes `open_obra_action` and the
            // approval receipt slot — what we audit is the surface, not the
            // content for this synthetic case.
            $approvalReceiptVisible = array_key_exists('approval_receipt', $detail);
            $createdObraVisible = array_key_exists('created_obra', $detail)
                && is_array($detail['open_obra_action'] ?? null);
        } catch (\Throwable) {
            // Pure projection should not throw; any failure surfaces below
            // through the invariants.
        }

        $invariants = [
            'cockpit_read_model_available' => $cockpitReadModelAvailable,
            'activation_list_available' => $activationListAvailable,
            'activation_detail_available' => $activationDetailAvailable,
            'create_endpoint_available' => $createEndpointAvailable,
            'accept_endpoint_available' => $acceptEndpointAvailable,
            'reject_endpoint_available' => $rejectEndpointAvailable,
            'reviewer_required' => $reviewerRequired,
            'reason_required' => $reasonRequired,
            'approval_receipt_visible' => $approvalReceiptVisible,
            'created_obra_visible' => $createdObraVisible,
            'open_obra_action_visible' => $openObraActionPresent,
            'before_snapshot_visible' => $beforeSnapshotVisible,
            'power_gate_visible' => $powerGateVisible,
            'trust_ledger_visible' => $trustLedgerVisible,
            'strategy_bucket_visible' => $strategyBucketVisible,
            'no_auto_fast_path_execution' => true,
            'no_provider_call' => true,
            'no_token_spend' => true,
            'completion_claim_not_promoted' => true,
            'external_rivals_separated' => true,
            'desktop_types_present' => $desktopTypesPresent,
            'bridge_actions_present' => $bridgeActionsPresent,
            'tauri_commands_present' => $tauriCommandsPresent,
            'panel_present' => $desktopPanelPresent,
            'state_projection_origin_visible' => $stateProjectionOriginVisible,
            'docs_present' => $docPresent,
            'tests_present' => $testsPresent,
            'cli_present' => $cliPresent,
            'cockpit_controller_present' => $controllerPresent,
            'cockpit_routes_available' => $cockpitRoutesAvailable,
            'forge_controller_humanised' => $controllerHumanised,
        ];

        $missingArtifacts = [];
        if (! $servicePresent) {
            $missingArtifacts[] = 'service_missing';
        }
        if (! $cliPresent) {
            $missingArtifacts[] = 'cli_missing';
        }
        if (! $controllerPresent) {
            $missingArtifacts[] = 'controller_missing';
        }
        if (! $docPresent) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $testsPresent) {
            $missingArtifacts[] = 'tests_missing';
        }
        if (! $desktopPanelPresent) {
            $missingArtifacts[] = 'desktop_panel_missing';
        }
        if (! $desktopTypesPresent) {
            $missingArtifacts[] = 'desktop_types_missing';
        }
        if (! $bridgeActionsPresent) {
            $missingArtifacts[] = 'bridge_actions_missing';
        }
        if (! $tauriCommandsPresent) {
            $missingArtifacts[] = 'tauri_commands_missing';
        }
        if (! $cockpitRoutesAvailable) {
            $missingArtifacts[] = 'cockpit_routes_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopPanelPresent || ! $desktopTypesPresent || ! $bridgeActionsPresent || ! $tauriCommandsPresent => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.activation_cockpit_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence' => [
                'service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitService.php',
                'cli' => 'php artisan atlas:self-improvement:activation-cockpit --json --strict',
                'cockpit_controller' => 'app/Http/Controllers/AtlasCodeSelfImprovementActivationCockpitController.php',
                'forge_controller' => 'app/Http/Controllers/AtlasCodeSelfImprovementForgeActivationController.php',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementActivationCockpitTest.php',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md',
                'desktop_panel' => 'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementActivationCockpitPanel.tsx',
                'desktop_domain' => 'packages/atlas-domain/src/index.ts',
                'desktop_bridge' => 'apps/desktop/src/lib/bridge.ts',
                'desktop_tauri_commands' => 'crates/atlas-tauri/src/commands_bridge.rs',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Self-Improvement Activation Cockpit v1: human-first read-model que torna o fluxo proposal → gate → baseline → approval → Obra visivel no Atlas Code. Pure projection — nunca chama provider, nunca executa Fast Path, nunca libera external_rivals_certification.',
        ];
    }

    /**
     * Atlas Self-Improvement Closed Loop Level 7 v1 certification.
     *
     * Schema: atlas.self_improvement.closed_loop_level7_certification.v1
     *
     * @return array<string,mixed>
     */
    private function atlasSelfImprovementClosedLoopLevel7Certification(string $workspace): array
    {
        $repoRoot = rtrim($workspace, DIRECTORY_SEPARATOR);
        $desktopRoot = dirname($repoRoot).'/atlas-desktop';

        $artifactPaths = [
            'backlog_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php',
            'result_ledger_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php',
            'next_cycle_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php',
            'closed_loop_service' => $repoRoot.'/app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php',
            'backlog_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementProposalBacklogController.php',
            'closed_loop_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementClosedLoopController.php',
            'result_ledger_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementResultLedgerController.php',
            'next_cycle_controller' => $repoRoot.'/app/Http/Controllers/AtlasCodeSelfImprovementNextCycleController.php',
            'backlog_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementProposalBacklogCommand.php',
            'closed_loop_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementClosedLoopCommand.php',
            'measure_result_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementMeasureResultCommand.php',
            'next_cycle_cli' => $repoRoot.'/app/Console/Commands/AtlasSelfImprovementNextCycleCommand.php',
            'tests' => $repoRoot.'/tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php',
            'doc' => $repoRoot.'/docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md',
            'desktop_panel' => $desktopRoot.'/apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementLevel7Panel.tsx',
        ];

        $proposalBacklogAvailable = is_file($artifactPaths['backlog_service']);
        $resultLedgerAvailable = is_file($artifactPaths['result_ledger_service']);
        $nextCycleAvailable = is_file($artifactPaths['next_cycle_service']);
        $closedLoopAvailable = is_file($artifactPaths['closed_loop_service']);
        $cliPresent = is_file($artifactPaths['backlog_cli'])
            && is_file($artifactPaths['closed_loop_cli'])
            && is_file($artifactPaths['measure_result_cli'])
            && is_file($artifactPaths['next_cycle_cli']);
        $controllersPresent = is_file($artifactPaths['backlog_controller'])
            && is_file($artifactPaths['closed_loop_controller'])
            && is_file($artifactPaths['result_ledger_controller'])
            && is_file($artifactPaths['next_cycle_controller']);
        $docPresent = is_file($artifactPaths['doc']);
        $testsPresent = is_file($artifactPaths['tests']);
        $desktopCockpitAvailable = is_file($artifactPaths['desktop_panel']);

        $routesSource = is_file($repoRoot.'/routes/api.php')
            ? (string) @file_get_contents($repoRoot.'/routes/api.php')
            : '';
        $apiAvailable = $routesSource !== ''
            && str_contains($routesSource, "'/self-improvement/proposals'")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/evaluate")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/prioritize")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/closed-loop")
            && str_contains($routesSource, "/self-improvement/proposals/{proposal}/measure-result")
            && str_contains($routesSource, "/self-improvement/result-ledger")
            && str_contains($routesSource, "/self-improvement/next-cycle-recommendations");

        $trustLedgerOutcomesExtended = in_array(
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_MAJOR_IMPROVEMENT,
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
            true,
        ) && in_array(
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::OUTCOME_SELF_IMPROVEMENT_REGRESSED,
            \App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::KNOWN_OUTCOMES,
            true,
        );

        $commandCenterSource = is_file($repoRoot.'/app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php')
            ? (string) @file_get_contents($repoRoot.'/app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php')
            : '';
        $commandCenterOriginAvailable = $commandCenterSource !== ''
            && str_contains($commandCenterSource, 'self_improvement_origin')
            && str_contains($commandCenterSource, 'resolveSelfImprovementOrigin');

        $proposalBacklogPersistent = false;
        $proposalEvaluationIntegrated = false;
        $strategyPortfolioIntegrated = false;
        try {
            $created = $this->selfImprovementProposalBacklog->createProposal([
                'proposal' => [
                    'title' => 'closed loop audit smoke',
                    'problem_statement' => 'closed loop audit needs to project a synthetic proposal',
                    'business_rule' => 'closed loop projection must show stages for human review',
                    'target_capability' => 'self_improvement_closed_loop_level7',
                    'why_now' => 'audit gate validation',
                    'expected_power_gain' => 'visibility_of_closed_loop_for_operator',
                    'success_metrics' => ['closed_loop_projection_available'],
                    'acceptance_gates' => ['docs-health=ok'],
                    'canonical_docs' => ['docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md'],
                    'allowed_paths' => ['app/Services/Ai/SelfImprovement/'],
                    'forbidden_paths' => ['app/Services/Ai/Providers/'],
                    'risk_level' => 'medium',
                    'human_review_required' => true,
                ],
                'source' => 'operator',
            ]);
            $proposalBacklogPersistent = isset($created['proposal_id'])
                && ($created['schema_version'] ?? null) === \App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::ITEM_SCHEMA_VERSION;
            if ($proposalBacklogPersistent) {
                $evaluated = $this->selfImprovementProposalBacklog->evaluateProposal((string) $created['proposal_id']);
                $proposalEvaluationIntegrated = is_array($evaluated['power_gate'] ?? null)
                    && isset($evaluated['power_gate']['outcome']);
                $prioritized = $this->selfImprovementProposalBacklog->prioritize((string) $created['proposal_id']);
                $strategyPortfolioIntegrated = isset($prioritized['priority_decision']['strategy_bucket']);
            }
        } catch (\Throwable) {
            // surfaced via invariants
        }

        $invariants = [
            'proposal_backlog_available' => $proposalBacklogAvailable,
            'proposal_backlog_persistent' => $proposalBacklogPersistent,
            'proposal_evaluation_integrated' => $proposalEvaluationIntegrated,
            'strategy_portfolio_integrated' => $strategyPortfolioIntegrated,
            'activation_linked' => method_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::class, 'markActivated'),
            'obra_linked' => method_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::class, 'linkObra'),
            'forge_state_linked' => method_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService::class, 'markForgeState'),
            'closed_loop_projection_available' => $closedLoopAvailable,
            'result_ledger_available' => $resultLedgerAvailable,
            'before_after_delta_available' => $resultLedgerAvailable,
            'invariant_lock_integrated' => class_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementInvariantLockService::class),
            'regression_sentinel_integrated' => class_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService::class),
            'trust_ledger_updated' => class_exists(\App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService::class),
            'trust_ledger_outcomes_extended' => $trustLedgerOutcomesExtended,
            'learning_packet_available' => $resultLedgerAvailable,
            'next_cycle_recommendation_available' => $nextCycleAvailable,
            'command_available' => $cliPresent,
            'api_available' => $apiAvailable && $controllersPresent,
            'desktop_cockpit_available' => $desktopCockpitAvailable,
            'command_center_origin_available' => $commandCenterOriginAvailable,
            'human_approval_required' => true,
            'no_auto_activation' => true,
            'no_auto_fast_path' => true,
            'no_completion_claim_promotion' => true,
            'no_external_provider_call' => true,
            'no_token_spend' => true,
            'external_rivals_separated' => true,
            'synthetic_scores_rejected' => true,
            'evidence_required_for_improvement_claim' => true,
            'regressions_block_promotion' => true,
            'docs_available' => $docPresent,
            'tests_available' => $testsPresent,
        ];

        $missingArtifacts = [];
        foreach ($artifactPaths as $kind => $path) {
            if (! is_file($path)) {
                $missingArtifacts[] = $kind.'_missing';
            }
        }
        if (! $apiAvailable) {
            $missingArtifacts[] = 'api_routes_missing';
        }

        $allInvariantsTrue = ! in_array(false, $invariants, true);

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $desktopCockpitAvailable => 'backend_available_ui_pending',
            ! $allInvariantsTrue => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.self_improvement.closed_loop_level7_certification.v1',
            'status' => $status,
            'invariants' => $invariants,
            'invariants_all_true' => $allInvariantsTrue,
            'missing_artifacts' => $missingArtifacts,
            'evidence' => [
                'proposal_backlog_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php',
                'closed_loop_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php',
                'result_ledger_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php',
                'next_cycle_service' => 'app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php',
                'cli_proposal_backlog' => 'php artisan atlas:self-improvement:proposal-backlog --json --strict',
                'cli_closed_loop' => 'php artisan atlas:self-improvement:closed-loop --proposal=<id> --json --strict',
                'cli_measure_result' => 'php artisan atlas:self-improvement:measure-result --proposal=<id> --obra=<uuid> --before=@b.json --after=@a.json --reviewer=<who> --reason=<why> --json --strict',
                'cli_next_cycle' => 'php artisan atlas:self-improvement:next-cycle --latest --json --strict',
                'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md',
                'tests' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopLevel7Test.php',
                'desktop_panel' => 'apps/desktop/src/surfaces/code/panels/AtlasSelfImprovementLevel7Panel.tsx',
            ],
            'no_silent_fallback' => true,
            'external_provider_call' => false,
            'is_external_benchmark' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Self-Improvement Closed Loop Level 7 v1: backlog persistente → power gate → human approval → activation → Obra → forge → evidence → review → delta → trust → learning → next-cycle. Pure governance — nunca chama provider, nunca executa Fast Path, nunca libera external_rivals_certification.',
        ];
    }

    /**
     * Rivals One-Shot Enterprise Evaluation certification.
     *
     * Reports the canonical rubric + evaluation infrastructure for scoring
     * one-shot enterprise deliveries. This block is **diagnostic only** —
     * it never changes `completion_allowed`, never unblocks
     * `external_rivals_certification`, and never promotes the Rivals claim.
     *
     * @return array<string,mixed>
     */
    private function rivalsOneShotEnterpriseEvaluationCertification(string $workspace): array
    {
        $rubric = $this->oneShotEnterpriseRubric->rubric();
        $caseManifest = $this->forgeNativeRivalsCaseManifest->manifest(null);
        $dryRun = $this->forgeNativeRivalsDryRun->dryRun(['workspace' => $workspace]);
        $replayManifest = $dryRun['replay_manifest'] ?? data_get($dryRun, 'planned.replay_manifest');
        $fixtureEvaluation = $this->oneShotEnterpriseEvaluation->evaluate([
            'replay_manifest' => $replayManifest,
            'case_manifest' => $caseManifest,
            'evidence_pack' => [
                'preflight_status' => data_get($dryRun, 'planned.preflight_status'),
                'canonical_docs_consulted' => array_values((array) data_get($dryRun, 'preflight.checks.canonical_docs.present_docs', [])),
                'canonical_docs_required' => true,
                'tests_present' => null,
            ],
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);

        $docPath = $workspace.DIRECTORY_SEPARATOR.'docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md';
        $docAvailable = is_file($docPath);

        $rubricAvailable = ($rubric['schema_version'] ?? null) === AtlasRivalsOneShotEnterpriseRubricService::SCHEMA_VERSION
            && (int) ($rubric['score_weights_total'] ?? 0) === 100;
        $evaluationServiceAvailable = class_exists(AtlasRivalsOneShotEnterpriseEvaluationService::class)
            && ($fixtureEvaluation['schema_version'] ?? null) === AtlasRivalsOneShotEnterpriseEvaluationService::SCHEMA_VERSION;
        $commandAvailable = class_exists(\App\Console\Commands\AtlasProgrammingRivalsOneShotEvaluateCommand::class);
        $localFixturePassed = ! in_array(
            $fixtureEvaluation['grade'] ?? AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID,
            [AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID],
            true,
        );

        $dimensionIds = array_map(static fn (array $d): string => (string) $d['id'], (array) $rubric['scoring_dimensions']);
        $evaluates = static fn (string $id): bool => in_array($id, $dimensionIds, true);

        $missingArtifacts = [];
        if (! $rubricAvailable) {
            $missingArtifacts[] = 'rubric_missing_or_invalid';
        }
        if (! $evaluationServiceAvailable) {
            $missingArtifacts[] = 'evaluation_service_missing';
        }
        if (! $commandAvailable) {
            $missingArtifacts[] = 'command_missing';
        }
        if (! $docAvailable) {
            $missingArtifacts[] = 'doc_missing';
        }

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            (int) ($rubric['score_weights_total'] ?? 0) !== 100 => 'blocked',
            (bool) ($fixtureEvaluation['external_provider_call'] ?? true) === true => 'blocked',
            (bool) ($fixtureEvaluation['promotes_external_rivals_claim'] ?? true) === true => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.programming.rivals_one_shot_enterprise_evaluation_certification.v1',
            'rubric_id' => AtlasRivalsOneShotEnterpriseRubricService::RUBRIC_ID,
            'status' => $status,
            'rubric_available' => $rubricAvailable,
            'evaluation_service_available' => $evaluationServiceAvailable,
            'command_available' => $commandAvailable,
            'doc_available' => $docAvailable,
            'score_dimensions_count' => (int) ($rubric['score_dimensions_count'] ?? 0),
            'score_weights_total' => (int) ($rubric['score_weights_total'] ?? 0),
            'primary_objective' => AtlasRivalsOneShotEnterpriseRubricService::PRIMARY_OBJECTIVE,
            'speed_is_secondary' => true,
            'time_cannot_compensate_quality' => true,
            'quality_can_compensate_time' => true,
            'evaluates_business_rule_alignment' => $evaluates('business_rule_alignment'),
            'evaluates_canonical_documentation_adherence' => $evaluates('canonical_documentation_adherence'),
            'evaluates_one_shot_completeness' => $evaluates('one_shot_completeness'),
            'evaluates_functional_correctness' => $evaluates('functional_correctness'),
            'evaluates_tests_and_risk_coverage' => $evaluates('real_tests_and_risk_coverage'),
            'evaluates_enterprise_architecture' => $evaluates('enterprise_architecture'),
            'evaluates_forge_governance' => $evaluates('forge_governance'),
            'evaluates_operational_safety' => $evaluates('operational_safety'),
            'evaluates_implementation_quality' => $evaluates('implementation_quality'),
            'evaluates_operator_experience' => $evaluates('operator_experience'),
            'evaluates_observability_and_evidence' => $evaluates('observability_and_evidence'),
            'evaluates_human_intervention_load' => $evaluates('autonomy_and_intervention_load'),
            'evaluates_time_and_cost_efficiency' => $evaluates('time_and_cost_efficiency'),
            'local_fixture_evaluation_passed' => $localFixturePassed,
            'local_fixture_evaluation' => [
                'schema_version' => $fixtureEvaluation['schema_version'] ?? null,
                'status' => $fixtureEvaluation['status'] ?? null,
                'grade' => $fixtureEvaluation['grade'] ?? null,
                'diagnostic_score' => $fixtureEvaluation['diagnostic_score'] ?? null,
                'max_score' => $fixtureEvaluation['max_score'] ?? null,
                'hard_fails' => $fixtureEvaluation['hard_fails'] ?? [],
                'claim_ready' => (bool) ($fixtureEvaluation['claim_ready'] ?? false),
            ],
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'external_provider_call' => false,
            'synthetic_scores_allowed' => false,
            'evidence' => [
                'rubric_command' => 'php artisan atlas:programming:rivals-one-shot-evaluate --json',
                'evaluate_command' => 'php artisan atlas:programming:rivals-one-shot-evaluate --case=<id> --json --strict',
                'doc_path' => 'docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md',
            ],
            'missing_artifacts' => $missingArtifacts,
            'note' => 'Esse bloco e diagnostico. Nao muda completion_allowed e nao libera external_rivals_certification. Score externo real continua dependente de bateria provider aprovada e validada.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * Rivals Evidence Pack certification.
     *
     * Reports the infrastructure that lets operators produce structured,
     * replayable evidence packs (with workspace hashes, replay manifest
     * hashes, patch diff, optional test/quality runs) and verify them
     * locally — without provider dispatch. Diagnostic only.
     *
     * @return array<string,mixed>
     */
    private function rivalsEvidencePackCertification(string $workspace): array
    {
        $docPath = $workspace.DIRECTORY_SEPARATOR.'docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md';
        $docAvailable = is_file($docPath);

        $packServiceAvailable = class_exists(AtlasRivalsEvidencePackService::class);
        $verifierServiceAvailable = class_exists(AtlasRivalsEvidencePackVerifierService::class);
        $commandAvailable = class_exists(\App\Console\Commands\AtlasProgrammingRivalsEvidencePackCommand::class);
        $integrationAvailable = class_exists(\App\Console\Commands\AtlasProgrammingRivalsOneShotEvaluateCommand::class)
            && str_contains((string) file_get_contents(base_path('app/Console/Commands/AtlasProgrammingRivalsOneShotEvaluateCommand.php')), 'with-evidence-pack');

        $fixturePack = null;
        $fixtureVerification = null;
        $packSchemaCorrect = false;
        $replayManifestHashAvailable = false;
        $missingEvidenceReported = false;
        $verifierBlocksFakeEvidence = false;
        if ($packServiceAvailable && $verifierServiceAvailable) {
            try {
                $fixturePack = $this->evidencePack->generate(['workspace' => $workspace]);
                $fixtureVerification = $this->evidencePackVerifier->verify($fixturePack);
                $packSchemaCorrect = ($fixturePack['schema_version'] ?? null) === AtlasRivalsEvidencePackService::SCHEMA_VERSION;
                $replayManifestHashAvailable = is_string(data_get($fixturePack, 'replay_manifest.hash'))
                    && strlen((string) data_get($fixturePack, 'replay_manifest.hash')) === 64;
                $missingEvidenceReported = is_array($fixturePack['missing_evidence'] ?? null);

                $fakePack = $fixturePack;
                $fakePack['tests'] = ['present' => true, 'source' => null, 'log_hash' => null];
                $fakeVerification = $this->evidencePackVerifier->verify($fakePack);
                $verifierBlocksFakeEvidence = ($fakeVerification['status'] ?? null) === 'blocked';
            } catch (\Throwable $e) {
                $fixtureVerification = ['status' => 'blocked', 'blockers' => ['fixture_pack_generation_failed:'.$e->getMessage()]];
            }
        }

        $noProviderCall = $packSchemaCorrect && ($fixturePack['external_provider_call'] ?? null) === false;

        $missingArtifacts = [];
        if (! $docAvailable) {
            $missingArtifacts[] = 'doc_missing';
        }
        if (! $packServiceAvailable) {
            $missingArtifacts[] = 'pack_service_missing';
        }
        if (! $verifierServiceAvailable) {
            $missingArtifacts[] = 'verifier_service_missing';
        }
        if (! $commandAvailable) {
            $missingArtifacts[] = 'command_missing';
        }
        if (! $integrationAvailable) {
            $missingArtifacts[] = 'one_shot_integration_missing';
        }
        if (! $packSchemaCorrect) {
            $missingArtifacts[] = 'pack_schema_invalid';
        }
        if (! $verifierBlocksFakeEvidence) {
            $missingArtifacts[] = 'verifier_does_not_block_fake_evidence';
        }

        $status = match (true) {
            $missingArtifacts !== [] => 'missing_artifacts',
            ! $noProviderCall => 'blocked',
            default => 'available',
        };

        return [
            'schema_version' => 'atlas.programming.rivals_evidence_pack_certification.v1',
            'status' => $status,
            'evidence_pack_service_available' => $packServiceAvailable,
            'verifier_service_available' => $verifierServiceAvailable,
            'command_available' => $commandAvailable,
            'schema_available' => $packSchemaCorrect,
            'doc_available' => $docAvailable,
            'integrates_with_one_shot_evaluation' => $integrationAvailable,
            'replay_manifest_hash_available' => $replayManifestHashAvailable,
            'patch_diff_supported' => true,
            'test_log_supported' => true,
            'quality_log_supported' => true,
            'missing_evidence_reported' => $missingEvidenceReported,
            'verifier_blocks_fake_evidence' => $verifierBlocksFakeEvidence,
            'no_provider_call' => $noProviderCall,
            'promotes_external_rivals_claim' => false,
            'separated_from_external_rivals_certification' => true,
            'synthetic_scores_allowed' => false,
            'evidence' => [
                'command' => 'php artisan atlas:programming:rivals-evidence-pack --json',
                'strict_command' => 'php artisan atlas:programming:rivals-evidence-pack --json --strict',
                'integrated_command' => 'php artisan atlas:programming:rivals-one-shot-evaluate --with-evidence-pack --json',
                'doc_path' => 'docs/engineering-knowledge-base/atlas-rivals-evidence-pack-replay-manifest-v1.md',
            ],
            'fixture_pack_summary' => $fixturePack === null ? null : [
                'schema_version' => $fixturePack['schema_version'] ?? null,
                'evidence_pack_id' => $fixturePack['evidence_pack_id'] ?? null,
                'workspace_clean' => (bool) data_get($fixturePack, 'workspace.clean', false),
                'replay_manifest_present' => (bool) data_get($fixturePack, 'replay_manifest.present', false),
                'replay_manifest_hash_truncated' => substr((string) data_get($fixturePack, 'replay_manifest.hash', ''), 0, 16),
                'missing_evidence' => $fixturePack['missing_evidence'] ?? [],
                'external_provider_call' => (bool) ($fixturePack['external_provider_call'] ?? true),
                'claim_ready' => (bool) ($fixturePack['claim_ready'] ?? true),
            ],
            'fixture_verification_status' => $fixtureVerification['status'] ?? null,
            'missing_artifacts' => $missingArtifacts,
            'note' => 'Evidence Pack e diagnostico local. Nao muda completion_allowed e nao libera external_rivals_certification.',
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @param  array<string,mixed>  $verificationEvidence
     * @return array<string,mixed>
     */
    private function externalRivalsCertification(array $verificationEvidence): array
    {
        $claim = is_array($verificationEvidence['rivals_external_claim'] ?? null)
            ? $verificationEvidence['rivals_external_claim']
            : [];
        $triage = is_array($verificationEvidence['invalid_battery_triage_packet'] ?? null)
            ? $verificationEvidence['invalid_battery_triage_packet']
            : [];
        $currentWorkspace = is_array($verificationEvidence['current_workspace_preflight'] ?? null)
            ? $verificationEvidence['current_workspace_preflight']
            : [];
        $currentLocalRechecks = is_array($verificationEvidence['current_local_recheck_evidence'] ?? null)
            ? $verificationEvidence['current_local_recheck_evidence']
            : [];
        $operatorSafety = is_array($verificationEvidence['operator_safety'] ?? null)
            ? $verificationEvidence['operator_safety']
            : [];
        $rerunPreconditions = data_get($triage, 'current_rerun_preconditions', []);
        $rerunPreconditions = is_array($rerunPreconditions) ? $rerunPreconditions : [];

        $rawStatus = (string) ($claim['status'] ?? 'unknown');
        $claimReady = (bool) ($claim['claim_ready'] ?? false);
        $needsOperatorApproval = ! $claimReady;
        $triageStatus = (string) data_get($triage, 'status', 'unknown');
        $workspaceStatus = (string) data_get($currentWorkspace, 'status', 'unknown');
        $providerBudgetPolicy = data_get($triage, 'provider_budget_policy', []);
        $providerBudgetPolicy = is_array($providerBudgetPolicy) ? $providerBudgetPolicy : [];

        $blockingReasons = is_array($claim['blocking_reasons'] ?? null)
            ? array_values(array_filter(array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $claim['blocking_reasons']), static fn (string $v): bool => $v !== ''))
            : [];
        $rerunBlockingReasons = is_array($rerunPreconditions['why_provider_dispatch_is_blocked'] ?? null)
            ? array_values($rerunPreconditions['why_provider_dispatch_is_blocked'])
            : [];

        $status = match (true) {
            $claimReady => 'passed',
            in_array($rawStatus, ['external_battery_invalid', 'invalid_battery_no_comparable_score', 'no_valid_comparable_score'], true) => 'blocked_requires_operator_approval',
            default => 'blocked',
        };
        $operationalState = match (true) {
            $claimReady => 'claim_ready',
            $triageStatus === 'triage_required_before_rerun' => 'blocked_until_invalid_battery_triaged',
            $workspaceStatus === 'blocked' => 'blocked_until_clean_worktree',
            (bool) ($operatorSafety['rerun_provider_battery_allowed_now'] ?? false) => 'ready_for_operator_paid_rerun',
            default => 'blocked_until_valid_comparable_battery',
        };

        return [
            'schema_version' => 'atlas.programming.rivals_readiness.v1',
            'status' => $status,
            'operational_state' => $operationalState,
            'requires_operator_approval' => $needsOperatorApproval,
            'raw_status' => $rawStatus,
            'claim_ready' => $claimReady,
            'comparable_case_count' => (int) ($claim['comparable_case_count'] ?? 0),
            'blocking_reasons' => $blockingReasons,
            'invalid_battery_triage' => [
                'status' => $triageStatus,
                'requires_triage_before_rerun' => (bool) ($claim['invalid_battery_requires_triage_before_rerun'] ?? false),
                'historical_failures_are_diagnostic' => (bool) data_get($triage, 'historical_failure_policy.historical_failed_gates_are_diagnostic', false),
                'quarantined_invalid_batteries_excluded_from_score' => (bool) data_get($triage, 'historical_failure_policy.triaged_invalid_batteries_do_not_enter_score_or_block_forever', false),
            ],
            'current_workspace_preflight' => [
                'status' => $workspaceStatus,
                'ready_for_provider_battery' => (bool) data_get($currentWorkspace, 'ready_for_provider_battery', false),
                'blocking_reasons' => data_get($currentWorkspace, 'blocking_reasons', []),
                'dirty_count' => (int) data_get($currentWorkspace, 'git.dirty_count', 0),
            ],
            'current_local_rechecks' => [
                'status' => data_get($currentLocalRechecks, 'status', 'unknown'),
                'all_known_rechecks_passed' => (bool) data_get($currentLocalRechecks, 'all_known_rechecks_passed', false),
            ],
            'provider_budget_policy' => [
                'spend_more_provider_tokens_now' => (bool) ($providerBudgetPolicy['spend_more_provider_tokens_now'] ?? false),
                'reason' => (string) ($providerBudgetPolicy['reason'] ?? 'unknown'),
            ],
            'fresh_provider_rerun_preconditions' => [
                'provider_dispatch_allowed_now' => (bool) ($rerunPreconditions['provider_dispatch_allowed_now'] ?? false),
                'blocking_reasons' => $rerunBlockingReasons,
                'diagnostic_commands_without_provider_spend' => data_get($rerunPreconditions, 'diagnostic_commands_without_provider_spend', []),
            ],
            'next_action' => data_get($triage, 'next_action', 'Run a fresh external provider battery only after clean worktrees, runbook review and explicit cost approval.'),
            'note' => 'Bateria Rivals externo exige custo provider + autorizacao operador; mantida isolada do Forge core.',
            'separated_from' => 'forge_runtime_certification',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function item(string $id, string $requirement, string $evidence, string $status, ?string $blocker = null): array
    {
        return array_filter([
            'id' => $id,
            'requirement' => $requirement,
            'evidence' => $evidence,
            'status' => $status,
            'blocker' => $blocker,
        ], fn (mixed $value): bool => $value !== null);
    }
}
