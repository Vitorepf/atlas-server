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
