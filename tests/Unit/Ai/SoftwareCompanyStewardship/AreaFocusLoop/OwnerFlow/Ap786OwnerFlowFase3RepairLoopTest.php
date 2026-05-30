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
 * FASE 3 FAILING HARNESS — iterative repair loop, hard-step model escalation to
 * claude-opus-4-8 and the meaningful-test verifier (anti coverage-theater).
 *
 * Spec:
 * docs/engineering-knowledge-base/atlas-fase3-worker-repair-loop-escalation-meaningful-tests.md
 *
 * Every contract case below is guarded by markFase3Incomplete() so the suite
 * stays GREEN until the FASE 3 implementation exists. Once a capability lands,
 * delete its guard line (and provide the seam it injects) so the assertions run
 * for real. The guards reference symbols the implementation MUST create
 * (constants, the MeaningfulTestVerifier seam) so this file documents the exact
 * target API.
 *
 * These tests run through interface doubles only: no provider driver router is
 * ever touched and the unit suite never shells out. FASE 3 is provider-dependent
 * (it changes how the MiniMax/Opus worker is prompted and which model runs hard
 * steps) — it is proven here with doubles, NEVER with a live provider call.
 *
 * @group fase3
 */
final class Ap786OwnerFlowFase3RepairLoopTest extends TestCase
{
    // ---------------------------------------------------------------------
    // §2.1 Iterative repair loop with validation-output feedback
    // ---------------------------------------------------------------------

