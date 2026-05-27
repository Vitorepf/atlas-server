<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\NightShift;

use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use Tests\TestCase;

/**
 * Contract tests for the Night Shift Area Focus contract registry (AP-712).
 *
 * The registry is read-only, stateless and deterministic: v1 registers only
 * `agentic_engineering_os`; any other area_id must resolve to null so the loop
 * blocks it as `area_not_registered`.
 */
final class AtlasNightShiftAreaFocusContractRegistryTest extends TestCase
{
    private function registry(): AtlasNightShiftAreaFocusContractRegistry
    {
        return app(AtlasNightShiftAreaFocusContractRegistry::class);
    }

    public function test_agentic_engineering_os_contract_is_canonical(): void
    {
        $contract = $this->registry()->resolve(
            AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS
        );

        $this->assertNotNull($contract);
        $this->assertSame(AtlasNightShiftAreaFocusContractRegistry::CONTRACT_SCHEMA, $contract['schema_version']);
        $this->assertSame(AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS, $contract['area_id']);
        $this->assertSame('Agentic Engineering OS', $contract['area_name']);
        $this->assertIsArray($contract['area_owner_docs']);
        $this->assertNotEmpty($contract['area_owner_docs']);
        $this->assertSame('atlas_itself', $contract['repo_scope']['target']);
        $this->assertSame(['atlas-server', 'atlas-desktop'], $contract['repo_scope']['repos']);
        $this->assertSame(0, $contract['autonomy_tier']);
        $this->assertSame(2, $contract['max_tier_for_area']);
        $this->assertSame('max_governed', $contract['dev_mode']);
        $this->assertSame('max_governed', $contract['forge_mode']);
        $this->assertSame('morning_inbox', $contract['inbox_destination']);
        $this->assertTrue($contract['risk_policy']['block_external_company']);
        $this->assertContains('budget_exhausted', $contract['stop_conditions']);
        $this->assertContains('kill_switch', $contract['stop_conditions']);
    }

    public function test_unknown_area_resolves_to_null(): void
    {
        $this->assertNull($this->registry()->resolve('blackink'));
        $this->assertNull($this->registry()->resolve('unknown_company'));
    }

    public function test_registered_areas_lists_only_v1_areas(): void
    {
        $areas = $this->registry()->registeredAreas();

        $this->assertSame(
            [AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS],
            $areas
        );
    }

    public function test_is_registered_reflects_registry_membership(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->isRegistered(
            AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS
        ));
        $this->assertFalse($registry->isRegistered('blackink'));
    }
}
