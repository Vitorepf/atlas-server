<?php

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\FaseABatteryOrchestrator;
use App\Services\Ai\Rivals\Core\SuiteRegistry;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FaseABatteryOrchestratorTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_fase_a_battery_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_dry_run_bare_lists_ten_suites_with_case_packs(): void
    {
        $payload = (new FaseABatteryOrchestrator)->dryRun('bare');
        $this->assertSame('atlas.rivals2.fase_a_battery_dry_run.v1', $payload['schema_version']);
        $this->assertSame(10, $payload['suite_count']);
        $this->assertFalse($payload['execute_allowed_here']);
        $this->assertSame('verboo_kimi_k2_7', $payload['primary_model']);
        $this->assertCount(10, $payload['plans']);
        $this->assertSame((new SuiteRegistry)->externalSuiteIds(), array_column($payload['plans'], 'suite_id'));
        foreach ($payload['plans'] as $plan) {
            $this->assertGreaterThanOrEqual(3, $plan['case_count'], $plan['suite_id']);
            $this->assertSame(['verboo_kimi_k2_7@bare'], $plan['arms']);
        }
    }

    public function test_dry_run_uplift_uses_five_family_suites_and_dual_arms(): void
    {
        $payload = (new FaseABatteryOrchestrator)->dryRun('uplift');
        $this->assertSame(5, $payload['suite_count']);
        foreach ($payload['plans'] as $plan) {
            $this->assertSame(
                ['verboo_kimi_k2_7@bare', 'verboo_kimi_k2_7@atlas_dev'],
                $plan['arms'],
            );
        }
    }

    public function test_dry_run_fast_is_one_case_one_rep_ten_suites(): void
    {
        $payload = (new FaseABatteryOrchestrator)->dryRun('bare', true);
        $this->assertTrue($payload['fast']);
        $this->assertSame('fast', $payload['profile']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertSame(10, $payload['suite_count']);
        foreach ($payload['plans'] as $plan) {
            $this->assertSame(1, $plan['case_count'], $plan['suite_id']);
            $this->assertSame(1, $plan['repetitions'], $plan['suite_id']);
            $this->assertSame(1, $plan['units_expected'], $plan['suite_id']);
        }
    }

    public function test_battery_cli_dry_run_action(): void
    {
        config()->set('atlas_rivals.enabled', false);
        $exit = $this->withoutMockingConsoleOutput()
            ->artisan('atlas:rivals', [
                'action' => 'battery',
                '--mode' => 'bare',
                '--json' => true,
            ]);
        $this->assertSame(0, $exit);
    }

    public function test_prepare_persists_real_plans_and_manifests(): void
    {
        config()->set('atlas_rivals.enabled', true);
        config()->set('atlas_rivals.provider_spend_allowed', true);
        $payload = (new FaseABatteryOrchestrator)->prepare('bare', true);
        $this->assertSame('atlas.rivals2.fase_a_battery_prepare.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status'], json_encode($payload['errors'] ?? []));
        $this->assertSame([], $payload['errors']);
        $this->assertCount(10, $payload['prepared']);
        foreach ($payload['prepared'] as $row) {
            $this->assertNotEmpty($row['run_id'], $row['suite_id']);
            $this->assertSame('done', $row['import_cases']['status']);
            $this->assertSame('done', $row['plan']['status']);
            $this->assertFileExists(RunPaths::planPath($row['run_id']));
            $this->assertFileExists(RunPaths::nativeManifestPath($row['run_id']));
            $this->assertSame('ok', $row['preflight']['status'], $row['suite_id']);
        }
    }

    public function test_prepare_fails_closed_without_approve(): void
    {
        config()->set('atlas_rivals.enabled', true);
        config()->set('atlas_rivals.provider_spend_allowed', true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rivals_battery_prepare_requires_approve_provider_spend');
        (new FaseABatteryOrchestrator)->prepare('bare', false);
    }

    public function test_prepare_kind_uplift_emits_five_dual_arm_steps(): void
    {
        config()->set('atlas_rivals.enabled', true);
        config()->set('atlas_rivals.provider_spend_allowed', true);
        $payload = (new FaseABatteryOrchestrator)->prepare('uplift', true);
        $this->assertSame('uplift', $payload['mode']);
        $this->assertSame('ok', $payload['status'], json_encode($payload['errors'] ?? []));
        $this->assertCount(5, $payload['prepared']);
        $this->assertStringContainsString('atlas_dev', $payload['prepared'][0]['plan']['arms']);
        $this->assertFileExists(RunPaths::nativeManifestPath($payload['prepared'][0]['run_id']));
        $this->assertGreaterThan(0, $payload['prepared'][0]['budget_usd_cap']);
        $this->assertTrue($payload['prepared'][0]['preflight']['checks']['hermes_arms_only']);
        $this->assertTrue($payload['prepared'][0]['preflight']['checks']['provider_spend_approved']);

        $signature = (new \ReflectionClass(\App\Console\Commands\AtlasRivalsCommand::class))
            ->getProperty('signature')
            ->getValue(new \App\Console\Commands\AtlasRivalsCommand);
        $this->assertStringContainsString('{--kind=bare', (string) $signature);
    }

    public function test_model_matrix_mode_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rivals_battery_model_matrix_not_supported');
        (new FaseABatteryOrchestrator)->dryRun('model_matrix');
    }

    public function test_case_pack_too_small_fails_closed(): void
    {
        config()->set('atlas_rivals.fase_a.case_packs.tau2_bench', ['only_one']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rivals_battery_case_pack_too_small:tau2_bench');
        (new FaseABatteryOrchestrator)->dryRun('bare');
    }

    public function test_prepare_cli_requires_rivals_enabled(): void
    {
        config()->set('atlas_rivals.enabled', false);
        $this->artisan('atlas:rivals', [
            'action' => 'battery',
            '--mode' => 'prepare',
            '--kind' => 'bare',
            '--approve-provider-spend' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('atlas_rivals_disabled')
            ->assertExitCode(1);
    }

    public function test_execute_blocked_off_mac_without_allow(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('mac_only gate is Darwin pass-through; Linux CI covers the block');
        }

        config()->set('atlas_rivals.enabled', true);
        config()->set('atlas_rivals.provider_spend_allowed', true);
        config()->set('atlas_rivals.fase_a.allow_execute', false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rivals_battery_execute_mac_only');
        (new FaseABatteryOrchestrator)->execute('bare', true, false);
    }

    public function test_execute_dry_run_prepares_and_lists_units_without_spend(): void
    {
        config()->set('atlas_rivals.enabled', true);
        config()->set('atlas_rivals.provider_spend_allowed', true);
        // Fake smoke running so prepare path is unchanged; dry-run skips smoke gate.
        $payload = (new FaseABatteryOrchestrator)->execute('bare', true, true);
        $this->assertSame('atlas.rivals2.fase_a_battery_execute.v1', $payload['schema_version']);
        $this->assertTrue($payload['units_dry_run']);
        $this->assertSame('ok', $payload['status'], json_encode($payload['errors'] ?? []));
        $this->assertCount(10, $payload['suite_results']);
        $this->assertSame('dry_run', $payload['suite_results'][0]['status']);
        $this->assertNotEmpty($payload['suite_results'][0]['units']);
        $this->assertStringContainsString(
            'rivals-native-runner.php',
            implode(' ', $payload['suite_results'][0]['units'][0]['argv']),
        );
        $this->assertNull($payload['enterprise_report']);
    }
}
