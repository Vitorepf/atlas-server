<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class EscalationSignals implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.escalation_signals.v1';

    /**
     * @param  list<string>  $riskKeywords
     */
    public function __construct(
        public readonly int $fileCount,
        public readonly int $layersTouched,
        public readonly array $riskKeywords,
        public readonly ?int $contextRequiredChars,
        public readonly ?int $threadMessages,
        public readonly int $priorFailureCount,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->fileCount < 0 || $this->layersTouched < 0 || $this->priorFailureCount < 0) {
            throw new InvalidArgumentException('EscalationSignals integer counters must be non-negative.');
        }
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            fileCount: AtlasDevSchemaArray::int($payload, 'file_count'),
            layersTouched: AtlasDevSchemaArray::int($payload, 'layers_touched'),
            riskKeywords: AtlasDevSchemaArray::stringList($payload, 'risk_keywords'),
            contextRequiredChars: AtlasDevSchemaArray::nullableInt($payload, 'context_required_chars'),
            threadMessages: AtlasDevSchemaArray::nullableInt($payload, 'thread_messages'),
            priorFailureCount: AtlasDevSchemaArray::int($payload, 'prior_failure_count'),
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
            'prior_failure_count' => $this->priorFailureCount,
            'risk_keywords' => array_values($this->riskKeywords),
            'thread_messages' => $this->threadMessages,
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
