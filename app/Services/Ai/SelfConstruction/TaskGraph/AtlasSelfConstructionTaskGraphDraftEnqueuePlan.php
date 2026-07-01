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

        // AC3: a dependent is only claimable once its prerequisite is actually resolved (already
        // queued, or itself accepted into enqueue_now this run) — NOT merely "present somewhere in
        // the submitted batch" regardless of that prerequisite's own outcome. $resolved starts as
        // the existing queue and grows every time a draft is accepted below.
        $resolved = $existing;

        $pending = [];
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
            $pending[] = $draft;
        }

        // Fixpoint pass: a draft whose in-batch prerequisite hasn't resolved YET (still pending,
        // not yet decided) waits for a later pass; one whose prerequisite is truly absent, or was
        // itself rejected/deferred (never resolved), is blocked for good once no more progress
        // is possible in a pass.
        $progress = true;
        while ($progress && $pending !== []) {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $draft) {
                $id     = (string) ($draft['task_packet_id'] ?? '');
                $depsOn = array_values(array_filter(array_map('strval', (array) ($draft['depends_on'] ?? [])), static fn (string $s): bool => $s !== ''));

                $unresolvedInBatch = array_values(array_filter($depsOn, static fn (string $dep): bool => ! isset($resolved[$dep]) && isset($batchIds[$dep])));
                if ($unresolvedInBatch !== []) {
                    // Prerequisite is still undecided in this batch — retry next pass.
                    $stillPending[] = $draft;

                    continue;
                }

                $trulyMissing = array_values(array_filter($depsOn, static fn (string $dep): bool => ! isset($resolved[$dep]) && ! isset($batchIds[$dep])));
                if ($trulyMissing !== []) {
                    $defer[] = ['task_packet_id' => $id, 'reason' => 'blocked_prerequisites', 'blockers' => $trulyMissing];
                    $progress = true;

                    continue;
                }

                // Collision risk: duplicate allowed_files across drafts in this batch → defer.
                $files      = array_values(array_filter(array_map('strval', (array) ($draft['allowed_files'] ?? [])), static fn (string $f): bool => $f !== ''));
                $collisions = array_values(array_filter($files, static fn (string $f): bool => isset($claimedFiles[$f])));
                if ($collisions !== []) {
                    $defer[] = ['task_packet_id' => $id, 'reason' => 'duplicate_allowed_files', 'blockers' => $collisions];
                    $progress = true;

                    continue;
                }

                // Queue pressure: capacity reached → defer.
                if ((bool) ($queueFacts['queue_at_capacity'] ?? false)) {
                    $defer[] = ['task_packet_id' => $id, 'reason' => 'queue_at_capacity'];
                    $progress = true;

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
                    $progress = true;

                    continue;
                }

                foreach ($files as $f) {
                    $claimedFiles[$f] = true;
                }
                $enqueueNow[] = $this->makeEnqueueInput($draft);
                $resolved[$id] = true;
                $progress = true;
            }

            $pending = $stillPending;
        }

        // Fixpoint stabilized with drafts still waiting → their in-batch prerequisite never
        // resolved (it was itself rejected/deferred, or there's a dependency cycle) — not
        // claimable, so the dependent is blocked rather than silently enqueued.
        foreach ($pending as $draft) {
            $id        = (string) ($draft['task_packet_id'] ?? '');
            $depsOn    = array_values(array_filter(array_map('strval', (array) ($draft['depends_on'] ?? [])), static fn (string $s): bool => $s !== ''));
            $unresolved = array_values(array_filter($depsOn, static fn (string $dep): bool => ! isset($resolved[$dep])));
            $defer[] = ['task_packet_id' => $id, 'reason' => 'blocked_prerequisites', 'blockers' => $unresolved];
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

        [$chains, $roleByTaskId] = $this->buildChains($drafts, $batchIds);
        $this->applyChainRoles($enqueueNow, $roleByTaskId, static fn (array $entry): string => (string) $entry['task_packet']['task_packet_id']);
        $this->applyChainRoles($defer, $roleByTaskId, static fn (array $entry): string => (string) $entry['task_packet_id']);
        $this->applyChainRoles($reject, $roleByTaskId, static fn (array $entry): string => (string) $entry['task_packet_id']);

        $chainedIds    = array_keys($roleByTaskId);
        $enqueuedIds   = array_column(array_column($enqueueNow, 'task_packet'), 'task_packet_id');
        $chainSummary  = [
            'chains'                    => count($chains),
            'chained_task_ids'         => $chainedIds,
            'standalone_task_ids'      => array_values(array_diff(array_keys($batchIds), $chainedIds)),
            'enqueued_chained_count'   => count(array_intersect($enqueuedIds, $chainedIds)),
            'enqueued_standalone_count' => count(array_diff($enqueuedIds, $chainedIds)),
        ];

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
            'chains'         => $chains,
            'chain_summary'  => $chainSummary,
        ];
    }

    /**
     * AC2: groups drafts sharing a within-batch depends_on relationship into chains, and labels
     * each member's role:
     *   prerequisite — nothing above it in the chain; something in-batch depends on it.
     *   unlock       — depends on something in-batch AND something in-batch depends on it.
     *   terminal     — depends on something in-batch; nothing further depends on it.
     * A draft with no in-batch dependency relationship at all (no dependents, no in-batch
     * dependencies) is standalone — not part of any chain, no role.
     *
     * @param  list<array<string,mixed>>  $drafts
     * @param  array<string,int>  $batchIds
     * @return array{0:list<array<string,mixed>>,1:array<string,string>} [chains, roleByTaskId]
     */
    private function buildChains(array $drafts, array $batchIds): array
    {
        $dependsOnMap = []; // id => list<in-batch dependency id>
        $dependentsOf = []; // id => list<in-batch dependent id>

        foreach ($drafts as $draft) {
            $id = (string) ($draft['task_packet_id'] ?? '');
            if ($id === '' || ! isset($batchIds[$id])) {
                continue;
            }
            $deps = array_values(array_filter(
                array_map('strval', (array) ($draft['depends_on'] ?? [])),
                static fn (string $dep): bool => $dep !== '' && isset($batchIds[$dep]),
            ));
            $dependsOnMap[$id] = $deps;
            foreach ($deps as $dep) {
                $dependentsOf[$dep][] = $id;
            }
        }

        $roleByTaskId = [];
        foreach (array_keys($batchIds) as $id) {
            $hasDeps       = ($dependsOnMap[$id] ?? []) !== [];
            $hasDependents = ($dependentsOf[$id] ?? []) !== [];
            if (! $hasDeps && ! $hasDependents) {
                continue; // standalone — no chain role
            }
            $roleByTaskId[$id] = match (true) {
                $hasDeps && $hasDependents => 'unlock',
                ! $hasDeps && $hasDependents => 'prerequisite',
                default => 'terminal',
            };
        }

        // Connected components (undirected) over ids that have at least one chain relationship.
        $parent = array_combine(array_keys($roleByTaskId), array_keys($roleByTaskId));
        $find = function (string $x) use (&$parent, &$find): string {
            if ($parent[$x] !== $x) {
                $parent[$x] = $find($parent[$x]);
            }

            return $parent[$x];
        };
        foreach ($dependsOnMap as $id => $deps) {
            if (! isset($roleByTaskId[$id])) {
                continue;
            }
            foreach ($deps as $dep) {
                if (! isset($roleByTaskId[$dep])) {
                    continue;
                }
                $rootA = $find($id);
                $rootB = $find($dep);
                if ($rootA !== $rootB) {
                    $parent[$rootA] = $rootB;
                }
            }
        }

        $groups = [];
        foreach (array_keys($roleByTaskId) as $id) {
            $groups[$find($id)][] = $id;
        }

        $chains = [];
        foreach (array_values($groups) as $members) {
            sort($members, SORT_STRING);
            $chains[] = [
                'members' => array_map(static fn (string $id) => ['task_packet_id' => $id, 'chain_role' => $roleByTaskId[$id]], $members),
            ];
        }
        usort($chains, static fn (array $a, array $b): int => strcmp((string) $a['members'][0]['task_packet_id'], (string) $b['members'][0]['task_packet_id']));

        return [$chains, $roleByTaskId];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @param  array<string,string>  $roleByTaskId
     * @param  callable(array<string,mixed>):string  $idExtractor
     */
    private function applyChainRoles(array &$entries, array $roleByTaskId, callable $idExtractor): void
    {
        foreach ($entries as &$entry) {
            $entry['chain_role'] = $roleByTaskId[$idExtractor($entry)] ?? null;
        }
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
