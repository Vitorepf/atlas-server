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
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Tests\TestCase;

/**
 * FASE 2 — proves the zero-provider pre-flight gate admits a qualified atlas_dev
 * cycle (reaching the AP-759 owner command) and cheaply skips an unqualified one
 * BEFORE any provider/owner command runs, recording the merges/token-spending
 * metric honestly (skipped cycles are NOT token-spending).
 */
final class ZeroProviderPreflightGateTest extends TestCase
{
    public function test_pure_gate_admits_qualified_and_blocks_each_failure_mode(): void
    {
        $gate = new ZeroProviderPreflightGate;

        $admitted = $gate->evaluate(
            ['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'],
            ['./vendor/bin/phpunit tests/Unit/Ai/FooTest.php'],
            ['risk_level' => 'medium', 'dependencies' => ['dep_a'], 'satisfied_dependencies' => ['dep_a']],
        );
        $this->assertTrue($admitted['admitted']);
        $this->assertTrue($admitted['token_spending_cycle']);
        $this->assertSame([], $admitted['blockers']);
        // A code file + its mirrored test is ONE logical layer, not two.
        $this->assertCount(1, $admitted['scope_layers']);

        // No allowed files.
        $this->assertContains(
            ZeroProviderPreflightGate::REASON_NO_ALLOWED_FILES,
            $gate->evaluate([], ['git diff --check'], [])['blockers'],
        );

        // No validation command.
        $this->assertContains(
            ZeroProviderPreflightGate::REASON_NO_VALIDATION_COMMAND,
            $gate->evaluate(['app/Services/Ai/Foo.php'], [], [])['blockers'],
        );

        // Risk above R3.
        $this->assertContains(
            ZeroProviderPreflightGate::REASON_RISK_ABOVE_CEILING,
            $gate->evaluate(['app/Services/Ai/Foo.php'], ['git diff --check'], ['risk_level' => 'R4'])['blockers'],
        );

        // Unsatisfied dependency.
        $this->assertContains(
            ZeroProviderPreflightGate::REASON_DEPS_UNSATISFIED,
            $gate->evaluate(['app/Services/Ai/Foo.php'], ['git diff --check'], ['dependencies' => ['missing_dep']])['blockers'],
        );

        // Scope above the file budget.
        $tooMany = array_map(static fn (int $i): string => "app/Services/Ai/F{$i}.php", range(1, ZeroProviderPreflightGate::MAX_SCOPE_FILES + 1));
        $this->assertContains(
            ZeroProviderPreflightGate::REASON_SCOPE_TOO_LARGE,
            $gate->evaluate($tooMany, ['git diff --check'], [])['blockers'],
        );

        // Scope spanning multiple production layers.
        $multiLayer = $gate->evaluate(
            ['app/Services/Ai/Foo.php', 'app/Console/Commands/Bar.php'],
            ['git diff --check'],
            [],
        );
        $this->assertContains(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $multiLayer['blockers']);
        $this->assertFalse($multiLayer['admitted']);
        $this->assertFalse($multiLayer['token_spending_cycle']);
    }

    public function test_operator_authorized_injected_slice_may_span_a_few_layers_within_ceiling(): void
    {
        $gate = new ZeroProviderPreflightGate;
        $authorized = ['risk_level' => 'medium', 'auto_execution_allowed' => true, 'operator_review_required' => false];

        // A real value slice: two services + the config flag it toggles + tests. Two production
        // layers (app/Services, config) — admitted ONLY because the operator authorized the slice.
        // The merge governor downstream still enforces validation-green + changed⊆allowed + bounded.
        $files = [
            'app/Services/Ai/Aaeos/AtlasAaeosPhaseRouterService.php',
            'app/Services/Ai/AgenticEngineeringOs/AtlasAaeosHttpPathFacadeService.php',
            'config/atlas.php',
            'tests/Unit/Ai/Aaeos/AtlasAaeosPhaseRouterServiceTest.php',
        ];
        $ok = $gate->evaluate($files, ['git diff --check'], $authorized);
        $this->assertTrue($ok['admitted'], 'authorized multi-layer slice within ceiling must be admitted');
        $this->assertTrue($ok['operator_authorized']);
        $this->assertSame([], $ok['blockers']);

        // The SAME files WITHOUT operator authorization stay capped at one layer → blocked.
        $unauth = $gate->evaluate($files, ['git diff --check'], ['risk_level' => 'medium']);
        $this->assertFalse($unauth['admitted']);
        $this->assertFalse($unauth['operator_authorized']);
        $this->assertContains(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $unauth['blockers']);

        // Authorization is NOT a blank cheque: beyond the layer ceiling it still blocks.
        $tooManyLayers = $gate->evaluate(
            ['app/Services/Ai/A.php', 'app/Console/Commands/B.php', 'config/atlas.php', 'routes/api.php'],
            ['git diff --check'],
            $authorized,
        );
        $this->assertContains(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $tooManyLayers['blockers']);

        // The risk ceiling is NEVER relaxed by authorization.
        $r4 = $gate->evaluate($files, ['git diff --check'], ['auto_execution_allowed' => true, 'operator_review_required' => false, 'risk_level' => 'R4']);
        $this->assertContains(ZeroProviderPreflightGate::REASON_RISK_ABOVE_CEILING, $r4['blockers']);
    }

