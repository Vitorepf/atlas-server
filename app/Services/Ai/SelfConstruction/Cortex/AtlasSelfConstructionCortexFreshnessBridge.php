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

    public const REQUIRED_SOURCES = ['docs', 'code_index', 'queue', 'receipts', 'runtime_evidence'];

    public const DEFAULT_WINDOW_SECONDS = 86400;

    /**
     * @param  array{now_unix?:int, freshness_window_seconds?:int, sources?:array<string,array{last_unix?:int, hash?:string}>}  $facts
     * @return array{schema:string, all_fresh:bool, rows:list<array{source_id:string, readiness:string, reason:string}>}
     */
    public function adapt(array $facts): array
    {
        $now = (int) ($facts['now_unix'] ?? 0);
        $window = (int) ($facts['freshness_window_seconds'] ?? self::DEFAULT_WINDOW_SECONDS);
        $sources = is_array($facts['sources'] ?? null) ? $facts['sources'] : [];

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
            $age = $now > 0 ? ($now - (int) $row['last_unix']) : 0;
            if ($now > 0 && $age > $window) {
                $rows[] = ['source_id' => $sourceId, 'readiness' => self::STALE, 'reason' => 'age_'.$age.'s_exceeds_window_'.$window.'s'];

                continue;
            }
            $rows[] = ['source_id' => $sourceId, 'readiness' => self::FRESH, 'reason' => 'within_window'];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['source_id'], $b['source_id']));

        $allFresh = ! array_filter($rows, static fn (array $r): bool => $r['readiness'] !== self::FRESH);

        return [
            'schema' => self::SCHEMA,
            'all_fresh' => $allFresh,
            'rows' => $rows,
        ];
    }
}
