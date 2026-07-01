<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolIndependenceGate;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolIndependenceGateTest extends TestCase
{
    private function svc(): AtlasExternalBrainProviderPoolIndependenceGate
    {
        return new AtlasExternalBrainProviderPoolIndependenceGate;
    }

    private function classificationOf(array $result, string $provider): string
    {
        foreach ($result['pool_classification'] as $row) {
            if ($row['provider'] === $provider) {
                return $row['classification'];
            }
        }

        return '';
    }

    // ── AC2: classifies pools as native, acceleration_only, risky_dependency, prohibited_dependency ──

    public function test_native_provider_classified_native(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'atlas_native', 'is_atlas_native' => true],
            ],
        ]);

        $this->assertSame('native', $this->classificationOf($result, 'atlas_native'));
    }

    public function test_provider_with_fallback_not_required_classified_acceleration_only(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'claude', 'has_local_fallback' => true, 'required_for_steady_state' => false],
            ],
        ]);

        $this->assertSame('acceleration_only', $this->classificationOf($result, 'claude'));
    }

    public function test_provider_without_exit_path_not_required_classified_risky_dependency(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'cursor', 'has_local_fallback' => false, 'required_for_steady_state' => false],
            ],
        ]);

        $this->assertSame('risky_dependency', $this->classificationOf($result, 'cursor'));
    }

    public function test_non_native_required_provider_classified_prohibited_dependency(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'codex', 'has_local_fallback' => true, 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertSame('prohibited_dependency', $this->classificationOf($result, 'codex'));
    }

    public function test_native_provider_required_for_steady_state_is_still_native_not_prohibited(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'atlas_native', 'is_atlas_native' => true, 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertSame('native', $this->classificationOf($result, 'atlas_native'));
        $this->assertFalse($result['production_promotion_blocked'], 'a native provider required for steady state is the intended architecture');
        $this->assertSame([], $result['required_for_steady_state_providers']);
    }

    // ── AC3: requires fallback, replacement or sunset criteria for non-native providers ──

    public function test_replacement_path_alone_satisfies_exit_path_requirement(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'cursor', 'has_local_fallback' => false, 'replacement_path' => 'migrate to composer-2.5 by Q3'],
            ],
        ]);

        $this->assertTrue($result['pool_classification'][0]['has_exit_path']);
        $this->assertSame('acceleration_only', $this->classificationOf($result, 'cursor'));
        $this->assertTrue($result['autonomy_preserved']);
    }

    public function test_sunset_criteria_alone_satisfies_exit_path_requirement(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'hermes', 'has_local_fallback' => false, 'sunset_criteria' => 'drop once atlas_native grind throughput exceeds 90'],
            ],
        ]);

        $this->assertTrue($result['pool_classification'][0]['has_exit_path']);
        $this->assertSame('acceleration_only', $this->classificationOf($result, 'hermes'));
    }

    public function test_no_fallback_no_replacement_no_sunset_is_risky_and_not_autonomy_preserved(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'ghost-provider'],
            ],
        ]);

        $this->assertFalse($result['pool_classification'][0]['has_exit_path']);
        $this->assertSame('risky_dependency', $this->classificationOf($result, 'ghost-provider'));
        $this->assertFalse($result['autonomy_preserved']);
    }

    // ── AC4: blocks steady-state designs requiring an external provider ────────

    public function test_required_non_native_provider_blocks_production_regardless_of_exit_path(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'codex', 'has_local_fallback' => true, 'replacement_path' => 'x', 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertTrue($result['production_promotion_blocked']);
        $this->assertContains('codex', $result['required_for_steady_state_providers']);
    }

    public function test_mixed_pool_only_non_native_required_provider_blocks(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'atlas_native', 'is_atlas_native' => true, 'required_for_steady_state' => true],
                ['provider' => 'claude', 'has_local_fallback' => true, 'required_for_steady_state' => false],
                ['provider' => 'codex', 'has_local_fallback' => false, 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertTrue($result['production_promotion_blocked']);
        $this->assertSame(['codex'], $result['required_for_steady_state_providers']);
    }

    // ── pool_classification output shape ─────────────────────────────────────

    public function test_pool_classification_present_for_every_provider(): void
    {
        $result = $this->svc()->evaluate([
            'providers' => [
                ['provider' => 'a', 'is_atlas_native' => true],
                ['provider' => 'b', 'has_local_fallback' => true],
                ['provider' => 'c'],
            ],
        ]);

        $providers = array_column($result['pool_classification'], 'provider');
        $this->assertSame(['a', 'b', 'c'], $providers);
    }

    public function test_pool_classification_empty_for_empty_pool(): void
    {
        $result = $this->svc()->evaluate(['providers' => []]);

        $this->assertSame([], $result['pool_classification']);
    }
}
