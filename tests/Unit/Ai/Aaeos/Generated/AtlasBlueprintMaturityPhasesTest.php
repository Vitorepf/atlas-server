<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasBlueprintMaturityPhasesService;
use Tests\TestCase;

/**
 * Pins the documented Engineering Blueprint Maturity Phases rules: the seven
 * items ordinal-state table (weakest item is the floor), the sequential 0..8
 * phase ladder (earliest undelivered phase is the frontier blocker), the seven
 * Final DoD criteria, and the evidence gate (no bare-boolean inflation).
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
 */
class AtlasBlueprintMaturityPhasesTest extends TestCase
{
    private function service(): AtlasBlueprintMaturityPhasesService
    {
        return new AtlasBlueprintMaturityPhasesService();
    }

    /**
     * Build an evidenced delivery map for every phase 0..8.
     *
     * @return array<int,array<string,mixed>>
     */
    private function allPhasesDelivered(): array
    {
        $phases = [];
        foreach (array_keys(AtlasBlueprintMaturityPhasesService::PHASES) as $i) {
            $phases[$i] = ['delivered' => true, 'evidence' => ['receipt:phase-' . $i]];
        }

        return $phases;
    }

    /**
     * Doc "Seven Items": the baseline carries the doc's exact current states.
     * The Contingency policy is the only "Partial" row, so it is the maturity
     * floor; nothing is "complete" because every item still lists remaining
     * work / sits below the top state. Verdict: operational base.
     */
    public function test_baseline_seven_items_floor_is_partial_contingency_policy(): void
    {
        $r = $this->service()->evaluateSevenItems(['items' => []]);

        $this->assertSame(7, $r['total_items']);
        $this->assertSame(0, $r['complete_count']);
        $this->assertFalse($r['all_complete']);
        $this->assertSame(AtlasBlueprintMaturityPhasesService::STATE_PARTIAL, $r['floor_state']);
        $this->assertSame(['contingency_policy'], $r['floor_items']);
    }

    /**
     * Doc decision "measured by executable flow, not documentation alone": an
     * item claiming state=complete but still listing remaining work is NOT
     * complete. Only a complete state with zero remaining work clears the item.
     */
    public function test_item_is_not_complete_while_remaining_work_exists(): void
    {
        $withRemaining = $this->service()->evaluateSevenItems([
            'items' => [
                'contingency_policy' => [
                    'state' => 'complete',
                    'remaining_work' => ['automatic blocking still open'],
                ],
            ],
        ]);
        $this->assertFalse($withRemaining['items']['contingency_policy']['complete']);
        $this->assertContains('contingency_policy', $withRemaining['incomplete_items']);

        $cleared = $this->service()->evaluateSevenItems([
            'items' => [
                'contingency_policy' => ['state' => 'complete', 'remaining_work' => []],
            ],
        ]);
        $this->assertTrue($cleared['items']['contingency_policy']['complete']);
    }

    /**
     * Doc "Phases" ladder is sequential: the frontier is the highest CONTIGUOUS
     * delivered phase. Deliver 0,1,2 with evidence but leave 3 missing while a
     * LATER phase (5) is delivered — the gap must not be skipped: frontier=2 and
     * the next phase to work is 3, not 5.
     */
    public function test_phase_ladder_frontier_does_not_skip_a_gap(): void
    {
        $r = $this->service()->evaluatePhases([
            'phases' => [
                0 => ['delivered' => true, 'evidence' => ['r0']],
                1 => ['delivered' => true, 'evidence' => ['r1']],
                2 => ['delivered' => true, 'evidence' => ['r2']],
                // 3 missing on purpose
                5 => ['delivered' => true, 'evidence' => ['r5']],
            ],
        ]);

        $this->assertSame(9, $r['total_phases']);
        $this->assertSame(8, $r['max_phase']);
        $this->assertSame(2, $r['frontier_phase']);
        $this->assertSame(3, $r['next_phase']);
        $this->assertSame('Inventory, scenarios and wireframes.', $r['next_goal']);
        $this->assertFalse($r['all_delivered']);
        // 0,1,2,5 delivered = 4 total even though only 0..2 are contiguous.
        $this->assertSame(4, $r['delivered_count']);
    }

