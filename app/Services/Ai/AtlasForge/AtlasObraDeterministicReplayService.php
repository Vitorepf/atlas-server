<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasForge;

/**
 * Atlas Obra Deterministic Replay (AP-705 / T2.5).
 *
 * Pure replay evaluator. Input is an ORDERED list of events:
 *   - phase envelopes (schema `atlas.aaeos.phase.v1`),
 *   - decision receipts (schema `atlas.decision_receipt.v2` or any
 *     decision-like envelope with `decision_id` field).
 *
 * Output is a canonical `atlas.obra.replay.v1` snapshot:
 *   - `events`:           the ordered events (echoed back for audit)
 *   - `phases_executed`:  unique list of phases that emitted envelopes
 *   - `decisions`:        decision_id → list of source event indices
 *   - `state_at_index`:   running snapshot of phase + blocker state
 *   - `final_state`:      the state at the last event
 *   - `replay_hash`:      deterministic sha256 of the reconstruction
 *
 * Idempotent: replay(replay(events)) === replay(events) (the service
 * does not mutate inputs and produces the same hash for identical input).
 *
 * The service does NOT query the Evidence Ledger. Callers query the
 * ledger and pass events to this service. This keeps replay testable
 * and independent of any storage backend.
 */
final class AtlasObraDeterministicReplayService
{
    public const SCHEMA_VERSION = 'atlas.obra.replay.v1';

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public function replay(array $events): array
    {
        $events = array_values($events);
        $phasesExecuted = [];
        $blockersOpen = [];
        $stateAtIndex = [];
        $decisions = [];

        foreach ($events as $idx => $event) {
            $phaseOut = (string) ($event['phase_out'] ?? '');
            if ($phaseOut !== '' && ! in_array($phaseOut, $phasesExecuted, true)) {
                $phasesExecuted[] = $phaseOut;
            }

            foreach ((array) ($event['blockers'] ?? []) as $blocker) {
                $id = is_array($blocker) ? (string) ($blocker['id'] ?? '') : (string) $blocker;
                if ($id !== '' && ! in_array($id, $blockersOpen, true)) {
                    $blockersOpen[] = $id;
                }
            }

            // A "decision" may live in `decision_id` or be implied by a
            // receipt-style envelope. We capture both.
            $decisionId = (string) ($event['decision_id'] ?? '');
            if ($decisionId !== '') {
                if (! isset($decisions[$decisionId])) {
                    $decisions[$decisionId] = [];
                }
                $decisions[$decisionId][] = $idx;
            }
            // Phase envelopes that include a decision receipt also count.
            $receiptId = (string) ($event['receipt_id'] ?? '');
            if ($receiptId !== '') {
                if (! isset($decisions[$receiptId])) {
                    $decisions[$receiptId] = [];
                }
                $decisions[$receiptId][] = $idx;
            }

            $stateAtIndex[$idx] = [
                'phases_executed' => $phasesExecuted,
                'blockers_open' => $blockersOpen,
                'last_phase_out' => $phaseOut,
            ];
        }

        $finalState = $stateAtIndex !== [] ? end($stateAtIndex) : [
            'phases_executed' => [],
            'blockers_open' => [],
            'last_phase_out' => '',
        ];

        $envelope = [
            'schema' => self::SCHEMA_VERSION,
            'events' => $events,
            'phases_executed' => $phasesExecuted,
            'decisions' => $decisions,
            'state_at_index' => $stateAtIndex,
            'final_state' => $finalState,
            'counts' => [
                'events' => count($events),
                'phases' => count($phasesExecuted),
                'decisions' => count($decisions),
                'blockers_open_at_end' => count($blockersOpen),
            ],
        ];
        $envelope['replay_hash'] = 'sha256:'.hash(
            'sha256',
            json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        );

        return $envelope;
    }

    /**
     * Trace a specific decision back to its source events. Returns an
     * empty list when the decision id is not present in the replay.
     *
     * @param  list<array<string,mixed>>  $events
     * @return list<array<string,mixed>>
     */
    public function lineage(array $events, string $decisionId): array
    {
        $replay = $this->replay($events);
        $indices = $replay['decisions'][$decisionId] ?? [];
        $lineage = [];
        foreach ($indices as $i) {
            if (isset($events[$i])) {
                $lineage[] = ['event_index' => $i, 'event' => $events[$i]];
            }
        }

        return $lineage;
    }
}
