<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ForgeOwnerRuntimeDispatchBridge;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueConsumptionGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueReleaseGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeExecutionAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeResultProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerSandboxRuntimeRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\RepairValidationRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\StewardshipOutcomeProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\RepairAgentFeedbackContextBuilderService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Tests\TestCase;

/**
 * Covers the AP-786 repair-agent hardening: repeated-repair detection, the
 * review-lock counter, the pre-return validation gate and the targeted repair
 * feedback context. The executor runs through interface doubles only — it never
 * touches a provider driver router and (with the injected validation runner)
 * never shells out.
 */
final class Ap786OwnerFlowRepairAgentTest extends TestCase
{
    public function test_repeated_same_diff_returns_repeated_repair_no_progress(): void
    {
        // The repair agent re-emits the IDENTICAL broken diff (same changed
        // files, same failure signature, still failing) → no progress, stop
        // before another provider call.
        $broken = $this->failedOwnerResult();
        $runner = $this->sequenceRunner([
            $this->runnerReport($broken, 'afrun_first'),
            $this->runnerReport($broken, 'afrun_repeat'),
        ]);

        $report = $this->executor($runner)->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_REPEATED_REPAIR_NO_PROGRESS, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertContains('owner_runtime_repeated_repair_no_progress', $report['blockers']);
        $this->assertTrue($report['repair_attempt']['repeated_repair_no_progress']);
        // Exactly two AP-759 runs: the initial loop and one repair that repeated.
        $this->assertSame(2, $runner->calls);
    }

