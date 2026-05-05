<?php

namespace App\Services\Ai\Kernel\Envelope;

final class RoutingState
{
    public function __construct(
        public ?IntentClassification $intent = null,
        public ?string $domain = null,
        public ?string $flow = null,
        public ?EffectiveProfile $profile = null,
    ) {}
}
