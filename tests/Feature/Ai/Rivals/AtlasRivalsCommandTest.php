<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\CampaignManifest;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use Tests\TestCase;

class AtlasRivalsCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_cmd_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
        config()->set('atlas_rivals.enabled', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            exec('rm -rf '.escapeshellarg($this->storage));
        }
        parent::tearDown();
    }

    public function test_doctor_reports_ok_json(): void
    {
        $this->artisan('atlas:rivals doctor --json')
            ->expectsOutputToContain('"status": "ok"')
            ->assertExitCode(0);
    }

    public function test_world_readiness_is_read_only_and_exposes_missing_real_provenance(): void
    {
        $manifestPath = (new CampaignManifest)->persist('world-readiness-test', [
            'schema_version' => 'atlas.rivals2.campaign_manifest.v1',
            'mode' => 'dev',
            'required_exposure' => 1,
            'required_outcome_days' => 30,
            'campaigns' => [
                ['id' => 'a', 'distinct_units' => 1, 'power' => 0.95, 'outcome_days' => 30, 'synthetic' => true, 'contamination_free' => true, 'itt_complete' => true, 'critical_dimensions' => []],
                ['id' => 'b', 'distinct_units' => 1, 'power' => 0.95, 'outcome_days' => 30, 'synthetic' => true, 'contamination_free' => true, 'itt_complete' => true, 'critical_dimensions' => []],
                ['id' => 'c', 'distinct_units' => 1, 'power' => 0.95, 'outcome_days' => 30, 'synthetic' => true, 'contamination_free' => true, 'itt_complete' => true, 'critical_dimensions' => []],
            ],
        ]);

        $this->artisan('atlas:rivals world-readiness --file='.$manifestPath.' --json')
            ->expectsOutputToContain('"status": "blocked"')
            ->assertExitCode(0);
    }

    public function test_full_fake_pipeline_via_command(): void
    {
        $this->artisan('atlas:rivals plan --json')->assertExitCode(0);
        $this->artisan('atlas:rivals run-fake --json')->assertExitCode(0);
        $this->artisan('atlas:rivals verify --json')->assertExitCode(0);
        $this->artisan('atlas:rivals adjudicate --json')
            ->expectsOutputToContain('"internal_claim_allowed": false')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals report --json')
            ->expectsOutputToContain('"claim_allowed": false')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals bundle --json')
            ->expectsOutputToContain('"verified": true')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals verify-bundle --json')
            ->expectsOutputToContain('"verified": true')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals ledger --verify --json')->assertExitCode(0);
        $this->artisan('atlas:rivals ledger --verify --semantic --json')
            ->expectsOutputToContain('"mode": "semantic"')
            ->assertExitCode(0);
    }

    public function test_run_canonico_despacha_pela_suite_do_plano(): void
    {
        $this->artisan('atlas:rivals plan --json')->assertExitCode(0);
        // plano é local_fake → run despacha para a execução fake
        $this->artisan('atlas:rivals run --json')
            ->expectsOutputToContain('atlas.rivals2.run_fake.v1')
            ->assertExitCode(0);
    }

    public function test_alias_temporario_atlas_rivals2_continua_funcionando(): void
    {
        $this->artisan('atlas:rivals2 doctor --json')
            ->expectsOutputToContain('"product": "Rivals"')
            ->assertExitCode(0);
    }

    public function test_unknown_arm_fails_closed(): void
    {
        $this->artisan('atlas:rivals arms --arms=fantasy_model@bare --json')->assertExitCode(1);
    }

    public function test_verify_without_run_fails_closed(): void
    {
        $this->artisan('atlas:rivals adjudicate --json')->assertExitCode(1);
    }

    public function test_doctor_exposes_ten_suite_catalog(): void
    {
        $registry = new SuiteRegistry;
        $registry->assertComplete();
        $ids = array_column($registry->catalog(), 'suite_id');
        $this->assertCount(10, $ids);
        $this->assertContains('tau2_bench', $ids);
        $this->assertContains('bfcl', $ids);
        $this->assertContains('swe_marathon', $ids);

        $this->artisan('atlas:rivals doctor --json')
            ->expectsOutputToContain('"suite_registry_complete": true')
            ->assertExitCode(0);
    }

    public function test_report_enterprise_is_read_only_and_emits_ten_suite_rows(): void
    {
        config()->set('atlas_rivals.enabled', false);
        $exit = $this->withoutMockingConsoleOutput()
            ->artisan('atlas:rivals', ['action' => 'report-enterprise', '--json' => true]);
        $this->assertSame(0, $exit);
        $this->assertFileExists(RunPaths::enterpriseReportPath());
        $this->assertFileExists(RunPaths::enterpriseMarkdownPath());
        $this->assertFileExists(RunPaths::enterpriseCsvPath());
        $payload = json_decode((string) file_get_contents(RunPaths::enterpriseReportPath()), true);
        $this->assertSame('atlas.rivals2.enterprise_report.v1', $payload['schema_version']);
        $this->assertFalse($payload['claim_allowed']);
        $this->assertCount(10, $payload['suite_rows']);
    }

    public function test_legacy_suite_alias_rejected_for_new_plans(): void
    {
        $this->artisan('atlas:rivals plan --suite=tau2_bfcl --json')
            ->expectsOutputToContain('legacy_alias_forbidden_for_new_plans')
            ->assertExitCode(1);
    }

    public function test_disabled_flag_blocks_mutations(): void
    {
        config()->set('atlas_rivals.enabled', false);
        $this->artisan('atlas:rivals plan --json')
            ->expectsOutputToContain('atlas_rivals_disabled')
            ->assertExitCode(1);
        $this->artisan('atlas:rivals doctor --json')->assertExitCode(0);
    }

    public function test_help_lists_import_actions(): void
    {
        $this->artisan('atlas:rivals --help')
            ->expectsOutputToContain('import-cases')
            ->expectsOutputToContain('import-results')
            ->assertExitCode(0);
    }

    public function test_external_plan_persists_native_manifest_with_unique_unit_outputs(): void
    {
        $cases = RunPaths::root().'/external/tau2_bench/cases';
        RunPaths::ensureDir($cases);
        file_put_contents($cases.'/airline_task_012.json', json_encode([
            'case_id' => 'airline_task_012',
            'task_type' => 'tool_use_function_calling',
            'title' => 'Airline task',
            'source_repo' => 'tau2_bench',
        ]));

        $this->artisan(
            'atlas:rivals plan --suite=tau2_bench --arms=claude_sonnet_5@bare --repetitions=2 --json'
        )
            ->expectsOutputToContain('"native_manifest_hash":')
            ->assertExitCode(0);

        $runId = RunPaths::latestRunId();
        $this->assertNotNull($runId);
        $this->assertFileExists(RunPaths::nativeManifestPath($runId));
        $this->assertFileExists(RunPaths::preregistrationPath($runId));
        $manifest = json_decode(file_get_contents(RunPaths::nativeManifestPath($runId)), true);
        $this->assertSame(2, $manifest['expected_executions']);
        $this->assertCount(2, array_unique(array_column($manifest['entries'], 'expected_result_path')));
        $this->assertStringStartsWith(
            'external_results/units/',
            $manifest['entries'][0]['expected_result_path'],
        );
    }

    public function test_plan_case_selector_is_exact_and_rejects_unknown_cases(): void
    {
        $cases = RunPaths::root().'/external/tau2_bench/cases';
        RunPaths::ensureDir($cases);
        foreach (['airline_task_012', 'airline_task_013'] as $caseId) {
            file_put_contents($cases.'/'.$caseId.'.json', json_encode([
                'case_id' => $caseId,
                'native_task_id' => str_ends_with($caseId, '012') ? '12' : '13',
                'domain' => 'airline',
                'task_type' => 'tool_use_function_calling',
                'title' => $caseId,
                'source_repo' => 'tau2_bench',
            ]));
        }

        $this->artisan(
            'atlas:rivals plan --suite=tau2_bench --cases=airline_task_013 --arms=claude_sonnet_5@bare --repetitions=2 --json'
        )->assertExitCode(0);
        $manifest = json_decode(
            file_get_contents(RunPaths::nativeManifestPath((string) RunPaths::latestRunId())),
            true,
        );
        $this->assertSame(2, $manifest['expected_executions']);
        $this->assertSame(
            ['airline_task_013'],
            array_values(array_unique(array_column($manifest['entries'], 'case_id'))),
        );

        $this->artisan('atlas:rivals plan --suite=tau2_bench --cases=missing --json')
            ->expectsOutputToContain('unknown_cases:missing')
            ->assertExitCode(1);
    }

    public function test_state_gate_blocks_adjudication_before_verify(): void
    {
        $this->artisan('atlas:rivals plan --json')->assertExitCode(0);
        $this->artisan('atlas:rivals adjudicate --json')
            ->expectsOutputToContain('rivals_run_state_required:verified')
            ->assertExitCode(1);
    }

    public function test_status_resume_and_cancel_are_explicit(): void
    {
        $this->artisan('atlas:rivals plan --json')->assertExitCode(0);
        $this->artisan('atlas:rivals status --json')
            ->expectsOutputToContain('"resume_action": "native_execution"')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals resume --json')
            ->expectsOutputToContain('"next_action": "native_execution"')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals cancel --reason="operator stop" --json')
            ->expectsOutputToContain('"state": "cancelled"')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals resume --json')
            ->expectsOutputToContain('"next_action": "operator_intervention"')
            ->assertExitCode(1);
    }
}
