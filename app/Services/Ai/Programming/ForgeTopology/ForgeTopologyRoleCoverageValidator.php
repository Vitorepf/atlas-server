<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;

final class ForgeTopologyRoleCoverageValidator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.forge_role_coverage.v1';

    private const SELECTED_STATUS = 'selected';

    private const DEFECT_MISSING_CANONICAL_ROLE = 'missing_canonical_role';

    private const DEFECT_DUPLICATE_ROLE_ASSIGNMENT = 'duplicate_role_assignment';

    private const DEFECT_NO_SELECTED_PRIMARY_BUILDER = 'no_selected_primary_builder';

    private const DEFECT_UNKNOWN_ROLE_PRESENT = 'unknown_role_present';

    /**
     * Validate the materialized topology roles[] against the canonical role
     * set and return a deterministic coverage verdict computed purely from the
     * supplied $roles. Each entry is expected to carry role/provider/status.
     *
     * @param  array<int, array<string, mixed>>  $roles
     * @return array{
     *     schema_version: 'atlas.aaeos.forge_role_coverage.v1',
     *     coherent: bool,
     *     defects: list<array{code: string, role: string}>,
     *     missing_roles: list<string>,
     *     duplicate_roles: list<string>,
     *     selected_builder_present: bool
     * }
     */
    public function inspect(array $roles): array
    {
        $roleCounts = [];
        $unknownRoles = [];
        $selectedBuilderPresent = false;

        foreach ($roles as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $role = $this->roleName($entry);
            if ($role === '') {
                continue;
            }

            if (in_array($role, AtlasForgeProviderTopologyService::CANONICAL_ROLES, true)) {
                $roleCounts[$role] = ($roleCounts[$role] ?? 0) + 1;

                if ($role === AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER
                    && $this->statusValue($entry) === self::SELECTED_STATUS) {
                    $selectedBuilderPresent = true;
                }

                continue;
            }

            // Unknown roles are tracked once, in first-seen order, and never
            // contribute to canonical coverage (missing) or duplicates.
            if (! in_array($role, $unknownRoles, true)) {
                $unknownRoles[] = $role;
            }
        }

        $missingRoles = [];
        $duplicateRoles = [];
        foreach (AtlasForgeProviderTopologyService::CANONICAL_ROLES as $canonicalRole) {
            $count = $roleCounts[$canonicalRole] ?? 0;

            if ($count === 0) {
                $missingRoles[] = $canonicalRole;

                continue;
            }

            if ($count > 1) {
                $duplicateRoles[] = $canonicalRole;
            }
        }

        $defects = [];

        foreach ($missingRoles as $missingRole) {
            $defects[] = [
                'code' => self::DEFECT_MISSING_CANONICAL_ROLE,
                'role' => $missingRole,
            ];
        }

        foreach ($duplicateRoles as $duplicateRole) {
            $defects[] = [
                'code' => self::DEFECT_DUPLICATE_ROLE_ASSIGNMENT,
                'role' => $duplicateRole,
            ];
        }

        // A selected primary builder is only meaningful when the builder role
        // is actually present; absence is already reported as a missing role.
        $builderPresent = ($roleCounts[AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER] ?? 0) > 0;
        if ($builderPresent && ! $selectedBuilderPresent) {
            $defects[] = [
                'code' => self::DEFECT_NO_SELECTED_PRIMARY_BUILDER,
                'role' => AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER,
            ];
        }

        foreach ($unknownRoles as $unknownRole) {
            $defects[] = [
                'code' => self::DEFECT_UNKNOWN_ROLE_PRESENT,
                'role' => $unknownRole,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'coherent' => $defects === [],
            'defects' => $defects,
            'missing_roles' => $missingRoles,
            'duplicate_roles' => $duplicateRoles,
            'selected_builder_present' => $selectedBuilderPresent,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function roleName(array $entry): string
    {
        $role = $entry['role'] ?? null;

        return is_string($role) ? $role : '';
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function statusValue(array $entry): string
    {
        $status = $entry['status'] ?? null;

        return is_string($status) ? $status : '';
    }
}
