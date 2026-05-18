<?php

namespace Tests\Feature\Ai\RouterRuntime;

use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class RouterRuntimeCommandSmokeTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_readiness_action_returns_zero_when_schema_present(): void
    {
        $exit = $this->artisan('atlas:ai:router-runtime', ['--action' => 'readiness', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_classify_action_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:router-runtime', [
            '--action' => 'classify',
            '--input' => 'implemente exporter csv com testes',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }

    public function test_route_action_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:router-runtime', [
            '--action' => 'route',
            '--input' => 'pesquise as fontes mais confiaveis sobre agentes',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }

    public function test_dispatch_action_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:router-runtime', [
            '--action' => 'dispatch',
            '--input' => 'explique em uma frase o que e o atlas',
            '--json' => true,
        ])->run();
        $this->assertSame(0, $exit);
    }

    public function test_smoke_action_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:router-runtime', ['--action' => 'smoke', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }

    public function test_control_plane_action_returns_zero(): void
    {
        $exit = $this->artisan('atlas:ai:router-runtime', ['--action' => 'control-plane', '--json' => true])->run();
        $this->assertSame(0, $exit);
    }
}
