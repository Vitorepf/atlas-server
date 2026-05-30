<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Axis N · Fleet AUDIT LEDGER (append-only JSONL, replayable, crash-recovery anchor).
 *
 * Records the lifecycle of every parallel fleet run as an append-only stream of events
 * under storage/, one event per line, scoped by (plan_id, area_id). The ledger is the
 * fleet's observability + crash-recovery substrate. It NEVER merges, NEVER mutates git,
 * NEVER trusts a worker's claim of success — it only records what the runner/integrator
 * actually did, with the tracker remaining the sole authority on delivery.
 *
 * Event kinds (each carries plan_id/area_id/recorded_at + an event_hash over the
 * non-volatile fields):
 *   - KIND_BATCH_PLANNED   : a batch of slice_ids was selected for parallel dispatch.
 *   - KIND_WORKER_CLAIMED  : a worker took a lease on a slice (a worktree began). A
 *                            CLAIMED slice with no terminal event is an in-flight (or
 *                            crashed-mid-run) worker on resume.
 *   - KIND_WORKER_RETURNED : a worker produced a cycle (terminal for the lease).
 *   - KIND_MERGED          : the integrator derived a real delivery for the slice (carries
 *                            merge_hash + cycle_id). This is the never-double-merge anchor.
 *   - KIND_DEFERRED        : the slice was deferred (file conflict / panel refutation).
 *   - KIND_NO_PROGRESS     : the slice cleared the panel but the tracker derived no delivery.
 *
 * Crash-recovery / idempotency (git/tracker is source of truth, the ledger mirrors it):
 *   A worker/worktree that died mid-run leaves a KIND_WORKER_CLAIMED with no matching
 *   terminal event. On resume the runner reads {@see mergedSliceIds()} and seeds them into
 *   its skip set so an already-merged slice is NEVER re-dispatched and NEVER double-merged —
 *   reinforcing the tracker's own idempotent (last-event-per-slice) delivery derivation.
 *   {@see reclaimableSliceIds()} surfaces the orphaned claims so the next batch re-plans
 *   them cleanly against the now-updated tree.
 *
 * Replay: {@see replay()} folds the whole stream into a deterministic state with NO side
 * effects, corruption-tolerant (unparseable lines counted, never fatal).
 */
final class FleetAuditLedgerService
{
    public const EVENT_SCHEMA = 'atlas.axis_n.fleet_audit_event.v1';

    public const REPLAY_SCHEMA = 'atlas.axis_n.fleet_audit_replay.v1';

    public const KIND_BATCH_PLANNED = 'batch_planned';

    public const KIND_WORKER_CLAIMED = 'worker_claimed';

    public const KIND_WORKER_RETURNED = 'worker_returned';

    public const KIND_MERGED = 'merged';

    public const KIND_DEFERRED = 'deferred';

    public const KIND_NO_PROGRESS = 'no_progress';

    private const KINDS = [
        self::KIND_BATCH_PLANNED,
        self::KIND_WORKER_CLAIMED,
        self::KIND_WORKER_RETURNED,
        self::KIND_MERGED,
        self::KIND_DEFERRED,
        self::KIND_NO_PROGRESS,
    ];

    private ?string $storageRootOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    /**
     * Record a batch-planning event (the slice_ids selected for one parallel batch).
     *
     * @param  list<string>  $sliceIds
     */
    public function recordBatchPlanned(string $planId, string $areaId, int $batchIndex, array $sliceIds, int $maxParallel): void
    {
        $this->append($planId, $areaId, [
            'kind' => self::KIND_BATCH_PLANNED,
            'batch_index' => $batchIndex,
            'slice_ids' => array_values(array_map('strval', $sliceIds)),
            'batch_size' => count($sliceIds),
            'max_parallel' => max(1, $maxParallel),
        ]);
    }

    /**
     * Record a worker lease (a worktree began for a slice). Recorded BEFORE the worker runs
     * so a mid-run crash leaves a claim with no terminal event (reclaimable on resume).
     */
    public function recordWorkerClaimed(string $planId, string $areaId, int $batchIndex, string $sliceId): void
    {
        $this->append($planId, $areaId, [
            'kind' => self::KIND_WORKER_CLAIMED,
            'batch_index' => $batchIndex,
            'slice_id' => $sliceId,
        ]);
    }

