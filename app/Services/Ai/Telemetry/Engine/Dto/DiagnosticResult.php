<?php

namespace App\Services\Ai\Telemetry\Engine\Dto;

final readonly class DiagnosticResult
{
    /**
     * @param  array<int,DiagnosticFinding>  $findings
     */
    public function __construct(
        public array $findings,
        public bool $persisted = false,
        public array $meta = [],
    ) {}

    public static function empty(string $reason): self
    {
        return new self([], false, ['skipped' => true, 'skip_reason' => $reason]);
    }
}
