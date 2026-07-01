<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricConsolidationFirstGate;
use Tests\TestCase;

/**
 * The external brain cannot keep adding organs into an area that should be simplified first:
 * verdict accept requires at least one task with positive capability_score AND no touched area
 * crossing duplicate_organs/orphaned_integrations/backlog_cost/forecast_confidence thresholds;
 * empty and zero-value batches consolidate_first; triggers and reasons are deterministic.
 */
final class AtlasTaskFabricConsolidationFirstGateTest extends TestCase
{
    private function gate(): AtlasTaskFabricConsolidationFirstGate
    {
        return new AtlasTaskFabricConsolidationFirstGate();
    }

    private function task(string $area, float $score = 0.8): array
    {
        return ['id' => 'task-'.$area, 'area' => $area, 'capability_score' => $score];
    }

    private function cleanDebt(): array
    {
        return [
            'duplicate_organs' => 0,
            'orphaned_integrations' => 0,
            'backlog_cost' => 0.0,
            'forecast_confidence' => 1.0,
        ];
    }

    public function test_accept_requires_positive_capability_score_and_no_triggers(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('memory', 0.75)],
            ['memory' => $this->cleanDebt()],
        );

        self::assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT, $result['verdict']);
        self::assertSame([], $result['consolidation_triggers']);
    }

    public function test_accept_is_impossible_without_positive_capability_score_even_when_area_is_clean(): void
    {
        $result = $this->gate()->evaluate(
            [['id' => 'x', 'area' => 'core', 'capability_score' => 0.0]],
            ['core' => $this->cleanDebt()],
        );

        self::assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_empty_batch_consolidates_first(): void
    {
        $result = $this->gate()->evaluate([], []);

        self::assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_zero_value_batch_consolidates_first(): void
    {
        $result = $this->gate()->evaluate(
            [['id' => 'x', 'area' => 'core']],
            ['core' => $this->cleanDebt()],
        );

        self::assertSame(AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST, $result['verdict']);
    }

    public function test_any_single_debt_threshold_family_blocks_accept_despite_positive_value(): void
    {
        foreach ([
            'duplicate_organs' => 2,
            'orphaned_integrations' => 3,
            'backlog_cost' => 25.0,
            'forecast_confidence' => 0.39,
        ] as $field => $value) {
            $result = $this->gate()->evaluate(
                [$this->task('area-'.$field, 0.9)],
                ['area-'.$field => array_merge($this->cleanDebt(), [$field => $value])],
            );

            self::assertSame(
                AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST,
                $result['verdict'],
                "threshold family {$field} must block accept"
            );
        }
    }

    public function test_consolidation_triggers_are_deterministic_and_unique_across_all_families(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('core', 0.8)],
            ['core' => [
                'duplicate_organs' => 3,
                'orphaned_integrations' => 5,
                'backlog_cost' => 30.0,
                'forecast_confidence' => 0.2,
            ]],
        );

        self::assertSame(
            ['duplicate_organs:core', 'high_backlog_cost:core', 'low_forecast_confidence:core', 'orphaned_integrations:core'],
            $result['consolidation_triggers'],
        );
        self::assertSame($result['consolidation_triggers'], array_unique($result['consolidation_triggers']));
    }

    public function test_identical_input_produces_identical_output(): void
    {
        $batch = [$this->task('memory', 0.5)];
        $debt = ['memory' => array_merge($this->cleanDebt(), ['duplicate_organs' => 2])];

        self::assertSame(
            $this->gate()->evaluate($batch, $debt),
            $this->gate()->evaluate($batch, $debt),
        );
    }
}