    /**
     * Record that a worker produced a cycle (terminal for the lease, NOT a delivery claim).
     */
    public function recordWorkerReturned(string $planId, string $areaId, int $batchIndex, string $sliceId, string $cycleId): void
    {
        $this->append($planId, $areaId, [
            'kind' => self::KIND_WORKER_RETURNED,
            'batch_index' => $batchIndex,
            'slice_id' => $sliceId,
            'cycle_id' => $cycleId,
        ]);
    }

    /**
     * Record an integration disposition for a slice. A MERGED disposition is the
     * never-double-merge anchor (carries merge_hash + cycle_id). DEFERRED/NO_PROGRESS are
     * recorded with their honest reason. Maps the integrator's disposition vocabulary onto
     * the audit kinds.
     *
     * @param  array<string,mixed>  $disposition  one FleetIntegratorService disposition row
     */
    public function recordIntegration(string $planId, string $areaId, int $batchIndex, array $disposition, string $cycleId = '', string $mergeHash = ''): void
    {
        $sliceId = (string) ($disposition['slice_id'] ?? '');
        $disp = (string) ($disposition['disposition'] ?? '');
        $reason = (string) ($disposition['reason'] ?? '');

        if ($disp === FleetIntegratorService::DISPOSITION_MERGED) {
            $this->append($planId, $areaId, [
                'kind' => self::KIND_MERGED,
                'batch_index' => $batchIndex,
                'slice_id' => $sliceId,
                'cycle_id' => $cycleId,
                'merge_hash' => $mergeHash,
                'reason' => $reason,
            ]);

            return;
        }

        if (str_starts_with($disp, 'deferred')) {
            $this->append($planId, $areaId, [
                'kind' => self::KIND_DEFERRED,
                'batch_index' => $batchIndex,
                'slice_id' => $sliceId,
                'disposition' => $disp,
                'reason' => $reason,
            ]);

            return;
        }

        $this->append($planId, $areaId, [
            'kind' => self::KIND_NO_PROGRESS,
            'batch_index' => $batchIndex,
            'slice_id' => $sliceId,
            'reason' => $reason,
        ]);
    }

    /**
     * Slice ids the ledger has already recorded as MERGED. The crash-recovery skip set:
     * a resumed run never re-dispatches (and so never double-merges) these.
     *
     * @return list<string>
     */
    public function mergedSliceIds(string $planId, string $areaId): array
    {
        $state = $this->replay($planId, $areaId);

        return $state['merged_slice_ids'];
    }

    /**
     * Slice ids with a worker lease (CLAIMED) but no terminal event (RETURNED/MERGED/
     * DEFERRED/NO_PROGRESS) — orphaned by a mid-run crash. The next batch re-plans them.
     *
     * @return list<string>
     */
    public function reclaimableSliceIds(string $planId, string $areaId): array
    {
        $state = $this->replay($planId, $areaId);

        return $state['reclaimable_slice_ids'];
    }

