<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Autonomy;

/**
 * Deterministic Self-Construction autonomy degradation policy.
 *
 * Maps observed degradation facts to a SAFE downgrade / pause / repair / rollback decision.
 * NEVER widens scope and NEVER relaxes gates when degradation evidence is present.
 *
 * Inputs (all booleans / counters; injection-only):
 *   - false_green_detected            : ledger or verifier says a recent green was a false positive
 *   - rollback_failure_detected       : a rollback attempt failed to complete
 *   - repeated_poison_packet_count    : how many times the same poison-shaped packet was re-served
 *   - missing_evidence_detected       : evidence ledger fact required by a finished step is absent
 *   - queue_jam_detected              : queue health says replenisher/serving is jammed
 *   - cost_or_risk_breach_detected    : cost/risk budget breached (token spend, blast radius, etc.)
 *
 * Outputs a deterministic verdict envelope with action + reason; precedence is fixed.
 */
final class AtlasSelfConstructionAutonomyDegradationPolicy
{
    public const SCHEMA = 'atlas.self_construction.autonomy_degradation_policy.v1';

    public const ACTION_NO_ACTION = 'no_action';

    public const ACTION_PAUSE = 'pause';

    public const ACTION_DOWNGRADE = 'downgrade';

    public const ACTION_REPAIR_FIRST = 'repair_first';

    public const ACTION_ROLLBACK_REQUIRED = 'rollback_required';

    public const REASON_FALSE_GREEN = 'false_green_detected';

    public const REASON_ROLLBACK_FAILURE = 'rollback_failure_detected';

    public const REASON_REPEATED_POISON = 'repeated_poison_packet_threshold_reached';

    public const REASON_MISSING_EVIDENCE = 'missing_evidence_detected';

    public const REASON_QUEUE_JAM = 'queue_jam_detected';

    public const REASON_COST_OR_RISK_BREACH = 'cost_or_risk_breach_detected';

    public const REPEATED_POISON_THRESHOLD = 3;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function decide(array $facts): array
    {
        // Fixed precedence — most severe first.
        if ($this->bool($facts, 'rollback_failure_detected')) {
            return $this->envelope(
                self::ACTION_PAUSE,
                self::REASON_ROLLBACK_FAILURE,
                $facts,
                widensScope: false,
                relaxesGates: false,
            );
        }
        if ($this->bool($facts, 'false_green_detected')) {
            return $this->envelope(
                self::ACTION_ROLLBACK_REQUIRED,
                self::REASON_FALSE_GREEN,
                $facts,
                widensScope: false,
                relaxesGates: false,
            );
        }
        if ($this->bool($facts, 'cost_or_risk_breach_detected')) {
            return $this->envelope(
                self::ACTION_DOWNGRADE,
                self::REASON_COST_OR_RISK_BREACH,
                $facts,
                widensScope: false,
                relaxesGates: false,
            );
        }
        if ($this->bool($facts, 'missing_evidence_detected')) {
            return $this->envelope(
                self::ACTION_DOWNGRADE,
                self::REASON_MISSING_EVIDENCE,
                $facts,
                widensScope: false,
                relaxesGates: false,
            );
        }
        if (((int) ($facts['repeated_poison_packet_count'] ?? 0)) >= self::REPEATED_POISON_THRESHOLD) {
            return $this->envelope(
                self::ACTION_REPAIR_FIRST,
                self::REASON_REPEATED_POISON,
                $facts,
                widensScope: false,
                relaxesGates: false,
            );
        }
        if ($this->bool($facts, 'queue_jam_detected')) {
            return $this->envelope(
                self::ACTION_REPAIR_FIRST,
                self::REASON_QUEUE_JAM,
                $facts,
                widensScope: false,
                relaxesGates: false,
            );
        }

        return $this->envelope(
            self::ACTION_NO_ACTION,
            'no_degradation_signal',
            $facts,
            widensScope: false,
            relaxesGates: false,
        );
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function bool(array $facts, string $key): bool
    {
        return (bool) ($facts[$key] ?? false);
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function envelope(
        string $action,
        string $reason,
        array $facts,
        bool $widensScope,
        bool $relaxesGates,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'action' => $action,
            'reason' => $reason,
            'widens_scope' => $widensScope,
            'relaxes_gates' => $relaxesGates,
            'observed_facts' => [
                'false_green_detected' => $this->bool($facts, 'false_green_detected'),
                'rollback_failure_detected' => $this->bool($facts, 'rollback_failure_detected'),
                'repeated_poison_packet_count' => (int) ($facts['repeated_poison_packet_count'] ?? 0),
                'missing_evidence_detected' => $this->bool($facts, 'missing_evidence_detected'),
                'queue_jam_detected' => $this->bool($facts, 'queue_jam_detected'),
                'cost_or_risk_breach_detected' => $this->bool($facts, 'cost_or_risk_breach_detected'),
            ],
        ];
    }
}
