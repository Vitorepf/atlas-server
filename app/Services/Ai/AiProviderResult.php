<?php

namespace App\Services\Ai;

class AiProviderResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $output,
        public readonly array $command,
        public readonly ?int $exitCode,
        public readonly int $durationMs,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $metadata = [],
    ) {}
}
