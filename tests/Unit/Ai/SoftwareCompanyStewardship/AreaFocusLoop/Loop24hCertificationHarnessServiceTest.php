<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Loop24hCertificationHarnessService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AP-792 · 24h loop certification harness.
 *
 * The harness must never report a production `passed` from a fixture/test double
 * and never when any real-authority component is mock/simulated.
 */
final class Loop24hCertificationHarnessServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/atlas_loop24h_cert_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        app(AutonomousEvolutionSessionService::class)->setStorageDirForTesting(null);
        app(Reliable24hLoopRunnerService::class)->setStorageRootForTesting(null);
        app()->forgetInstance(Loop24hCertificationHarnessService::class);
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    private function harness(): Loop24hCertificationHarnessService
    {
        return app(Loop24hCertificationHarnessService::class);
    }

    /** A real recorded merged cycle carrying full real authority. */
    private function realMergedCycleWithFullAuthority(): array
    {
        return [
            'cycle_id' => 'aesc_real',
            'final_status' => 'cycle_completed',
            'owner' => 'atlas_dev',
            'provider_called' => true,
            'merge_performed' => true,
            'work_packet_id' => 'wp_real',
            'decision_receipt_id' => 'dr_real',
            'decision_receipt_hash' => 'sha256:deadbeef',
            'provider_result' => ['topology_id' => 'topo_real'],
            'workspace_id' => 'ws_real',
            'result_bridge_id' => 'rb_real',
            'evidence_recorded' => 'ev_real',
            'inbox_item_id' => 'inbox_real',
            'branch_created' => true,
            'worktree_created' => true,
            'sandbox_id' => 'sbx_real',
            'branch_ref' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/real',
            'worktree_path' => '/tmp/atlas/sbx_real',
            'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php'],
            'validation' => ['passed' => true, 'status' => 'passed'],
            'owner_sandbox_run_id' => 'osr_real',
            'merge_hash' => 'b8d6a749',
            'merge_governance' => [
                'status' => 'merged',
                'strategy' => 'ff_only',
                'ff_only' => true,
                'receipt_id' => 'mrg_real',
                'merge_result' => ['base_head' => 'dc71d3a1', 'new_head' => 'b8d6a749'],
            ],
        ];
    }

    /** A real Dev/Forge owner-runtime cycle, not a maintenance-only cycle. */
    private function realDevForgeRuntimeCycleWithFullAuthority(): array
    {
        return array_merge($this->realMergedCycleWithFullAuthority(), [
            'selected_finding' => [
                'finding_id' => 'factory_max_ap790_priority_owner_runtime_real_execution_bridge',
                'title' => 'Wire AP-790 owner runtime real execution bridge',
                'origin_type' => 'factory_max_seed',
            ],
            'flow_integrity_gate' => ['uses_full_owner_runtime_chain' => true],
            'scope_contract' => [
                'allowed_files' => [
                    'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerService.php',
                    'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Reliable24hLoopRunnerServiceTest.php',
                ],
            ],
        ]);
    }

    /** @return array<string,bool> */
    private function allCapabilitiesPresent(): array
    {
        return [
            'ap786_loop_session' => true, 'ap786_cycle_certification' => true,
            'branch_sandbox_materializer' => true, 'branch_merge_governor' => true,
            'owner_flow_runner' => true, 'robust_forge_quality_contract' => true,
            'forge_owner_runtime_dispatch_bridge' => true, 'loop_receipt_integrity' => true,
            'product_mode_visibility' => true,
            'forge_live_authority' => true, 'loop_resume_ledger' => true, 'loop_kill_switch' => true,
        ];
    }

    public function test_test_mode_never_certifies_production(): void
    {
        $report = $this->harness()->certify([
            'capability_overrides' => $this->allCapabilitiesPresent(),
        ]);

        $this->assertSame(Loop24hCertificationHarnessService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('test_mode', $report['certification_mode']);
        $this->assertFalse($report['production_certified'], 'fixtures must never certify production');
        $this->assertNotSame(Loop24hCertificationHarnessService::STATUS_PASSED, $report['status']);
        $this->assertFalse($report['claim_policy']['fixtures_certify_production']);
        $this->assertFalse($report['claim_policy']['maintenance_cycles_certify_production']);
        $this->assertFalse($report['claim_policy']['false_pass_possible']);
        // Every scenario is a fixture self-test, not production.
        foreach ($report['scenarios'] as $scenario) {
            $this->assertSame('fake_fixture', $scenario['evaluated_against']);
            $this->assertNotSame(Loop24hCertificationHarnessService::STATUS_PASSED, $scenario['status']);
        }
    }

    public function test_passed_only_with_runtime_real_full_authority_and_required_capabilities(): void
    {
        $report = $this->harness()->certify([
            'use_real_services' => true,
            'scenario' => 'merge_eligible_ff_only_receipt',
            'capability_overrides' => $this->allCapabilitiesPresent(),
            'real_recorded_sessions' => [['session_id' => 'aes_real', 'cycles' => [$this->realDevForgeRuntimeCycleWithFullAuthority()]]],
        ]);

        $this->assertSame('runtime_real', $report['certification_mode']);
        $this->assertTrue($report['production_certified']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PASSED, $report['status']);
        $this->assertSame(1, $report['recorded_cycle_runtime_audit']['dev_forge_runtime_cycle_count']);

        $scenario = $report['scenarios'][0];
        $this->assertSame('merge_eligible_ff_only_receipt', $scenario['scenario']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PASSED, $scenario['status']);
        $this->assertSame('runtime_real', $scenario['evaluated_against']);
        $this->assertSame(Loop24hCertificationHarnessService::CYCLE_RUNTIME_DEV_FORGE, $scenario['cycle_runtime_class']);
        $this->assertTrue($scenario['production_scenario_certified']);
        $this->assertSame([], $scenario['missing_real_authority']);
        $this->assertSame([], $scenario['missing_isolated_agent_execution_facts']);
        $this->assertTrue($scenario['isolated_agent_execution_substrate']['provider_invoked_with_authority']);
        $this->assertTrue($scenario['isolated_agent_execution_substrate']['sandbox_worktree_materialized']);
        foreach ($scenario['real_authority'] as $component => $present) {
            $this->assertTrue($present, "real authority component {$component} must be present for a production pass");
        }
    }

    public function test_no_pass_when_a_required_capability_is_missing(): void
    {
        $caps = $this->allCapabilitiesPresent();
        $caps['branch_merge_governor'] = false; // required by the merge scenario

        $report = $this->harness()->certify([
            'use_real_services' => true,
            'scenario' => 'merge_eligible_ff_only_receipt',
            'capability_overrides' => $caps,
            'real_recorded_sessions' => [['session_id' => 'aes_real', 'cycles' => [$this->realMergedCycleWithFullAuthority()]]],
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $report['status']);
        $this->assertContains('branch_merge_governor', $report['missing_capabilities']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $report['scenarios'][0]['status']);
    }

    public function test_no_pass_when_real_authority_component_is_simulated(): void
    {
        // A real cycle missing the Decision Receipt → cannot be production-certified.
        $cycle = $this->realMergedCycleWithFullAuthority();
        unset($cycle['decision_receipt_id'], $cycle['decision_receipt_hash']);

        $report = $this->harness()->certify([
            'use_real_services' => true,
            'scenario' => 'merge_eligible_ff_only_receipt',
            'capability_overrides' => $this->allCapabilitiesPresent(),
            'real_recorded_sessions' => [['session_id' => 'aes_real', 'cycles' => [$cycle]]],
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $report['status']);
        $scenario = $report['scenarios'][0];
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $scenario['status']);
        $this->assertContains('real_decision_receipt', $scenario['missing_real_authority']);
    }

    public function test_no_pass_when_ap793_isolated_agent_substrate_fact_is_missing(): void
    {
        $cycle = $this->realDevForgeRuntimeCycleWithFullAuthority();
        $cycle['provider_called'] = false;

        $report = $this->harness()->certify([
            'use_real_services' => true,
            'scenario' => 'merge_eligible_ff_only_receipt',
            'capability_overrides' => $this->allCapabilitiesPresent(),
            'real_recorded_sessions' => [['session_id' => 'aes_real', 'cycles' => [$cycle]]],
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $report['status']);
        $this->assertContains('provider_invoked_with_authority', $report['missing_isolated_agent_execution_facts']);
        $this->assertContains('provider_invoked_with_authority', $report['scenarios'][0]['missing_isolated_agent_execution_facts']);
    }

    public function test_no_pass_when_recorded_cycle_is_maintenance_only_despite_full_real_authority(): void
    {
        $report = $this->harness()->certify([
            'use_real_services' => true,
            'scenario' => 'merge_eligible_ff_only_receipt',
            'capability_overrides' => $this->allCapabilitiesPresent(),
            'real_recorded_sessions' => [['session_id' => 'aes_real', 'cycles' => [$this->realMergedCycleWithFullAuthority()]]],
        ]);

        $this->assertFalse($report['production_certified']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $report['status']);
        $this->assertTrue($report['recorded_cycle_runtime_audit']['partial_runtime_false_confidence_blocked']);
        $this->assertSame(1, $report['recorded_cycle_runtime_audit']['maintenance_cycle_count']);
        $this->assertSame(0, $report['recorded_cycle_runtime_audit']['dev_forge_runtime_cycle_count']);

        $scenario = $report['scenarios'][0];
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $scenario['status']);
        $this->assertSame(Loop24hCertificationHarnessService::CYCLE_RUNTIME_MAINTENANCE, $scenario['cycle_runtime_class']);
        $this->assertContains('maintenance_only_cycle_not_dev_forge_runtime', $scenario['missing_real_authority']);
    }

    public function test_partial_lists_exact_missing_optional_capabilities(): void
    {
        $caps = $this->allCapabilitiesPresent();
        $caps['loop_resume_ledger'] = false; // AP-790 not merged
        $caps['loop_kill_switch'] = false;   // AP-791 not merged

        $report = $this->harness()->certify(['capability_overrides' => $caps]);

        $this->assertSame(Loop24hCertificationHarnessService::STATUS_PARTIAL, $report['status']);
        $this->assertContains('loop_resume_ledger', $report['missing_capabilities']);
        $this->assertContains('loop_kill_switch', $report['missing_capabilities']);
        $this->assertNotContains('ap786_loop_session', $report['missing_capabilities']);
    }

    public function test_injected_fixture_safety_violation_is_blocked(): void
    {
        // A fixture where a provider was called despite missing Forge authority is
        // a safety violation the contract self-test must catch as blocked.
        $report = $this->harness()->certify([
            'scenario' => 'forge_missing_authority_honest_block',
            'fixtures' => [
                'forge_missing_authority_honest_block' => [
                    'final_status' => 'blocked',
                    'blockers' => ['full_atlas_forge_flow_required'],
                    'provider_called' => true, // VIOLATION
                    'merge_performed' => false,
                ],
            ],
        ]);

        $this->assertSame(Loop24hCertificationHarnessService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(Loop24hCertificationHarnessService::STATUS_BLOCKED, $report['scenarios'][0]['status']);
        $this->assertFalse($report['scenarios'][0]['invariants']['no_provider_called']);
    }

    public function test_every_scenario_has_machine_readable_evidence(): void
    {
        $report = $this->harness()->certify();

        $this->assertSame(10, $report['scenario_count']);
        foreach ($report['scenarios'] as $scenario) {
            $this->assertSame(Loop24hCertificationHarnessService::SCENARIO_SCHEMA, $scenario['schema_version']);
            foreach (['scenario', 'status', 'evaluated_against', 'cycle_runtime_class', 'contract_self_test', 'invariants', 'evidence', 'real_authority', 'missing_real_authority', 'required_capabilities'] as $key) {
                $this->assertArrayHasKey($key, $scenario, "scenario missing key {$key}");
            }
            $this->assertIsArray($scenario['invariants']);
            $this->assertIsArray($scenario['real_authority']);
        }
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
    }

    public function test_runtime_real_certification_tails_session_and_runner_ledgers(): void
    {
        $session = app(AutonomousEvolutionSessionService::class);
        $session->setStorageDirForTesting($this->tmp.'/sessions');
        $this->app->instance(AutonomousEvolutionSessionService::class, $session);

        $runner = app(Reliable24hLoopRunnerService::class);
        $runner->setStorageRootForTesting($this->tmp.'/reliable_24h_loop');
        $this->app->instance(Reliable24hLoopRunnerService::class, $runner);
        app()->forgetInstance(Loop24hCertificationHarnessService::class);

        $sessionPath = $this->tmp.'/sessions/agentic_engineering_os.jsonl';
        File::ensureDirectoryExists(dirname($sessionPath));
        for ($i = 0; $i < 2500; $i++) {
            File::append($sessionPath, json_encode([
                'session_id' => 'old_'.$i,
                'cycles' => [],
                'padding' => str_repeat('x', 256),
            ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
        File::append($sessionPath, str_repeat('x', 1048577).PHP_EOL);
        File::append($sessionPath, json_encode([
            'session_id' => 'latest_real',
            'cycles' => [$this->realDevForgeRuntimeCycleWithFullAuthority()],
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);

        $ledgerPath = $this->tmp.'/reliable_24h_loop/agentic_engineering_os__dev_forge.jsonl';
        File::ensureDirectoryExists(dirname($ledgerPath));
        for ($i = 1; $i <= 2500; $i++) {
            File::append($ledgerPath, json_encode([
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'cycle_index' => $i,
            ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $report = $this->harness()->certify([
            'use_real_services' => true,
            'capability_overrides' => $this->allCapabilitiesPresent(),
        ]);

        $this->assertSame('runtime_real', $report['certification_mode']);
        $this->assertSame(1, $report['real_recorded_cycles_inspected']);

        $crashRestart = array_values(array_filter(
            $report['scenarios'],
            static fn (array $scenario): bool => ($scenario['scenario'] ?? '') === 'crash_restart_resume_ledger',
        ))[0] ?? null;

        $this->assertIsArray($crashRestart);
        $this->assertSame(2500, $crashRestart['evidence']['ledger_line_count']);
        $this->assertSame(2500, $crashRestart['evidence']['last_cycle_index']);
    }

    public function test_deterministic_report_hash(): void
    {
        $input = ['capability_overrides' => $this->allCapabilitiesPresent()];
        $a = $this->harness()->certify($input);
        $b = $this->harness()->certify($input);
        $this->assertSame($a['report_hash'], $b['report_hash']);
    }

    public function test_command_strict_exits_non_zero_on_partial(): void
    {
        $exit = Artisan::call('atlas:software-company-stewardship:certify-24h-loop', ['--json' => true, '--strict' => true]);
        $this->assertNotSame(0, $exit, 'strict must exit non-zero on partial');

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('AP-792', $payload['ap_contract']);
        $this->assertFalse($payload['production_certified']);
    }
}
