<?php

namespace Tests\Feature\Ai\MarketingDomain;

use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingDomainCommandSmokeTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_readiness_command(): void
    {
        $exit = $this->artisan('atlas:ai:marketing-domain', ['--action' => 'readiness', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_smoke_command(): void
    {
        $exit = $this->artisan('atlas:ai:marketing-domain', ['--action' => 'smoke', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_control_plane_command(): void
    {
        $exit = $this->artisan('atlas:ai:marketing-domain', ['--action' => 'control-plane', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_limited_autonomy_policy_command(): void
    {
        $exit = $this->artisan('atlas:ai:marketing-domain', ['--action' => 'limited-autonomy-policy', '--json' => true])->run();

        $this->assertSame(0, $exit);
    }
}
