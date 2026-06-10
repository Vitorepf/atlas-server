<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Gates the L7 self-evolving rung on a stable Trust Ledger window.
 *
 * Mirrors the canonical Trust Ledger model (atlas-trust-ledger-canonical.md):
 * success event kinds raise trust, adverse kinds lower it, and the score is a
 * bounded trust ratio over the supplied evidence window. On top of the base
 * ledger this gate enforces two S93 honesty penalties — a provider invocation
 * with no receipt, and a merge that was not truthful (a false merge) — and only
 * admits L7 when the trust score reaches the hard 0.95 gate over a window that
 * carries no penalties and no signature breach.
 *
 * Pure: every returned field is computed from the events argument. No I/O,
 * clock, randomness or external state.
 */
final class TrustLedgerStabilityGate
{
    private const SCHEMA_VERSION = 'atlas.trust_ledger.stability_gate.v1';

    /**
     * Hard L7 trust gate. Trust >= 0.95 is required (a hard gate); 0.949 blocks.
     */
    private const TRUST_GATE_THRESHOLD = 0.95;

    /**
     * Fixed trust cost charged per honesty penalty, on top of its adverse mass.
     */
    private const PENALTY_COST = 0.10;

    /**
     * Minimum number of events for the window to be considered a stable window.
     */
    private const MIN_STABLE_EVENTS = 3;

    private const PENALTY_PROVIDER_INVOKED_WITHOUT_RECEIPT = 'provider_invoked_without_receipt';

    private const PENALTY_FALSE_MERGE = 'false_merge';

    /**
     * Success event kinds (sign +1) mirrored from the canonical weight table.
     *
     * @var array<string, float>
     */
    private const SUCCESS_WEIGHTS = [
        'cert_pass' => 1.0,
        'promotion' => 0.5,
        'self_construction_approved' => 1.5,
        'gate_pass' => 0.2,
        'incident_resolved' => 0.8,
    ];

    /**
     * Adverse event kinds (sign -1) mirrored from the canonical weight table.
     *
     * @var array<string, float>
     */
    private const ADVERSE_WEIGHTS = [
        'cert_fail' => 1.5,
        'demote' => 1.0,
        'signature_breach' => 3.0,
        'gate_fail' => 0.5,
        'drift_detected' => 0.5,
    ];

    /**
     * Adverse kinds that, on their own, break window stability regardless of score.
     *
     * @var list<string>
     */
    private const DESTABILIZING_KINDS = [
        'signature_breach',
    ];

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{
     *     schema_version: string,
     *     score: float,
     *     window_start: string,
     *     window_end: string,
     *     penalties: list<string>,
     *     stable: bool,
     *     passed: bool,
     *     threshold: float
     * }
     */
    public function evaluate(array $events): array
    {
        $penalties = [];
        $successMass = 0.0;
        $adverseMass = 0.0;
        $windowStart = '';
        $windowEnd = '';
        $eventCount = 0;
        $hasDestabilizingEvent = false;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $eventCount++;

            $kind = AreaFocusScalarNormalizer::payloadRawString($event, 'kind');
            $weight = $this->weightFor($kind, $event);

            $at = AreaFocusScalarNormalizer::payloadRawString($event, 'at');
            if ($at !== '') {
                if ($windowStart === '' || $at < $windowStart) {
                    $windowStart = $at;
                }
                if ($windowEnd === '' || $at > $windowEnd) {
                    $windowEnd = $at;
                }
            }

            if ($this->isProviderInvokedWithoutReceipt($kind, $event)) {
                $penalties[] = self::PENALTY_PROVIDER_INVOKED_WITHOUT_RECEIPT;
                $adverseMass += $weight;

                continue;
            }

            if ($this->isFalseMerge($kind, $event)) {
                $penalties[] = self::PENALTY_FALSE_MERGE;
                $adverseMass += $weight;

                continue;
            }

            if (in_array($kind, self::DESTABILIZING_KINDS, true)) {
                $hasDestabilizingEvent = true;
            }

            if ($this->isSuccessKind($kind)) {
                $successMass += $weight;

                continue;
            }

            // Any non-success contribution lowers trust.
            $adverseMass += $weight;
        }

        $score = $this->computeScore($successMass, $adverseMass, count($penalties));

        $stable = $penalties === []
            && ! $hasDestabilizingEvent
            && $eventCount >= self::MIN_STABLE_EVENTS;

        $passed = $score >= self::TRUST_GATE_THRESHOLD && $stable;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $score,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'penalties' => array_values($penalties),
            'stable' => $stable,
            'passed' => $passed,
            'threshold' => self::TRUST_GATE_THRESHOLD,
        ];
    }

    /**
     * Trust ratio over the window, decayed by the fixed cost of each penalty,
     * clamped to the inclusive 0..1 bound.
     */
    private function computeScore(float $successMass, float $adverseMass, int $penaltyCount): float
    {
        $totalMass = $successMass + $adverseMass;

        $base = $totalMass > 0.0
            ? $successMass / $totalMass
            : 0.0;

        $score = $base - ((float) $penaltyCount * self::PENALTY_COST);

        return $this->clampUnit($score);
    }

    /**
     * Magnitude an event contributes to the window. The canonical event schema
     * carries its own `weight`; when present it is authoritative (the kind only
     * fixes the sign). Otherwise the canonical per-kind weight table is used,
     * falling back to unit weight.
     */
    private function weightFor(string $kind, array $event): float
    {
        $weight = $event['weight'] ?? null;

        if (is_int($weight) || is_float($weight)) {
            return abs((float) $weight);
        }

        if (array_key_exists($kind, self::SUCCESS_WEIGHTS)) {
            return self::SUCCESS_WEIGHTS[$kind];
        }

        if (array_key_exists($kind, self::ADVERSE_WEIGHTS)) {
            return self::ADVERSE_WEIGHTS[$kind];
        }

        return 1.0;
    }

    private function isSuccessKind(string $kind): bool
    {
        return array_key_exists($kind, self::SUCCESS_WEIGHTS);
    }

    private function isProviderInvokedWithoutReceipt(string $kind, array $event): bool
    {
        if ($kind !== 'provider_invoked') {
            return false;
        }

        return ! $this->hasReceipt($event);
    }

    private function hasReceipt(array $event): bool
    {
        if (($event['has_receipt'] ?? null) === true) {
            return true;
        }

        $receipt = $event['receipt'] ?? null;

        if (is_string($receipt)) {
            return trim($receipt) !== '';
        }

        if (is_array($receipt)) {
            return $receipt !== [];
        }

        return false;
    }

    private function isFalseMerge(string $kind, array $event): bool
    {
        if ($kind !== 'merge') {
            return false;
        }

        if (($event['truthful'] ?? null) === false) {
            return true;
        }

        return ($event['false_merge'] ?? null) === true;
    }

    private function clampUnit(float $value): float
    {
        // A NAN ratio (e.g. when overflowing event masses make total INF, so
        // success/total is INF/INF) escapes both bound checks below — every NAN
        // comparison is false — and would leak a non-[0,1] score past the clamp.
        // NAN is not a valid trust ratio: collapse it to the safe floor so the
        // documented inclusive 0..1 bound holds and the hard gate stays
        // fail-closed (0.0 can never satisfy the >= 0.95 gate).
        if (is_nan($value)) {
            return 0.0;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}
