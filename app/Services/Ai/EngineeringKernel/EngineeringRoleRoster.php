<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final class EngineeringRoleRoster
{
    /**
     * @param  array<string,mixed>  $roster
     * @return array<string,array<string,mixed>>
     */
    public static function validateRoster(array $roster): array
    {
        self::assertExactlyTwentyTwoUniqueRoles($roster, 'role_roster');
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
        self::assertExactlyTwentyTwoUniqueRoles($dispositions, 'role_dispositions');
        $normalized = [];
        foreach ($dispositions as $role => $entry) {
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
    private static function assertExactlyTwentyTwoUniqueRoles(array $values, string $field): void
    {
        $roles = array_keys($values);
        $validIds = array_filter($roles, static fn (mixed $role): bool => is_string($role) && trim($role) !== '');
        if (count($values) !== 22 || count($validIds) !== 22 || count(array_unique($roles)) !== 22) {
            throw new InvalidArgumentException("{$field}_must_contain_exactly_22_roles");
        }
    }
}
