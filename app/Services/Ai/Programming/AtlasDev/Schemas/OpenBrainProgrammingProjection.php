<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\ProviderSafeRedactor;
use InvalidArgumentException;

final class OpenBrainProgrammingProjection implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.open_brain.programming_projection.v1';

    public const MODE = 'programming';

    private const HASH_FIELD = 'projection_hash';

    /**
     * @param  list<ContextRef>  $memoryRefs
     * @param  list<ContextRef>  $knowledgeRefs
     * @param  list<ContextRef>  $codeRefs
     * @param  list<string>  $missingSources
     * @param  array<string, mixed>  $truncation  e.g. ['truncated' => bool, 'reasons' => list<string>]
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $mode,
        public readonly string $objectiveHash,
        public readonly array $memoryRefs,
        public readonly array $knowledgeRefs,
        public readonly array $codeRefs,
        public readonly array $missingSources,
        public readonly array $truncation,
        public readonly bool $providerSafe,
        public readonly string $projectionHash,
    ) {
        if ($mode !== self::MODE) {
            throw new InvalidArgumentException(
                "OpenBrainProgrammingProjection.mode must be '".self::MODE."'."
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'code_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->codeRefs),
            'knowledge_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->knowledgeRefs),
            'memory_refs' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->memoryRefs),
            'missing_sources' => array_values($this->missingSources),
            'mode' => $this->mode,
            'objective_hash' => $this->objectiveHash,
            'projection_hash' => $this->projectionHash,
            'provider_safe' => $this->providerSafe,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'truncation' => $this->truncation,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        $canonical = $this->toCanonicalArray();
        if ($this->providerSafe) {
            return $canonical;
        }

        $canonical['memory_refs'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['memory_refs'],
            ['ref'],
            'open_brain_projection.memory_refs',
        );
        $canonical['knowledge_refs'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['knowledge_refs'],
            ['ref'],
            'open_brain_projection.knowledge_refs',
        );
        $canonical['code_refs'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['code_refs'],
            ['ref'],
            'open_brain_projection.code_refs',
        );
        $canonical['missing_sources'] = ProviderSafeRedactor::redactStringList(
            $this->missingSources,
            'open_brain_projection.missing_sources',
        );

        return CanonicalJson::canonicalize($canonical);
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    public function isTruncated(): bool
    {
        return (bool) ($this->truncation['truncated'] ?? false);
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            mode: (string) ($payload['mode'] ?? self::MODE),
            objectiveHash: (string) $payload['objective_hash'],
            memoryRefs: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['memory_refs'] ?? [])),
            ),
            knowledgeRefs: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['knowledge_refs'] ?? [])),
            ),
            codeRefs: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['code_refs'] ?? [])),
            ),
            missingSources: array_values((array) ($payload['missing_sources'] ?? [])),
            truncation: (array) ($payload['truncation'] ?? []),
            providerSafe: (bool) ($payload['provider_safe'] ?? true),
            projectionHash: (string) ($payload['projection_hash'] ?? ''),
        );
    }
}
