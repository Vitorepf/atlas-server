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

    // ── AC2: proxy scaffolds and evidence floor block net-new ────────────────

    public function test_proxy_scaffolds_trigger_consolidation(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('api', 0.8)],
            ['api' => array_merge($this->cleanDebt(), ['proxy_scaffold_count' => 2])],
        );

        self::assertSame(
            AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST,
            $result['verdict'],
        );
        self::assertStringContainsString('proxy_scaffolds', implode(' ', $result['consolidation_triggers']));
    }

    public function test_below_evidence_floor_triggers_conservative_check(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('core', 0.8)],
            ['core' => array_merge($this->cleanDebt(), ['evidence_strength' => 0.3])],
            ['evidence_floor' => 0.5],
        );

        self::assertSame(
            AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST,
            $result['verdict'],
        );
        self::assertStringContainsString('below_evidence_floor', implode(' ', $result['consolidation_triggers']));
    }

    // ── AC3: critical unblock + cleanup follow-through overrides ─────────────

    public function test_critical_capability_unblock_with_cleanup_link_admits_despite_triggers(): void
    {
        $result = $this->gate()->evaluate(
            [[
                'id' => 'critical-task',
                'area' => 'core',
                'capability_score' => 0.9,
                'unblocks_critical_capability' => true,
                'cleanup_follow_through_link' => 'task-fabric://refactor/cleanup/core',
            ]],
            ['core' => array_merge($this->cleanDebt(), ['duplicate_organs' => 3])],
        );

        self::assertSame(
            AtlasTaskFabricConsolidationFirstGate::VERDICT_ACCEPT,
            $result['verdict'],
        );
        self::assertStringContainsString('cleanup_follow_through', implode(' ', $result['reasons']));
    }

    public function test_critical_unblock_without_cleanup_link_still_consolidates(): void
    {
        $result = $this->gate()->evaluate(
            [[
                'id' => 'critical-task',
                'area' => 'core',
                'capability_score' => 0.9,
                'unblocks_critical_capability' => true,
            ]],
            ['core' => array_merge($this->cleanDebt(), ['duplicate_organs' => 3])],
        );

        self::assertSame(
            AtlasTaskFabricConsolidationFirstGate::VERDICT_CONSOLIDATE_FIRST,
            $result['verdict'],
        );
    }

    // ── AC4: consolidation_first_recommendation in rejected output ──────────

    public function test_consolidation_first_recommendation_present_on_rejection(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('core', 0.8)],
            ['core' => array_merge($this->cleanDebt(), ['duplicate_organs' => 2, 'orphaned_integrations' => 1])],
        );

        self::assertArrayHasKey('consolidation_first_recommendation', $result);
        $rec = $result['consolidation_first_recommendation'];
        self::assertArrayHasKey('target_family', $rec);
        self::assertArrayHasKey('deletion_candidate_count', $rec);
        self::assertArrayHasKey('next_refactor_task_shape', $rec);
        self::assertSame('core', $rec['target_family']);
        self::assertGreaterThan(0, $rec['deletion_candidate_count']);
    }

    public function test_consolidation_first_recommendation_absent_on_accept(): void
    {
        $result = $this->gate()->evaluate(
            [$this->task('memory', 0.75)],
            ['memory' => $this->cleanDebt()],
        );

        self::assertArrayNotHasKey('consolidation_first_recommendation', $result);
    }
}
