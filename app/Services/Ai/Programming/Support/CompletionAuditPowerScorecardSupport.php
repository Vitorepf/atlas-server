<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

/**
 * Pure power-scorecard + protocol/checklist/rivals-cert projection for professional completion audit.
 *
 * Extracted from ProgrammingProfessionalCompletionAuditService private pure methods.
 * No I/O, no DI, no provider calls, no time side effects.
 * Residual FS artifactCoverage + DI certification owners stay on the host.
 */
final class CompletionAuditPowerScorecardSupport
{
    /**
     * @param  array<string,mixed>  $artifactCoverage
     * @param  array<string,mixed>  $verificationEvidence
     * @return array<string,mixed>
     */
    public static function powerScorecard(
        bool $localReady,
        bool $claimReady,
        array $artifactCoverage,
        array $verificationEvidence,
    ): array {
        $dimensions = [
            self::scoreDimension(
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
            self::scoreDimension(
                'durable_execution_contracts',
                'Stage receipts, action manifests, sandbox and completion gates are present.',
                1.4,
                data_get($artifactCoverage, 'stage_receipts_resume.covered') === true
                    && data_get($artifactCoverage, 'tool_runtime_manifests.covered') === true
                    && data_get($artifactCoverage, 'sandbox_repair_learning.covered') === true,
                1.0,
                'durable_execution_contract_missing',
            ),
            self::scoreDimension(
                'repair_loop_execution',
                'Repair loop has executable capsule, stop rules, receipt integrity and benchmark evidence.',
                1.3,
                data_get($verificationEvidence, 'local_benchmarks.repair_loop.status') === 'passed'
                    && data_get($verificationEvidence, 'local_benchmarks.repair_loop.metrics.receipt_integrity_passed') === true,
                1.0,
                'repair_loop_benchmark_not_passed',
            ),
            self::scoreDimension(
                'test_quality_gates',
                'Test impact and patch verifier block weak or ungrounded changes.',
                1.1,
                data_get($verificationEvidence, 'local_benchmarks.test_impact.status') === 'passed'
                    && data_get($verificationEvidence, 'local_benchmarks.patch_verifier.status') === 'passed',
                1.0,
                'test_impact_or_patch_verifier_not_passed',
            ),
            self::scoreDimension(
                'rivals_provider_preflight',
                'Paid Rivals provider runs are blocked until workspaces are clean, runnable and bounded by timeout.',
                0.6,
                data_get($artifactCoverage, 'rivals_provider_runtime_preflight.covered') === true,
                1.0,
                'rivals_provider_runtime_preflight_missing',
            ),
            self::scoreDimension(
                'resume_and_continuation',
                'Work can resume from persisted receipts without relying on chat memory.',
                0.9,
                data_get($artifactCoverage, 'stage_receipts_resume.covered') === true
                    && data_get($artifactCoverage, 'programming_cli_commands.checks.AtlasProgrammingResumeCommand.registered_in_bootstrap') === true,
                1.0,
                'resume_contract_not_verified',
            ),
            self::scoreDimension(
                'python_runtime_boundary',
                'Python runtime is governed and approval-gated for code intelligence.',
                0.7,
                data_get($artifactCoverage, 'python_runtime.covered') === true,
                1.0,
                'python_runtime_boundary_not_verified',
            ),
            self::scoreDimension(
                'graph_rag_runtime',
                'Graph RAG is a promoted runtime, not only future-governed proposal.',
                0.8,
                data_get($artifactCoverage, 'programming_graph_rag_runtime.covered') === true
                    && data_get($verificationEvidence, 'local_benchmarks.retrieval.promotion_gate.graph_rag_runtime_promoted') === true,
                1.0,
                'graph_rag_runtime_still_future_governed',
            ),
            self::scoreDimension(
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
    public static function scoreDimension(
        string $id,
        string $label,
        float $weight,
        bool $passed,
        float $credit,
        string $blocker,
    ): array {
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
     * @param  array<string,mixed>  $rivals
     * @return array<string,mixed>
     */
    public static function verificationEvidence(array $rivals): array
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
    public static function operatorNextAction(bool $claimReady, array $verificationEvidence): string
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
     * @param  array<int,array<string,mixed>>  $missing
     * @param  array<string,mixed>  $verificationEvidence
     * @return array<string,mixed>
     */
    public static function executiveReport(
        bool $localReady,
        bool $claimReady,
        array $missing,
        array $verificationEvidence,
    ): array {
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
            'operator_next_action' => self::operatorNextAction($claimReady, $verificationEvidence),
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
     * @return array<string,mixed>
     */
    public static function auditProtocol(): array
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
     * @param  array<string,mixed>  $artifactCoverage
     * @return array<int,array<string,mixed>>
     */
    public static function checklist(
        array $artifactCoverage,
        bool $localReady,
        bool $claimReady,
        bool $operatorPacketReady,
        bool $integrityAssuranceReady,
        bool $rerunPreconditionsReady,
        string $rivalsRealBlocker,
    ): array {
        $localStatus = static fn (string $key): string => $localReady && (bool) data_get($artifactCoverage, $key.'.covered', false) ? 'passed' : 'blocked';
        $docStatus = static fn (string $key): string => (bool) data_get($artifactCoverage, $key.'.covered', false) ? 'passed' : 'blocked';

        return [
            self::item('professional_operating_standard', 'Professional RAG operating standard exists and rejects weak MVP as a completion path.', 'docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md', $docStatus('professional_operating_standard'), $docStatus('professional_operating_standard') === 'passed' ? null : 'professional_operating_standard_missing'),
            self::item('professional_spec', 'Professional Agentic RAG spec exists and rejects weak MVP.', 'docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md', $docStatus('professional_spec'), $docStatus('professional_spec') === 'passed' ? null : 'professional_spec_missing'),
            self::item('enterprise_plan', 'Enterprise programming plan maps the professional implementation blocks.', 'docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md', $docStatus('enterprise_plan'), $docStatus('enterprise_plan') === 'passed' ? null : 'enterprise_plan_missing'),
            self::item('completion_audit_doc', 'Completion audit document records requirement-to-artifact coverage.', 'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md', $docStatus('completion_audit_doc'), $docStatus('completion_audit_doc') === 'passed' ? null : 'completion_audit_doc_missing'),
            self::item('agentic_rag_context_pack', 'Professional plan and context pack are generated, hashed and replayable.', 'ProgrammingRetrievalPlanner + ProgrammingContextPackStore', $localStatus('agentic_rag_context_pack'), $localStatus('agentic_rag_context_pack') === 'passed' ? null : 'agentic_rag_context_pack_missing_or_unverified'),
            self::item('hybrid_retrieval_and_gap_critic', 'Hybrid graph/vector retrieval, reranking and gap critic are covered by local benchmarks.', 'ProgrammingRetrievalExecutor + ProgrammingGapCritic', $localStatus('hybrid_retrieval_and_gap_critic'), $localStatus('hybrid_retrieval_and_gap_critic') === 'passed' ? null : 'hybrid_retrieval_or_gap_critic_missing_or_unverified'),
            self::item('semantic_code_graph', 'Semantic Code Graph feeds programming retrieval and test impact.', 'ProgrammingSemanticCodeGraphService', $localStatus('semantic_code_graph'), $localStatus('semantic_code_graph') === 'passed' ? null : 'semantic_code_graph_missing_or_unverified'),
            self::item('stage_receipts_resume', 'Stage receipts and resume reconstruct prior work without session memory.', 'ProgrammingStageReceiptStore + ProgrammingResumeService', $localStatus('stage_receipts_resume'), $localStatus('stage_receipts_resume') === 'passed' ? null : 'stage_receipts_resume_missing_or_unverified'),
            self::item('tool_runtime_manifests', 'Programming actions produce manifests with dry-run, rollback and gate effect.', 'ProgrammingActionManifestFactory + AiToolRuntime', $localStatus('tool_runtime_manifests'), $localStatus('tool_runtime_manifests') === 'passed' ? null : 'tool_runtime_manifests_missing_or_unverified'),
            self::item('patch_verifier', 'Patch Verifier blocks ungrounded or weakly tested patches.', 'ProgrammingPatchVerifier + patch-verifier benchmark', $localStatus('patch_verifier'), $localStatus('patch_verifier') === 'passed' ? null : 'patch_verifier_missing_or_unverified'),
            self::item('test_impact', 'Test Impact Analysis selects proportional tests with evidence.', 'ProgrammingTestImpactAnalyzer + test-impact benchmark', $localStatus('test_impact'), $localStatus('test_impact') === 'passed' ? null : 'test_impact_missing_or_unverified'),
            self::item('sandbox_repair_learning', 'Sandbox, repair attempts and learning candidates are receipt-backed and review-gated.', 'ProgrammingSandboxManager + ProgrammingRepairAttemptStore + ProgrammingLearningCandidateStore', $localStatus('sandbox_repair_learning'), $localStatus('sandbox_repair_learning') === 'passed' ? null : 'sandbox_repair_learning_missing_or_unverified'),
            self::item('python_runtime', 'Python runtime is governed, provider-safe and approval-gated.', 'runtimes/python/programming_intelligence + ProgrammingPythonRuntimeExecutor', $localStatus('python_runtime'), $localStatus('python_runtime') === 'passed' ? null : 'python_runtime_missing_or_unverified'),
            self::item('local_benchmarks', 'Retrieval, Test Impact, Patch Verifier and Repair Loop golden sets pass locally.', 'atlas:programming:*benchmark', $localStatus('local_benchmarks'), $localStatus('local_benchmarks') === 'passed' ? null : 'local_benchmarks_missing_or_failed'),
            self::item('programming_cli_commands', 'Programming professional commands are registered for operator execution.', 'bootstrap/app.php + AtlasProgramming*Command', $docStatus('programming_cli_commands'), $docStatus('programming_cli_commands') === 'passed' ? null : 'programming_cli_commands_missing_or_unregistered'),
            self::item(
                'operator_execution_packet',
                'Rivals operator packet exposes cost/runbook confirmations and does not dispatch providers from readiness.',
                'atlas.programming.rivals_operator_execution_packet.v1',
                $operatorPacketReady ? 'passed' : 'blocked',
                $operatorPacketReady ? null : 'operator_execution_packet_missing_or_unsafe',
            ),
            self::item(
                'rivals_integrity_assurance',
                'Rivals integrity assurance enforces A/B-style validity, external variable controls and score admission gates.',
                'atlas.programming.rivals_integrity_assurance.v1',
                $integrityAssuranceReady ? 'passed' : 'blocked',
                $integrityAssuranceReady ? null : 'rivals_integrity_assurance_missing_or_unsafe',
            ),
            self::item(
                'structure_mother_safe_rivals_commands',
                'Structure mother exposes paid Rivals commands only through clean worktree placeholders and operator approval.',
                'AtlasStructureMotherAuditReadModel clean worktree command contract',
                $docStatus('structure_mother_safe_rivals_commands'),
                $docStatus('structure_mother_safe_rivals_commands') === 'passed' ? null : 'structure_mother_safe_rivals_commands_missing_or_unsafe',
            ),
            self::item(
                'rivals_rerun_preconditions',
                'Invalid Rivals battery triage separates historical failures from current rerun preconditions and blocks provider dispatch.',
                'atlas.programming.current_rivals_rerun_preconditions.v1',
                $rerunPreconditionsReady ? 'passed' : 'blocked',
                $rerunPreconditionsReady ? null : 'rivals_rerun_preconditions_missing_or_unsafe',
            ),
            self::item(
                'api_rivals_battery_guard',
                'Mobile/API battery-plan blocks dirty, non-Git or historically invalid Rivals batteries before provider execution.',
                'EngineeringBenchmarkController battery-plan preflight',
                $docStatus('api_rivals_battery_guard'),
                $docStatus('api_rivals_battery_guard') === 'passed' ? null : 'api_rivals_battery_guard_missing_or_unsafe',
            ),
            self::item(
                'rivals_invalid_battery_quarantine',
                'Invalid Rivals battery can be quarantined without deleting history, admitting score or declaring a winner.',
                'atlas:engineering:benchmark:rivals triage-invalid-battery',
                $docStatus('rivals_invalid_battery_quarantine'),
                $docStatus('rivals_invalid_battery_quarantine') === 'passed' ? null : 'rivals_invalid_battery_quarantine_missing_or_unsafe',
            ),
            self::item(
                'rivals_history_timeline',
                'Rivals report exposes run history as a structured timeline with integrity, score admission and blocker fields.',
                'atlas.fair_claude.history_timeline.v1',
                $docStatus('rivals_history_timeline'),
                $docStatus('rivals_history_timeline') === 'passed' ? null : 'rivals_history_timeline_missing_or_unsafe',
            ),
            self::item(
                'rivals_experiment_validity_contract',
                'Rivals report/export carries A/B-style experiment validity controls so external variables can block comparability but never decide the winner.',
                'atlas.fair_claude.experiment_validity.v1',
                $docStatus('rivals_experiment_validity_contract'),
                $docStatus('rivals_experiment_validity_contract') === 'passed' ? null : 'rivals_experiment_validity_contract_missing_or_unsafe',
            ),
            self::item(
                'rivals_provider_runtime_preflight',
                'Fair Claude provider execution preflights runnable Laravel workspaces, high-memory Pint and provider timeout before spending tokens.',
                'atlas.fair_claude.provider_execution_guard.v1',
                $docStatus('rivals_provider_runtime_preflight'),
                $docStatus('rivals_provider_runtime_preflight') === 'passed' ? null : 'rivals_provider_runtime_preflight_missing_or_unsafe',
            ),
            self::item(
                'rivals_programming_real',
                'Real paired provider battery has comparable cases and verified export bundle.',
                'atlas:engineering:benchmark:rivals + atlas:programming:rivals-readiness',
                $claimReady ? 'passed' : 'blocked',
                $claimReady ? null : $rivalsRealBlocker,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function item(string $id, string $requirement, string $evidence, string $status, ?string $blocker = null): array
    {
        return array_filter([
            'id' => $id,
            'requirement' => $requirement,
            'evidence' => $evidence,
            'status' => $status,
            'blocker' => $blocker,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Pure projection of external Rivals certification from verification evidence.
     *
     * @param  array<string,mixed>  $verificationEvidence
     * @return array<string,mixed>
     */
    public static function externalRivalsCertification(array $verificationEvidence): array
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
}
