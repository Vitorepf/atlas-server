<?php

namespace App\Services\Ai\Kernel\Envelope;

final readonly class IntentClassification
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    public function __construct(
        public string $intent,
        public float $confidence,
        public array $attributes = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            intent: trim((string) ($payload['intent'] ?? $payload['id'] ?? 'unknown')) ?: 'unknown',
            confidence: max(0.0, min(1.0, (float) ($payload['confidence'] ?? 0.0))),
            attributes: $payload,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->attributes, [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
        ]);
    }
}
