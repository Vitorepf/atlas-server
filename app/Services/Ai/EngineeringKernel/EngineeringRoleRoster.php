<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use InvalidArgumentException;

final class EngineeringRoleRoster
{
    public const OFFICIAL_ROLES = AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLES;

    /** The exact public vocabulary frozen by the Quality Foundry master plan. */
    public const CANONICAL_ROLES = [
        'product_strategy', 'product_management', 'domain_research', 'ux_research',
        'interaction_design', 'visual_design', 'software_architecture', 'backend',
        'frontend', 'mobile', 'data', 'qa_test', 'appsec_privacy',
        'performance_resilience', 'devops_sre', 'observability', 'release',
        'technical_docs_dx', 'maintainability_simplification', 'outcome_analysis',
        'evidence_audit', 'final_certification',
    ];

    /** @var array<string,string> */
    private const LEGACY_TO_CANONICAL = [
        'architecture' => 'software_architecture',
        'qa_testing' => 'qa_test',
        'documentation_dx' => 'technical_docs_dx',
        'maintenance_simplification' => 'maintainability_simplification',
    ];

    public static function canonicalRole(string $role): string
    {
        if (in_array($role, self::CANONICAL_ROLES, true)) {
            return $role;
        }
        if (isset(self::LEGACY_TO_CANONICAL[$role])) {
            return self::LEGACY_TO_CANONICAL[$role];
        }

        throw new InvalidArgumentException('unknown_quality_foundry_role:'.$role);
    }

    public static function runtimeRole(string $role): string
    {
        $canonical = self::canonicalRole($role);
        $legacy = array_search($canonical, self::LEGACY_TO_CANONICAL, true);

        return $legacy === false ? $canonical : $legacy;
    }

    /** @return list<string> */
    public static function canonicalRoster(): array
    {
        return array_map(static fn (string $role): string => self::canonicalRole($role), self::OFFICIAL_ROLES);
    }

    /**
     * @param  array<string,mixed>  $roster
     * @return array<string,array<string,mixed>>
     */
    public static function validateRoster(array $roster): array
    {
        self::assertOfficialRoles($roster, 'role_roster');
        $normalized = [];
        foreach ($roster as $role => $entry) {
            if (! is_array($entry) || ! is_string($entry['depth'] ?? null) || trim($entry['depth']) === '') {
                throw new InvalidArgumentException("role_roster_invalid:{$role}");
            }
            $normalized[$role] = $entry;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $dispositions
     * @return array<string,array<string,mixed>>
     */
    public static function validateDispositions(array $dispositions): array
    {
        if (count($dispositions) !== count(self::OFFICIAL_ROLES)
            || array_diff(array_keys($dispositions), self::OFFICIAL_ROLES) !== []
            || array_diff(self::OFFICIAL_ROLES, array_keys($dispositions)) !== []) {
            throw new InvalidArgumentException('role_dispositions_must_match_official_quality_foundry_roster');
        }
        $normalized = [];
        foreach (self::OFFICIAL_ROLES as $role) {
            $entry = $dispositions[$role];
            if (! is_array($entry)) {
                throw new InvalidArgumentException("role_disposition_invalid:{$role}");
            }
            $status = $entry['status'] ?? null;
            if (! in_array($status, ['pass', 'block', 'not_applicable'], true)) {
                throw new InvalidArgumentException("role_disposition_status_invalid:{$role}");
            }
            CanonicalKernelPayload::requireHash($entry, 'evidence_hash');
            CanonicalKernelPayload::requireHash($entry, 'signature');
            if ($status === 'not_applicable') {
                CanonicalKernelPayload::requireString($entry, 'applicability_rule');
                CanonicalKernelPayload::requireString($entry, 'justification');
            }
            $normalized[$role] = $entry;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $values */
    private static function assertOfficialRoles(array $values, string $field): void
    {
        $roles = array_keys($values);
        if ($roles !== self::OFFICIAL_ROLES) {
            throw new InvalidArgumentException("{$field}_must_match_official_quality_foundry_roster");
        }
    }
}
