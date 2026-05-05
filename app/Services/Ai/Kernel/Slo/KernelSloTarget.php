<?php

namespace App\Services\Ai\Kernel\Slo;

final readonly class KernelSloTarget
{
    public function __construct(
        public string $stage,
        public int $p50Ms,
        public int $p95Ms,
        public int $p99Ms,
        public float $successRate,
        public string $severity,
        public string $notes = '',
        public string $schemaVersion = 'atlas.kernel.slo_target.v1',
    ) {}

    public function assess(int $durationMs, bool $success = true): KernelSloAssessment
    {
        $violations = [];

        if (! $success) {
            $violations[] = 'stage_failed';
        }

        if ($durationMs > $this->p99Ms) {
            $violations[] = 'latency_above_p99';
        } elseif ($durationMs > $this->p95Ms) {
            $violations[] = 'latency_above_p95';
        }

        $status = match (true) {
            in_array('stage_failed', $violations, true) || in_array('latency_above_p99', $violations, true) => 'breach',
            in_array('latency_above_p95', $violations, true) => 'warning',
            default => 'ok',
        };

        return new KernelSloAssessment(
            stage: $this->stage,
            durationMs: $durationMs,
            success: $success,
            status: $status,
            severity: $status === 'ok' ? 'none' : $this->severity,
            target: $this,
            violations: $violations,
        );
    }

    /**
     * @return array{
     *     schema_version:string,
     *     stage:string,
     *     p50_ms:int,
     *     p95_ms:int,
     *     p99_ms:int,
     *     success_rate:float,
     *     severity:string,
     *     notes:string
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'stage' => $this->stage,
            'p50_ms' => $this->p50Ms,
            'p95_ms' => $this->p95Ms,
            'p99_ms' => $this->p99Ms,
            'success_rate' => $this->successRate,
            'severity' => $this->severity,
            'notes' => $this->notes,
        ];
    }
}