    public function test_skips_test_authoring_for_complex_existing_subject_before_provider_spend(): void
    {
        $gate = new ZeroProviderPreflightGate;
        $files = ['app/Services/Ai/Foo/BarService.php', 'tests/Unit/Ai/Foo/BarServiceTest.php'];

        // Existing subject WITH a constructor dependency → both providers spend-and-fail to
        // author a passing test for it. The gate must skip it BEFORE provider spend.
        $withDeps = $gate->evaluate($files, ['git diff --check'], [
            'preflight_test_authoring' => ['is_test_authoring' => true, 'subject_exists' => true, 'subject_constructor_deps' => 1, 'subject_loc' => 80],
        ]);
        $this->assertFalse($withDeps['admitted']);
        $this->assertFalse($withDeps['token_spending_cycle'], 'a skipped cycle must NOT count as token-spending');
        $this->assertContains(ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE, $withDeps['blockers']);

        // Existing subject that is large/branchy (over the LOC ceiling) → also skipped.
        $large = $gate->evaluate($files, ['git diff --check'], [
            'preflight_test_authoring' => ['is_test_authoring' => true, 'subject_exists' => true, 'subject_constructor_deps' => 0, 'subject_loc' => 254],
        ]);
        $this->assertContains(ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE, $large['blockers']);
    }

    public function test_skips_known_non_retryable_failure_pattern_before_provider_spend(): void
    {
        $gate = new ZeroProviderPreflightGate;

        $report = $gate->evaluate(
            [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ZeroDowntimeGateContract.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseServiceTest.php',
            ],
            ['git diff --check'],
            [
                'risk_level' => 'medium',
                'auto_execution_allowed' => true,
                'operator_review_required' => false,
                'repair_learning' => [
                    'prior_blocked_occurrences' => 7,
                    'top_prior_blockers' => [
                        'delivery_not_final_scaffold_or_mock',
                        'owner_runtime_deletion_heavy_product_diff_without_test_update',
                    ],
                ],
            ],
        );

        $this->assertFalse($report['admitted']);
        $this->assertFalse($report['token_spending_cycle']);
        $this->assertContains(ZeroProviderPreflightGate::REASON_PRIOR_NON_RETRYABLE_FAILURE_PATTERN, $report['blockers']);

        $onePriorFailure = $gate->evaluate(
            ['app/Services/Ai/Foo/BarService.php', 'tests/Unit/Ai/Foo/BarServiceTest.php'],
            ['git diff --check'],
            [
                'repair_learning' => [
                    'prior_blocked_occurrences' => 1,
                    'top_prior_blockers' => ['owner_runtime_provider_diff_quality_gate_failed'],
                ],
            ],
        );
        $this->assertTrue($onePriorFailure['admitted']);
    }

    public function test_admits_pure_existing_subject_and_new_class_creation(): void
    {
        $gate = new ZeroProviderPreflightGate;
        $files = ['app/Services/Ai/Foo/BarService.php', 'tests/Unit/Ai/Foo/BarServiceTest.php'];

        // Pure, small existing subject (0 deps, within LOC) → the loop can test it → admitted.
        $pure = $gate->evaluate($files, ['git diff --check'], [
            'preflight_test_authoring' => ['is_test_authoring' => true, 'subject_exists' => true, 'subject_constructor_deps' => 0, 'subject_loc' => 120],
        ]);
        $this->assertTrue($pure['admitted']);
        $this->assertNotContains(ZeroProviderPreflightGate::REASON_TEST_SUBJECT_NOT_AUTONOMOUSLY_TESTABLE, $pure['blockers']);

        // Brand-new class created WITH its test (subject does not pre-exist) is the proven-
        // deliverable path and must NEVER be blocked by this gate, regardless of metrics.
        $newClass = $gate->evaluate($files, ['git diff --check'], [
            'preflight_test_authoring' => ['is_test_authoring' => true, 'subject_exists' => false, 'subject_constructor_deps' => 0, 'subject_loc' => 0],
        ]);
        $this->assertTrue($newClass['admitted']);

        // No test-authoring signal at all → gate behaves exactly as before (admitted).
        $noSignal = $gate->evaluate($files, ['git diff --check'], ['risk_level' => 'medium']);
        $this->assertTrue($noSignal['admitted']);
    }

