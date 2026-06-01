<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
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
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Illuminate\Support\Facades\File;
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

            /** @var list<array{ap:string,input:array<string,mixed>}> */
            public array $capturedHistory = [];

            /** @param array<string,mixed> $input */
            public function rec(string $ap, array $input): void
            {
                $this->log[] = $ap;
                $this->captured[$ap] = $input;
                $this->capturedHistory[] = ['ap' => $ap, 'input' => $input];
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
        // The inner provider-call timeout must be threaded into the senior-loop
        // command (default 600s) so the real provider is never silently capped
        // at the old 120s config and forced into owner_runtime_provider_timeout.
        $this->assertContains('--provider-timeout-seconds=600', $command);
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

    public function test_ap790_kill_switch_path_is_threaded_to_ap759_receipt(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);
        $killSwitchPath = sys_get_temp_dir().'/atlas-ap790-test.kill';

        $executor->execute($this->input([
            'ap790_kill_switch_path' => $killSwitchPath,
        ]));

        $receipt = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt');
        $this->assertSame('AP-790', $receipt['supervisor'] ?? null);
        $this->assertSame($killSwitchPath, $receipt['kill_switch_path'] ?? null);
    }

    public function test_atlas_dev_owner_intent_sanitizes_forge_control_plane_terms_without_losing_code_scope(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'finding' => [
                'finding_id' => 'factory_max_ap789_forge_topology_dispatch_readiness',
                'title' => 'Repair AP-789 Forge live topology dispatch readiness',
                'detail' => 'Forge topology and council wording must not leak into the executable owner prompt.',
                'proposed_next_action' => 'Wire the Forge owner runtime readiness proof without opening a council session.',
                'affected_files' => [
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
                ],
                'spec_seed' => [
                    'tests_required' => [
                        'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
                    ],
                    'acceptance' => [
                        'Forge live topology readiness is proven without unsafe control-plane prompt leakage.',
                    ],
                ],
            ],
            'allowed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
            ],
            'validation_commands' => [
                'php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ForgeLiveAuthorityBootstrapServiceTest.php',
            ],
        ]));

        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');
        $intentArg = collect($command)->first(static fn ($arg): bool => is_string($arg) && str_starts_with($arg, '--intent='));

        $this->assertIsString($intentArg);
        $this->assertStringContainsString('factory live topology dispatch readiness', $intentArg);
        $this->assertStringContainsString('review group wording', $intentArg);
        $this->assertStringContainsString('ForgeLiveAuthorityBootstrapService.php', $intentArg);
        $this->assertStringContainsString('ForgeLiveAuthorityBootstrapServiceTest.php', $intentArg);
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_])forge(?![A-Za-z0-9_])/i', $intentArg);
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z0-9_])council(?![A-Za-z0-9_])/i', $intentArg);
    }

    public function test_complex_subject_gate_does_not_starve_runtime_bugfix_slices(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-runtime-slice-'.bin2hex(random_bytes(4));
        $source = 'app/Services/Ai/Foo/ComplexRuntimeService.php';
        $test = 'tests/Unit/Ai/Foo/ComplexRuntimeServiceTest.php';

        try {
            File::ensureDirectoryExists($worktree.'/'.dirname($source));
            File::put($worktree.'/'.$source, "<?php\nfinal class ComplexRuntimeService\n{\n    public function __construct(private object \$dependency) {}\n}\n");

            $report = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))])->execute($this->input([
                'finding' => [
                    'finding_id' => 'factory_max_ap789_forge_authority_readiness',
                    'kind' => 'bug',
                    'title' => 'Improve AP-789 live authority readiness diagnostics',
                    'auto_execution_allowed' => true,
                    'operator_review_required' => false,
                    'spec_seed' => ['tests_required' => [$test]],
                ],
                'allowed_files' => [$source, $test],
                'validation_commands' => ['php artisan test '.$test],
                'worktree_path' => $worktree,
            ]));

            $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
            $this->assertContains('AP-759', $this->recorder->log);
            $this->assertNotContains(ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE, $report['blockers']);
        } finally {
            File::deleteDirectory($worktree);
        }
    }

    public function test_complex_subject_gate_still_skips_pure_test_authoring_slices(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-test-slice-'.bin2hex(random_bytes(4));
        $source = 'app/Services/Ai/Foo/ComplexRuntimeService.php';
        $test = 'tests/Unit/Ai/Foo/ComplexRuntimeServiceTest.php';

        try {
            File::ensureDirectoryExists($worktree.'/'.dirname($source));
            File::put($worktree.'/'.$source, "<?php\nfinal class ComplexRuntimeService\n{\n    public function __construct(private object \$dependency) {}\n}\n");

            $report = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))])->execute($this->input([
                'finding' => [
                    'finding_id' => 'factory_max_complex_runtime_test',
                    'kind' => 'test',
                    'title' => 'Add focused unit coverage for complex runtime service',
                    'auto_execution_allowed' => true,
                    'operator_review_required' => false,
                    'spec_seed' => ['tests_required' => [$test]],
                ],
                'allowed_files' => [$source, $test],
                'validation_commands' => ['php artisan test '.$test],
                'worktree_path' => $worktree,
            ]));

            $this->assertSame(Ap786OwnerFlowExecutor::STATUS_PREFLIGHT_SKIPPED, $report['status']);
            $this->assertContains(ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE, $report['blockers']);
            $this->assertNotContains('AP-759', $this->recorder->log);
        } finally {
            File::deleteDirectory($worktree);
        }
    }

    public function test_large_existing_runtime_surface_is_skipped_before_owner_runtime_without_anchor(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-large-runtime-surface-'.bin2hex(random_bytes(4));
        $source = 'app/Services/Ai/Foo/LargeRuntimeService.php';
        $test = 'tests/Unit/Ai/Foo/LargeRuntimeServiceTest.php';

        try {
            File::ensureDirectoryExists($worktree.'/'.dirname($source));
            File::put(
                $worktree.'/'.$source,
                "<?php\nfinal class LargeRuntimeService\n{\n".str_repeat("    public function noop(): void {}\n", ZeroProviderPreflightGate::MAX_AUTONOMOUS_EXISTING_RUNTIME_SURFACE_LOC + 1)."}\n",
            );

            $report = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))])->execute($this->input([
                'finding' => [
                    'finding_id' => 'canonical_aaeos_aaeos_24h_backlog_depth_admission_surface::packet::1',
                    'kind' => 'runtime',
                    'title' => 'Self-Construction packet 1 — execute ONLY this bounded step',
                    'auto_execution_allowed' => true,
                    'operator_review_required' => false,
                    'spec_seed' => ['tests_required' => [$test]],
                ],
                'allowed_files' => [$source, $test],
                'validation_commands' => ['php artisan test '.$test],
                'worktree_path' => $worktree,
            ]));

            $this->assertSame(Ap786OwnerFlowExecutor::STATUS_PREFLIGHT_SKIPPED, $report['status']);
            $this->assertContains(
                ZeroProviderPreflightGate::REASON_LARGE_EXISTING_RUNTIME_SURFACE_NEEDS_NARROWER_SLICE,
                $report['blockers'],
            );
            $this->assertNotContains('AP-759', $this->recorder->log);
        } finally {
            File::deleteDirectory($worktree);
        }
    }

    public function test_self_construction_existing_runtime_surface_is_skipped_without_structured_anchor(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-self-construction-runtime-anchor-'.bin2hex(random_bytes(4));
        $source = 'app/Services/Ai/Foo/SmallRuntimeService.php';
        $test = 'tests/Unit/Ai/Foo/SmallRuntimeServiceTest.php';

        try {
            File::ensureDirectoryExists($worktree.'/'.dirname($source));
            File::put(
                $worktree.'/'.$source,
                "<?php\nfinal class SmallRuntimeService\n{\n    public function currentSignal(): string\n    {\n        return 'unknown';\n    }\n}\n",
            );

            $report = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))])->execute($this->input([
                'finding' => [
                    'finding_id' => 'canonical_aaeos_aaeos_preflight_admission_deficit_reason_contract::packet::1',
                    'kind' => 'runtime',
                    'title' => 'Self-Construction packet 1 — execute ONLY this bounded step',
                    'origin_type' => 'self_construction_admission_packet',
                    'active_slice_kind' => 'self_construction_packet',
                    'auto_execution_allowed' => true,
                    'operator_review_required' => false,
                    'self_construction_packet' => [
                        'task_packet' => [
                            'objective' => 'Add the smallest runtime signal for the admission-deficit reason.',
                        ],
                    ],
                    'spec_seed' => ['tests_required' => [$test]],
                ],
                'allowed_files' => [$source, $test],
                'validation_commands' => ['php artisan test '.$test],
                'worktree_path' => $worktree,
            ]));

            $this->assertSame(Ap786OwnerFlowExecutor::STATUS_PREFLIGHT_SKIPPED, $report['status']);
            $this->assertContains(
                ZeroProviderPreflightGate::REASON_EXISTING_RUNTIME_SURFACE_NEEDS_STRUCTURED_ANCHOR,
                $report['blockers'],
            );
            $this->assertNotContains('AP-759', $this->recorder->log);
        } finally {
            File::deleteDirectory($worktree);
        }
    }

    public function test_self_construction_existing_runtime_surface_with_structured_anchor_reaches_owner_runtime(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-self-construction-runtime-anchor-admit-'.bin2hex(random_bytes(4));
        $source = 'app/Services/Ai/Foo/SmallRuntimeService.php';
        $test = 'tests/Unit/Ai/Foo/SmallRuntimeServiceTest.php';

        try {
            File::ensureDirectoryExists($worktree.'/'.dirname($source));
            File::put(
                $worktree.'/'.$source,
                "<?php\nfinal class SmallRuntimeService\n{\n    public function currentSignal(): string\n    {\n        return 'unknown';\n    }\n}\n",
            );

            $report = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))])->execute($this->input([
                'finding' => [
                    'finding_id' => 'canonical_aaeos_aaeos_preflight_admission_deficit_reason_contract::packet::1',
                    'kind' => 'runtime',
                    'title' => 'Self-Construction packet 1 — execute ONLY this bounded step',
                    'origin_type' => 'self_construction_admission_packet',
                    'active_slice_kind' => 'self_construction_packet',
                    'auto_execution_allowed' => true,
                    'operator_review_required' => false,
                    'self_construction_packet' => [
                        'target_symbol' => 'runtime_signal:admission_deficit_reason',
                        'method_anchor' => 'currentSignal',
                        'surgical_anchor' => 'file:'.$source.'; method:currentSignal; semantic_step:runtime_signal; capability:admission deficit reason',
                        'task_packet' => [
                            'objective' => 'Add the smallest runtime signal for the admission-deficit reason.',
                        ],
                    ],
                    'spec_seed' => ['tests_required' => [$test]],
                ],
                'allowed_files' => [$source, $test],
                'validation_commands' => ['php artisan test '.$test],
                'worktree_path' => $worktree,
            ]));

            $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
            $this->assertContains('AP-759', $this->recorder->log);
        } finally {
            File::deleteDirectory($worktree);
        }
    }

    public function test_self_construction_runtime_signal_anchor_without_method_is_skipped_before_provider(): void
    {
        $worktree = sys_get_temp_dir().'/atlas-ap786-self-construction-runtime-signal-anchor-'.bin2hex(random_bytes(4));
        $source = 'app/Services/Ai/Foo/SmallRuntimeService.php';
        $test = 'tests/Unit/Ai/Foo/SmallRuntimeServiceTest.php';

        try {
            File::ensureDirectoryExists($worktree.'/'.dirname($source));
            File::put(
                $worktree.'/'.$source,
                "<?php\nfinal class SmallRuntimeService\n{\n    public function currentSignal(): string\n    {\n        return 'unknown';\n    }\n}\n",
            );

            $report = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))])->execute($this->input([
                'finding' => [
                    'finding_id' => 'canonical_aaeos_aaeos_dept_maturity_qa_contract_testing::packet::1',
                    'kind' => 'runtime',
                    'title' => 'Self-Construction packet 1 — execute ONLY this bounded step',
                    'origin_type' => 'self_construction_admission_packet',
                    'active_slice_kind' => 'self_construction_packet',
                    'auto_execution_allowed' => true,
                    'operator_review_required' => false,
                    'self_construction_packet' => [
                        'target_symbol' => 'runtime_signal:e2e_contract_test_count_gate',
                        'surgical_anchor' => 'file:'.$source.'; semantic_step:runtime_signal; capability:E2E contract test count gate',
                        'task_packet' => [
                            'objective' => 'Add the smallest runtime signal for the E2E contract test count gate.',
                        ],
                    ],
                    'spec_seed' => ['tests_required' => [$test]],
                ],
                'allowed_files' => [$source, $test],
                'validation_commands' => ['php artisan test '.$test],
                'worktree_path' => $worktree,
            ]));

            $this->assertSame(Ap786OwnerFlowExecutor::STATUS_PREFLIGHT_SKIPPED, $report['status']);
            $this->assertContains(
                ZeroProviderPreflightGate::REASON_EXISTING_RUNTIME_SURFACE_NEEDS_STRUCTURED_ANCHOR,
                $report['blockers'],
            );
            $this->assertNotContains('AP-759', $this->recorder->log);
        } finally {
            File::deleteDirectory($worktree);
        }
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

    public function test_minimax_worker_command_arms_the_bounded_repair_loop(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'provider' => 'minimax_m27_cli',
        ]));

        $ap759 = array_values(array_filter(
            $this->recorder->capturedHistory,
            static fn (array $entry): bool => $entry['ap'] === 'AP-759',
        ));
        $command = (array) data_get($ap759[0] ?? [], 'input.runtime_command_receipt.command');

        // minimax_m27_cli routes atlas_dev to the minimax-worker runtime ...
        $this->assertContains('atlas:dev:minimax-worker:run', $command);
        // ... with the bounded repair loop EXPLICITLY armed: a single MiniMax syntax /
        // validation error must get repair attempts before the cycle is failed, never a
        // blocked-without-repair that wastes the provider spend already made.
        $this->assertContains('--max-repairs=2', $command);
    }

    public function test_minimax_completed_patch_runs_codex_cli_pre_commit_review_before_bridge(): void
    {
        $minimax = $this->ownerResult('completed', [
            'result_id' => 'afrunres_minimax',
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'passed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 1,
            ]],
        ]);
        $codex = $this->ownerResult('completed', [
            'result_id' => 'afrunres_codex_review',
            'summary' => 'Codex reviewed and tightened the MiniMax patch.',
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'passed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 1,
            ]],
        ]);
        $runner = new class($this->recorder, [$this->runnerReport($minimax, 'afrun_minimax'), $this->runnerReport($codex, 'afrun_codex_review')]) implements OwnerSandboxRuntimeRunner
        {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports);
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input([
            'provider' => 'minimax_m27_cli',
        ]));

        $ap759 = array_values(array_filter(
            $this->recorder->capturedHistory,
            static fn (array $entry): bool => $entry['ap'] === 'AP-759',
        ));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertCount(2, $ap759);
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759', 'AP-759', 'AP-750'], $this->recorder->log);
        $this->assertContains('atlas:dev:minimax-worker:run', (array) data_get($ap759[0], 'input.runtime_command_receipt.command'));
        $reviewCommand = (array) data_get($ap759[1], 'input.runtime_command_receipt.command');
        $this->assertContains('atlas:dev:senior-loop:run', $reviewCommand);
        $this->assertContains('--provider-choice=codex_cli', $reviewCommand);
        $this->assertTrue((bool) data_get($ap759[1], 'input.runtime_command_receipt.minimax_codex_pre_commit_review'));
        $this->assertTrue((bool) data_get($ap759[1], 'input.runtime_command_receipt.review_before_commit'));
        $this->assertSame('codex_cli', data_get($ap759[1], 'input.runtime_command_receipt.review_provider_choice'));
        $this->assertSame('minimax_m27_cli', data_get($ap759[1], 'input.runtime_command_receipt.reviewed_provider_choice'));
        $this->assertSame('accepted', data_get($report, 'minimax_codex_review.status'));
        $this->assertSame('afrun_minimax', data_get($report, 'minimax_codex_review.reviewed_owner_sandbox_run_id'));
        $this->assertSame('afrun_codex_review', data_get($report, 'minimax_codex_review.review_owner_sandbox_run_id'));
        $this->assertSame(2, $report['provider_calls_total']);
        $this->assertSame('afrunres_codex_review', $report['owner_result']['result_id']);
        $this->assertSame('accepted', data_get($this->recorder->captured['AP-750'], 'owner_result.minimax_codex_review.status'));
    }

    public function test_minimax_codex_review_can_accept_without_extra_codex_edits(): void
    {
        $minimax = $this->ownerResult('completed', [
            'result_id' => 'afrunres_minimax',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'passed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 1,
            ]],
        ]);
        $codexNoPatchNeeded = $this->ownerResult('failed', [
            'result_id' => 'afrunres_codex_review_no_patch',
            'changed_files' => [],
            'completion_state' => 'no_patch_needed',
            'summary' => 'Codex reviewed the MiniMax patch and found no additional edits needed.',
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'no_patch_needed',
                'owner_cli_status' => 'passed',
                'owner_cli_blockers' => [],
                'owner_cli_provider_calls' => 1,
            ]],
        ]);
        $runner = new class($this->recorder, [$this->runnerReport($minimax, 'afrun_minimax'), $this->runnerReport($codexNoPatchNeeded, 'afrun_codex_review_no_patch')]) implements OwnerSandboxRuntimeRunner
        {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports);
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input([
            'provider' => 'minimax_m27_cli',
        ]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertSame('accepted', data_get($report, 'minimax_codex_review.status'));
        $this->assertSame('no_patch_needed', data_get($report, 'minimax_codex_review.review_completion_state'));
        $this->assertTrue((bool) data_get($report, 'minimax_codex_review.review_kept_minimax_patch'));
        $this->assertSame('afrunres_minimax', $report['owner_result']['result_id']);
        $this->assertSame(2, $report['provider_calls_total']);
    }

    public function test_minimax_codex_pre_commit_review_blocks_merge_when_codex_fails(): void
    {
        $minimax = $this->ownerResult('completed', [
            'result_id' => 'afrunres_minimax',
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'passed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 1,
            ]],
        ]);
        $codexFailed = $this->ownerResult('failed', [
            'result_id' => 'afrunres_codex_review_failed',
            'summary' => 'Codex found an unmergeable assertion quality issue.',
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'failed',
                'owner_cli_status' => 'failed',
                'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                'owner_cli_provider_calls' => 1,
            ]],
        ]);
        $runner = new class($this->recorder, [$this->runnerReport($minimax, 'afrun_minimax'), $this->runnerReport($codexFailed, 'afrun_codex_review_failed')]) implements OwnerSandboxRuntimeRunner
        {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports);
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input([
            'provider' => 'minimax_m27_cli',
        ]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_RESULT_FAILED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertSame('blocked', data_get($report, 'minimax_codex_review.status'));
        $this->assertContains('owner_runtime_minimax_codex_review_not_passed', $report['blockers']);
        $this->assertSame('failed', $report['owner_result']['result_status']);
        $this->assertSame(2, $report['provider_calls_total']);
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

    public function test_atlas_dev_owner_command_rewrites_artisan_test_to_worktree_phpunit(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $executor->execute($this->input([
            'validation_commands' => [
                'git diff --check',
                'php artisan test tests/Unit/Ai/Programming/ExampleTest.php',
            ],
        ]));

        $command = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command');

        $this->assertContains('--validation-command=git diff --check', $command);
        $this->assertContains('--validation-command=./vendor/bin/phpunit --configuration=phpunit.xml tests/Unit/Ai/Programming/ExampleTest.php', $command);
        $this->assertNotContains('--validation-command=php artisan test tests/Unit/Ai/Programming/ExampleTest.php', $command);
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

    /**
     * SEC-001 provider-proof for owner=forge: a forge cycle that produced
     * changed files with ZERO provider calls is unattributed (stray worktree
     * files / local stub) and must never complete or merge — the same law that
     * already guards atlas_dev. Before this gate, forge completion checked only
     * changed_files, leaving a false-merge hole.
     */
    public function test_forge_changed_files_without_provider_call_is_not_completed_and_blocks_merge(): void
    {
        $ownerResult = $this->ownerResult('completed', [
            'changed_files' => ['app/Services/Ai/Forge.php'],
            'runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 0]],
        ]);
        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->forgeInput());

        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
    }

    /**
     * SEC-004: a mutative provider-invoke must re-check AWIS readiness at the
     * dispatch seam. With live topology + decision + provider authorization +
     * budget but AWIS NOT ready (forge_awis_ready=false), the bridge must block
     * with awis_execution_gate_required and never build a provider command — so
     * no caller can route execution onto an uncertified workspace.
     */
    public function test_forge_provider_invoke_blocks_when_awis_not_ready(): void
    {
        $report = $this->executor()->execute($this->forgeInput([
            'forge_dispatch_mode' => 'forge_provider_invoke',
            'forge_provider_authorization' => true,
            'forge_budget_approved' => true,
            'forge_awis_ready' => false,
        ]));

        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertContains('awis_execution_gate_required', $report['blockers']);
    }

    public function test_owner_result_not_completed_still_bridges_but_blocks_merge(): void
    {
        $ownerResult = $this->ownerResult('failed');
        $executor = $this->executor(['runner' => $this->runnerReport($ownerResult)]);

        $report = $executor->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_RESULT_FAILED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertContains('owner_runtime_result_failed', $report['blockers']);
        $this->assertNotContains('owner_runtime_result_not_completed', $report['blockers']);
        $this->assertStringContainsString(
            'without machine-readable owner_cli_blockers',
            (string) (collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_result_failed')['reason'] ?? ''),
        );
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
                'senior_loop' => [
                    'persisted_ref' => 'receipts/dev-x/senior_engineer_loop_execution.json',
                    'run_summary' => [
                        'scope_guard_status' => 'passed',
                        'verification_status' => 'failed',
                        'verification_receipt_hash' => 'sha256:verification_x',
                    ],
                    'debug_loop' => [
                        'failure_capsules' => [
                            ['ref' => 'receipts/dev-x/failure_capsule.0.json'],
                        ],
                    ],
                    'learning' => [
                        'error_ledger_ref' => 'receipts/dev-x/error_ledger.v1.json',
                    ],
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

        $runner = new class($this->recorder, [$this->runnerReport($first), $this->runnerReport($second, 'afrun_repair')]) implements OwnerSandboxRuntimeRunner
        {
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
        $this->assertStringContainsString('scope_guard_status=passed', $intentArg);
        $this->assertStringContainsString('verification_status=failed', $intentArg);
        $this->assertStringContainsString('verification_receipt_hash=sha256:verification_x', $intentArg);
        $this->assertStringContainsString('failure_capsules=receipts/dev-x/failure_capsule.0.json', $intentArg);
        $this->assertStringContainsString('error_ledger_ref=receipts/dev-x/error_ledger.v1.json', $intentArg);
    }

    public function test_atlas_dev_senior_loop_repair_exhausted_preserves_first_attempt_without_hiding_failures(): void
    {
        $failedSeniorLoop = [
            'changed_files' => ['app/Services/Ai/Example.php'],
            'provider_invoked' => true,
            'completion_state' => 'failed',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'failed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                ],
                'senior_loop' => [
                    'persisted_ref' => 'receipts/dev-y/senior_engineer_loop_execution.json',
                    'run_summary' => [
                        'scope_guard_status' => 'passed',
                        'verification_status' => 'failed',
                        'verification_receipt_hash' => 'sha256:verification_y',
                    ],
                    'debug_loop' => [
                        'reason' => 'focused phpunit failed after scoped diff',
                        'failure_capsules' => [
                            ['ref' => 'receipts/dev-y/failure_capsule.0.json'],
                        ],
                    ],
                    'learning' => [
                        'error_ledger_ref' => 'receipts/dev-y/error_ledger.v1.json',
                    ],
                ],
            ],
        ];
        $first = $this->ownerResult('failed', $failedSeniorLoop);
        $second = $this->ownerResult('failed', array_replace($failedSeniorLoop, [
            'result_id' => 'afrunres_repair_failed',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'failed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                ],
            ],
        ]));

        $runner = new class($this->recorder, [$this->runnerReport($first), $this->runnerReport($second, 'afrun_repair_failed')]) implements OwnerSandboxRuntimeRunner
        {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports);
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input());

        // After one senior-loop failure plus one failed repair (two failures on
        // the same slice), the slice is review-locked so the loop advances to
        // the next finding instead of burning a third provider call. The first
        // attempt's diagnostics are preserved (not hidden) on repair_attempt.
        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_REVIEW_LOCKED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertTrue($report['repair_attempt']['retried']);
        $this->assertTrue($report['repair_attempt']['review_locked']);
        $this->assertSame(Ap786OwnerFlowExecutor::REPAIR_REVIEW_LOCK_THRESHOLD, $report['repair_attempt']['repair_failure_count']);
        $this->assertSame('failed', $report['repair_attempt']['first_result_status']);
        $this->assertSame('failed', $report['repair_attempt']['repair_result_status']);
        $this->assertSame(1, $report['repair_attempt']['first_provider_calls']);
        $this->assertContains('verification_status=failed', $report['repair_attempt']['first_diagnostics']);
        $this->assertContains('debug_reason=focused phpunit failed after scoped diff', $report['repair_attempt']['first_diagnostics']);
        $this->assertContains('senior_loop_execution_not_passed', $report['repair_attempt']['first_blockers']);
        $this->assertContains('owner_runtime_review_locked', $report['blockers']);
        $this->assertStringContainsString(
            'review-locked',
            (string) (collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_review_locked')['reason'] ?? ''),
        );
        $this->assertStringContainsString(
            'focused phpunit failed after scoped diff',
            (string) (collect($report['blocker_details'])->firstWhere('blocker', 'owner_runtime_review_locked')['reason'] ?? ''),
        );
        // Exactly two AP-759 runs: the initial senior loop and one repair.
        $this->assertSame(2, count(array_filter($this->recorder->log, static fn (string $ap): bool => $ap === 'AP-759')));
    }

    public function test_atlas_dev_repair_prompt_includes_failure_capsule_excerpt(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-ap786-failure-capsule-'.bin2hex(random_bytes(4));
        $receiptDir = $workspace.'/storage/atlas-dev/receipts/dev-z';
        mkdir($receiptDir, 0777, true);
        file_put_contents($receiptDir.'/test_log_02.v1.json', json_encode([
            'stderr' => [
                'Class App\\Services\\Ai\\Example located in ./app/Services/Ai/WrongFile.php does not comply with psr-4 autoloading standard. Skipping.',
                'ERRORS!',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($receiptDir.'/failure_capsule.0.json', json_encode([
            'failing_test' => './vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php',
            'primary_error_excerpt' => 'Failed asserting that expected state hash abc equals actual def. output_path='.$receiptDir.'/test_log_02.v1.json',
            'output_path' => $receiptDir.'/test_log_02.v1.json',
            'failure_signature' => 'sha256:failsig',
        ], JSON_THROW_ON_ERROR));

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
                'senior_loop' => [
                    'debug_loop' => [
                        'failure_capsules' => [
                            ['ref' => 'receipts/dev-z/failure_capsule.0.json'],
                        ],
                    ],
                    'run_summary' => [
                        'scope_guard_status' => 'passed',
                        'verification_status' => 'failed',
                    ],
                ],
            ],
        ]);
        $second = $this->ownerResult('completed', [
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

        $runner = new class($this->recorder, [$this->runnerReport($first), $this->runnerReport($second, 'afrun_repair')]) implements OwnerSandboxRuntimeRunner
        {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports);
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input(['worktree_path' => $workspace]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        // The repair-run outer subprocess timeout must stay strictly larger than
        // the inner provider-call budget (default 600s) plus margin, so a repair
        // attempt can never be killed mid-provider-call. 600 + 120 = 720.
        $this->assertSame(720, data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.timeout_seconds'));
        $repairCommand = (array) data_get($this->recorder->captured['AP-759'], 'runtime_command_receipt.command', []);
        $repairIntent = implode(' ', array_values(array_filter(
            $repairCommand,
            static fn ($part): bool => is_string($part) && str_starts_with($part, '--intent='),
        )));
        $this->assertStringContainsString('primary_error=Failed asserting that expected state hash abc equals actual def', $repairIntent);
        $this->assertStringContainsString('failing_test=./vendor/bin/phpunit tests/Unit/Ai/ExampleTest.php', $repairIntent);
        $this->assertStringContainsString('verification_log=Class App\\Services\\Ai\\Example located in ./app/Services/Ai/WrongFile.php does not comply with psr-4 autoloading standard. Skipping.', $repairIntent);
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

        $runner = new class($this->recorder, $this->runnerReport($first)) implements OwnerSandboxRuntimeRunner
        {
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

    public function test_owner_runtime_salvages_provider_timeout_when_scoped_diff_already_validated(): void
    {
        $ownerResult = $this->ownerResult('failed', [
            'changed_files' => ['app/Services/Ai/Example.php'],
            'completion_state' => 'failed',
            'provider_invoked' => true,
            'test_results' => [
                ['gate' => 'verification', 'status' => 'passed', 'receipt_hash' => 'sha256:verification'],
                ['gate' => 'scope_guard', 'status' => 'passed'],
            ],
            'evidence_pack' => [
                'changed_files' => ['app/Services/Ai/Example.php'],
                'test_results' => [
                    ['gate' => 'verification', 'status' => 'passed', 'receipt_hash' => 'sha256:verification'],
                    ['gate' => 'scope_guard', 'status' => 'passed'],
                ],
            ],
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
                        'scope_guard_status' => 'passed',
                        'verification_status' => 'passed',
                        'provider_call' => ['error_codes' => ['timeout']],
                    ],
                ],
            ],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertSame('completed', $report['owner_result']['result_status']);
        $this->assertSame('passed', data_get($report, 'owner_result.runtime_invocation.command_result.owner_cli_completion_state'));
        $this->assertSame('completed', data_get($report, 'owner_result.runtime_invocation.command_result.owner_cli_status'));
        $this->assertSame('completed', data_get($report, 'owner_result.runtime_invocation.senior_loop.run_summary.status'));
        $this->assertSame([], $report['blockers']);
        $this->assertTrue(data_get($report, 'execution_result.real_execution_bridge.validated_timeout_salvage.salvaged'));
        $this->assertSame(
            'provider_timed_out_after_validated_scoped_diff',
            data_get($report, 'execution_result.real_execution_bridge.validated_timeout_salvage.reason'),
        );
    }

    // ---- Bug #2: provider-proof hard rule (no scaffold-without-provider) ----

    public function test_no_provider_call_means_no_scaffold_merge_and_not_a_completable_attempt(): void
    {
        // Senior-loop scaffolded a file but never called a provider.
        $ownerResult = $this->ownerResult('completed', [
            'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SomeScaffold.php'],
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'no_patch_needed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 0,
            ]],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        // No fake patch, no merge: a provider-less patch can never be completed.
        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed'], 'provider-less scaffold must never be merge_allowed');
        // Honest blocker surfaces.
        $this->assertContains('owner_runtime_scaffold_without_provider', $report['blockers']);
        // Not a completable/terminal attempt — the finding is retried (no permanent
        // quarantine, no seen pollution that marks it done).
        $q = app(AreaFocusCandidateQuarantineService::class);
        $this->assertFalse($q->shouldQuarantine($report['blockers']), 'scaffold-without-provider must not permanently quarantine the finding');
        $this->assertFalse($q->isQuarantineBlocker('owner_runtime_scaffold_without_provider'));
    }

    public function test_provider_unavailable_is_honest_transient_with_retry_window(): void
    {
        $ownerResult = $this->ownerResult('failed', [
            'changed_files' => [],
            'completion_state' => 'blocked',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'blocked',
                    'owner_cli_status' => 'blocked',
                    'owner_cli_provider_calls' => 0,
                ],
                'senior_loop' => [
                    'run_summary' => ['provider_call' => ['error_codes' => ['unavailable']]],
                ],
            ],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        $this->assertNotSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertContains('owner_runtime_provider_unavailable', $report['blockers']);
        // Transient => retried, never permanently quarantined.
        $q = app(AreaFocusCandidateQuarantineService::class);
        $this->assertTrue($q->hasTransientBlocker($report['blockers']));
        $this->assertFalse($q->shouldQuarantine($report['blockers']));
    }

    public function test_provider_called_with_valid_patch_completes_and_allows_merge(): void
    {
        $ownerResult = $this->ownerResult('completed', [
            'changed_files' => ['app/Services/Ai/Example.php'],
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'passed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 1,
            ]],
        ]);

        $report = $this->executor(['runner' => $this->runnerReport($ownerResult)])->execute($this->input());

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        $this->assertSame([], $report['blockers']);
        $this->assertNotContains('owner_runtime_scaffold_without_provider', $report['blockers']);
    }

    public function test_failed_provider_result_feeds_exact_failure_to_repair(): void
    {
        // First attempt fails with a real provider call + concrete stderr; repair
        // must receive that exact failure feedback (not a generic prompt).
        $first = $this->ownerResult('failed', [
            'changed_files' => ['app/Services/Ai/Example.php'],
            'completion_state' => 'failed',
            'runtime_invocation' => [
                'command_result' => [
                    'owner_cli_completion_state' => 'failed',
                    'owner_cli_status' => 'failed',
                    'owner_cli_blockers' => ['senior_loop_execution_not_passed'],
                    'owner_cli_provider_calls' => 1,
                    'exit_code' => 2,
                    'stderr_excerpt' => 'PHPUnit: 1 failed — Example::test_contract expected 3 got 4',
                ],
            ],
        ]);
        $second = $this->ownerResult('completed', [
            'changed_files' => ['app/Services/Ai/Example.php'],
            'runtime_invocation' => ['command_result' => [
                'owner_cli_completion_state' => 'passed',
                'owner_cli_status' => 'completed',
                'owner_cli_provider_calls' => 1,
            ]],
        ]);

        $runner = new class($this->recorder, [$this->runnerReport($first), $this->runnerReport($second, 'afrun_repair')]) implements OwnerSandboxRuntimeRunner
        {
            /** @param list<array<string,mixed>> $reports */
            public function __construct(private object $rec, private array $reports) {}

            public function project(array $input): array
            {
                $this->rec->rec('AP-759', $input);

                return array_shift($this->reports) ?? ['status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY];
            }
        };

        $report = $this->executor(['runner_service' => $runner])->execute($this->input());

        // Repair received the exact failure (stderr) — assert it reached the repair prompt/feedback.
        $blob = json_encode($report);
        $this->assertStringContainsString('Example::test_contract expected 3 got 4', $blob, 'repair must receive the exact provider failure feedback');
    }

    public function test_execution_result_materializes_real_owner_runtime_bridge_for_ap790(): void
    {
        $executor = $this->executor(['runner' => $this->runnerReport($this->ownerResult('completed'))]);

        $report = $executor->execute($this->input([
            'finding' => [
                'finding_id' => 'factory_max_ap790_priority_owner_runtime_real_execution_bridge',
                'title' => 'Materialize owner runtime real execution bridge backlog into AP-790 work',
                'detail' => 'The priority engine ranks owner-runtime real execution as the highest pending factory unlock, but it has no executable files attached.',
                'proposed_next_action' => 'Materialize it through AP-786 owner-flow diagnostics and tests.',
                'spec_seed' => [
                    'tests_required' => ['tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php'],
                ],
            ],
            'allowed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutorTest.php',
            ],
        ]));

        $executionResult = $report['execution_result'];
        $bridge = $executionResult['real_execution_bridge'];

        $this->assertTrue($executionResult['uses_full_owner_runtime_chain']);
        $this->assertFalse($executionResult['provider_router_used']);
        $this->assertSame('afrun_x', $executionResult['owner_sandbox_run_id']);
        $this->assertSame(Ap786OwnerFlowExecutor::REAL_EXECUTION_BRIDGE_SCHEMA, $bridge['schema_version']);
        $this->assertSame(Ap786OwnerFlowExecutor::AP790_BACKLOG_OWNER_RUNTIME_REAL_EXECUTION_BRIDGE, $bridge['ap790_backlog_item']);
        $this->assertSame(['AP-747', 'AP-748', 'AP-749', 'AP-758', 'AP-759', 'AP-750'], $bridge['owner_chain_ap_contracts']);
        $this->assertSame('atlas_dev_senior_loop', $bridge['dispatch_kind']);
        $this->assertFalse($bridge['plan_only']);
        $this->assertSame([], $bridge['blockers']);
    }

    public function test_execution_result_real_execution_bridge_records_blockers_for_ap790_ledger(): void
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

        $bridge = $report['execution_result']['real_execution_bridge'];
        $this->assertContains('owner_runtime_no_patch_needed_without_proof', $bridge['blockers']);
        $this->assertContains('owner_runtime_senior_loop_execution_not_passed', $bridge['blockers']);
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
            new class($this->recorder, $release) implements OwnerQueueReleaseGate
            {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function release(array $input): array
                {
                    $this->rec->rec('AP-747', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $outcome) implements StewardshipOutcomeProjector
            {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input = []): array
                {
                    $this->rec->rec('AP-748', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $consumption) implements OwnerQueueConsumptionGate
            {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-749', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $adapter) implements OwnerRuntimeExecutionAdapter
            {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-758', $input);

                    return $this->report;
                }
            },
            $runnerService instanceof OwnerSandboxRuntimeRunner ? $runnerService : new class($this->recorder, $runner) implements OwnerSandboxRuntimeRunner
            {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-759', $input);

                    return $this->report;
                }
            },
            new class($this->recorder, $bridge) implements OwnerRuntimeResultProjector
            {
                /** @param array<string,mixed> $report */
                public function __construct(private object $rec, private array $report) {}

                public function project(array $input): array
                {
                    $this->rec->rec('AP-750', $input);

                    return $this->report;
                }
            },
            new ForgeOwnerRuntimeDispatchBridge,
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
            // A real owner runtime that produced a patch invoked a provider; the
            // Bug #2 provider-proof gate requires this for atlas_dev completion.
            'runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 1]],
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

    /**
     * Regression for the 2026-05-30 soak: the minimax-worker dispatch command must match
     * the real atlas:dev:minimax-worker:run signature — NO --json flag (it does not exist
     * and crashed the worker), ONE comma-joined --allowed-files, ONE JSON-array
     * --validation-commands, and the finding JSON passed RAW (not truncated by safeCliValue,
     * which produced invalid_finding_json).
     */
    public function test_minimax_worker_command_matches_real_signature(): void
    {
        $executor = app(Ap786OwnerFlowExecutor::class);
        $method = new \ReflectionMethod($executor, 'atlasMinimaxWorkerCommand');
        $method->setAccessible(true);

        $finding = [
            'finding_id' => 'afdf_test',
            'title' => 'Consume inert contract Foo in Bar decision path',
            'spec_seed' => ['objective' => 'wire it', 'acceptance' => ['x']],
        ];
        $longFindingJson = json_encode($finding, JSON_UNESCAPED_SLASHES);

        $command = $method->invoke(
            $executor,
            '/tmp/wt',
            $finding,
            ['app/A.php', 'app/B.php'],
            ['git diff --check', 'php artisan test tests/Unit/FooTest.php'],
        );

        // --json must NOT be present (the real command has no such option).
        $this->assertNotContains('--json', $command);

        // Exactly ONE --allowed-files, comma-joined.
        $allowed = array_values(array_filter($command, fn ($a) => str_starts_with((string) $a, '--allowed-files=')));
        $this->assertCount(1, $allowed);
        $this->assertSame('--allowed-files=app/A.php,app/B.php', $allowed[0]);

        // Exactly ONE --validation-commands, a JSON array (php artisan test rewritten to phpunit).
        $validation = array_values(array_filter($command, fn ($a) => str_starts_with((string) $a, '--validation-commands=')));
        $this->assertCount(1, $validation);
        $decoded = json_decode(substr($validation[0], strlen('--validation-commands=')), true);
        $this->assertIsArray($decoded);
        $this->assertSame('git diff --check', $decoded[0]);
        $this->assertStringContainsString('vendor/bin/phpunit', $decoded[1]);

        // finding JSON passed RAW and complete (not truncated).
        $findingArg = array_values(array_filter($command, fn ($a) => str_starts_with((string) $a, '--finding-json=')));
        $this->assertCount(1, $findingArg);
        $this->assertSame('--finding-json='.$longFindingJson, $findingArg[0]);
    }
}
