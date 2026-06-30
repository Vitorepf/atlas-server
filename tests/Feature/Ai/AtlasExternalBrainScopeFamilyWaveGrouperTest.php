<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScopeFamilyWaveGrouper;
use Tests\TestCase;

final class AtlasExternalBrainScopeFamilyWaveGrouperTest extends TestCase
{
    private function grouper(): AtlasExternalBrainScopeFamilyWaveGrouper
    {
        return new AtlasExternalBrainScopeFamilyWaveGrouper;
    }

    public function test_output_has_required_keys(): void
    {
        $r = $this->grouper()->group(['tasks' => []]);

        foreach (['schema', 'compatible_groups', 'conflict_groups', 'recommended_parallelism', 'tasks_that_should_run_serially'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertSame(AtlasExternalBrainScopeFamilyWaveGrouper::SCHEMA, $r['schema']);
    }

    public function test_disjoint_tasks_are_grouped_together(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
        ]]);

        $this->assertCount(1, $r['compatible_groups']);
        $this->assertEqualsCanonicalizing(['a', 'b'], $r['compatible_groups'][0]);
        $this->assertSame(2, $r['recommended_parallelism']);
        $this->assertSame([], $r['tasks_that_should_run_serially']);
    }

    public function test_overlapping_allowed_files_marks_unsafe_for_same_wave(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/Shared.php']],
        ]]);

        $this->assertCount(1, $r['conflict_groups']);
        $this->assertSame('allowed_files_overlap', $r['conflict_groups'][0]['reason']);
        $this->assertContains('a', $r['tasks_that_should_run_serially']);
        $this->assertContains('b', $r['tasks_that_should_run_serially']);
        $this->assertCount(2, $r['compatible_groups']); // each gets its own group
    }

    public function test_shared_fragile_test_fixture_marks_unsafe_for_same_wave(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'fragile_test_fixtures' => ['tests/fixtures/db.sqlite']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'fragile_test_fixtures' => ['tests/fixtures/db.sqlite']],
        ]]);

        $this->assertSame('fragile_test_fixture_overlap', $r['conflict_groups'][0]['reason']);
        $this->assertContains('a', $r['tasks_that_should_run_serially']);
    }

    public function test_both_recent_contention_marks_unsafe_for_same_wave(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'recent_contention' => true],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'recent_contention' => true],
        ]]);

        $this->assertSame('recent_contention', $r['conflict_groups'][0]['reason']);
    }

    public function test_single_recent_contention_does_not_conflict(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'recent_contention' => true],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'recent_contention' => false],
        ]]);

        $this->assertSame([], $r['conflict_groups']);
    }

    public function test_three_way_chain_conflict_splits_correctly(): void
    {
        // a conflicts with b (shared file), b conflicts with c (shared file), a/c disjoint.
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['shared1.php']],
            ['task_id' => 'b', 'allowed_files' => ['shared1.php', 'shared2.php']],
            ['task_id' => 'c', 'allowed_files' => ['shared2.php']],
        ]]);

        $this->assertCount(2, $r['conflict_groups']);
        // a and c can share a group (no direct conflict); b is isolated.
        $groupSizes = array_map('count', $r['compatible_groups']);
        rsort($groupSizes);
        $this->assertSame([2, 1], $groupSizes);
    }

    public function test_recommended_parallelism_is_largest_group_size(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
            ['task_id' => 'c', 'allowed_files' => ['app/C.php']],
        ]]);

        $this->assertSame(3, $r['recommended_parallelism']);
    }

    public function test_empty_tasks_returns_safe_empty_result(): void
    {
        $r = $this->grouper()->group(['tasks' => []]);

        $this->assertSame([], $r['compatible_groups']);
        $this->assertSame([], $r['conflict_groups']);
        $this->assertSame(1, $r['recommended_parallelism']);
        $this->assertSame([], $r['tasks_that_should_run_serially']);
    }

    public function test_group_is_deterministic(): void
    {
        $input = ['tasks' => [
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
        ]];

        $first = $this->grouper()->group($input);
        $second = $this->grouper()->group($input);

        $this->assertSame($first, $second);
    }
}