    public function test_unqualified_cycle_is_cheaply_skipped_without_provider_spend(): void
    {
        $runnerCalls = 0;
        $executor = $this->executor($runnerCalls);

        // Scope spans two unrelated production layers — unqualified.
        $report = $executor->execute($this->input([
            'allowed_files' => [
                'app/Services/Ai/Foo.php',
                'app/Console/Commands/Bar.php',
            ],
        ]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_PREFLIGHT_SKIPPED, $report['status']);
        $this->assertFalse($report['merge_allowed']);
        $this->assertFalse($report['provider_invoked']);
        $this->assertFalse($report['provider_router_used']);
        // The metric: a skip is NOT a token-spending cycle.
        $this->assertFalse($report['token_spending_cycle']);
        $this->assertContains(ZeroProviderPreflightGate::REASON_SCOPE_MULTIPLE_LAYERS, $report['blockers']);
        // No owner command ran — zero provider spend. The whole AP-747..AP-759
        // chain was short-circuited before any runtime command.
        $this->assertSame(0, $runnerCalls);
        $this->assertSame([], $report['steps']);
    }

    public function test_qualified_cycle_passes_the_gate_and_reaches_the_owner_command(): void
    {
        $runnerCalls = 0;
        $executor = $this->executor($runnerCalls);

        $report = $executor->execute($this->input([
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'validation_commands' => ['git diff --check'],
            'finding' => ['finding_id' => 'aff_x', 'title' => 'Improve owner flow', 'risk_level' => 'medium'],
        ]));

        $this->assertSame(Ap786OwnerFlowExecutor::STATUS_COMPLETED, $report['status']);
        $this->assertTrue($report['merge_allowed']);
        // A qualified cycle reached AP-759 and IS a token-spending cycle.
        $this->assertTrue($report['token_spending_cycle']);
        $this->assertSame(1, $runnerCalls);
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
            'finding' => ['finding_id' => 'aff_x', 'title' => 'Improve owner flow'],
            'allowed_files' => ['app/Services/Ai/Example.php'],
            'preflight_report' => ['handoff_packet' => ['handoff_hash' => 'sha256:handoff_x']],
            'sandbox_record' => ['sandbox_id' => 'afsb_x', 'status' => 'materialized'],
            'worktree_path' => '/tmp/atlas-ap786-preflight-worktree',
            'execute' => true,
            'validation_commands' => ['git diff --check'],
        ], $overrides);
    }

    private function executor(int &$runnerCalls): Ap786OwnerFlowExecutor
    {
        $ownerResult = [
            'result_id' => 'afrunres_x',
            'result_status' => 'completed',
            'changed_files' => ['app/Services/Ai/Example.php'],
            'runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 1]],
        ];

        return new Ap786OwnerFlowExecutor(
            new class implements OwnerQueueReleaseGate
            {
                public function release(array $input): array
                {
                    return ['status' => AreaFocusDevForgeReleaseService::STATUS_READY, 'release_id' => 'afrel_x', 'queue_item' => ['queue_item_id' => 'afq_x']];
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
                    return ['status' => AreaFocusOwnerQueueConsumptionGateService::STATUS_READY, 'consumption_id' => 'afcons_x', 'release_id' => 'afrel_x', 'queue_item_id' => 'afq_x', 'sandbox_binding' => ['sandbox_id' => 'afsb_x']];
                }
            },
            new class implements OwnerRuntimeExecutionAdapter
            {
                public function project(array $input): array
                {
                    return ['status' => StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY, 'owner_execution_id' => 'afexec_x'];
                }
            },
            new class($runnerCalls, $ownerResult) implements OwnerSandboxRuntimeRunner
            {
                /** @param array<string,mixed> $ownerResult */
                public function __construct(private int &$calls, private array $ownerResult) {}

                public function project(array $input): array
                {
                    $this->calls++;

                    return ['status' => StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, 'owner_sandbox_run_id' => 'afrun_x', 'owner_result' => $this->ownerResult];
                }
            },
            new class implements OwnerRuntimeResultProjector
            {
                public function project(array $input): array
                {
                    return ['status' => StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, 'result_bridge_id' => 'afobr_x'];
                }
            },
            new ForgeOwnerRuntimeDispatchBridge,
        );
    }
}
