<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphRoiScheduler;
use Tests\TestCase;

final class AtlasExternalBrainTaskGraphRoiSchedulerTest extends TestCase
{
    private function svc(): AtlasExternalBrainTaskGraphRoiScheduler
    {
        return new AtlasExternalBrainTaskGraphRoiScheduler;
    }

    private function task(string $id, array $overrides = []): array
    {
        return array_merge([
            'task_id'         => $id,
            'depends_on'      => [],
            'expected_impact' => 0.8,
            'cost_risk'       => 0.2,
            'unlock_value'    => 0.5,
            'allowed_files'   => ["src/{$id}.php"],
        ], $overrides);
    }

    // ── AC1: topological ordering into waves; blockers as warnings ─────────────

    public function test_ac1_prerequisite_appears_in_earlier_wave_than_dependent(): void
    {
        $r = $this->svc()->schedule([
            $this->task('A'),
            $this->task('B', ['depends_on' => ['A']]),
        ]);

        $this->assertCount(2, $r['waves']);

        $wave0Ids = $r['waves'][0]['tasks'];
        $wave1Ids = $r['waves'][1]['tasks'];

        $this->assertContains('A', $wave0Ids, 'A (prerequisite) must be in wave 0');
        $this->assertContains('B', $wave1Ids, 'B (dependent on A) must be in wave 1');
    }

    public function test_ac1_three_level_chain_produces_three_waves(): void
    {
        $r = $this->svc()->schedule([
            $this->task('X'),
            $this->task('Y', ['depends_on' => ['X']]),
            $this->task('Z', ['depends_on' => ['Y']]),
        ]);

        $allWaveTasks = array_map(static fn ($w) => $w['tasks'], $r['waves']);
        [$w0, $w1, $w2] = $allWaveTasks;

        $this->assertContains('X', $w0);
        $this->assertContains('Y', $w1);
        $this->assertContains('Z', $w2);
    }

    public function test_ac1_same_file_collision_is_surfaced_as_warning(): void
    {
        // Two independent tasks with same allowed_file → collision warning
        $r = $this->svc()->schedule([
            $this->task('P', ['allowed_files' => ['shared.php'], 'depends_on' => []]),
            $this->task('Q', ['allowed_files' => ['shared.php'], 'depends_on' => []]),
        ]);

        $collisions = $r['waves'][0]['collisions'];
        $this->assertContains('shared.php', $collisions);

        $warningTexts = implode('|', $r['warnings']);
        $this->assertStringContainsString('same_file_collision', $warningTexts);
    }

    public function test_ac1_parallel_safe_true_when_no_file_collision(): void
    {
        $r = $this->svc()->schedule([
            $this->task('M', ['allowed_files' => ['m.php']]),
            $this->task('N', ['allowed_files' => ['n.php']]),
        ]);

        $this->assertTrue($r['waves'][0]['parallel_safe']);
        $this->assertSame([], $r['waves'][0]['collisions']);
    }

    public function test_ac1_critical_path_follows_longest_dependency_chain(): void
    {
        $r = $this->svc()->schedule([
            $this->task('root'),
            $this->task('mid',  ['depends_on' => ['root']]),
            $this->task('leaf', ['depends_on' => ['mid']]),
            $this->task('iso'),  // isolated — not on critical path
        ]);

        $this->assertContains('root', $r['critical_path']);
        $this->assertContains('mid',  $r['critical_path']);
        $this->assertContains('leaf', $r['critical_path']);
    }

    // ── AC2: give_back_risk and blocked_prerequisite_risk lower priority ───────

    public function test_ac2_give_back_risk_demotes_task_below_lower_nominal_roi(): void
    {
        // high_roi has great nominal ROI but also high give_back_risk
        // safe_roi has modest ROI but zero give_back_risk
        // Same layer (no dependencies), so sort is purely by adjusted ROI
        $r = $this->svc()->schedule([
            $this->task('high_roi', [
                'expected_impact' => 1.0,
                'unlock_value'    => 1.0,
                'cost_risk'       => 0.01,
                'give_back_risk'  => 1.0,  // max penalty
                'allowed_files'   => ['high.php'],
            ]),
            $this->task('safe_roi', [
                'expected_impact' => 0.9,
                'unlock_value'    => 0.5,
                'cost_risk'       => 0.2,
                'give_back_risk'  => 0.0,
                'allowed_files'   => ['safe.php'],
            ]),
        ]);

        // Both in wave 0 (no deps). safe_roi must rank first.
        $wave0 = $r['waves'][0]['tasks'];
        $this->assertSame('safe_roi', $wave0[0],
            'give_back_risk=1.0 must demote high_roi below safe_roi');
    }

