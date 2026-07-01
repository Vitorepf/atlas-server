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
}
