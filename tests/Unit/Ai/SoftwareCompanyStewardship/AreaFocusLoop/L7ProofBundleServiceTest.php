<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L7ProofBundleService;
use PHPUnit\Framework\TestCase;

final class L7ProofBundleServiceTest extends TestCase
{
    private L7ProofBundleService $service;

    protected function setUp(): void
    {
        $this->service = new L7ProofBundleService();
    }

    /**
     * @return array<string,mixed>
     */
    private function fullyGreenChecklist(): array
    {
        return [
            'loop_executes_real' => ['met' => true, 'evidence_ref' => 'evidence://l7/loop_executes_real/ap805'],
            'ten_consecutive_cycles' => ['met' => true, 'evidence_ref' => 'evidence://l7/ten_consecutive_cycles/ap805'],
            'flywheel_proven' => ['met' => true, 'evidence_ref' => 'evidence://l7/flywheel_proven/s59'],
            'departments_l4' => ['met' => true, 'evidence_ref' => 'evidence://l7/departments_l4/s87'],
            'http_path_mission_control' => ['met' => true, 'evidence_ref' => 'evidence://l7/http_path_mission_control/s88'],
            'self_construction_proven' => ['met' => true, 'evidence_ref' => 'evidence://l7/self_construction_proven/s92'],
            'trust_ledger_stable' => ['met' => true, 'evidence_ref' => 'evidence://l7/trust_ledger_stable/s93'],
            'promotion_recorded' => ['met' => true, 'evidence_ref' => 'evidence://l7/promotion_recorded/s96'],
        ];
    }

    public function testBuildReturnsRunIdEvidenceRefsBlockersAndFinalStatusKeys(): void
    {
        $bundle = $this->service->build([
            'checklist' => $this->fullyGreenChecklist(),
        ]);

        $this->assertArrayHasKey('run_id', $bundle);
        $this->assertArrayHasKey('evidence_refs', $bundle);
        $this->assertArrayHasKey('blockers', $bundle);
        $this->assertArrayHasKey('final_status', $bundle);

        $this->assertSame('atlas.loop.l7_proof_bundle.v1', $bundle['schema_version']);
        $this->assertIsString($bundle['run_id']);
        $this->assertNotSame('', $bundle['run_id']);
        $this->assertSame('l7proof_', substr($bundle['run_id'], 0, 8));
    }

    public function testFullyGreenChecklistYieldsL7ReadyWithAllEightEvidenceRefsAndNoBlockers(): void
    {
        $bundle = $this->service->build([
            'checklist' => $this->fullyGreenChecklist(),
        ]);

        $this->assertSame('l7_ready', $bundle['final_status']);
        $this->assertTrue($bundle['is_l7_ready']);
        $this->assertTrue($bundle['checklist_complete']);
        $this->assertSame(8, $bundle['checklist_met_count']);
        $this->assertSame(8, $bundle['checklist_total']);
        $this->assertSame([], $bundle['blockers']);

        $this->assertCount(8, $bundle['evidence_refs']);
        $this->assertSame(
            [
                'evidence://l7/loop_executes_real/ap805',
                'evidence://l7/ten_consecutive_cycles/ap805',
                'evidence://l7/flywheel_proven/s59',
                'evidence://l7/departments_l4/s87',
                'evidence://l7/http_path_mission_control/s88',
                'evidence://l7/self_construction_proven/s92',
                'evidence://l7/trust_ledger_stable/s93',
                'evidence://l7/promotion_recorded/s96',
            ],
            $bundle['evidence_refs'],
        );
    }

    public function testMissingChecklistItemYieldsBlockedNotL7(): void
    {
        $checklist = $this->fullyGreenChecklist();
        unset($checklist['trust_ledger_stable']);

        $bundle = $this->service->build(['checklist' => $checklist]);

        $this->assertSame('blocked_not_l7', $bundle['final_status']);
        $this->assertFalse($bundle['is_l7_ready']);
        $this->assertFalse($bundle['checklist_complete']);
        $this->assertSame(7, $bundle['checklist_met_count']);
        $this->assertContains('trust_ledger_below_threshold', $bundle['blockers']);
        // The single missing item produces exactly one blocker.
        $this->assertSame(['trust_ledger_below_threshold'], $bundle['blockers']);
    }

    public function testFalseChecklistItemAlsoBlocksWithItsNamedBlocker(): void
    {
        $checklist = $this->fullyGreenChecklist();
        $checklist['self_construction_proven'] = ['met' => false, 'evidence_ref' => 'evidence://l7/self_construction_proven/nine_only'];

        $bundle = $this->service->build(['checklist' => $checklist]);

        $this->assertSame('blocked_not_l7', $bundle['final_status']);
        $this->assertSame(['self_construction_proposals_incomplete'], $bundle['blockers']);
        $this->assertSame(7, $bundle['checklist_met_count']);
        // An unmet item never leaks its ref into the proof evidence list.
        $this->assertNotContains('evidence://l7/self_construction_proven/nine_only', $bundle['evidence_refs']);
    }

