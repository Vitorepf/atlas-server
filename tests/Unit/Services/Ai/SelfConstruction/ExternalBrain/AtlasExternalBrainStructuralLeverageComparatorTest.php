<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStructuralLeverageComparator;
use Tests\TestCase;

final class AtlasExternalBrainStructuralLeverageComparatorTest extends TestCase
{
    private function comparator(): AtlasExternalBrainStructuralLeverageComparator
    {
        return new AtlasExternalBrainStructuralLeverageComparator;
    }

    // ── AC: closed_loop_learning, autonomy_repair, collision_prevention outrank cosmetic wrappers and raw task-count expansion ──

    public function test_closed_loop_learning_outranks_cosmetic_wrapper(): void
    {
        $result = $this->comparator()->rank([
            ['task_id' => 'wrapper', 'leverage_category' => 'cosmetic_wrapper'],
            ['task_id' => 'learning', 'leverage_category' => 'closed_loop_learning'],
        ]);

        $this->assertSame('learning', $result['ranked'][0]['task_id']);
        $this->assertSame('wrapper', $result['ranked'][1]['task_id']);
    }

    public function test_autonomy_repair_outranks_raw_task_count_expansion(): void
    {
        $result = $this->comparator()->rank([
            ['task_id' => 'expansion', 'leverage_category' => 'raw_task_count_expansion'],
            ['task_id' => 'repair', 'leverage_category' => 'autonomy_repair'],
        ]);

        $this->assertSame('repair', $result['ranked'][0]['task_id']);
    }

    public function test_collision_prevention_outranks_cosmetic_wrapper(): void
    {
        $result = $this->comparator()->rank([
            ['task_id' => 'wrapper', 'leverage_category' => 'cosmetic_wrapper'],
            ['task_id' => 'collision', 'leverage_category' => 'collision_prevention'],
        ]);

        $this->assertSame('collision', $result['ranked'][0]['task_id']);
    }

    // ── leverage score factors in downstream gain over shallow proxies ──

    public function test_high_downstream_gain_outranks_low_within_same_category(): void
    {
        $result = $this->comparator()->rank([
            ['task_id' => 'low-gain', 'leverage_category' => 'closed_loop_learning', 'expected_downstream_gain' => 0.1],
            ['task_id' => 'high-gain', 'leverage_category' => 'closed_loop_learning', 'expected_downstream_gain' => 0.9],
        ]);

        $this->assertSame('high-gain', $result['ranked'][0]['task_id']);
    }

    public function test_queue_depth_reduces_leverage_score(): void
    {
        $result = $this->comparator()->rank([
            ['task_id' => 'shallow', 'leverage_category' => 'capability_expansion', 'expected_downstream_gain' => 0.5, 'queue_depth' => 0],
            ['task_id' => 'deep', 'leverage_category' => 'capability_expansion', 'expected_downstream_gain' => 0.5, 'queue_depth' => 10],
        ]);

        $this->assertSame('shallow', $result['ranked'][0]['task_id']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->comparator()->rank([
            ['task_id' => 't1', 'leverage_category' => 'closed_loop_learning'],
        ]);

        $this->assertSame(AtlasExternalBrainStructuralLeverageComparator::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('ranked', $result);
        $this->assertArrayHasKey('total_candidates', $result);
        $this->assertArrayHasKey('top_candidate', $result);
    }

    public function test_empty_input_returns_empty_ranked(): void
    {
        $result = $this->comparator()->rank([]);

        $this->assertSame([], $result['ranked']);
        $this->assertSame(0, $result['total_candidates']);
        $this->assertNull($result['top_candidate']);
    }

    public function test_result_is_deterministic(): void
    {
        $candidates = [
            ['task_id' => 'b', 'leverage_category' => 'cosmetic_wrapper'],
            ['task_id' => 'a', 'leverage_category' => 'closed_loop_learning'],
        ];

        $a = $this->comparator()->rank($candidates);
        $b = $this->comparator()->rank($candidates);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
