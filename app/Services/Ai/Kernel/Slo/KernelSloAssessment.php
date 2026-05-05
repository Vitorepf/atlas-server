<?php

namespace App\Services\Ai\Kernel\Slo;

final readonly class KernelSloAssessment
{
    public function __construct(
        public string $stage,
        public int $durationMs,
        public bool $success,
        public string $status,
        public string $severity,
        public ?KernelSloTarget $target,
        public array $violations = [],
    ) {}

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * @return array{
     *     stage:string,
     *     duration_ms:int,
     *     success:bool,
     *     status:string,
     *     severity:string,
     *     target:array<string,mixed>|null,
     *     violations:array<int,string>
     * }
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'duration_ms' => $this->durationMs,
            'success' => $this->success,
            'status' => $this->status,
            'severity' => $this->severity,
            'target' => $this->target?->toArray(),
            'violations' => $this->violations,
        ];
    }
}
