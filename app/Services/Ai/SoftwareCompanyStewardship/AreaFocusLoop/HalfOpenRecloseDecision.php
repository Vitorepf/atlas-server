<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class HalfOpenRecloseDecision
{
    private const SCHEMA_VERSION = 'atlas.software_company_stewardship.half_open_reclose_decision.v1';

    private const DEFAULT_REQUIRED_SUCCESSES = 2;

    private const MIN_REQUIRED_SUCCESSES = 2;

    /**
     * @param  array<string, mixed>  $probe
     * @return array{
     *     schema_version: string,
     *     circuit_state_in: string,
     *     consecutive_successes: int,
     *     required_successes: int,
     *     probe_failures: int,
     *     reclose: bool,
     *     decided_state: string,
     *     reason: string
     * }
     */
    public function decide(array $probe): array
    {
        $circuitStateIn = $this->normalizeCircuitState($probe);
        $consecutiveSuccesses = $this->normalizeNonNegativeInt($probe['consecutive_successes'] ?? 0);
        $requiredSuccesses = $this->normalizeRequiredSuccesses($probe['required_successes'] ?? null);
        $probeFailures = $this->normalizeNonNegativeInt($probe['probe_failures'] ?? 0);

        if ($circuitStateIn !== 'half_open') {
            return $this->buildResult(
                $circuitStateIn,
                $consecutiveSuccesses,
                $requiredSuccesses,
                $probeFailures,
                false,
                $circuitStateIn,
                'not_half_open',
            );
        }

        if ($probeFailures > 0) {
            return $this->buildResult(
                $circuitStateIn,
                $consecutiveSuccesses,
                $requiredSuccesses,
                $probeFailures,
                false,
                'half_open',
                'probe_failure_blocks_reclose',
            );
        }

        if ($consecutiveSuccesses < $requiredSuccesses) {
            return $this->buildResult(
                $circuitStateIn,
                $consecutiveSuccesses,
                $requiredSuccesses,
                $probeFailures,
                false,
                'half_open',
                'insufficient_consecutive_successes',
            );
        }

        return $this->buildResult(
            $circuitStateIn,
            $consecutiveSuccesses,
            $requiredSuccesses,
            $probeFailures,
            true,
            'closed',
            'reclosed_after_required_consecutive_successes',
        );
    }

    /**
     * @param  array<string, mixed>  $probe
     */
    private function normalizeCircuitState(array $probe): string
    {
        $raw = $probe['circuit_state_in']
            ?? $probe['circuit_state']
            ?? $probe['state']
            ?? '';

        $value = strtolower(trim((string) $raw));

        return match ($value) {
            'half_open', 'half-open', 'probing' => 'half_open',
            'open', 'circuit_open', 'tripped' => 'open',
            'closed', 'ok', 'healthy' => 'closed',
            default => $value === '' ? 'open' : $value,
        };
    }

    private function normalizeRequiredSuccesses(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_REQUIRED_SUCCESSES;
        }

        $required = (int) $value;

        if ($required < self::MIN_REQUIRED_SUCCESSES) {
            return self::MIN_REQUIRED_SUCCESSES;
        }

        return $required;
    }

    private function normalizeNonNegativeInt(mixed $value): int
    {
        $normalized = (int) $value;

        return $normalized < 0 ? 0 : $normalized;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     circuit_state_in: string,
     *     consecutive_successes: int,
     *     required_successes: int,
     *     probe_failures: int,
     *     reclose: bool,
     *     decided_state: string,
     *     reason: string
     * }
     */
    private function buildResult(
        string $circuitStateIn,
        int $consecutiveSuccesses,
        int $requiredSuccesses,
        int $probeFailures,
        bool $reclose,
        string $decidedState,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'circuit_state_in' => $circuitStateIn,
            'consecutive_successes' => $consecutiveSuccesses,
            'required_successes' => $requiredSuccesses,
            'probe_failures' => $probeFailures,
            'reclose' => $reclose,
            'decided_state' => $decidedState,
            'reason' => $reason,
        ];
    }
}
