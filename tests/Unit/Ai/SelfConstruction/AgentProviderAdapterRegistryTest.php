<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentProviderAdapterRegistry;
use Tests\TestCase;

final class AgentProviderAdapterRegistryTest extends TestCase
{
    private function registry(): AgentProviderAdapterRegistry
    {
        return new AgentProviderAdapterRegistry;
    }

    public function test_descriptors_include_capability_score_and_verdict(): void
    {
        $projection = $this->registry()->projection();

        foreach ($projection['providers'] as $descriptor) {
            $this->assertArrayHasKey('capability_score', $descriptor);
            $this->assertArrayHasKey('capability_verdict', $descriptor);
            $this->assertIsFloat($descriptor['capability_score']);
            $this->assertContains($descriptor['capability_verdict'], ['capable', 'marginal', 'incapable']);
        }
    }

    public function test_default_projection_has_high_capability_score(): void
    {
        $projection = $this->registry()->projection();

        foreach ($projection['providers'] as $descriptor) {
            $this->assertGreaterThanOrEqual(0.75, $descriptor['capability_score']);
            $this->assertSame('capable', $descriptor['capability_verdict']);
        }
    }

    public function test_fallback_available_signal_present(): void
    {
        $projection = $this->registry()->projection();

        foreach ($projection['providers'] as $descriptor) {
            $this->assertArrayHasKey('fallback_available', $descriptor);
            $this->assertTrue($descriptor['fallback_available']);
        }
    }

    public function test_evaluate_adapter_capability_returns_score_and_verdict(): void
    {
        $result = $this->registry()->evaluateAdapterCapability('codex', [
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'implementation', 'required_capabilities' => ['code_edit']],
            ],
            'self_declared' => false,
            'evidence_age_days' => 0,
            'recent_outcomes' => [],
        ]);

        $this->assertArrayHasKey('safe_routing_hint', $result);
        $this->assertContains('implementation', $result['safe_task_families']);
    }

    public function test_high_give_back_rate_blocks_family(): void
    {
        $result = $this->registry()->evaluateAdapterCapability('codex', [
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'implementation', 'required_capabilities' => ['code_edit']],
            ],
            'recent_outcomes' => [
                ['family' => 'implementation', 'outcome' => 'give_back'],
                ['family' => 'implementation', 'outcome' => 'give_back'],
                ['family' => 'implementation', 'outcome' => 'give_back'],
            ],
        ]);

        $blocked = array_filter($result['blocked_task_families'], fn ($b) => $b['family'] === 'implementation');
        $this->assertNotEmpty($blocked);
    }

    public function test_stale_evidence_triggers_global_failure(): void
    {
        $result = $this->registry()->evaluateAdapterCapability('codex', [
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'implementation', 'required_capabilities' => ['code_edit']],
            ],
            'evidence_age_days' => 30,
            'max_evidence_age_days' => 14,
        ]);

        $this->assertTrue($result['global_evidence_failure']);
        $this->assertSame('route_with_caution_collect_evidence', $result['safe_routing_hint']);
    }

    public function test_self_declared_evidence_fails_closed(): void
    {
        $result = $this->registry()->evaluateAdapterCapability('codex', [
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'implementation', 'required_capabilities' => ['code_edit']],
            ],
            'self_declared' => true,
        ]);

        $this->assertTrue($result['global_evidence_failure']);
    }

    public function test_resolve_returns_descriptor_for_registered_provider(): void
    {
        $descriptor = $this->registry()->resolve('codex', 'codex');
        $this->assertSame('codex', $descriptor['provider']);
        $this->assertSame('implementation_worker', $descriptor['role']);
    }

    public function test_resolve_throws_for_unregistered_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->resolve('unknown', 'unknown');
    }

    public function test_descriptor_hash_is_deterministic(): void
    {
        $registry = $this->registry();
        $descriptor = $registry->descriptors()['codex'];
        $hashA = $registry->descriptorHash($descriptor);
        $hashB = $registry->descriptorHash($descriptor);
        $this->assertSame($hashA, $hashB);
    }
}
