<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use ReflectionMethod;
use Tests\TestCase;

final class AtlasLoopProjectionWorkerTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $worker = new AtlasLoopProjectionWorker(new AtlasLoopStore());
        $reflection = new ReflectionMethod($worker, $method);

        return $reflection->invoke($worker, ...$args);
    }

    public function test_is_terminal_duplicate_distinguishes_terminal_and_live_statuses(): void
    {
        foreach ([AtlasLoopTask::STATUS_DONE, AtlasLoopTask::STATUS_FAILED, AtlasLoopTask::STATUS_DEFERRED] as $status) {
            $task = new AtlasLoopTask();
            $task->status = $status;

            $this->assertTrue($this->invoke('isTerminalDuplicate', [$task]), "{$status} should be treated as a terminal duplicate");
        }

        $liveTask = new AtlasLoopTask();
        $liveTask->status = AtlasLoopTask::STATUS_PENDING;

        $this->assertFalse($this->invoke('isTerminalDuplicate', [$liveTask]), 'pending work must stay live instead of being treated as a terminal duplicate');
    }

    public function test_reopen_retryable_terminal_duplicate_returns_false_for_a_task_that_already_has_a_winner(): void
    {
        $task = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $task->status = AtlasLoopTask::STATUS_DONE;
        $task->attempts = 1;
        $task->max_attempts = 2;
        $task->result = ['has_winner' => true, 'winner' => 'certified-task'];

        $this->assertFalse(
            $this->invoke('reopenRetryableTerminalDuplicate', [$task]),
            'a terminal duplicate that already has a winner must stay terminal',
        );
        $this->assertFalse($task->saveCalled, 'winner duplicates must not be reopened or persisted again');
        $this->assertSame(AtlasLoopTask::STATUS_DONE, $task->status);
        $this->assertSame(['has_winner' => true, 'winner' => 'certified-task'], $task->result);
    }

    public function test_reopen_retryable_terminal_duplicate_returns_false_for_live_supply_even_with_attempt_budget_remaining(): void
    {
        $task = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $task->status = AtlasLoopTask::STATUS_PENDING;
        $task->attempts = 1;
        $task->max_attempts = 2;
        $task->claimed_by = 'worker-live';
        $task->result = ['has_winner' => false, 'reason' => 'still_pending'];

        $this->assertFalse(
            $this->invoke('reopenRetryableTerminalDuplicate', [$task]),
            'live supply must not be reopened as if it were a terminal duplicate',
        );
        $this->assertFalse($task->saveCalled, 'live tasks must not be force-filled or saved again');
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status);
        $this->assertSame('worker-live', $task->claimed_by);
        $this->assertSame(['has_winner' => false, 'reason' => 'still_pending'], $task->result);
    }

    public function test_reopen_retryable_terminal_duplicate_treats_status_as_the_gate_between_live_and_retryable_terminal_supply(): void
    {
        $liveTask = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $liveTask->status = AtlasLoopTask::STATUS_PENDING;
        $liveTask->attempts = 0;
        $liveTask->max_attempts = 2;
        $liveTask->claimed_by = 'worker-live';
        $liveTask->result = ['has_winner' => false, 'reason' => 'still_pending'];

        $this->assertFalse(
            $this->invoke('reopenRetryableTerminalDuplicate', [$liveTask]),
            'the reopen path must reject still-live supply before touching any persistence fields',
        );
        $this->assertFalse($liveTask->saveCalled);
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $liveTask->status);
        $this->assertSame('worker-live', $liveTask->claimed_by);
        $this->assertSame(['has_winner' => false, 'reason' => 'still_pending'], $liveTask->result);

        $terminalTask = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $terminalTask->status = AtlasLoopTask::STATUS_DONE;
        $terminalTask->attempts = 0;
        $terminalTask->max_attempts = 2;
        $terminalTask->claimed_by = 'worker-terminal';
        $terminalTask->claimed_at = now();
        $terminalTask->lease_expires_at = now()->addMinute();
        $terminalTask->heartbeat_at = now();
        $terminalTask->result = ['has_winner' => false, 'reason' => 'needs_retry'];

        $this->assertTrue(
            $this->invoke('reopenRetryableTerminalDuplicate', [$terminalTask]),
            'the same no-winner payload becomes retryable once the task is actually terminal',
        );
        $this->assertTrue($terminalTask->saveCalled);
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $terminalTask->status);
        $this->assertNull($terminalTask->claimed_by);
        $this->assertNull($terminalTask->claimed_at);
        $this->assertNull($terminalTask->lease_expires_at);
        $this->assertNull($terminalTask->heartbeat_at);
        $this->assertNull($terminalTask->result);
    }

    public function test_reopen_retryable_terminal_duplicate_returns_false_when_attempt_budget_is_exhausted(): void
    {
        $task = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $task->status = AtlasLoopTask::STATUS_FAILED;
        $task->attempts = 2;
        $task->max_attempts = 2;
        $task->claimed_by = 'worker-exhausted';
        $task->result = ['has_winner' => false, 'reason' => 'quality_bar:below_min:7.33'];

        $this->assertFalse(
            $this->invoke('reopenRetryableTerminalDuplicate', [$task]),
            'a no-winner terminal duplicate with no attempt budget remaining must stay terminal',
        );
        $this->assertFalse($task->saveCalled, 'attempt-exhausted duplicates must not be reopened or persisted again');
        $this->assertSame(AtlasLoopTask::STATUS_FAILED, $task->status);
        $this->assertSame('worker-exhausted', $task->claimed_by);
        $this->assertSame(['has_winner' => false, 'reason' => 'quality_bar:below_min:7.33'], $task->result);

        $retryableTask = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $retryableTask->status = AtlasLoopTask::STATUS_FAILED;
        $retryableTask->attempts = 1;
        $retryableTask->max_attempts = 2;
        $retryableTask->claimed_by = 'worker-retryable';
        $retryableTask->result = ['has_winner' => false, 'reason' => 'quality_bar:below_min:7.33'];

        $this->assertTrue(
            $this->invoke('reopenRetryableTerminalDuplicate', [$retryableTask]),
            'the same no-winner terminal duplicate should reopen once it still has attempt budget remaining',
        );
        $this->assertTrue($retryableTask->saveCalled, 'retryable duplicates should be reopened and persisted as live supply');
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $retryableTask->status);
        $this->assertNull($retryableTask->claimed_by);
        $this->assertNull($retryableTask->result);
    }

    public function test_reopen_retryable_terminal_duplicate_reopens_a_no_winner_task_with_attempt_budget_remaining(): void
    {
        $task = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $task->status = AtlasLoopTask::STATUS_FAILED;
        $task->claimed_by = 'worker-A';
        $task->attempts = 1;
        $task->max_attempts = 2;
        $task->result = ['has_winner' => false, 'reason' => 'quality_bar:below_min:7.33'];

        $this->assertTrue(
            $this->invoke('reopenRetryableTerminalDuplicate', [$task]),
            'a no-winner terminal duplicate with attempts left should be reopened as live supply',
        );

        $this->assertTrue($task->saveCalled);
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status);
        $this->assertNull($task->claimed_by);
        $this->assertNull($task->claimed_at);
        $this->assertNull($task->lease_expires_at);
        $this->assertNull($task->heartbeat_at);
        $this->assertNull($task->result);
        $this->assertNotNull($task->updated_at);
    }

    public function test_reopen_retryable_terminal_duplicate_treats_missing_has_winner_flag_as_retryable_no_winner_supply(): void
    {
        $task = new class extends AtlasLoopTask
        {
            public bool $saveCalled = false;

            public function save(array $options = []): bool
            {
                $this->saveCalled = true;

                return true;
            }
        };
        $task->status = AtlasLoopTask::STATUS_DEFERRED;
        $task->claimed_by = 'worker-B';
        $task->attempts = 1;
        $task->max_attempts = 2;
        $task->result = ['reason' => 'needs_retry'];

        $this->assertTrue(
            $this->invoke('reopenRetryableTerminalDuplicate', [$task]),
            'missing has_winner should behave like no winner, so retryable terminal supply is reopened',
        );

        $this->assertTrue($task->saveCalled);
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status);
        $this->assertNull($task->claimed_by);
        $this->assertNull($task->claimed_at);
        $this->assertNull($task->lease_expires_at);
        $this->assertNull($task->heartbeat_at);
        $this->assertNull($task->result);
        $this->assertNotNull($task->updated_at);
    }

    public function test_mark_projected_loop_self_improvement_leaves_non_harness_targets_unchanged(): void
    {
        $payload = [
            'objective_kind' => 'refactor_reduce_complexity',
            'acceptance' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/FooTest.php'],
                'complexity_proof' => true,
            ],
        ];

        $result = $this->invoke('markProjectedLoopSelfImprovement', [$payload, 'app/Services/Domain/FooService.php']);

        $this->assertSame($payload, $result, 'only harness targets should be marked as loop self-improvement');
        $this->assertArrayNotHasKey('is_self_improvement', $result);
        $this->assertArrayNotHasKey('quality_bar', $result);
        $this->assertArrayNotHasKey('quality_bar_gate', $result['acceptance']);
    }

    public function test_mark_projected_loop_self_improvement_marks_harness_complexity_refactors_and_sets_quality_bar_gate(): void
    {
        $payload = [
            'objective_kind' => 'refactor_reduce_complexity',
            'acceptance' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/DemoLoopHarnessTest.php'],
                'complexity_proof' => true,
            ],
        ];

        $result = $this->invoke('markProjectedLoopSelfImprovement', [$payload, 'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php']);

        $this->assertTrue((bool) ($result['is_self_improvement'] ?? false), 'eligible harness refactors must be classified as governed self-improvement');
        $this->assertTrue((bool) ($result['acceptance']['quality_bar_gate'] ?? false), 'eligible harness refactors must enforce the quality-bar gate');
        $this->assertSame(9.0, (float) ($result['quality_bar'] ?? 0.0));
        $this->assertSame(9.0, (float) ($result['acceptance']['quality_bar'] ?? 0.0));
    }


    public function test_mark_projected_loop_self_improvement_requires_a_refactor_objective_kind(): void
    {
        $basePayload = [
            'acceptance' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/DemoLoopHarnessTest.php'],
                'complexity_proof' => true,
            ],
        ];

        $featureResult = $this->invoke('markProjectedLoopSelfImprovement', [
            $basePayload + ['objective_kind' => 'feature'],
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertArrayNotHasKey(
            'is_self_improvement',
            $featureResult,
            'non-refactor objectives must not be laundered into governed self-improvement even on harness targets',
        );
        $this->assertArrayNotHasKey('quality_bar', $featureResult);
        $this->assertArrayNotHasKey('quality_bar_gate', $featureResult['acceptance']);

        $refactorResult = $this->invoke('markProjectedLoopSelfImprovement', [
            $basePayload + ['objective_kind' => 'refactor_reduce_complexity'],
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertTrue(
            (bool) ($refactorResult['is_self_improvement'] ?? false),
            'the same eligible harness payload should become governed self-improvement once its objective kind is a refactor',
        );
        $this->assertTrue((bool) ($refactorResult['acceptance']['quality_bar_gate'] ?? false));
    }

    public function test_mark_projected_loop_self_improvement_requires_both_a_refactor_kind_and_a_real_target_path(): void
    {
        $payload = [
            'objective_kind' => 'feature_addition',
            'acceptance' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/DemoLoopHarnessTest.php'],
                'complexity_proof' => true,
            ],
        ];

        $nonRefactor = $this->invoke('markProjectedLoopSelfImprovement', [
            $payload,
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertArrayNotHasKey(
            'is_self_improvement',
            $nonRefactor,
            'harness work with commands and complexity proof must still stay ordinary when the objective is not a refactor',
        );
        $this->assertArrayNotHasKey('quality_bar', $nonRefactor);
        $this->assertArrayNotHasKey('quality_bar_gate', $nonRefactor['acceptance']);

        $eligible = $this->invoke('markProjectedLoopSelfImprovement', [
            array_merge($payload, ['objective_kind' => 'refactor_reduce_complexity']),
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertTrue((bool) ($eligible['is_self_improvement'] ?? false));
        $this->assertTrue((bool) ($eligible['acceptance']['quality_bar_gate'] ?? false));

        $missingTarget = $this->invoke('markProjectedLoopSelfImprovement', [
            array_merge($payload, ['objective_kind' => 'refactor_reduce_complexity']),
            '',
        ]);

        $this->assertArrayNotHasKey(
            'is_self_improvement',
            $missingTarget,
            'the same refactor contract must stay unmarked when the target path is missing',
        );
        $this->assertArrayNotHasKey('quality_bar', $missingTarget);
        $this->assertArrayNotHasKey('quality_bar_gate', $missingTarget['acceptance']);
    }

    public function test_mark_projected_loop_self_improvement_requires_a_non_empty_acceptance_command_list(): void
    {
        $basePayload = [
            'objective_kind' => 'refactor_reduce_complexity',
            'acceptance' => [
                'commands' => ['   ', ''],
                'complexity_proof' => true,
            ],
        ];

        $unmarked = $this->invoke('markProjectedLoopSelfImprovement', [
            $basePayload,
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertArrayNotHasKey(
            'is_self_improvement',
            $unmarked,
            'blank acceptance commands must not qualify a harness refactor for self-improvement governance',
        );
        $this->assertArrayNotHasKey('quality_bar', $unmarked);
        $this->assertArrayNotHasKey('quality_bar_gate', $unmarked['acceptance']);

        $marked = $this->invoke('markProjectedLoopSelfImprovement', [
            [
                'objective_kind' => 'refactor_reduce_complexity',
                'acceptance' => [
                    'commands' => ['   ', './vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/DemoLoopHarnessTest.php'],
                    'complexity_proof' => true,
                ],
            ],
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertTrue(
            (bool) ($marked['is_self_improvement'] ?? false),
            'adding one real acceptance command should flip the same harness refactor into governed self-improvement',
        );
        $this->assertTrue((bool) ($marked['acceptance']['quality_bar_gate'] ?? false));
    }

    public function test_mark_projected_loop_self_improvement_honors_payload_complexity_proof_and_rejects_falsey_values(): void
    {
        $basePayload = [
            'objective_kind' => 'refactor_reduce_complexity',
            'acceptance' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/Ai/AutonomousEvolution/DemoLoopHarnessTest.php'],
            ],
        ];

        $marked = $this->invoke('markProjectedLoopSelfImprovement', [
            $basePayload + ['complexity_proof' => 'true'],
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertTrue(
            (bool) ($marked['is_self_improvement'] ?? false),
            'payload-level complexity proof should also qualify a harness complexity refactor',
        );
        $this->assertTrue((bool) ($marked['acceptance']['quality_bar_gate'] ?? false));

        $unmarked = $this->invoke('markProjectedLoopSelfImprovement', [
            $basePayload + ['complexity_proof' => 'false'],
            'app/Services/Ai/AutonomousEvolution/DemoLoopHarness.php',
        ]);

        $this->assertArrayNotHasKey(
            'is_self_improvement',
            $unmarked,
            'falsey payload-level complexity proof must not mark the task as self-improvement',
        );
        $this->assertArrayNotHasKey('quality_bar', $unmarked);
        $this->assertArrayNotHasKey('quality_bar_gate', $unmarked['acceptance']);
    }

    public function test_truthy_accepts_only_the_worker_supported_true_literals(): void
    {
        foreach ([true, 1, '1', 'true'] as $value) {
            $this->assertTrue(
                $this->invoke('truthy', [$value]),
                sprintf('value %s should be treated as truthy by the worker helper', var_export($value, true)),
            );
        }

        foreach ([false, 0, '0', 'false', 'TRUE', '', null] as $value) {
            $this->assertFalse(
                $this->invoke('truthy', [$value]),
                sprintf('value %s should stay falsey for the worker helper', var_export($value, true)),
            );
        }
    }
}
