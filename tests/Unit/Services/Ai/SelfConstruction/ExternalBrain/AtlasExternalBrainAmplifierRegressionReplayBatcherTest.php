<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierRegressionReplayBatcher;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierRegressionReplayBatcherTest extends TestCase
{
    private AtlasExternalBrainAmplifierRegressionReplayBatcher $batcher;

    protected function setUp(): void
    {
        $this->batcher = new AtlasExternalBrainAmplifierRegressionReplayBatcher;
    }

    public function test_recurring_failures_are_grouped(): void
    {
        $result = $this->batcher->batch([
            ['failure_pattern' => 'timeout', 'task_id' => 't1'],
            ['failure_pattern' => 'timeout', 'task_id' => 't2'],
            ['failure_pattern' => 'timeout', 'task_id' => 't3'],
        ]);

        $this->assertSame(1, $result['recurring_count']);
        $this->assertSame(3, $result['batches'][0]['case_count']);
    }

    public function test_one_offs_are_sampled(): void
    {
        $result = $this->batcher->batch([
            ['failure_pattern' => 'unique_error', 'task_id' => 't1'],
        ]);

        $this->assertSame(1, $result['one_off_count']);
        $this->assertSame(1, $result['batches'][0]['case_count']);
    }

    public function test_each_batch_has_runnable_replay_acceptance(): void
    {
        $result = $this->batcher->batch([
            ['failure_pattern' => 'recurring', 'task_id' => 't1'],
            ['failure_pattern' => 'recurring', 'task_id' => 't2'],
            ['failure_pattern' => 'oneoff', 'task_id' => 't3'],
        ]);

        foreach ($result['batches'] as $batch) {
            $this->assertNotEmpty($batch['replay_acceptance']);
        }
    }

    public function test_empty_input_returns_empty_batches(): void
    {
        $result = $this->batcher->batch([]);
        $this->assertSame(0, $result['batch_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->batcher->batch([]);
        $this->assertSame(AtlasExternalBrainAmplifierRegressionReplayBatcher::SCHEMA, $result['schema']);
    }
}
