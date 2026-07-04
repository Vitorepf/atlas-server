<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolIndependenceGate;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolIndependenceGateTest extends TestCase
{
    public function test_provider_with_local_fallback_and_not_required_preserves_autonomy_for_all_scenarios(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'claude', 'has_local_fallback' => true, 'fallback_proof_refs' => ['cli_override_available'], 'required_for_steady_state' => false],
            ],
        ]);

        $this->assertTrue($result['autonomy_preserved']);
        $this->assertFalse($result['production_promotion_blocked']);
        $this->assertSame([], $result['required_for_steady_state_providers']);
        $this->assertSame([], $result['missing_fallback_capabilities']);
        $this->assertSame([], $result['missing_fallback_proof']);
        $this->assertSame([], $result['minimal_next_tasks_needed_to_restore_independence']);

        $scenarios = array_column($result['scenario_results'], 'scenario');
        foreach (AtlasExternalBrainProviderPoolIndependenceGate::SCENARIOS as $scenario) {
            $this->assertContains($scenario, $scenarios);
        }
        foreach ($result['scenario_results'] as $row) {
            $this->assertTrue($row['autonomy_preserved']);
        }
    }

    public function test_required_for_steady_state_provider_blocks_production_promotion(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'codex', 'has_local_fallback' => true, 'fallback_proof_refs' => ['cli_fallback_works'], 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertFalse($result['autonomy_preserved']);
        $this->assertTrue($result['production_promotion_blocked']);
        $this->assertSame(['codex'], $result['required_for_steady_state_providers']);
        $this->assertSame([], $result['missing_fallback_proof']);
        foreach ($result['scenario_results'] as $row) {
            $this->assertFalse($row['autonomy_preserved']);
        }
        $this->assertContains('build_atlas_native_fallback_for_codex', $result['minimal_next_tasks_needed_to_restore_independence']);
    }

    // ── AC1: has_local_fallback without fallback_proof_refs blocks autonomy_preserved ──

    public function test_has_local_fallback_without_fallback_proof_refs_blocks_autonomy(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'no-proof', 'has_local_fallback' => true, 'required_for_steady_state' => false],
            ],
        ]);

        $this->assertFalse($result['autonomy_preserved']);
        $this->assertContains('no-proof', $result['missing_fallback_proof']);
        foreach ($result['scenario_results'] as $row) {
            $this->assertFalse($row['autonomy_preserved']);
        }
    }

    // ── AC2: provider with fallback_proof_refs and not required preserves autonomy ──

    public function test_fallback_proof_refs_and_not_required_preserves_autonomy(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'proven', 'has_local_fallback' => true, 'fallback_proof_refs' => ['smoke_test_passes'], 'required_for_steady_state' => false],
            ],
        ]);

        $this->assertTrue($result['autonomy_preserved']);
        $this->assertSame([], $result['missing_fallback_proof']);
        foreach ($result['scenario_results'] as $row) {
            $this->assertTrue($row['autonomy_preserved']);
        }
    }

    // ── AC3: required_for_steady_state still blocks even with fallback proof ──

    public function test_required_blocks_even_with_fallback_proof_refs(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'needed', 'has_local_fallback' => true, 'fallback_proof_refs' => ['proven'], 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertFalse($result['autonomy_preserved']);
        $this->assertTrue($result['production_promotion_blocked']);
        $this->assertSame([], $result['missing_fallback_proof']);
        foreach ($result['scenario_results'] as $row) {
            $this->assertFalse($row['autonomy_preserved']);
        }
    }

    public function test_provider_without_local_fallback_emits_missing_fallback_capabilities(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                [
                    'provider' => 'cursor',
                    'has_local_fallback' => false,
                    'required_for_steady_state' => false,
                    'missing_fallback_capabilities' => ['local_codegen_fallback'],
                ],
            ],
        ]);

        $this->assertFalse($result['autonomy_preserved']);
        $this->assertFalse($result['production_promotion_blocked']);
        $this->assertSame(
            [['provider' => 'cursor', 'capability' => 'local_codegen_fallback']],
            $result['missing_fallback_capabilities'],
        );
        $this->assertContains('build_atlas_native_fallback_for_cursor', $result['minimal_next_tasks_needed_to_restore_independence']);
    }

    public function test_provider_without_explicit_missing_capabilities_gets_default_capability_name(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'hermes', 'has_local_fallback' => false, 'required_for_steady_state' => false],
            ],
        ]);

        $this->assertSame(
            [['provider' => 'hermes', 'capability' => 'atlas_native_fallback_for_hermes']],
            $result['missing_fallback_capabilities'],
        );
    }

    public function test_empty_provider_pool_is_trivially_autonomous(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate(['providers' => []]);

        $this->assertTrue($result['autonomy_preserved']);
        $this->assertFalse($result['production_promotion_blocked']);
        $this->assertSame([], $result['scenario_results']);
    }

    public function test_evaluate_does_not_create_tasks_only_lists_their_names(): void
    {
        $result = (new AtlasExternalBrainProviderPoolIndependenceGate)->evaluate([
            'providers' => [
                ['provider' => 'codex', 'has_local_fallback' => false, 'required_for_steady_state' => true],
            ],
        ]);

        $this->assertIsArray($result['minimal_next_tasks_needed_to_restore_independence']);
        foreach ($result['minimal_next_tasks_needed_to_restore_independence'] as $task) {
            $this->assertIsString($task);
        }
    }
}
