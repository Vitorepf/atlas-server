<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroProviderClassRegistry;
use Tests\TestCase;

final class AtlasMaestroProviderClassRegistryTest extends TestCase
{
    public function test_providers_declare_axes_and_cost_bands(): void
    {
        $providers = $this->registry()->providers();

        $this->assertGreaterThanOrEqual(4, count($providers));
        $this->assertArrayHasKey('minimax-m3', $providers);
        $this->assertArrayHasKey('codex-gpt-5-5', $providers);

        foreach ($providers as $provider) {
            $this->assertSame(AtlasMaestroProviderClassRegistry::AXES, array_keys($provider['axes']));
            foreach ($provider['axes'] as $value) {
                $this->assertIsInt($value);
                $this->assertGreaterThanOrEqual(0, $value);
                $this->assertLessThanOrEqual(100, $value);
            }
            $this->assertNotSame('', $provider['cost_band']);
            $this->assertSame('loop-provider-minimax-impl-codex-hard', $provider['memory_anchor']);
        }
    }

    public function test_minimax_is_grind_throughput_provider_and_codex_is_hard_architecture_provider(): void
    {
        $registry = $this->registry();

        $this->assertGreaterThan(
            $registry->axesFor('codex-gpt-5-5')['grind-throughput'],
            $registry->axesFor('minimax-m3')['grind-throughput'],
        );
        $this->assertGreaterThan(
            $registry->axesFor('minimax-m3')['architecture-depth'],
            $registry->axesFor('codex-gpt-5-5')['architecture-depth'],
        );
        $this->assertSame('cheap', $registry->costBandFor('minimax-m3'));
        $this->assertSame('expensive', $registry->costBandFor('codex-gpt-5-5'));
    }

    private function registry(): AtlasMaestroProviderClassRegistry
    {
        return new AtlasMaestroProviderClassRegistry;
    }
}
