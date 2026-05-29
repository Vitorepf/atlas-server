<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Ap786RealCycleCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Ap786RealCycleCertificationServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap786_cert_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): Ap786RealCycleCertificationService
    {
        $service = new Ap786RealCycleCertificationService();
        $service->setSessionsDirForTesting($this->tmp.'/sessions');

        return $service;
    }

    /**
     * A fully shaped real cycle: full owner-flow chain recorded, validation
     * passed, evidence/inbox emitted, governed ff-merge, isolated branch/worktree
     * and not a direct-provider path.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function realCycle(string $suffix, array $overrides = []): array
    {
        return array_replace_recursive([
            'cycle_id' => 'aesc_'.$suffix,
            'cycle_index' => 1,
            'final_status' => 'cycle_completed',
            'selected_finding' => ['finding_id' => 'find_'.$suffix, 'title' => 'Harden factory runtime '.$suffix],
            'owner' => 'atlas_dev',
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/Foo.php'],
            'sandbox_id' => 'afsb_'.$suffix,
            'branch_ref' => 'atlas/area-focus/agentic-engineering-os/atlas-dev/'.$suffix,
            'worktree_path' => '/tmp/atlas-worktrees/'.$suffix,
            'branch_created' => true,
            'worktree_created' => true,
            'provider_called' => true,
            'flow_integrity_gate' => [
                'uses_full_owner_runtime_chain' => true,
                'direct_provider_driver_path' => false,
                'direct_provider_driver_allowed' => false,
            ],
            'owner_flow' => [
                'AP-756' => ['status' => 'materialized', 'id' => 'afsb_'.$suffix],
                'AP-747' => ['status' => 'recorded', 'id' => 'rel_'.$suffix],
                'AP-757' => ['status' => 'recorded', 'id' => 'bind_'.$suffix],
                'AP-749' => ['status' => 'recorded', 'id' => 'cons_'.$suffix],
                'AP-758' => ['status' => 'recorded', 'id' => 'exec_'.$suffix],
                'AP-759' => ['status' => 'recorded', 'id' => 'run_'.$suffix],
                'AP-750' => ['status' => 'recorded', 'id' => 'rb_'.$suffix],
            ],
            'validation' => [
                'passed' => true,
                'commands' => ['php artisan test tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionServiceTest.php'],
                'results' => [['command' => 'php artisan test ...', 'ok' => true]],
            ],
            'commit' => ['message' => 'Harden factory runtime '.$suffix, 'committed' => true],
            'result_bridge_id' => 'rb_'.$suffix,
            'inbox_item_id' => 'inbox_'.$suffix,
            'inbox_emitted_before_merge_attempt' => true,
            'merge_governance' => ['status' => 'merged'],
            'merge_performed' => true,
        ], $overrides);
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function sessionFixture(array $cycles, array $overrides = []): array
    {
        return array_replace([
            'schema_version' => AutonomousEvolutionSessionService::RECORD_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => 'completed',
            'session_id' => 'aess_test',
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'session_storage_status' => 'recorded',
            'claim_policy' => ['direct_provider_driver_allowed' => false],
            'cycles' => $cycles,
        ], $overrides);
    }

    public function test_certifies_a_fully_shaped_three_real_cycle_session(): void
    {
        $session = $this->sessionFixture([
            $this->realCycle('a'),
            $this->realCycle('b'),
            $this->realCycle('c'),
        ]);

        $report = $this->service()->certify(['session_report' => $session]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertTrue($report['three_cycle_audit']['satisfied']);
        $this->assertSame(3, $report['three_cycle_audit']['certified_real_cycles']);
        foreach ($report['cycles'] as $cycle) {
            $this->assertSame(Ap786RealCycleCertificationService::STATUS_CERTIFIED, $cycle['status']);
            $this->assertSame([], $cycle['missing_stages']);
            $this->assertSame([], $cycle['fake_signals']);
        }
        $this->assertIsString($report['next_safe_command']);
        $this->assertStringContainsString('--execute', (string) $report['next_safe_command']);
    }

    public function test_blocks_direct_provider_diagnostic_cycle(): void
    {
        $session = $this->sessionFixture(
            [$this->realCycle('a')],
            ['claim_policy' => ['direct_provider_driver_allowed' => true]],
        );

        $report = $this->service()->certify(['session_report' => $session]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertTrue($report['session_direct_provider_driver_allowed']);
        $this->assertContains('session_direct_provider_driver_allowed', $report['cycles'][0]['fake_signals']);
        // A direct-provider session must never offer a "run more" next command.
        $this->assertNull($report['next_safe_command']);
    }

    public function test_blocks_cycle_that_used_provider_router_direct_path(): void
    {
        $cycle = $this->realCycle('a', ['flow_integrity_gate' => [
            'uses_full_owner_runtime_chain' => false,
            'direct_provider_driver_path' => true,
            'direct_provider_driver_allowed' => false,
        ]]);

        $report = $this->service()->certify(['session_report' => $this->sessionFixture([$cycle])]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['cycles'][0]['status']);
        $this->assertContains('provider_router_direct_path', $report['cycles'][0]['fake_signals']);
        $this->assertContains('not_full_owner_runtime_chain', $report['cycles'][0]['fake_signals']);
    }

    public function test_blocks_cycle_missing_ap759_owner_run(): void
    {
        $cycle = $this->realCycle('a');
        unset($cycle['owner_flow']['AP-759']); // no owner sandbox run evidence anywhere

        $report = $this->service()->certify(['session_report' => $this->sessionFixture([$cycle])]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['cycles'][0]['status']);
        $this->assertContains('AP-759_stage_missing', $report['cycles'][0]['missing_stages']);
    }

    public function test_blocks_repeated_finding_and_commit_title(): void
    {
        // Two cycles claiming the exact same finding + commit title = wasted/fake repeat.
        $first = $this->realCycle('a');
        $second = $this->realCycle('a', ['cycle_id' => 'aesc_b', 'cycle_index' => 2]);

        $report = $this->service()->certify(['session_report' => $this->sessionFixture([$first, $second])]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_CERTIFIED, $report['cycles'][0]['status']);
        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['cycles'][1]['status']);
        $this->assertContains('duplicated_finding_from_previous_cycle', $report['cycles'][1]['fake_signals']);
        $this->assertContains('duplicated_commit_title_from_previous_cycle', $report['cycles'][1]['fake_signals']);
    }

    public function test_blocks_forge_cycle_with_changed_files_but_no_provider_call(): void
    {
        // SEC-001 provider-proof: a forge cycle reporting a diff with zero
        // provider calls is unattributed and must be certified as fake.
        $cycle = $this->realCycle('f', [
            'owner' => 'forge',
            'changed_files' => ['app/Services/Ai/Forge.php'],
            'runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 0]],
        ]);

        $report = $this->service()->certify(['session_report' => $this->sessionFixture([$cycle])]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['status']);
        $cycleReport = $report['cycles'][0] ?? [];
        $this->assertContains('forge_diff_without_provider_proof', $cycleReport['fake_signals'] ?? []);
    }

    public function test_three_cycle_audit_not_satisfied_when_one_cycle_is_incomplete(): void
    {
        $incomplete = $this->realCycle('c');
        unset($incomplete['owner_flow']['AP-758']); // missing execution stage

        $report = $this->service()->certify(['session_report' => $this->sessionFixture([
            $this->realCycle('a'),
            $this->realCycle('b'),
            $incomplete,
        ])]);

        $this->assertSame(Ap786RealCycleCertificationService::STATUS_PARTIAL, $report['status']);
        $this->assertFalse($report['three_cycle_audit']['satisfied']);
        $this->assertSame(2, $report['three_cycle_audit']['certified_real_cycles']);
        $this->assertSame(1, $report['three_cycle_audit']['missing_real_cycles']);
        $this->assertContains('AP-758_stage_missing', $report['cycles'][2]['missing_stages']);
        // Not proven: must not suggest the --execute run.
        $this->assertStringNotContainsString('--execute', (string) $report['next_safe_command']);
    }

    public function test_blocks_when_session_is_not_replayable_from_jsonl(): void
    {
        // A report schema that is not recorded and not present in JSONL.
        $session = $this->sessionFixture([$this->realCycle('a')], [
            'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
            'session_storage_status' => 'projected',
        ]);

        $report = $this->service()->certify(['session_report' => $session]);

        $this->assertFalse($report['session_replayable_from_jsonl']);
        $this->assertContains('session_not_replayable_from_jsonl', $report['cycles'][0]['missing_stages']);
        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $report['status']);
    }

    public function test_replays_recorded_session_from_jsonl(): void
    {
        $service = $this->service();
        $session = $this->sessionFixture([
            $this->realCycle('a'),
            $this->realCycle('b'),
            $this->realCycle('c'),
        ], ['session_id' => 'aess_replayme']);

        File::ensureDirectoryExists($this->tmp.'/sessions');
        File::put(
            $service->sessionRecordPath('agentic_engineering_os'),
            json_encode($session, JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $report = $service->replay('aess_replayme', 'agentic_engineering_os');

        $this->assertSame('replayed_from_jsonl', $report['source']);
        $this->assertSame(Ap786RealCycleCertificationService::STATUS_CERTIFIED, $report['status']);
        $this->assertTrue($report['session_replayable_from_jsonl']);
        $this->assertSame(3, $report['three_cycle_audit']['certified_real_cycles']);
    }

    public function test_blocks_unknown_session_and_non_ap786_record(): void
    {
        $service = $this->service();

        $missing = $service->replay('does_not_exist', 'agentic_engineering_os');
        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $missing['status']);
        $this->assertSame('session_record_not_found', $missing['reason']);

        $notAp786 = $service->certify(['session_report' => ['ap_contract' => 'AP-999', 'schema_version' => 'x', 'cycles' => []]]);
        $this->assertSame(Ap786RealCycleCertificationService::STATUS_BLOCKED, $notAp786['status']);
        $this->assertSame('not_an_ap786_session', $notAp786['reason']);
    }
}