    /**
     * Evidence gate (doc forbidden_changes): a bare boolean `true` is NOT proof
     * for a phase. All phases set to bare true => none delivered, frontier=-1,
     * next phase = 0 (Canonical documentation).
     */
    public function test_bare_true_phase_is_not_delivered(): void
    {
        $phases = [];
        foreach (array_keys(AtlasBlueprintMaturityPhasesService::PHASES) as $i) {
            $phases[$i] = true; // asserted, but no evidence
        }

        $r = $this->service()->evaluatePhases(['phases' => $phases]);

        $this->assertSame(0, $r['delivered_count']);
        $this->assertSame(-1, $r['frontier_phase']);
        $this->assertSame(0, $r['next_phase']);
        $this->assertSame('Canonical documentation.', $r['next_goal']);
    }

    /**
     * Doc "Final DoD": seven criteria. With nothing proven none are met; an
     * evidenced subset is counted exactly (no inflation from a bare boolean).
     */
    public function test_final_dod_counts_only_evidenced_criteria(): void
    {
        $empty = $this->service()->evaluateFinalDod(['dod' => []]);
        $this->assertSame(7, $empty['total_criteria']);
        $this->assertSame(0, $empty['met_count']);
        $this->assertFalse($empty['all_met']);

        $partial = $this->service()->evaluateFinalDod([
            'dod' => [
                'blueprint_lifecycle' => ['met' => true, 'evidence' => ['cmd:atlas:project:blueprint']],
                'strong_task_contracts' => true, // bare boolean => not counted
                'auditable_evidence' => ['met' => true, 'evidence' => []], // no evidence => not counted
            ],
        ]);
        $this->assertSame(1, $partial['met_count']);
        $this->assertContains('strong_task_contracts', $partial['unmet_criteria']);
        $this->assertContains('auditable_evidence', $partial['unmet_criteria']);
    }

    /**
     * Full DONE requires ALL three: every DoD criterion met, every item complete,
     * AND every phase delivered. Proving phases + DoD while items stay at the
     * documented baseline must remain an operational base — never "done".
     */
    public function test_system_done_requires_items_phases_and_dod(): void
    {
        $service = $this->service();

        $allDod = [];
        foreach (array_keys(AtlasBlueprintMaturityPhasesService::DOD_CRITERIA) as $k) {
            $allDod[$k] = ['met' => true, 'evidence' => ['receipt:' . $k]];
        }
        $allItems = [];
        foreach (array_keys(AtlasBlueprintMaturityPhasesService::SEVEN_ITEMS) as $k) {
            $allItems[$k] = ['state' => 'complete', 'remaining_work' => []];
        }

        // Phases + DoD proven, but items left at baseline => operational base.
        $itemsBaseline = $service->assess([
            'items' => [],
            'phases' => $this->allPhasesDelivered(),
            'dod' => $allDod,
        ]);
        $this->assertTrue($itemsBaseline['phases']['all_delivered']);
        $this->assertTrue($itemsBaseline['final_dod']['all_met']);
        $this->assertFalse($itemsBaseline['seven_items']['all_complete']);
        $this->assertFalse($itemsBaseline['is_done']);
        $this->assertSame(
            AtlasBlueprintMaturityPhasesService::VERDICT_OPERATIONAL_BASE,
            $itemsBaseline['verdict'],
        );

        // All three proven => done.
        $done = $service->assess([
            'items' => $allItems,
            'phases' => $this->allPhasesDelivered(),
            'dod' => $allDod,
        ]);
        $this->assertTrue($done['is_done']);
        $this->assertSame(AtlasBlueprintMaturityPhasesService::VERDICT_DONE, $done['verdict']);
    }
}
