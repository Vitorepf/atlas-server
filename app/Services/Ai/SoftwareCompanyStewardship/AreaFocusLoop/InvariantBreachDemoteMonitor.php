<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S94 — InvariantBreachDemoteMonitor (L7 Runtime Completion).
 *
 * Blocks or demotes L7 on any sacred invariant breach. The monitor is pure:
 * it derives every field from the supplied invariant records and ladder state
 * with no IO, clock, randomness or provider calls.
 *
 * Doctrine: invariant beats score. A single breach blocks promotion and forces
 * an automatic demote from L7 to L6, even when the Trust Ledger score is at or
 * above the 0.95 gate — the score can never override a breached invariant.
 */
final class InvariantBreachDemoteMonitor
{
    private const SCHEMA_VERSION = 'atlas.loop.invariant_breach_demote_monitor.v1';

    /**
     * Hard Trust Ledger gate documented in the autonomy-ladder runbook (L6 -> L7
     * requires Trust >= 0.95). Mirrored here only to assert that the score never
     * suppresses an automatic demote; it is not a promotion authority by itself.
     */
    private const TRUST_GATE = 0.95;

    /**
     * @param  list<array<string,mixed>>|array<int|string,array<string,mixed>>  $invariants
     * @param  array<string,mixed>  $state
     * @return array{
     *     schema_version: string,
     *     invariant_breach_count: int,
     *     breached_invariants: list<string>,
     *     demote_required: bool,
     *     promotion_blocked: bool,
     *     current_level: string,
     *     demote_to_level: string,
     *     trust_ledger_score: float,
     *     trust_gate_satisfied: bool,
     *     blockers: list<string>
     * }
     */
    public function evaluate(array $invariants, array $state): array
    {
        $breached = $this->breachedInvariantNames($invariants);
        $breachCount = count($breached);
        $demoteRequired = $breachCount >= 1;

        $currentLevel = $this->normaliseLevel($state['current_level'] ?? ($state['level'] ?? 'L7'));
        $demoteToLevel = $demoteRequired
            ? $this->oneLevelDown($currentLevel)
            : $currentLevel;

        $trustScore = AreaFocusScalarNormalizer::payloadFloat($state, 'trust_ledger_score', 0.0);
        $trustGateSatisfied = $trustScore >= self::TRUST_GATE;

        $blockers = [];
        foreach ($breached as $name) {
            $blockers[] = 'sacred_invariant_breached:'.$name;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'invariant_breach_count' => $breachCount,
            'breached_invariants' => $breached,
            'demote_required' => $demoteRequired,
            'promotion_blocked' => $demoteRequired,
            'current_level' => $currentLevel,
            'demote_to_level' => $demoteToLevel,
            'trust_ledger_score' => $trustScore,
            'trust_gate_satisfied' => $trustGateSatisfied,
            'blockers' => $blockers,
        ];
    }

    /**
     * Extract the ordered, de-duplicated names of breached invariants as a
     * strict list<string>. A record counts as breached when it is explicitly
     * flagged (breached/ok/status/passing) as failing.
     *
     * @param  array<int|string,array<string,mixed>>  $invariants
     * @return list<string>
     */
    private function breachedInvariantNames(array $invariants): array
    {
        $names = [];

        foreach ($invariants as $key => $invariant) {
            if (! is_array($invariant)) {
                continue;
            }

            if (! $this->isBreached($invariant)) {
                continue;
            }

            $name = $this->invariantName($invariant, $key);

            if ($name === '' || in_array($name, $names, true)) {
                continue;
            }

            $names[] = $name;
        }

        // Re-index defensively so the contract stays a list<string> even if a
        // caller passed an associative map of invariants.
        return array_values($names);
    }

    /**
     * @param  array<string,mixed>  $invariant
     */
    private function isBreached(array $invariant): bool
    {
        if (array_key_exists('breached', $invariant) && $invariant['breached'] === true) {
            return true;
        }

        if (array_key_exists('passing', $invariant) && $invariant['passing'] === false) {
            return true;
        }

        if (array_key_exists('ok', $invariant) && $invariant['ok'] === false) {
            return true;
        }

        if (array_key_exists('status', $invariant)) {
            return in_array(
                strtolower(trim((string) $invariant['status'])),
                ['breached', 'fail', 'failed', 'violation', 'violated'],
                true,
            );
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $invariant
     */
    private function invariantName(array $invariant, int|string $fallbackKey): string
    {
        foreach (['id', 'name', 'invariant', 'key'] as $field) {
            if (isset($invariant[$field]) && (is_string($invariant[$field]) || is_int($invariant[$field]))) {
                $candidate = trim((string) $invariant[$field]);

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return is_string($fallbackKey) ? $fallbackKey : (string) $fallbackKey;
    }

    private function normaliseLevel(mixed $level): string
    {
        if (is_int($level)) {
            return 'L'.max(0, $level);
        }

        $raw = strtoupper(trim((string) $level));

        if ($raw === '') {
            return 'L7';
        }

        if (preg_match('/^L(\d+)$/', $raw, $matches) === 1) {
            return 'L'.(int) $matches[1];
        }

        if (preg_match('/^\d+$/', $raw) === 1) {
            return 'L'.(int) $raw;
        }

        return $raw;
    }

    private function oneLevelDown(string $level): string
    {
        if (preg_match('/^L(\d+)$/', $level, $matches) === 1) {
            $current = (int) $matches[1];

            return 'L'.max(0, $current - 1);
        }

        return $level;
    }
}
