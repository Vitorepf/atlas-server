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
}
