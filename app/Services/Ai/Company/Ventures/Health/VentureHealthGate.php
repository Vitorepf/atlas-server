<?php

namespace App\Services\Ai\Company\Ventures\Health;

/**
 * K4 — the multi-signal AND-gate (Atlas Phase 0 keystone).
 *
 * A venture is HEALTHY only when ALL required signals are green. Any RED →
 * failed_by_<signal>. Any UNKNOWN (or missing) → blocked (can't certify) but
 * NOT failed. Fail-closed: a signal not supplied is treated as unknown, never
 * assumed green. Pure / no-I/O / deterministic.
 */
class VentureHealthGate
{
    /** The required signals; a missing one is unknown (fail-closed). */
    public const REQUIRED = ['solvency', 'churn', 'concentration', 'legality', 'deliverability'];

    public const VERDICT_HEALTHY = 'healthy';

    public const VERDICT_BLOCKED_RED = 'blocked_red';

    public const VERDICT_BLOCKED_UNKNOWN = 'blocked_unknown';

    /**
     * @param  array<string,HealthSignalState|string>  $signals
     * @return array{verdict:string, succeeded_eligible:bool, red:array<int,string>, unknown:array<int,string>}
     */
    public function evaluate(array $signals): array
    {
        $red = [];
        $unknown = [];

        foreach (self::REQUIRED as $name) {
            $state = $this->normalize($signals[$name] ?? null);
            if ($state === HealthSignalState::RED) {
                $red[] = $name;
            } elseif ($state === HealthSignalState::UNKNOWN) {
                $unknown[] = $name; // includes missing signals — fail-closed
            }
        }

        if ($red !== []) {
            $verdict = self::VERDICT_BLOCKED_RED;
        } elseif ($unknown !== []) {
            $verdict = self::VERDICT_BLOCKED_UNKNOWN;
        } else {
            $verdict = self::VERDICT_HEALTHY;
        }

        return [
            'verdict' => $verdict,
            'succeeded_eligible' => $verdict === self::VERDICT_HEALTHY,
            'red' => $red,
            'unknown' => $unknown,
        ];
    }

    /**
     * Monotonic-downgrade composition: never returns a state better than either
     * input (red beats unknown beats green). Used so a re-evaluation can only
     * hold or worsen a venture's standing, never silently upgrade it.
     */
    public function downgradeOnly(HealthSignalState $previous, HealthSignalState $current): HealthSignalState
    {
        return $current->severity() >= $previous->severity() ? $current : $previous;
    }

    private function normalize(HealthSignalState|string|null $value): HealthSignalState
    {
        if ($value instanceof HealthSignalState) {
            return $value;
        }
        if ($value === null) {
            return HealthSignalState::UNKNOWN; // fail-closed: missing = unknown
        }

        return HealthSignalState::tryFrom($value) ?? HealthSignalState::UNKNOWN;
    }
}
