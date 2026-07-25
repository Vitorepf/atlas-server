<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\Support\CompletionAuditPowerScorecardSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pure power-scorecard + report assembly — no I/O, no CompletionAuditService, no rivals readiness.
 */
final class CompletionAuditPowerScorecardSupportTest extends TestCase
{
    public function test_score_dimension_clamps_credit_and_nulls_blocker_when_passed(): void
    {
        $passed = CompletionAuditPowerScorecardSupport::scoreDimension(
            'demo',
            'Demo label',
            1.5,
            true,
            2.0,
            'should_not_appear',
        );

        $this->assertSame('demo', $passed['id']);
        $this->assertSame(1.5, $passed['weight']);
        $this->assertSame(1.0, $passed['credit']);
        $this->assertSame(1.5, $passed['earned']);
        $this->assertTrue($passed['passed']);
        $this->assertNull($passed['blocker']);

        $failed = CompletionAuditPowerScorecardSupport::scoreDimension(
            'demo',
            'Demo label',
            1.5,
            false,
            0.8,
            'missing_evidence',
        );

        $this->assertSame(0.0, $failed['credit']);
        $this->assertSame(0.0, $failed['earned']);
        $this->assertFalse($failed['passed']);
        $this->assertSame('missing_evidence', $failed['blocker']);
    }

    public function test_power_scorecard_all_dimensions_passed_with_claim_meets_target(): void
    {
        $coverage = $this->fullArtifactCoverage();
        $evidence = $this->fullVerificationEvidence(claimReady: true, rivalsStatus: 'claim_ready');

        $card = CompletionAuditPowerScorecardSupport::powerScorecard(
            localReady: true,
            claimReady: true,
            artifactCoverage: $coverage,
            verificationEvidence: $evidence,
        );

        $this->assertSame('atlas.programming.power_scorecard.v1', $card['schema_version']);
        $this->assertSame(10.0, $card['score_out_of_10']);
        $this->assertSame(9.0, $card['target_score_out_of_10']);
        $this->assertSame('target_met', $card['status']);
        $this->assertSame([], $card['blocking_items']);
        $this->assertSame([], $card['next_score_actions']);
        $this->assertCount(9, $card['dimensions']);
        $this->assertFalse($card['scoring_policy']['synthetic_scores_allowed']);
        $this->assertTrue($card['scoring_policy']['external_rivals_claim_required_for_target_met']);
    }

    public function test_power_scorecard_blocks_target_without_claim_even_when_local_full(): void
    {
        $coverage = $this->fullArtifactCoverage();
        $evidence = $this->fullVerificationEvidence(claimReady: false, rivalsStatus: 'external_battery_required');

        $card = CompletionAuditPowerScorecardSupport::powerScorecard(
            localReady: true,
            claimReady: false,
            artifactCoverage: $coverage,
            verificationEvidence: $evidence,
        );

        $this->assertSame('target_not_met', $card['status']);
        $this->assertLessThan(10.0, $card['score_out_of_10']);
        $blockingIds = array_column($card['blocking_items'], 'id');
        $this->assertContains('real_rivals_execution_proof', $blockingIds);
        $this->assertContains('external_battery_required', $card['next_score_actions']);
    }

    public function test_power_scorecard_surfaces_missing_local_dimensions(): void
    {
        $card = CompletionAuditPowerScorecardSupport::powerScorecard(
            localReady: false,
            claimReady: false,
            artifactCoverage: [],
            verificationEvidence: [],
        );

        $this->assertSame('target_not_met', $card['status']);
        $this->assertSame(0.0, $card['score_out_of_10']);
        $this->assertNotEmpty($card['blocking_items']);
        $this->assertContains('retrieval_or_context_pack_not_verified', $card['next_score_actions']);
        $this->assertContains('durable_execution_contract_missing', $card['next_score_actions']);
    }

