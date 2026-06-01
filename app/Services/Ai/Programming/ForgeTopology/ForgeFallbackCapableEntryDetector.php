<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeTopology;

final class ForgeFallbackCapableEntryDetector
{
    private const SCHEMA_VERSION = 'atlas.aaeos.forge_fallback_capable.v1';

    /**
     * @param  array<int, array<string, mixed>>  $fallbackChain
     * @return array{schema_version: 'atlas.aaeos.forge_fallback_capable.v1', has_capable_entry: bool, capable_roles: list<string>, incapable_count: int, defect: ?string}
     */
    public function detect(array $fallbackChain): array
    {
        $capableRoles = [];
        $incapableCount = 0;

        foreach ($fallbackChain as $entry) {
            if (is_array($entry) && ($entry['capable'] ?? null) === true) {
                $capableRoles[] = (string) ($entry['role'] ?? '');

                continue;
            }

            $incapableCount++;
        }

        $hasCapableEntry = $capableRoles !== [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'has_capable_entry' => $hasCapableEntry,
            'capable_roles' => $capableRoles,
            'incapable_count' => $incapableCount,
            'defect' => $hasCapableEntry ? null : 'no_capable_fallback',
        ];
    }
}
