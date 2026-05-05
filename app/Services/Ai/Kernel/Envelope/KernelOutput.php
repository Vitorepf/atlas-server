<?php

namespace App\Services\Ai\Kernel\Envelope;

final readonly class KernelOutput
{
    /**
     * @param  array<string,mixed>  $artifacts
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $status,
        public array $artifacts = [],
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            status: trim((string) ($payload['status'] ?? 'pending')) ?: 'pending',
            artifacts: is_array($payload['artifacts'] ?? null) ? $payload['artifacts'] : [],
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'artifacts' => $this->artifacts,
            'metadata' => $this->metadata,
        ];
    }
}
