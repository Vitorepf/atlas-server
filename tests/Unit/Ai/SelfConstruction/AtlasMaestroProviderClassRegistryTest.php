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

    public function test_every_provider_declares_supports_task_classes_autonomy_level_and_steady_state_allowed(): void
    {
        $providers = $this->registry()->providers();

        $this->assertArrayHasKey('atlas_native', $providers, 'atlas_native must be registered');

        foreach ($providers as $id => $provider) {
            $this->assertIsArray($provider['supports_task_classes'] ?? null, "$id must declare supports_task_classes");
            $this->assertNotEmpty($provider['supports_task_classes'], "$id supports_task_classes must not be empty");
            $this->assertIsString($provider['autonomy_level'] ?? null, "$id must declare autonomy_level");
            $this->assertNotSame('', $provider['autonomy_level'], "$id autonomy_level must not be empty");
            $this->assertIsBool($provider['steady_state_allowed'] ?? null, "$id must declare steady_state_allowed as bool");
        }
    }

    public function test_atlas_native_is_zero_cost_native_and_steady_state_allowed(): void
    {
        $providers = $this->registry()->providers();
        $atlas = $providers['atlas_native'];

        $this->assertSame('native', $atlas['autonomy_level']);
        $this->assertSame('zero', $atlas['cost_band']);
        $this->assertTrue($atlas['steady_state_allowed']);
        $this->assertContains('*', $atlas['supports_task_classes']);
    }

    public function test_well_formed_assertion_rejects_registry_without_atlas_native(): void
    {
        $ref = new \ReflectionClass(AtlasMaestroProviderClassRegistry::class);
        $method = $ref->getMethod('assertWellFormed');
        $method->setAccessible(true);

        $this->expectException(\LogicException::class);
        $method->invoke(new AtlasMaestroProviderClassRegistry, []); // empty registry → no atlas_native
    }

    private function registry(): AtlasMaestroProviderClassRegistry
    {
        return new AtlasMaestroProviderClassRegistry;
    }
}