    /** Iteration N+1 must be prompted with the REAL validation output of iteration N. */
    public function test_repair_loop_feeds_previous_validation_output_into_next_attempt(): void
    {
        $this->markFase3Incomplete('iterative repair loop must thread the previous iteration validation output into the next worker command');

        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $second = $this->failedOwnerResult([
            'result_id' => 'afrunres_second',
            'failure_signature' => 'sha256:sig_b',
        ]);
        $runner = $this->commandRecordingRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($second, 'afrun_second'),
        ]);
        // The first repair validation fails with a recognizable error string that
        // the loop must feed into the next worker prompt.
        $validation = $this->scriptedValidationRunner([
            ['exit_code' => 1, 'output' => 'FAILED: expected 2 got 3 in CalculatorTest::test_adds', 'ran' => true],
        ]);

        $this->executor($runner, $validation)->execute($this->input([
            'validation_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/CalculatorTest.php'],
            'worktree_path' => $this->realWorktree(),
        ]));

        $secondCommand = $this->commandText($runner->commands[1] ?? []);
        $this->assertStringContainsString('expected 2 got 3', $secondCommand, 'iteration 2 must carry iteration 1 validation output');
    }

    /** The loop must iterate up to MAX_REPAIR_ITERATIONS then review-lock (not one-shot). */
    public function test_repair_loop_is_bounded_to_max_iterations_then_review_locks(): void
    {
        $this->markFase3Incomplete('repair loop must iterate up to MAX_REPAIR_ITERATIONS then review-lock');

        $reports = [];
        for ($i = 0; $i <= Ap786OwnerFlowExecutor::MAX_REPAIR_ITERATIONS; $i++) {
            $reports[] = $this->runnerReport(
                $this->failedOwnerResult(['failure_signature' => 'sha256:sig_'.$i, 'result_id' => 'afrunres_'.$i]),
                'afrun_'.$i,
            );
        }
        $runner = $this->commandRecordingRunner($reports);

        $report = $this->executor($runner)->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_REVIEW_LOCKED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertLessThanOrEqual(1 + Ap786OwnerFlowExecutor::MAX_REPAIR_ITERATIONS, $runner->calls);
        $this->assertGreaterThan(2, $runner->calls, 'FASE 3 must iterate more than the old single repair');
    }

    // ---------------------------------------------------------------------
    // §2.2 Hard-step model escalation to claude-opus-4-8
    // ---------------------------------------------------------------------

    /** A finding flagged hard must route the repair attempt to claude-opus-4-8 under the Claude CLI. */
    public function test_hard_finding_escalates_repair_to_claude_opus_4_8(): void
    {
        $this->markFase3Incomplete('hard finding must escalate the repair command to claude-opus-4-8 / claude_cli');

        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $second = $this->failedOwnerResult(['result_id' => 'afrunres_second', 'failure_signature' => 'sha256:sig_b']);
        $runner = $this->commandRecordingRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($second, 'afrun_second'),
        ]);

        $report = $this->executor($runner)->execute($this->input([
            'provider' => 'cursor',
            'finding' => [
                'finding_id' => 'aff_hard',
                'title' => 'Hard scoped repair',
                'difficulty' => 'hard',
                'escalate_to_premium' => true,
                'proposed_next_action' => 'Fix the focused test failure.',
            ],
        ]));

        $repairCommand = $this->commandText($runner->commands[1] ?? []);
        $this->assertStringContainsString('--composer-model='.Ap786OwnerFlowExecutor::ESCALATION_MODEL, $repairCommand);
        $this->assertStringContainsString('--provider-choice=claude_cli', $repairCommand);
        $this->assertSame(Ap786OwnerFlowExecutor::ESCALATION_MODEL, (string) data_get($report, 'repair_attempt.escalated_model'));
        $this->assertSame('claude-opus-4-8', Ap786OwnerFlowExecutor::ESCALATION_MODEL, 'canonical premium id, never claude-opus-4-7');
    }

    /** Persistent failure on the cheap model must escalate a later iteration to Opus. */
    public function test_persistent_cheap_failure_escalates_to_opus_after_threshold(): void
    {
        $this->markFase3Incomplete('persistent cheap failure must escalate a later repair iteration to claude-opus-4-8');

        $reports = [];
        for ($i = 0; $i <= Ap786OwnerFlowExecutor::MAX_REPAIR_ITERATIONS; $i++) {
            $reports[] = $this->runnerReport(
                $this->failedOwnerResult(['failure_signature' => 'sha256:sig_'.$i, 'result_id' => 'afrunres_'.$i]),
                'afrun_'.$i,
            );
        }
        $runner = $this->commandRecordingRunner($reports);

        $this->executor($runner)->execute($this->input(['provider' => 'cursor']));

        // The iteration at/after ESCALATE_AFTER_ITERATION must use Opus.
        $escalatedIndex = Ap786OwnerFlowExecutor::ESCALATE_AFTER_ITERATION; // 0-based: initial run is index 0
        $escalatedCommand = $this->commandText($runner->commands[$escalatedIndex] ?? []);
        $this->assertStringContainsString('--composer-model='.Ap786OwnerFlowExecutor::ESCALATION_MODEL, $escalatedCommand);
    }

    // ---------------------------------------------------------------------
    // §2.3 Meaningful-test verifier (anti coverage-theater)
    // ---------------------------------------------------------------------

    /** A scoped, exit-0 repair whose test only does assertTrue(true) must be rejected. */
    public function test_coverage_theater_repair_is_rejected(): void
    {
        $this->markFase3Incomplete('meaningful-test verifier must reject vacuous (coverage-theater) repairs');

        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $repaired = $this->completedOwnerResult();
        $runner = $this->commandRecordingRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($repaired, 'afrun_repair'),
        ]);
        $validation = $this->scriptedValidationRunner([
            ['exit_code' => 0, 'output' => 'OK (1 test, 1 assertion)', 'ran' => true],
        ]);
        // Verifier rejects: the test asserts nothing real.
        $meaningful = $this->meaningfulVerifier(false, 'test asserts assertTrue(true); does not exercise the changed symbol');

        $report = $this->executor($runner, $validation, $meaningful)->execute($this->input([
            'validation_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php'],
            'worktree_path' => $this->realWorktree(),
        ]));

        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertFalse((bool) data_get($report, 'repair_attempt.repaired', true));
        $this->assertContains(Ap786OwnerFlowExecutor::BLOCKER_COVERAGE_THEATER, $report['blockers']);
    }

    /** A repair with a real assertion referencing the changed symbol passes the verifier. */
    public function test_meaningful_test_repair_is_accepted(): void
    {
        $this->markFase3Incomplete('meaningful-test verifier must accept a real assertion that exercises the change');

        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $repaired = $this->completedOwnerResult();
        $runner = $this->commandRecordingRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($repaired, 'afrun_repair'),
        ]);
        $validation = $this->scriptedValidationRunner([
            ['exit_code' => 0, 'output' => 'OK (1 test, 2 assertions)', 'ran' => true],
        ]);
        $meaningful = $this->meaningfulVerifier(true, 'asserts Calculator::add output');

        $report = $this->executor($runner, $validation, $meaningful)->execute($this->input([
            'validation_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php'],
            'worktree_path' => $this->realWorktree(),
        ]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertTrue((bool) data_get($report, 'repair_attempt.repaired'));
    }

    /** Provider-proof law is never weakened: a zero-provider-call repaired diff is still rejected. */
    public function test_provider_proof_still_required_under_fase3(): void
    {
        $this->markFase3Incomplete('provider-proof law must remain: zero provider calls never merges, even with a meaningful test');

        $first = $this->failedOwnerResult(['failure_signature' => 'sha256:sig_a']);
        $repaired = $this->completedOwnerResult();
        // Strip provider proof from the repaired result.
        $repaired['provider_invoked'] = false;
        data_set($repaired, 'runtime_invocation.command_result.owner_cli_provider_calls', 0);
        $runner = $this->commandRecordingRunner([
            $this->runnerReport($first, 'afrun_first'),
            $this->runnerReport($repaired, 'afrun_repair'),
        ]);
        $validation = $this->scriptedValidationRunner([
            ['exit_code' => 0, 'output' => 'OK (1 test, 2 assertions)', 'ran' => true],
        ]);
        $meaningful = $this->meaningfulVerifier(true, 'real assertion');

        $report = $this->executor($runner, $validation, $meaningful)->execute($this->input([
            'validation_commands' => ['./vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php'],
            'worktree_path' => $this->realWorktree(),
        ]));

        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
    }

    // ---------------------------------------------------------------------
    // Guard + fixtures
    // ---------------------------------------------------------------------

    /**
     * FASE 3 is not implemented yet. Keep the suite green by marking the case
     * incomplete. Delete this call (per case) when the matching capability lands.
     */
    private function markFase3Incomplete(string $capability): void
    {
        $this->markTestIncomplete('FASE 3 not implemented — '.$capability.' (see atlas-fase3-worker-repair-loop-escalation-meaningful-tests.md).');
    }

    /** @param list<int|string> $command */
    private function commandText(array $command): string
    {
        return implode(' ', array_map(static fn ($p): string => (string) $p, $command));
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
     * A runner that records the exact command argv of every AP-759 invocation so
     * the harness can assert what was prompted (feedback / escalation flags).
     *
     * @param  list<array<string,mixed>>  $reports
     */
    private function commandRecordingRunner(array $reports): OwnerSandboxRuntimeRunner
    {
        return new class($reports) implements OwnerSandboxRuntimeRunner
        {
            public int $calls = 0;

            /** @var list<list<int|string>> */
            public array $commands = [];

            /** @param list<array<string,mixed>> $reports */
            public function __construct(private array $reports) {}

            public function project(array $input): array
            {
                $this->calls++;
                $command = (array) data_get($input, 'runtime_command_receipt.command', []);
                $this->commands[] = array_values(array_map(
                    static fn ($p) => is_int($p) ? $p : (string) $p,
                    $command,
                ));

                return array_shift($this->reports) ?? [
                    'status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED,
                    'owner_result' => [],
                ];
            }
        };
    }

    /**
     * Validation runner that returns a scripted result per call (so the harness
     * can drive a distinct failure string into each repair iteration). After the
     * script is exhausted it returns a passing result.
     *
     * @param  list<array{exit_code:int,output:string,ran:bool}>  $script
     */
    private function scriptedValidationRunner(array $script): RepairValidationRunner
    {
        return new class($script) implements RepairValidationRunner
        {
            public int $calls = 0;

            /** @param list<array{exit_code:int,output:string,ran:bool}> $script */
            public function __construct(private array $script) {}

            public function validate(string $worktree, string $command): array
            {
                $this->calls++;

                return array_shift($this->script) ?? [
                    'exit_code' => 0,
                    'output' => 'OK (1 test)',
                    'ran' => true,
                ];
            }
        };
    }

    /**
     * Fake MeaningfulTestVerifier. The interface does not exist yet; the cases
     * that use it are guarded by markFase3Incomplete() and will not construct it
     * until the FASE 3 seam lands. The closure-typed factory keeps this file
     * loadable today (no hard reference to a missing class at parse time).
     */
    private function meaningfulVerifier(bool $passed, string $reason): object
    {
        $interface = 'App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\OwnerFlow\\MeaningfulTestVerifier';
        if (! interface_exists($interface)) {
            $this->markFase3Incomplete('MeaningfulTestVerifier seam not implemented yet');
        }

        return new class($passed, $reason) implements \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\MeaningfulTestVerifier
        {
            public function __construct(private bool $passed, private string $reason) {}

            public function verify(string $worktree, array $changedFiles, array $testFiles): array
            {
                return ['passed' => $this->passed, 'reason' => $this->reason, 'signals' => []];
            }
        };
    }

    private function realWorktree(): string
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-fase3-'.bin2hex(random_bytes(4));
        mkdir($worktree, 0777, true);
        $this->beforeApplicationDestroyed(static function () use ($worktree): void {
            @rmdir($worktree);
        });

        return $worktree;
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

    private function executor(
        OwnerSandboxRuntimeRunner $runner,
        ?RepairValidationRunner $validation = null,
        ?object $meaningful = null,
    ): Ap786OwnerFlowExecutor {
        // NOTE: the meaningful-test verifier is a FASE 3 seam not yet present on
        // the constructor. Cases that pass $meaningful are guarded incomplete
        // until the constructor gains its optional nullable parameter.
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
}
