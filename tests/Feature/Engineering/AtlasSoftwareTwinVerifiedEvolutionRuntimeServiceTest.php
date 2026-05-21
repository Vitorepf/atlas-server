<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use App\Services\Engineering\AtlasSoftwareTwinVerifiedEvolutionCertificationService;
use App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAemorTables;
use Tests\Concerns\CreatesSoftwareTwinTables;
use Tests\TestCase;

final class AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest extends TestCase
{
    use CreatesAemorTables;
    use CreatesSoftwareTwinTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSoftwareTwinTables();
        $this->createAemorTables();
    }

    protected function tearDown(): void
    {
        $this->dropAemorTables();
        $this->dropSoftwareTwinTables();

        parent::tearDown();
    }

    public function test_software_twin_builds_read_only_living_system_twin(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->twin('app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('twin', $payload['action']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
        $this->assertSame('read_only_living_system_twin', data_get($payload, 'software_twin.mode'));
        $this->assertGreaterThanOrEqual(8, data_get($payload, 'software_twin.node_count'));
        $this->assertGreaterThanOrEqual(6, data_get($payload, 'software_twin.edge_count'));
        $this->assertSame('ready', data_get($payload, 'quality_score.status'));
        $this->assertSame('app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php', $payload['target_path']);
        $this->assertContains('operational_reality', array_keys(data_get($payload, 'software_twin.runtime_lenses')));
    }

    public function test_software_twin_impact_resolves_tests_docs_and_required_gates(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)
            ->impact('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::IMPACT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('impact', $payload['action']);
        $this->assertSame('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', $payload['target_path']);
        $this->assertContains($payload['classification'], ['active_runtime', 'active_read_only', 'headless_available']);
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', data_get($payload, 'impact.required_tests'));
        $this->assertContains('docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md', data_get($payload, 'impact.owner_docs'));
        $this->assertContains('php artisan atlas:aver:certify --json --strict', data_get($payload, 'impact.required_gates'));
    }

    public function test_software_twin_snapshot_persists_living_system_twin(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->snapshot('app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php');

        $this->assertSame(AtlasSoftwareTwinRuntimeService::SNAPSHOT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue($payload['writes']);
        $this->assertNotEmpty($payload['snapshot_id']);
        $this->assertNotEmpty($payload['snapshot_hash']);
        $this->assertDatabaseCount('atlas_software_twin_snapshots', 1);
    }

    public function test_context_envelope_is_provider_safe_and_names_do_not_claim(): void
    {
        $payload = app(AtlasSoftwareTwinRuntimeService::class)->contextEnvelope(
            'implementar AVEOR boundary contract',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $this->assertSame(AtlasSoftwareTwinRuntimeService::CONTEXT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue($payload['provider_safe']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md', $payload['minimal_sources']);
        $this->assertContains('docs/engineering-knowledge-base/atlas-verified-execution-runtime.md', $payload['minimal_sources']);
        $this->assertContains('safe_to_edit_without_boundary_contract', $payload['do_not_claim']);
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', $payload['required_tests']);
    }

    public function test_verified_evolution_boundary_contract_limits_mutation_and_requires_aver(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->boundaryContract(
            'implementar ASTR e AVEOR com runtime read-only',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::BOUNDARY_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('boundary-contract', $payload['action']);
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($payload, 'boundary_contract.allowed_write_paths'));
        $this->assertContains('unrelated_user_changes', data_get($payload, 'boundary_contract.do_not_touch_paths'));
        $this->assertFalse(data_get($payload, 'boundary_contract.mutation_policy.direct_mutation_authorized'));
        $this->assertTrue(data_get($payload, 'boundary_contract.mutation_policy.execution_must_go_through_aver'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_verified_evolution_proof_plan_requires_tests_gates_aver_and_aemor(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->proofPlan(
            'certificar runtime de software twin',
            'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::PROOF_PLAN_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', data_get($payload, 'proof_plan.required_tests'));
        $this->assertTrue(data_get($payload, 'proof_plan.aver_bridge.required'));
        $this->assertTrue(data_get($payload, 'proof_plan.aemor_bridge.required'));
        $this->assertContains('allowed_write_paths_respected', data_get($payload, 'proof_plan.completion_requires'));
        $this->assertContains('php artisan atlas:software-twin quality-score --json', data_get($payload, 'proof_plan.required_gates'));
    }

    public function test_verified_evolution_execution_contract_prepares_aver_plan_input(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->executionContract(
            'executar mudanca via AVER com prova',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::EXECUTION_CONTRACT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('ready_for_aver_plan', data_get($payload, 'execution_contract.status'));
        $this->assertSame('programming', data_get($payload, 'execution_contract.aver_plan_input.domain'));
        $this->assertSame('atlas_dev', data_get($payload, 'execution_contract.aver_plan_input.flow_id'));
        $this->assertContains('app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', data_get($payload, 'execution_contract.aver_plan_input.allowed_write_paths'));
        $this->assertContains('tests/Feature/Engineering/AtlasSoftwareTwinVerifiedEvolutionRuntimeServiceTest.php', data_get($payload, 'execution_contract.aver_plan_input.verification_plan.required_tests'));
        $this->assertContains('scope_drift_watch_required', data_get($payload, 'execution_contract.pre_execution_guards'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_verified_evolution_drift_watch_blocks_changed_files_outside_boundary(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->driftWatch(
            'editar apenas AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            [
                'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
                'app/Services/Ai/UnrelatedRuntime.php',
            ]
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::SCOPE_DRIFT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('app/Services/Ai/UnrelatedRuntime.php', data_get($payload, 'scope_drift_watch.outside_boundary_files'));
        $this->assertContains('changed_file_outside_boundary', array_column($payload['blockers'], 'reason'));
        $this->assertTrue(data_get($payload, 'scope_drift_watch.requires_human_review'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_verified_evolution_patch_simulation_predicts_blast_radius_and_required_gates(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->patchSimulation(
            'simular patch AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php']
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::PATCH_SIMULATION_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('proceed_to_aver_plan', data_get($payload, 'patch_simulation.recommendation'));
        $this->assertContains('git diff --check', data_get($payload, 'patch_simulation.must_run'));
        $this->assertSame(0, data_get($payload, 'patch_simulation.predicted_blast_radius.outside_boundary_count'));
    }

    public function test_verified_evolution_outcome_bridge_records_aemor_outcome_with_evidence(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->outcomeBridge(
            'fechar outcome AVEOR',
            'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php',
            'succeeded',
            ['test:aveor_outcome']
        );

        $this->assertSame(AtlasVerifiedEvolutionRuntimeService::OUTCOME_BRIDGE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('recorded', data_get($payload, 'outcome_bridge.status'));
        $this->assertTrue(data_get($payload, 'outcome_bridge.writes'));
        $this->assertDatabaseCount('atlas_aemor_execution_episodes', 1);
        $this->assertDatabaseCount('atlas_aemor_outcomes', 1);
    }

    public function test_verified_evolution_blocks_unknown_target_conservatively(): void
    {
        $payload = app(AtlasVerifiedEvolutionRuntimeService::class)->boundaryContract(
            'editar runtime desconhecido',
            'NoSuchAtlasTwinTarget'
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_not_found_for_boundary', array_column($payload['blockers'], 'reason'));
        $this->assertSame([], data_get($payload, 'boundary_contract.allowed_write_paths'));
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);
    }

    public function test_cli_actions_emit_canonical_json(): void
    {
        $softwareTwinCases = [
            ['twin', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'], AtlasSoftwareTwinRuntimeService::SCHEMA_VERSION],
            ['impact', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'], AtlasSoftwareTwinRuntimeService::IMPACT_SCHEMA_VERSION],
            ['context-envelope', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php', '--task' => 'implementar ASTR'], AtlasSoftwareTwinRuntimeService::CONTEXT_SCHEMA_VERSION],
            ['quality-score', [], AtlasSoftwareTwinRuntimeService::QUALITY_SCHEMA_VERSION],
            ['snapshot', ['--target' => 'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php'], AtlasSoftwareTwinRuntimeService::SNAPSHOT_SCHEMA_VERSION],
        ];

        foreach ($softwareTwinCases as [$action, $options, $schema]) {
            $exit = Artisan::call('atlas:software-twin', array_merge(['action' => $action, '--json' => true, '--strict' => true], $options));
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, 'software-twin '.$action);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertSame($action === 'snapshot', $payload['writes']);
        }

        $verifiedEvolutionCases = [
            ['intent-lock', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::SCHEMA_VERSION],
            ['boundary-contract', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::BOUNDARY_SCHEMA_VERSION],
            ['proof-plan', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::PROOF_PLAN_SCHEMA_VERSION],
            ['execution-contract', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::EXECUTION_CONTRACT_SCHEMA_VERSION],
            ['drift-watch', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', '--changed-file' => ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php']], AtlasVerifiedEvolutionRuntimeService::SCOPE_DRIFT_SCHEMA_VERSION],
            ['patch-simulation', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', '--changed-file' => ['app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php']], AtlasVerifiedEvolutionRuntimeService::PATCH_SIMULATION_SCHEMA_VERSION],
            ['outcome-bridge', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php', '--evidence' => ['test:cli_outcome']], AtlasVerifiedEvolutionRuntimeService::OUTCOME_BRIDGE_SCHEMA_VERSION],
            ['quality-score', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::QUALITY_SCHEMA_VERSION],
            ['evolution-envelope', ['--objective' => 'implementar AVEOR', '--target' => 'app/Services/Engineering/AtlasVerifiedEvolutionRuntimeService.php'], AtlasVerifiedEvolutionRuntimeService::SCHEMA_VERSION],
        ];

        foreach ($verifiedEvolutionCases as [$action, $options, $schema]) {
            $exit = Artisan::call('atlas:verified-evolution', array_merge(['action' => $action, '--json' => true, '--strict' => true], $options));
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, 'verified-evolution '.$action);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse((bool) ($payload['claim_policy']['authorizes_mutation'] ?? true));
        }
    }

    public function test_certification_service_and_command_are_ready(): void
    {
        $payload = app(AtlasSoftwareTwinVerifiedEvolutionCertificationService::class)->certify();

        $this->assertSame(AtlasSoftwareTwinVerifiedEvolutionCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(17, $payload['summary']['total']);
        $this->assertSame(17, $payload['summary']['passed']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['authorizes_mutation']);

        $exit = Artisan::call('atlas:software-twin-verified-evolution:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $cliPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSoftwareTwinVerifiedEvolutionCertificationService::SCHEMA_VERSION, $cliPayload['schema_version']);
        $this->assertSame('ready', $cliPayload['status']);
    }
}
