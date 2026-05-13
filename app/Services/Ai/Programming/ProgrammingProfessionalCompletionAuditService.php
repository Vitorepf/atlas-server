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

        return [
            'schema_version' => 'atlas.programming.professional_completion_audit.v1',
            'status' => $missing === [] ? 'complete' : 'blocked',
            'generated_at' => now()->toJSON(),
            'objective' => 'Implement professional programming documentation and enterprise runtime for RAG, Agentic RAG, quality gates, receipts and Rivals-Programming integrity.',
            'completion_allowed' => $missing === [],
            'audit_protocol' => $this->auditProtocol(),
            'artifact_coverage' => $artifactCoverage,
            'verification_evidence' => $verificationEvidence,
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
                'external_provider_cost_requires_operator_approval' => true,
                'synthetic_scores_allowed' => false,
            ],
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
                    'label' => 'Comparable real Rivals cases',
                    'value' => data_get($verificationEvidence, 'rivals_external_claim.comparable_case_count', 0),
                    'status' => data_get($verificationEvidence, 'rivals_external_claim.status', 'unknown'),
                ],
            ],
            'current_blocker' => $missing[0] ?? null,
            'operator_next_action' => $this->operatorNextAction($claimReady, $verificationEvidence),
            'safety_summary' => [
                'provider_dispatches_now' => data_get($verificationEvidence, 'operator_safety.provider_dispatches_now', true),
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
            ],
            'rivals_external_claim' => [
                'status' => data_get($rivals, 'status', 'unknown'),
                'claim_ready' => (bool) data_get($rivals, 'summary.claim_ready', false),
                'external_provider_battery_executed' => (bool) data_get($rivals, 'summary.external_provider_battery_executed', false),
                'real_provider_battery_attempted' => (bool) data_get($rivals, 'summary.real_provider_battery_attempted', false),
                'comparable_case_count' => (int) data_get($rivals, 'summary.comparable_case_count', 0),
                'invalid_case_count' => (int) data_get($rivals, 'summary.invalid_case_count', 0),
                'real_battery_invalid' => (bool) data_get($rivals, 'summary.real_battery_invalid', false),
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

        if ((bool) data_get($verificationEvidence, 'rivals_external_claim.real_battery_invalid', false)) {
            return 'Stop paid Rivals runs; triage invalid Atlas protocol result, failed gates and workspace scope before another provider battery.';
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
            'local_benchmarks' => $this->classesCovered([
                ProgrammingRetrievalBenchmarkService::class,
                ProgrammingTestImpactBenchmarkService::class,
                ProgrammingPatchVerifierBenchmarkService::class,
            ]),
            'programming_cli_commands' => $this->programmingCliCommandsCovered($root),
            'structure_mother_safe_rivals_commands' => $this->structureMotherSafeRivalsCommandsCovered($root),
            'api_rivals_battery_guard' => $this->apiRivalsBatteryGuardCovered($root),
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
                'agentic_rag_generates_replayable_context_pack',
                'required_sources_are_checked_by_fail_closed_gap_critic',
                'programming_actions_have_receipts_manifests_rollback_and_review_gates',
                'local_retrieval_test_impact_and_patch_verifier_benchmarks_pass',
                'programming_cli_commands_are_registered_for_operator_execution',
                'rivals_readiness_separates_local_evidence_from_external_provider_claims',
                'rivals_integrity_blocks_unfair_or_synthetic_ab_test_scores',
                'structure_mother_blocks_paid_rivals_commands_from_dirty_workspace',
                'rivals_rerun_preconditions_prevent_token_spend_on_invalid_battery',
                'mobile_api_battery_plan_blocks_dirty_non_git_and_invalid_historical_runs',
                'real_provider_claim_requires_comparable_cases_and_verified_export_bundle',
            ],
            'prompt_to_artifact_map' => [
                [
                    'prompt_requirement' => 'documentacao profissional de programacao',
                    'primary_artifacts' => [
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
                    'prompt_requirement' => 'desempenho e qualidade de programacao',
                    'primary_artifacts' => [
                        'ProgrammingTestImpactAnalyzer',
                        'ProgrammingPatchVerifier',
                        'ProgrammingSemanticCodeGraphService',
                    ],
                    'verification' => 'php artisan atlas:programming:test-impact-benchmark --json && php artisan atlas:programming:patch-verifier-benchmark --json',
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
            $this->item('local_benchmarks', 'Retrieval, Test Impact and Patch Verifier golden sets pass locally.', 'atlas:programming:*benchmark', $localStatus('local_benchmarks'), $localStatus('local_benchmarks') === 'passed' ? null : 'local_benchmarks_missing_or_failed'),
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
