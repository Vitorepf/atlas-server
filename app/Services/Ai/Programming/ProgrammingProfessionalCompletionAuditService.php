<?php

namespace App\Services\Ai\Programming;

class ProgrammingProfessionalCompletionAuditService
{
    public function __construct(
        private readonly ProgrammingRivalsReadinessService $rivalsReadiness,
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

        return [
            'schema_version' => 'atlas.programming.professional_completion_audit.v1',
            'status' => $missing === [] ? 'complete' : 'blocked',
            'generated_at' => now()->toJSON(),
            'objective' => 'Implement professional programming documentation and enterprise runtime for RAG, Agentic RAG, quality gates, receipts and Rivals-Programming integrity.',
            'completion_allowed' => $missing === [],
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