    public function test_verification_evidence_defaults_and_maps_rivals_payload(): void
    {
        $empty = CompletionAuditPowerScorecardSupport::verificationEvidence([]);
        $this->assertSame('atlas.programming.professional_completion_verification_evidence.v1', $empty['schema_version']);
        $this->assertSame('unknown', $empty['local_benchmarks']['retrieval']['status']);
        $this->assertFalse($empty['rivals_external_claim']['claim_ready']);
        $this->assertTrue($empty['operator_safety']['provider_dispatches_now']);
        $this->assertTrue($empty['operator_safety']['operator_approval_required']);

        $mapped = CompletionAuditPowerScorecardSupport::verificationEvidence([
            'status' => 'external_battery_required',
            'summary' => [
                'claim_ready' => true,
                'comparable_case_count' => 3,
                'real_battery_invalid' => true,
                'invalid_battery_requires_triage_before_rerun' => true,
                'synthetic_scores_allowed' => false,
            ],
            'local_benchmarks' => [
                'retrieval' => [
                    'status' => 'passed',
                    'metrics' => ['recall_at_k' => 0.9],
                    'promotion_gate' => ['graph_rag_runtime_promoted' => true],
                ],
                'repair_loop' => [
                    'status' => 'passed',
                    'metrics' => ['receipt_integrity_passed' => true],
                ],
            ],
            'operator_execution_packet' => [
                'provider_dispatches_now' => false,
                'operator_approval_required' => true,
                'external_cost_possible' => true,
                'runbook_review_required' => true,
                'rerun_provider_battery_allowed_now' => false,
            ],
            'integrity_assurance' => [
                'status' => 'enforced',
                'blocking_reasons' => ['none'],
            ],
        ]);

        $this->assertSame('passed', $mapped['local_benchmarks']['retrieval']['status']);
        $this->assertSame(0.9, $mapped['local_benchmarks']['retrieval']['metrics']['recall_at_k']);
        $this->assertTrue($mapped['rivals_external_claim']['claim_ready']);
        $this->assertSame(3, $mapped['rivals_external_claim']['comparable_case_count']);
        $this->assertTrue($mapped['rivals_external_claim']['real_battery_invalid']);
        $this->assertFalse($mapped['operator_safety']['provider_dispatches_now']);
        $this->assertSame('enforced', $mapped['rivals_external_claim']['integrity_status']);
    }

    public function test_operator_next_action_priority_order(): void
    {
        $this->assertSame(
            'Review verified export bundle and promote completion.',
            CompletionAuditPowerScorecardSupport::operatorNextAction(true, []),
        );

        $this->assertSame(
            'Stop paid Rivals runs; triage invalid Atlas protocol result, failed gates and workspace scope before another provider battery.',
            CompletionAuditPowerScorecardSupport::operatorNextAction(false, [
                'rivals_external_claim' => [
                    'invalid_battery_requires_triage_before_rerun' => true,
                    'real_battery_invalid' => true,
                ],
            ]),
        );

        $this->assertSame(
            'Historical invalid Rivals battery is quarantined; use clean isolated worktrees and explicit operator cost approval for the next fresh paired battery.',
            CompletionAuditPowerScorecardSupport::operatorNextAction(false, [
                'rivals_external_claim' => [
                    'invalid_battery_requires_triage_before_rerun' => false,
                    'real_battery_invalid' => true,
                ],
            ]),
        );

        $this->assertSame(
            'Approve and run a real paired Rivals battery only after reviewing runbook and accepting provider cost.',
            CompletionAuditPowerScorecardSupport::operatorNextAction(false, [
                'rivals_external_claim' => [
                    'invalid_battery_requires_triage_before_rerun' => false,
                    'real_battery_invalid' => false,
                ],
            ]),
        );
    }

