<?php

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionOrchestrator;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
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
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\Maestro\Health\Maestro;

final class AgentControlPlaneTaskQueueOrchestrator
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_task_queue_orchestrator.v1';

    public const MODE = 'persistent_local_agent_control_plane_task_queue_orchestrator';

    private const MAX_ANTI_FARM_CANDIDATES = 64;

    public function __construct(
        private readonly AgentControlPlaneTaskPacketBuilder $builder,
        private readonly AgentControlPlaneScopeLockRuntimeValidator $validator,
        private readonly AgentControlPlaneTaskPacketQueueRepository $queue,
        private readonly AgentControlPlaneClaimLeaseRepository $leases,
        private readonly AgentControlPlaneEvidenceLedgerDryRun $evidence,
        private readonly AgentControlPlaneContinuationSummaryBuilder $continuation,
        private readonly ?string $commitRepositoryRoot = null,
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

        // Anti-farm gate: reject near-duplicate / template-farm candidates before they cost muscle.
        // Unavailable authority must block admission; accepting it would turn a broken duplicate
        // control into more queue work.
        try {
            $antiFarmBlock = $this->checkAntiFarmGates($packet, $packetInput);
            if ($antiFarmBlock !== null) {
                return $this->envelope('prepare_blocked', array_merge([
                    'task_packet' => $packet,
                    'validation' => $validation,
                    'queue_entry' => null,
                    'evidence_plan' => null,
                    'continuation_summary' => null,
                ], $antiFarmBlock));
            }
        } catch (Throwable $e) {
            return $this->envelope('prepare_blocked', [
                'task_packet' => $packet,
                'validation' => $validation,
                'queue_entry' => null,
                'evidence_plan' => null,
                'continuation_summary' => null,
                'reason' => 'anti_farm_gate_unavailable',
                'anti_farm_gate' => ['status' => 'unavailable', 'error_class' => $e::class],
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
                // Persist built metadata for a later semantic-duplicate check.
                'capability_key' => trim((string) data_get($packet, 'metadata.capability_key', '')),
                'target_family' => trim((string) data_get($packet, 'metadata.target_family', '')),
                'acceptance_intent' => trim((string) data_get($packet, 'metadata.acceptance_intent', '')),
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

    /** @return array<string,mixed>|null */
    private function checkAntiFarmGates(array $packet, array $packetInput): ?array
    {
        $candidateId = (string) ($packet['task_packet_id'] ?? '');
        $registry = $this->queue->registry(['status' => 'claimable'], true);
        $claimableCount = (int) ($registry['entry_count'] ?? 0);
        $claimableIds = array_column((array) ($registry['entries'] ?? []), 'task_packet_id');
        if ($claimableCount > self::MAX_ANTI_FARM_CANDIDATES && ! in_array($candidateId, $claimableIds, true)) {
            return [
                'reason' => 'anti_farm_queue_scan_limit_exceeded',
                'anti_farm_gate' => [
                    'status' => 'blocked',
                    'claimable_count' => $claimableCount,
                    'scan_limit' => self::MAX_ANTI_FARM_CANDIDATES,
                ],
            ];
        }

        // Preserve idempotent re-enqueue of the same packet id.
        $existingEntries = array_values(array_filter(
            $this->queue->list(['status' => 'claimable', 'limit' => self::MAX_ANTI_FARM_CANDIDATES]),
            static fn (array $entry): bool => $candidateId === '' || (string) ($entry['task_packet_id'] ?? '') !== $candidateId,
        ));
        if ($existingEntries === []) {
            return null;
        }

        $candidateForFarm = [
            'objective' => (string) ($packet['objective'] ?? ''),
            'acceptance_criteria' => (array) ($packet['acceptance_criteria'] ?? []),
            'allowed_files' => (array) data_get($packet, 'normalized_scope.allowed_files', []),
        ];

        $farmGate = new TaskFabric\AtlasTaskFabricTemplateFarmSimilarityGate;
        $matchedFarmIds = [];
        $lastFarmAssessment = null;
        foreach ($existingEntries as $entry) {
            $existingPacket = (array) ($entry['task_packet'] ?? []);
            $existingForFarm = [
                'objective' => (string) ($existingPacket['objective'] ?? ''),
                'acceptance_criteria' => (array) ($existingPacket['acceptance_criteria'] ?? []),
                'allowed_files' => (array) data_get($existingPacket, 'normalized_scope.allowed_files', []),
            ];
            $assessment = $farmGate->assess([$candidateForFarm, $existingForFarm]);
            if ((bool) ($assessment['blocking'] ?? false)) {
                $matchedFarmIds[] = (string) ($entry['task_packet_id'] ?? '');
                $lastFarmAssessment = $assessment;
            }
        }

        if ($matchedFarmIds !== []) {
            return [
                'reason' => 'template_farm_similarity',
                'matched_task_packet_ids' => $matchedFarmIds,
                'template_farm_similarity_gate' => $lastFarmAssessment,
            ];
        }

        $candidateForDup = [
            'task_packet_id' => (string) ($packet['task_packet_id'] ?? ''),
            // Use the built packet because callers frequently omit derived metadata.
            'capability_key' => trim((string) data_get($packet, 'metadata.capability_key', '')),
            'target_family' => trim((string) data_get($packet, 'metadata.target_family', '')),
            'allowed_files' => (array) data_get($packet, 'normalized_scope.allowed_files', []),
            'acceptance_intent' => trim((string) data_get($packet, 'metadata.acceptance_intent', '')),
        ];
        $existingForDup = array_map(static function (array $entry): array {
            return [
                'task_packet_id' => (string) ($entry['task_packet_id'] ?? ''),
                'capability_key' => (string) data_get($entry, 'metadata.capability_key', ''),
                'target_family' => (string) data_get($entry, 'metadata.target_family', ''),
                'allowed_files' => (array) data_get($entry, 'task_packet.normalized_scope.allowed_files', []),
                'acceptance_intent' => (string) data_get($entry, 'metadata.acceptance_intent', ''),
            ];
        }, $existingEntries);

        $dupIndex = new TaskFabric\AtlasTaskFabricSemanticDuplicateIndex;
        $dupResult = $dupIndex->check($candidateForDup, $existingForDup);
        if ((string) ($dupResult['status'] ?? '') === TaskFabric\AtlasTaskFabricSemanticDuplicateIndex::DUPLICATE_SEMANTIC) {
            return [
                'reason' => 'semantic_duplicate',
                'matched_task_packet_ids' => array_values(array_filter([(string) ($dupResult['matched_packet_id'] ?? '')])),
                'semantic_duplicate_index' => $dupResult,
            ];
        }

        return null;
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
        // BEHAVIOR DEMOTION (flag-gated, soft): within a wave, families THIS worker has repeatedly given back
        // (durable worker-behavior ledger, written by the outcome bridge) sort LAST — never skipped, so a task
        // can never starve: the worker still claims it when nothing better exists, and other workers see the
        // normal order. This is the read side of the learning circuit: outcome → ledger → next claim.
        $demote = $this->behaviorDemotionScorer($agentId);
        usort($candidates, static function (array $a, array $b) use ($demote): int {
            return [((int) data_get($a, 'metadata.wave', 0)), $demote($a)]
                <=> [((int) data_get($b, 'metadata.wave', 0)), $demote($b)];
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
    /**
     * Returns a candidate → {0,1} scorer for the claim sort: 1 = demote (this worker has enough durable
     * give-back evidence on the candidate's scope-family). OFF by default (atlas.maestro.adaptive.
     * behavior_ledger_enabled) and fail-open: any hiccup returns the all-zeros scorer (today's ordering).
     * Recalls are memoized per family — the ledger hydrates once, each family resolves once per claim call.
     *
     * @return callable(array<string,mixed>):int
     */
    private function behaviorDemotionScorer(string $agentId): callable
    {
        $zero = static fn (array $candidate): int => 0;
        try {
            if (! (bool) config('atlas.maestro.adaptive.behavior_ledger_enabled', false)) {
                return $zero;
            }
            $minEvents = max(1, (int) config('atlas.maestro.adaptive.demotion_min_events', 3));
            $minRate = (float) config('atlas.maestro.adaptive.demotion_give_back_rate', 0.5);
            $ledger = new AtlasMaestroWorkerBehaviorLedger;
            $memo = [];

            return static function (array $candidate) use ($ledger, $agentId, $minEvents, $minRate, &$memo): int {
                try {
                    $family = self::scopeFamily((array) data_get($candidate, 'task_packet.normalized_scope.allowed_files', []));
                    if (! array_key_exists($family, $memo)) {
                        $facts = $ledger->recall($agentId, $family);
                        $memo[$family] = ((int) $facts['total_events'] >= $minEvents
                            && (float) $facts['give_back_rate'] >= $minRate) ? 1 : 0;
                    }

                    return $memo[$family];
                } catch (Throwable) {
                    return 0;
                }
            };
        } catch (Throwable) {
            return $zero;
        }
    }

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

        // ORDER: a task is servable only when its prerequisites are MET. Missing, cancelled, and cyclic
        // dependencies remain gated until repaired; no invalid dependency graph may authorize a worker.
        return $this->classifyDependencies($candidate, $cache) === 'met';
    }

    /** A dependency is SATISFIED only after its dry-run completion is recorded. */
    private const DEPENDENCY_SATISFIED_STATES = ['completed_dry_run'];

    /** A dependency here is unmet and requires operator repair before its dependent can run. */
    private const DEPENDENCY_DEAD_STATES = ['blocked', 'cancelled'];

    /**
     * Classify a candidate's depends_on into the gate/wait verdict — the single source of truth for ordering:
     *   - 'met'      every prerequisite is satisfied ⇒ SERVABLE now.
     *   - 'inflight' ≥1 unmet prerequisite is still moving (queued/claimable/claimed/lease_expired/released) ⇒
     *                the version-ladder IS advancing; a worker should WAIT.
     *   - 'blocked'  unmet prerequisites exist but ALL are DEAD (quarantined) ⇒ the ladder is NOT advancing;
     *                this is escalation, never a "just wait" — so it can't masquerade as waiting_on_dependencies.
     *
     * A prerequisite is met only by recorded completion. Missing, cancelled, or cyclic prerequisites are
     * blocked for operator repair; silently serving them would execute outside a valid dependency order.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     */
    private function classifyDependencies(array $candidate, array &$cache): string
    {
        return TaskQueue\AgentControlPlaneTaskDependencyClassifier::classifyDependencies(
            $this->nodeLoaderClosure(),
            $candidate,
            $cache,
        );
    }

    /**
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @return array{status:string, depends_on:list<string>}|null
     */
    private function dependencyNode(string $id, array &$cache): ?array
    {
        return TaskQueue\AgentControlPlaneTaskDependencyClassifier::dependencyNode(
            $this->nodeLoaderClosure(),
            $id,
            $cache,
        );
    }

    /**
     * @param  array<string, array{status:string, depends_on:list<string>}|null>  $cache
     * @param  array<string, bool>  $seen
     */
    private function dependencyReaches(string $fromId, string $targetId, array &$cache, array $seen): bool
    {
        return TaskQueue\AgentControlPlaneTaskDependencyClassifier::dependencyReaches(
            $this->nodeLoaderClosure(),
            $fromId,
            $targetId,
            $cache,
            $seen,
        );
    }

    /**
     * Closure used by the dependency-classifier delegates to load queue nodes
     * without coupling the new collaborator to the queue repository class.
     */
    private function nodeLoaderClosure(): \Closure
    {
        $queue = $this->queue;

        return static fn (string $id): ?array => $queue->get($id);
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
     * Routing thresholds for {@see classifyPacket()}.
     */
    private const POISON_RISK_QUARANTINE_THRESHOLD = 0.70;

    /**
     * Pure, stateless packet router: classifies a task packet into serve,
     * repair, quarantine, or defer using only fields already present on the
     * packet (scope, freshness, duplicate, and poison-risk evidence) before
     * a worker burns tokens claiming it. Does not query the queue, leases,
     * or disk — callers that need fresh duplicate/poison signals must
     * compute them and place the result on the packet before calling.
     *
     * Decision priority (first match wins):
     *   1. allowed_files is empty                                  -> quarantine: implementation_missing_no_allowed_files
     *   2. every allowed_files entry lives under tests/ (no app/ target) -> quarantine: test_only_packet_no_implementation_target
     *   3. duplicate_of is non-empty                                -> quarantine: duplicate_target
     *   4. poison_risk_score >= POISON_RISK_QUARANTINE_THRESHOLD (0.70) -> quarantine: high_poison_risk_score
     *   5. is_stale=true                                            -> defer: stale_context_requires_refresh
     *   6. allowed_files contains a path not covered by scope_in     -> repair: scope_in_does_not_cover_allowed_files
     *   7. acceptance_criteria is empty                              -> repair: missing_acceptance_criteria
     *   8. otherwise                                                  -> serve: packet_passes_classification_checks
     *
     * @param  array<string, mixed>  $packet
     * @return array{decision: string, reason: string}
     */
    public function classifyPacket(array $packet): array
    {
        $allowedFiles = array_values(array_map('strval', (array) (
            data_get($packet, 'normalized_scope.allowed_files', $packet['allowed_files'] ?? [])
        )));
        $scopeIn = array_values(array_map('strval', (array) (
            data_get($packet, 'normalized_scope.scope_in', $packet['scope_in'] ?? [])
        )));
        $acceptanceCriteria = (array) ($packet['acceptance_criteria'] ?? []);
        $duplicateOf = trim((string) ($packet['duplicate_of'] ?? ''));
        $poisonRiskScore = (float) ($packet['poison_risk_score'] ?? 0.0);
        $isStale = (bool) ($packet['is_stale'] ?? false);

        if ($allowedFiles === []) {
            return ['decision' => 'quarantine', 'reason' => 'implementation_missing_no_allowed_files'];
        }

        $hasNonTestTarget = false;
        foreach ($allowedFiles as $file) {
            if (! str_contains($file, 'tests/') && ! str_ends_with($file, 'Test.php')) {
                $hasNonTestTarget = true;
                break;
            }
        }
        if (! $hasNonTestTarget) {
            return ['decision' => 'quarantine', 'reason' => 'test_only_packet_no_implementation_target'];
        }

        if ($duplicateOf !== '') {
            return ['decision' => 'quarantine', 'reason' => 'duplicate_target'];
        }

        if ($poisonRiskScore >= self::POISON_RISK_QUARANTINE_THRESHOLD) {
            return ['decision' => 'quarantine', 'reason' => 'high_poison_risk_score'];
        }

        if ($isStale) {
            return ['decision' => 'defer', 'reason' => 'stale_context_requires_refresh'];
        }

        foreach ($allowedFiles as $file) {
            if (! in_array($file, $scopeIn, true)) {
                return ['decision' => 'repair', 'reason' => 'scope_in_does_not_cover_allowed_files'];
            }
        }

        if ($acceptanceCriteria === []) {
            return ['decision' => 'repair', 'reason' => 'missing_acceptance_criteria'];
        }

        return ['decision' => 'serve', 'reason' => 'packet_passes_classification_checks'];
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
        $guard = new AtlasLoopHarnessGuard;
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
        $guard = new AtlasLoopHarnessGuard;
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

            // 'test_only_microtask_requires_contract' is the SAME fact the RETIRE branch below
            // handles (only test paths buildable) — a scope-doomed test-only packet always carries
            // both, so counting it as "another deficiency" made retirement unreachable.
            $otherBlocking = array_values(array_diff($blocking, [
                'scope_repair_removed_required_target_from_allowed_files',
                'test_only_microtask_requires_contract',
            ]));
            if (! $selfSufficient && (! $isScopeRepairDoomed || $otherBlocking !== [])) {
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
        return TaskQueue\AgentControlPlaneScopeRepairInputRebuilder::isTestPath($path);
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $removedTargets
     * @return list<string>
     */
    private function petreoPathsToScrub(array $packet, array $removedTargets, AtlasLoopHarnessGuard $guard): array
    {
        return TaskQueue\AgentControlPlaneScopeRepairInputRebuilder::petreoPathsToScrub($packet, $removedTargets, $guard);
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $scrubPaths
     * @return array<string, mixed>
     */
    private function repairInputKeepingScope(array $packet, array $scrubPaths): array
    {
        return TaskQueue\AgentControlPlaneScopeRepairInputRebuilder::repairInputKeepingScope($packet, $scrubPaths);
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $forbiddenAllowed
     * @return array<string, mixed>
     */
    private function repairInputWithoutForbiddenTargets(array $packet, array $forbiddenAllowed): array
    {
        return TaskQueue\AgentControlPlaneScopeRepairInputRebuilder::repairInputWithoutForbiddenTargets($packet, $forbiddenAllowed);
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

    /** @return list<array<string,mixed>> */
    public function activeLeasesForAgent(string $agentId): array
    {
        return array_map(function (array $lease): array {
            $packet = $this->queue->get((string) ($lease['task_packet_id'] ?? ''));

            return $lease + [
                'allowed_files' => array_values(array_map('strval', (array) data_get($packet, 'task_packet.allowed_files', []))),
                'authority_hash' => (string) data_get($packet, 'task_packet.metadata.authority_hash', ''),
                'envelope_hash' => (string) data_get($packet, 'task_packet.metadata.envelope_hash', ''),
            ];
        }, $this->leases->activeLeases(['agent_id' => $agentId]));
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
            'learning_bridge' => $this->bridgeOutcomeToLearning($taskPacketId, 'give_back', [
                'agent_id' => $agentId,
                'give_back_reason' => $reason,
            ]),
        ]);
    }

    /**
     * Hands a resolved/completed/give_back outcome to the learning-transfer admission
     * orchestrator (composed here in its default OBSERVE mode — never modified, never armed
     * for APPLY) as a lesson-candidate fact. Fail-open: a learning-side exception never breaks
     * the caller's report; it only downgrades this bridge's own status to 'error'.
     *
     * @param  array<string,mixed>  $extra  agent_id, give_back_reason (when outcome=give_back)
     * @return array{status:string, outcome?:string, lesson_key?:mixed, error?:string}
     */
    /**
     * Green-run exemplar ledger path — derived from the admission ledger path
     * (`.jsonl` → `.resolved.jsonl`) so the phpunit env pin covers it with
     * zero new config, mirroring the observation store convention.
     */
    public static function resolvedReceiptsPath(): string
    {
        return (string) preg_replace(
            '/\.jsonl$/',
            '.resolved.jsonl',
            AtlasSelfConstructionLearningTransferAdmissionLedger::defaultPath(),
        );
    }

    private function bridgeOutcomeToLearning(string $taskPacketId, string $outcome, array $extra = []): array
    {
        try {
            $record = $this->queue->get($taskPacketId);
            $packet = (array) data_get($record, 'task_packet', []);
            $objective = (string) data_get($packet, 'objective', '');

            $fact = [
                'task_packet_id' => $taskPacketId,
                'outcome' => $outcome,
                'agent_id' => (string) ($extra['agent_id'] ?? ''),
                'allowed_files' => array_values((array) data_get($packet, 'normalized_scope.allowed_files', [])),
                'objective_digest' => hash('sha256', $objective),
                // r125 outcome-proof floor producers — only what this bridge
                // verifiably has (the c5 contract: the caller carries the
                // proof, the orchestrator never invents it). Without these,
                // EVERY live admit was refused_by_admission_floor and the
                // admission ledger never got a single row — the known_lessons
                // reader (w15) was reading an empty well. The packet id is a
                // real, resolvable reference (queue record + receipts).
                'evidence_refs' => ['task_packet:'.$taskPacketId],
                'impact_class' => 'packet_admission',
                'design_path_refs' => ['task_packet:'.$taskPacketId.'#objective:'.substr(hash('sha256', $objective), 0, 16)],
                // The ledger floor whitelists success-class outcomes only
                // ('success'|'resolved'|'green_commit'): a 'resolved' lesson
                // records; give_back / completed_dry_run still classify and
                // feed the family-recurrence signal, but the ledger refuses
                // them by floor design (visible in the w17 blocked-admission
                // histogram) — never faked into a success status here.
                'muscle_outcome' => [
                    'status' => $outcome,
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => (string) ($extra['agent_id'] ?? ''),
                ],
            ];
            if (isset($extra['give_back_reason'])) {
                // The classifier reads 'reason' to derive the lesson class.
                $fact['reason'] = (string) $extra['give_back_reason'];
                $fact['give_back_reason'] = (string) $extra['give_back_reason'];
            }

            // Worker-behavior ledger: the SAME outcome also becomes a durable
            // (agent × scope-family) fact so the claim path can demote serving
            // a family back to a worker that keeps giving it back. Identity is
            // the packet's dominant scope directory — the one identity real
            // packets actually carry (task_class/lane never exist on them).
            $this->recordWorkerBehavior(
                (string) ($extra['agent_id'] ?? ''),
                $fact['allowed_files'],
                $outcome,
                (string) ($extra['give_back_reason'] ?? ''),
            );

            $result = (new AtlasSelfConstructionLearningTransferAdmissionOrchestrator)->admit($fact);
            $admissionOutcome = (string) ($result['outcome'] ?? '');

            return [
                'status' => $admissionOutcome === 'admitted_and_recorded' ? 'accepted' : 'rejected',
                'outcome' => $admissionOutcome,
                'lesson_key' => $result['lesson_key'] ?? null,
                'fact' => $fact,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * GOVERNED SCOPE EXPANSION — a worker mid-refactor discovers the seam needs files the
     * packet did not anticipate. Instead of a dumb give_back (losing the WIP to the cooldown
     * ladder), it requests expansion WITH justification; the packet is rebuilt through the
     * SAME builder + quality-inspector + replaceBlockedTaskPacket machinery the repair path
     * uses (never a raw packet mutation — hashes and forbidden-axes stay enforced), audited
     * on the receipt chain, and returns claimable with NO give_back stamp — the requesting
     * worker reclaims it immediately and continues.
     *
     * Fail-closed: an invalid request (empty/too-broad file list, thin justification, a file
     * the builder refuses — pétreo, forbidden axis, traversal) refuses the expansion WITHOUT
     * touching the packet or the lease; the caller falls back to the normal give_back path.
     *
     * @param  list<string>  $files
     * @return array<string,mixed>
     */
    public function requestScopeExpansion(string $taskPacketId, string $leaseId, string $agentId, array $files, string $justification): array
    {
        $files = array_values(array_unique(array_filter(array_map(
            static fn ($f): string => trim((string) $f),
            $files,
        ), static fn (string $f): bool => $f !== '')));

        $refuse = fn (string $reason, array $extra = []): array => $this->envelope('scope_expansion_refused', array_merge([
            'task_packet_id' => $taskPacketId,
            'agent_id' => $agentId,
            'reason' => $reason,
        ], $extra));

        if ($files === [] || count($files) > 5) {
            return $refuse('expansion_must_name_1_to_5_files', ['requested' => count($files)]);
        }
        if (mb_strlen(trim($justification)) < 20) {
            return $refuse('justification_too_thin_name_the_seam_and_the_evidence');
        }

        $record = $this->queue->get($taskPacketId);
        $packet = (array) data_get($record, 'task_packet', []);
        if ($packet === [] || (string) data_get($record, 'status') !== 'claimed') {
            return $refuse('packet_not_claimed', ['actual_status' => (string) data_get($record, 'status')]);
        }

        $current = $this->stringList((array) data_get($packet, 'normalized_scope.allowed_files', []));
        $new = array_values(array_diff($files, $current));
        if ($new === []) {
            return $refuse('requested_files_already_in_scope');
        }
        if (count($current) + count($new) > AgentControlPlaneScopeLockRuntimeValidator::DEFAULT_MAX_FILES) {
            return $refuse('expansion_exceeds_max_scope_files', ['max' => AgentControlPlaneScopeLockRuntimeValidator::DEFAULT_MAX_FILES]);
        }

        // Rebuild FIRST (pure): the builder re-runs every scope gate (forbidden axes, pétreo,
        // traversal, risk). A refused build = a refused expansion, nothing mutated.
        $input = $this->repairInputKeepingScope($packet, []);
        $input['allowed_files'] = array_values(array_unique(array_merge($input['allowed_files'], $new)));
        $input['scope_in'] = array_values(array_unique(array_merge($input['scope_in'], $new)));
        $rebuilt = $this->builder->build($input);
        if ((string) ($rebuilt['status'] ?? '') !== 'planned') {
            return $refuse('builder_refused_expanded_scope', [
                'blocking_reasons' => array_values((array) ($rebuilt['blocking_reasons'] ?? [])),
            ]);
        }
        $quality = (new AtlasTaskPacketQualityInspector)->inspect($rebuilt);
        if (! (bool) ($quality['self_sufficient'] ?? false)) {
            return $refuse('expanded_packet_not_self_sufficient', [
                'blocking_deficiencies' => array_values((array) ($quality['blocking_deficiencies'] ?? [])),
            ]);
        }

        // Commit the expansion through the sanctioned mutation path: release the lease,
        // park claimed→blocked (the only replaceable state), swap in the rebuilt packet
        // (flips back to claimable), and leave the audit trail. Deliberately NO
        // give_back_count / last_give_back stamps: an expansion is not a failure, so the
        // requesting worker faces no cooldown and reclaims immediately.
        $this->leases->release($leaseId, $agentId, ['reason' => 'scope_expansion_requested']);
        $this->queue->updateStatus($taskPacketId, 'blocked', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'reason' => 'scope_expansion_requested',
        ]);
        $replace = $this->queue->replaceBlockedTaskPacket($taskPacketId, $rebuilt, [
            'reason' => 'scope_expansion_granted',
            'agent_id' => $agentId,
            'expanded_files' => $new,
            'expansion_justification' => $justification,
        ]);
        if ((string) ($replace['status'] ?? '') !== 'ok') {
            // Packet stays blocked — operator-recoverable and LOUD, never silently lost.
            return $refuse('expansion_replace_failed_packet_parked_blocked', [
                'replace_status' => (string) ($replace['status'] ?? 'unknown'),
            ]);
        }
        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'scope_expansion_granted',
            'agent_id' => $agentId,
            'expanded_files' => $new,
            'justification' => $justification,
        ]);

        return $this->envelope('scope_expanded', [
            'task_packet_id' => $taskPacketId,
            'agent_id' => $agentId,
            'expanded_files' => $new,
            'allowed_files' => array_values((array) data_get($rebuilt, 'normalized_scope.allowed_files', [])),
            'reclaimable_now' => true,
        ]);
    }

    /**
     * Report-path receipt append for composing gates (e.g. the refactor delta
     * proof) that live in the serving service but must leave their verdict on
     * the packet's durable receipt chain.
     *
     * @param  array<string,mixed>  $receipt
     */
    public function appendReportReceipt(string $taskPacketId, array $receipt): void
    {
        $this->queue->appendReceipt($taskPacketId, $receipt);
    }

    /**
     * Records the outcome into the durable worker-behavior ledger as an
     * (agent × scope-family) fact. Fail-open — a behavior-ledger hiccup never
     * breaks the caller's report. Read back by {@see claimNext}'s demotion sort.
     */
    private function recordWorkerBehavior(string $agentId, array $allowedFiles, string $outcome, string $giveBackReason): void
    {
        if ($agentId === '') {
            return;
        }
        try {
            $event = [
                'client_id' => $agentId,
                'task_family' => self::scopeFamily($allowedFiles),
                'outcome' => $outcome,
            ];
            if ($outcome === 'give_back' && $giveBackReason !== '') {
                // Classified family, not the free-form reason string — otherwise
                // topGiveBackCauses() fragments into one bucket per unique phrase.
                // An 'unknown' classification keeps the raw reason: losing the
                // signal is worse than one extra bucket.
                $family = (new Maestro\Adaptive\AtlasMaestroGiveBackPatternMiner)
                    ->classifyGiveBackReason(['give_back_reason' => $giveBackReason]);
                $event['root_cause_family'] = $family === 'unknown' ? $giveBackReason : $family;
            }
            (new AtlasMaestroWorkerBehaviorLedger)->record($event);
        } catch (Throwable) {
            // Fail-open by contract.
        }
    }

    /**
     * The ONE family identity real packets carry: the dominant top-two-segment
     * directory of the allowed_files scope (the same directory identity the
     * w28 lesson accumulator and the w30 known-lessons matcher use). Packets
     * never carry task_class/lane/task_family, so any key built on those would
     * write facts no reader could ever recall.
     */
    public static function scopeFamily(array $allowedFiles): string
    {
        $counts = [];
        foreach ($allowedFiles as $file) {
            $segments = explode('/', trim((string) $file, '/'));
            if ($segments === [] || $segments[0] === '') {
                continue;
            }
            $dir = implode('/', array_slice($segments, 0, min(2, max(1, count($segments) - 1))));
            $counts[$dir] = ($counts[$dir] ?? 0) + 1;
        }
        if ($counts === []) {
            return 'family:unscoped';
        }
        arsort($counts);

        return 'family:'.array_key_first($counts);
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
    public function quarantineClaimed(
        string $taskPacketId,
        string $leaseId,
        string $agentId,
        array $deficiencies = [],
        string $reason = 'packet_not_self_sufficient',
        string $receiptKind = 'packet_quarantined_not_self_sufficient',
    ): array {
        // Release the lease (registry only) so no active lease lingers; the queue record stays `claimed`.
        $this->leases->release($leaseId, $agentId, ['reason' => $reason]);

        $transition = $this->queue->updateStatus($taskPacketId, 'blocked', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'reason' => $reason,
            'blocking_deficiencies' => array_values($deficiencies),
        ]);
        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => $receiptKind,
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'reason' => $reason,
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
            // The seam decision (when present) — the refactor proof judges the WHOLE
            // seam, not one chain stage in isolation (an extraction stage alone always
            // grows; the payoff lands when the callers shed their copies).
            'refactor_design_spec' => data_get($packet, 'refactor_design_spec'),
            // K4 (Obra #18) — the frozen acceptance-test reference so the kit
            // conformance gate can prove the pre-written oracle was untouched.
            'acceptance_test_ref' => (array) data_get($packet, 'acceptance_test_ref', []),
        ];
    }

    /**
     * Shared-main RESOLVE close: after the scoped commit landed the work, release the lease and move the queue
     * record to its terminal `completed_dry_run` state, recording a canonical SHA. The commit must be reachable
     * from HEAD, carry the packet's Atlas-Task trailer, and change only the packet's allowed files before this
     * terminal owner accepts it; {@see completeDryRun} remains the evidence path for non-commit completions.
     *
     * @return array<string, mixed>
     */
    public function markResolved(string $taskPacketId, string $leaseId, string $agentId, string $commitSha): array
    {
        $queueRecord = $this->queue->get($taskPacketId);
        if ($queueRecord === null) {
            return $this->envelope('resolve_blocked', ['reason' => 'task_packet_not_found', 'task_packet_id' => $taskPacketId]);
        }

        if (preg_match('/^[a-f0-9]{40,64}$/i', $commitSha) !== 1) {
            return $this->envelope('resolve_blocked', ['reason' => 'commit_sha_invalid', 'task_packet_id' => $taskPacketId]);
        }

        $repo = $this->commitRepositoryRoot ?? base_path();
        $resolve = new Process(['git', 'rev-parse', '--verify', $commitSha.'^{commit}'], $repo);
        $resolve->setTimeout(3);
        $resolve->run();
        if (! $resolve->isSuccessful()) {
            return $this->envelope('resolve_blocked', ['reason' => 'commit_not_found', 'task_packet_id' => $taskPacketId]);
        }
        $commitSha = trim($resolve->getOutput());

        $ancestor = new Process(['git', 'merge-base', '--is-ancestor', $commitSha, 'HEAD'], $repo);
        $ancestor->setTimeout(3);
        $ancestor->run();
        if (! $ancestor->isSuccessful()) {
            return $this->envelope('resolve_blocked', ['reason' => 'commit_not_reachable_from_head', 'task_packet_id' => $taskPacketId]);
        }

        $message = new Process(['git', 'show', '-s', '--format=%B', $commitSha], $repo);
        $message->setTimeout(3);
        $message->run();
        if (! $message->isSuccessful() || ! str_contains($message->getOutput(), 'Atlas-Task: '.$taskPacketId)) {
            return $this->envelope('resolve_blocked', ['reason' => 'commit_task_binding_missing', 'task_packet_id' => $taskPacketId]);
        }

        $changedFiles = new Process(['git', 'diff-tree', '--no-commit-id', '--name-only', '-r', $commitSha], $repo);
        $changedFiles->setTimeout(3);
        $changedFiles->run();
        $outsideScope = array_values(array_diff(array_filter(explode("\n", trim($changedFiles->getOutput()))), $this->allowedFilesForRecord($queueRecord)));
        if (! $changedFiles->isSuccessful() || $outsideScope !== []) {
            return $this->envelope('resolve_blocked', ['reason' => 'commit_changed_files_outside_scope', 'task_packet_id' => $taskPacketId]);
        }

        // A daemon/provider retry may arrive after the first resolve released
        // the lease. Replay the same terminal result without appending a second
        // resolution or learning receipt. A different commit for the same
        // terminal packet remains blocked, so idempotency cannot hide drift.
        $queueStatus = (string) ($queueRecord['status'] ?? '');
        $resolvedCommitSha = (string) data_get($queueRecord, 'metadata.commit_sha', '');
        if ($queueStatus === 'completed_dry_run' && $resolvedCommitSha !== '') {
            if ($resolvedCommitSha !== $commitSha) {
                return $this->envelope('resolve_blocked', [
                    'reason' => 'task_already_resolved_with_different_commit',
                    'task_packet_id' => $taskPacketId,
                    'resolved_commit_sha' => $resolvedCommitSha,
                    'requested_commit_sha' => $commitSha,
                ]);
            }

            return $this->envelope('task_resolved', [
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'commit_sha' => $commitSha,
                'queue_transition' => 'completed_dry_run',
                'replayed' => true,
            ]);
        }

        $lease = $this->leases->get($leaseId);
        if ($lease === null || (string) $lease['task_packet_id'] !== $taskPacketId) {
            return $this->envelope('resolve_blocked', ['reason' => 'lease_not_found_or_mismatch', 'task_packet_id' => $taskPacketId]);
        }

        $queueLeaseId = (string) data_get($queueRecord, 'metadata.lease_id', '');
        $queueAgentId = (string) data_get($queueRecord, 'metadata.agent_id', '');
        if ($queueStatus !== 'claimed') {
            return $this->envelope('resolve_blocked', ['reason' => 'task_packet_not_claimed', 'task_packet_id' => $taskPacketId, 'queue_status' => $queueStatus]);
        }
        if ($queueLeaseId === '' || $queueLeaseId !== $leaseId) {
            return $this->envelope('resolve_blocked', ['reason' => 'queue_lease_id_mismatch', 'task_packet_id' => $taskPacketId, 'queue_lease_id' => $queueLeaseId]);
        }
        if ($queueAgentId === '' || $queueAgentId !== $agentId) {
            return $this->envelope('resolve_blocked', ['reason' => 'queue_agent_id_mismatch', 'task_packet_id' => $taskPacketId, 'queue_agent_id' => $queueAgentId]);
        }

        $transition = $this->queue->updateStatus($taskPacketId, 'completed_dry_run', [
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'resolution' => 'committed_to_main',
            'commit_sha' => $commitSha,
        ]);
        if ((string) ($transition['status'] ?? '') !== 'ok') {
            return $this->envelope('resolve_blocked', [
                'reason' => 'queue_completion_transition_failed',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_transition_status' => (string) ($transition['status'] ?? 'unknown'),
            ]);
        }

        $release = $this->leases->release($leaseId, $agentId, ['reason' => 'resolved_committed']);
        if ((string) ($release['status'] ?? '') !== 'ok') {
            return $this->envelope('resolve_blocked', [
                'reason' => 'lease_release_failed_after_queue_completion',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'queue_transition' => 'completed_dry_run',
                'lease_release_status' => (string) ($release['status'] ?? 'unknown'),
            ]);
        }

        $this->queue->appendReceipt($taskPacketId, [
            'receipt_kind' => 'task_resolved_committed',
            'lease_id' => $leaseId,
            'agent_id' => $agentId,
            'commit_sha' => $commitSha,
        ]);

        // Green-run exemplar ledger: one compact append per REAL resolution
        // (lease-validated + committed), so future packets of the same area
        // can be served a proven exemplar (the muscle-side analogue of the
        // Dev DevGreenRunExemplarRetriever). objective_excerpt rides because
        // an opaque id teaches nothing (w9 lesson). Fail-open: never breaks
        // the resolve.
        try {
            $packet = (array) data_get($queueRecord, 'task_packet', []);
            $objective = (string) data_get($packet, 'objective', '');
            (new JsonlReceiptStore(
                self::resolvedReceiptsPath(),
            ))->appendWith(static fn (?string $lastLine): ?array => [
                'schema_version' => 'atlas.self_construction.resolved_receipt.v1',
                'resolved_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'task_packet_id' => $taskPacketId,
                'allowed_files' => array_values(array_map('strval', (array) data_get($packet, 'normalized_scope.allowed_files', []))),
                'objective_excerpt' => mb_substr(trim($objective), 0, 140),
                'commit_sha' => $commitSha,
                'agent_id' => $agentId,
            ]);
        } catch (Throwable) {
            // fail-open
        }

        return $this->envelope('task_resolved', [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'commit_sha' => $commitSha,
            'queue_transition' => (string) ($transition['status'] ?? ''),
            'lease_release' => $release,
            'learning_bridge' => $this->bridgeOutcomeToLearning($taskPacketId, 'resolved', ['agent_id' => $agentId]),
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
        $existingRecord = $this->queue->get($taskPacketId);
        if ((string) ($existingRecord['status'] ?? '') === 'completed_dry_run') {
            $completionReceipt = collect((array) ($existingRecord['receipts'] ?? []))
                ->filter(static fn (mixed $receipt): bool => is_array($receipt) && ($receipt['receipt_kind'] ?? '') === 'dry_run_completion_recorded')
                ->last();
            $incomingHash = $evidence === []
                ? ''
                : (string) data_get($this->validateCompletionEvidence(
                    $this->completionEvidence($evidence),
                    ['task_packet_id' => $taskPacketId, 'lease_id' => $leaseId, 'agent_id' => '', 'allowed_files' => $this->allowedFilesForRecord($existingRecord)],
                ), 'evidence_hash', '');
            $recordedHash = (string) data_get($completionReceipt, 'evidence_hash', '');
            if ($incomingHash === '' || ($recordedHash !== '' && hash_equals($recordedHash, $incomingHash))) {
                return $this->envelope('completed_dry_run', [
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'queue_status_at_completion' => 'completed_dry_run',
                    'completion_real_allowed' => false,
                    'replayed' => true,
                ]);
            }

            return $this->envelope('complete_dry_run_blocked', [
                'reason' => 'dry_run_completion_replay_evidence_mismatch',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'completion_real_allowed' => false,
            ]);
        }

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
            'completion_evidence' => $completionEvidence,
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
            'learning_bridge' => $this->bridgeOutcomeToLearning($taskPacketId, 'completed_dry_run', ['agent_id' => $agentId]),
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
