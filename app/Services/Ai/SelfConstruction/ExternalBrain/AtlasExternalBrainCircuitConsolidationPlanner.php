<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure proof-backed task-graph gate. Turns compression candidates (e.g. from
 * {@see AtlasExternalBrainArchitectureCompressionPlanner}) into an ordered consolidation
 * circuit so destructive simplification NEVER runs before the behavior it touches is
 * locked down and proven.
 *
 * WAVE ORDER (fixed, always in this sequence):
 *   1. behavior_lock   — every destructive candidate first gets a behavior-lock task that
 *                         pins current behavior with a runnable proof (test/receipt).
 *   2. consolidate     — merge candidates only: the actual consolidation of organs into one.
 *   3. delete_or_merge — the destructive action itself (delete, merge, or simplify).
 *   4. knowledge_sync  — only emitted when the candidate declares required_tests or an
 *                         explicit requires_knowledge_sync flag; keeps KB/code-intelligence
 *                         from drifting from the new shape.
 *
 * Only candidates whose action is merge, delete, or simplify are ever planned — a 'keep'
 * candidate has nothing destructive to gate and is skipped entirely.
 *
 * DEPENDENCY PRESERVATION: every task's depends_on set names the task ids from strictly
 * earlier waves that must complete first. execute always depends on its behavior_lock (and
 * consolidate, for merges); knowledge_sync always depends on execute. No task in a later
 * wave is ever emitted without its upstream lock/proof task already present in the graph.
 *
 * Pure. No I/O, no provider calls, deterministic on identical input.
 */
final class AtlasExternalBrainCircuitConsolidationPlanner
{
    public const SCHEMA = 'atlas.external_brain.circuit_consolidation_planner.v1';

    public const WAVE_BEHAVIOR_LOCK   = 'behavior_lock';
    public const WAVE_CONSOLIDATE     = 'consolidate';
    public const WAVE_DELETE_OR_MERGE = 'delete_or_merge';
    public const WAVE_KNOWLEDGE_SYNC  = 'knowledge_sync';

    private const WAVE_SEQUENCE = [
        self::WAVE_BEHAVIOR_LOCK,
        self::WAVE_CONSOLIDATE,
        self::WAVE_DELETE_OR_MERGE,
        self::WAVE_KNOWLEDGE_SYNC,
    ];

    /** @var list<string> */
    private const DESTRUCTIVE_ACTIONS = ['merge', 'delete', 'simplify'];

    /**
     * @param  array{candidates?: list<array<string,mixed>>}  $input
     * @return array{schema:string, waves:list<array{wave:string, tasks:list<string>}>, tasks:array<string,array{wave:string, depends_on:list<string>, candidate_id:string}>, plan_hash:string}
     */
    public function plan(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];

        $tasks       = [];
        $waveBuckets = array_fill_keys(self::WAVE_SEQUENCE, []);

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $candidateId = (string) ($candidate['candidate_id'] ?? '');
            $action      = (string) ($candidate['action'] ?? '');
            if ($candidateId === '' || ! in_array($action, self::DESTRUCTIVE_ACTIONS, true)) {
                continue;
            }

            $lockTaskId = 'lock:'.$candidateId;
            $tasks[$lockTaskId] = [
                'wave'         => self::WAVE_BEHAVIOR_LOCK,
                'depends_on'   => [],
                'candidate_id' => $candidateId,
            ];
            $waveBuckets[self::WAVE_BEHAVIOR_LOCK][] = $lockTaskId;

            $upstream = [$lockTaskId];

            if ($action === 'merge') {
                $consolidateTaskId = 'consolidate:'.$candidateId;
                $tasks[$consolidateTaskId] = [
                    'wave'         => self::WAVE_CONSOLIDATE,
                    'depends_on'   => [$lockTaskId],
                    'candidate_id' => $candidateId,
                ];
                $waveBuckets[self::WAVE_CONSOLIDATE][] = $consolidateTaskId;
                $upstream[] = $consolidateTaskId;
            }

            $executeTaskId = 'execute:'.$candidateId;
            $tasks[$executeTaskId] = [
                'wave'         => self::WAVE_DELETE_OR_MERGE,
                'depends_on'   => $upstream,
                'candidate_id' => $candidateId,
            ];
            $waveBuckets[self::WAVE_DELETE_OR_MERGE][] = $executeTaskId;

            $requiredTests = array_values((array) ($candidate['required_tests'] ?? []));
            $requiresSync  = (bool) ($candidate['requires_knowledge_sync'] ?? false) || $requiredTests !== [];
            if ($requiresSync) {
                $syncTaskId = 'knowledge_sync:'.$candidateId;
                $tasks[$syncTaskId] = [
                    'wave'         => self::WAVE_KNOWLEDGE_SYNC,
                    'depends_on'   => [$executeTaskId],
                    'candidate_id' => $candidateId,
                ];
                $waveBuckets[self::WAVE_KNOWLEDGE_SYNC][] = $syncTaskId;
            }
        }

        $waves = [];
        foreach (self::WAVE_SEQUENCE as $waveName) {
            if ($waveBuckets[$waveName] === []) {
                continue;
            }
            $waves[] = ['wave' => $waveName, 'tasks' => $waveBuckets[$waveName]];
        }

        $payload = [
            'schema' => self::SCHEMA,
            'waves'  => $waves,
            'tasks'  => $tasks,
        ];
        $payload['plan_hash'] = 'circuit_'.substr(hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32);

        return $payload;
    }
}
