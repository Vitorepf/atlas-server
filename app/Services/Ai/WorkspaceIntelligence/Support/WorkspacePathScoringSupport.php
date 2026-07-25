<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence\Support;

/**
 * Pure workspace path area key + command affinity scoring (full-pass peel).
 */
final class WorkspacePathScoringSupport
{
    public static function areaKey(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_starts_with($path, '..')) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return null;
        }

        return implode('/', array_slice($parts, 0, min(2, count($parts))));
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  list<string>  $areaKeys
     */
    public static function commandAreaScore(array $stats, array $areaKeys): int
    {
        if ($areaKeys === []) {
            return 0;
        }

        $affinity = (array) ($stats['area_affinity'] ?? []);
        $score = 0;
        foreach ($areaKeys as $area) {
            foreach ($affinity as $knownArea => $weight) {
                if (! is_string($knownArea)) {
                    continue;
                }
                if ($knownArea === $area || str_starts_with($knownArea, $area.'/') || str_starts_with($area, $knownArea.'/')) {
                    $score += (int) $weight;
                }
            }
        }

        return $score;
    }
}
