<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLearningRetentionRunner;
use Tests\TestCase;

final class AtlasExternalBrainLearningRetentionRunnerTest extends TestCase
{
    private AtlasExternalBrainLearningRetentionRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = new AtlasExternalBrainLearningRetentionRunner;
    }

    public function test_fresh_learning_is_retained(): void
    {
        // Fresh: confirmed, low age, high utility → retained.
        $result = $this->runner->run([
            'learning_records' => [
                [
                    'id' => 'fresh-1',
                    'type' => 'always',
                    'utility_score' => 0.9,
                    'age_days' => 1,
                    'confirmed' => true,
                    'actionable' => true,
                ],
            ],
        ]);

        $this->assertSame(1, $result['retention_summary']['retained_count']);
        $this->assertCount(1, $result['retained']);
    }

    public function test_old_unconfirmed_learning_is_retired(): void
    {
        // Overridden → retired.
        $result = $this->runner->run([
            'learning_records' => [
                [
                    'id' => 'stale-1',
                    'type' => 'always',
                    'utility_score' => 0.9,
                    'age_days' => 1,
                    'confirmed' => true,
                    'overridden' => true,
                    'actionable' => true,
                ],
            ],
        ]);

        $this->assertSame(1, $result['retention_summary']['retired_count']);
        $this->assertCount(1, $result['retired']);
    }

    public function test_run_has_expected_keys(): void
    {
        $result = $this->runner->run([]);

        $this->assertArrayHasKey('decay_summary', $result);
        $this->assertArrayHasKey('retention_summary', $result);
        $this->assertArrayHasKey('retained', $result);
        $this->assertArrayHasKey('refreshed', $result);
        $this->assertArrayHasKey('retired', $result);

        $this->assertArrayHasKey('total_learnings', $result['decay_summary']);
        $this->assertArrayHasKey('retained_count', $result['retention_summary']);
        $this->assertArrayHasKey('refreshed_count', $result['retention_summary']);
        $this->assertArrayHasKey('retired_count', $result['retention_summary']);
    }

    public function test_run_is_deterministic(): void
    {
        $a = $this->runner->run([]);
        $b = $this->runner->run([]);

        $this->assertSame($a['decay_summary'], $b['decay_summary']);
        $this->assertSame($a['retention_summary'], $b['retention_summary']);
    }

    public function test_decay_detector_path_works_with_lesson_input(): void
    {
        // Supply lessons in decay detector format with a stale lesson.
        $result = $this->runner->run([
            'lessons' => [
                [
                    'lesson_id' => 'stale-deep',
                    'created_at_seconds_ago' => 999999,
                    'confirmation_count' => 0,
                    'campaign_ids' => ['a', 'b', 'c', 'd', 'e'],
                    'architecture_version' => '2026',
                ],
            ],
            'decay_threshold_seconds' => 86400,
            'current_architecture_version' => '2026',
        ]);

        $this->assertSame(1, $result['decay_summary']['decayed_count'],
            'decay detector must detect stale lesson');
    }
}
