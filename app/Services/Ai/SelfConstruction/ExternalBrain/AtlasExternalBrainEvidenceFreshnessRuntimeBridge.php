<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure bridge: aggregates three independent evidence channels (commits, worker_reports,
 * evidence_intake) into a single freshness verdict.
 *
 * AC2 — fresh=true requires:
 *   - At least min_fresh_channels (default REQUIRED_FRESH_CHANNELS=2) independent
 *     non-empty, non-age-stale channels.
 *   - All channels named in required_channels (default []) must themselves be fresh.
 *
 * A channel is age-stale when max_age_seconds + now_iso are both provided and the
 * channel's newest timestamp is older than (now_iso - max_age_seconds). Empty channels
 * contribute 'no_recent_<channel>'; age-stale channels contribute 'stale_<channel>'.
 *
 * AC3 — output also includes oldest_seen_at (min timestamp across all items, or null).
 *
 * This class is pure/read-only: no DB queries, no HTTP calls, no git mutations.
 */
final class AtlasExternalBrainEvidenceFreshnessRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.evidence_freshness_runtime_bridge.v1';

    public const REQUIRED_FRESH_CHANNELS = 2;

    /**
     * @param  array<string,mixed>  $input  commits, worker_reports, evidence_intake,
     *                                       min_fresh_channels?, required_channels?,
     *                                       max_age_seconds?, now_iso?
     * @return array<string,mixed>
     */
    public function assess(array $input): array
    {
        $commits        = is_array($input['commits']         ?? null) ? $input['commits']         : [];
        $workerReports  = is_array($input['worker_reports']  ?? null) ? $input['worker_reports']  : [];
        $evidenceIntake = is_array($input['evidence_intake'] ?? null) ? $input['evidence_intake'] : [];

        $minFreshChannels = (int) ($input['min_fresh_channels'] ?? self::REQUIRED_FRESH_CHANNELS);
        $requiredChannels = is_array($input['required_channels'] ?? null) ? $input['required_channels'] : [];
        $maxAgeSeconds    = isset($input['max_age_seconds']) ? (int) $input['max_age_seconds'] : null;
        $nowIso           = isset($input['now_iso']) ? (string) $input['now_iso'] : null;

        $sourceCounts = [
            'commits'         => count($commits),
            'evidence_intake' => count($evidenceIntake),
            'worker_reports'  => count($workerReports),
        ];

        $channelItems = [
            'commits'         => $commits,
            'worker_reports'  => $workerReports,
            'evidence_intake' => $evidenceIntake,
        ];

        $freshChannels = [];
        $staleReasons  = [];

        foreach ($channelItems as $name => $items) {
            if (count($items) === 0) {
                $staleReasons[] = 'no_recent_'.$name;
            } elseif ($this->isAgeStale($items, $maxAgeSeconds, $nowIso)) {
                $staleReasons[] = 'stale_'.$name;
            } else {
                $freshChannels[] = $name;
            }
        }

        // AC2: required channels must all be fresh.
        $allRequiredFresh = true;
        foreach ($requiredChannels as $req) {
            if (! in_array((string) $req, $freshChannels, true)) {
                $allRequiredFresh = false;
            }
        }

        sort($freshChannels);
        sort($staleReasons);

        $fresh = count($freshChannels) >= $minFreshChannels && $allRequiredFresh;

        // AC3: newest and oldest timestamps across all channels.
        $newestSeenAt = $this->extremeTimestamp('max', $commits, $workerReports, $evidenceIntake);
        $oldestSeenAt = $this->extremeTimestamp('min', $commits, $workerReports, $evidenceIntake);

        return [
            'schema_version'            => self::SCHEMA,
            'fresh'                     => $fresh,
            'fresh_channels'            => $freshChannels,
            'stale_or_missing_evidence' => $staleReasons,
            'source_counts'             => $sourceCounts,
            'newest_seen_at'            => $newestSeenAt,
            'oldest_seen_at'            => $oldestSeenAt,
        ];
    }

    /**
     * A channel is age-stale when its newest timestamp is older than (nowIso - maxAgeSeconds).
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function isAgeStale(array $items, ?int $maxAgeSeconds, ?string $nowIso): bool
    {
        if ($maxAgeSeconds === null || $nowIso === null) {
            return false;
        }
        $nowTs = strtotime($nowIso);
        if ($nowTs === false) {
            return false;
        }
        $newestItemTs = null;
        foreach ($items as $item) {
            $ts = (string) ($item['timestamp'] ?? $item['created_at'] ?? '');
            if ($ts === '') {
                continue;
            }
            $t = strtotime($ts);
            if ($t !== false) {
                $newestItemTs = $newestItemTs === null ? $t : max($newestItemTs, $t);
            }
        }
        if ($newestItemTs === null) {
            return false;
        }

        return ($nowTs - $newestItemTs) > $maxAgeSeconds;
    }

    private function extremeTimestamp(string $fn, array ...$channels): ?string
    {
        $timestamps = [];
        foreach (array_merge(...$channels) as $item) {
            $ts = (string) ($item['timestamp'] ?? $item['created_at'] ?? '');
            if ($ts !== '') {
                $timestamps[] = $ts;
            }
        }

        return $timestamps !== [] ? ($fn === 'min' ? min($timestamps) : max($timestamps)) : null;
    }
}
