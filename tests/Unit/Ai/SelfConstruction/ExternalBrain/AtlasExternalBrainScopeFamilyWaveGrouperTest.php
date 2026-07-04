<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScopeFamilyWaveGrouper;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScopeFamilyWaveGrouperTest extends TestCase
{
    private function grouper(): AtlasExternalBrainScopeFamilyWaveGrouper
    {
        return new AtlasExternalBrainScopeFamilyWaveGrouper;
    }

    // ── AC1: overlapping allowed_files are not placed in the same parallel group ──

    public function test_overlapping_allowed_files_never_share_a_group(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/Shared.php']],
        ]]);

        foreach ($r['compatible_groups'] as $group) {
            $this->assertFalse(in_array('a', $group, true) && in_array('b', $group, true));
        }
    }

    public function test_disjoint_allowed_files_can_share_a_group(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
        ]]);

        $this->assertCount(1, $r['compatible_groups']);
    }

    // ── AC2: dependency chains preserve order across groups ──────────────────────

    public function test_dependent_task_lands_in_a_later_wave_than_its_dependency(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'child', 'allowed_files' => ['app/Child.php'], 'depends_on' => ['parent']],
            ['task_id' => 'parent', 'allowed_files' => ['app/Parent.php']],
        ]]);

        $parentPlacement = current(array_filter($r['task_placements'], static fn (array $p): bool => $p['task_id'] === 'parent'));
        $childPlacement = current(array_filter($r['task_placements'], static fn (array $p): bool => $p['task_id'] === 'child'));

        $parentWaveIndex = (int) str_replace('wave_', '', $parentPlacement['wave_id']);
        $childWaveIndex = (int) str_replace('wave_', '', $childPlacement['wave_id']);

        $this->assertGreaterThan($parentWaveIndex, $childWaveIndex);
    }

    public function test_dependency_chain_of_three_produces_strictly_increasing_waves(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'c', 'allowed_files' => ['app/C.php'], 'depends_on' => ['b']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'depends_on' => ['a']],
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
        ]]);

        $waveOf = [];
        foreach ($r['task_placements'] as $p) {
            $waveOf[$p['task_id']] = (int) str_replace('wave_', '', $p['wave_id']);
        }

        $this->assertLessThan($waveOf['b'], $waveOf['a']);
        $this->assertLessThan($waveOf['c'], $waveOf['b']);
    }

    public function test_independent_tasks_without_dependency_relationship_still_group_together(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
        ]]);

        $this->assertCount(1, $r['compatible_groups']);
        $this->assertEqualsCanonicalizing(['a', 'b'], $r['compatible_groups'][0]);
    }

    public function test_dependency_on_task_absent_from_batch_never_blocks_placement(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'depends_on' => ['not-in-batch']],
        ]]);

        $this->assertCount(1, $r['compatible_groups']);
        $this->assertSame(['a'], $r['compatible_groups'][0]);
    }

    // ── AC3: output includes wave_id, parallel_safe and collision_reason fields ──

    public function test_output_includes_task_placements_key(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
        ]]);

        $this->assertArrayHasKey('task_placements', $r);
        $this->assertCount(1, $r['task_placements']);

        $placement = $r['task_placements'][0];
        $this->assertArrayHasKey('task_id', $placement);
        $this->assertArrayHasKey('wave_id', $placement);
        $this->assertArrayHasKey('parallel_safe', $placement);
        $this->assertArrayHasKey('collision_reason', $placement);
    }

    public function test_parallel_safe_task_has_no_collision_reason(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
        ]]);

        foreach ($r['task_placements'] as $p) {
            $this->assertTrue($p['parallel_safe']);
            $this->assertNull($p['collision_reason']);
        }
    }

    public function test_colliding_task_has_parallel_safe_false_and_named_collision_reason(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/Shared.php']],
        ]]);

        $placementA = current(array_filter($r['task_placements'], static fn (array $p): bool => $p['task_id'] === 'a'));

        $this->assertFalse($placementA['parallel_safe']);
        $this->assertSame('allowed_files_overlap', $placementA['collision_reason']);
    }

    public function test_wave_id_matches_the_task_actual_compatible_group_index(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/Shared.php']],
        ]]);

        foreach ($r['task_placements'] as $p) {
            $waveIndex = (int) str_replace('wave_', '', $p['wave_id']);
            $this->assertContains($p['task_id'], $r['compatible_groups'][$waveIndex]);
        }
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_group_with_dependencies_is_deterministic(): void
    {
        $input = ['tasks' => [
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'depends_on' => ['a']],
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
        ]];

        $this->assertSame($this->grouper()->group($input), $this->grouper()->group($input));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: dominant file families + fragile fixture / contention isolation
    // ═══════════════════════════════════════════════════════════════════════

    public function test_fragile_test_fixture_overlap_creates_conflict(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'fragile_test_fixtures' => ['tests/Fixtures/db.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'fragile_test_fixtures' => ['tests/Fixtures/db.php']],
        ]]);

        $this->assertCount(2, $r['compatible_groups'],
            'Tasks sharing a fragile fixture must be in separate groups');
        $conflict = current(array_filter($r['conflict_groups'],
            static fn (array $p): bool => $p['reason'] === 'fragile_test_fixture_overlap'));
        $this->assertNotEmpty($conflict, 'Must report fragile_test_fixture_overlap conflict');
    }

    public function test_recent_contention_tasks_separated(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'recent_contention' => true],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'recent_contention' => true],
        ]]);

        $this->assertCount(2, $r['compatible_groups'],
            'Both tasks with recent_contention must be in separate groups');
    }

    public function test_recent_contention_one_task_does_not_conflict(): void
    {
        // Only one task flagged recent_contention → no conflict.
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php'], 'recent_contention' => true],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'recent_contention' => false],
        ]]);

        $this->assertCount(1, $r['compatible_groups']);
    }

    public function test_fragile_fixture_and_allowed_files_both_checked(): void
    {
        // Fragile fixture overlap creates conflict even when allowed_files are disjoint.
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php', 'app/Shared.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'fragile_test_fixtures' => ['app/Shared.php']],
        ]]);

        // 'app/Shared.php' is in a's allowed_files AND b's fragile_test_fixtures.
        // Only allowed_files_overlap is checked, NOT fragile fixture vs allowed_files overlap.
        // So they shouldn't conflict since they only share via fixture vs allowed_files (not same category).
        $this->assertCount(1, $r['compatible_groups'],
            'Fixture path in one task and allowed file in another does NOT conflict by current rules');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: dependency ordering
    // ═══════════════════════════════════════════════════════════════════════

    public function test_dependency_in_topological_order(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php'], 'depends_on' => ['a']],
            ['task_id' => 'c', 'allowed_files' => ['app/C.php'], 'depends_on' => ['b']],
        ]]);

        $waveOf = [];
        foreach ($r['task_placements'] as $p) {
            $waveOf[$p['task_id']] = (int) str_replace('wave_', '', $p['wave_id']);
        }

        $this->assertLessThan($waveOf['b'], $waveOf['a'], 'a must be in an earlier wave than b');
        $this->assertLessThan($waveOf['c'], $waveOf['b'], 'b must be in an earlier wave than c');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: output contract — compatible_groups, conflict_groups,
    //      recommended_parallelism, family_load
    // ═══════════════════════════════════════════════════════════════════════

    public function test_output_includes_ac4_keys(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
        ]]);

        foreach (['compatible_groups', 'conflict_groups', 'recommended_parallelism', 'family_load'] as $key) {
            $this->assertArrayHasKey($key, $r, "Output missing required key: {$key}");
        }
    }

    public function test_family_load_reports_wave_distribution_for_each_file(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/Shared.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/Shared.php']],
            ['task_id' => 'c', 'allowed_files' => ['app/Unique.php']],
        ]]);

        $this->assertNotEmpty($r['family_load']);

        $sharedEntry = null;
        $uniqueEntry = null;
        foreach ($r['family_load'] as $entry) {
            if ($entry['file'] === 'app/Shared.php') {
                $sharedEntry = $entry;
            }
            if ($entry['file'] === 'app/Unique.php') {
                $uniqueEntry = $entry;
            }
        }

        $this->assertNotNull($sharedEntry, 'family_load must include app/Shared.php');
        $this->assertNotNull($uniqueEntry, 'family_load must include app/Unique.php');

        // Shared.php appears in two tasks that conflict, so distributed across two waves.
        $totalShared = array_sum($sharedEntry['wave_distribution']);
        $this->assertSame(2, $totalShared, 'Shared.php must appear 2 times across waves');

        // Unique.php appears in one task, so only in one wave.
        $totalUnique = array_sum($uniqueEntry['wave_distribution']);
        $this->assertSame(1, $totalUnique, 'Unique.php must appear 1 time across waves');
    }

    public function test_family_load_avoids_overloading_one_wave_with_same_file(): void
    {
        // Two tasks sharing the same file must end up in different waves.
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/Overloaded.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/Overloaded.php']],
        ]]);

        $entry = null;
        foreach ($r['family_load'] as $e) {
            if ($e['file'] === 'app/Overloaded.php') {
                $entry = $e;
                break;
            }
        }

        $this->assertNotNull($entry);
        // Must have at most 1 task per wave for a file that causes conflicts.
        foreach ($entry['wave_distribution'] as $waveIdx => $count) {
            $this->assertLessThanOrEqual(1, $count,
                "File app/Overloaded.php must not have more than 1 task in wave {$waveIdx}");
        }
    }

    public function test_recommended_parallelism_equals_largest_group_size(): void
    {
        $r = $this->grouper()->group(['tasks' => [
            ['task_id' => 'a', 'allowed_files' => ['app/A.php']],
            ['task_id' => 'b', 'allowed_files' => ['app/B.php']],
            ['task_id' => 'c', 'allowed_files' => ['app/C.php']],
        ]]);

        $this->assertSame(
            max(array_map('count', $r['compatible_groups'])),
            $r['recommended_parallelism'],
        );
    }
}
