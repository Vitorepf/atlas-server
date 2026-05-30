<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceSelectionService;
use PHPUnit\Framework\TestCase;

final class PlanSliceSelectionServiceTest extends TestCase
{
    private function service(): PlanSliceSelectionService
    {
        return new PlanSliceSelectionService;
    }

    /**
     * @param  list<array{id:string,seq:int,depends_on?:list<string>}>  $specs
     * @return array<string,mixed>
     */
    private function plan(array $specs): array
    {
        $slices = [];
        foreach ($specs as $s) {
            $slices[] = [
                'slice_id' => $s['id'],
                'sequence' => $s['seq'],
                'depends_on' => $s['depends_on'] ?? [],
                'finding_id' => $s['id'],
            ];
        }

        return ['plan_id' => 'p1', 'slices' => $slices];
    }

    /**
     * @param  array<string,array{state:string,dependency_satisfied?:bool}>  $states
     * @return array<string,mixed>
     */
    private function rollup(array $states): array
    {
        $sliceStates = [];
        foreach ($states as $sid => $row) {
            $sliceStates[$sid] = [
                'slice_id' => $sid,
                'state' => $row['state'],
                'dependency_satisfied' => $row['dependency_satisfied'] ?? true,
            ];
        }

        return ['slice_states' => $sliceStates];
    }

    public function test_empty_plan_is_empty_plan_kind(): void
    {
        $sel = $this->service()->selectNext(['plan_id' => 'p1', 'slices' => []], []);
        $this->assertSame(PlanSliceSelectionService::KIND_EMPTY_PLAN, $sel['kind']);
        $this->assertNull($sel['slice']);
    }

    public function test_fresh_plan_picks_earliest_sequence_with_no_deps(): void
    {
        $plan = $this->plan([
            ['id' => 'S2', 'seq' => 2, 'depends_on' => ['S1']],
            ['id' => 'S1', 'seq' => 1],
        ]);
        $sel = $this->service()->selectNext($plan, []);
        $this->assertSame(PlanSliceSelectionService::KIND_SLICE_READY, $sel['kind']);
        $this->assertSame('S1', $sel['slice_id']);
    }

    public function test_advances_to_next_when_dependency_delivered(): void
    {
        $plan = $this->plan([
            ['id' => 'S1', 'seq' => 1],
            ['id' => 'S2', 'seq' => 2, 'depends_on' => ['S1']],
        ]);
        $rollup = $this->rollup([
            'S1' => ['state' => 'delivered'],
            'S2' => ['state' => 'planned', 'dependency_satisfied' => true],
        ]);
        $sel = $this->service()->selectNext($plan, $rollup);
        $this->assertSame(PlanSliceSelectionService::KIND_SLICE_READY, $sel['kind']);
        $this->assertSame('S2', $sel['slice_id']);
    }

    public function test_all_delivered_is_plan_complete(): void
    {
        $plan = $this->plan([['id' => 'S1', 'seq' => 1], ['id' => 'S2', 'seq' => 2]]);
        $rollup = $this->rollup(['S1' => ['state' => 'delivered'], 'S2' => ['state' => 'delivered']]);
        $sel = $this->service()->selectNext($plan, $rollup);
        $this->assertSame(PlanSliceSelectionService::KIND_PLAN_COMPLETE, $sel['kind']);
    }

    public function test_unsatisfied_dependency_is_not_picked(): void
    {
        $plan = $this->plan([
            ['id' => 'S1', 'seq' => 1],
            ['id' => 'S2', 'seq' => 2, 'depends_on' => ['S1']],
        ]);
        // S1 stuck in_progress (not delivered) => S2 dep not satisfied, S1 not ready (in_progress IS pickable),
        // so S1 should be picked, never S2.
        $rollup = $this->rollup([
            'S1' => ['state' => 'in_progress', 'dependency_satisfied' => true],
            'S2' => ['state' => 'planned', 'dependency_satisfied' => false],
        ]);
        $sel = $this->service()->selectNext($plan, $rollup);
        $this->assertSame('S1', $sel['slice_id']);
    }

    public function test_hard_blocked_slice_with_no_other_ready_is_blocked(): void
    {
        $plan = $this->plan([
            ['id' => 'S1', 'seq' => 1],
            ['id' => 'S2', 'seq' => 2, 'depends_on' => ['S1']],
        ]);
        $rollup = $this->rollup([
            'S1' => ['state' => 'blocked', 'dependency_satisfied' => true],
            'S2' => ['state' => 'planned', 'dependency_satisfied' => false],
        ]);
        $sel = $this->service()->selectNext($plan, $rollup);
        $this->assertSame(PlanSliceSelectionService::KIND_BLOCKED, $sel['kind']);
        $this->assertStringContainsString('slice_blocked:S1', (string) $sel['reason']);
    }
}
