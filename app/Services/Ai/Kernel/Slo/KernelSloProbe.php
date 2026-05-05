<?php

namespace App\Services\Ai\Kernel\Slo;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Throwable;

class KernelSloProbe
{
    public function __construct(
        private readonly KernelSloTargets $targets,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $context
     */
    public function observe(string $stage, int $durationMs, bool $success = true, array $context = []): KernelSloAssessment
    {
        $assessment = $this->targets->assess($stage, $durationMs, $success);
        $this->ledger->recordSloObservation($assessment, $context);

        return $assessment;
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @param  array<string,mixed>  $context
     * @return T
     *
     * @throws Throwable
     */
    public function measure(string $stage, callable $callback, array $context = []): mixed
    {
        $started = hrtime(true);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->observe($stage, $this->durationMs($started), false, $context);

            throw $exception;
        }

        $this->observe($stage, $this->durationMs($started), true, $context);

        return $result;
    }

    private function durationMs(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }
}