    public function test_executive_report_headline_and_blocker(): void
    {
        $evidence = [
            'local_benchmarks' => [
                'retrieval' => [
                    'status' => 'passed',
                    'metrics' => ['recall_at_k' => 0.88, 'precision_at_k' => 0.77],
                ],
                'test_impact' => ['status' => 'passed', 'metrics' => ['recall' => 0.7]],
                'patch_verifier' => ['status' => 'passed', 'metrics' => ['grounded_patch_rate' => 0.95]],
                'repair_loop' => ['status' => 'passed', 'metrics' => ['repair_planning_pass_rate' => 0.8]],
            ],
            'rivals_external_claim' => [
                'comparable_case_count' => 0,
                'status' => 'external_battery_required',
                'synthetic_scores_allowed' => false,
            ],
            'operator_safety' => [
                'provider_dispatches_now' => false,
                'operator_approval_required' => true,
            ],
            'invalid_battery_triage_packet' => [
                'provider_budget_policy' => [
                    'spend_more_provider_tokens_now' => false,
                    'reason' => 'preflight_blocked',
                ],
            ],
        ];
        $missing = [['id' => 'rivals_programming_real', 'status' => 'blocked']];

        $localBlocked = CompletionAuditPowerScorecardSupport::executiveReport(false, false, $missing, $evidence);
        $this->assertSame('atlas.programming.professional_completion_executive_report.v1', $localBlocked['schema_version']);
        $this->assertSame('Programming foundation is not ready locally.', $localBlocked['headline']);
        $this->assertSame('Blocked', $localBlocked['status_label']);
        $this->assertSame('blocked', $localBlocked['primary_state']['local_foundation']);
        $this->assertSame($missing[0], $localBlocked['current_blocker']);
        $this->assertSame(0.88, $localBlocked['key_metrics'][0]['value']);
        $this->assertFalse($localBlocked['safety_summary']['provider_dispatches_now']);
        $this->assertSame('preflight_blocked', $localBlocked['safety_summary']['provider_budget_reason']);

        $localReady = CompletionAuditPowerScorecardSupport::executiveReport(true, false, $missing, $evidence);
        $this->assertStringContainsString('ready locally', $localReady['headline']);
        $this->assertSame('ready', $localReady['primary_state']['local_foundation']);
        $this->assertSame('blocked', $localReady['primary_state']['external_rivals_claim']);

        $claimReady = CompletionAuditPowerScorecardSupport::executiveReport(true, true, [], $evidence);
        $this->assertStringContainsString('verified real Rivals', $claimReady['headline']);
        $this->assertSame('Complete', $claimReady['status_label']);
        $this->assertSame('allowed', $claimReady['primary_state']['completion']);
        $this->assertNull($claimReady['current_blocker']);
        $this->assertSame(
            'Review verified export bundle and promote completion.',
            $claimReady['operator_next_action'],
        );
    }

    public function test_audit_protocol_is_stable_static_map(): void
    {
        $protocol = CompletionAuditPowerScorecardSupport::auditProtocol();

        $this->assertSame('atlas.programming.professional_completion_audit_protocol.v1', $protocol['schema_version']);
        $this->assertCount(16, $protocol['success_criteria']);
        $this->assertContains('uncertainty_blocks_completion', array_keys($protocol['proxy_signal_policy']));
        $this->assertTrue($protocol['proxy_signal_policy']['tests_alone_are_insufficient']);
        $this->assertCount(6, $protocol['prompt_to_artifact_map']);
        $this->assertSame(
            'documentacao profissional de programacao',
            $protocol['prompt_to_artifact_map'][0]['prompt_requirement'],
        );
        $this->assertSame(
            'php artisan atlas:programming:completion-audit --json',
            $protocol['prompt_to_artifact_map'][5]['verification'],
        );
        $this->assertSame($protocol, CompletionAuditPowerScorecardSupport::auditProtocol());
    }

    public function test_item_omits_null_blocker(): void
    {
        $passed = CompletionAuditPowerScorecardSupport::item(
            'demo',
            'Demo requirement',
            'evidence.path',
            'passed',
        );
        $this->assertSame(['id', 'requirement', 'evidence', 'status'], array_keys($passed));
        $this->assertArrayNotHasKey('blocker', $passed);

        $blocked = CompletionAuditPowerScorecardSupport::item(
            'demo',
            'Demo requirement',
            'evidence.path',
            'blocked',
            'missing_artifact',
        );
        $this->assertSame('missing_artifact', $blocked['blocker']);
    }

