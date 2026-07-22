<?php

namespace Tests\Feature\Ai\VerifiedExecution;

use App\Models\AtlasAverCommandLedger;
use App\Models\AtlasAverTestLedger;
use App\Services\Ai\AutonomousWorkExecution\AtlasAutonomousWorkExecutionService;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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

    /**
     * F0 characterization: every public AVER API except executeFixtureCycle()
     * is exercised through a safe path. The fixture cycle is deliberately
     * excluded: it creates a temporary workspace and starts a child process.
     */
    public function test_f0_executes_the_nine_safe_public_aver_apis_without_fixture_cycle(): void
    {
        $runtime = app(AtlasVerifiedExecutionRuntimeService::class);

        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new \ReflectionClass($runtime))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => ! $method->isConstructor(),
            ),
        );
        sort($methods);
        $this->assertSame([
            'certify',
            'claimPolicy',
            'controlPlane',
            'executeFixtureCycle',
            'plan',
            'planFromVerifiedEvolutionContract',
            'repair',
            'runCommand',
            'runTest',
            'verifyDiff',
        ], $methods, 'Static inventory remains paired with the executable safe characterization.');

        $plan = $runtime->plan([
            'objective' => 'F0 safe characterization plan',
            'evidence_refs' => ['test:runtime-execution-f0'],
        ]);
        $fromInvalidContract = $runtime->planFromVerifiedEvolutionContract([
            'schema_version' => 'invalid.schema',
            'status' => 'blocked',
        ]);
        $blockedCommand = $runtime->runCommand([
            'execution_id' => $plan['execution_id'],
            'command' => 'rm -rf /',
        ]);
        $blockedDiff = $runtime->verifyDiff([
            'execution_id' => $plan['execution_id'],
            'changed_files' => ['app/F0.php'],
            'tests' => ['php artisan test --filter=F0'],
        ]);
        $blockedTest = $runtime->runTest([
            'execution_id' => $plan['execution_id'],
            'command' => 'rm -rf /',
        ]);
        $repair = $runtime->repair([
            'execution_id' => $plan['execution_id'],
            'failure_packet' => ['failure_type' => 'characterization_only'],
        ]);
        $missingCertification = $runtime->certify(['execution_id' => null]);
        $controlPlane = $runtime->controlPlane();
        $claimPolicy = $runtime->claimPolicy();

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::EXECUTION_SCHEMA, $plan['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $fromInvalidContract['status']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $blockedCommand['status']);
        $this->assertNull($blockedCommand['exit_code'], 'The command safety gate blocks before Process execution.');
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $blockedDiff['status']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $blockedTest['status']);
        $this->assertNull($blockedTest['command_ledger']['exit_code'], 'runTest inherits the blocked no-process command path.');
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::REPAIR_CYCLE_SCHEMA, $repair['schema_version']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_BLOCKED, $missingCertification['status']);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::CONTROL_PLANE_SCHEMA, $controlPlane['schema_version']);
        $this->assertTrue($claimPolicy['external_side_effects_blocked_by_default']);
    }

    public function test_f0_characterizes_uncorrelatable_legacy_aver_certification_and_real_diff_hash_contract(): void
    {
        $runtime = app(AtlasVerifiedExecutionRuntimeService::class);
        $rawDiff = "--- a/app/F0.php\n+++ b/app/F0.php\n@@\n-old\n+new\n";

        $plan = $runtime->plan([
            'objective' => 'F0 current-ledger contradiction',
            'evidence_refs' => ['test:runtime-execution-f0'],
        ]);
        $this->assertFalse(
            Schema::hasColumn('atlas_aver_executions', 'goal_record_id'),
            'F4a must add this correlation column before AVER and RealExecution rows can be related.',
        );
        $executionId = (string) $plan['execution_id'];
        $diff = $runtime->verifyDiff([
            'execution_id' => $executionId,
            'changed_files' => ['app/F0.php'],
            'action_manifests' => [[
                'schema_version' => 'atlas.programming.action_manifest.v1',
                'manifest_id' => 'f0-safe-diff-manifest',
                'stage' => 'patch',
                'dry_run' => false,
                'gate_effect' => 'passed',
                'changed_files' => ['app/F0.php'],
                'rollback' => ['available' => true],
            ]],
            'tests' => ['php artisan test --filter=F0'],
            'evidence_refs' => ['test:f0-diff'],
        ]);

        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_PASSED, $diff['status']);
        $realExecutionKernel = file_get_contents(base_path('app/Services/Ai/RealExecution/AtlasRealEngineeringExecutionKernelService.php'));
        $this->assertIsString($realExecutionKernel);
        $this->assertStringContainsString(
            "'diff_hash' => hash('sha256', \$diff)",
            $realExecutionKernel,
            'This characterization is bound to the real producer contract without running its mutative kernel.',
        );
        $realExecutionRawDiffHash = hash('sha256', $rawDiff);
        $this->assertNotSame(
            $realExecutionRawDiffHash,
            $diff['diff_hash'],
            'AVER diff_hash is the canonical payload hash; RealExecution hashes the raw diff string.',
        );

        $command = AtlasAverCommandLedger::query()->create([
            'execution_id' => $executionId,
            'schema_version' => AtlasVerifiedExecutionRuntimeService::COMMAND_LEDGER_SCHEMA,
            'status' => AtlasVerifiedExecutionRuntimeService::STATUS_PASSED,
            'command_hash' => hash('sha256', 'F0 no-process command receipt'),
            'cwd_hash' => hash('sha256', base_path()),
            'exit_code' => 0,
            'duration_ms' => 0,
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'safety_gate' => ['status' => 'passed', 'blockers' => []],
            'evidence_refs' => ['test:f0-command-receipt'],
            'ledger_hash' => hash('sha256', 'F0 no-process command ledger'),
        ]);
        AtlasAverTestLedger::query()->create([
            'execution_id' => $executionId,
            'command_ledger_id' => $command->id,
            'schema_version' => AtlasVerifiedExecutionRuntimeService::TEST_LEDGER_SCHEMA,
            'status' => AtlasVerifiedExecutionRuntimeService::STATUS_PASSED,
            'test_command_hash' => $command->command_hash,
            'exit_code' => 0,
            'test_impact' => ['schema_version' => 'atlas.aver.test_impact.v1', 'passed' => true],
            'evidence_refs' => ['test:f0-test-receipt'],
            'test_hash' => hash('sha256', 'F0 no-process test ledger'),
        ]);
        $legacyCertification = $runtime->certify([
            'execution_id' => $executionId,
            'evidence_refs' => ['test:f0-legacy-certification'],
        ]);
        $this->assertSame(AtlasVerifiedExecutionRuntimeService::STATUS_CERTIFIED, $legacyCertification['status']);
        $this->assertDatabaseHas('atlas_aver_certified_executions', ['execution_id' => $executionId]);
        $this->assertDatabaseHas('atlas_aver_executions', ['id' => $executionId]);
    }
}
