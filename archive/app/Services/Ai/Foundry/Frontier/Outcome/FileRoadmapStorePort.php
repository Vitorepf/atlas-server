<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

/**
 * Foundry AP-E · file-backed read of the living, versioned, append-only roadmap.v1
 * for the materializer's Outcome\RoadmapStorePort contract.
 *
 * latest() returns the highest-version roadmap.v1 line for an area from the SAME
 * append-only JSONL the materializer writes (roadmap.jsonl under the foundry
 * storage tree), or null when none exists. The roadmap is NEVER mutated in place
 * here (read-only) and NEVER prose. The storage dir mirrors the materializer's
 * setOutcomesStorageDirForTesting seam so a CLI can point both at one tree.
 */
final class FileRoadmapStorePort implements RoadmapStorePort
{
    private ?string $storageDirOverride = null;

    public function setStorageDir(?string $dir): void
    {
        $this->storageDirOverride = $dir;
    }

    /**
     * @return array<string,mixed>|null roadmap.v1 line with the highest version
     */
    public function latest(string $areaId): ?array
    {
        $path = $this->storagePath($areaId, 'roadmap.jsonl');
        if (! is_file($path)) {
            return null;
        }

        $latest = null;
        $latestVersion = -1;
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }
                $version = (int) ($decoded['version'] ?? 0);
                if ($version >= $latestVersion) {
                    $latestVersion = $version;
                    $latest = $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        return $latest;
    }

    private function storagePath(string $areaId, string $file): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower($areaId)) ?: 'unknown_area';

        $base = $this->storageDirOverride
            ?? (function_exists('storage_path') ? storage_path('atlas/foundry') : sys_get_temp_dir().'/atlas/foundry');

        return $base.DIRECTORY_SEPARATOR.$slug.DIRECTORY_SEPARATOR.$file;
    }
}
