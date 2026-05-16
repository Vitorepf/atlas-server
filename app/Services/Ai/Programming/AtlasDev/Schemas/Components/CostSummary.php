<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class CostSummary implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.cost_summary.v1';

    public function __construct(
        public readonly int $providerCalls,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly ?float $estimatedCostUsd,
        public readonly ?int $wallTimeMs,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->providerCalls < 0) {
            throw new InvalidArgumentException('CostSummary.provider_calls must be non-negative.');
        }
    }

    public static function fromArray(array $payload): self
    {
        $estimated = $payload['estimated_cost_usd'] ?? null;
        if ($estimated !== null && ! is_float($estimated) && ! is_int($estimated)) {
            throw new InvalidArgumentException('CostSummary.estimated_cost_usd must be number or null.');
        }

        return new self(
            providerCalls: AtlasDevSchemaArray::int($payload, 'provider_calls'),
            tokensIn: AtlasDevSchemaArray::nullableInt($payload, 'tokens_in'),
            tokensOut: AtlasDevSchemaArray::nullableInt($payload, 'tokens_out'),
            estimatedCostUsd: $estimated === null ? null : (float) $estimated,
            wallTimeMs: AtlasDevSchemaArray::nullableInt($payload, 'wall_time_ms'),
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
            'estimated_cost_usd' => $this->estimatedCostUsd,
            'provider_calls' => $this->providerCalls,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'wall_time_ms' => $this->wallTimeMs,
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
