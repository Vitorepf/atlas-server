<?php

namespace App\Services\Ai\Kernel\Envelope;

final class RoutingState
{
    public function __construct(
        public ?array $intent = null,
        public ?string $domain = null,
        public ?string $flow = null,
        public ?array $profile = null,
    ) {}
}
