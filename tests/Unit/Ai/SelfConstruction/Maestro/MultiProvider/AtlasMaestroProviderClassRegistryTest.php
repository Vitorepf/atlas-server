<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\MultiProvider;

use App\Services\Ai\SelfConstruction\Maestro\MultiProvider\AtlasMaestroProviderClassRegistry;
use Tests\TestCase;

final class AtlasMaestroProviderClassRegistryTest extends TestCase
{
    private function svc(): AtlasMaestroProviderClassRegistry
    {
        return new AtlasMaestroProviderClassRegistry;
    }

    // ── AC2: capabilities, cost band, proof strength, quota style, fallback class, autonomy risk ──

    public function test_every_provider_declares_proof_strength_quota_style_fallback_class_and_autonomy_risk(): void
    {
        foreach ($this->svc()->providers() as $providerId => $provider) {
            $this->assertIsInt($provider['proof_strength'], "provider {$providerId} proof_strength must be int");
            $this->assertGreaterThanOrEqual(0, $provider['proof_strength']);
            $this->assertLessThanOrEqual(100, $provider['proof_strength']);
            $this->assertNotSame('', $provider['quota_style'], "provider {$providerId} quota_style must be set");
            $this->assertNotSame('', $provider['fallback_class'], "provider {$providerId} fallback_class must be set");
            $this->assertNotSame('', $provider['autonomy_dependency_risk'], "provider {$providerId} autonomy_dependency_risk must be set");
            $this->assertNotEmpty($provider['axes'], "provider {$providerId} capability axes must be set");
            $this->assertNotSame('', $provider['cost_band'], "provider {$providerId} cost_band must be set");
        }
    }

    // ── AC3: distinguishes local Atlas-native, subscription external and API-metered classes ──

    public function test_provider_classes_distinguish_local_native_subscription_external_and_api_metered(): void
    {
        $providers = $this->svc()->providers();

        $this->assertSame(AtlasMaestroProviderClassRegistry::PROVIDER_CLASS_LOCAL_NATIVE, $providers['atlas_native']['provider_class']);
        $this->assertSame(AtlasMaestroProviderClassRegistry::PROVIDER_CLASS_SUBSCRIPTION_EXTERNAL, $providers['minimax-m3']['provider_class']);
        $this->assertSame(AtlasMaestroProviderClassRegistry::PROVIDER_CLASS_SUBSCRIPTION_EXTERNAL, $providers['glm-5-2']['provider_class']);
        $this->assertSame(AtlasMaestroProviderClassRegistry::PROVIDER_CLASS_API_METERED, $providers['codex-gpt-5-5']['provider_class']);
        $this->assertSame(AtlasMaestroProviderClassRegistry::PROVIDER_CLASS_API_METERED, $providers['claude-opus']['provider_class']);
    }

    public function test_provider_classes_never_embed_secrets(): void
    {
        foreach ($this->svc()->providers() as $provider) {
            foreach ($provider as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/sk-|api[_-]?key|secret|token=/i', $value, "field {$key} must not embed secret-shaped values");
            }
        }
    }

    // ── AC4: unknown providers get a safe degraded contract ──

    public function test_unknown_provider_returns_safe_degraded_contract(): void
    {
        $contract = $this->svc()->contractFor('some-unregistered-provider');

        $this->assertSame('some-unregistered-provider', $contract['provider_id']);
        $this->assertSame(AtlasMaestroProviderClassRegistry::PROVIDER_CLASS_UNKNOWN, $contract['provider_class']);
        $this->assertFalse($contract['steady_state_allowed']);
        $this->assertSame('atlas_native', $contract['fallback_class']);
        $this->assertSame([], $contract['supports_task_classes']);
        $this->assertTrue($contract['degraded']);
    }

    public function test_known_provider_contract_matches_registry_row(): void
    {
        $contract = $this->svc()->contractFor('minimax-m3');

        $this->assertSame($this->svc()->providers()['minimax-m3'], $contract);
        $this->assertArrayNotHasKey('degraded', $contract);
    }

    public function test_degraded_contract_is_deterministic(): void
    {
        $a = $this->svc()->contractFor('ghost-provider');
        $b = $this->svc()->contractFor('ghost-provider');

        $this->assertSame($a, $b);
    }

    // ── existing well-formedness still holds with the new fields ──

    public function test_well_formed_registry_still_includes_atlas_native_as_zero_cost_native(): void
    {
        $providers = $this->svc()->providers();

        $this->assertSame('zero', $providers['atlas_native']['cost_band']);
        $this->assertSame('none', $providers['atlas_native']['fallback_class']);
        $this->assertSame('none', $providers['atlas_native']['autonomy_dependency_risk']);
    }
}
