<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the first persistent runtime layer of the Agent Control
 * Plane: Task Packet Builder → Scope Lock Runtime Validator → Task Packet
 * Queue Repository → Claim/Lease Repository → Evidence Ledger Dry-Run →
 * Continuation Summary Builder. Every step still writes only to local
 * storage; no provider call, no dispatch, no real completion.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneTaskQueueOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_queue_orchestrator.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_queue_orchestrator';

    public function __construct(
        private readonly AgentControlPlaneTaskPacketBuilder $builder,
        private readonly AgentControlPlaneScopeLockRuntimeValidator $validator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
        private readonly AgentControlPlaneEvidenceLedgerDryRun $evidence,
        private readonly AgentControlPlaneContinuationSummaryBuilder $continuation,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function prepareAndEnqueue(array $input): array
    {
        $packetInput = (array) ($input['task_packet'] ?? $input);
        $queueOptions = (array) ($input['queue'] ?? []);
        $validatorOptions = (array) ($input['validator'] ?? []);

        $packet = $this->builder->build($packetInput);
        $validation = $this->validator->validate($packet, $validatorOptions);

        if ((string) ($packet['status'] ?? '') !== 'planned' || (string) ($validation['status'] ?? '') !== 'valid') {
            return $this->envelope('prepare_blocked', [
                'task_packet' => $packet,
                'validation' => $validation,
                'queue_entry' => null,
                'evidence_plan' => null,
                'continuation_summary' => null,
                'reason' => 'task_packet_or_validation_blocked',
            ]);
        }

        $quality = (new AtlasTaskPacketQualityInspector)->inspect($packet);
        if (! (bool) ($quality['self_sufficient'] ?? false)) {
            return $this->envelope('prepare_blocked', [
                'task_packet' => $packet,
                'validation' => $validation,
                'packet_quality' => $quality,
                'queue_entry' => null,
                'evidence_plan' => null,
                'continuation_summary' => null,
                'reason' => 'task_packet_not_self_sufficient',
            ]);
        }

        $enqueueResult = $this->queue->enqueue($packet, [
            'metadata' => [
                'scope_lock_hash' => (string) $validation['scope_lock_hash'],
                'validation_hash' => (string) $validation['validation_hash'],
                // ORDER: a task is only servable once every depends_on task is completed; wave is a human-readable
                // ordering hint (the version-ladder phase). The serving enforces depends_on at claim time.
                'depends_on' => array_values(array_filter((array) data_get($packetInput, 'depends_on', []), 'is_string')),
                'wave' => (int) data_get($packetInput, 'wave', 0),
            ],
            'priority' => (int) ($queueOptions['priority'] ?? 5),
            'tags' => (array) ($queueOptions['tags'] ?? []),
        ]);

        $evidencePlan = $this->evidence->plan($packet, [
            'write_set' => (array) $validation['normalized_scope_lock']['write_set'],
            'read_set' => (array) $validation['normalized_scope_lock']['read_set'],
            'scope_lock_plan_hash' => (string) $validation['scope_lock_hash'],
            'blocking_reasons' => [],
        ]);
        $continuation = $this->continuation->build($packet, $evidencePlan);

        // Attach planning receipts to the queue record.
        $taskPacketId = (string) data_get($enqueueResult, 'task_packet_id', '');
        if ($taskPacketId !== '' && (string) $enqueueResult['status'] === 'ok') {
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'scope_lock_runtime_validated',
                'scope_lock_hash' => (string) $validation['scope_lock_hash'],
                'validation_hash' => (string) $validation['validation_hash'],
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'evidence_plan_prepared',
                'evidence_hash' => (string) $evidencePlan['evidence_hash'],
                'evidence_plan_hash' => (string) $evidencePlan['evidence_plan_hash'],
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'continuation_summary_prepared',
                'continuation_hash' => (string) $continuation['continuation_hash'],
            ]);
        }

        return $this->envelope('prepared_and_enqueued', [
            'task_packet' => $packet,
            'validation' => $validation,
            'queue_entry' => $enqueueResult,
            'evidence_plan' => $evidencePlan,
            'continuation_summary' => $continuation,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function claimNext(string $agentId, array $filters = []): array
    {
        if ($agentId === '') {
            return $this->envelope('claim_blocked', ['reason' => 'agent_id_missing']);
        }

        // A3/MF-05: reclaim stranded leases BEFORE listing, so a task stranded by an expired lease, an orphaned
        // claim, OR a client give-back (status `released`) is visible (and serveable) again this very call —
        // R1/R2 recovery on the hot path, not just by the scheduled reaper. Best-effort: a hiccup in recovery
        // never blocks a claim.
        $this->reapExpiredBeforeListing();

        $candidates = $this->queue->list(array_merge(['status' => 'claimable'], $filters));
        // ORDER (soft): serve lower waves first so the version-ladder advances v1 → v2 → v3 in sequence. This is
        // a stable preference, not a hard gate (depends_on is the hard gate); a stable sort preserves the prior
        // ordering within a wave, so same-wave disjoint tasks still flow in parallel.
        usort($candidates, static function (array $a, array $b): int {
            return ((int) data_get($a, 'metadata.wave', 0)) <=> ((int) data_get($b, 'metadata.wave', 0));
        });
        $depCache = []; // per-call {status, depends_on} memo so dependency+cycle resolution bounds file reads.
        foreach ($candidates as $candidate) {
            if (! $this->candidateCanBeClaimedByWorker($candidate, $agentId, $depCache)) {
                continue;
            }

            $taskPacketId = (string) $candidate['task_packet_id'];
            $scopeLock = [
                'write_set' => (array) data_get($candidate, 'task_packet.normalized_scope.allowed_files', []),
                'read_set' => (array) data_get($candidate, 'task_packet.normalized_scope.scope_in', []),
                'scope_lock_plan_hash' => (string) data_get($candidate, 'metadata.scope_lock_hash', ''),
            ];
            $claim = $this->leases->claim($taskPacketId, $agentId, $scopeLock, [
                'ttl_seconds' => (int) ($filters['ttl_seconds'] ?? 1800),
            ]);
            if ((string) $claim['status'] === 'ok') {
                // A2/MF-16: the lease (A1) already serialized the winner; the ATOMIC compare-and-swap
                // claimable->claimed guarantees the queue record can never be double-flipped by a stale
                // selection. If the record moved under us, release the lease we just took and try the next.
                $swap = $this->queue->compareAndSwapStatus($taskPacketId, 'claimable', 'claimed', [
                    'lease_id' => (string) $claim['lease_id'],
                    'agent_id' => $agentId,
                ]);
                if (($swap['swapped'] ?? false) !== true) {
                    $this->leases->release((string) $claim['lease_id'], $agentId, ['reason' => 'queue_status_moved']);

                    continue;
                }
                $this->queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => 'claim_acquired_by_orchestrator',
                    'lease_id' => (string) $claim['lease_id'],
                    'agent_id' => $agentId,
                ]);

                return $this->envelope('claimed', [
                    'queue_entry' => $candidate,
                    'lease' => $claim['lease'] ?? null,
                    'lease_id' => (string) $claim['lease_id'],
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $agentId,
                ]);
            }
            // Conflict: try next candidate.
        }

        return $this->envelope('no_claimable_task', [
            'agent_id' => $agentId,
            'candidate_count' => count($candidates),
        ]);
    }

    /**
     * A3/MF-05 — return any stranded task to `claimable` before the claim scan, reusing this orchestrator's OWN
     * queue + lease repos (same disk/lock config). Best-effort + fail-open: recovery never throws into the
     * claim path. Equivalent to the scheduled reaper, on the hot path. Three strands:
     *   - EXPIRED leases (dead client past TTL) — `recoverExpiredLeases`.
     *   - ORPHANED claims (queue stuck `claimed` with a missing/non-active lease) — `recoverOrphanedClaims`.
     *   - RELEASED tasks (a client reported give_back/failed) — `recoverReleasedTasks`. WITHOUT this, a
     *     give-back stranded the task in `released` forever (never re-listed), silently draining the queue
     *     (R1) and failing to re-serve recoverable work (R2). The recovery itself SKIPS released-with-blocker
     *     reasons (operator-investigation), so only transient give-backs are re-admitted.
     */
    private function reapExpiredBeforeListing(): void
    {
        try {
            $recovery = new AgentControlPlaneTaskLeaseRecoveryService($this->queue, $this->leases);
            $recovery->recoverExpiredLeases(['actor' => 'claim_next_presweep']);
            $recovery->recoverOrphanedClaims(['actor' => 'claim_next_presweep']);
            $recovery->recoverReleasedTasks(['actor' => 'claim_next_presweep']);
        } catch (Throwable) {
            // Pre-sweep is best-effort; a recovery hiccup must never block serving a claim.
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache  per-call node memo
     */
    private function candidateCanBeClaimedByWorker(array $candidate, string $agentId, array &$cache): bool
    {
        if ((bool) data_get($candidate, 'task_packet.continuation_context.worker_executable', true) === false) {
            return false;
        }
        if ((bool) data_get($candidate, 'task_packet.continuation_context.operator_handoff_required', false)) {
            return false;
        }
        // QUEUE-POISONING GUARD: the serving surface must NEVER hand a certification/probe packet to a real
        // worker. Disk isolation ({@see AtlasTaskServingStack}) is the first line; this is the belt-and-
        // suspenders second line so a probe that leaks into the serving disk can still never be claimed.
        if ($this->isCertificationProbe($candidate)) {
            return false;
        }
        // ANTI-LOOP COOLDOWN: never RE-serve a task to the SAME worker that just gave it back WHILE the reclaim
        // cooldown is still open (else it pulls its own give-back instantly and spins). The window is BOUNDED,
        // not permanent: a permanent skip deadlocks the version-ladder for a single worker — every task it ever
        // gave back, and everything depending on it, becomes forever unservable. After the window it may retry;
        // the task always stays available to OTHER workers. See {@see workerInGiveBackCooldown}.
        if ($this->workerInGiveBackCooldown($candidate, $agentId)) {
            return false;
        }

        // ORDER: a task is servable only when its prerequisites are MET. Cancelled/absent/cyclic deps are
        // fail-open (never a permanent indue block); a blocked (quarantined) prereq keeps the dependent gated
        // but is operator-recoverable, not permanent. See {@see classifyDependencies}.
        return $this->classifyDependencies($candidate, $cache) === 'met';
    }

    /** A dep in one of these states is SATISFIED: completed, or terminally GONE (cancelled ⇒ fail-open). */
    private const DEPENDENCY_SATISFIED_STATES = ['completed_dry_run', 'cancelled'];

    /** A dep here is unmet but DEAD (quarantined) — operator-recoverable, NOT the advancing ladder. */
    private const DEPENDENCY_DEAD_STATES = ['blocked'];

    /**
     * Classify a candidate's depends_on into the gate/wait verdict — the single source of truth for ordering:
     *   - 'met'      every prerequisite is satisfied ⇒ SERVABLE now.
     *   - 'inflight' ≥1 unmet prerequisite is still moving (queued/claimable/claimed/lease_expired/released) ⇒
     *                the version-ladder IS advancing; a worker should WAIT.
     *   - 'blocked'  unmet prerequisites exist but ALL are DEAD (quarantined) ⇒ the ladder is NOT advancing;
     *                this is escalation, never a "just wait" — so it can't masquerade as waiting_on_dependencies.
     *
     * Three fail-open rules guarantee depends_on can NEVER create a permanent indue block:
     *   - an ABSENT dep (typo/pruned) is satisfied,
     *   - a CANCELLED dep (terminally gone) is satisfied,
     *   - a CYCLIC dep (a prerequisite that transitively depends back on this task) is satisfied — a cycle has
     *     no valid topological order, so freezing the belt on it would be exactly the deadlock we must avoid.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     */
    private function classifyDependencies(array $candidate, array &$cache): string
    {
        $rootId = (string) ($candidate['task_packet_id'] ?? '');
        $dependsOn = array_values(array_filter((array) data_get($candidate, 'metadata.depends_on', []), 'is_string'));
        $sawInflight = false;
        $sawDead = false;
        foreach ($dependsOn as $depId) {
            $node = $this->dependencyNode($depId, $cache);
            if ($node === null) {
                continue; // absent ⇒ fail-open (satisfied).
            }
            if (in_array($node['status'], self::DEPENDENCY_SATISFIED_STATES, true)) {
                continue; // completed OR cancelled ⇒ satisfied.
            }
            if ($rootId !== '' && $this->dependencyReaches($depId, $rootId, $cache, [])) {
                continue; // CYCLE ⇒ fail-open (break the deadlock).
            }
            if (in_array($node['status'], self::DEPENDENCY_DEAD_STATES, true)) {
                $sawDead = true;
            } else {
                $sawInflight = true;
            }
        }

        if ($sawInflight) {
            return 'inflight';
        }

        return $sawDead ? 'blocked' : 'met';
    }

    /**
     * Lightweight, memoized {status, depends_on} for a queue node (null when absent). Bounds file reads to one
     * per distinct node across a single claim/scan call.
     *
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @return array{status:string, depends_on:list<string>}|null
     */
    private function dependencyNode(string $id, array &$cache): ?array
    {
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        $record = $this->queue->get($id);

        return $cache[$id] = $record === null ? null : [
            'status' => (string) ($record['status'] ?? ''),
            'depends_on' => array_values(array_filter((array) data_get($record, 'metadata.depends_on', []), 'is_string')),
        ];
    }

    /**
     * True when following depends_on edges from $fromId ever reaches $targetId — i.e. $fromId is (transitively)
     * a prerequisite of $targetId, so making $targetId depend on $fromId closes a cycle. The visited set makes
     * this terminate on any graph.
     *
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @param  array<string, bool>  $seen
     */
    private function dependencyReaches(string $fromId, string $targetId, array &$cache, array $seen): bool
    {
        if (isset($seen[$fromId])) {
            return false;
        }
        $seen[$fromId] = true;
        $node = $this->dependencyNode($fromId, $cache);
        if ($node === null) {
            return false;
        }
        foreach ($node['depends_on'] as $next) {
            if ($next === $targetId || $this->dependencyReaches($next, $targetId, $cache, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tag PREFIXES the Agent Control Plane fleet/bootstrap certification machinery stamps on its probes (some
     * probe tags — e.g. `terminal_bootstrap_partial_supply` — carry neither the word "probe" nor "certification",
     * so a substring check alone would miss them). Real serving tasks are tagged `brain-originated` / `docs` /
     * `tests` / `guardrail` / `runtime_gap` / `chain_integrity` etc., none of which match — so no false-positive.
     */
    private const PROBE_TAG_PREFIXES = ['terminal_fleet', 'terminal_worker', 'terminal_bootstrap', 'multi_agent_loop'];

    /**
     * A certification/probe packet must NEVER be served to a real worker. The authoritative marker is the queue
     * TAG the certification/fleet machinery stamps (a substring `probe`/`certification`, or one of the fleet/
     * bootstrap prefixes), plus the canonical `probe_*` id prefix. Disk isolation ({@see AtlasTaskServingStack})
     * is the primary defense; this guard is the belt-and-suspenders for the shared-disk fallback.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function isCertificationProbe(array $candidate): bool
    {
        $id = strtolower((string) ($candidate['task_packet_id'] ?? ''));
        if ($id !== '' && (str_starts_with($id, 'probe_') || str_starts_with($id, 'probe-'))) {
            return true;
        }
        foreach ((array) ($candidate['tags'] ?? []) as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag === '') {
                continue;
            }
            if (str_contains($tag, 'probe') || str_contains($tag, 'certification')) {
                return true;
            }
            foreach (self::PROBE_TAG_PREFIXES as $prefix) {
                if (str_starts_with($tag, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * SERVABILITY BREAKDOWN — the honest cross-cut the coordination-health panel embeds so health, the
     * orchestrator, the service and the CLI all AGREE on how many claimable tasks can actually be pulled now.
     * Reuses the SAME predicates the claim path uses (dependency classification, probe guard, executability),
     * for a FRESH worker (the per-worker give-back cooldown is transient and excluded here). `servable_now` is
     * the count a cold worker could claim this instant (the quality-gate quarantine still applies at claim).
     *
     * @return array<string, int>
     */
    public function servabilityBreakdown(): array
    {
        $cache = [];
        $inspector = new AtlasTaskPacketQualityInspector;
        $claimable = $this->queue->list(['status' => 'claimable']);
        $servable = 0;
        $waitingInflight = 0;
        $blockedPrereq = 0;
        $notExecutable = 0;
        $probe = 0;
        $malformed = 0;

        foreach ($claimable as $candidate) {
            if ($this->isCertificationProbe($candidate)) {
                $probe++;

                continue;
            }
            $executable = (bool) data_get($candidate, 'task_packet.continuation_context.worker_executable', true)
                && ! (bool) data_get($candidate, 'task_packet.continuation_context.operator_handoff_required', false);
            if (! $executable) {
                $notExecutable++;

                continue;
            }
            $depState = $this->classifyDependencies($candidate, $cache);
            if ($depState === 'inflight') {
                $waitingInflight++;

                continue;
            }
            if ($depState !== 'met') {
                $blockedPrereq++;

                continue;
            }
            // deps met — the SAME quality gate the claim path applies decides servable vs quarantine-on-claim.
            // This makes servable_now equal what a worker actually gets served (no over-count of doomed packets).
            if ($inspector->isSelfSufficient((array) data_get($candidate, 'task_packet', []))) {
                $servable++;
            } else {
                $malformed++;
            }
        }

        return [
            'claimable' => count($claimable),
            'servable_now' => $servable,
            'waiting_on_inflight_deps' => $waitingInflight,
            'blocked_by_dead_prereq' => $blockedPrereq,
            'malformed_quarantine_on_claim' => $malformed,
            'not_executable' => $notExecutable,
            'certification_probe_excluded' => $probe,
        ];
    }

    /**
     * Operator maintenance: quarantine claimable packets that the serving front door would reject as not
     * self-sufficient. This keeps workers from spending even a `next` call cleaning old malformed backlog.
     *
     * @return array<string, mixed>
     */
    public function sweepMalformedClaimableTasks(int $limit = 0, bool $dryRun = false, string $actor = 'task_sweep'): array
    {
        $inspector = new AtlasTaskPacketQualityInspector;
        $limit = max(0, $limit);
        $inspected = 0;
        $blocked = [];
        $wouldBlock = [];

        foreach ($this->queue->list(['status' => 'claimable']) as $candidate) {
            if ($limit > 0 && count($blocked) + count($wouldBlock) >= $limit) {
                break;
            }
            $inspected++;
            $quality = $inspector->inspect((array) data_get($candidate, 'task_packet', []));
            if ((bool) ($quality['self_sufficient'] ?? false)) {
                continue;
            }

            $taskPacketId = (string) ($candidate['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                continue;
            }

            $item = [
                'task_packet_id' => $taskPacketId,
                'blocking_deficiencies' => array_values((array) ($quality['blocking_deficiencies'] ?? [])),
            ];

            if ($dryRun) {
                $wouldBlock[] = $item;

                continue;
            }

            $transition = $this->queue->updateStatus($taskPacketId, 'blocked', [
                'reason' => 'packet_not_self_sufficient_sweep',
                'agent_id' => $actor,
                'blocking_deficiencies' => $item['blocking_deficiencies'],
            ]);
            if ((string) ($transition['status'] ?? '') === 'ok') {
                $this->queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => 'packet_quarantined_not_self_sufficient_sweep',
                    'agent_id' => $actor,
                    'blocking_deficiencies' => $item['blocking_deficiencies'],
                ]);
                $blocked[] = $item;
            }
        }

        return [
            'schema' => 'atlas.task_serving.malformed_sweep.v1',
            'dry_run' => $dryRun,
            'inspected_claimable' => $inspected,
            'blocked_count' => count($blocked),
            'would_block_count' => count($wouldBlock),
            'blocked' => $blocked,
            'would_block' => $wouldBlock,
        ];
    }

    /**
     * Repair blocked packets whose only known issue is an uncommittable forbidden self-target in allowed_files.
     * The repair keeps the SAME task_packet_id, removes forbidden paths from the write scope, marks them as
     * forbidden_files, rebuilds the packet hash via the canonical builder, and reopens the task as claimable.
     * Dependencies keep pointing at the same id, so the task ladder does not fork.
     *
     * @return array<string, mixed>
     */
    public function repairBlockedForbiddenSelfTargetTasks(int $limit = 0, bool $dryRun = false, string $actor = 'task_repair'): array
    {
        $guard = new \App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
        $inspector = new AtlasTaskPacketQualityInspector($guard);
        $limit = max(0, $limit);
        $inspected = 0;
        $repairable = [];
        $repaired = [];
        $retired = [];
        $unrepairable = [];

        foreach ($this->queue->list(['status' => 'blocked']) as $record) {
            if ($limit > 0 && count($repairable) + count($repaired) + count($retired) + count($unrepairable) >= $limit) {
                break;
            }
            $inspected++;
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            $packet = (array) data_get($record, 'task_packet', []);
            $allowed = $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', [])));
            $forbiddenAllowed = array_values(array_filter($allowed, fn (string $path): bool => $guard->isForbiddenSelfTarget($path)));
            if ($taskPacketId === '' || $forbiddenAllowed === []) {
                continue;
            }

            $input = $this->repairInputWithoutForbiddenTargets($packet, $forbiddenAllowed);
            $rebuilt = $this->builder->build($input);
            $quality = $inspector->inspect($rebuilt);
            $item = [
                'task_packet_id' => $taskPacketId,
                'removed_allowed_files' => $forbiddenAllowed,
                'remaining_allowed_files' => array_values((array) data_get($rebuilt, 'normalized_scope.allowed_files', [])),
                'blocking_deficiencies' => array_values((array) ($quality['blocking_deficiencies'] ?? [])),
            ];

            if ((string) ($rebuilt['status'] ?? '') !== 'planned' || ! (bool) ($quality['self_sufficient'] ?? false)) {
                if (! $dryRun && $item['remaining_allowed_files'] === []) {
                    $retire = $this->queue->updateStatus($taskPacketId, 'cancelled', [
                        'reason' => 'unrepairable_empty_allowed_files_after_forbidden_self_target_repair',
                        'agent_id' => $actor,
                        'removed_allowed_files' => $forbiddenAllowed,
                        'blocking_deficiencies' => $item['blocking_deficiencies'],
                    ]);
                    if ((string) ($retire['status'] ?? '') === 'ok') {
                        $this->queue->appendReceipt($taskPacketId, [
                            'receipt_kind' => 'blocked_packet_retired_empty_scope_after_forbidden_self_target_repair',
                            'agent_id' => $actor,
                            'removed_allowed_files' => $forbiddenAllowed,
                            'blocking_deficiencies' => $item['blocking_deficiencies'],
                        ]);
                        $retired[] = $item;

                        continue;
                    }

                    $item['retire_status'] = (string) ($retire['status'] ?? 'unknown');
                    $item['retire_reason'] = (string) ($retire['reason'] ?? '');
                }

                $unrepairable[] = $item;

                continue;
            }

            if ($dryRun) {
                $repairable[] = $item;

                continue;
            }

            $replace = $this->queue->replaceBlockedTaskPacket($taskPacketId, $rebuilt, [
                'reason' => 'forbidden_self_target_scope_repaired',
                'agent_id' => $actor,
                'removed_allowed_files' => $forbiddenAllowed,
            ]);
            if ((string) ($replace['status'] ?? '') === 'ok') {
                $this->queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => 'blocked_packet_repaired_forbidden_self_target',
                    'agent_id' => $actor,
                    'removed_allowed_files' => $forbiddenAllowed,
                ]);
                $repaired[] = $item;
            } else {
                $item['repair_status'] = (string) ($replace['status'] ?? 'unknown');
                $item['repair_reason'] = (string) ($replace['reason'] ?? '');
                $unrepairable[] = $item;
            }
        }

        return [
            'schema' => 'atlas.task_serving.blocked_repair.v1',
            'dry_run' => $dryRun,
            'inspected_blocked' => $inspected,
            'repairable_count' => count($repairable),
            'repaired_count' => count($repaired),
            'retired_count' => count($retired),
            'unrepairable_count' => count($unrepairable),
            'repairable' => $repairable,
            'repaired' => $repaired,
            'retired' => $retired,
            'unrepairable' => $unrepairable,
        ];
    }

    /**
     * Repair the OTHER blocked class the forbidden-target repair cannot touch: a packet whose pétreo target was
     * ALREADY moved out of allowed_files by a prior scope-repair, but whose acceptance STILL demands that target
     * — so the inspector keeps it blocked on `scope_repair_removed_required_target_from_allowed_files` forever
     * (this was the dominant jam cause: ~all blocked packets, each stranding dead-prereq dependents). It also
     * reopens any blocked packet that simply re-inspects self-sufficient now (stale quarantine).
     *
     * Per blocked packet:
     *   - re-inspect; if SELF-SUFFICIENT now ⇒ reopen unchanged (stale quarantine cleared).
     *   - else if blocked ONLY by the scope-repair deficiency:
     *       · if a BUILDABLE target remains (a non-test, non-pétreo allowed_file) ⇒ scrub the acceptance lines
     *         that demand a pétreo/removed path (operator-wiring, not worker work), reopen. The worker builds the
     *         class + test; the pétreo flag/judge wiring is the operator's separate step.
     *       · else (allowed is only tests / only pétreo) ⇒ RETIRE (cancel). A cancelled prereq is fail-open, so
     *         its dependents stop being `blocked_by_dead_prereq` and the ladder advances.
     *   - else ⇒ leave blocked (unrepairable here; reported).
     *
     * Same task_packet_id throughout, so depends_on edges never fork.
     *
     * @return array<string, mixed>
     */
    public function repairScopeBlockedTasks(int $limit = 0, bool $dryRun = false, string $actor = 'task_repair'): array
    {
        $guard = new \App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
        $inspector = new AtlasTaskPacketQualityInspector($guard);
        $limit = max(0, $limit);
        $inspected = 0;
        $reopened = [];
        $retired = [];
        $unrepairable = [];
        $plan = [];

        foreach ($this->queue->list(['status' => 'blocked']) as $record) {
            if ($limit > 0 && count($reopened) + count($retired) + count($unrepairable) + count($plan) >= $limit) {
                break;
            }
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                continue;
            }
            $packet = (array) data_get($record, 'task_packet', []);
            $quality = $inspector->inspect($packet);
            $blocking = array_values((array) ($quality['blocking_deficiencies'] ?? []));
            $inspected++;

            $selfSufficient = (bool) ($quality['self_sufficient'] ?? false);
            $isScopeRepairDoomed = in_array('scope_repair_removed_required_target_from_allowed_files', $blocking, true);
            if ($this->isRepeatedGiveBackQuarantine($record)) {
                $unrepairable[] = [
                    'task_packet_id' => $taskPacketId,
                    'blocking_deficiencies' => array_values(array_unique(array_merge($blocking, ['repeated_give_back_8']))),
                    'reason' => 'repeated_give_back_quarantine_requires_respec',
                ];

                continue;
            }

            if (! $selfSufficient && (! $isScopeRepairDoomed || count($blocking) > 1)) {
                // Not our class, or compounded with another deficiency we must not silently paper over.
                $unrepairable[] = ['task_packet_id' => $taskPacketId, 'blocking_deficiencies' => $blocking];

                continue;
            }

            $allowed = $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', [])));
            $buildable = array_values(array_filter(
                $allowed,
                fn (string $p): bool => ! $this->isTestPath($p) && ! $guard->isForbiddenSelfTarget($p),
            ));
            $removedTargets = array_values((array) data_get($quality, 'facts.scope_repair_removed_required_targets', []));

            // RETIRE: nothing a worker can build (only tests / only pétreo) and not self-sufficient.
            if (! $selfSufficient && $buildable === []) {
                $item = ['task_packet_id' => $taskPacketId, 'action' => 'retire', 'reason' => 'no_buildable_non_petreo_target', 'removed_targets' => $removedTargets];
                if ($dryRun) {
                    $plan[] = $item;

                    continue;
                }
                $t = $this->queue->updateStatus($taskPacketId, 'cancelled', [
                    'reason' => 'operator_only_task_no_buildable_target_after_scope_repair',
                    'agent_id' => $actor,
                    'removed_targets' => $removedTargets,
                ]);
                if ((string) ($t['status'] ?? '') === 'ok') {
                    $this->queue->appendReceipt($taskPacketId, [
                        'receipt_kind' => 'blocked_packet_retired_operator_only_after_scope_repair',
                        'agent_id' => $actor,
                        'removed_targets' => $removedTargets,
                    ]);
                    $retired[] = $item;
                } else {
                    $unrepairable[] = ['task_packet_id' => $taskPacketId, 'retire_status' => (string) ($t['status'] ?? 'unknown')];
                }

                continue;
            }

            // REOPEN: self-sufficient-now (scrub nothing) or scope-repair-doomed-but-buildable (scrub the
            // pétreo demand from acceptance so the worker contract is exactly the buildable work).
            $scrub = $selfSufficient ? [] : $this->petreoPathsToScrub($packet, $removedTargets, $guard);
            $input = $this->repairInputKeepingScope($packet, $scrub);
            $rebuilt = $this->builder->build($input);
            $requality = $inspector->inspect($rebuilt);
            $item = [
                'task_packet_id' => $taskPacketId,
                'action' => $selfSufficient ? 'reopen_clean' : 'reopen_scrubbed',
                'scrubbed_paths' => $scrub,
                'remaining_allowed_files' => array_values((array) data_get($rebuilt, 'normalized_scope.allowed_files', [])),
            ];

            if ((string) ($rebuilt['status'] ?? '') !== 'planned' || ! (bool) ($requality['self_sufficient'] ?? false)) {
                $item['still_blocking'] = array_values((array) ($requality['blocking_deficiencies'] ?? []));
                $unrepairable[] = $item;

                continue;
            }
            if ($dryRun) {
                $plan[] = $item;

                continue;
            }
            $replace = $this->queue->replaceBlockedTaskPacket($taskPacketId, $rebuilt, [
                'reason' => $selfSufficient ? 'stale_quarantine_reopened' : 'scope_repair_acceptance_reconciled',
                'agent_id' => $actor,
                'scrubbed_paths' => $scrub,
            ]);
            if ((string) ($replace['status'] ?? '') === 'ok') {
                $this->queue->appendReceipt($taskPacketId, [
                    'receipt_kind' => $selfSufficient ? 'blocked_packet_reopened_stale_quarantine' : 'blocked_packet_reopened_scope_repair_reconciled',
                    'agent_id' => $actor,
                    'scrubbed_paths' => $scrub,
                ]);
                $reopened[] = $item;
            } else {
                $item['repair_status'] = (string) ($replace['status'] ?? 'unknown');
                $unrepairable[] = $item;
            }
        }

        return [
            'schema' => 'atlas.task_serving.scope_blocked_repair.v1',
            'dry_run' => $dryRun,
            'inspected_blocked' => $inspected,
            'reopened_count' => count($reopened),
            'retired_count' => count($retired),
            'unrepairable_count' => count($unrepairable),
            'planned_count' => count($plan),
            'reopened' => $reopened,
            'retired' => $retired,
            'unrepairable' => $unrepairable,
            'plan' => $plan,
        ];
    }

    /**
     * A repeated give-back quarantine is not "stale" just because today's structural inspector passes. It means
     * several workers already found the packet non-executable or contradictory in practice; reopening it blindly
     * recreates the poison loop and burns tokens again. Respec or retire it explicitly instead.
     *
     * @param  array<string,mixed>  $record
     */
    private function isRepeatedGiveBackQuarantine(array $record): bool
    {
        $giveBackCount = (int) data_get($record, 'metadata.give_back_count', data_get($record, 'give_back_count', 0));
        $deficiencies = array_map('strval', (array) data_get($record, 'metadata.blocking_deficiencies', []));
        $reason = (string) data_get($record, 'metadata.reason', '');

        return $giveBackCount >= 7
            && ($reason === 'packet_not_self_sufficient' || in_array('repeated_give_back_8', $deficiencies, true));
    }

    private function isTestPath(string $path): bool
    {
        $p = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_contains($p, '/tests/') || str_starts_with($p, 'tests/') || str_ends_with($p, 'Test.php');
    }

    /**
     * The pétreo/removed paths a reopened packet must stop demanding in its acceptance (worker can't commit them;
     * the operator wires them). Union of the inspector's removed-required-targets and any pétreo allowed/forbidden
     * path on the packet — matched in acceptance text by full path or basename.
     *
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $removedTargets
     * @return list<string>
     */
    private function petreoPathsToScrub(array $packet, array $removedTargets, \App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard $guard): array
    {
        $forbidden = $this->stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', [])));
        $petreoForbidden = array_values(array_filter($forbidden, fn (string $p): bool => $guard->isForbiddenSelfTarget($p)));

        return array_values(array_unique(array_merge($removedTargets, $petreoForbidden)));
    }

    /**
     * Builder input from a blocked packet KEEPING its scope (allowed/forbidden), optionally dropping any
     * acceptance criterion that references one of $scrubPaths (full path or basename) — the operator-wiring
     * demand the worker cannot satisfy. If scrubbing empties acceptance, a minimal buildable criterion is
     * synthesised so the reopened packet stays self-sufficient.
     *
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $scrubPaths
     * @return array<string, mixed>
     */
    private function repairInputKeepingScope(array $packet, array $scrubPaths): array
    {
        $acceptance = $this->stringList((array) data_get($packet, 'acceptance_criteria', []));
        if ($scrubPaths !== []) {
            $needles = [];
            foreach ($scrubPaths as $p) {
                $p = trim((string) $p);
                if ($p === '') {
                    continue;
                }
                $needles[] = $p;
                $needles[] = basename($p);
            }
            $acceptance = array_values(array_filter($acceptance, function (string $line) use ($needles): bool {
                foreach ($needles as $n) {
                    if ($n !== '' && str_contains($line, $n)) {
                        return false; // drop a criterion that demands a pétreo/removed path
                    }
                }

                return true;
            }));
        }
        if ($acceptance === []) {
            $acceptance = ['Implement the listed allowed_files with their public API and a passing unit test; do not edit any forbidden_files (the operator wires those separately).'];
        }

        $objective = trim((string) data_get($packet, 'objective', ''));
        if ($scrubPaths !== []) {
            $objective .= ' Scope reconciliation: the pétreo path(s) ['.implode(', ', $scrubPaths).'] are operator-wired, not worker scope. Implement only the buildable allowed_files + tests; do not edit the pétreo path(s).';
        }

        return [
            'task_packet_id' => (string) data_get($packet, 'task_packet_id', ''),
            'objective' => $objective,
            'source' => (string) data_get($packet, 'source', 'operator_intake'),
            'operator_id' => (string) data_get($packet, 'operator_id', 'operator-unknown'),
            'parent_run_id' => (string) data_get($packet, 'parent_run_id', ''),
            'allowed_files' => $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            'scope_in' => $this->stringList((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            'scope_out' => $this->stringList((array) data_get($packet, 'normalized_scope.scope_out', data_get($packet, 'scope_out', []))),
            'forbidden_files' => $this->stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', []))),
            'acceptance_criteria' => $acceptance,
            'required_evidence' => $this->stringList((array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []))),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'low')),
            'max_runtime_seconds' => (int) data_get($packet, 'cost_budget_requirements.max_runtime_seconds', data_get($packet, 'max_runtime_seconds', 3600)),
            'max_token_budget' => (int) data_get($packet, 'cost_budget_requirements.max_token_budget', data_get($packet, 'max_token_budget', 0)),
            'workspace_policy' => (array) data_get($packet, 'workspace_policy', []),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            'lease_ttl_seconds' => (int) data_get($packet, 'lease_requirements.lease_ttl_seconds', 1800),
            'rollback_strategy' => (string) data_get($packet, 'rollback_requirements.rollback_strategy', 'plan_only'),
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $forbiddenAllowed
     * @return array<string, mixed>
     */
    private function repairInputWithoutForbiddenTargets(array $packet, array $forbiddenAllowed): array
    {
        $blocked = array_fill_keys($forbiddenAllowed, true);
        $allowed = array_values(array_filter(
            $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            static fn (string $path): bool => ! isset($blocked[$path]),
        ));
        $scopeIn = array_values(array_filter(
            $this->stringList((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            static fn (string $path): bool => ! isset($blocked[$path]),
        ));
        $forbidden = array_values(array_unique(array_merge(
            $this->stringList((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', []))),
            $forbiddenAllowed,
        )));
        sort($forbidden);

        $objective = trim((string) data_get($packet, 'objective', ''));
        $removed = implode(', ', $forbiddenAllowed);
        $repairNote = " Scope repair: {$removed} was removed from allowed_files because Atlas cannot safely commit forbidden self-targets. Implement only the remaining allowed_files and do not edit the removed path(s).";

        return [
            'task_packet_id' => (string) data_get($packet, 'task_packet_id', ''),
            'objective' => $objective.$repairNote,
            'source' => (string) data_get($packet, 'source', 'operator_intake'),
            'operator_id' => (string) data_get($packet, 'operator_id', 'operator-unknown'),
            'parent_run_id' => (string) data_get($packet, 'parent_run_id', ''),
            'allowed_files' => $allowed,
            'scope_in' => array_values(array_unique(array_merge($scopeIn, $allowed))),
            'scope_out' => $this->stringList((array) data_get($packet, 'normalized_scope.scope_out', data_get($packet, 'scope_out', []))),
            'forbidden_files' => $forbidden,
            'acceptance_criteria' => $this->stringList((array) data_get($packet, 'acceptance_criteria', [])),
            'required_evidence' => $this->stringList((array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []))),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'low')),
            'max_runtime_seconds' => (int) data_get($packet, 'cost_budget_requirements.max_runtime_seconds', data_get($packet, 'max_runtime_seconds', 3600)),
            'max_token_budget' => (int) data_get($packet, 'cost_budget_requirements.max_token_budget', data_get($packet, 'max_token_budget', 0)),
            'workspace_policy' => (array) data_get($packet, 'workspace_policy', []),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            'lease_ttl_seconds' => (int) data_get($packet, 'lease_requirements.lease_ttl_seconds', 1800),
            'rollback_strategy' => (string) data_get($packet, 'rollback_requirements.rollback_strategy', 'plan_only'),
        ];
    }

    /**
     * Is there at least one claimable task held back ONLY by unmet dependencies? The serving uses this to tell a
     * worker to WAIT (the ordered ladder is still flowing) instead of stopping as if the queue were drained.
     */
    public function hasDependencyGatedClaimableTasks(string $agentId = ''): bool
    {
        $cache = [];
        foreach ($this->queue->list(['status' => 'claimable']) as $candidate) {
            // A task in this worker's give-back cooldown, or a probe, is NOT "the ladder advancing" — skip it
            // (same predicates the claim path uses) so neither can be misread as waiting-on-dependencies.
            if ($this->workerInGiveBackCooldown($candidate, $agentId) || $this->isCertificationProbe($candidate)) {
                continue;
            }
            // ONLY an in-flight prerequisite counts as the ladder advancing. A task gated solely by a DEAD
            // (quarantined) prereq is NOT a transient wait — it must fall through to an honest empty/escalation,
            // never tell the worker to keep waiting on something that will never complete on its own.
            if ($this->classifyDependencies($candidate, $cache) === 'inflight') {
                return true;
            }
        }

        return false;
    }

    /**
     * RECLAIM-AFTER-GIVE-BACK predicate. Is this candidate still inside the bounded no-re-serve window for the
     * worker that last gave it back? TRUE ⇒ skip it for THAT worker (prevents instant self-respin). FALSE ⇒
     * reclaimable by anyone, including the original giver. The window has ELAPSED (⇒ reclaimable) when:
     *   - the worker is not the last giver (never their cooldown),
     *   - the configured cooldown is ≤ 0 (operator disabled it),
     *   - the record carries no `last_give_back_at` (a give-back recorded BEFORE this field existed — the live
     *     backlog — so it self-heals into reclaimable rather than staying permanently locked), or
     *   - now is past `last_give_back_at + cooldown`.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function workerInGiveBackCooldown(array $candidate, string $agentId): bool
    {
        if ($agentId === '') {
            return false;
        }
        $lastGiver = (string) data_get($candidate, 'metadata.last_give_back_by', '');
        if ($lastGiver === '' || $lastGiver !== $agentId) {
            return false;
        }
        $cooldownSeconds = $this->giveBackReclaimCooldownSeconds();
        if ($cooldownSeconds <= 0) {
            return false; // cooldown disabled ⇒ immediate reclaim by the giver.
        }
        $lastAt = trim((string) data_get($candidate, 'metadata.last_give_back_at', ''));
        if ($lastAt === '') {
            return false; // legacy give-back (no timestamp) ⇒ window already elapsed ⇒ reclaimable.
        }
        try {
            $expiresAt = CarbonImmutable::parse($lastAt)->addSeconds($cooldownSeconds);
        } catch (Throwable) {
            return false; // unparseable timestamp ⇒ never strand the task.
        }

        return CarbonImmutable::now()->lessThan($expiresAt);
    }

    /** The reclaim-after-give-back cooldown in seconds (config-overridable, non-negative; default constant). */
    private function giveBackReclaimCooldownSeconds(): int
    {
        $configured = config('atlas.task_serving.give_back_reclaim_cooldown_seconds', self::DEFAULT_GIVE_BACK_RECLAIM_COOLDOWN_SECONDS);
        $seconds = is_numeric($configured) ? (int) $configured : self::DEFAULT_GIVE_BACK_RECLAIM_COOLDOWN_SECONDS;

        return $seconds >= 0 ? $seconds : self::DEFAULT_GIVE_BACK_RECLAIM_COOLDOWN_SECONDS;
    }

    /**
     * @return array<string, mixed>
     */
    public function renewLease(string $leaseId, string $agentId, int $ttlSeconds): array
    {
        $renewal = $this->leases->renew($leaseId, $agentId, $ttlSeconds);

        return $this->envelope('lease_renewal', ['renewal' => $renewal]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function releaseLease(string $leaseId, string $agentId, array $options = []): array
    {
        $release = $this->leases->release($leaseId, $agentId, $options);
        $taskPacketId = (string) data_get($release, 'task_packet_id', '');
        if ($taskPacketId !== '' && (string) $release['status'] === 'ok') {
            $this->queue->updateStatus($taskPacketId, 'released', [
                'lease_id' => $leaseId,
                'release_reason' => (string) ($options['reason'] ?? 'released_by_owner'),
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'lease_released_by_orchestrator',
                'lease_id' => $leaseId,
                'agent_id' => $agentId,
            ]);
        }

        return $this->envelope('lease_release', ['release' => $release]);
    }

    /** A task given back this many times is DOOMED (no worker can do it as scoped) — quarantine it. The
     *  per-worker re-serve is already prevented by the give-back cooldown; this caps cross-worker bouncing. */
    public const MAX_GIVE_BACKS = 8;

    /**
     * RECLAIM-AFTER-GIVE-BACK cooldown default (seconds). The per-worker anti-loop skip
     * ({@see workerInGiveBackCooldown}) lasts only this long; afterwards the SAME worker may retry the task it
     * gave back. A permanent skip would deadlock the version-ladder for a single worker. Overridable via
     * config('atlas.task_serving.give_back_reclaim_cooldown_seconds'); 0 disables the cooldown entirely.
     */
    public const DEFAULT_GIVE_BACK_RECLAIM_COOLDOWN_SECONDS = 600;

    /**
     * ANTI-LOOP give-back: release the task so ANOTHER worker can try, recording the giver (so it is never
     * re-served to the same worker) and the count. After {@see MAX_GIVE_BACKS}, the task is DOOMED as scoped —
     * quarantine it (blocked) instead of re-admitting it, so it can never cycle forever.
     *
     * @return array<string, mixed>
     */
    public function reportGiveBack(string $taskPacketId, string $leaseId, string $agentId, string $reason = 'client_reported_give_back'): array
    {
        $record = $this->queue->get($taskPacketId);
        $count = (int) data_get($record, 'metadata.give_back_count', 0) + 1;

        if ($count >= self::MAX_GIVE_BACKS) {
            $this->quarantineClaimed($taskPacketId, $leaseId, $agentId, ['repeated_give_back_'.$count]);

            return $this->envelope('give_back_quarantined', [
                'task_packet_id' => $taskPacketId,
                'give_back_count' => $count,
                'reason' => 'doomed_after_repeated_give_back',
            ]);
        }

        $release = $this->leases->release($leaseId, $agentId, ['reason' => $reason]);
        if ((string) data_get($release, 'task_packet_id', $taskPacketId) !== '' && (string) ($release['status'] ?? '') === 'ok') {
            $this->queue->updateStatus($taskPacketId, 'released', [
                'lease_id' => $leaseId,
                'release_reason' => $reason,
                'give_back_count' => $count,
                'last_give_back_by' => $agentId,
                // Stamp WHEN the give-back happened so the reclaim cooldown is time-bounded, not permanent.
                // Without this anchor the giver could never reclaim and the version-ladder would deadlock.
                'last_give_back_at' => CarbonImmutable::now()->toIso8601String(),
            ]);
            $this->queue->appendReceipt($taskPacketId, [
                'receipt_kind' => 'task_given_back',
                'agent_id' => $agentId,
                'give_back_count' => $count,
            ]);
        }

        return $this->envelope('given_back', [
            'task_packet_id' => $taskPacketId,
            'give_back_count' => $count,
            'lease_released' => (string) ($release['status'] ?? '') === 'ok',
            'release' => $release,
        ]);
    }

    /**
     * Axis 8 — QUARANTINE a claimed packet that is NOT self-sufficient: a cold client could never implement or
     * prove it (e.g. no acceptance criteria / no required evidence / a bare-dir write scope). Release the lease
     * and move the queue record `claimed → blocked` so it is never re-offered to a client until an operator
     * fixes it (recovery never auto-reopens `blocked`). The deficiencies are recorded on the receipt. This is
     * how the serving path guarantees a client only ever receives an implementable task.
     *
     * @param  list<string>  $deficiencies
     * @return array<string, mixed>
     */
    public function quarantineClaimed(string $taskPacketId, string $leaseId, string $agentId, array $deficiencies = []): array
    {
        // Release the lease (registry only) so no active lease lingers; the queue record stays `claimed`.
        $this->leases->release($leaseId, $agentId, ['reason' => 'packet_not_self_sufficient']);

        $transition = $this->queue->updateStatus($taskPacketId, 'blocked', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'reason' => 'packet_not_self_sufficient',
            'blocking_deficiencies' => array_values($deficiencies),
        ]);
        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'packet_quarantined_not_self_sufficient',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'blocking_deficiencies' => array_values($deficiencies),
        ]);

        return $this->envelope('packet_quarantined', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'blocking_deficiencies' => array_values($deficiencies),
            'queue_transition' => (string) ($transition['status'] ?? ''),
        ]);
    }

    /**
     * Server-side TRUTH of a task's commit scope (never trust the client's echo). The shared-main resolve
     * commits exactly these files.
     *
     * @return array{allowed_files:list<string>, objective:string}
     */
    public function taskScope(string $taskPacketId): array
    {
        $record = $this->queue->get($taskPacketId);
        $packet = (array) data_get($record, 'task_packet', []);

        return [
            'allowed_files' => array_values((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            'objective' => (string) data_get($packet, 'objective', ''),
        ];
    }

    /**
     * Shared-main RESOLVE close: after the scoped commit landed the work, release the lease and move the queue
     * record to its terminal `completed_dry_run` state, recording the commit SHA. The evidence gate of
     * {@see completeDryRun} is bypassed here because the COMMIT itself is the proof of work (the AI ran its
     * gates before reporting — see the runbook); the scope was enforced by {@see AtlasTaskScopedCommitter}.
     *
     * @return array<string, mixed>
     */
    public function markResolved(string $taskPacketId, string $leaseId, string $agentId, string $commitSha): array
    {
        $lease = $this->leases->get($leaseId);
        if ($lease === null || (string) $lease['task_packet_id'] !== $taskPacketId) {
            return $this->envelope('resolve_blocked', ['reason' => 'lease_not_found_or_mismatch', 'task_packet_id' => $taskPacketId]);
        }

        $this->leases->release($leaseId, $agentId, ['reason' => 'resolved_committed']);
        $transition = $this->queue->updateStatus($taskPacketId, 'completed_dry_run', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'resolution' => 'committed_to_main',
            'commit_sha' => $commitSha,
        ]);
        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'task_resolved_committed',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'commit_sha' => $commitSha,
        ]);

        return $this->envelope('task_resolved', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'commit_sha' => $commitSha,
            'queue_transition' => (string) ($transition['status'] ?? ''),
        ]);
    }

    /**
     * Finalises the dry-run cycle: the lease is released and the queue
     * record moves to `completed_dry_run`. Real completion remains forbidden.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function completeDryRun(string $taskPacketId, string $leaseId, array $evidence = []): array
    {
        $lease = $this->leases->get($leaseId);
        if ($lease === null) {
            return $this->envelope('complete_dry_run_blocked', ['reason' => 'lease_not_found']);
        }
        if ((string) $lease['task_packet_id'] !== $taskPacketId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_lease_mismatch',
                'lease_task_packet_id' => (string) $lease['task_packet_id'],
                'requested_task_packet_id' => $taskPacketId,
            ]);
        }
        if ((string) $lease['lease_status'] !== AgentControlPlaneClaimLeaseRepository::LEASE_STATUS_ACTIVE) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'lease_not_active',
                'lease_status' => (string) $lease['lease_status'],
            ]);
        }
        $queueRecord = $this->queue->get($taskPacketId);
        if ($queueRecord === null) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_not_found',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
            ]);
        }
        $agentId = (string) $lease['agent_id'];
        $queueStatus = (string) ($queueRecord['status'] ?? '');
        $queueLeaseId = (string) data_get($queueRecord, 'metadata.lease_id', '');
        $queueAgentId = (string) data_get($queueRecord, 'metadata.agent_id', '');
        if ($queueStatus !== 'claimed') {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'task_packet_not_claimed_for_completion',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_status' => $queueStatus,
                'completion_real_allowed' => false,
            ]);
        }
        if ($queueLeaseId === '' || $queueLeaseId !== $leaseId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'queue_lease_id_mismatch',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_lease_id' => $queueLeaseId,
                'completion_real_allowed' => false,
            ]);
        }
        if ($queueAgentId === '' || $queueAgentId !== $agentId) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'queue_agent_id_mismatch',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'agent_id' => $agentId,
                'queue_agent_id' => $queueAgentId,
                'completion_real_allowed' => false,
            ]);
        }
        $completionEvidence = $this->completionEvidence($evidence);
        $evidenceValidation = $this->validateCompletionEvidence($completionEvidence, [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'allowed_files' => $this->allowedFilesForRecord($queueRecord),
        ]);
        if ($evidenceValidation['blockers'] !== []) {
            return $this->envelope('complete_dry_run_blocked', [
                'reason' => (string) $evidenceValidation['blockers'][0],
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'evidence_validation' => $evidenceValidation,
                'completion_real_allowed' => false,
            ]);
        }

        $release = $this->leases->release($leaseId, $agentId, ['reason' => 'completed_dry_run']);

        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'dry_run_completion_recorded',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'evidence_hash' => (string) $evidenceValidation['evidence_hash'],
            'evidence_validation_status' => (string) $evidenceValidation['status'],
            'evidence_validation_hash' => (string) $evidenceValidation['evidence_validation_hash'],
            'evidence_validation_blockers' => (array) $evidenceValidation['blockers'],
            'structured_completion_evidence_required' => true,
            'structured_completion_evidence_valid' => (bool) $evidenceValidation['structured_completion_evidence_valid'],
            'queue_claim_binding_verified' => true,
            'queue_status_at_completion' => $queueStatus,
            'queue_lease_id' => $queueLeaseId,
            'queue_agent_id' => $queueAgentId,
            'files_changed_within_allowed_scope' => (bool) $evidenceValidation['files_changed_within_allowed_scope'],
            'files_changed_outside_allowed_scope' => (array) $evidenceValidation['files_changed_outside_allowed_scope'],
            'evidence_keys' => array_keys($completionEvidence),
            'evidence_digest' => hash('sha256', (string) json_encode($completionEvidence, JSON_THROW_ON_ERROR)),
        ]);
        $update = $this->queue->updateStatus($taskPacketId, 'completed_dry_run', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
        ]);

        return $this->envelope('completed_dry_run', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'release' => $release,
            'queue_update' => $update,
            'evidence_validation' => $evidenceValidation,
            'queue_claim_binding_verified' => true,
            'queue_status_at_completion' => $queueStatus,
            'queue_lease_id' => $queueLeaseId,
            'queue_agent_id' => $queueAgentId,
            'completion_real_allowed' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function completionEvidence(array $evidence): array
    {
        return TaskQueue\AgentControlPlaneCompletionEvidenceValidator::completionEvidence($evidence);
    }

    private function validateCompletionEvidence(array $evidence, array $expectedBinding): array
    {
        return TaskQueue\AgentControlPlaneCompletionEvidenceValidator::validateCompletionEvidence($evidence, $expectedBinding);
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public static function canonicalCompletionEvidenceHash(array $evidence): string
    {
        return TaskQueue\AgentControlPlaneCompletionEvidenceValidator::canonicalCompletionEvidenceHash($evidence);
    }

    private static function normalizeEvidenceForHash(mixed $value): mixed
    {
        return TaskQueue\AgentControlPlaneCompletionEvidenceValidator::normalizeEvidenceForHash($value);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<string>
     */
    private function allowedFilesForRecord(array $record): array
    {
        $allowed = $this->stringList((array) data_get($record, 'task_packet.normalized_scope.allowed_files', []));
        if ($allowed !== []) {
            return $allowed;
        }

        return $this->stringList((array) data_get($record, 'task_packet.allowed_files', []));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $event, array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'event' => $event,
            'orchestration_id' => (string) Str::uuid(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'task_queue_orchestrator_does_not_start_codex',
                'task_queue_orchestrator_does_not_call_codex_cli_or_app',
                'task_queue_orchestrator_does_not_spawn_subprocess',
                'task_queue_orchestrator_does_not_invoke_adapter',
                'task_queue_orchestrator_does_not_call_provider',
                'task_queue_orchestrator_does_not_dispatch_work',
                'task_queue_orchestrator_does_not_spend_tokens',
                'task_queue_orchestrator_does_not_enable_self_programming',
                'task_queue_orchestrator_does_not_write_ledger',
                'task_queue_orchestrator_does_not_mutate_pointer',
                'task_queue_orchestrator_does_not_mark_real_completion',
            ],
        ], $payload);
    }
}
