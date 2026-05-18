<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Coarse measure of how broadly a patch reaches, for the patch_receipt.
 *
 * Captures the obvious volumetric numbers (file/line counts) plus the set of
 * top-level directories touched. `touched_dirs` is bucketed at the top depth
 * (e.g. "app/Services", "tests/Feature") so receipts stay stable as files
 * move within the same area.
 */
final class BlastRadius implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.blast_radius.v1';

    /**
     * @param  list<string>  $touchedDirs
     */
    public function __construct(
        public readonly int $fileCount,
        public readonly int $linesAddedTotal,
        public readonly int $linesRemovedTotal,
        public readonly array $touchedDirs,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->fileCount < 0) {
            throw new InvalidArgumentException('BlastRadius.file_count must be non-negative.');
        }
        if ($this->linesAddedTotal < 0) {
            throw new InvalidArgumentException('BlastRadius.lines_added_total must be non-negative.');
        }
        if ($this->linesRemovedTotal < 0) {
            throw new InvalidArgumentException('BlastRadius.lines_removed_total must be non-negative.');
        }
        foreach ($this->touchedDirs as $i => $dir) {
            if (! is_string($dir) || $dir === '') {
                throw new InvalidArgumentException("BlastRadius.touched_dirs[{$i}] must be a non-empty string.");
            }
        }
    }

    public static function fromArray(array $payload): self
    {
        $dirs = (array) ($payload['touched_dirs'] ?? []);
        $normalized = [];
        foreach (array_values($dirs) as $dir) {
            if (is_string($dir)) {
                $normalized[] = $dir;
            }
        }

        return new self(
            fileCount: AtlasDevSchemaArray::int($payload, 'file_count'),
            linesAddedTotal: AtlasDevSchemaArray::int($payload, 'lines_added_total'),
            linesRemovedTotal: AtlasDevSchemaArray::int($payload, 'lines_removed_total'),
            touchedDirs: $normalized,
            providerSafe: array_key_exists('provider_safe', $payload)
                ? AtlasDevSchemaArray::bool($payload, 'provider_safe')
                : true,
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'file_count' => $this->fileCount,
            'lines_added_total' => $this->linesAddedTotal,
            'lines_removed_total' => $this->linesRemovedTotal,
            'touched_dirs' => $this->touchedDirs,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }
}
