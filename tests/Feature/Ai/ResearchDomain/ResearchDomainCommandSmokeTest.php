<?php

namespace Tests\Feature\Ai\ResearchDomain;

use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchDomainCommandSmokeTest extends TestCase
{
    use CreatesResearchDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createResearchDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropResearchDomainTables();
        parent::tearDown();
    }

    public function test_readiness_command_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:research-domain', ['--action' => 'readiness', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_smoke_command_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:research-domain', ['--action' => 'smoke', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_control_plane_command_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:research-domain', ['--action' => 'control-plane', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }
}
