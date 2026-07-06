<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderIndependenceProofRunner;
use Tests\TestCase;

final class AtlasExternalBrainProviderIndependenceProofRunnerTest extends TestCase
{
    private function runner(): AtlasExternalBrainProviderIndependenceProofRunner
    {
        return new AtlasExternalBrainProviderIndependenceProofRunner;
    }

    // ── AC: a load-bearing single provider fails the independence proof ──

    public function test_load_bearing_single_provider_fails(): void
    {
        $result = $this->runner()->run([
            'providers' => [
                ['provider' => 'claude', 'required_for_steady_state' => true, 'has_local_fallback' => false, 'is_atlas_native' => false],
            ],
            'outcomes' => [
                ['provider' => 'claude', 'result' => 'success'],
            ],
            'patch_hash' => 'abc123',
        ]);

        $this->assertFalse($result['independent']);
        $this->assertContains('claude', $result['load_bearing_providers']);
        $this->assertStringContainsString('load_bearing_providers:claude', $result['summary']);
    }

    // ── AC: a redundant pool passes independence ──

    public function test_redundant_pool_passes(): void
    {
        $result = $this->runner()->run([
            'providers' => [
                ['provider' => 'claude', 'required_for_steady_state' => false, 'has_local_fallback' => true, 'is_atlas_native' => false, 'fallback_proof_refs' => ['tests/FallbackTest.php']],
                ['provider' => 'codex', 'required_for_steady_state' => false, 'has_local_fallback' => true, 'is_atlas_native' => false, 'fallback_proof_refs' => ['tests/FallbackTest.php']],
                ['provider' => 'atlas_native', 'is_atlas_native' => true, 'required_for_steady_state' => true],
            ],
            'outcomes' => [
                ['provider' => 'claude', 'result' => 'success'],
                ['provider' => 'codex', 'result' => 'success'],
            ],
            'patch_hash' => 'abc123',
        ]);

        $this->assertTrue($result['independent']);
        $this->assertSame([], $result['load_bearing_providers']);
        $this->assertSame('no_single_provider_is_load_bearing', $result['summary']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->runner()->run([]);

        $this->assertSame(AtlasExternalBrainProviderIndependenceProofRunner::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('independent', $result);
        $this->assertArrayHasKey('load_bearing_providers', $result);
        $this->assertArrayHasKey('benchmarks', $result);
        $this->assertArrayHasKey('independence_proof', $result);
        $this->assertArrayHasKey('outcome_attribution', $result);
        $this->assertArrayHasKey('patch_dry_run', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'providers' => [
                ['provider' => 'claude', 'required_for_steady_state' => true, 'has_local_fallback' => false],
            ],
        ];

        $a = $this->runner()->run($input);
        $b = $this->runner()->run($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
