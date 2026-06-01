<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasEngineeringBlueprintMaturityDodService;
use Tests\TestCase;

/**
 * Pins the documented Engineering Blueprint Maturity And DoD rules: the fixed
 * 8-stage Final DoD pipeline (sequential, evidence-gated, no hidden chat
 * context), and the 5-area Remaining Product Maturity table.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
 */
class AtlasEngineeringBlueprintMaturityDodTest extends TestCase
{
    private function service(): AtlasEngineeringBlueprintMaturityDodService
    {
        return new AtlasEngineeringBlueprintMaturityDodService();
    }

    /**
     * Build a fully-proven set of the 8 DoD stages, each with verifiable
     * evidence and no hidden chat context.
     *
     * @return array<string,array<string,mixed>>
     */
    private function allStagesProven(): array
    {
        $stages = [];
        foreach (array_keys(AtlasEngineeringBlueprintMaturityDodService::DOD_STAGES) as $key) {
            $stages[$key] = [
                'passed' => true,
                'evidence' => ['receipt:' . $key],
                'hidden_chat_context' => false,
            ];
        }

        return $stages;
    }

    /**
     * Build a fully-cleared set of the 5 maturity areas, each with evidence.
     *
     * @return array<string,array<string,mixed>>
     */
    private function allAreasCleared(): array
    {
        $areas = [];
        foreach (array_keys(AtlasEngineeringBlueprintMaturityDodService::MATURITY_AREAS) as $key) {
            $areas[$key] = ['cleared' => true, 'evidence' => ['report:' . $key]];
        }

        return $areas;
    }

    /**
     * Doc "Final DoD Rule": the system is complete only when ALL eight stages
     * of the new-AI-session path pass with evidence and no hidden chat context.
     */
    public function test_all_stages_proven_is_dod_complete(): void
    {
        $r = $this->service()->evaluateDod(['stages' => $this->allStagesProven()]);

        $this->assertSame(AtlasEngineeringBlueprintMaturityDodService::DOD_COMPLETE, $r['verdict']);
        $this->assertTrue($r['is_complete']);
        $this->assertSame(8, $r['total_stages']);
        $this->assertSame(8, $r['passed_count']);
        $this->assertNull($r['blocking_stage']);
        $this->assertSame([], $r['pending_stages']);
    }

    /**
     * The pipeline is sequential: the EARLIEST not-proven stage (in doc order)
     * is the blocking stage. Here every stage is proven except the 6th
     * (evidence_collected), so it must be named as the blocker — not a later one.
     */
    public function test_sequential_pipeline_names_earliest_blocking_stage(): void
    {
        $stages = $this->allStagesProven();
        unset($stages['evidence_collected']); // remove the 6th stage's proof

        $r = $this->service()->evaluateDod(['stages' => $stages]);

        $this->assertSame(AtlasEngineeringBlueprintMaturityDodService::DOD_INCOMPLETE, $r['verdict']);
        $this->assertFalse($r['is_complete']);
        $this->assertSame('evidence_collected', $r['blocking_stage']);
        $this->assertSame('missing', $r['blocking_reason']);
        $this->assertSame(7, $r['passed_count']);
        $this->assertContains('evidence_collected', $r['pending_stages']);
    }

    /**
     * Evidence gate (doc forbidden_changes + maintenance): a stage asserted
     * passed but carrying NO verifiable evidence does NOT count as passed. A bare
     * boolean `true` is not proof. So "all stages = true" is still incomplete.
     */
    public function test_bare_true_without_evidence_does_not_satisfy_dod(): void
    {
        $stages = [];
        foreach (array_keys(AtlasEngineeringBlueprintMaturityDodService::DOD_STAGES) as $key) {
            $stages[$key] = true; // asserted, but no evidence
        }

        $r = $this->service()->evaluateDod(['stages' => $stages]);

        $this->assertFalse($r['is_complete']);
        $this->assertSame(0, $r['passed_count']);
        $this->assertSame('objective_intake', $r['blocking_stage']);
        $this->assertSame('no_evidence', $r['blocking_reason']);
    }

    /**
     * Doc "without relying on hidden chat context": a stage that passed with
     * evidence but leaned on out-of-band chat context is NOT satisfied, and is
     * flagged as a hidden-chat-context violation.
     */
    public function test_hidden_chat_context_blocks_a_stage(): void
    {
        $stages = $this->allStagesProven();
        $stages['memory_promoted'] = [
            'passed' => true,
            'evidence' => ['receipt:memory_promoted'],
            'hidden_chat_context' => true,
        ];

        $r = $this->service()->evaluateDod(['stages' => $stages]);

        $this->assertFalse($r['is_complete']);
        $this->assertSame('memory_promoted', $r['blocking_stage']);
        $this->assertSame('hidden_chat_context', $r['blocking_reason']);
        $this->assertContains('memory_promoted', $r['hidden_chat_context_violations']);
    }

    /**
     * Doc "Remaining Product Maturity" table + decision "strong operational
     * base, not the final mature product": with no area cleared the product is
     * operational_base and all five areas remain.
     */
    public function test_no_area_cleared_is_operational_base_with_five_remaining(): void
    {
        $r = $this->service()->evaluateProductMaturity(['areas' => []]);

        $this->assertSame(AtlasEngineeringBlueprintMaturityDodService::MATURITY_OPERATIONAL_BASE, $r['verdict']);
        $this->assertFalse($r['is_mature']);
        $this->assertSame(5, $r['total_areas']);
        $this->assertSame(0, $r['cleared_count']);
        $this->assertEqualsCanonicalizing(
            ['ux', 'missing_evidence', 'wireframes', 'calibration', 'postgres'],
            $r['remaining_areas'],
        );
    }

    /**
     * Full DONE requires BOTH the Final DoD Rule complete AND all maturity areas
     * cleared. Proving the DoD pipeline alone leaves the product an operational
     * base (not yet mature); proving both flips overall_state to "done".
     */
    public function test_system_done_requires_both_dod_and_maturity(): void
    {
        $service = $this->service();

        // DoD complete but maturity not cleared => operational_base, not done.
        $dodOnly = $service->assess(['stages' => $this->allStagesProven(), 'areas' => []]);
        $this->assertTrue($dodOnly['dod']['is_complete']);
        $this->assertFalse($dodOnly['product_maturity']['is_mature']);
        $this->assertFalse($dodOnly['system_done']);
        $this->assertSame('operational_base', $dodOnly['overall_state']);

        // Both proven => done.
        $both = $service->assess([
            'stages' => $this->allStagesProven(),
            'areas' => $this->allAreasCleared(),
        ]);
        $this->assertTrue($both['system_done']);
        $this->assertSame('done', $both['overall_state']);
    }
}
