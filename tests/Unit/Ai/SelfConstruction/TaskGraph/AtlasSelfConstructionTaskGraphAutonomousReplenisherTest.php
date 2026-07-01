<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher;
use Tests\TestCase;

class AtlasSelfConstructionTaskGraphAutonomousReplenisherTest extends TestCase
{
    private function validDraft(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'fill '.$id,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/'.$id.'/Service.php',
                'tests/Unit/Ai/SelfConstruction/'.$id.'/ServiceTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/'.$id.'/Service.php',
                'tests/Unit/Ai/SelfConstruction/'.$id.'/ServiceTest.php',
            ],
            'acceptance_criteria' => ['noop'],
            'required_evidence' => ['tests_or_gates_result'],
            'expected_delta' => 'Adds '.$id.' capability to the task graph.',
            'anti_proxy' => 'Green PHPUnit tests prove real behavior change.',
            'depends_on' => [],
            'wave' => 'w',
            'tags' => ['self_construction', $id],
            'priority' => 5,
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
        ];
    }

    // ── AC1: unlocked high-value follow-ups rank before isolated low-value drafts ──

    public function test_unlocked_high_value_follow_up_ranks_before_speculative_low_value_when_feed_thin(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->prioritizeUnlockedFollowUps(
            [
                ['task_packet_id' => 'speculative-1', 'value' => 9, 'dependency_count' => 0, 'speculative' => true],
                ['task_packet_id' => 'follow-up-1', 'value' => 8, 'dependency_count' => 2, 'is_unlocked_follow_up' => true],
            ],
            ['claimable_per_active_worker' => 1.0],
        );

        $this->assertTrue($result['worker_feed_thin']);
        $this->assertSame('follow-up-1', $result['ordered'][0]['task_packet_id']);
    }

    public function test_worker_feed_not_thin_falls_back_to_value_ordering(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->prioritizeUnlockedFollowUps(
            [
                ['task_packet_id' => 'speculative-1', 'value' => 9, 'dependency_count' => 0, 'speculative' => true],
                ['task_packet_id' => 'follow-up-1', 'value' => 8, 'dependency_count' => 2, 'is_unlocked_follow_up' => true],
            ],
            ['claimable_per_active_worker' => 10.0],
        );

        $this->assertFalse($result['worker_feed_thin']);
        $this->assertSame('speculative-1', $result['ordered'][0]['task_packet_id']);
    }

    // ── AC3: run() output includes worker_feed_thin ──────────────────────────

    public function test_run_output_includes_worker_feed_thin(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1')],
            ['claimable_per_active_worker' => 1.0],
        );

        $this->assertArrayHasKey('worker_feed_thin', $result);
        $this->assertTrue($result['worker_feed_thin']);
    }

    public function test_run_output_worker_feed_thin_false_when_comfortable(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1')],
            ['claimable_per_active_worker' => 10.0],
        );

        $this->assertFalse($result['worker_feed_thin']);
    }

    public function test_default_dry_run_plans_but_applies_nothing(): void
    {
        $called = 0;
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            [],
            ['enqueue_callback' => function () use (&$called) {
                $called++;
            }],
        );

        self::assertTrue($verdict['dry_run']);
        self::assertSame(0, $verdict['applied_count']);
        self::assertSame(0, $called);
        self::assertSame(2, $verdict['plan']['enqueue_input_count']);
    }

    public function test_apply_invokes_callback_for_each_valid_input(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            [],
            [
                'apply' => true,
                'enqueue_callback' => function (array $input) use (&$received): array {
                    $received[] = $input['task_packet']['task_packet_id'];

                    return ['enqueued' => true];
                },
            ],
        );

        self::assertFalse($verdict['dry_run']);
        self::assertSame(2, $verdict['applied_count']);
        self::assertSame(['a-1', 'b-1'], $received);
        self::assertCount(2, $verdict['enqueue_results']);
        self::assertTrue($verdict['enqueue_results'][0]['applied']);
    }

    public function test_max_applied_caps_callback_invocations(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1'), $this->validDraft('c-1')],
            [],
            [
                'apply' => true,
                'max_applied' => 2,
                'enqueue_callback' => function (array $input) use (&$received): void {
                    $received[] = $input['task_packet']['task_packet_id'];
                },
            ],
        );

        self::assertSame(2, $verdict['applied_count']);
        self::assertCount(2, $received);
        self::assertSame(1, $verdict['withheld_count']);
        self::assertContains('max_applied_reached:2', $verdict['plan']['withheld'][count($verdict['plan']['withheld']) - 1]['blockers']);
    }

    public function test_duplicate_drafts_are_not_applied(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            ['existing_packet_ids' => ['b-1']],
            [
                'apply' => true,
                'enqueue_callback' => function (array $input) use (&$received): void {
                    $received[] = $input['task_packet']['task_packet_id'];
                },
            ],
        );

        self::assertSame(1, $verdict['applied_count']);
        self::assertSame(['a-1'], $received);
        self::assertSame(1, $verdict['duplicate_count']);
    }

    public function test_malformed_or_gate_blocked_drafts_remain_withheld(): void
    {
        $bad = $this->validDraft('bad-1');
        $bad['acceptance_criteria'] = []; // gate blocks

        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$bad],
            [],
            ['apply' => true, 'enqueue_callback' => fn () => null],
        );

        self::assertSame(0, $verdict['applied_count']);
        self::assertSame(1, $verdict['withheld_count']);
        self::assertSame('quality_gate_blocked', $verdict['plan']['withheld'][0]['reason']);
    }

    // ── AC2: callback exception is isolated, does not stop later eligible inputs ──

    public function test_callback_exception_creates_unapplied_result_and_does_not_stop_later_inputs(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            [],
            [
                'apply' => true,
                'enqueue_callback' => static function (array $input): array {
                    if ($input['task_packet']['task_packet_id'] === 'a-1') {
                        throw new \RuntimeException('enqueue failed for a-1');
                    }

                    return ['enqueued' => true];
                },
            ],
        );

        $results = array_column($verdict['enqueue_results'], null, 'task_packet_id');
        $this->assertFalse($results['a-1']['applied']);
        $this->assertNotNull($results['a-1']['error']);
        $this->assertTrue($results['b-1']['applied']);
        $this->assertSame(1, $verdict['applied_count']);
        $this->assertNotEmpty($verdict['replenisher_hash'], 'hash must still be emitted after a partial failure');
    }

    public function test_apply_without_callback_stays_dry_run(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1')],
            [],
            ['apply' => true],
        );

        self::assertTrue($verdict['dry_run']);
        self::assertSame(0, $verdict['applied_count']);
    }

    public function test_replenisher_source_does_not_call_provider_git_or_workers(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/TaskGraph/AtlasSelfConstructionTaskGraphAutonomousReplenisher.php'));
        foreach (['shell_exec', 'exec(', 'system(', 'proc_open', '`git ', 'Http::', 'curl_', 'proc_spawn'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "replenisher must not call {$forbidden}");
        }
    }

    // ── replenishFromGaps ─────────────────────────────────────────────────────

    public function test_replenish_from_gaps_no_op_when_empty(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([]);

        self::assertTrue($result['no_op']);
        self::assertSame([], $result['packet_drafts']);
    }

    public function test_replenish_from_gaps_produces_draft_per_gap(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex'],
            ['lane' => 'maestro'],
        ]);

        self::assertFalse($result['no_op']);
        self::assertCount(2, $result['packet_drafts']);
    }

    public function test_replenish_from_gaps_draft_has_required_keys(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex'],
        ]);

        $draft = $result['packet_drafts'][0];
        foreach (['task_packet_id', 'lane', 'wave_order', 'wave', 'depends_on',
                  'objective', 'allowed_files', 'acceptance_criteria', 'tags', 'priority'] as $key) {
            self::assertArrayHasKey($key, $draft);
        }
    }

    public function test_replenish_from_gaps_task_packet_id_contains_lane(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'worker_swarm'],
        ]);

        self::assertStringContainsString('worker_swarm', $result['packet_drafts'][0]['task_packet_id']);
    }

    public function test_replenish_from_gaps_independent_lanes_all_wave_1(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex'],
            ['lane' => 'maestro'],
            ['lane' => 'receipts'],
        ]);

        foreach ($result['packet_drafts'] as $draft) {
            self::assertSame(1, $draft['wave_order']);
        }
    }

    public function test_replenish_from_gaps_dependent_lane_is_wave_2(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex'],
            ['lane' => 'maestro', 'depends_on_lanes' => ['cortex']],
        ]);

        $byLane = array_column($result['packet_drafts'], null, 'lane');
        self::assertSame(1, $byLane['cortex']['wave_order']);
        self::assertSame(2, $byLane['maestro']['wave_order']);
    }

    public function test_replenish_from_gaps_chain_produces_3_waves(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'a'],
            ['lane' => 'b', 'depends_on_lanes' => ['a']],
            ['lane' => 'c', 'depends_on_lanes' => ['b']],
        ]);

        $byLane = array_column($result['packet_drafts'], null, 'lane');
        self::assertSame(1, $byLane['a']['wave_order']);
        self::assertSame(2, $byLane['b']['wave_order']);
        self::assertSame(3, $byLane['c']['wave_order']);
    }

    public function test_replenish_from_gaps_depends_on_uses_packet_ids(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex'],
            ['lane' => 'maestro', 'depends_on_lanes' => ['cortex']],
        ]);

        $byLane = array_column($result['packet_drafts'], null, 'lane');
        self::assertNotEmpty($byLane['maestro']['depends_on']);
        self::assertStringContainsString('cortex', $byLane['maestro']['depends_on'][0]);
        self::assertEmpty($byLane['cortex']['depends_on']);
    }

    public function test_replenish_from_gaps_external_depends_on_lanes_ignored(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex', 'depends_on_lanes' => ['external-organ']],
        ]);

        self::assertEmpty($result['packet_drafts'][0]['depends_on']);
        self::assertSame(1, $result['packet_drafts'][0]['wave_order']);
    }

    public function test_replenish_from_gaps_drafts_sorted_by_wave_then_lane(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'z_lane'],
            ['lane' => 'a_lane'],
            ['lane' => 'm_lane', 'depends_on_lanes' => ['a_lane']],
        ]);

        $waves = array_column($result['packet_drafts'], 'wave_order');
        self::assertSame([1, 1, 2], $waves);

        $lanes = array_column($result['packet_drafts'], 'lane');
        self::assertSame('a_lane', $lanes[0]);
        self::assertSame('z_lane', $lanes[1]);
    }

    public function test_replenish_from_gaps_schema_always_present(): void
    {
        $result = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([]);
        self::assertSame(AtlasSelfConstructionTaskGraphAutonomousReplenisher::SCHEMA, $result['schema']);

        $result2 = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->replenishFromGaps([
            ['lane' => 'cortex'],
        ]);
        self::assertSame(AtlasSelfConstructionTaskGraphAutonomousReplenisher::SCHEMA, $result2['schema']);
    }
}
