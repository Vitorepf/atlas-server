<?php

namespace App\Services\Ai\Programming;

use App\Services\Ai\Programming\CompletionAudit\AtlasAudit;
use App\Services\Ai\Programming\CompletionAudit\CompletionAuditSupport;
use App\Services\Ai\Programming\CompletionAudit\ForgeAudit;
use App\Services\Ai\Programming\CompletionAudit\RivalsAudit;
use App\Services\Ai\Programming\Support\CompletionAuditPowerScorecardSupport;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementActivationCockpitService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPacketService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalPowerGateService;

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
        private readonly AtlasForgeProviderFallbackPolicyService $forgeProviderFallbackPolicy,
        private readonly AtlasForgeProviderInvocationDriverRouter $forgeProviderInvocationDriverRouter,
        private readonly AtlasSelfImprovementProposalPacketService $selfImprovementProposalPacket,
        private readonly AtlasSelfImprovementProposalPowerGateService $selfImprovementProposalPowerGate,
        private readonly AtlasSelfImprovementForgeActivationService $selfImprovementForgeActivation,
        private readonly AtlasSelfImprovementActivationCockpitService $selfImprovementActivationCockpit,
        private readonly AtlasSelfImprovementProposalBacklogService $selfImprovementProposalBacklog,
        private readonly CompletionAuditSupport $support,
        private readonly ForgeAudit $forgeAudit,
        private readonly AtlasAudit $atlasAudit,
        private readonly RivalsAudit $rivalsAudit,
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
        $verificationEvidence = CompletionAuditPowerScorecardSupport::verificationEvidence($rivals);
        $powerScorecard = CompletionAuditPowerScorecardSupport::powerScorecard($localReady, $claimReady, $artifactCoverage, $verificationEvidence);

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
            'executive_report' => CompletionAuditPowerScorecardSupport::executiveReport($localReady, $claimReady, $missing, $verificationEvidence),
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
            'rivals_invalid_battery_quarantine' => $this->rivalsAudit->rivalsInvalidBatteryQuarantineCovered($root),
            'rivals_history_timeline' => $this->rivalsAudit->rivalsHistoryTimelineCovered($root),
            'rivals_experiment_validity_contract' => $this->rivalsAudit->rivalsExperimentValidityContractCovered($root),
            'rivals_provider_runtime_preflight' => $this->rivalsAudit->rivalsProviderRuntimePreflightCovered($root),
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
     * @param  array<int,array<string,mixed>>  $checklist
     * @return array<string,mixed>
     */
    private function forgeRuntimeCertification(array $checklist): array
    {
        return $this->forgeAudit->forgeRuntimeCertification($checklist);
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeLiveExecutionCertification(): array
    {
        return $this->forgeAudit->forgeLiveExecutionCertification();
    }

    /**
     * @return array<string,mixed>
     */
    private function atlasCodeEnterpriseCertification(): array
    {
        return $this->atlasAudit->atlasCodeEnterpriseCertification();
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeFastPathCertification(): array
    {
        return $this->forgeAudit->forgeFastPathCertification();
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeReviewCompletionCertification(): array
    {
        return $this->forgeAudit->forgeReviewCompletionCertification();
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
        return $this->forgeAudit->forgeNativeRivalsCertification($workspace);
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeWorkIntakeCertification(): array
    {
        return $this->forgeAudit->forgeWorkIntakeCertification();
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeOperatorCockpitCertification(): array
    {
        return $this->forgeAudit->forgeOperatorCockpitCertification();
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
        return $this->atlasAudit->atlasForgeContinuumCertification($workspace);
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
        return $this->atlasAudit->atlasForgeProviderCapacityCertification($workspace);
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
        return $this->atlasAudit->atlasForgeProviderInvocationCertification($workspace);
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
        return $this->atlasAudit->atlasForgeRealProviderDriversCertification($workspace);
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
        return $this->atlasAudit->atlasCodeForgeHumanFirstUxCertification($workspace);
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
        return $this->atlasAudit->atlasCodeObraCommandCenterCertification($workspace);
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
        return $this->atlasAudit->atlasCodeVisualErgonomicsCertification($workspace);
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
        return $this->atlasAudit->atlasCodePremiumWorkbenchVisualComfortCertification($workspace);
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
        return $this->atlasAudit->atlasSelfImprovementGovernanceCertification($workspace);
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
        return $this->atlasAudit->atlasSelfImprovementForgeActivationCertification($workspace);
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
        return $this->atlasAudit->atlasSelfImprovementActivationCockpitCertification($workspace);
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
        return $this->atlasAudit->atlasSelfImprovementClosedLoopLevel7Certification($workspace);
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
        return $this->rivalsAudit->rivalsOneShotEnterpriseEvaluationCertification($workspace);
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
        return $this->rivalsAudit->rivalsEvidencePackCertification($workspace);
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
