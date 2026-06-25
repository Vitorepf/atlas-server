<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure FACTS-only miner. Turns Self-Construction roadmap rows into candidate contract inputs for the
 * Task Fabric architecture contract compiler.
 *
 * INPUT: list of roadmap rows, each:
 *   { organ, capability, current_state, target_state, evidence_path?, suggested_files?,
 *     resolved?:bool, kind?:string }
 *
 * OUTPUT: list of CANDIDATE contract inputs (NOT contracts themselves):
 *   { organ, capability, capability_gap, evidence_path, suggested_files, owner_scope, tags:list<string> }
 *
 * FILTERS:
 *   - resolved===true ⇒ skipped
 *   - kind matches /cosmetic|proxy|whitespace|comment/i ⇒ skipped (anti-Goodhart)
 *   - missing evidence_path ⇒ skipped (cannot prove a gap exists)
 *   - organ outside the declared owner-scope allowlist ⇒ skipped
 *
 * INVARIANTS:
 *   - DETERMINISTIC: candidates sorted by (organ asc, capability asc).
 *   - No I/O, no provider call, no shell, no git.
 */
final class AtlasTaskFabricRoadmapGapMiner
{
    public const SCHEMA = 'atlas.taskfabric.roadmap_gap_candidate.v1';

    public const SUPPORTED_ORGANS = [
        'Task Fabric',
        'Maestro',
        'Worker Swarm',
        'Verification Court',
        'Merge Governor',
        'Learning Transfer',
        'Multi Project',
    ];

    public const COSMETIC_KIND_REGEX = '/cosmetic|proxy|whitespace|comment/i';

    /**
     * @param  list<array{organ?:string, capability?:string, current_state?:string, target_state?:string, evidence_path?:string, suggested_files?:list<string>, resolved?:bool, kind?:string}>  $rows
     * @return list<array{schema_version:string, organ:string, capability:string, capability_gap:string, evidence_path:string, suggested_files:list<string>, owner_scope:string, tags:list<string>}>
     */
    public function mine(array $rows): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((bool) ($row['resolved'] ?? false)) {
                continue;
            }
            $kind = (string) ($row['kind'] ?? '');
            if ($kind !== '' && preg_match(self::COSMETIC_KIND_REGEX, $kind)) {
                continue;
            }
            $evidence = trim((string) ($row['evidence_path'] ?? ''));
            if ($evidence === '') {
                continue;
            }
            $organ = trim((string) ($row['organ'] ?? ''));
            if (! in_array($organ, self::SUPPORTED_ORGANS, true)) {
                continue;
            }
            $capability = trim((string) ($row['capability'] ?? ''));
            if ($capability === '') {
                continue;
            }
            $current = trim((string) ($row['current_state'] ?? ''));
            $target = trim((string) ($row['target_state'] ?? ''));
            $gap = sprintf('CURRENT: %s | TARGET: %s', $current === '' ? '(unspecified)' : $current, $target === '' ? '(unspecified)' : $target);
            $files = is_array($row['suggested_files'] ?? null) ? array_values(array_map('strval', $row['suggested_files'])) : [];

            $candidates[] = [
                'schema_version' => self::SCHEMA,
                'organ' => $organ,
                'capability' => $capability,
                'capability_gap' => $gap,
                'evidence_path' => $evidence,
                'suggested_files' => $files,
                'owner_scope' => 'atlas-native',
                'tags' => ['organ:'.$organ, 'capability:'.$capability],
            ];
        }
        usort($candidates, static function (array $a, array $b): int {
            return strcmp($a['organ'], $b['organ']) ?: strcmp($a['capability'], $b['capability']);
        });

        return $candidates;
    }
}