    public function test_ac2_blocked_prerequisite_risk_demotes_task(): void
    {
        $r = $this->svc()->schedule([
            $this->task('blocked', [
                'expected_impact'           => 1.0,
                'unlock_value'              => 1.0,
                'cost_risk'                 => 0.01,
                'blocked_prerequisite_risk' => 1.0,
                'allowed_files'             => ['b.php'],
            ]),
            $this->task('clear', [
                'expected_impact'           => 0.8,
                'unlock_value'              => 0.6,
                'cost_risk'                 => 0.2,
                'blocked_prerequisite_risk' => 0.0,
                'allowed_files'             => ['c.php'],
            ]),
        ]);

        $wave0 = $r['waves'][0]['tasks'];
        $this->assertSame('clear', $wave0[0],
            'blocked_prerequisite_risk=1.0 must demote "blocked" below "clear"');
    }

    public function test_ac2_risk_notes_appear_in_next_wave_candidate_reasons(): void
    {
        $r = $this->svc()->schedule([
            $this->task('risky', [
                'give_back_risk'            => 0.80,
                'blocked_prerequisite_risk' => 0.60,
                'allowed_files'             => ['r.php'],
            ]),
        ]);

        $reasons = implode('|', $r['next_wave_candidate_reasons']['risky'] ?? []);
        $this->assertStringContainsString('give_back_risk', $reasons);
        $this->assertStringContainsString('blocked_prerequisite_risk', $reasons);
    }

    // ── AC3: independent high-value chains can run in parallel ────────────────

    public function test_ac3_independent_tasks_land_in_same_wave(): void
    {
        // Two chains, each with their own root — roots share no deps and no files
        $r = $this->svc()->schedule([
            $this->task('chain_a_root', ['allowed_files' => ['a1.php']]),
            $this->task('chain_b_root', ['allowed_files' => ['b1.php']]),
        ]);

        $wave0Ids = $r['waves'][0]['tasks'];
        $this->assertContains('chain_a_root', $wave0Ids);
        $this->assertContains('chain_b_root', $wave0Ids);
        $this->assertTrue($r['waves'][0]['parallel_safe']);
    }

    public function test_ac3_parallel_safe_false_when_chains_share_file(): void
    {
        $r = $this->svc()->schedule([
            $this->task('ca', ['allowed_files' => ['shared.php', 'a_only.php']]),
            $this->task('cb', ['allowed_files' => ['shared.php', 'b_only.php']]),
        ]);

        $this->assertFalse($r['waves'][0]['parallel_safe']);
    }

    public function test_ac3_two_independent_chains_schedule_separate_wave_sets(): void
    {
        // Chain A: a1 → a2; Chain B: b1 → b2; all files unique
        $r = $this->svc()->schedule([
            $this->task('a1', ['allowed_files' => ['a1.php']]),
            $this->task('a2', ['depends_on' => ['a1'], 'allowed_files' => ['a2.php']]),
            $this->task('b1', ['allowed_files' => ['b1.php']]),
            $this->task('b2', ['depends_on' => ['b1'], 'allowed_files' => ['b2.php']]),
        ]);

        // Wave 0 must contain a1 and b1 (both roots, no shared files)
        $wave0 = $r['waves'][0]['tasks'];
        $this->assertContains('a1', $wave0);
        $this->assertContains('b1', $wave0);
        $this->assertTrue($r['waves'][0]['parallel_safe']);

        // Wave 1 must contain a2 and b2
        $wave1 = $r['waves'][1]['tasks'];
        $this->assertContains('a2', $wave1);
        $this->assertContains('b2', $wave1);
        $this->assertTrue($r['waves'][1]['parallel_safe']);
    }

    // ── AC4: deterministic, no side effects ───────────────────────────────────

    public function test_ac4_identical_input_yields_identical_output(): void
    {
        $tasks = [
            $this->task('t1'),
            $this->task('t2', ['depends_on' => ['t1']]),
        ];

        $this->assertSame(
            json_encode($this->svc()->schedule($tasks), JSON_UNESCAPED_SLASHES),
            json_encode($this->svc()->schedule($tasks), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_empty_task_list_returns_empty_waves(): void
    {
        $r = $this->svc()->schedule([]);

        $this->assertSame([], $r['waves']);
        $this->assertSame([], $r['warnings']);
        $this->assertSame([], $r['critical_path']);
    }
}
