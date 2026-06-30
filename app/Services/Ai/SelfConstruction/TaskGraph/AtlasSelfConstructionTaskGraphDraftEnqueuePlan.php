<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskGraph;

/**
 * Deterministic enqueue plan builder. Pure, facts-only.
 *
 * Converts valid task-graph drafts into `prepareAndEnqueue` inputs:
 *   { task_packet: <draft minus queue keys>, queue: { priority, tags, metadata } }
 *
 * Composes {@see AtlasSelfConstructionTaskGraphDraftQualityGate} to filter drafts.
 * NEVER writes to the queue.
 *
 * Inputs:
 *   $drafts     — list<array<string,mixed>> from the missing-organ planner
 *   $queueFacts — { known_packet_ids?:list<string>, existing_packet_ids?:list<string> }
 *
 * Output:
 *   {
 *     schema_version, status, enqueue_inputs, withheld, duplicates,
 *     counts:{drafts, enqueue_inputs, withheld, duplicates}, plan_hash
 *   }
 *
 * Preserves: depends_on, wave, tags, priority, allowed_files, required_evidence.
 */
final class AtlasSelfConstructionTaskGraphDraftEnqueuePlan
{
    public const SCHEMA = 'atlas.self_construction.task_graph_draft_enqueue_plan.v1';

    public function __construct(
        private readonly ?AtlasSelfConstructionTaskGraphDraftQualityGate $gate = null,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $drafts
     * @param  array<string,mixed>  $queueFacts
     * @return array<string,mixed>
     */
    public function plan(array $drafts, array $queueFacts = []): array
    {
        $gate = $this->gate ?? new AtlasSelfConstructionTaskGraphDraftQualityGate();

        $existing = array_flip(array_values(array_map('strval', (array) ($queueFacts['existing_packet_ids'] ?? []))));
        // All IDs in this batch — used for dependency-wave resolution.
        $batchIds = array_flip(array_values(array_filter(
            array_map(static fn (array $d): string => (string) ($d['task_packet_id'] ?? ''), $drafts),
            static fn (string $id): bool => $id !== '',
        )));
        $claimedFiles = []; // collision-risk tracking: files claimed by earlier drafts in this run

        $enqueueNow = [];
        $defer      = [];
        $reject     = [];

        foreach ($drafts as $draft) {
            $id = (string) ($draft['task_packet_id'] ?? '');
            if ($id === '') {
                $reject[] = ['task_packet_id' => '', 'reason' => 'task_packet_id_missing', 'blockers' => ['task_packet_id_missing']];

                continue;
            }
            if (isset($existing[$id])) {
                $defer[] = ['task_packet_id' => $id, 'reason' => 'already_in_queue'];

                continue;
            }

            // Dependency wave: prerequisites not in queue or batch → defer.
            $depsOn      = array_values(array_filter(array_map('strval', (array) ($draft['depends_on'] ?? [])), static fn (string $s): bool => $s !== ''));
            $blockedDeps = array_values(array_filter($depsOn, static fn (string $dep): bool => ! isset($existing[$dep]) && ! isset($batchIds[$dep])));
            if ($blockedDeps !== []) {
                $defer[] = ['task_packet_id' => $id, 'reason' => 'blocked_prerequisites', 'blockers' => $blockedDeps];

                continue;
            }

            // Collision risk: duplicate allowed_files across drafts in this batch → defer.
            $files      = array_values(array_filter(array_map('strval', (array) ($draft['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
            $collisions = array_values(array_filter($files, static fn (string $f): bool => isset($claimedFiles[$f])));
            if ($collisions !== []) {
                $defer[] = ['task_packet_id' => $id, 'reason' => 'duplicate_allowed_files', 'blockers' => $collisions];

                continue;
            }

            // Queue pressure: capacity reached → defer.
            if ((bool) ($queueFacts['queue_at_capacity'] ?? false)) {
                $defer[] = ['task_packet_id' => $id, 'reason' => 'queue_at_capacity'];

                continue;
            }

            // Evidence readiness via quality gate → reject (permanent, not recoverable by re-scheduling).
            $verdict = $gate->evaluate($draft, $queueFacts);
            if (! (bool) $verdict['passed']) {
                $reject[] = [
                    'task_packet_id' => $id,
                    'reason'         => 'quality_gate_blocked',
                    'blockers'       => array_values((array) $verdict['blockers']),
                ];

                continue;
            }

            foreach ($files as $f) {
                $claimedFiles[$f] = true;
            }
            $enqueueNow[] = $this->makeEnqueueInput($draft);
        }

        $sortById = static fn (array $a, array $b): int => strcmp((string) $a['task_packet_id'], (string) $b['task_packet_id']);
        usort($enqueueNow, static fn (array $a, array $b): int => strcmp((string) $a['task_packet']['task_packet_id'], (string) $b['task_packet']['task_packet_id']));
        usort($defer,      $sortById);
        usort($reject,     $sortById);

        // Backward-compat aliases.
        $duplicates = array_values(array_filter($defer, static fn (array $d): bool => $d['reason'] === 'already_in_queue'));

        $counts = [
            'drafts'         => count($drafts),
            'enqueue_now'    => count($enqueueNow),
            'defer'          => count($defer),
            'reject'         => count($reject),
            // legacy keys
            'enqueue_inputs' => count($enqueueNow),
            'withheld'       => count($reject),
            'duplicates'     => count($duplicates),
        ];

        $planHash = $this->planHash($enqueueNow, $defer, $reject);

        return [
            'schema'         => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status'         => 'ok',
            'enqueue_now'    => $enqueueNow,
            'defer'          => $defer,
            'reject'         => $reject,
            // legacy keys
            'enqueue_inputs' => $enqueueNow,
            'withheld'       => $reject,
            'duplicates'     => $duplicates,
            'counts'         => $counts,
            'plan_hash'      => $planHash,
        ];
    }

    /**
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    private function makeEnqueueInput(array $draft): array
    {
        $packet = $draft;
        $priority = (int) ($packet['priority'] ?? 5);
        $tags = array_values(array_map('strval', (array) ($packet['tags'] ?? [])));
        $wave = (string) ($packet['wave'] ?? '');
        $metadata = is_array($packet['metadata'] ?? null) ? $packet['metadata'] : [];
        if ($wave !== '') {
            $metadata['wave'] = $wave;
        }
        $rationale = (string) ($packet['rationale'] ?? '');
        if ($rationale !== '') {
            $metadata['rationale'] = $rationale;
        }

        // Keep depends_on, allowed_files, required_evidence, wave, tags, priority INSIDE the packet
        // (do not strip them) — Task Fabric reads them. But remove the queue-only `metadata` mirror
        // from the inner packet to avoid drift; the queue block carries it.
        unset($packet['metadata']);

        return [
            'task_packet' => $packet,
            'queue' => [
                'priority' => $priority,
                'tags' => $tags,
                'metadata' => $metadata,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $enqueueNow
     * @param  list<array<string,mixed>>  $defer
     * @param  list<array<string,mixed>>  $reject
     */
    private function planHash(array $enqueueNow, array $defer, array $reject): string
    {
        $canonical = json_encode([
            'enqueue_now' => $enqueueNow,
            'defer'       => $defer,
            'reject'      => $reject,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'plan_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
