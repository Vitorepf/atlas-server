<?php

namespace App\Services\Ai\Programming\Sdd\Pipeline;

/**
 * Final return shape from AtlasSddPipeline::run().
 */
final class AtlasSddOutput
{
    /**
     * @param  array<string,mixed>  $payload
     */
    private function __construct(
        public readonly string $status,
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function completed(array $payload): self
    {
        return new self('completed', $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function needsClarification(array $payload): self
    {
        return new self('needs_clarification', $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function blocked(array $payload): self
    {
        return new self('blocked', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 'atlas.sdd_pipeline_output.v1',
            'status' => $this->status,
            'payload' => $this->payload,
        ];
    }
}
