<?php

declare(strict_types=1);

namespace Tests\Unit\AgentGovernance;

use App\Services\Ai\AgentGovernance\AtlasFleetCatalog;
use App\Services\Ai\AgentGovernance\FleetAgentDefinition;
use Tests\TestCase;

/**
 * The catalog enumerates the real fleet the operator discovered — and every entry declares which account it
 * spends, so the apps can show "🔴 gastando [conta]".
 */
final class AtlasFleetCatalogTest extends TestCase
{
    public function test_catalog_lists_the_known_fleet(): void
    {
        $keys = AtlasFleetCatalog::keys();
        foreach ([
            AtlasFleetCatalog::LOOP,
            AtlasFleetCatalog::AI_WORKER_CODEX,
            AtlasFleetCatalog::AI_WORKER_CLAUDE,
            AtlasFleetCatalog::FINANCE_STRATEGY_LOOP,
            AtlasFleetCatalog::MAC_AGENT,
        ] as $expected) {
            $this->assertContains($expected, $keys, "fleet must include {$expected}");
        }
    }

    public function test_every_agent_declares_account_and_kind(): void
    {
        foreach (AtlasFleetCatalog::all() as $key => $def) {
            $this->assertInstanceOf(FleetAgentDefinition::class, $def);
            $this->assertSame($key, $def->key);
            $this->assertNotSame('', $def->label, "{$key} needs a label");
            $this->assertNotSame('', $def->account, "{$key} must declare which account it spends");
            $this->assertTrue($def->providerSpending, "{$key} is a provider-consumer the operator must see");
        }
    }

    public function test_get_and_has_are_consistent(): void
    {
        $this->assertTrue(AtlasFleetCatalog::has(AtlasFleetCatalog::LOOP));
        $this->assertNotNull(AtlasFleetCatalog::get(AtlasFleetCatalog::LOOP));
        $this->assertFalse(AtlasFleetCatalog::has('nope.not.real'));
        $this->assertNull(AtlasFleetCatalog::get('nope.not.real'));
    }
}
