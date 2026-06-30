<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure bridge: aggregates three independent evidence channels (commits, worker_reports,
 * evidence_intake) into a single freshness verdict.
 *
 * fresh=true requires at least REQUIRED_FRESH_CHANNELS (2) independent channels to be
 * non-empty.  Any channel with count=0 contributes a 'no_recent_<channel>' entry to
 * stale_or_missing_evidence (always populated; zero-length means all channels live).
 *
 * newest_seen_at is the lexicographic max of all 'timestamp' or 'created_at' fields
 * across all channel items. null when no timestamps are present.
 *
 * This class is pure/read-only: no DB queries, no HTTP calls, no git mutations.
 */
final class AtlasExternalBrainEvidenceFreshnessRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.evidence_freshness_runtime_bridge.v1';

    public const REQUIRED_FRESH_CHANNELS = 2;

    /**
     * @param  array<string,mixed>  $input  commits, worker_reports, evidence_intake
     * @return array{schema_version:string, fresh:bool, fresh_channels:list<string>, stale_or_missing_evidence:list<string>, source_counts:array<string,int>, newest_seen_at:string|null}
     */
    public function assess(array $input): array
    {
        $commits = is_array($input['commits'] ?? null) ? $input['commits'] : [];
        $workerReports = is_array($input['worker_reports'] ?? null) ? $input['worker_reports'] : [];
        $evidenceIntake = is_array($input['evidence_intake'] ?? null) ? $input['evidence_intake'] : [];

        $sourceCounts = [
            'commits' => count($commits),
            'evidence_intake' => count($evidenceIntake),
            'worker_reports' => count($workerReports),
        ];

        $freshChannels = [];
        $staleReasons = [];

        foreach ($sourceCounts as $name => $count) {
            if ($count > 0) {
                $freshChannels[] = $name;
            } else {
                $staleReasons[] = 'no_recent_'.$name;
            }
        }

        sort($freshChannels);
        sort($staleReasons);

        $fresh = count($freshChannels) >= self::REQUIRED_FRESH_CHANNELS;

        $newestSeenAt = $this->maxTimestamp($commits, $workerReports, $evidenceIntake);

        return [
            'schema_version' => self::SCHEMA,
            'fresh' => $fresh,
            'fresh_channels' => $freshChannels,
            'stale_or_missing_evidence' => $staleReasons,
            'source_counts' => $sourceCounts,
            'newest_seen_at' => $newestSeenAt,
        ];
    }

    private function maxTimestamp(array ...$channels): ?string
    {
        $timestamps = [];
        foreach (array_merge(...$channels) as $item) {
            $ts = (string) ($item['timestamp'] ?? $item['created_at'] ?? '');
            if ($ts !== '') {
                $timestamps[] = $ts;
            }
        }

        return $timestamps !== [] ? max($timestamps) : null;
    }
}
