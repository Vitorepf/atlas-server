<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;

final class ForgeRuntimeInputPolicy
{
    public static function validObraId(string $obraId): bool
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $obraId) !== 1) {
            return false;
        }

        $lower = strtolower($obraId);

        return ! str_contains($lower, 'fake')
            && ! str_contains($lower, 'placeholder')
            && $lower !== '00000000-0000-0000-0000-000000000000';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function role(array $input): string
    {
        $role = strtolower(trim((string) ($input['forge_role'] ?? AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER)));

        return in_array($role, AtlasForgeProviderTopologyService::CANONICAL_ROLES, true)
            ? $role
            : AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER;
    }
}