    /**
     * Deterministic, side-effect-free fold of the whole event stream.
     *
     * @return array{
     *   schema_version:string, plan_id:string, area_id:string, total_events:int,
     *   corrupted_lines:int, batches_planned:int, workers_claimed:int, workers_returned:int,
     *   merged_count:int, deferred_count:int, no_progress_count:int,
     *   merged_slice_ids:list<string>, deferred_slice_ids:list<string>,
     *   reclaimable_slice_ids:list<string>, batch_sizes:list<int>, max_parallel_seen:int
     * }
     */
    public function replay(string $planId, string $areaId): array
    {
        [$events, $corrupted] = $this->readRows($this->ledgerPath($planId, $areaId));

        $batchesPlanned = 0;
        $workersClaimed = 0;
        $workersReturned = 0;
        $mergedSet = [];
        $deferredSet = [];
        $noProgressSet = [];
        $claimed = [];      // slice_id => true (lease taken)
        $terminated = [];   // slice_id => true (any terminal event seen)
        $batchSizes = [];
        $maxParallelSeen = 0;

        foreach ($events as $event) {
            $kind = (string) ($event['kind'] ?? '');
            $sliceId = (string) ($event['slice_id'] ?? '');

            switch ($kind) {
                case self::KIND_BATCH_PLANNED:
                    $batchesPlanned++;
                    $batchSizes[] = (int) ($event['batch_size'] ?? 0);
                    $maxParallelSeen = max($maxParallelSeen, (int) ($event['max_parallel'] ?? 0));
                    break;
                case self::KIND_WORKER_CLAIMED:
                    $workersClaimed++;
                    if ($sliceId !== '') {
                        $claimed[$sliceId] = true;
                    }
                    break;
                case self::KIND_WORKER_RETURNED:
                    $workersReturned++;
                    break;
                case self::KIND_MERGED:
                    if ($sliceId !== '') {
                        $mergedSet[$sliceId] = true;
                        $terminated[$sliceId] = true;
                        // A merge supersedes any earlier deferral/no-progress for the slice.
                        unset($deferredSet[$sliceId], $noProgressSet[$sliceId]);
                    }
                    break;
                case self::KIND_DEFERRED:
                    if ($sliceId !== '' && ! isset($mergedSet[$sliceId])) {
                        $deferredSet[$sliceId] = true;
                        $terminated[$sliceId] = true;
                    }
                    break;
                case self::KIND_NO_PROGRESS:
                    if ($sliceId !== '' && ! isset($mergedSet[$sliceId])) {
                        $noProgressSet[$sliceId] = true;
                        $terminated[$sliceId] = true;
                    }
                    break;
            }
        }

        $reclaimable = [];
        foreach (array_keys($claimed) as $sliceId) {
            if (! isset($terminated[$sliceId])) {
                $reclaimable[$sliceId] = true;
            }
        }

        return [
            'schema_version' => self::REPLAY_SCHEMA,
            'plan_id' => $planId,
            'area_id' => $areaId,
            'total_events' => count($events),
            'corrupted_lines' => $corrupted,
            'batches_planned' => $batchesPlanned,
            'workers_claimed' => $workersClaimed,
            'workers_returned' => $workersReturned,
            'merged_count' => count($mergedSet),
            'deferred_count' => count($deferredSet),
            'no_progress_count' => count($noProgressSet),
            'merged_slice_ids' => array_keys($mergedSet),
            'deferred_slice_ids' => array_keys($deferredSet),
            'reclaimable_slice_ids' => array_keys($reclaimable),
            'batch_sizes' => $batchSizes,
            'max_parallel_seen' => $maxParallelSeen,
        ];
    }

    public function ledgerPath(string $planId, string $areaId): string
    {
        return $this->storageDir($planId, $areaId).DIRECTORY_SEPARATOR.'fleet_audit_ledger.jsonl';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function append(string $planId, string $areaId, array $payload): void
    {
        if (! in_array((string) ($payload['kind'] ?? ''), self::KINDS, true)) {
            return; // never write an unknown kind
        }

        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'plan_id' => $planId,
            'area_id' => $areaId,
            'recorded_at' => $this->now(),
        ] + $payload;
        $event['event_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->withoutVolatile($event));

        $path = $this->ledgerPath($planId, $areaId);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function readRows(string $path): array
    {
        if (! is_file($path)) {
            return [[], 0];
        }
        $rows = [];
        $corrupted = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['kind']) && is_string($decoded['kind'])) {
                $rows[] = $decoded;
            } else {
                $corrupted++;
            }
        }

        return [$rows, $corrupted];
    }

    /**
     * @param  array<string,mixed>  $event
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $event): array
    {
        unset($event['recorded_at'], $event['event_hash']);

        return $event;
    }

    private function storageDir(string $planId, string $areaId): string
    {
        $root = $this->storageRootOverride ?? $this->defaultRoot();

        return rtrim($root, '/')
            .DIRECTORY_SEPARATOR.$this->slug($areaId)
            .DIRECTORY_SEPARATOR.$this->slug($planId);
    }

    private function defaultRoot(): string
    {
        if (function_exists('storage_path')) {
            try {
                return storage_path('atlas/axis_n/fleet_audit');
            } catch (\Throwable) {
                // Fall through to the temp-dir fallback (e.g. unbootstrapped unit TestCase).
            }
        }

        return sys_get_temp_dir().'/atlas/axis_n/fleet_audit';
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'unscoped';
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
