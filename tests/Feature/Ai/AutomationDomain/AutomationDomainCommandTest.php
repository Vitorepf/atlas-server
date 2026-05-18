<?php

namespace Tests\Feature\Ai\AutomationDomain;

use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainCommandTest extends TestCase
{
    use CreatesAutomationDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAutomationDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropAutomationDomainTables();
        parent::tearDown();
    }

    public function test_readiness_command_exit_code_is_zero(): void
    {
        $exit = $this->artisan('atlas:ai:automation-domain', ['--action' => 'readiness', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_smoke_command_exit_code_is_zero(): void
    {
        $exit = $this->artisan('atlas:ai:automation-domain', ['--action' => 'smoke', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_control_plane_command_exit_code_is_zero(): void
    {
        $exit = $this->artisan('atlas:ai:automation-domain', ['--action' => 'control-plane', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_invalid_action_returns_failure(): void
    {
        $exit = $this->artisan('atlas:ai:automation-domain', ['--action' => 'bogus', '--json' => true])->run();
        $this->assertSame(1, $exit);
    }
}
