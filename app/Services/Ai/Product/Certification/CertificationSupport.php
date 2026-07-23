<?php

namespace App\Services\Ai\Product\Certification;

use Illuminate\Support\Facades\File;

/**
 * Shared, stateless helpers for the Atlas AI Product Certification sections.
 *
 * Extracted verbatim from AtlasAiProductCertificationService so the per-surface
 * Section classes and the façade read/inspect the tree the same way. Pure
 * functions of the filesystem — no state, container-resolvable singleton-safe.
 */
final class CertificationSupport
{
    /**
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function check(string $id, bool $passed, string $severity, array $evidence): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'passed' : 'failed',
            'severity' => $severity,
            'evidence' => $evidence,
        ];
    }

    public function source(string $path): string
    {
        return is_file($path) ? File::get($path) : '';
    }

    public function callOrderInSource(string $source, string $needle, string $after): bool
    {
        $needleAt = strpos($source, $needle);
        $afterAt = strpos($source, $after);
        if ($needleAt === false || $afterAt === false) {
            return false;
        }

        return $needleAt < $afterAt;
    }

    public function importsRichInputCanon(string $source): bool
    {
        return str_contains($source, '@atlas/rich-input-canon')
            || str_contains($source, 'atlas-rich-input-canon')
            || str_contains($source, 'rich-input-canon');
    }

    public function repoPath(string $relative): string
    {
        if (str_starts_with($relative, 'app/')
            || str_starts_with($relative, 'database/')
            || str_starts_with($relative, 'tests/')
            || str_starts_with($relative, 'docs/')
        ) {
            return rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
        }

        return rtrim(dirname(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.ltrim($relative, '/');
    }
}