    public function testEmptyInputBlocksWithAllEightBlockersInCanonicalOrder(): void
    {
        $bundle = $this->service->build([]);

        $this->assertSame('blocked_not_l7', $bundle['final_status']);
        $this->assertSame(0, $bundle['checklist_met_count']);
        $this->assertSame([], $bundle['evidence_refs']);
        $this->assertSame(
            [
                'loop_not_real_tier_scan_only',
                'ten_consecutive_cycles_missing',
                'compounding_flywheel_unproven',
                'departments_below_l4',
                'http_path_or_mission_control_missing',
                'self_construction_proposals_incomplete',
                'trust_ledger_below_threshold',
                'promotion_receipt_missing',
            ],
            $bundle['blockers'],
        );
    }

    public function testProofSummaryPathOnlyWrittenWhenCalledByCommandLayer(): void
    {
        $withoutCommand = $this->service->build([
            'checklist' => $this->fullyGreenChecklist(),
        ]);

        $this->assertFalse($withoutCommand['summary_written']);
        $this->assertSame('', $withoutCommand['summary_path']);

        $withCommand = $this->service->build([
            'checklist' => $this->fullyGreenChecklist(),
            'called_by_command_layer' => true,
        ]);

        $this->assertTrue($withCommand['summary_written']);
        $this->assertNotSame('', $withCommand['summary_path']);
        $this->assertSame(
            'docs/engineering-knowledge-base/proof-bundles/'.$withCommand['run_id'].'.md',
            $withCommand['summary_path'],
        );
    }

    public function testProductModeSummaryStatesBlockersHonestlyWithNoFalseAllGreen(): void
    {
        $checklist = $this->fullyGreenChecklist();
        unset($checklist['departments_l4'], $checklist['trust_ledger_stable']);

        $bundle = $this->service->build(['checklist' => $checklist]);
        $summary = $bundle['product_mode_summary'];

        $this->assertFalse($summary['all_green']);
        $this->assertSame('blocked_not_l7', $summary['final_status']);
        $this->assertSame(6, $summary['items_met']);
        $this->assertSame(8, $summary['items_total']);
        $this->assertSame(
            ['departments_below_l4', 'trust_ledger_below_threshold'],
            $summary['blockers'],
        );
        $this->assertSame($bundle['blockers'], $summary['blockers']);
        $this->assertStringContainsString('Not L7 yet', $summary['headline']);
    }

    public function testProductModeSummaryAllGreenOnlyWhenZeroBlockers(): void
    {
        $bundle = $this->service->build([
            'checklist' => $this->fullyGreenChecklist(),
        ]);
        $summary = $bundle['product_mode_summary'];

        $this->assertTrue($summary['all_green']);
        $this->assertSame([], $summary['blockers']);
        $this->assertSame(8, $summary['items_met']);
        $this->assertStringContainsString('L7 reached', $summary['headline']);
    }

    public function testBareBooleanTrueCountsAsMetAndDerivesDefaultEvidenceRef(): void
    {
        $checklist = $this->fullyGreenChecklist();
        $checklist['flywheel_proven'] = true;

        $bundle = $this->service->build(['checklist' => $checklist]);

        $this->assertSame('l7_ready', $bundle['final_status']);
        $this->assertContains('evidence://l7/flywheel_proven/met', $bundle['evidence_refs']);
    }

    public function testEvidenceRefsAndBlockersHonorListStringContract(): void
    {
        $checklist = $this->fullyGreenChecklist();
        unset($checklist['http_path_mission_control']);

        $bundle = $this->service->build(['checklist' => $checklist]);

        $this->assertIsList($bundle['evidence_refs']);
        $this->assertIsList($bundle['blockers']);
        foreach ($bundle['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
        }
        foreach ($bundle['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        $this->assertIsInt($bundle['checklist_met_count']);
    }

    public function testIdenticalInputsAreDeterministicAndDistinctOutcomesDiffer(): void
    {
        $green = $this->fullyGreenChecklist();

        $first = $this->service->build(['checklist' => $green]);
        $second = $this->service->build(['checklist' => $green]);
        $this->assertSame($first, $second);

        $partial = $green;
        unset($partial['promotion_recorded']);
        $blocked = $this->service->build(['checklist' => $partial]);

        // A different checklist outcome must produce a different run_id (no canned id).
        $this->assertNotSame($first['run_id'], $blocked['run_id']);
    }
}
