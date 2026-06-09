<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeRuntimeInputPolicy;
use Tests\TestCase;

final class ForgeRuntimeInputPolicyTest extends TestCase
{
    public function test_validates_real_obra_uuid_and_rejects_fake_placeholder_or_zero_ids(): void
    {
        $this->assertTrue(ForgeRuntimeInputPolicy::validObraId('11111111-2222-3333-4444-555555555555'));
        $this->assertTrue(ForgeRuntimeInputPolicy::validObraId('obra_11111111-2222-3333-4444-555555555555'));

        $this->assertFalse(ForgeRuntimeInputPolicy::validObraId('not-a-uuid'));
        $this->assertFalse(ForgeRuntimeInputPolicy::validObraId('fake-11111111-2222-3333-4444-555555555555'));
        $this->assertFalse(ForgeRuntimeInputPolicy::validObraId('placeholder-11111111-2222-3333-4444-555555555555'));
        $this->assertFalse(ForgeRuntimeInputPolicy::validObraId('00000000-0000-0000-0000-000000000000'));
    }

    public function test_normalizes_forge_role_from_topology_canonical_roles(): void
    {
        foreach (AtlasForgeProviderTopologyService::CANONICAL_ROLES as $role) {
            $this->assertSame($role, ForgeRuntimeInputPolicy::role(['forge_role' => strtoupper($role)]));
        }

        $this->assertSame(
            AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
            ForgeRuntimeInputPolicy::role(['forge_role' => 'unknown_role']),
        );
        $this->assertSame(
            AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
            ForgeRuntimeInputPolicy::role([]),
        );
    }
}
