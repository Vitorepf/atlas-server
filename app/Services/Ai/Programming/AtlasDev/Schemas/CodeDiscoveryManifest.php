<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\ProviderSafeRedactor;
use InvalidArgumentException;

final class CodeDiscoveryManifest implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.code_discovery_manifest.v1';

    public const CONFIDENCE_CONFIRMED_FACT = 'confirmed_fact';

    public const CONFIDENCE_STRONG_INFERENCE = 'strong_inference';

    public const CONFIDENCE_HYPOTHESIS = 'hypothesis';

    public const CONFIDENCE_BLOCKING_AMBIGUITY = 'blocking_ambiguity';

    public const CONFIDENCE_VALUES = [
        self::CONFIDENCE_CONFIRMED_FACT,
        self::CONFIDENCE_STRONG_INFERENCE,
        self::CONFIDENCE_HYPOTHESIS,
        self::CONFIDENCE_BLOCKING_AMBIGUITY,
    ];

    private const HASH_FIELD = 'manifest_hash';

    /**
     * @param  list<CodeCandidate>  $likelyFiles
     * @param  list<ContextRef>  $relatedSymbols
     * @param  list<ContextRef>  $relatedTests
     * @param  list<ContextRef>  $relatedCommands
     * @param  list<MissingRef>  $missingRefs
     * @param  list<string>  $forbiddenFiles
     * @param  list<ContextRef>  $likelyCallers  the strongest production consumers of the likely
     *                                           files, discovered via the same code-intelligence lookup used for relatedSymbols. Additive,
     *                                           defaults empty when the lookup is unavailable — never blocks discovery.
     * @param  list<string>  $recentOutcomeFacts  compact facts about recent Dev run outcomes that
     *                                            touched the same files (read through AtlasAemorRuntimeService when available). Additive,
     *                                            defaults empty when the runtime is unavailable — never blocks discovery.
     */
    public function __construct(
        public readonly string $runId,
        public readonly array $likelyFiles,
        public readonly array $relatedSymbols,
        public readonly array $relatedTests,
        public readonly array $relatedCommands,
        public readonly string $confidence,
        public readonly array $missingRefs,
        public readonly array $forbiddenFiles,
        public readonly bool $providerSafe,
        public readonly string $manifestHash,
        public readonly array $likelyCallers = [],
        public readonly array $recentOutcomeFacts = [],
    ) {
        if (! in_array($confidence, self::CONFIDENCE_VALUES, true)) {
            throw new InvalidArgumentException(
                'CodeDiscoveryManifest.confidence must be one of: '.implode('|', self::CONFIDENCE_VALUES)
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function isBlocking(): bool
    {
        return $this->confidence === self::CONFIDENCE_BLOCKING_AMBIGUITY;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'confidence' => $this->confidence,
            'forbidden_files' => array_values($this->forbiddenFiles),
            'likely_callers' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->likelyCallers),
            'likely_files' => array_map(static fn (CodeCandidate $c): array => $c->toCanonicalArray(), $this->likelyFiles),
            'manifest_hash' => $this->manifestHash,
            'missing_refs' => array_map(static fn (MissingRef $r): array => $r->toCanonicalArray(), $this->missingRefs),
            'provider_safe' => $this->providerSafe,
            'recent_outcome_facts' => array_values(array_map('strval', $this->recentOutcomeFacts)),
            'related_commands' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->relatedCommands),
            'related_symbols' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->relatedSymbols),
            'related_tests' => array_map(static fn (ContextRef $r): array => $r->toCanonicalArray(), $this->relatedTests),
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        $canonical = $this->toCanonicalArray();
        if ($this->providerSafe) {
            return $canonical;
        }

        $canonical['likely_files'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['likely_files'],
            ['path'],
            'code_discovery_manifest.likely_files.path',
        );
        $canonical['related_symbols'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['related_symbols'],
            ['file', 'ref'],
            'code_discovery_manifest.related_symbols',
        );
        $canonical['related_tests'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['related_tests'],
            ['ref'],
            'code_discovery_manifest.related_tests',
        );
        $canonical['related_commands'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['related_commands'],
            ['ref'],
            'code_discovery_manifest.related_commands',
        );
        $canonical['likely_callers'] = ProviderSafeRedactor::redactArrayOfMaps(
            $canonical['likely_callers'],
            ['ref'],
            'code_discovery_manifest.likely_callers',
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

    public static function fromArray(array $payload): self
    {
        return new self(
            runId: (string) $payload['run_id'],
            likelyFiles: array_map(
                static fn (array $c): CodeCandidate => CodeCandidate::fromArray($c),
                array_values((array) ($payload['likely_files'] ?? [])),
            ),
            relatedSymbols: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['related_symbols'] ?? [])),
            ),
            relatedTests: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['related_tests'] ?? [])),
            ),
            relatedCommands: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['related_commands'] ?? [])),
            ),
            confidence: (string) $payload['confidence'],
            missingRefs: array_map(
                static fn (array $r): MissingRef => MissingRef::fromArray($r),
                array_values((array) ($payload['missing_refs'] ?? [])),
            ),
            forbiddenFiles: array_values((array) ($payload['forbidden_files'] ?? [])),
            providerSafe: (bool) ($payload['provider_safe'] ?? true),
            manifestHash: (string) ($payload['manifest_hash'] ?? ''),
            likelyCallers: array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                array_values((array) ($payload['likely_callers'] ?? [])),
            ),
            recentOutcomeFacts: array_values(array_map('strval', (array) ($payload['recent_outcome_facts'] ?? []))),
        );
    }
}
