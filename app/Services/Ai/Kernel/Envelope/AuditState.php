<?php

namespace App\Services\Ai\Kernel\Envelope;

final readonly class AuditState
{
    public function __construct(
        public string $traceId,
        public ?string $parentTraceId,
        public string $chainHash,
    ) {}
}
