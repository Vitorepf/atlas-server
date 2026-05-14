<?php

namespace App\Services\Ai\Programming\Sdd\Pipeline;

/**
 * Versioned, governed context bundle. Per context-packages-and-projections.md,
 * a ContextPack lists which packages were selected and a digest hash so the
 * exact context can be reproduced and audited.
 */
final class ContextPack
{
    /**
     * @param  list<string>  $packages
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $stack,
        public readonly array $packages,
        public readonly array $payload,
        public readonly string $digest,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 'atlas.sdd_context_pack.v1',
            'stack' => $this->stack,
            'packages' => $this->packages,
            'digest' => $this->digest,
            'payload' => $this->payload,
        ];
    }
}
