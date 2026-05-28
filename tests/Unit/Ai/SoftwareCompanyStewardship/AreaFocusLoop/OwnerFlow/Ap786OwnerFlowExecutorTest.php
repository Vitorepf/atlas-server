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
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\StewardshipOutcomeProjector;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Tests\TestCase;

/**
 * Proves the AP-786 owner-flow executor composes the REAL owner chain in order
 * (AP-747 -> AP-748 -> AP-749 -> AP-758 -> AP-759 -> AP-750), threads the owner
 * result into AP-750, blocks before the result bridge when AP-759 fails, and
 * never claims a fake Forge dispatch. It uses interface spies — the executor
 * never touches a provider driver router.
 */
final class Ap786OwnerFlowExecutorTest extends TestCase
{
    /** @var object{log:list<string>,captured:array<string,array<string,mixed>>} */
    private object $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new class
        {
            /** @var list<string> */
            public array $log = [];

            /** @var array<string,array<string,mixed>> */
            public array $captured = [];

            /** @param array<string,mixed> $input */
            public function rec(string $ap, array $input): void
            {
                $this->log[] = $ap;
                $this->captured[$ap] = $input;
            }
        };
    }

    public function test_executes_owner_chain_in_order_and_bridges_owner_result(): void
    {
        $ownerResult = $this->ownerResult('completed');
        $executor = $this->executor(['runner' => $this->runnerReport($ownerResult)]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['uses_full_owner_runtime_chain']);
        $this->assertFalse($report['provider_router_used']);
        $this->assertTrue($report['merge_allowed']);

        // Owners are invoked in the canonical order.
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759', 'AP-750'], $this->recorder->log);

        // AP-750 received exactly the AP-759 owner_result and the AP-749 consumption.
        $this->assertSame($ownerResult['result_id'], $this->recorder->captured['AP-750']['owner_result']['result_id']);
        $this->assertSame('afcons_x', (string) data_get($this->recorder->captured['AP-750'], 'consumption_report.consumption_id'));
        $this->assertSame('start_owner_runtime', data_get($this->recorder->captured['AP-749'], 'execution_receipt.decision'));
        $this->assertSame('operator', data_get($this->recorder->captured['AP-749'], 'execution_receipt.operator_actor'));
        $this->assertSame('afrel_x', data_get($this->recorder->captured['AP-749'], 'execution_receipt.target_release_id'));
        $this->assertSame('afq_x', data_get($this->recorder->captured['AP-749'], 'execution_receipt.target_queue_item_id'));
        $this->assertNotSame('', (string) $report['result_bridge_id']);
        $this->assertSame($ownerResult, $report['owner_result']);

        // Regression: atlas_dev keeps using the senior-loop owner command.
        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $this->assertContains('atlas:dev:senior-loop:run', $command);
        $this->assertSame(base_path('artisan'), $command[1] ?? null);
        $this->assertContains('--allowed-file=app/Services/Ai/Example.php', $command);
        $this->assertContains('--validation-command=git diff --check', $command);
        $this->assertContains('--flow-origin=atlas_ai_router', $command);
        $this->assertContains('--operator-explicit', $command);
        $this->assertContains('--provider-choice=cursor_cli', $command);
        $this->assertContains('--composer-model=composer-2.5-fast', $command);
        $this->assertTrue(data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.provider_execution_authorized'));
        $this->assertTrue(data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.budget_approved'));
        $this->assertSame('cursor_cli', data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.provider_choice'));
        $this->assertSame('composer-2.5-fast', data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.model_family'));
    }

    public function test_atlas_dev_owner_intent_includes_concrete_target_files_tests_and_no_patch_guard(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'finding' => [
                'finding_id' => 'factory_max_ap786_loop_hardening',
                'title' => 'Harden AP-786 autonomous evolution loop against wasted cycles',
                'detail' => 'The loop must stop wasting provider calls on vague work.',
                'proposed_next_action' => 'Implement concrete owner-runtime no-progress handling.',
                'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php'],
                'spec_seed' => [
                    'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php'],
                    'acceptance' => ['Provider no-diff cycles are recorded and skipped next time.'],
                ],
            ],
            'allowed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            ],
        ]));

        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $intentArg = collect($command)->first(static fn ($arg): bool => is_string($arg) && str_starts_with($arg, '--intent='));

        $this->assertIsString($intentArg);
        $this->assertStringContainsString('OBJECTIVE:', $intentArg);
        $this->assertStringContainsString('Harden AP-786 autonomous evolution loop', $intentArg);
        $this->assertStringContainsString('ALLOWED_FILES:', $intentArg);
        $this->assertStringContainsString('AutonomousEvolutionSessionService.php', $intentArg);
        $this->assertStringContainsString('TESTS_REQUIRED:', $intentArg);
        $this->assertStringContainsString('AutonomousEvolutionSessionServiceTest.php', $intentArg);
        $this->assertStringContainsString('PATCH_MANDATE:', $intentArg);
        $this->assertStringContainsString('no_patch_needed', $intentArg);
    }

    public function test_atlas_dev_owner_command_honors_provider_and_model_from_atlas_decide_input(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'provider' => 'codex',
            'model' => 'gpt-5.5',
        ]));

        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');

        $this->assertContains('--provider-choice=codex_cli', $command);
        $this->assertContains('--composer-model=gpt-5.5', $command);
        $this->assertSame('codex_cli', data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.provider_choice'));
        $this->assertSame('gpt-5.5', data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.model_family'));
    }

    public function test_atlas_dev_owner_command_uses_worktree_artisan_when_present(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-worktree-'.bin2hex(random_bytes(4));
        mkdir($worktree, 0777, true);
        touch($worktree.'/artisan');

        try {
            $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);
            $executor->execute($this->input(['worktree_path' => $worktree]));

            $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
            $this->assertSame($worktree.'/artisan', $command[1] ?? null);
        } finally {
            @unlink($worktree.'/artisan');
            @rmdir($worktree);
        }
    }


    public function test_owner_intent_sanitizes_forge_preview_phrases_for_executable_routing(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'finding' => [
                'finding_id' => 'factory_max_ap790_blocked_cycle_mergeable_test',
                'title' => 'Add mergeable AP-790 blocked-cycle regression coverage for Atlas Forge multi-agent',
                'detail' => 'Whole system forge obra promotion preview must stay out of atlas_dev fast path.',
                'proposed_next_action' => 'Implement a focused test-only patch.',
                'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php'],
                'spec_seed' => [
                    'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php'],
                    'acceptance' => ['Focused test proves blocked-cycle summary behavior.'],
                ],
            ],
            'allowed_files' => [
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
            ],
        ]));

        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $intentArg = collect($command)->first(static fn ($arg): bool => is_string($arg) && str_starts_with($arg, '--intent='));

        $this->assertIsString($intentArg);
        $this->assertStringNotContainsString('multi-agent', strtolower($intentArg));
        $this->assertStringNotContainsString('forge promotion preview', strtolower($intentArg));
        $this->assertStringContainsString('governed workcell', strtolower($intentArg));
        $this->assertStringContainsString('HARDEN', $intentArg);
    }

    public function test_owner_intent_sanitizes_dev_forge_flow_phrase_without_hiding_class_targets(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'finding' => [
                'finding_id' => 'factory_max_missing_cursor_driver_test',
                'title' => 'Missing test for AtlasForgeCursorSdkInvocationDriver',
                'detail' => 'AtlasForgeCursorSdkInvocationDriver is a factory-critical runtime class in the AAEOS / Atlas Dev / Forge flow without same-name focused coverage.',
                'affected_files' => ['app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php'],
                'spec_seed' => [
                    'tests_required' => ['tests/Unit/Ai/Programming/AtlasForgeCursorSdkInvocationDriverTest.php'],
                    'acceptance' => ['Focused test proves Cursor SDK driver behavior.'],
                ],
            ],
            'allowed_files' => [
                'app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php',
                'tests/Unit/Ai/Programming/AtlasForgeCursorSdkInvocationDriverTest.php',
            ],
        ]));

        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $intentArg = collect($command)->first(static fn ($arg): bool => is_string($arg) && str_starts_with($arg, '--intent='));

        $this->assertIsString($intentArg);
        $this->assertStringContainsString('AtlasForgeCursorSdkInvocationDriver', $intentArg);
        $this->assertStringContainsString('AAEOS software-development flow', $intentArg);
        $this->assertStringNotContainsString('Atlas Dev / Forge flow', $intentArg);
    }

    public function test_blocks_before_result_bridge_when_ap759_blocks(): void
    {
        $executor = $this->executor(['runner' => ['status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED, 'blockers' => ['runtime_command_not_allowed']]]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap759_owner_command_failed', $report['reason']);
        $this->assertFalse($report['merge_allowed']);
        // The result bridge (AP-750) is never reached when AP-759 fails.
        $this->assertNotContains('AP-750', $this->recorder->log);
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759'], $this->recorder->log);
    }

    public function test_forge_blocks_with_precise_reason_when_obra_or_authority_missing(): void
    {
        // No Obra -> honest precise blocker, no owner step, no merge.
        $report = $this->executor()->execute($this->input(['owner' => 'forge']));
        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_BLOCKED, $report['status']);
        $this->assertSame('forge_obra_required', $report['reason']);
        $this->assertFalse($report['provider_router_used']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertSame([], $this->recorder->log);

        // Obra but no live topology -> precise blocker.
        $report = $this->executor()->execute($this->forgeInput(['forge_live_topology' => null]));
        $this->assertSame('forge_live_topology_required', $report['reason']);
        $this->assertSame([], $this->recorder->log);

        // Obra + topology but no live decision -> precise blocker.
        $report = $this->executor()->execute($this->forgeInput(['forge_live_decision' => null]));
        $this->assertSame('forge_live_decision_required', $report['reason']);
        $this->assertSame([], $this->recorder->log);
    }

    public function test_forge_proceeds_with_minimal_inputs_using_allowlisted_runtime_dispatch_command(): void
    {
        $ownerResult = $this->ownerResult('completed', ['changed_files' => ['app/Services/Ai/Forge.php']]);
        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->forgeInput());

        // It did NOT block at forge_obra_dispatch_required; it ran the full chain.
        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_BLOCKED, $report['status']);
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759', 'AP-750'], $this->recorder->log);
        $this->assertFalse($report['provider_router_used']);

        // AP-759 received a real, allowlisted Forge command — never a provider driver.
        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $this->assertContains('atlas:forge:runtime-dispatch', $command);
        $this->assertContains('--strict', $command);
        $this->assertSame(ForgeOwnerRuntimeDispatchBridge::KIND_RUNTIME_DISPATCH, $report['dispatch_kind']);
    }

    public function test_forge_runtime_dispatch_plan_only_is_planned_not_completed(): void
    {
        // runtime-dispatch ran (exit 0) but produced a PLAN with no changed files.
        $ownerResult = $this->ownerResult('completed', ['changed_files' => []]);
        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->forgeInput());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_FORGE_PLANNED, $report['status']);
        $this->assertTrue($report['forge_planned']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertContains('forge_runtime_dispatch_planned_only', $report['blockers']);
        // The plan is still recorded as evidence (AP-750), honestly as non-completed.
        $this->assertContains('AP-750', $this->recorder->log);
        $this->assertSame('partial', (string) data_get($this->recorder->captured['AP-750'], 'owner_result.result_status'));
    }

    public function test_forge_completed_with_changed_files_bridges_owner_result(): void
    {
        $ownerResult = $this->ownerResult('completed', ['changed_files' => ['app/Services/Ai/Forge.php']]);
        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->forgeInput());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertContains('AP-750', $this->recorder->log);
        $this->assertSame($ownerResult['result_id'], $this->recorder->captured['AP-750']['owner_result']['result_id']);
    }

    public function test_owner_result_not_completed_still_bridges_but_blocks_merge(): void
    {
        $ownerResult = $this->ownerResult('failed');
        $executor = $this->executor(['runner' => $this->runnerReport($ownerResult)]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_RESULT_FAILED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        // AP-750 still records the failed result for evidence/inbox.
        $this->assertContains('AP-750', $this->recorder->log);
    }

    public function test_atlas_dev_failed_senior_loop_retries_once_before_bridge(): void
    {
        $first = $this->ownerResult('failed', [
            'changed_files' => ['app/Services/Ai/Example.php'],
            'provider_invoked' => true,
            'completion_state' => 'failed',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'failed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                ],
            ],
        ]);
        $second = $this->ownerResult('completed', [
            'result_id' => 'afrunres_repaired',
            'completion_state' => 'passed',
            'provider_invoked' => true,
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'passed',
                    'owner_cli_blockers' => [],
                    'owner_cli_provider_calls' => 1,
                ],
            ],
        ]);

        $runner = new class($this->recorder, [$this->runnerReport($first), $this->runnerReport($second, 'afrun_repair')]) implements OwnerSandboxRuntimeRunner {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports);
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertTrue($report['repair_attempt']['retried']);
        $this->assertSame('afrunres_repaired', $report['owner_result']['result_id']);
        $this->assertSame(2, count(array_filter($this->recorder->log, static fn (string $ap): bool => $ap === 'AP-759')));
        $this->assertSame(1, count(array_filter($this->recorder->log, static fn (string $ap): bool => $ap === 'AP-750')));
        $lastCommand = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $intentArg = collect($lastCommand)->first(static fn ($arg): bool => is_string($arg) && str_starts_with($arg, '--intent='));
        $this->assertIsString($intentArg);
        $this->assertStringContainsString('Previous AP-759 senior-loop attempt', $intentArg);
    }

    public function test_atlas_dev_failed_senior_loop_does_not_retry_unsafe_diff(): void
    {
        $first = $this->ownerResult('failed', [
            'changed_files' => ['app/Services/Ai/Example.php', 'config/secrets.php'],
            'provider_invoked' => true,
            'completion_state' => 'failed',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'failed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                ],
            ],
        ]);

        $runner = new class($this->recorder, $this->runnerReport($first)) implements OwnerSandboxRuntimeRunner {
            public int $calls = 0;

            /** @param array<string,mixed> $report */
            public function __construct(private object $rec, private array $report) {}

            public function project(array $input): array
            {
                $this->calls++;
                $this->rec->rec('AP-759', $input);

                return $this->report;
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_RESULT_FAILED, $report['status']);
        $this->assertFalse($report['repair_attempt']['attempted']);
        $this->assertSame(1, $runner->calls);
        $this->assertContains('owner_runtime_senior_loop_execution_not_passed', $report['blockers']);
    }

    public function test_owner_runtime_failure_surfaces_actionable_blockers(): void
    {
        $ownerResult = $this->ownerResult('failed', [
            'changed_files' => [],
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'no_patch_needed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                ],
            ],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_RESULT_FAILED, $report['status']);
        $this->assertContains('owner_runtime_no_patch_needed_without_proof', $report['blockers']);
        $this->assertContains('owner_runtime_senior_loop_execution_not_passed', $report['blockers']);
        $this->assertNotContains('owner_runtime_result_not_completed', $report['blockers']);
        $this->assertNotEmpty($report['blocker_details']);
        $this->assertSame(
            'owner_runtime_senior_loop_execution_not_passed',
            collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_senior_loop_execution_not_passed')['blocker'] ?? '',
        );
        $this->assertStringContainsString(
            'Senior loop did not reach passed',
            (string) (collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_senior_loop_execution_not_passed')['reason'] ?? ''),
        );
    }

    public function test_owner_runtime_routing_failure_surfaces_actionable_blocker_detail(): void
    {
        $ownerResult = $this->ownerResult('failed', [
            'changed_files' => [],
            'completion_state' => 'blocked',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'blocked',
                    'owner_cli_status' => 'blocked',
                    'owner_cli_blockers' => ['routing_not_executable'],
                ],
                'senior_loop' => [
                    'routing_decision' => 'forge_promotion_preview',
                    'debug_loop' => ['reason' => 'routing_not_executable'],
                ],
            ],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        $this->assertContains('owner_runtime_routing_not_executable', $report['blockers']);
        $this->assertStringContainsString(
            'forge_promotion_preview',
            (string) (collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_routing_not_executable')['reason'] ?? ''),
        );
    }

    public function test_owner_runtime_provider_timeout_does_not_get_mislabeled_as_scope_violation(): void
    {
        $ownerResult = $this->ownerResult('failed', [
            'changed_files' => [],
            'completion_state' => 'blocked',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'blocked',
                    'owner_cli_status' => 'blocked',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                    'timed_out' => true,
                ],
                'senior_loop' => [
                    'run_summary' => [
                        'completion_state' => 'blocked',
                        'provider_call' => ['error_codes' => ['timeout']],
                    ],
                ],
            ],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        $this->assertContains('owner_runtime_provider_timeout', $report['blockers']);
        $this->assertContains('owner_runtime_senior_loop_execution_not_passed', $report['blockers']);
        $this->assertNotContains('owner_runtime_scope_violation', $report['blockers']);
        $this->assertStringContainsString(
            'timed out',
            (string) (collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_provider_timeout')['reason'] ?? ''),
        );
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function executor(array $overrides = []): Ap786OwnerFlowExecutor
    {
        $release = $overrides['release'] ?? [
            'status' => AreaFocusDevForgeReleaseService::STATUS_READY,
            'release_id' => 'afrel_x',
            'queue_item' => ['queue_item_id' => 'afq_x'],
        ];
        $outcome = $overrides['outcome'] ?? ['status' => StewardshipOutcomeEvidenceBridgeService::STATUS_READY];
        $consumption = $overrides['consumption'] ?? [
            'status' => AreaFocusOwnerQueueConsumptionGateService::STATUS_READY,
            'consumption_id' => 'afcons_x',
            'release_id' => 'afrel_x',
            'queue_item_id' => 'afq_x',
            'sandbox_binding' => ['sandbox_id' => 'afsb_x', 'branch_name' => 'atlas/area-focus/x'],
        ];
        $adapter = $overrides['adapter'] ?? [
            'status' => StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY,
            'owner_execution_id' => 'afexec_x',
        ];
        $runner = $overrides['runner'] ?? $this->runnerReport($this->ownerResult('completed'));
        $bridge = $overrides['bridge'] ?? [
            'status' => StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
            'result_bridge_id' => 'afobr_x',
        ];
        $runnerService = $overrides['runner_service'] ?? null;

        return new Ap786OwnerFlowExecutor(
            new class($this->recorder, $release) implements OwnerQueueReleaseGate {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function release(array $input): array
                {
                    $this->rec->rec('AP-747', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $outcome) implements StewardshipOutcomeProjector {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input = []): array
                {
                    $this->rec->rec('AP-748', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $consumption) implements OwnerQueueConsumptionGate {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-749', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $adapter) implements OwnerRuntimeExecutionAdapter {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-758', $input);

                    return $this->report;
                }
            },
            $runnerService instanceof OwnerSandboxRuntimeRunner ? $runnerService : new class($this->recorder, $runner) implements OwnerSandboxRuntimeRunner {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-759', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $bridge) implements OwnerRuntimeResultProjector {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-750', $input);

                    return $this->report;
                }
            },
            new ForgeOwnerRuntimeDispatchBridge(),
        );
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @return array<string,mixed>
     */
    private function runnerReport(array $ownerResult, string $runId = 'afrun_x'): array
    {
        return [
            'status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            'owner_sandbox_run_id' => $runId,
            'owner_result' => $ownerResult,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function ownerResult(string $status, array $overrides = []): array
    {
        return array_replace([
            'result_id' => 'afrunres_x',
            'consumption_id' => 'afcons_x',
            'release_id' => 'afrel_x',
            'queue_item_id' => 'afq_x',
            'target_owner' => 'atlas_dev',
            'result_status' => $status,
            'summary' => 'Atlas owner runtime ran inside the AP-756 worktree.',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'tests' => ['php artisan test --filter=Example'],
            'evidence_pack' => ['summary' => 'AP-759 owner runtime command receipt.'],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function forgeInput(array $overrides = []): array
    {
        return array_replace($this->input([
            'owner' => 'forge',
            'forge_obra' => '11111111-2222-3333-4444-555555555555',
            'forge_live_topology' => ['status' => 'live'],
            'forge_live_decision' => ['decision' => 'dispatch_forge_owner_runtime', 'operator_actor' => 'operator'],
        ]), $overrides);
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
                'finding_id' => 'aff_x',
                'title' => 'Improve owner flow',
                'proposed_next_action' => 'Implement the smallest correct fix.',
            ],
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'preflight_report' => ['handoff_packet' => ['handoff_hash' => 'sha256:handoff_x']],
            'sandbox_record' => ['sandbox_id' => 'afsb_x', 'status' => 'materialized'],
            'worktree_path' => '/tmp/atlas-ap786-worktree',
            'execute' => true,
            'validation_commands' => ['git diff --check'],
        ], $overrides);
    }
}
