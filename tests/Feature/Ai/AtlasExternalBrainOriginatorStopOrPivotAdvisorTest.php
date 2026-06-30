<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorStopOrPivotAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOriginatorStopOrPivotAdvisorTest extends TestCase
{
    private function advisor(): AtlasExternalBrainOriginatorStopOrPivotAdvisor
    {
        return new AtlasExternalBrainOriginatorStopOrPivotAdvisor;
    }

    private function healthyTask(array $overrides = []): array
    {
        return array_merge([
            'task_id' => 'task-1',
            'theme' => 'theme-a',
            'target_family' => 'family-a',
            'capability_type' => 'cap-a',
            'allowed_files' => ['app/Services/Foo.php'],
            'structural_gates_passed' => true,
            'has_runnable_proof' => true,
            'impact_score' => 0.9,
            'evidence_strength' => 0.9,
            'impact_class' => 'task_fabric',
            'new_prerequisite_unlock' => true,
            'distinct_impact_class' => 'task_fabric',
        ], $overrides);
    }

    private function diverseTasks(): array
    {
        return array_map(
            fn (string $class) => $this->healthyTask(['task_id' => "task-{$class}", 'theme' => "theme-{$class}", 'impact_class' => $class, 'distinct_impact_class' => $class]),
            \App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOriginatorImpactDiversityReport::IMPACT_CLASSES,
        );
    }

    // ── output shape ───────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->advisor()->advise([]);

        foreach (['schema', 'next_action', 'reasons', 'evidence', 'inputs', 'mutates_queue'] as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::SCHEMA, $result['schema']);
        $this->assertFalse($result['mutates_queue']);
    }

    public function test_next_action_is_always_one_of_the_five_canonical_actions(): void
    {
        $result = $this->advisor()->advise([]);

        $this->assertContains($result['next_action'], [
            AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_KEEP_ORIGINATING,
            AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_PIVOT_TO_GAP,
            AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_RESEARCH_BEFORE_ORIGINATING,
            AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_CONSOLIDATE_EXISTING_QUEUE,
            AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_STOP_DUE_TO_LOW_VALUE,
        ]);
    }

    // ── AC3: refuses keep_originating when queue sufficient + saturated/low-novelty ──

    public function test_refuses_keep_originating_when_queue_sufficient_and_theme_saturated(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 10, 'target_min_claimable' => 3, 'active_leases' => 2, 'servable_now' => 10],
            'recent_batch_tasks' => [$this->healthyTask(), $this->healthyTask(['task_id' => 'task-2'])],
            'theme_recent_tasks' => array_fill(0, 5, ['theme' => 'same-theme']),
            'diversity_tasks' => $this->diverseTasks(),
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_STOP_DUE_TO_LOW_VALUE, $result['next_action']);
        $this->assertNotSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_KEEP_ORIGINATING, $result['next_action']);
    }

    public function test_refuses_keep_originating_when_queue_sufficient_and_diversity_low(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 10, 'target_min_claimable' => 3, 'active_leases' => 2, 'servable_now' => 10],
            'recent_batch_tasks' => [$this->healthyTask(), $this->healthyTask(['task_id' => 'task-2'])],
            'theme_recent_tasks' => [
                ['theme' => 'theme-a'],
                ['theme' => 'theme-b'],
            ],
            'diversity_tasks' => [
                ['task_packet_id' => 't1', 'impact_class' => 'task_fabric'],
                ['task_packet_id' => 't2', 'impact_class' => 'task_fabric'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_STOP_DUE_TO_LOW_VALUE, $result['next_action']);
    }

    // ── keep_originating: queue below target, healthy diverse batch ──────────

    public function test_keep_originating_when_queue_below_target_and_batch_healthy_and_diverse(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 2, 'servable_now' => 1],
            'recent_batch_tasks' => [$this->healthyTask(), $this->healthyTask(['task_id' => 'task-2', 'theme' => 'theme-b'])],
            'theme_recent_tasks' => [
                ['theme' => 'theme-a'],
                ['theme' => 'theme-b'],
                ['theme' => 'theme-c'],
            ],
            'diversity_tasks' => $this->diverseTasks(),
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_KEEP_ORIGINATING, $result['next_action']);
    }

    // ── research_before_originating: batch auditor says stop_and_research ────

    public function test_research_before_originating_when_batch_value_auditor_says_stop_and_research(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 1, 'servable_now' => 1],
            'recent_batch_tasks' => [
                $this->healthyTask(['has_runnable_proof' => false]),
                $this->healthyTask(['task_id' => 'task-2', 'has_runnable_proof' => false]),
            ],
            'diversity_tasks' => $this->diverseTasks(),
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_RESEARCH_BEFORE_ORIGINATING, $result['next_action']);
    }

    // ── pivot_to_gap: unresolved high-priority gaps + queue not yet sufficient ──

    public function test_pivot_to_gap_when_unresolved_gaps_and_queue_not_sufficient(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 1, 'servable_now' => 1],
            'recent_batch_tasks' => [$this->healthyTask()],
            'diversity_tasks' => [
                ['task_packet_id' => 't1', 'impact_class' => 'task_fabric'],
            ],
            'high_priority_classes' => ['task_fabric', 'evidence_integrity'],
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_PIVOT_TO_GAP, $result['next_action']);
        $this->assertContains('evidence_integrity', $result['inputs']['unresolved_high_priority_gaps']);
    }

    // ── consolidate_existing_queue: high duplicate risk ───────────────────────

    public function test_consolidate_existing_queue_when_duplicate_risk_high(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 1, 'servable_now' => 1],
            'recent_batch_tasks' => [$this->healthyTask()],
            'diversity_tasks' => $this->diverseTasks(),
            'duplicate_risk_ratio' => 0.9,
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_CONSOLIDATE_EXISTING_QUEUE, $result['next_action']);
    }

    // ── consolidate_existing_queue: theme saturated, no gap to pivot to ───────

    public function test_consolidate_existing_queue_when_saturated_with_no_unresolved_gap(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 1, 'servable_now' => 1],
            'recent_batch_tasks' => [$this->healthyTask()],
            'theme_recent_tasks' => array_fill(0, 5, ['theme' => 'same-theme']),
            'diversity_tasks' => $this->diverseTasks(),
        ]);

        $this->assertSame(AtlasExternalBrainOriginatorStopOrPivotAdvisor::ACTION_CONSOLIDATE_EXISTING_QUEUE, $result['next_action']);
    }

    // ── evidence cites every combined factor ──────────────────────────────────

    public function test_evidence_cites_all_seven_combined_factors(): void
    {
        $result = $this->advisor()->advise([
            'queue' => ['claimable_depth' => 4, 'target_min_claimable' => 3, 'active_leases' => 2, 'servable_now' => 4],
            'recent_batch_tasks' => [$this->healthyTask()],
            'diversity_tasks' => $this->diverseTasks(),
        ]);

        $evidenceText = implode('|', $result['evidence']);
        $this->assertStringContainsString('claimable_depth', $evidenceText);
        $this->assertStringContainsString('drain_rate_low', $evidenceText);
        $this->assertStringContainsString('batch_recommendation', $evidenceText);
        $this->assertStringContainsString('theme_saturation_high', $evidenceText);
        $this->assertStringContainsString('impact_diversity_score', $evidenceText);
        $this->assertStringContainsString('duplicate_risk', $evidenceText);
        $this->assertStringContainsString('unresolved_high_priority_gaps', $evidenceText);
    }

    // ── determinism ────────────────────────────────────────────────────────

    public function test_advise_is_deterministic(): void
    {
        $facts = [
            'queue' => ['claimable_depth' => 1, 'target_min_claimable' => 5, 'active_leases' => 1, 'servable_now' => 1],
            'recent_batch_tasks' => [$this->healthyTask()],
            'diversity_tasks' => $this->diverseTasks(),
        ];

        $a = $this->advisor()->advise($facts);
        $b = $this->advisor()->advise($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
