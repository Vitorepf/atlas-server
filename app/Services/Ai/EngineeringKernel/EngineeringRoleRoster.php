<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final class EngineeringRoleRoster
{
    public const OFFICIAL_ROLES = [
        'product_strategy', 'product_management', 'domain_research', 'ux_research',
        'interaction_design', 'visual_design', 'architecture', 'backend', 'frontend',
        'mobile', 'data', 'qa_testing', 'appsec_privacy', 'performance_resilience',
        'devops_sre', 'observability', 'release', 'documentation_dx',
        'maintenance_simplification', 'outcome_analysis', 'evidence_audit', 'final_certification',
    ];

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
