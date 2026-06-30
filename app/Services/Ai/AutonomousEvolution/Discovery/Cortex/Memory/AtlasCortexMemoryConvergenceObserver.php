<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

/**
 * Reads the episodic ledger and emits the FACT of which intent_interpretations have STABILIZED — i.e.
 * the canonical_form for that intent_id has been byte-identical for the last K consecutive episodes.
 *
 * INVARIANTS:
 *   - NO opinion on which canonical_form is "better"; only the FACT of stability.
 *   - A flip of canonical_form RESETS the stability run to 1 (starting at the flipping cycle).
 *   - Output sort: (stable_runs desc, last_seen_cycle_id desc, intent_id asc).
 *   - Deterministic: identical ledger ⇒ byte-identical output across calls.
 */
final class AtlasCortexMemoryConvergenceObserver
{
    public function __construct(private readonly AtlasCortexMemoryEpisodicLedger $ledger) {}

    /**
     * @return list<array{intent_id:string, stable_runs:int, canonical_form:string, first_stable_cycle_id:string, last_seen_cycle_id:string}>
     */
    public function stabilizedIntents(int $minStableRuns, ?int $sinceUnix = null): array
    {
        $episodes = iterator_to_array($this->ledger->iterate(limit: null, sinceUnix: $sinceUnix), false);

        // Per intent_id, track the CURRENT canonical_form streak.
        $current = []; // intent_id => {canonical_form, stable_runs, first_stable_cycle_id, last_seen_cycle_id}

        foreach ($episodes as $episode) {
            $cycleId = (string) ($episode['cycle_id'] ?? '');
            $seenThisCycle = [];

            foreach ((array) ($episode['intent_interpretations'] ?? []) as $intent) {
                if (! is_array($intent)) {
                    continue;
                }
                $intentId = (string) ($intent['intent_id'] ?? '');
                $canonical = (string) ($intent['canonical_form'] ?? '');
                if ($intentId === '') {
                    continue;
                }

                $seenThisCycle[$intentId] = true;

                if (isset($current[$intentId]) && $current[$intentId]['canonical_form'] === $canonical) {
                    $current[$intentId]['stable_runs']++;
                    $current[$intentId]['last_seen_cycle_id'] = $cycleId;
                } else {
                    // Flip (or first observation) ⇒ start a fresh streak of 1 from THIS cycle.
                    $current[$intentId] = [
                        'canonical_form' => $canonical,
                        'stable_runs' => 1,
                        'first_stable_cycle_id' => $cycleId,
                        'last_seen_cycle_id' => $cycleId,
                    ];
                }
            }

            // K-consecutive invariant: absence in a cycle breaks the streak.
            foreach (array_keys($current) as $intentId) {
                if (! isset($seenThisCycle[$intentId])) {
                    unset($current[$intentId]);
                }
            }
        }

        $out = [];
        foreach ($current as $intentId => $streak) {
            if ($streak['stable_runs'] >= $minStableRuns) {
                $out[] = [
                    'intent_id' => $intentId,
                    'stable_runs' => $streak['stable_runs'],
                    'canonical_form' => $streak['canonical_form'],
                    'first_stable_cycle_id' => $streak['first_stable_cycle_id'],
                    'last_seen_cycle_id' => $streak['last_seen_cycle_id'],
                ];
            }
        }
        usort($out, static function (array $a, array $b): int {
            return $b['stable_runs'] <=> $a['stable_runs']
                ?: strcmp($b['last_seen_cycle_id'], $a['last_seen_cycle_id'])
                ?: strcmp($a['intent_id'], $b['intent_id']);
        });

        return $out;
    }
}
