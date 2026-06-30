<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentProviderAdapterRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasAiSelfConstructionAgentProviderAdapterRegistryTest extends TestCase
{
    public function test_registry_projects_canonical_provider_adapters_without_execution_authority(): void
    {
        $projection = app(AgentProviderAdapterRegistry::class)->projection();

        $this->assertSame('provider_adapter_registry_ready', $projection['status']);
        $this->assertSame(5, $projection['provider_count']);
        $this->assertFalse($projection['registry_policy']['external_process_start_allowed']);
        $this->assertFalse($projection['registry_policy']['token_spend_allowed']);

        $providers = array_column($projection['providers'], 'provider');

        $this->assertSame(['codex', 'claude', 'gemini', 'local', 'http'], $providers);
    }

    public function test_registry_resolves_provider_adapter_descriptor(): void
    {
        $registry = app(AgentProviderAdapterRegistry::class);
        $descriptor = $registry->resolve('codex', 'codex');

        $this->assertSame('ADAPTER-CODEX-SELF-CONSTRUCTION-0001', $descriptor['adapter_id']);
        $this->assertFalse($descriptor['external_process_start_enabled']);
        $this->assertFalse($descriptor['token_spend_enabled']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $registry->descriptorHash($descriptor));
    }

    public function test_registry_rejects_unknown_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_adapter_not_registered');

        app(AgentProviderAdapterRegistry::class)->resolve('unknown', 'unknown');
    }

    public function test_registry_rejects_provider_adapter_mismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('provider_adapter_mismatch');

        app(AgentProviderAdapterRegistry::class)->resolve('local', 'local');
    }

    // ── evaluateAdapterCapability ──────────────────────────────────────────

    private function capabilityFacts(array $overrides = []): array
    {
        return array_merge([
            'capabilities' => ['code_edit', 'evidence_collection'],
            'task_families' => [
                ['family' => 'service_layer', 'required_capabilities' => ['code_edit']],
            ],
            'self_declared' => false,
            'evidence_age_days' => 1,
            'recent_outcomes' => [],
            'known_failure_modes' => [],
        ], $overrides);
    }

    public function test_clean_capability_evidence_routes_freely(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability('codex', $this->capabilityFacts());

        $this->assertSame('codex', $result['provider']);
        $this->assertSame('route_freely', $result['safe_routing_hint']);
        $this->assertContains('service_layer', $result['safe_task_families']);
        $this->assertSame([], $result['blocked_task_families']);
        $this->assertFalse($result['global_evidence_failure']);
    }

    public function test_self_declared_capability_with_no_outcomes_routes_with_caution(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability(
            'codex',
            $this->capabilityFacts(['self_declared' => true]),
        );

        $this->assertSame('route_with_caution_collect_evidence', $result['safe_routing_hint']);
        $this->assertTrue($result['global_evidence_failure']);
        $this->assertSame([], $result['safe_task_families']);
        $this->assertContains('self_declared_evidence_not_verified', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_stale_evidence_routes_with_caution(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability(
            'codex',
            $this->capabilityFacts(['evidence_age_days' => 30, 'max_evidence_age_days' => 14]),
        );

        $this->assertSame('route_with_caution_collect_evidence', $result['safe_routing_hint']);
        $this->assertTrue($result['evidence_freshness']['is_stale']);
    }

    public function test_missing_capability_blocks_family_and_avoids_routing(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability('codex', $this->capabilityFacts([
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'doc_writer', 'required_capabilities' => ['doc_generation']],
            ],
        ]));

        $this->assertSame('avoid_routing', $result['safe_routing_hint']);
        $this->assertContains('missing_capability:doc_generation', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_high_give_back_rate_blocks_family(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability('codex', $this->capabilityFacts([
            'recent_outcomes' => [
                ['family' => 'service_layer', 'outcome' => 'give_back'],
                ['family' => 'service_layer', 'outcome' => 'give_back'],
                ['family' => 'service_layer', 'outcome' => 'success'],
            ],
        ]));

        $this->assertContains('high_give_back_rate', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_known_failure_mode_blocks_family(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability('codex', $this->capabilityFacts([
            'known_failure_modes' => ['service_layer'],
        ]));

        $this->assertContains('known_failure_mode', $result['blocked_task_families'][0]['reasons']);
    }

    public function test_mixed_allowed_and_blocked_families_routes_only_to_allowed(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability('codex', $this->capabilityFacts([
            'capabilities' => ['code_edit'],
            'task_families' => [
                ['family' => 'service_layer', 'required_capabilities' => ['code_edit']],
                ['family' => 'doc_writer', 'required_capabilities' => ['doc_generation']],
            ],
        ]));

        $this->assertSame('route_only_to_allowed_families', $result['safe_routing_hint']);
        $this->assertContains('service_layer', $result['safe_task_families']);
        $this->assertSame('doc_writer', $result['blocked_task_families'][0]['family']);
    }

    public function test_capability_evaluation_never_enables_execution_or_token_spend(): void
    {
        $result = app(AgentProviderAdapterRegistry::class)->evaluateAdapterCapability('codex', $this->capabilityFacts());

        $this->assertFalse($result['external_process_start_enabled']);
        $this->assertFalse($result['token_spend_allowed']);
    }
}
