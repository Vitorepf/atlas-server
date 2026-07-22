<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

use InvalidArgumentException;

final readonly class AtlasLoopFactConfidenceBoundsSchema
{
    public function __construct(
        public AtlasLoopFactConfidenceBoundsEnvelope $envelope,
    ) {}

    public static function make(mixed $value, int $sampleSize, int $sourceCount): self
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException('fact_confidence_value_must_be_bool');
        }

        if ($sampleSize <= 0) {
            throw new InvalidArgumentException('fact_confidence_sample_size_must_be_positive');
        }

        if ($sourceCount <= 0) {
            throw new InvalidArgumentException('fact_confidence_source_count_must_be_positive');
        }

        return new self(new AtlasLoopFactConfidenceBoundsEnvelope($value, $sampleSize, $sourceCount));
    }

    public static function fromLegacy(bool $value): self
    {
        return self::make($value, 1, 1);
    }

    /**
     * @return array{sample_size:int,source_count:int,value:bool}
     */
    public function toArray(): array
    {
        return $this->envelope->toArray();
    }

    public function toJson(bool $legacyProjection = false): string
    {
        if ($legacyProjection) {
            return (string) json_encode($this->envelope->value, JSON_THROW_ON_ERROR);
        }

        return (string) json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function equals(self $other): bool
    {
        return $this->envelope->equals($other->envelope);
    }
}
