<?php

namespace Tests\Feature\Ai\Rivals2;

use Tests\TestCase;

class AtlasRivals2CommandTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals2_cmd_test_'.uniqid();
        config()->set('atlas_rivals2.storage_root', $this->storage);
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
        $this->artisan('atlas:rivals2 doctor --json')
            ->expectsOutputToContain('"status": "ok"')
            ->assertExitCode(0);
    }

    public function test_full_fake_pipeline_via_command(): void
    {
        $this->artisan('atlas:rivals2 plan --json')->assertExitCode(0);
        $this->artisan('atlas:rivals2 run-fake --json')->assertExitCode(0);
        $this->artisan('atlas:rivals2 verify --json')->assertExitCode(0);
        $this->artisan('atlas:rivals2 adjudicate --json')
            ->expectsOutputToContain('"claim_allowed": true')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals2 report --json')
            ->expectsOutputToContain('"claim_allowed": true')
            ->assertExitCode(0);
        $this->artisan('atlas:rivals2 ledger --verify --json')->assertExitCode(0);
    }

    public function test_unknown_arm_fails_closed(): void
    {
        $this->artisan('atlas:rivals2 arms --arms=fantasy_model@bare --json')->assertExitCode(1);
    }

    public function test_verify_without_run_fails_closed(): void
    {
        $this->artisan('atlas:rivals2 adjudicate --json')->assertExitCode(1);
    }
}
