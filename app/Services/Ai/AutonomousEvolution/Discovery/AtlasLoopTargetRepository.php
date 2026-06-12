<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopTarget;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin persistence over `atlas_loop_targets` — keeps discovery + loop-back
 * side-effect-light and testable. Idempotent upsert by (campaign, target_key, content
 * hash) so re-scanning unchanged files is a no-op; claim-top-K is parallel-safe
 * (`FOR UPDATE SKIP LOCKED` on pgsql) so the refiller never double-pulls a candidate.
 */
final class AtlasLoopTargetRepository
{
    public function targetKey(string $campaignId, string $targetPath): string
    {
        return hash('sha256', $campaignId.'|'.$targetPath);
    }

    /**
     * Idempotent upsert on (campaign_id, target_key). Unchanged candidate files still
     * refresh score/signals so live ranking policy changes take effect without churn.
     *
     * @param  array{score:float,self_contained:float,improvement:float,novelty:float,signals:array<string,mixed>}  $scored
     * @param  array<string,mixed>  $lineage
     */
    public function upsert(string $campaignId, string $targetPath, string $contentHash, array $scored, array $lineage = []): AtlasLoopTarget
    {
        $key = $this->targetKey($campaignId, $targetPath);
        $existing = AtlasLoopTarget::query()->where('campaign_id', $campaignId)->where('target_key', $key)->first();

        if ($existing instanceof AtlasLoopTarget) {
            if ($existing->content_hash === $contentHash) {
                if ($existing->status === AtlasLoopTarget::STATUS_CANDIDATE) {
                    $existing->forceFill([
                        'score' => $scored['score'],
                        'self_contained_score' => $scored['self_contained'],
                        'improvement_score' => $scored['improvement'],
                        'novelty_score' => $scored['novelty'],
                        'signals' => $scored['signals'],
                    ])->save();
                }

                return $existing; // same content: refreshed if candidate, otherwise historical state
            }
            $existing->forceFill([
                'content_hash' => $contentHash,
                'score' => $scored['score'],
                'self_contained_score' => $scored['self_contained'],
                'improvement_score' => $scored['improvement'],
                'novelty_score' => $scored['novelty'],
                'signals' => $scored['signals'],
                'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            ])->save();

            return $existing;
        }

        return AtlasLoopTarget::query()->create([
            'campaign_id' => $campaignId,
            'schema_version' => 'atlas.loop.target.v1',
            'target_path' => $targetPath,
            'target_key' => $key,
            'content_hash' => $contentHash,
            'status' => AtlasLoopTarget::STATUS_CANDIDATE,
            'score' => $scored['score'],
            'self_contained_score' => $scored['self_contained'],
            'improvement_score' => $scored['improvement'],
            'novelty_score' => $scored['novelty'],
            'signals' => $scored['signals'],
            'lineage' => $lineage,
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }

    /**
     * Claim the top-K candidate targets by score — atomically, so parallel refills never
     * pull the same target. Returns the claimed AtlasLoopTarget rows.
     *
     * @return list<AtlasLoopTarget>
     */
    public function claimTop(string $campaignId, int $k, int $leaseSeconds = 600): array
    {
        $k = max(1, $k);
        $leaseSeconds = max(60, $leaseSeconds);

        if (DB::connection()->getDriverName() === 'pgsql') {
            $rows = DB::select(
                <<<'SQL'
                UPDATE atlas_loop_targets
                   SET status = 'claimed',
                       claimed_by = ?,
                       claimed_at = NOW(),
                       lease_expires_at = NOW() + (? * INTERVAL '1 second'),
                       updated_at = NOW()
                 WHERE id IN (
                       SELECT id FROM atlas_loop_targets
                        WHERE campaign_id = ?
                          AND status = 'candidate'
                          AND attempts < max_attempts
                        ORDER BY score DESC, created_at ASC
                        FOR UPDATE SKIP LOCKED
                        LIMIT ?
                 )
                 RETURNING id
                SQL,
                [Str::uuid()->toString(), $leaseSeconds, $campaignId, $k],
            );
            $ids = array_map(static fn ($r): string => $r->id, $rows);

            return $ids === [] ? [] : AtlasLoopTarget::query()->whereIn('id', $ids)->get()->all();
        }

        return DB::transaction(function () use ($campaignId, $k, $leaseSeconds): array {
            $targets = AtlasLoopTarget::query()
                ->where('campaign_id', $campaignId)
                ->where('status', AtlasLoopTarget::STATUS_CANDIDATE)
                ->whereColumn('attempts', '<', 'max_attempts')
                ->orderByDesc('score')->orderBy('created_at')
                ->lockForUpdate()
                ->limit($k)->get();

            foreach ($targets as $target) {
                $target->forceFill([
                    'status' => AtlasLoopTarget::STATUS_CLAIMED,
                    'claimed_by' => (string) Str::uuid(),
                    'claimed_at' => Carbon::now(),
                    'lease_expires_at' => Carbon::now()->addSeconds($leaseSeconds),
                ])->save();
            }

            return $targets->all();
        });
    }

    public function markStatus(string $targetId, string $status, ?string $reason = null): void
    {
        AtlasLoopTarget::query()->whereKey($targetId)->update(array_filter([
            'status' => $status,
            'reason' => $reason,
            'attempts' => DB::raw('attempts + 1'),
        ], static fn ($v): bool => $v !== null));
    }

    public function quarantine(string $targetId, string $reason): void
    {
        AtlasLoopTarget::query()->whereKey($targetId)->update([
            'status' => AtlasLoopTarget::STATUS_QUARANTINED,
            'reason' => mb_substr($reason, 0, 160),
        ]);
    }

    public function reclaimStaleClaimed(string $campaignId, int $ttlSeconds): int
    {
        return AtlasLoopTarget::query()
            ->where('campaign_id', $campaignId)
            ->where('status', AtlasLoopTarget::STATUS_CLAIMED)
            ->where('lease_expires_at', '<', Carbon::now())
            ->update(['status' => AtlasLoopTarget::STATUS_CANDIDATE, 'claimed_by' => null, 'lease_expires_at' => null]);
    }

    public function alreadyProposedPaths(string $campaignId): array
    {
        return AtlasLoopTarget::query()
            ->where('campaign_id', $campaignId)
            ->where('status', AtlasLoopTarget::STATUS_PROPOSED)
            ->pluck('target_path')->all();
    }

    /**
     * @return array<string,int>
     */
    public function recentTargetActivity(string $campaignId, int $hours): array
    {
        $since = Carbon::now()->subHours(max(1, $hours));
        $counts = [];

        foreach (['atlas_loop_tasks', 'atlas_loop_proposals'] as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                continue;
            }

            try {
                $rows = DB::table($table)
                    ->select('target_path', DB::raw('count(*) as hits'))
                    ->where('campaign_id', $campaignId)
                    ->whereNotNull('target_path')
                    ->where('target_path', '!=', '')
                    ->where('created_at', '>=', $since)
                    ->groupBy('target_path')
                    ->get();

                foreach ($rows as $row) {
                    $path = (string) $row->target_path;
                    $counts[$path] = ($counts[$path] ?? 0) + (int) $row->hits;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $counts;
    }
}
