<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderModelRegistryService;
use Tests\TestCase;

/**
 * Focused contract for the Forge Rivals provider/model registry (factory-critical).
 */
final class AtlasForgeRivalsProviderModelRegistryServiceTest extends TestCase
{
    private AtlasForgeRivalsProviderModelRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new AtlasForgeRivalsProviderModelRegistryService;
    }

    public function test_snapshot_exposes_advisory_registry_contract(): void
    {
        $snapshot = $this->registry->snapshot();

        $this->assertSame(AtlasForgeRivalsProviderModelRegistryService::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertSame('ok', $snapshot['status']);
        $this->assertTrue($snapshot['advisory_only']);
        $this->assertFalse($snapshot['should_update_provider_topology']);
        $this->assertTrue($snapshot['never_changes_atlas_decide_topology']);
        $this->assertSame('atlas_decide', $snapshot['owner_of_model_routing']);
        $this->assertSame('none', $snapshot['routing_effect']);
        $this->assertSame(count($this->registry->providers()), $snapshot['provider_count']);
    }

    public function test_resolve_accepts_configured_model_id_as_request_token(): void
    {
        $cursorDefaultModelId = (string) (config('atlas.ai.providers.cursor_cli.model') ?: 'composer-2.5-fast');

        $resolved = $this->registry->resolve('cursor', $cursorDefaultModelId);

        $this->assertTrue($resolved['ok'], json_encode($resolved['blockers'] ?? [], JSON_THROW_ON_ERROR));
        $this->assertSame('default', $resolved['canonical_model']);
        $this->assertSame($cursorDefaultModelId, $resolved['model_id']);
        $this->assertSame('atlas.ai.providers.cursor_cli.model', $resolved['model_id_config_key']);
    }

    public function test_resolve_uses_provider_default_when_requested_model_is_empty(): void
    {
        $resolved = $this->registry->resolve('composer', '');

        $this->assertTrue($resolved['ok']);
        $this->assertSame('composer-2.5', $resolved['canonical_model']);
    }

    public function test_resolve_blocks_unknown_provider(): void
    {
        $resolved = $this->registry->resolve('unknown_provider', 'default');

        $this->assertFalse($resolved['ok']);
        $this->assertContains('provider_unknown:unknown_provider', $resolved['blockers']);
    }

    public function test_aliases_for_unknown_provider_returns_empty_list(): void
    {
        $this->assertSame([], $this->registry->aliasesForProvider('not_a_provider'));
    }
}
