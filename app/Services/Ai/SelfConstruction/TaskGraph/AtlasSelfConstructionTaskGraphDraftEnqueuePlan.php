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

        $enqueueInputs = [];
        $withheld = [];
        $duplicates = [];

        foreach ($drafts as $draft) {
            $id = (string) ($draft['task_packet_id'] ?? '');
            if ($id === '') {
                $withheld[] = ['task_packet_id' => '', 'reason' => 'task_packet_id_missing', 'blockers' => ['task_packet_id_missing']];

                continue;
            }
            if (isset($existing[$id])) {
                $duplicates[] = ['task_packet_id' => $id, 'reason' => 'already_in_queue'];

                continue;
            }

            $verdict = $gate->evaluate($draft, $queueFacts);
            if (! (bool) $verdict['passed']) {
                $withheld[] = [
                    'task_packet_id' => $id,
                    'reason' => 'quality_gate_blocked',
                    'blockers' => array_values((array) $verdict['blockers']),
                ];

                continue;
            }

            $enqueueInputs[] = $this->makeEnqueueInput($draft);
        }

        // Deterministic ordering.
        usort($enqueueInputs, static fn (array $a, array $b): int => strcmp((string) $a['task_packet']['task_packet_id'], (string) $b['task_packet']['task_packet_id']));
        usort($withheld, static fn (array $a, array $b): int => strcmp((string) $a['task_packet_id'], (string) $b['task_packet_id']));
        usort($duplicates, static fn (array $a, array $b): int => strcmp((string) $a['task_packet_id'], (string) $b['task_packet_id']));

        $counts = [
            'drafts' => count($drafts),
            'enqueue_inputs' => count($enqueueInputs),
            'withheld' => count($withheld),
            'duplicates' => count($duplicates),
        ];

        $planHash = $this->planHash($enqueueInputs, $withheld, $duplicates);

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => 'ok',
            'enqueue_inputs' => $enqueueInputs,
            'withheld' => $withheld,
            'duplicates' => $duplicates,
            'counts' => $counts,
            'plan_hash' => $planHash,
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
     * @param  list<array<string,mixed>>  $enqueueInputs
     * @param  list<array<string,mixed>>  $withheld
     * @param  list<array<string,mixed>>  $duplicates
     */
    private function planHash(array $enqueueInputs, array $withheld, array $duplicates): string
    {
        $canonical = json_encode([
            'enqueue_inputs' => $enqueueInputs,
            'withheld' => $withheld,
            'duplicates' => $duplicates,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'plan_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
