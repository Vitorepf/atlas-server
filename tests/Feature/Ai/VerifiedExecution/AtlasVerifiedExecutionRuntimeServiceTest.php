<?php

namespace Tests\Feature\Ai\VerifiedExecution;

use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionService;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAverTables;
use Tests\Concerns\CreatesAweosTables;
use Tests\TestCase;

class AtlasVerifiedExecutionRuntimeServiceTest extends TestCase
{
    use CreatesAverTables;
    use CreatesAweosTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAweosTables();
        $this->createAverTables();
    }

    protected function tearDown(): void
    {
        $this->dropAverTables();
        $this->dropAweosTables();

        parent::tearDown();
    }

    public function test_plan_creates_aver_l10_execution(): void
    {
        $payload = app(AtlasVerifiedExecutionRuntimeService::class)->plan([
            'objective' => 'executar patch com testes e rollback',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['doc:aver'],
        ]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, $payload['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::LEVEL_MAX, $payload['maturity_level']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_READY, $payload['status']);
        $this->assertTrue(data_get($payload, 'execution_contract.requires_certified_execution'));
        $this->assertDatabaseCount('atlas_aver_executions', 1);
    }

    public function test_plan_from_verified_evolution_contract_consumes_aveor_execution_contract(): void
    {
        $contract = app(AtlasVerifiedEvolutionRuntimeService::class)->executionContract(
            'executar patch de AVEOR via AVER',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $payload = app(AtlasVerifiedExecutionRuntimeService::class)->planFromVerifiedEvolutionContract($contract);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, $payload['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_READY, $payload['status']);
        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::EXECUTION_CONTRACT_SCHEMA_VERSION, data_get($payload, 'execution_contract.source_verified_evolution_schema'));
        $this->assertSame('ready', data_get($payload, 'execution_contract.source_verified_evolution_status'));
        $this->assertNotEmpty(data_get($payload, 'execution_contract.source_verified_evolution_contract_hash'));
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($payload, 'execution_contract.allowed_write_paths'));
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($payload, 'patch_plan.expected_files'));
        $this->assertContains('git diff --check', data_get($payload, 'verification_plan.expected_commands'));
        $this->assertSame([], data_get($payload, 'safety_gate.blockers'));
        $this->assertDatabaseCount('atlas_aver_executions', 1);
    }

    public function test_invalid_verified_evolution_contract_blocks_aver_plan(): void
    {
        $payload = app(AtlasVerifiedExecutionRuntimeService::class)->planFromVerifiedEvolutionContract([
            'schema_version' => 'invalid.schema',
            'status' => 'blocked',
        ]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('invalid_verified_evolution_contract_schema', data_get($payload, 'safety_gate.blockers'));
        $this->assertContains('verified_evolution_contract_not_ready', data_get($payload, 'safety_gate.blockers'));
        $this->assertContains('missing_aver_plan_input', data_get($payload, 'safety_gate.blockers'));
    }

    public function test_run_command_records_safe_command_without_raw_command_text(): void
    {
        $runtime = app(AtlasVerifiedExecutionRuntimeService::class);
        $execution = $runtime->plan(['objective' => 'command ledger smoke', 'evidence_refs' => ['test:plan']]);
        $ledger = $runtime->runCommand([
            'execution_id' => $execution['execution_id'],
            'command' => 'php -r "echo \'ok\';"',
            'cwd' => base_path(),
            'evidence_refs' => ['test:command'],
        ]);

        $encoded = json_encode($ledger, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::COMMAND_LEDGER_SCHEMA, $ledger['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_PASSED, $ledger['status']);
        $this->assertSame(0, $ledger['exit_code']);
        $this->assertStringNotContainsString('php -r', $encoded);
        $this->assertNotEmpty($ledger['command_hash']);
        $this->assertDatabaseCount('atlas_aver_command_ledgers', 1);
    }

    public function test_dangerous_command_is_blocked(): void
    {
        $runtime = app(AtlasVerifiedExecutionRuntimeService::class);
        $execution = $runtime->plan(['objective' => 'block destructive command']);
        $ledger = $runtime->runCommand([
            'execution_id' => $execution['execution_id'],
            'command' => 'rm -rf /',
            'cwd' => base_path(),
        ]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $ledger['status']);
        $this->assertContains('dangerous_command_pattern:rm -rf', data_get($ledger, 'safety_gate.blockers'));
        $this->assertNull($ledger['exit_code']);
    }

    public function test_diff_verifier_blocks_missing_action_manifest(): void
    {
        $runtime = app(AtlasVerifiedExecutionRuntimeService::class);
        $execution = $runtime->plan(['objective' => 'diff blocked']);
        $diff = $runtime->verifyDiff([
            'execution_id' => $execution['execution_id'],
            'changed_files' => ['app/Foo.php'],
            'tests' => ['php artisan test --filter=Foo'],
        ]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $diff['status']);
        $this->assertContains('missing_action_manifests', data_get($diff, 'patch_verifier_report.blocking_reasons'));
    }

    public function test_fixture_cycle_certifies_gold_execution(): void
    {
        $payload = app(AtlasVerifiedExecutionRuntimeService::class)->executeFixtureCycle();

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_PASSED, $payload['status']);
        $this->assertSame('gold', data_get($payload, 'certification.certification_level'));
        $this->assertTrue(data_get($payload, 'sandbox.workspace_cleaned'));
        $this->assertDatabaseCount('atlas_aver_certified_executions', 1);
    }

    public function test_failed_fixture_creates_repair_cycle_and_blocks_certification(): void
    {
        $payload = app(AtlasVerifiedExecutionRuntimeService::class)->executeFixtureCycle(['simulate_test_failure' => true]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::REPAIR_CYCLE_SCHEMA, data_get($payload, 'repair_cycle.schema_version'));
        $this->assertContains(data_get($payload, 'certification.blockers.0'), ['command_ledger_not_green', 'test_ledger_not_green'], true);
        $this->assertDatabaseCount('atlas_aver_repair_cycles', 1);
    }

    public function test_certification_requires_evidence_and_green_ledgers(): void
    {
        $runtime = app(AtlasVerifiedExecutionRuntimeService::class);
        $execution = $runtime->plan(['objective' => 'cannot certify without evidence']);
        $certification = $runtime->certify(['execution_id' => $execution['execution_id']]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $certification['status']);
        $this->assertContains('missing_evidence_refs', $certification['blockers']);
    }

    public function test_aweos_uses_aver_as_standard_verified_execution_sidecar(): void
    {
        $payload = app(AtlasAutonomousWorkExecutionService::class)->run([
            'objective' => 'programming work must receive AVER execution contract',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
            'evidence_refs' => ['test:aweos_aver'],
        ]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, data_get($payload, 'verified_execution.schema_version'));
        $this->assertSame($payload['execution_id'], data_get($payload, 'verified_execution.aweos_execution_id'));
        $this->assertDatabaseCount('atlas_aver_executions', 1);
    }

    public function test_control_plane_exposes_verified_execution_without_raw_objective(): void
    {
        app(AtlasVerifiedExecutionRuntimeService::class)->plan([
            'objective' => 'objetivo aver sensivel que nao deve vazar',
            'domain' => 'programming',
            'flow_id' => 'atlas_dev',
        ]);

        $report = app(AtlasAiControlPlaneService::class)->report();
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);

        $this->assertSame(1, data_get($report, 'summary.verified_execution_total'));
        $this->assertSame(1, data_get($report, 'verified_execution.summary.executions_total'));
        $this->assertStringNotContainsString('objetivo aver sensivel', $encoded);
    }

    public function test_cli_fixture_and_control_plane_emit_json(): void
    {
        Artisan::call('atlas:aver', ['action' => 'fixture-cycle', '--json' => true]);
        $fixture = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.aver.fixture_cycle.v1', $fixture['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_PASSED, $fixture['status']);

        Artisan::call('atlas:aver', ['action' => 'control-plane', '--json' => true]);
        $control = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::CONTROL_PLANE_SCHEMA, $control['schema_version']);
        $this->assertGreaterThanOrEqual(1, data_get($control, 'summary.executions_total'));
    }

    public function test_cli_plan_from_verified_evolution_emits_json(): void
    {
        $contract = app(AtlasVerifiedEvolutionRuntimeService::class)->executionContract(
            'cli AVER from AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $exit = Artisan::call('atlas:aver', [
            'action' => 'plan-from-verified-evolution',
            '--contract-json' => json_encode($contract, JSON_THROW_ON_ERROR),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, $payload['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_READY, $payload['status']);
        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::EXECUTION_CONTRACT_SCHEMA_VERSION, data_get($payload, 'execution_contract.source_verified_evolution_schema'));
    }
}
