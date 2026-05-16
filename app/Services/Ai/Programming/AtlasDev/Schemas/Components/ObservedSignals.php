<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;

final class ObservedSignals implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.observed_signals.v1';

    /**
     * @param  list<string>  $riskKeywords
     */
    public function __construct(
        public readonly int $fileCount,
        public readonly int $layersTouched,
        public readonly array $riskKeywords,
        public readonly ?int $contextRequiredChars = null,
        public readonly ?bool $priorFailureInArea = null,
        public readonly ?bool $testCoverageGap = null,
        public readonly bool $providerSafe = true,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            fileCount: AtlasDevSchemaArray::int($payload, 'file_count'),
            layersTouched: AtlasDevSchemaArray::int($payload, 'layers_touched'),
            riskKeywords: AtlasDevSchemaArray::stringList($payload, 'risk_keywords'),
            contextRequiredChars: AtlasDevSchemaArray::nullableInt($payload, 'context_required_chars'),
            priorFailureInArea: AtlasDevSchemaArray::nullableBool($payload, 'prior_failure_in_area'),
            testCoverageGap: AtlasDevSchemaArray::nullableBool($payload, 'test_coverage_gap'),
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
            'context_required_chars' => $this->contextRequiredChars,
            'file_count' => $this->fileCount,
            'layers_touched' => $this->layersTouched,
            'prior_failure_in_area' => $this->priorFailureInArea,
            'risk_keywords' => array_values($this->riskKeywords),
            'test_coverage_gap' => $this->testCoverageGap,
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
