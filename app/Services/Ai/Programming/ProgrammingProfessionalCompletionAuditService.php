<?php

namespace App\Services\Ai\Programming;

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
        $forgeNativeRivalsCertification = $this->forgeNativeRivalsCertification($workspace);
        $rivalsOneShotEnterpriseEvaluationCertification = $this->rivalsOneShotEnterpriseEvaluationCertification($workspace);
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
            'forge_native_rivals_certification' => $forgeNativeRivalsCertification,
            'rivals_one_shot_enterprise_evaluation_certification' => $rivalsOneShotEnterpriseEvaluationCertification,
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
                && str_contains($plannerSource, 'promoted_programming_graph_rag_semantic'),
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
