<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure closed-loop snapshot. Connects the seven External Brain organ FACTS the caller supplies
 * — context_intake, leverage_ranking, task_fabric, queue_self_healing, outcome_learning,
 * simplification — into one machine-readable contract, instead of each organ shipping as a
 * disconnected report only a human stitches together.
 *
 * Input shape:
 *   { sections: {
 *       context_intake?:      array{ready?:bool, ...},
 *       leverage_ranking?:    array{ready?:bool, top_candidate?:string, ...},
 *       task_fabric?:         array{ready?:bool, ...},
 *       queue_self_healing?:  array{ready?:bool, needs_repair?:bool, ...},
 *       outcome_learning?:    array{ready?:bool, ...},
 *       simplification?:      array{ready?:bool, has_approved_candidates?:bool, ...},
 *   } }
 *
 * A section absent from the input, or explicitly not ready, is an integration gap — the snapshot
 * never silently reports ready=true over a missing/broken link. next_action is derived from the
 * live section states (never a fixed constant): the first integration gap wins; with every
 * section present and ready, an urgent queue-repair signal, an approved simplification
 * candidate, or a ranked leverage candidate each route to a distinct action before falling back
 * to waiting for a new signal.
 *
 * Pure: no I/O, no provider calls, no queue/DB access — callers inject already-computed organ
 * facts and act on the returned snapshot.
 */
final class AtlasExternalBrainAutonomousSpine
{
    public const SCHEMA = 'atlas.self_construction.external_brain.autonomous_spine.v1';

    /** @var list<string> */
    private const REQUIRED_SECTIONS = [
        'context_intake',
        'leverage_ranking',
        'task_fabric',
        'queue_self_healing',
        'outcome_learning',
        'simplification',
    ];

    /**
     * @param  array{sections?: array<string, array<string,mixed>>}  $facts
     * @return array<string,mixed>
     */
    public function compile(array $facts): array
    {
        $sections = is_array($facts['sections'] ?? null) ? $facts['sections'] : [];

        $states = [];
        $integrationGaps = [];

        foreach (self::REQUIRED_SECTIONS as $name) {
            $raw = is_array($sections[$name] ?? null) ? $sections[$name] : null;

            if ($raw === null) {
                $states[$name] = ['present' => false, 'ready' => false];
                $integrationGaps[] = "{$name}_missing";

                continue;
            }

            $ready = (bool) ($raw['ready'] ?? false);
            $states[$name] = array_merge($raw, ['present' => true, 'ready' => $ready]);

            if (! $ready) {
                $integrationGaps[] = "{$name}_not_ready";
            }
        }

        $ready = $integrationGaps === [];
        $nextAction = $this->deriveNextAction($states, $integrationGaps);

        return array_merge(
            [
                'schema' => self::SCHEMA,
                'ready' => $ready,
                'integration_gaps' => $integrationGaps,
            ],
            $states,
            ['next_action' => $nextAction],
        );
    }

    /**
     * @param  array<string, array<string,mixed>>  $states
     * @param  list<string>  $integrationGaps
     */
    private function deriveNextAction(array $states, array $integrationGaps): string
    {
        // A broken/missing link always wins — nothing downstream can be trusted until it closes.
        if ($integrationGaps !== []) {
            return 'close_integration_gap:'.$integrationGaps[0];
        }

        if ((bool) ($states['queue_self_healing']['needs_repair'] ?? false)) {
            return 'repair_queue';
        }

        if ((bool) ($states['simplification']['has_approved_candidates'] ?? false)) {
            return 'execute_simplification';
        }

        $topCandidate = trim((string) ($states['leverage_ranking']['top_candidate'] ?? ''));
        if ($topCandidate !== '') {
            return 'create_task:'.$topCandidate;
        }

        return 'wait_for_new_signal';
    }
}
