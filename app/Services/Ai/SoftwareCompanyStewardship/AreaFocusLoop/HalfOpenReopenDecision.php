<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class HalfOpenReopenDecision
{
    private const SCHEMA_VERSION = 'atlas.software_company_stewardship.half_open_reopen_decision.v1';

    private const DEFAULT_REOPEN_FAILURE_THRESHOLD = 2;

    private const MIN_REOPEN_FAILURE_THRESHOLD = 2;

    /**
     * @param  array<string, mixed>  $probe
     * @return array{
     *     schema_version: string,
     *     circuit_state_in: string,
     *     probe_failures: int,
     *     reopen_failure_threshold: int,
     *     consecutive_successes: int,
     *     reopen: bool,
     *     decided_state: string,
     *     reason: string
     * }
     */
    public function decide(array $probe): array
    {
        $circuitStateIn = AreaFocusCircuitStateNormalizer::fromPayload($probe);
        $probeFailures = AreaFocusScalarNormalizer::nonNegativeInt($probe['probe_failures'] ?? 0);
        $reopenFailureThreshold = AreaFocusScalarNormalizer::intWithDefaultAndMin(
            $probe['reopen_failure_threshold'] ?? null,
            self::DEFAULT_REOPEN_FAILURE_THRESHOLD,
            self::MIN_REOPEN_FAILURE_THRESHOLD,
        );
        $consecutiveSuccesses = AreaFocusScalarNormalizer::nonNegativeInt($probe['consecutive_successes'] ?? 0);

        if ($circuitStateIn !== 'half_open') {
            return $this->buildResult(
                $circuitStateIn,
                $probeFailures,
                $reopenFailureThreshold,
                $consecutiveSuccesses,
                false,
                $circuitStateIn,
                'not_half_open',
            );
        }

        if ($probeFailures >= $reopenFailureThreshold) {
            return $this->buildResult(
                $circuitStateIn,
                $probeFailures,
                $reopenFailureThreshold,
                $consecutiveSuccesses,
                true,
                'open',
                'reopened_after_probe_failure_threshold',
            );
        }

        if ($probeFailures === 0) {
            return $this->buildResult(
                $circuitStateIn,
                $probeFailures,
                $reopenFailureThreshold,
                $consecutiveSuccesses,
                false,
                'half_open',
                'probe_succeeding_stay_half_open',
            );
        }

        if ($probeFailures === 1) {
            return $this->buildResult(
                $circuitStateIn,
                $probeFailures,
                $reopenFailureThreshold,
                $consecutiveSuccesses,
                false,
                'half_open',
                'single_transient_tolerated_stay_half_open',
            );
        }

        return $this->buildResult(
            $circuitStateIn,
            $probeFailures,
            $reopenFailureThreshold,
            $consecutiveSuccesses,
            false,
            'half_open',
            'insufficient_probe_failures',
        );
    }

    /**
     * @return array{
     *     schema_version: string,
     *     circuit_state_in: string,
     *     probe_failures: int,
     *     reopen_failure_threshold: int,
     *     consecutive_successes: int,
     *     reopen: bool,
     *     decided_state: string,
     *     reason: string
     * }
     */
    private function buildResult(
        string $circuitStateIn,
        int $probeFailures,
        int $reopenFailureThreshold,
        int $consecutiveSuccesses,
        bool $reopen,
        string $decidedState,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'circuit_state_in' => $circuitStateIn,
            'probe_failures' => $probeFailures,
            'reopen_failure_threshold' => $reopenFailureThreshold,
            'consecutive_successes' => $consecutiveSuccesses,
            'reopen' => $reopen,
            'decided_state' => $decidedState,
            'reason' => $reason,
        ];
    }
}