    public function test_two_failures_triggers_review_lock(): void
    {
        // Two DIFFERENT failing attempts (different signatures, still failing)
        // hit the review-lock ceiling so the loop advances to the next finding.
        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $second = $this->failedOwnerResult([
            'result_id' => 'afrunres_second',
            'failure_signature' => 'sha256:sig_b',
        ]);
        $runner = $this->sequenceRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($second, 'afrun_second'),
        ]);

        $report = $this->executor($runner)->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_REVIEW_LOCKED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertTrue($report['repair_attempt']['review_locked']);
        $this->assertSame(Ap786OwnerFlowExecutor::REPAIR_REVIEW_LOCK_THRESHOLD, $report['repair_attempt']['repair_failure_count']);
        $this->assertContains('owner_runtime_review_locked', $report['blockers']);
        $this->assertNotSame('', (string) ($report['repair_attempt']['last_error_summary'] ?? ''));
        // The loop stops after exactly two runs; it never retries the slice again.
        $this->assertSame(2, $runner->calls);
    }

    public function test_validation_command_failure_does_not_return_repaired_true(): void
    {
        // The repair reports completed with a real scoped diff, but the
        // pre-return validation command FAILS (exit 1) → not repaired.
        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $repaired = $this->completedOwnerResult();
        $runner = $this->sequenceRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($repaired, 'afrun_repair'),
        ]);
        $validation = $this->validationRunner(7); // non-zero exit

        $report = $this->executor($runner, $validation)->execute($this->input([
            'validation_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php'],
            'worktree_path' => $this->realWorktree(),
        ]));

        // A failed validation must NOT be claimed as a completed repair; with two
        // failures it review-locks instead of merging.
        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertFalse($report['repair_attempt']['repaired'] ?? true);
        $this->assertSame(1, $validation->calls, 'the validation command must actually run');
        $this->assertSame(7, (int) data_get($report, 'repair_attempt.pre_return_validation_result.exit_code'));
        $this->assertTrue((bool) data_get($report, 'repair_attempt.pre_return_validation_result.ran'));
    }

    public function test_validation_command_success_with_real_diff_returns_repaired_true(): void
    {
        // The repair reports completed with a real scoped diff AND the
        // pre-return validation command PASSES (exit 0) → repaired, mergeable.
        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $repaired = $this->completedOwnerResult();
        $runner = $this->sequenceRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($repaired, 'afrun_repair'),
        ]);
        $validation = $this->validationRunner(0); // success

        $report = $this->executor($runner, $validation)->execute($this->input([
            'validation_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php'],
            'worktree_path' => $this->realWorktree(),
        ]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertTrue($report['repair_attempt']['repaired']);
        $this->assertTrue($report['repair_attempt']['retried']);
        $this->assertSame('afrunres_repaired', $report['owner_result']['result_id']);
        $this->assertSame(1, $validation->calls);
        $this->assertSame(0, (int) data_get($report, 'repair_attempt.pre_return_validation_result.exit_code'));
        $this->assertTrue((bool) data_get($report, 'repair_attempt.pre_return_validation_result.passed'));
    }

    public function test_feedback_context_builder_includes_all_required_fields(): void
    {
        $builder = new RepairAgentFeedbackContextBuilderService;

        $context = $builder->build([
            'owner_result' => [
                'changed_files' => ['app/Services/Ai/Foo.php'],
                'runtime_invocation' => [
                    'senior_loop' => [
                        'debug_loop' => [
                            'reason' => 'focused phpunit failed after scoped diff',
                            'failure_capsules' => [[
                                'failing_test' => './vendor/bin/phpunit tests/Unit/Ai/FooTest.php',
                                'primary_error_excerpt' => 'Class App\\Services\\Ai\\Foo located in ./app/Services/Ai/Wrong.php does not comply with psr-4 autoloading standard. Skipping.',
                                'failure_signature' => 'sha256:sig',
                            ]],
                        ],
                    ],
                ],
            ],
            'allowed_files' => ['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'],
            'forbidden_files' => ['config/secrets.php'],
            'validation_commands' => ['git diff --check', './vendor/bin/phpunit tests/Unit/Ai/FooTest.php'],
            'rejected_diff' => 'diff --git a/app/Services/Ai/Foo.php b/app/Services/Ai/Foo.php',
            'merge_rejection_reason' => 'branch is not fast-forward to main',
            'sandbox_current_commit' => 'abc1234',
            'repair_attempt_number' => 2,
        ]);

        foreach ([
            'failed_test',
            'test_error_output',
            'lint_errors',
            'failed_file',
            'allowed_files',
            'forbidden_files',
            'expected_namespace',
            'validation_command',
            'rejected_diff',
            'merge_rejection_reason',
            'sandbox_current_commit',
            'repair_attempt_number',
        ] as $field) {
            $this->assertArrayHasKey($field, $context, "feedback context must expose {$field}");
        }

        $this->assertSame('./vendor/bin/phpunit tests/Unit/Ai/FooTest.php', $context['failed_test']);
        $this->assertStringContainsString('does not comply with psr-4', (string) $context['test_error_output']);
        $this->assertNotEmpty($context['lint_errors']);
        $this->assertSame('app/Services/Ai/Wrong.php', $context['lint_errors'][0]['file']);
        $this->assertSame('app/Services/Ai/Wrong.php', $context['failed_file']);
        $this->assertSame(['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'], $context['allowed_files']);
        $this->assertSame(['config/secrets.php'], $context['forbidden_files']);
        $this->assertSame('App\\Services\\Ai', $context['expected_namespace']);
        // The focused phpunit command is preferred over the `git diff --check` lint.
        $this->assertSame('./vendor/bin/phpunit tests/Unit/Ai/FooTest.php', $context['validation_command']);
        $this->assertSame('branch is not fast-forward to main', $context['merge_rejection_reason']);
        $this->assertSame('abc1234', $context['sandbox_current_commit']);
        $this->assertSame(2, $context['repair_attempt_number']);
        $this->assertIsString($context['rejected_diff']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function failedOwnerResult(array $overrides = []): array
    {
        $signature = (string) ($overrides['failure_signature'] ?? 'sha256:sig_default');
        unset($overrides['failure_signature']);

        return array_replace([
            'result_id' => 'afrunres_failed',
            'result_status' => 'failed',
            'completion_state' => 'failed',
            'provider_invoked' => true,
            'changed_files' => ['app/Services/Ai/Example.php'],
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'failed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                ],
                'senior_loop' => [
                    'run_summary' => [
                        'scope_guard_status' => 'passed',
                        'verification_status' => 'failed',
                    ],
                    'debug_loop' => [
                        'reason' => 'focused phpunit failed after scoped diff',
                        'failure_capsules' => [['ref' => 'receipts/dev/failure.json', 'failure_signature' => $signature]],
                    ],
                ],
            ],
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function completedOwnerResult(): array
    {
        return [
            'result_id' => 'afrunres_repaired',
            'result_status' => 'completed',
            'completion_state' => 'passed',
            'provider_invoked' => true,
            'changed_files' => ['app/Services/Ai/Example.php'],
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'passed',
                    'owner_cli_blockers' => [],
                    'owner_cli_provider_calls' => 1,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return array<string,mixed>
     */
    private function runnerReport(array $ownerResult, string $runId): array
    {
        return [
            'status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            'owner_sandbox_run_id' => $runId,
            'owner_result' => $ownerResult,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $reports
     */
    private function sequenceRunner(array $reports): OwnerSandboxRuntimeRunner
    {
        return new class($reports) implements OwnerSandboxRuntimeRunner
        {
            public int $calls = 0;

            /** @param list<array<string,mixed>> $reports */
            public function __construct(private array $reports) {}

            public function project(array $input): array
            {
                $this->calls++;

                return array_shift($this->reports) ?? [
                    'status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED,
                    'owner_result' => [],
                ];
            }
        };
    }

    private function validationRunner(int $exitCode): RepairValidationRunner
    {
        return new class($exitCode) implements RepairValidationRunner
        {
            public int $calls = 0;

            public function __construct(private int $exitCode) {}

            public function validate(string $worktree, string $command): array
            {
                $this->calls++;

                return [
                    'exit_code' => $this->exitCode,
                    'output' => $this->exitCode === 0 ? 'OK (1 test)' : 'FAILURES! Tests: 1, Failures: 1.',
                    'ran' => true,
                ];
            }
        };
    }

    private function realWorktree(): string
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-repair-'.bin2hex(random_bytes(4));
        mkdir($worktree, 0777, true);
        $this->beforeApplicationDestroyed(static function () use ($worktree): void {
            @rmdir($worktree);
        });

        return $worktree;
    }

    private function executor(OwnerSandboxRuntimeRunner $runner, ?RepairValidationRunner $validation = null): Ap786OwnerFlowExecutor
    {
        return new Ap786OwnerFlowExecutor(
            new class implements OwnerQueueReleaseGate
            {
                public function release(array $input): array
                {
                    return [
                        'status' => AreaFocusDevForgeReleaseService::STATUS_READY,
                        'release_id' => 'afrel_x',
                        'queue_item' => ['queue_item_id' => 'afq_x'],
                    ];
                }
            },
            new class implements StewardshipOutcomeProjector
            {
                public function project(array $input = []): array
                {
                    return ['status' => StewardshipOutcomeEvidenceBridgeService::STATUS_READY];
                }
            },
            new class implements OwnerQueueConsumptionGate
            {
                public function project(array $input): array
                {
                    return [
                        'status' => AreaFocusOwnerQueueConsumptionGateService::STATUS_READY,
                        'consumption_id' => 'afcons_x',
                        'release_id' => 'afrel_x',
                        'queue_item_id' => 'afq_x',
                        'sandbox_binding' => ['sandbox_id' => 'afsb_x', 'branch_name' => 'atlas/area-focus/x'],
                    ];
                }
            },
            new class implements OwnerRuntimeExecutionAdapter
            {
                public function project(array $input): array
                {
                    return [
                        'status' => StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY,
                        'owner_execution_id' => 'afexec_x',
                    ];
                }
            },
            $runner,
            new class implements OwnerRuntimeResultProjector
            {
                public function project(array $input): array
                {
                    return [
                        'status' => StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
                        'result_bridge_id' => 'afobr_x',
                    ];
                }
            },
            new ForgeOwnerRuntimeDispatchBridge,
            $validation,
            new RepairAgentFeedbackContextBuilderService,
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'owner' => 'atlas_dev',
            'actor' => 'operator',
            'finding' => [
                'finding_id' => 'aff_repair',
                'title' => 'Repair the failing scoped diff',
                'proposed_next_action' => 'Fix the focused test failure.',
            ],
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'preflight_report' => ['handoff_packet' => ['handoff_hash' => 'sha256:handoff_x']],
            'sandbox_record' => ['sandbox_id' => 'afsb_x', 'status' => 'materialized'],
            'worktree_path' => '/tmp/atlas-ap786-worktree-missing',
            'execute' => true,
            'validation_commands' => ['git diff --check'],
        ], $overrides);
    }
}
