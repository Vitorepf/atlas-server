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

    /** Organ priority weights for leverage scoring (higher = more impactful). */
    public const ORGAN_PRIORITY = [
        'Task Fabric'        => 10,
        'Maestro'            => 9,
        'Worker Swarm'       => 8,
        'Verification Court' => 7,
        'Merge Governor'     => 6,
        'Learning Transfer'  => 5,
        'Multi Project'      => 4,
    ];

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
     * Final-brain execution lanes. Each lane scopes a distinct area of autonomous self-improvement.
     * A row with a `lane` field matching one of these values is tagged with `lane:<value>` in output.
     */
    public const FINAL_BRAIN_LANES = [
        'self-recovery',
        'lane-governance',
        'task-repair',
        'muscle-feedback',
        'frontier-import',
        'compounding',
        'completion-certification',
    ];

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

    /**
     * Lane-aware mining for the final-brain roadmap. Extends mine() with:
     *   - Lane tagging: rows with a `lane` field in FINAL_BRAIN_LANES get a `lane:<value>` tag and a
     *     top-level `lane` key in the output candidate.
     *   - Deduplication: candidates whose `organ:capability` key already appears in `$liveTargets` are
     *     silently dropped (no phantom work for packets that already exist).
     *
     * Sorted by (lane asc, organ asc, capability asc).
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $liveTargets  Existing packet keys in the form "organ:capability" (or any
     *                                     string that should suppress duplicate emission).
     * @return list<array<string,mixed>>
     */
    public function mineByLane(array $rows, array $liveTargets = []): array
    {
        $liveSet = array_flip($liveTargets);
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

            // Deduplication: skip if an equivalent packet already exists.
            $liveKey = $organ.':'.$capability;
            if (isset($liveSet[$liveKey])) {
                continue;
            }

            $current = trim((string) ($row['current_state'] ?? ''));
            $target  = trim((string) ($row['target_state'] ?? ''));
            $gap     = sprintf(
                'CURRENT: %s | TARGET: %s',
                $current === '' ? '(unspecified)' : $current,
                $target  === '' ? '(unspecified)' : $target,
            );
            $files = is_array($row['suggested_files'] ?? null)
                ? array_values(array_map('strval', $row['suggested_files']))
                : [];

            $lane = trim((string) ($row['lane'] ?? ''));
            $tags = ['organ:'.$organ, 'capability:'.$capability];
            if ($lane !== '' && in_array($lane, self::FINAL_BRAIN_LANES, true)) {
                $tags[] = 'lane:'.$lane;
            }

            $candidate = array_merge([
                'schema_version'  => self::SCHEMA,
                'organ'           => $organ,
                'capability'      => $capability,
                'capability_gap'  => $gap,
                'evidence_path'   => $evidence,
                'suggested_files' => $files,
                'owner_scope'     => 'atlas-native',
                'tags'            => $tags,
            ], $this->extractChainFields($row));
            if ($lane !== '') {
                $candidate['lane'] = $lane;
            }

            $candidates[] = $candidate;
        }

        usort($candidates, static function (array $a, array $b): int {
            $laneA = (string) ($a['lane'] ?? '');
            $laneB = (string) ($b['lane'] ?? '');
            return strcmp($laneA, $laneB) ?: strcmp($a['organ'], $b['organ']) ?: strcmp($a['capability'], $b['capability']);
        });

        return $candidates;
    }

    /**
     * Extract optional chain/unlock hint fields from a roadmap row.
     * Only non-empty values are included in the returned array.
     *
     * Fields sourced from the row (all optional):
     *   chain_key             string      — logical chain this gap belongs to
     *   unlocks_capabilities  string[]    — capability labels unlocked when this gap closes
     *   prerequisite_gap_refs string[]    — other gap IDs that must close first
     *   next_unblock_hint     string      — human-readable hint for what to do next
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function extractChainFields(array $row): array
    {
        $out = [];

        $chainKey = trim((string) ($row['chain_key'] ?? ''));
        if ($chainKey !== '') {
            $out['chain_key'] = $chainKey;
        }

        $unlocks = array_values(array_filter(
            array_map('trim', (array) ($row['unlocks_capabilities'] ?? [])),
            static fn (string $s): bool => $s !== '',
        ));
        if ($unlocks !== []) {
            $out['unlocks_capabilities'] = $unlocks;
        }

        $prereqs = array_values(array_filter(
            array_map('trim', (array) ($row['prerequisite_gap_refs'] ?? [])),
            static fn (string $s): bool => $s !== '',
        ));
        if ($prereqs !== []) {
            $out['prerequisite_gap_refs'] = $prereqs;
        }

        $hint = trim((string) ($row['next_unblock_hint'] ?? ''));
        if ($hint !== '') {
            $out['next_unblock_hint'] = $hint;
        }

        return $out;
    }

    /**
     * Mine gaps from roadmap rows, exclude blocked families and live-target duplicates, then rank by
     * implementability leverage so the originator always sees the highest-impact work first.
     *
     * Leverage score = ORGAN_PRIORITY[organ] + min(count(suggested_files), 5)
     * Candidates sorted: leverage_score DESC, organ ASC, capability ASC.
     * Rejections sorted deterministically: by reason ASC, organ ASC, capability ASC.
     *
     * Rejection reasons (exposed so the brain can diagnose gaps, not just count them):
     *   resolved              — row['resolved'] === true
     *   cosmetic_or_proxy     — kind matches COSMETIC_KIND_REGEX
     *   missing_evidence      — evidence_path empty
     *   unsupported_organ     — organ not in SUPPORTED_ORGANS
     *   blocked_family        — row['family'] in $blockedFamilies
     *   live_target_duplicate — organ:capability key in $liveTargets
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  list<string>  $blockedFamilies  Task-family names that are currently blocked
     * @param  list<string>  $liveTargets      Existing "organ:capability" keys to deduplicate against
     * @return array{candidates:list<array<string,mixed>>, rejections:list<array{reason:string,organ:string,capability:string}>}
     */
    public function mineRanked(array $rows, array $blockedFamilies = [], array $liveTargets = []): array
    {
        $blockedSet = array_flip($blockedFamilies);
        $liveSet    = array_flip($liveTargets);
        $candidates = [];
        $rejections = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $organ      = trim((string) ($row['organ'] ?? ''));
            $capability = trim((string) ($row['capability'] ?? ''));
            $label      = ['organ' => $organ, 'capability' => $capability];

            if ((bool) ($row['resolved'] ?? false)) {
                $rejections[] = $label + ['reason' => 'resolved'];
                continue;
            }
            $kind = (string) ($row['kind'] ?? '');
            if ($kind !== '' && preg_match(self::COSMETIC_KIND_REGEX, $kind)) {
                $rejections[] = $label + ['reason' => 'cosmetic_or_proxy'];
                continue;
            }
            $evidence = trim((string) ($row['evidence_path'] ?? ''));
            if ($evidence === '') {
                $rejections[] = $label + ['reason' => 'missing_evidence'];
                continue;
            }
            if (! in_array($organ, self::SUPPORTED_ORGANS, true)) {
                $rejections[] = $label + ['reason' => 'unsupported_organ'];
                continue;
            }
            if ($capability === '') {
                continue;
            }
            $family = trim((string) ($row['family'] ?? ''));
            if ($family !== '' && isset($blockedSet[$family])) {
                $rejections[] = $label + ['reason' => 'blocked_family'];
                continue;
            }
            $liveKey = $organ.':'.$capability;
            if (isset($liveSet[$liveKey])) {
                $rejections[] = $label + ['reason' => 'live_target_duplicate'];
                continue;
            }

            $current = trim((string) ($row['current_state'] ?? ''));
            $target  = trim((string) ($row['target_state'] ?? ''));
            $gap     = sprintf(
                'CURRENT: %s | TARGET: %s',
                $current === '' ? '(unspecified)' : $current,
                $target === '' ? '(unspecified)' : $target,
            );
            $files = is_array($row['suggested_files'] ?? null)
                ? array_values(array_map('strval', $row['suggested_files']))
                : [];

            $leverageScore = (self::ORGAN_PRIORITY[$organ] ?? 0) + min(count($files), 5);

            $lane = trim((string) ($row['lane'] ?? ''));
            $tags = ['organ:'.$organ, 'capability:'.$capability];
            if ($lane !== '' && in_array($lane, self::FINAL_BRAIN_LANES, true)) {
                $tags[] = 'lane:'.$lane;
            }

            $candidate = array_merge([
                'schema_version'  => self::SCHEMA,
                'organ'           => $organ,
                'capability'      => $capability,
                'capability_gap'  => $gap,
                'evidence_path'   => $evidence,
                'suggested_files' => $files,
                'owner_scope'     => 'atlas-native',
                'leverage_score'  => $leverageScore,
                'tags'            => $tags,
            ], $this->extractChainFields($row));
            if ($lane !== '') {
                $candidate['lane'] = $lane;
            }

            $candidates[] = $candidate;
        }

        usort($candidates, static function (array $a, array $b): int {
            if ($b['leverage_score'] !== $a['leverage_score']) {
                return $b['leverage_score'] - $a['leverage_score'];
            }

            return strcmp($a['organ'], $b['organ']) ?: strcmp($a['capability'], $b['capability']);
        });

        usort($rejections, static fn (array $a, array $b): int =>
            strcmp($a['reason'], $b['reason']) ?: strcmp($a['organ'], $b['organ']) ?: strcmp($a['capability'], $b['capability'])
        );

        return ['candidates' => $candidates, 'rejections' => $rejections];
    }
}
