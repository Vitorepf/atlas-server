<?php

namespace App\Services\Ai;

class AiProviderHealthCheck
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly string $message,
        public readonly array $metadata = [],
    ) {}
}
