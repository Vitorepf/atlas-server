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
}
