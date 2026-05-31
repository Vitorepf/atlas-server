<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class OpenCircuitProbeAdmissionDecision
{
    private const SCHEMA_VERSION = 'atlas.software_company_stewardship.open_circuit_probe_admission.v1';

    private const DEFAULT_COOLDOWN_SECONDS = 60;

    private const MIN_COOLDOWN_SECONDS = 1;

    /**
     * @param  array<string, mixed>  $breaker
     * @return array{
     *     schema_version: string,
     *     circuit_state_in: string,
     *     seconds_since_open: int,
     *     cooldown_seconds: int,
     *     seconds_remaining: int,
     *     admit_probe: bool,
     *     decided_state: string,
     *     reason: string
     * }
     */
    public function decide(array $breaker): array
    {
        $circuitStateIn = $this->normalizeCircuitState($breaker);
        $secondsSinceOpen = $this->normalizeNonNegativeInt($breaker['seconds_since_open'] ?? 0);
        $cooldownSeconds = $this->normalizeCooldownSeconds($breaker['cooldown_seconds'] ?? null);

        if ($circuitStateIn === 'closed') {
            return $this->buildResult(
                $circuitStateIn,
                $secondsSinceOpen,
                $cooldownSeconds,
                $this->secondsRemaining($secondsSinceOpen, $cooldownSeconds),
                false,
                'closed',
                'not_open',
            );
        }

        if ($circuitStateIn === 'half_open') {
            return $this->buildResult(
                $circuitStateIn,
                $secondsSinceOpen,
                $cooldownSeconds,
                $this->secondsRemaining($secondsSinceOpen, $cooldownSeconds),
                false,
                'half_open',
                'already_half_open',
            );
        }

        if ($circuitStateIn !== 'open') {
            return $this->buildResult(
                $circuitStateIn,
                $secondsSinceOpen,
                $cooldownSeconds,
                $this->secondsRemaining($secondsSinceOpen, $cooldownSeconds),
                false,
                $circuitStateIn,
                'not_open',
            );
        }

        if ($secondsSinceOpen >= $cooldownSeconds) {
            return $this->buildResult(
                $circuitStateIn,
                $secondsSinceOpen,
                $cooldownSeconds,
                0,
                true,
                'half_open',
                'cooldown_elapsed_admit_probe',
            );
        }

        return $this->buildResult(
            $circuitStateIn,
            $secondsSinceOpen,
            $cooldownSeconds,
            $this->secondsRemaining($secondsSinceOpen, $cooldownSeconds),
            false,
            'open',
            'cooldown_not_elapsed_stay_open',
        );
    }

    /**
     * @param  array<string, mixed>  $breaker
     */
    private function normalizeCircuitState(array $breaker): string
    {
        $raw = $breaker['circuit_state_in']
            ?? $breaker['circuit_state']
            ?? $breaker['state']
            ?? '';

        $value = strtolower(trim((string) $raw));

        return match ($value) {
            'half_open', 'half-open', 'probing' => 'half_open',
            'open', 'circuit_open', 'tripped' => 'open',
            'closed', 'ok', 'healthy' => 'closed',
            default => $value === '' ? 'open' : $value,
        };
    }

    private function normalizeCooldownSeconds(mixed $value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_COOLDOWN_SECONDS;
        }

        $cooldown = (int) $value;

        if ($cooldown < self::MIN_COOLDOWN_SECONDS) {
            return self::MIN_COOLDOWN_SECONDS;
        }

        return $cooldown;
    }

    private function normalizeNonNegativeInt(mixed $value): int
    {
        $normalized = (int) $value;

        return $normalized < 0 ? 0 : $normalized;
    }

    private function secondsRemaining(int $secondsSinceOpen, int $cooldownSeconds): int
    {
        $remaining = $cooldownSeconds - $secondsSinceOpen;

        return $remaining < 0 ? 0 : $remaining;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     circuit_state_in: string,
     *     seconds_since_open: int,
     *     cooldown_seconds: int,
     *     seconds_remaining: int,
     *     admit_probe: bool,
     *     decided_state: string,
     *     reason: string
     * }
     */
    private function buildResult(
        string $circuitStateIn,
        int $secondsSinceOpen,
        int $cooldownSeconds,
        int $secondsRemaining,
        bool $admitProbe,
        string $decidedState,
        string $reason,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'circuit_state_in' => $circuitStateIn,
            'seconds_since_open' => $secondsSinceOpen,
            'cooldown_seconds' => $cooldownSeconds,
            'seconds_remaining' => $secondsRemaining,
            'admit_probe' => $admitProbe,
            'decided_state' => $decidedState,
            'reason' => $reason,
        ];
    }
}
