<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Persistence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LOOP-OS · FASE 2 · SLICE 8 — the async DELIVERY PIPELINE substrate.
 *
 * The keystone topology fix: the unbounded designer↔critic PROJECTION ({@see \App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine})
 * must NOT run on the synchronous refiller hot-loop — else a worker that spends minutes projecting shows
 * "0 grinds" and the zombie-detector reclaims it, reintroducing the stall. Instead `produce()` DISPATCHES a
 * row here and returns in microseconds; a SEPARATE drainer CLAIMS the row under its OWN lease and runs the
 * projection. A claimed projection is OPEN work ({@see countOpenProjections}) so the supervisor's
 * starvation check never stops while one is in flight.
 *
 * The claim is portable: pgsql uses `FOR UPDATE SKIP LOCKED` for true multi-worker contention; sqlite (the
 * test suite + single-process dev) uses a serialized `lockForUpdate` transaction with Carbon — mirroring the
 * proven {@see AtlasLoopStore::claimNextTask} two-branch split (raw NOW()/INTERVAL/SKIP LOCKED are pgsql-only
 * and would break sqlite). Checkpointed projections resume AHEAD of fresh ones (accrued_ev DESC).
 */
final class AtlasLoopDeliveryPipeline
{
    public const TABLE = 'atlas_loop_pipeline_state';

    public const STAGE_PROJECTION = 'projection';

    public const STAGE_PARKED = 'parked';

    /**
     * DISPATCH an objective into the async projection stage. Idempotent on objective_id (insertOrIgnore): a
     * re-dispatch never creates a second row, so the µs-returning sever is safe to call every refill.
     */
    public function dispatchProjection(string $campaignId, string $objectiveId, float $accruedEv = 0.0, array $checkpoint = []): bool
    {
        $now = Carbon::now();

        return DB::table(self::TABLE)->insertOrIgnore([
            'campaign_id' => $campaignId,
            'objective_id' => $objectiveId,
            'stage' => self::STAGE_PROJECTION,
            'claim_owner' => null,
            'lease_expires_at' => null,
            'checkpoint' => json_encode($checkpoint, JSON_UNESCAPED_SLASHES),
            'accrued_ev' => $accruedEv,
            'obligation_set' => json_encode([], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]) > 0;
    }

    /**
     * Atomically CLAIM the highest-accrued-EV unleased projection (resume priority). Returns the decoded row
     * or null when none is claimable. The lease is what the zombie-detector honours.
     *
     * @return array<string,mixed>|null
     */
    public function claimNextProjection(string $campaignId, string $owner, int $leaseSeconds = 300): ?array
    {
        $leaseSeconds = max(1, $leaseSeconds);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select(
                'UPDATE '.self::TABLE.' SET claim_owner = ?, lease_expires_at = NOW() + (interval \'1 second\' * ?), updated_at = NOW()'
                .' WHERE id = ('
                .'   SELECT id FROM '.self::TABLE
                .'   WHERE campaign_id = ? AND stage = ? AND (claim_owner IS NULL OR lease_expires_at < NOW())'
                .'   ORDER BY accrued_ev DESC, created_at ASC FOR UPDATE SKIP LOCKED LIMIT 1'
                .' ) RETURNING id',
                [$owner, $leaseSeconds, $campaignId, self::STAGE_PROJECTION],
            );
            if ($rows === []) {
                return null;
            }

            return $this->decode((array) DB::table(self::TABLE)->find($rows[0]->id));
        }

        // sqlite / other: a serialized transaction with row locking is sufficient (single test process).
        return DB::transaction(function () use ($campaignId, $owner, $leaseSeconds): ?array {
            $row = DB::table(self::TABLE)
                ->where('campaign_id', $campaignId)
                ->where('stage', self::STAGE_PROJECTION)
                ->where(function ($q): void {
                    $q->whereNull('claim_owner')->orWhere('lease_expires_at', '<', Carbon::now());
                })
                ->orderByDesc('accrued_ev')->orderBy('created_at')
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return null;
            }
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'claim_owner' => $owner,
                'lease_expires_at' => Carbon::now()->addSeconds($leaseSeconds),
                'updated_at' => Carbon::now(),
            ]);

            return $this->decode((array) DB::table(self::TABLE)->find($row->id));
        });
    }

    /**
     * In-flight (dispatched, not yet completed) projections for a campaign — the count the supervisor's
     * starvation guard must treat as OPEN work, so severing produce() never trips queue_starved_no_refill.
     */
    public function countOpenProjections(string $campaignId): int
    {
        return DB::table(self::TABLE)
            ->where('campaign_id', $campaignId)
            ->where('stage', '<>', self::STAGE_PARKED)
            ->count();
    }

    /** Persist mid-loop progress (designer↔critic round + obligation set) WITHOUT releasing the claim. */
    public function checkpoint(string $objectiveId, array $checkpoint, array $obligationSet = []): void
    {
        DB::table(self::TABLE)->where('objective_id', $objectiveId)->update([
            'checkpoint' => json_encode($checkpoint, JSON_UNESCAPED_SLASHES),
            'obligation_set' => json_encode($obligationSet, JSON_UNESCAPED_SLASHES),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Read a projection row (decoded) by objective id, or null. A read does NOT touch the lease — the async
     * worker that holds the claim uses this to resume from its own checkpoint.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $objectiveId): ?array
    {
        $row = DB::table(self::TABLE)->where('objective_id', $objectiveId)->first();

        return $row === null ? null : $this->decode((array) $row);
    }

    /** A finished projection leaves the pipeline (the row is removed) so it never counts as open again. */
    public function complete(string $objectiveId): void
    {
        DB::table(self::TABLE)->where('objective_id', $objectiveId)->delete();
    }

    /** Park a projection that could not converge (oscillation) — terminal, no longer open work. */
    public function park(string $objectiveId, string $reason): void
    {
        DB::table(self::TABLE)->where('objective_id', $objectiveId)->update([
            'stage' => self::STAGE_PARKED,
            'claim_owner' => null,
            'lease_expires_at' => null,
            'checkpoint' => json_encode(['parked_reason' => $reason], JSON_UNESCAPED_SLASHES),
            'updated_at' => Carbon::now(),
        ]);
    }

    /** Explicitly free expired leases (a worker died mid-projection). Returns how many were reclaimed. */
    public function reclaimExpired(string $campaignId): int
    {
        return DB::table(self::TABLE)
            ->where('campaign_id', $campaignId)
            ->where('stage', self::STAGE_PROJECTION)
            ->whereNotNull('claim_owner')
            ->where('lease_expires_at', '<', Carbon::now())
            ->update(['claim_owner' => null, 'lease_expires_at' => null, 'updated_at' => Carbon::now()]);
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['checkpoint'] = is_string($row['checkpoint'] ?? null) ? (json_decode($row['checkpoint'], true) ?: []) : [];
        $row['obligation_set'] = is_string($row['obligation_set'] ?? null) ? (json_decode($row['obligation_set'], true) ?: []) : [];

        return $row;
    }
}
