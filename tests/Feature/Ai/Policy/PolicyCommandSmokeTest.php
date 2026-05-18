<?php

namespace Tests\Feature\Ai\Policy;

use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class PolicyCommandSmokeTest extends TestCase
{
    use CreatesPolicySafetyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolicySafetyTables();
    }

    protected function tearDown(): void
    {
        $this->dropPolicySafetyTables();
        parent::tearDown();
    }

    public function test_readiness_command_fails_before_seed(): void
    {
        $exit = $this->artisan('atlas:ai:policy', ['--action' => 'readiness', '--json' => true])->run();
        $this->assertSame(1, $exit, 'readiness should fail before defaults are seeded');
    }

    public function test_seed_defaults_command_succeeds(): void
    {
        $exit = $this->artisan('atlas:ai:policy', ['--action' => 'seed-defaults', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_readiness_passes_after_seed(): void
    {
        $this->artisan('atlas:ai:policy', ['--action' => 'seed-defaults', '--json' => true])->run();
        $exit = $this->artisan('atlas:ai:policy', ['--action' => 'readiness', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_control_plane_command_succeeds(): void
    {
        $this->artisan('atlas:ai:policy', ['--action' => 'seed-defaults', '--json' => true])->run();
        $exit = $this->artisan('atlas:ai:policy', ['--action' => 'control-plane', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_evaluate_command_returns_zero_for_allow(): void
    {
        $this->artisan('atlas:ai:policy', ['--action' => 'seed-defaults', '--json' => true])->run();
        $exit = $this->artisan('atlas:ai:policy', [
            '--action' => 'evaluate',
            '--action-key' => 'programming.read',
            '--domain-id' => 'programming',
            '--risk-level' => 'low',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }
}
