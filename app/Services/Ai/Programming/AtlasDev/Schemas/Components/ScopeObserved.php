<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class ScopeObserved implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.scope_observed.v1';

    /**
     * @param  list<string>  $changedFiles
     * @param  list<ScopeFileDiff>  $fileDiffs
     */
    public function __construct(
        public readonly ?string $gitDiffHash,
        public readonly array $changedFiles,
        public readonly int $changedFilesCount,
        public readonly array $fileDiffs,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->changedFilesCount !== count($this->changedFiles)) {
            throw new InvalidArgumentException(
                'ScopeObserved invariant: changed_files_count must equal len(changed_files).'
            );
        }
        foreach ($this->fileDiffs as $i => $diff) {
            if (! $diff instanceof ScopeFileDiff) {
                throw new InvalidArgumentException("ScopeObserved.file_diffs[{$i}] must be ScopeFileDiff.");
            }
        }
    }

    public static function fromArray(array $payload): self
    {
        $changedFiles = AtlasDevSchemaArray::stringList($payload, 'changed_files');
        $diffsRaw = (array) ($payload['file_diffs'] ?? []);
        $diffs = [];
        foreach (array_values($diffsRaw) as $i => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException("ScopeObserved.file_diffs[{$i}] must be an array.");
            }
            $diffs[] = ScopeFileDiff::fromArray($raw);
        }

        return new self(
            gitDiffHash: AtlasDevSchemaArray::nullableString($payload, 'git_diff_hash'),
            changedFiles: $changedFiles,
            changedFilesCount: AtlasDevSchemaArray::int($payload, 'changed_files_count'),
            fileDiffs: $diffs,
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
            'changed_files' => array_values($this->changedFiles),
            'changed_files_count' => $this->changedFilesCount,
            'file_diffs' => array_map(
                static fn (ScopeFileDiff $d): array => $d->toCanonicalArray(),
                array_values($this->fileDiffs),
            ),
            'git_diff_hash' => $this->gitDiffHash,
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