    public function test_checklist_passes_when_local_claim_and_docs_ready(): void
    {
        $coverage = [
            'professional_operating_standard' => ['covered' => true],
            'professional_spec' => ['covered' => true],
            'enterprise_plan' => ['covered' => true],
            'completion_audit_doc' => ['covered' => true],
            'agentic_rag_context_pack' => ['covered' => true],
            'hybrid_retrieval_and_gap_critic' => ['covered' => true],
            'semantic_code_graph' => ['covered' => true],
            'stage_receipts_resume' => ['covered' => true],
            'tool_runtime_manifests' => ['covered' => true],
            'patch_verifier' => ['covered' => true],
            'test_impact' => ['covered' => true],
            'sandbox_repair_learning' => ['covered' => true],
            'python_runtime' => ['covered' => true],
            'local_benchmarks' => ['covered' => true],
            'programming_cli_commands' => ['covered' => true],
            'structure_mother_safe_rivals_commands' => ['covered' => true],
            'api_rivals_battery_guard' => ['covered' => true],
            'rivals_invalid_battery_quarantine' => ['covered' => true],
            'rivals_history_timeline' => ['covered' => true],
            'rivals_experiment_validity_contract' => ['covered' => true],
            'rivals_provider_runtime_preflight' => ['covered' => true],
        ];

        $checklist = CompletionAuditPowerScorecardSupport::checklist(
            artifactCoverage: $coverage,
            localReady: true,
            claimReady: true,
            operatorPacketReady: true,
            integrityAssuranceReady: true,
            rerunPreconditionsReady: true,
            rivalsRealBlocker: 'external_battery_required',
        );

        $this->assertCount(25, $checklist);
        $statuses = array_column($checklist, 'status');
        $this->assertSame(['passed'], array_values(array_unique($statuses)));
        $this->assertSame('rivals_programming_real', $checklist[24]['id']);
        $this->assertArrayNotHasKey('blocker', $checklist[24]);
    }

