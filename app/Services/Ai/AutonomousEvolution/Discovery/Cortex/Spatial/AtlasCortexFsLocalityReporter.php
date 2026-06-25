<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * FACT-only filesystem-locality reporter for the Loop scope.
 *
 * For every PHP file under app/Services/Ai/AutonomousEvolution/**, enumerates sibling files at
 * directory-tree depth K (configurable, default {1,2,3}) and writes one JSONL record per (file, K)
 * to storage/atlas/cortex/spatial/fs/. Records contain raw counts and FQCN lists — NEVER a locality
 * score or ranking.
 *
 * Deterministic ordering: sorted by file path then depth.
 *
 * Honors the pétreo floor: with ATLAS_LOOP_MASTER_ENABLED=false the reporter writes ZERO bytes and
 * returns an empty Collection.
 */
final class AtlasCortexFsLocalityReporter
{
    public const SCHEMA = 'atlas.cortex.spatial.fs_locality.v1';

    public const ALLOWED_SCOPE_RELATIVE = 'app/Services/Ai/AutonomousEvolution';

    /** @var list<int> */
    private array $depths;

    /**
     * @param  list<int>  $depths
     */
    public function __construct(
        private readonly string $scopeRoot,
        private readonly string $storageRoot,
        array $depths = [1, 2, 3],
    ) {
        $cleaned = array_values(array_unique(array_map('intval', $depths)));
        sort($cleaned, SORT_NUMERIC);
        $this->depths = array_values(array_filter($cleaned, static fn (int $d): bool => $d >= 1));
    }

    /**
     * @return Collection<int, array<string,mixed>>
     */
    public function report(): Collection
    {
        if (! $this->masterEnabled()) {
            return new Collection();
        }

        $scopeReal = realpath($this->scopeRoot);
        if ($scopeReal === false) {
            return new Collection();
        }
        $this->assertWithinAllowedScope($scopeReal);

        $files = $this->listPhpFiles($scopeReal);
        sort($files, SORT_STRING);

        $records = [];
        foreach ($files as $file) {
            foreach ($this->depths as $depth) {
                $records[] = $this->record($scopeReal, $file, $depth);
            }
        }

        $this->writeJsonl($records);

        return new Collection($records);
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function assertWithinAllowedScope(string $real): void
    {
        $normalized = rtrim($real, '/');
        if (! str_contains($normalized, '/'.self::ALLOWED_SCOPE_RELATIVE)) {
            throw new RuntimeException('AtlasCortexFsLocalityReporter: scope_root outside allowed boundary: '.$real);
        }
    }

    /**
     * @return list<string>
     */
    private function listPhpFiles(string $root): array
    {
        $out = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $info) {
            if (! $info instanceof \SplFileInfo) {
                continue;
            }
            if (! $info->isFile()) {
                continue;
            }
            if (strtolower($info->getExtension()) !== 'php') {
                continue;
            }
            $real = $info->getRealPath();
            if ($real === false || str_contains($real, '/MarketingDomain/') || str_contains($real, '/Aaeos/') || str_contains($real, '/Forge/')) {
                continue;
            }
            $out[] = $real;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function record(string $scopeReal, string $filePath, int $depth): array
    {
        $relPath = ltrim(str_replace($scopeReal, '', $filePath), '/');
        $fileDir = dirname($filePath);
        $ancestor = $this->ancestor($fileDir, $depth);

        $neighbors = [];
        if ($ancestor !== null && is_dir($ancestor)) {
            foreach ($this->listPhpFiles($ancestor) as $sibling) {
                if ($sibling === $filePath) {
                    continue;
                }
                $neighbors[] = $sibling;
            }
        }
        sort($neighbors, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA,
            'file_path' => $relPath,
            'depth' => $depth,
            'neighbor_count' => count($neighbors),
            'neighbor_fqcns' => array_map(
                fn (string $n): string => ltrim(str_replace($scopeReal, '', $n), '/'),
                $neighbors,
            ),
        ];
    }

    private function ancestor(string $dir, int $depth): ?string
    {
        for ($i = 0; $i < $depth; $i++) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }

        return $dir;
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function writeJsonl(array $records): void
    {
        $dir = $this->storageRoot.'/atlas/cortex/spatial/fs';
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $path = $dir.'/locality.jsonl';
        $body = '';
        foreach ($records as $r) {
            $body .= json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
        @file_put_contents($path, $body);
    }

    private function masterEnabled(): bool
    {
        $env = getenv('ATLAS_LOOP_MASTER_ENABLED');
        if ($env === false) {
            return true; // default ON for the reporter; the storage write is bounded
        }

        return ! in_array(strtolower((string) $env), ['0', 'false', 'off', ''], true);
    }
}
