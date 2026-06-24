<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

final readonly class AtlasLoopFactConfidenceBoundsEnvelope
{
    public function __construct(
        public bool $value,
        public int $sampleSize,
        public int $sourceCount,
    ) {}

    /**
     * @return array{sample_size:int,source_count:int,value:bool}
     */
    public function toArray(): array
    {
        return [
            'sample_size' => $this->sampleSize,
            'source_count' => $this->sourceCount,
            'value' => $this->value,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value
            && $this->sampleSize === $other->sampleSize
            && $this->sourceCount === $other->sourceCount;
    }
}
