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
 * AC5 — per-channel item classification distinguishes runtime proof from
 * stale docs, old memory and self-declared status. Each channel is
 * classified as one of:
 *   missing       — channel has no items
 *   self_declared — newest item has source_type=self_declared and was not
 *                   verified_by_runtime=true (a status claim without proof)
 *   stale         — newest item is older than (now_iso - max_age_seconds)
 *   fresh         — none of the above
 * (priority when multiple apply: missing > self_declared > stale > fresh)
 *
 * Admission is blocked when any channel listed in critical_channels (default
 * [commits, worker_reports]) classifies as stale, self_declared or missing.
 * The output's freshness_status mirrors the worst classification among
 * critical_channels; blocking_reason names the offending channel and
 * classification; refresh_hint gives a concrete next step. All three are
 * null when admission is not blocked.
 *
 * This class is pure/read-only: no DB queries, no HTTP calls, no git mutations.
 */
final class AtlasExternalBrainEvidenceFreshnessRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.evidence_freshness_runtime_bridge.v1';

    public const REQUIRED_FRESH_CHANNELS = 2;

    private const DEFAULT_CRITICAL_CHANNELS = ['commits', 'worker_reports'];

    private const CLASSIFICATION_RANK = ['missing' => 3, 'self_declared' => 2, 'stale' => 1, 'fresh' => 0];

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

        // AC5: per-channel runtime-proof classification + admission blocking.
        $criticalChannels = is_array($input['critical_channels'] ?? null)
            ? array_map('strval', $input['critical_channels'])
            : self::DEFAULT_CRITICAL_CHANNELS;

        $channelClassifications = [];
        foreach ($channelItems as $name => $items) {
            $channelClassifications[$name] = $this->classifyChannel($items, $maxAgeSeconds, $nowIso);
        }

        $worstChannel = null;
        $worstClassification = 'fresh';
        foreach ($criticalChannels as $channel) {
            $classification = $channelClassifications[$channel] ?? 'missing';
            if (self::CLASSIFICATION_RANK[$classification] > self::CLASSIFICATION_RANK[$worstClassification]) {
                $worstClassification = $classification;
                $worstChannel = $channel;
            }
        }

        $admissionBlocked = $worstClassification !== 'fresh';
        $blockingReason = $admissionBlocked ? "critical_evidence_{$worstChannel}_{$worstClassification}" : null;
        $refreshHint = $admissionBlocked ? $this->refreshHint((string) $worstChannel, $worstClassification) : null;

        return [
            'schema_version'            => self::SCHEMA,
            'fresh'                     => $fresh,
            'fresh_channels'            => $freshChannels,
            'stale_or_missing_evidence' => $staleReasons,
            'source_counts'             => $sourceCounts,
            'newest_seen_at'            => $newestSeenAt,
            'oldest_seen_at'            => $oldestSeenAt,
            'channel_classifications'   => $channelClassifications,
            'freshness_status'          => $worstClassification,
            'admission_blocked'         => $admissionBlocked,
            'blocking_reason'           => $blockingReason,
            'refresh_hint'              => $refreshHint,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    private function classifyChannel(array $items, ?int $maxAgeSeconds, ?string $nowIso): string
    {
        if ($items === []) {
            return 'missing';
        }

        $newest = null;
        foreach ($items as $item) {
            $ts = (string) ($item['timestamp'] ?? $item['created_at'] ?? '');
            $t = $ts === '' ? false : strtotime($ts);
            if ($t !== false && ($newest === null || $t > $newest['t'])) {
                $newest = ['t' => $t, 'item' => $item];
            }
        }

        if ($newest === null) {
            return 'missing';
        }

        $sourceType = (string) ($newest['item']['source_type'] ?? '');
        $verifiedByRuntime = (bool) ($newest['item']['verified_by_runtime'] ?? false);
        if ($sourceType === 'self_declared' && ! $verifiedByRuntime) {
            return 'self_declared';
        }

        if ($this->isAgeStale($items, $maxAgeSeconds, $nowIso)) {
            return 'stale';
        }

        return 'fresh';
    }

    private function refreshHint(string $channel, string $classification): string
    {
        return match ($classification) {
            'missing' => "collect_runtime_evidence_for_{$channel}",
            'self_declared' => "verify_{$channel}_with_runtime_confirmation_not_self_report",
            'stale' => "refresh_{$channel}_with_current_runtime_proof",
            default => "review_{$channel}",
        };
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
