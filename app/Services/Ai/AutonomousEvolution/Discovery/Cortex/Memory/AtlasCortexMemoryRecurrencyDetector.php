<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

/**
 * Reads the episodic ledger and emits the FACT of which inventory items remained byte-identical
 * (item_id, kind, fingerprint) across N consecutive episodes in (captured_at, cycle_id) order.
 *
 * INVARIANTS:
 *   - NO score, NO ranking by importance, NO opinion — pure recurrence FACT.
 *   - A gap (item disappears for one cycle) RESETS the run for that item.
 *   - Output ordering: (run_length desc, last_seen_cycle_id desc, item_id asc) — deterministic.
 *   - Pure: identical ledger input ⇒ byte-identical json_encode output (no hidden state).
 */
final class AtlasCortexMemoryRecurrencyDetector
{
    public function __construct(private readonly AtlasCortexMemoryEpisodicLedger $ledger) {}

    /**
     * @return list<array{item_id:string, kind:string, run_length:int, first_seen_cycle_id:string, last_seen_cycle_id:string, fingerprint:string}>
     */
    public function recurrentItems(int $minRuns, ?int $sinceUnix = null): array
    {
        $episodes = iterator_to_array($this->ledger->iterate(limit: null, sinceUnix: $sinceUnix), false);
        // Episodes come in (captured_at, cycle_id) order from the ledger.

        // Track ACTIVE (uninterrupted-up-to-this-cycle) runs per identity-key. A gap resets the run.
        // Identity-key = item_id + "\0" + kind + "\0" + fingerprint.
        $current = []; // key => {item_id, kind, fingerprint, run_length, first_seen_cycle_id, last_seen_cycle_id}

        foreach ($episodes as $episode) {
            $cycleId = (string) ($episode['cycle_id'] ?? '');
            $seenThisCycle = [];

            foreach ((array) ($episode['inventory_items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = (string) ($item['item_id'] ?? '');
                $kind = (string) ($item['kind'] ?? '');
                $fp = (string) ($item['fingerprint'] ?? '');
                if ($itemId === '') {
                    continue;
                }
                $key = $itemId."\0".$kind."\0".$fp;
                $seenThisCycle[$key] = true;

                if (isset($current[$key])) {
                    $current[$key]['run_length']++;
                    $current[$key]['last_seen_cycle_id'] = $cycleId;
                } else {
                    $current[$key] = [
                        'item_id' => $itemId,
                        'kind' => $kind,
                        'fingerprint' => $fp,
                        'run_length' => 1,
                        'first_seen_cycle_id' => $cycleId,
                        'last_seen_cycle_id' => $cycleId,
                    ];
                }
            }

            // Any keys not seen this cycle ⇒ their run is broken; reset.
            foreach (array_keys($current) as $key) {
                if (! isset($seenThisCycle[$key])) {
                    unset($current[$key]);
                }
            }
        }

        // Only items whose ACTIVE run at end-of-stream meets minRuns count as recurrent.
        $out = [];
        foreach ($current as $entry) {
            if ($entry['run_length'] >= $minRuns) {
                $out[] = [
                    'item_id' => $entry['item_id'],
                    'kind' => $entry['kind'],
                    'run_length' => $entry['run_length'],
                    'first_seen_cycle_id' => $entry['first_seen_cycle_id'],
                    'last_seen_cycle_id' => $entry['last_seen_cycle_id'],
                    'fingerprint' => $entry['fingerprint'],
                ];
            }
        }
        usort($out, static function (array $a, array $b): int {
            return $b['run_length'] <=> $a['run_length']
                ?: strcmp($b['last_seen_cycle_id'], $a['last_seen_cycle_id'])
                ?: strcmp($a['item_id'], $b['item_id']);
        });

        return $out;
    }
}
