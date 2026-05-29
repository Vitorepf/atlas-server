<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CrossReviewAutomaticGateContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchReviewPacketService;
use Tests\TestCase;

final class StewardshipBranchReviewPacketServiceTest extends TestCase
{
    public function test_builds_auto_merge_candidate_packet_from_ap769_governance(): void
    {
        $packet = app(StewardshipBranchReviewPacketService::class)->build([
            'governance_report' => $this->governance([
                'status' => StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE,
                'auto_merge_policy' => [
                    'eligible' => true,
                    'risk_class' => 'p3_low_risk_docs_tests',
                ],
            ]),
            'evidence_refs' => ['docs-health:ok', 'tests:green'],
        ]);

        $this->assertSame(StewardshipBranchReviewPacketService::PACKET_SCHEMA, $packet['schema_version']);
        $this->assertSame(StewardshipBranchReviewPacketService::STATUS_AUTO_MERGE_CANDIDATE, $packet['status']);
        $this->assertSame('atlas/area-focus/docs-safe', $packet['branch_identity']['branch_ref']);
        $this->assertSame('main', $packet['branch_identity']['base_ref']);
        $this->assertSame('finding_001', $packet['cycle_traceability']['finding_id']);
        $this->assertSame('p3_low_risk_docs_tests', $packet['risk_summary']['risk_class']);
        $this->assertTrue($packet['risk_summary']['auto_merge_eligible']);
        $this->assertSame('execute_policy_gated_auto_merge', $packet['decision_options'][0]['decision']);
        $this->assertStringContainsString('--auto-merge --execute-merge', $packet['decision_options'][0]['command']);
        $this->assertFalse($packet['claim_policy']['merge_performed']);
        $this->assertStringStartsWith('sha256:', $packet['packet_hash']);
    }

    public function test_builds_operator_review_packet_for_code_branch(): void
    {
        $packet = app(StewardshipBranchReviewPacketService::class)->build([
            'governance_report' => $this->governance([
                'status' => StewardshipBranchMergeGovernorService::STATUS_REVIEW_REQUIRED,
                'classification' => ['kind' => 'code_or_mixed'],
                'auto_merge_policy' => [
                    'eligible' => false,
                    'risk_class' => 'p2_code_review_boundary',
                ],
            ]),
        ]);

        $this->assertSame(StewardshipBranchReviewPacketService::STATUS_READY_FOR_OPERATOR_REVIEW, $packet['status']);
        $this->assertSame('code_or_mixed', $packet['risk_summary']['change_class']);
        $this->assertSame('accept_for_manual_ff_merge', $packet['decision_options'][0]['decision']);
        $this->assertNotSame('execute_policy_gated_auto_merge', $packet['decision_options'][0]['decision']);
        $this->assertTrue($packet['claim_policy']['operator_review_required_before_code_or_mixed_merge']);
    }

    public function test_blocks_packet_when_governance_has_blockers(): void
    {
        $packet = app(StewardshipBranchReviewPacketService::class)->build([
            'governance_report' => $this->governance([
                'status' => StewardshipBranchMergeGovernorService::STATUS_BLOCKED,
                'blockers' => ['merge_conflict_detected'],
                'merge_conflict_check' => ['clean' => false],
            ]),
        ]);

        $this->assertSame(StewardshipBranchReviewPacketService::STATUS_BLOCKED, $packet['status']);
        $this->assertSame(['merge_conflict_detected'], $packet['blockers']);
        $this->assertSame('repair_branch_before_review', $packet['decision_options'][0]['decision']);
        $this->assertFalse($packet['risk_summary']['conflict_clean']);
    }

    public function test_blocks_without_ap769_governance_report(): void
    {
        $packet = app(StewardshipBranchReviewPacketService::class)->build([]);

        $this->assertSame(StewardshipBranchReviewPacketService::STATUS_BLOCKED, $packet['status']);
        $this->assertSame('branch_governance_report_required', $packet['reason']);
        $this->assertFalse($packet['claim_policy']['merge_performed']);
    }

    public function test_accepts_recorded_ap769_governance_envelope(): void
    {
        $packet = app(StewardshipBranchReviewPacketService::class)->build([
            'governance_report' => $this->governance([
                'schema_version' => StewardshipBranchMergeGovernorService::RECORD_SCHEMA,
                'recorded_at' => '2026-05-27T00:00:00+00:00',
                'governance_storage_status' => 'recorded',
            ]),
        ]);

        $this->assertSame(StewardshipBranchReviewPacketService::STATUS_AUTO_MERGE_CANDIDATE, $packet['status']);
        $this->assertSame('atlas/area-focus/docs-safe', $packet['branch_identity']['branch_ref']);
    }

    public function test_cross_review_automatic_gate_entry_empty_input_returns_default_contract(): void
    {
        $result = app(StewardshipBranchReviewPacketService::class)->crossReviewAutomaticGate([]);

        $this->assertSame(
            CrossReviewAutomaticGateContract::defaults()->toArray(),
            $result,
        );
        $this->assertSame(CrossReviewAutomaticGateContract::SCHEMA, $result['schema_version']);
        $this->assertSame('cross_review_automatic_gate', $result['gate_id']);
        $this->assertFalse($result['outputs']['requires_mandatory_secondary_review']);
        $this->assertNull($result['outputs']['automatic_secondary_review_route']);
    }

    /** Step-3 first rule: cross_system true maps through the step-1 contract. */
    public function test_cross_review_automatic_gate_entry_cross_system_true_requires_mandatory_secondary_review(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'cross_system' => true,
            'changed_files' => [
                'app/Services/Ai/Mission/MissionService.php',
                'atlas-desktop/src/lib.rs',
            ],
        ];

        $result = app(StewardshipBranchReviewPacketService::class)->crossReviewAutomaticGate($input);

        $this->assertSame(
            CrossReviewAutomaticGateContract::fromArray($input)->toArray(),
            $result,
        );
        $this->assertTrue($result['outputs']['requires_mandatory_secondary_review']);
        $this->assertSame('cross_review_lane', $result['outputs']['automatic_secondary_review_route']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function governance(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => StewardshipBranchMergeGovernorService::REPORT_SCHEMA,
            'ap_contract' => 'AP-769',
            'status' => StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE,
            'repo' => [
                'repo_root_hash' => 'repo_hash',
                'base_ref' => 'main',
                'base_commit' => 'base123',
                'branch_ref' => 'atlas/area-focus/docs-safe',
                'branch_commit' => 'branch123',
            ],
            'gitkraken_review_surface' => [
                'visible_branch_ref' => 'atlas/area-focus/docs-safe',
                'visible_base_ref' => 'main',
                'reviewable_commit_count' => 1,
                'reviewable_commits' => [
                    ['short_hash' => 'abc1234', 'subject' => 'Docs safe update'],
                ],
                'changed_files' => ['docs/README.md'],
                'graph_shape' => 'branch_on_top_of_base',
                'cycle_traceability' => [
                    'finding_id' => 'finding_001',
                    'spec_id' => 'spec_001',
                    'receipt_id' => 'receipt_001',
                    'handoff_id' => 'handoff_001',
                    'sandbox_id' => 'sandbox_001',
                ],
            ],
            'classification' => ['kind' => 'documentation_only'],
            'merge_conflict_check' => ['clean' => true],
            'auto_merge_policy' => ['eligible' => true, 'risk_class' => 'p3_low_risk_docs_tests'],
            'blockers' => [],
        ], $overrides);
    }
}
