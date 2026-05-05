<?php

namespace App\Services\Ai\Kernel\Envelope;

final class ExecutionState
{
    public function __construct(
        public ?string $executorKind = null,
        public ?array $runtimeGraph = null,
        public string $status = 'received',
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {}
}
