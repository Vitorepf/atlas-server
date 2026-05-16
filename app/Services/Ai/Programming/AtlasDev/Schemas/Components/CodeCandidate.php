<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class CodeCandidate implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.components.code_candidate.v1';

    /**
     * @param  list<string>  $symbols
     */
    public function __construct(
        public readonly string $path,
        public readonly string $reason,
        public readonly float $confidence,
        public readonly array $symbols = [],
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('CodeCandidate.path must be non-empty.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('CodeCandidate.reason must be non-empty.');
        }

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('CodeCandidate.confidence must be in [0.0, 1.0].');
        }

        foreach ($symbols as $i => $symbol) {
            if (! is_string($symbol) || $symbol === '') {
                throw new InvalidArgumentException("CodeCandidate.symbols[{$i}] must be a non-empty string.");
            }
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'confidence' => $this->confidence,
            'path' => $this->path,
            'reason' => $this->reason,
            'symbols' => array_values($this->symbols),
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
        return true;
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            path: AtlasDevSchemaArray::string($payload, 'path'),
            reason: AtlasDevSchemaArray::string($payload, 'reason'),
            confidence: (float) $payload['confidence'],
            symbols: array_values((array) ($payload['symbols'] ?? [])),
        );
    }
}
