<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Read-only inventory of existing Atlas knowledge / code / doc / memory / evidence / queue sources for
 * the Self-Construction Cortex. Pure: never queries the sources — works from bounded FACTS describing
 * which sources are declared.
 *
 * INPUT FACTS:
 *   { sources:list<{source_id, kind, authority?, freshness_requirement?, workspace_boundary?,
 *                   read_only_available?:bool}> }
 *
 * REQUIRED kinds (any missing ⇒ blocker missing_required_source:<kind>):
 *   - docs                (authoring source-of-truth)
 *   - code_index          (read model)
 *   - memory              (Atlas memory)
 *   - evidence_ledger     (audited runtime events)
 *   - task_queue          (atlas:task queue)
 *
 * OUTPUT:
 *   { schema, inventory:list<{source_id, kind, authority, freshness_requirement, workspace_boundary,
 *                            read_only_available}>,
 *     blockers:list<string> }
 *
 * INVARIANTS:
 *   - DETERMINISTIC: inventory sorted by (kind, source_id).
 *   - Duplicate source_id ⇒ duplicate_source_id:<id> blocker (first occurrence wins).
 *   - Missing required kind ⇒ missing_required_source:<kind>.
 *   - Defaults applied DETERMINISTICALLY: authority='atlas_native', freshness_requirement='per_cycle',
 *     workspace_boundary='atlas_repo', read_only_available=true.
 */
final class AtlasSelfConstructionCortexSourceInventory
{
    public const SCHEMA = 'atlas.cortex.source_inventory.v1';

    public const REQUIRED_KINDS = ['docs', 'code_index', 'memory', 'evidence_ledger', 'task_queue'];

    public const DEFAULT_AUTHORITY = 'atlas_native';

    public const DEFAULT_FRESHNESS = 'per_cycle';

    public const DEFAULT_WORKSPACE_BOUNDARY = 'atlas_repo';

    public const FRESHNESS_FRESH = 'fresh';

    public const FRESHNESS_STALE = 'stale';

    public const FRESHNESS_UNKNOWN = 'unknown';

    /**
     * @param  array{sources?:list<array{source_id?:string, kind?:string, authority?:string, freshness_requirement?:string, workspace_boundary?:string, read_only_available?:bool, last_updated_at?:string, max_age_s?:int}>, now_at?:string}  $facts
     * @return array{schema:string, inventory:list<array<string,mixed>>, blockers:list<string>, required_summary:array<string,list<string>>}
     */
    public function inventory(array $facts): array
    {
        $rawSources = is_array($facts['sources'] ?? null) ? array_values($facts['sources']) : [];
        $nowAt = isset($facts['now_at']) ? strtotime((string) $facts['now_at']) : null;
        $blockers = [];

        $seenIds = [];
        $normalized = [];
        $kindsPresent = [];

        foreach ($rawSources as $s) {
            if (! is_array($s)) {
                continue;
            }
            $id = trim((string) ($s['source_id'] ?? ''));
            $kind = trim((string) ($s['kind'] ?? ''));
            if ($id === '' || $kind === '') {
                continue; // skip malformed silently — the missing-kind check picks up the gap
            }
            if (isset($seenIds[$id])) {
                $blockers[] = 'duplicate_source_id:'.$id;

                continue;
            }
            $seenIds[$id] = true;

            $freshness = self::FRESHNESS_UNKNOWN;
            if ($nowAt !== false && $nowAt !== null && isset($s['last_updated_at'])) {
                $updatedAt = strtotime((string) $s['last_updated_at']);
                $maxAge = (int) ($s['max_age_s'] ?? 3600);
                if ($updatedAt !== false) {
                    $freshness = ($nowAt - $updatedAt) <= $maxAge ? self::FRESHNESS_FRESH : self::FRESHNESS_STALE;
                }
            }

            $normalized[] = [
                'source_id' => $id,
                'kind' => $kind,
                'authority' => trim((string) ($s['authority'] ?? '')) !== '' ? (string) $s['authority'] : self::DEFAULT_AUTHORITY,
                'freshness_requirement' => trim((string) ($s['freshness_requirement'] ?? '')) !== '' ? (string) $s['freshness_requirement'] : self::DEFAULT_FRESHNESS,
                'workspace_boundary' => trim((string) ($s['workspace_boundary'] ?? '')) !== '' ? (string) $s['workspace_boundary'] : self::DEFAULT_WORKSPACE_BOUNDARY,
                'read_only_available' => array_key_exists('read_only_available', $s) ? (bool) $s['read_only_available'] : true,
                'freshness_status' => $freshness,
            ];
            $kindsPresent[$kind] = true;
        }

        $presentKinds = [];
        $missingKinds = [];
        foreach (self::REQUIRED_KINDS as $req) {
            if (isset($kindsPresent[$req])) {
                $presentKinds[] = $req;
            } else {
                $missingKinds[] = $req;
                $blockers[] = 'missing_required_source:'.$req;
            }
        }

        usort($normalized, static fn (array $a, array $b): int => strcmp($a['kind'], $b['kind']) ?: strcmp($a['source_id'], $b['source_id']));
        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'inventory' => $normalized,
            'blockers' => $blockers,
            'required_summary' => ['present' => $presentKinds, 'missing' => $missingKinds],
        ];
    }
}