    public function test_checklist_blocks_local_and_claim_with_specific_blockers(): void
    {
        $checklist = CompletionAuditPowerScorecardSupport::checklist(
            artifactCoverage: [],
            localReady: false,
            claimReady: false,
            operatorPacketReady: false,
            integrityAssuranceReady: false,
            rerunPreconditionsReady: false,
            rivalsRealBlocker: 'external_battery_required',
        );

        $byId = [];
        foreach ($checklist as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertSame('blocked', $byId['professional_operating_standard']['status']);
        $this->assertSame('professional_operating_standard_missing', $byId['professional_operating_standard']['blocker']);
        $this->assertSame('blocked', $byId['agentic_rag_context_pack']['status']);
        $this->assertSame('agentic_rag_context_pack_missing_or_unverified', $byId['agentic_rag_context_pack']['blocker']);
        $this->assertSame('operator_execution_packet_missing_or_unsafe', $byId['operator_execution_packet']['blocker']);
        $this->assertSame('rivals_integrity_assurance_missing_or_unsafe', $byId['rivals_integrity_assurance']['blocker']);
        $this->assertSame('rivals_rerun_preconditions_missing_or_unsafe', $byId['rivals_rerun_preconditions']['blocker']);
        $this->assertSame('external_battery_required', $byId['rivals_programming_real']['blocker']);
    }

    public function test_external_rivals_certification_status_and_operational_state_matrix(): void
    {
        $passed = CompletionAuditPowerScorecardSupport::externalRivalsCertification([
            'rivals_external_claim' => [
                'status' => 'claim_ready',
                'claim_ready' => true,
                'comparable_case_count' => 4,
                'blocking_reasons' => [],
            ],
        ]);
        $this->assertSame('atlas.programming.rivals_readiness.v1', $passed['schema_version']);
        $this->assertSame('passed', $passed['status']);
        $this->assertSame('claim_ready', $passed['operational_state']);
        $this->assertFalse($passed['requires_operator_approval']);
        $this->assertSame(4, $passed['comparable_case_count']);
        $this->assertSame('forge_runtime_certification', $passed['separated_from']);

        $invalid = CompletionAuditPowerScorecardSupport::externalRivalsCertification([
            'rivals_external_claim' => [
                'status' => 'external_battery_invalid',
                'claim_ready' => false,
                'blocking_reasons' => ['dirty_worktree', 12, ''],
                'invalid_battery_requires_triage_before_rerun' => true,
            ],
            'invalid_battery_triage_packet' => [
                'status' => 'triage_required_before_rerun',
                'next_action' => 'Triage first.',
                'historical_failure_policy' => [
                    'historical_failed_gates_are_diagnostic' => true,
                    'triaged_invalid_batteries_do_not_enter_score_or_block_forever' => true,
                ],
                'provider_budget_policy' => [
                    'spend_more_provider_tokens_now' => false,
                    'reason' => 'invalid_battery',
                ],
                'current_rerun_preconditions' => [
                    'provider_dispatch_allowed_now' => false,
                    'why_provider_dispatch_is_blocked' => ['needs_triage'],
                    'diagnostic_commands_without_provider_spend' => ['atlas:programming:rivals-readiness --triage'],
                ],
            ],
            'current_workspace_preflight' => [
                'status' => 'blocked',
                'ready_for_provider_battery' => false,
                'blocking_reasons' => ['dirty'],
                'git' => ['dirty_count' => 3],
            ],
            'current_local_recheck_evidence' => [
                'status' => 'passed',
                'all_known_rechecks_passed' => true,
            ],
            'operator_safety' => [
                'rerun_provider_battery_allowed_now' => false,
            ],
        ]);

        $this->assertSame('blocked_requires_operator_approval', $invalid['status']);
        $this->assertSame('blocked_until_invalid_battery_triaged', $invalid['operational_state']);
        $this->assertTrue($invalid['requires_operator_approval']);
        $this->assertSame(['dirty_worktree'], $invalid['blocking_reasons']);
        $this->assertTrue($invalid['invalid_battery_triage']['requires_triage_before_rerun']);
        $this->assertTrue($invalid['invalid_battery_triage']['historical_failures_are_diagnostic']);
        $this->assertSame(3, $invalid['current_workspace_preflight']['dirty_count']);
        $this->assertFalse($invalid['provider_budget_policy']['spend_more_provider_tokens_now']);
        $this->assertSame(['needs_triage'], $invalid['fresh_provider_rerun_preconditions']['blocking_reasons']);
        $this->assertSame('Triage first.', $invalid['next_action']);

        $readyRerun = CompletionAuditPowerScorecardSupport::externalRivalsCertification([
            'rivals_external_claim' => [
                'status' => 'external_battery_required',
                'claim_ready' => false,
            ],
            'invalid_battery_triage_packet' => ['status' => 'ok'],
            'current_workspace_preflight' => ['status' => 'ready'],
            'operator_safety' => ['rerun_provider_battery_allowed_now' => true],
        ]);
        $this->assertSame('blocked', $readyRerun['status']);
        $this->assertSame('ready_for_operator_paid_rerun', $readyRerun['operational_state']);

        $cleanBlocked = CompletionAuditPowerScorecardSupport::externalRivalsCertification([
            'rivals_external_claim' => [
                'status' => 'external_battery_required',
                'claim_ready' => false,
            ],
            'current_workspace_preflight' => ['status' => 'blocked'],
        ]);
        $this->assertSame('blocked_until_clean_worktree', $cleanBlocked['operational_state']);

        $defaultBlocked = CompletionAuditPowerScorecardSupport::externalRivalsCertification([]);
        $this->assertSame('blocked', $defaultBlocked['status']);
        $this->assertSame('blocked_until_valid_comparable_battery', $defaultBlocked['operational_state']);
        $this->assertSame('unknown', $defaultBlocked['raw_status']);
        $this->assertStringContainsString('clean worktrees', (string) $defaultBlocked['next_action']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fullArtifactCoverage(): array
    {
        return [
            'agentic_rag_context_pack' => ['covered' => true],
            'hybrid_retrieval_and_gap_critic' => ['covered' => true],
            'stage_receipts_resume' => ['covered' => true],
            'tool_runtime_manifests' => ['covered' => true],
            'sandbox_repair_learning' => ['covered' => true],
            'rivals_provider_runtime_preflight' => ['covered' => true],
            'programming_cli_commands' => [
                'checks' => [
                    'AtlasProgrammingResumeCommand' => ['registered_in_bootstrap' => true],
                ],
            ],
            'python_runtime' => ['covered' => true],
            'programming_graph_rag_runtime' => ['covered' => true],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fullVerificationEvidence(bool $claimReady, string $rivalsStatus): array
    {
        return [
            'local_benchmarks' => [
                'retrieval' => [
                    'status' => 'passed',
                    'promotion_gate' => ['graph_rag_runtime_promoted' => true],
                ],
                'test_impact' => ['status' => 'passed'],
                'patch_verifier' => ['status' => 'passed'],
                'repair_loop' => [
                    'status' => 'passed',
                    'metrics' => ['receipt_integrity_passed' => true],
                ],
            ],
            'rivals_external_claim' => [
                'status' => $rivalsStatus,
                'claim_ready' => $claimReady,
            ],
        ];
    }
}
