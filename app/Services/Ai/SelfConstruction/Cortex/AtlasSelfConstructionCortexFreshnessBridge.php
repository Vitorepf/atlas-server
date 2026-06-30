<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Adapter — turns SUPPLIED context-freshness FACTS into Self-Construction Cortex READINESS facts. Pure:
 * never refreshes any index, never starts runtime work. The bridge only adapts the inputs it is given.
 *
 * INPUT FACTS:
 *   { now_unix:int, freshness_window_seconds:int=86400,
 *     sources:array<source_id, {last_unix:int, hash:string}> }
 *
 * REQUIRED source_ids:
 *   docs, code_index, queue, receipts, runtime_evidence
 *
 * READINESS PER SOURCE:
 *   fresh    — last_unix present, hash non-empty, (now - last_unix) <= window
 *   stale    — last_unix present but (now - last_unix) > window
 *   unknown  — source row absent entirely
 *   blocked  — row present but hash or last_unix missing (fail-closed)
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (rows sorted by source_id).
 *   - PURE.
 *   - NO scalar score.
 */
final class AtlasSelfConstructionCortexFreshnessBridge
{
    public const SCHEMA = 'atlas.cortex.freshness_bridge.v1';

    public const FRESH = 'fresh';

    public const STALE = 'stale';

    public const UNKNOWN = 'unknown';

    public const BLOCKED = 'blocked';

    public const REQUIRED_SOURCES = ['docs', 'code_index', 'queue', 'receipts', 'runtime_evidence', 'worker_outcome', 'project_lane'];

    public const DEFAULT_WINDOW_SECONDS = 86400;

    /**
     * @param  array{now_unix?:int, freshness_window_seconds?:int, sources?:array<string,array{last_unix?:int, hash?:string}>}  $facts
     * @return array{schema:string, all_fresh:bool, safe_to_origin_tasks:bool, rows:list<array{source_id:string, readiness:string, reason:string}>, knowledge_dominance_refresh_plan:list<array<string,mixed>>}
     */
    public function adapt(array $facts): array
    {
        $now = (int) ($facts['now_unix'] ?? 0);
        $window = (int) ($facts['freshness_window_seconds'] ?? self::DEFAULT_WINDOW_SECONDS);
        $sources = is_array($facts['sources'] ?? null) ? $facts['sources'] : [];

        // Fail closed: a non-positive window is an impossible freshness contract.
        if ($window <= 0) {
            $rows = array_map(
                static fn (string $s): array => ['source_id' => $s, 'readiness' => self::BLOCKED, 'reason' => 'invalid_freshness_window'],
                self::REQUIRED_SOURCES,
            );
            usort($rows, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']));

            return [
                'schema'                          => self::SCHEMA,
                'all_fresh'                       => false,
                'safe_to_origin_tasks'            => false,
                'rows'                            => $rows,
                'knowledge_dominance_refresh_plan' => $this->buildRefreshPlan($rows),
            ];
        }

        $rows = [];
        foreach (self::REQUIRED_SOURCES as $sourceId) {
            $row = is_array($sources[$sourceId] ?? null) ? $sources[$sourceId] : null;
            if ($row === null) {
                $rows[] = ['source_id' => $sourceId, 'readiness' => self::UNKNOWN, 'reason' => 'source_not_supplied'];

                continue;
            }
            $hash = (string) ($row['hash'] ?? '');
            if (! isset($row['last_unix']) || $hash === '') {
                $rows[] = ['source_id' => $sourceId, 'readiness' => self::BLOCKED, 'reason' => $hash === '' ? 'hash_missing' : 'last_unix_missing'];

                continue;
            }
            $lastUnix = (int) $row['last_unix'];
            // Fail closed: a last_unix in the future cannot be fresh.
            if ($now > 0 && $lastUnix > $now) {
                $rows[] = ['source_id' => $sourceId, 'readiness' => self::BLOCKED, 'reason' => 'future_timestamp'];

                continue;
            }
            $age = $now > 0 ? ($now - $lastUnix) : 0;
            if ($now > 0 && $age > $window) {
                $rows[] = ['source_id' => $sourceId, 'readiness' => self::STALE, 'reason' => 'age_'.$age.'s_exceeds_window_'.$window.'s'];

                continue;
            }
            $rows[] = ['source_id' => $sourceId, 'readiness' => self::FRESH, 'reason' => 'within_window'];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']));

        $allFresh = ! array_filter($rows, static fn (array $r): bool => $r['readiness'] !== self::FRESH);
        $fullPlan = $this->buildRefreshPlan($rows);

        // Split the refresh plan into blocking (unknown/blocked) and advisory (stale).
        $blockingPlan = array_values(array_filter($fullPlan, static fn (array $p): bool => ! $p['safe_to_origin_tasks']));
        $advisoryPlan = array_values(array_filter($fullPlan, static fn (array $p): bool => $p['safe_to_origin_tasks']));

        // stale_but_usable: stale sources with hash present can still be read (with caveats).
        $staleButUsable = ! empty($advisoryPlan) && ! $allFresh;

        return [
            'schema'                          => self::SCHEMA,
            'all_fresh'                       => $allFresh,
            'safe_to_origin_tasks'            => $allFresh,
            'stale_but_usable'                => $staleButUsable,
            'rows'                            => $rows,
            'blocking_refresh_plan'           => $blockingPlan,
            'advisory_refresh_plan'           => $advisoryPlan,
            'knowledge_dominance_refresh_plan' => $fullPlan,
        ];
    }

    /**
     * Build a deterministic refresh plan for every non-fresh source row.
     * Fresh rows produce no plan entry. Does NOT perform any refresh.
     *
     * Plan entry fields:
     *   source_id          string  — which source needs refreshing
     *   refresh_action     string  — deterministic action label
     *   blocking_reason    string  — reason from the readiness row
     *   required_receipt   string  — token the caller must obtain after the action
     *   safe_to_origin_tasks bool  — stale=true (data old but present), unknown/blocked=false
     *
     * @param  list<array{source_id:string, readiness:string, reason:string}>  $rows
     * @return list<array<string,mixed>>
     */
    private function buildRefreshPlan(array $rows): array
    {
        $plan = [];
        foreach ($rows as $row) {
            if ($row['readiness'] === self::FRESH) {
                continue;
            }

            $action = match ($row['readiness']) {
                self::STALE   => 'run_sync',
                self::UNKNOWN => 'supply_source',
                self::BLOCKED => match (true) {
                    str_contains($row['reason'], 'hash_missing')        => 'repair_hash',
                    str_contains($row['reason'], 'last_unix_missing')   => 'repair_timestamp',
                    str_contains($row['reason'], 'future_timestamp')    => 'correct_clock',
                    str_contains($row['reason'], 'invalid_freshness_window') => 'repair_window_config',
                    default                                             => 'inspect_and_repair',
                },
                default => 'inspect_and_repair',
            };

            $plan[] = [
                'source_id'            => $row['source_id'],
                'refresh_action'       => $action,
                'blocking_reason'      => $row['reason'],
                'required_receipt'     => 'receipt:'.$row['source_id'].':'.$action,
                'safe_to_origin_tasks' => $row['readiness'] === self::STALE,
            ];
        }

        return $plan;
    }
}
