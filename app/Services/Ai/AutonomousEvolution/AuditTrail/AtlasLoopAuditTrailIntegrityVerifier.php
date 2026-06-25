<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\AuditTrail;

/**
 * Pure verifier for receipt chain integrity across sub-ledgers. FACT-only: never repairs, never
 * writes — only reports anomalies through {@see IntegrityReport}.
 *
 * Event row contract (each event is an associative array):
 *   {
 *     event_id:        int|string  (monotonic within source_ledger)
 *     source_ledger:   string      (e.g. 'projection_outcome', 'cycle_git_contract', 'impact_receipt')
 *     prev_hash:       ?string     (content_hash of the prior event in the same source_ledger)
 *     content_hash:    string      (sha256 over canonical facts payload)
 *     facts:           array<string,mixed>  (canonical payload that content_hash MUST cover)
 *     refs:            list<int|string>     (optional cross-ledger references to other event_ids)
 *   }
 */
final class AtlasLoopAuditTrailIntegrityVerifier
{
    /**
     * Verify a "timeline window" — accepts either a list of event rows or an object exposing
     * events(): iterable<array>. NEVER mutates the events or any ledger.
     *
     * @param  iterable<array<string,mixed>>|object  $window
     */
    public function verify($window): IntegrityReport
    {
        $events = $this->collect($window);
        $anomalies = [];

        // 1. Detect SEQUENCE_GAP per source_ledger.
        $byLedger = $this->groupBySourceLedger($events);
        foreach ($byLedger as $ledger => $ledgerEvents) {
            $anomalies = array_merge($anomalies, $this->detectSequenceGaps($ledger, $ledgerEvents));
        }

        // 2. Recompute content_hash; mismatch is BROKEN_LINK (cascade).
        foreach ($byLedger as $ledger => $ledgerEvents) {
            $anomalies = array_merge($anomalies, $this->detectBrokenLinks($ledger, $ledgerEvents));
        }

        // 3. Cross-ledger ORPHAN_REF: any refs[] pointing to event_id not in the window.
        $knownIds = [];
        foreach ($events as $e) {
            $knownIds[(string) ($e['event_id'] ?? '')] = true;
        }
        foreach ($events as $e) {
            $refs = (array) ($e['refs'] ?? []);
            foreach ($refs as $r) {
                $key = (string) $r;
                if ($key === '' || isset($knownIds[$key])) {
                    continue;
                }
                $anomalies[] = [
                    'type' => IntegrityReport::ANOMALY_ORPHAN_REF,
                    'source_ledger' => (string) ($e['source_ledger'] ?? ''),
                    'event_id' => $e['event_id'] ?? null,
                    'detail' => 'orphan_ref:'.$key,
                    'orphan_target' => $r,
                ];
            }
        }

        return new IntegrityReport($anomalies);
    }

    /**
     * @param  iterable<array<string,mixed>>|object  $window
     * @return list<array<string,mixed>>
     */
    private function collect($window): array
    {
        if (is_object($window) && method_exists($window, 'events')) {
            $iter = $window->events();
        } else {
            $iter = $window;
        }
        $out = [];
        foreach ($iter as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string, list<array<string,mixed>>>
     */
    private function groupBySourceLedger(array $events): array
    {
        $grouped = [];
        foreach ($events as $e) {
            $ledger = (string) ($e['source_ledger'] ?? '');
            $grouped[$ledger] ??= [];
            $grouped[$ledger][] = $e;
        }
        foreach ($grouped as &$rows) {
            usort($rows, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);
        }
        unset($rows);

        return $grouped;
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    private function detectSequenceGaps(string $ledger, array $events): array
    {
        if ($events === []) {
            return [];
        }
        $anomalies = [];
        $expected = null;
        foreach ($events as $e) {
            $id = $e['event_id'] ?? null;
            if (! is_int($id)) {
                continue;
            }
            if ($expected === null) {
                $expected = $id;
            }
            if ($id !== $expected) {
                // Report the missing sequence id(s) between $expected..$id-1.
                for ($missing = $expected; $missing < $id; $missing++) {
                    $anomalies[] = [
                        'type' => IntegrityReport::ANOMALY_SEQUENCE_GAP,
                        'source_ledger' => $ledger,
                        'event_id' => $missing,
                        'detail' => 'missing_event_id:'.$missing,
                    ];
                }
                $expected = $id;
            }
            $expected++;
        }

        return $anomalies;
    }

    /**
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    private function detectBrokenLinks(string $ledger, array $events): array
    {
        $anomalies = [];
        $priorHash = null;
        $cascadeFrom = null;
        $affected = [];

        foreach ($events as $e) {
            $facts = is_array($e['facts'] ?? null) ? $e['facts'] : [];
            $recomputed = $this->canonicalHash($facts);
            $stated = (string) ($e['content_hash'] ?? '');
            $prev = $e['prev_hash'] ?? null;

            $stale = $stated !== $recomputed;
            $linkBroken = $priorHash !== null && $prev !== null && (string) $prev !== (string) $priorHash;

            if ($cascadeFrom !== null) {
                // Once tamper detected, every downstream event in the same ledger is affected.
                $affected[] = $e['event_id'] ?? null;
            } elseif ($stale || $linkBroken) {
                $cascadeFrom = $e['event_id'] ?? null;
                $affected[] = $e['event_id'] ?? null;
            }

            // Advance the chain using whatever the event CLAIMS (not the recomputed) — this is what
            // downstream events were chained against, so the cascade keeps following the claim.
            $priorHash = $stated;
        }

        if ($cascadeFrom !== null) {
            $anomalies[] = [
                'type' => IntegrityReport::ANOMALY_BROKEN_LINK,
                'source_ledger' => $ledger,
                'event_id' => $cascadeFrom,
                'detail' => 'cascade_from:'.(string) $cascadeFrom,
                'affected_event_ids' => $affected,
            ];
        }

        return $anomalies;
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function canonicalHash(array $facts): string
    {
        $this->ksortRecursive($facts);

        return hash('sha256', (string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }
}
