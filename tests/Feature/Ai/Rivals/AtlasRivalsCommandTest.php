<?php

namespace Tests\Feature\Ai\Rivals;

use Tests\TestCase;

class AtlasRivalsCommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_cmd_test_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
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

    public function test_full_fake_pipeline_via_command(): void
    {
        $this->artisan('atlas:rivals plan --json')->assertExitCode(0);
        $this->artisan('atlas:rivals run-fake --json')->assertExitCode(0);
        $this->artisan('atlas:rivals verify --json')->assertExitCode(0);
        $this->artisan('atlas:rivals adjudicate --json')
            ->expectsOutputToContain('"claim_allowed": true')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals report --json')
            ->expectsOutputToContain('"claim_allowed": true')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals ledger --verify --json')->assertExitCode(0);
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
}
