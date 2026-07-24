<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Architecture;

/**
 * Shared workspace-relative path helper for the Kernel Architecture scanner family —
 * de-duplicates the byte-identical relativePath() copied across scanner/audit classes.
 */
trait ArchitecturePathHelper
{
    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $normalizedBase)
            ? substr($normalized, strlen($normalizedBase))
            : $normalized;
    }
}
