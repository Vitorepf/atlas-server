<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DeepFinding;

/**
 * Shared leaf helpers for the Area Focus Deep Finding Engine and its extracted
 * sections (GOD-DEBULK split). Pure, stateless, container-resolved so both the
 * façade and every Section reference the same implementation instead of
 * duplicating it. Bodies are byte-identical to their pre-split façade originals.
 */
class DeepFindingSupport
{
    public function isCodePath(string $path): bool
    {
        foreach (['app/', 'tests/', 'config/', 'routes/', 'database/', 'packages/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function pathExists(string $relativePath): bool
    {
        $base = function_exists('base_path') ? base_path() : getcwd();

        return file_exists(rtrim((string) $base, '/').'/'.ltrim($relativePath, '/'));
    }
}
