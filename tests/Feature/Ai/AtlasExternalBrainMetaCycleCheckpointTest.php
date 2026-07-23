<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMetaCycleCheckpoint;
use Tests\TestCase;

final class AtlasExternalBrainMetaCycleCheckpointTest extends TestCase
{
    public function test_nested_forbidden_keys_are_rejected_provider_safely(): void
    {
        foreach (['raw_prompt', 'prompt_text', 'prompt_template', 'raw_log', 'log_data'] as $key) {
            $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
                'cycle_id' => 'c1',
                'nested' => ['deep' => [$key => 'leak']],
            ]);

            $this->assertFalse($result['accepted'], "expected rejection for key: $key");
            $this->assertSame('provider_unsafe_key:'.$key, $result['rejection_reason']);
        }
    }

    public function test_unbounded_task_payload_over_max_key_count_is_rejected(): void
    {
        $bigTask = [];
        for ($i = 0; $i < 25; $i++) {
            $bigTask["k$i"] = $i;
        }

        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'tasks' => [$bigTask],
        ]);

        $this->assertFalse($result['accepted']);
        $this->assertSame('unbounded_task_payload', $result['rejection_reason']);
    }

    public function test_next_cycle_recommendation_escalate_on_high_queue_pressure(): void
    {
        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'queue_pressure' => 0.95,
        ]);

        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_ESCALATE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_next_cycle_recommendation_drain_on_high_pressure_zero_enqueued(): void
    {
        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'queue_pressure' => 0.75,
            'tasks_enqueued' => 0,
        ]);

        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_DRAIN, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_next_cycle_recommendation_consolidate_on_low_validation_rate(): void
    {
        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'tasks_enqueued' => 3,
            'validation_results' => ['passed' => 1, 'failed' => 3],
        ]);

        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_CONSOLIDATE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_next_cycle_recommendation_repair_on_zero_enqueued_zero_skipped_with_failures(): void
    {
        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'tasks_enqueued' => 0,
            'tasks_skipped' => 0,
            'validation_results' => ['passed' => 0, 'failed' => 2],
        ]);

        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_REPAIR, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_next_cycle_recommendation_pause_on_zero_enqueued_some_skipped_low_validation(): void
    {
        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'tasks_enqueued' => 0,
            'tasks_skipped' => 5,
            'validation_results' => ['passed' => 1, 'failed' => 3],
        ]);

        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_PAUSE, $result['checkpoint']['next_cycle_recommendation']);
    }

    public function test_next_cycle_recommendation_continue_by_default(): void
    {
        $result = (new AtlasExternalBrainMetaCycleCheckpoint)->capture([
            'cycle_id' => 'c1',
            'queue_pressure' => 0.1,
            'tasks_enqueued' => 3,
            'validation_results' => ['passed' => 3, 'failed' => 0],
        ]);

        $this->assertSame(AtlasExternalBrainMetaCycleCheckpoint::REC_CONTINUE, $result['checkpoint']['next_cycle_recommendation']);
    }
}
