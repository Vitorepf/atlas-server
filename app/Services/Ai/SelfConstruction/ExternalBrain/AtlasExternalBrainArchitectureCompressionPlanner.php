<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure planner. Turns an organ inventory into ranked consolidation candidates.
 *
 * Action rules (first match wins per organ / group):
 *   merge    — two or more organs share a capability label
 *   delete   — stale scaffold + replacement_owner present + test_coverage=true
 *   keep     — stale scaffold but no safe delete (no owner OR no coverage)
 *   simplify — line_count >= growth_threshold + test_coverage=true
 *   keep     — line_count >= growth_threshold but no coverage
 *
 * INVARIANTS:
 *   - Never proposes delete without replacement_owner AND test_coverage.
 *   - Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainArchitectureCompressionPlanner
{
    public const SCHEMA = 'atlas.external_brain.architecture_compression_planner.v1';

    public const ACTION_MERGE    = 'merge';
    public const ACTION_DELETE   = 'delete';
    public const ACTION_SIMPLIFY = 'simplify';
    public const ACTION_KEEP     = 'keep';

    private const DEFAULT_DUPLICATE_THRESHOLD = 2;
    private const DEFAULT_GROWTH_THRESHOLD    = 200;

    private const ACTION_ORDER = [
        self::ACTION_MERGE    => 0,
        self::ACTION_DELETE   => 1,
        self::ACTION_SIMPLIFY => 2,
        self::ACTION_KEEP     => 3,
    ];

    /**
     * @param  array{
     *   organs?: list<array{id?:string, capability_labels?:list<string>, files?:list<string>,
     *            line_count?:int, is_scaffold?:bool, stale_scaffold_marker?:bool,
     *            test_coverage?:bool, replacement_owner?:string}>,
     *   duplicate_threshold?: int,
     *   growth_threshold?: int,
     * }  $inventory
     * @return array{schema:string, candidates:list<array<string,mixed>>, plan_hash:string}
     */
    public function plan(array $inventory): array
    {
        $organs          = is_array($inventory['organs'] ?? null) ? $inventory['organs'] : [];
        $dupThreshold    = max(2, (int) ($inventory['duplicate_threshold'] ?? self::DEFAULT_DUPLICATE_THRESHOLD));
        $growthThreshold = max(1, (int) ($inventory['growth_threshold']    ?? self::DEFAULT_GROWTH_THRESHOLD));

        // Build capability-label → organ-id index for duplicate detection.
        $labelToIds = [];
        foreach ($organs as $organ) {
            $id     = (string) ($organ['id'] ?? '');
            $labels = array_values(array_filter(array_map('strval', (array) ($organ['capability_labels'] ?? [])), static fn (string $l): bool => $l !== ''));
            foreach ($labels as $label) {
                $labelToIds[$label][] = $id;
            }
        }

        $candidates      = [];
        $mergedOrganIds  = [];

        // ── Pass 1: merge candidates (duplicate capability labels) ──────────────
        foreach ($labelToIds as $label => $ids) {
            $uniqueIds = array_values(array_unique($ids));
            if (count($uniqueIds) < $dupThreshold) {
                continue;
            }
            $files      = [];
            $totalLines = 0;
            foreach ($organs as $organ) {
                if (! in_array((string) ($organ['id'] ?? ''), $uniqueIds, true)) {
                    continue;
                }
                foreach ((array) ($organ['files'] ?? []) as $f) {
                    $fs = (string) $f;
                    if ($fs !== '' && ! in_array($fs, $files, true)) {
                        $files[] = $fs;
                    }
                }
                $totalLines += max(0, (int) ($organ['line_count'] ?? 0));
            }
            sort($files);
            $candidates[] = [
                'candidate_id'        => 'merge:'.implode('+', $uniqueIds),
                'action'              => self::ACTION_MERGE,
                'impacted_files'      => $files,
                'expected_line_delta' => -(int) round($totalLines * 0.20),
                'risk_level'          => count($uniqueIds) > 3 ? 'high' : 'medium',
                'evidence_floor'      => 'duplicate_capability_label:'.$label.':organs:'.implode(',', $uniqueIds),
                'duplicate_label'     => $label,
                'organ_ids'           => $uniqueIds,
            ];
            foreach ($uniqueIds as $id) {
                $mergedOrganIds[$id] = true;
            }
        }

        // ── Pass 2: per-organ delete / simplify / keep ──────────────────────────
        foreach ($organs as $organ) {
            $id          = (string) ($organ['id'] ?? '');
            $files       = array_values(array_filter(array_map('strval', (array) ($organ['files'] ?? [])), static fn (string $f): bool => $f !== ''));
            $lineCount   = max(0, (int) ($organ['line_count'] ?? 0));
            $isStale     = (bool) ($organ['stale_scaffold_marker'] ?? false) || (bool) ($organ['is_scaffold'] ?? false);
            $hasCoverage = (bool) ($organ['test_coverage'] ?? false);
            $hasOwner    = (string) ($organ['replacement_owner'] ?? '') !== '';
            sort($files);

            if ($isStale) {
                if ($hasOwner && $hasCoverage) {
                    $candidates[] = [
                        'candidate_id'        => 'delete:'.$id,
                        'action'              => self::ACTION_DELETE,
                        'impacted_files'      => $files,
                        'expected_line_delta' => -$lineCount,
                        'risk_level'          => 'low',
                        'evidence_floor'      => 'stale_scaffold_marker:true AND test_coverage:true AND replacement_owner:'.$organ['replacement_owner'],
                    ];
                } else {
                    $reason = ! $hasOwner ? 'no_replacement_owner' : 'missing_test_coverage';
                    $candidates[] = [
                        'candidate_id'        => 'keep:'.$id.':stale_no_safe_delete',
                        'action'              => self::ACTION_KEEP,
                        'impacted_files'      => $files,
                        'expected_line_delta' => 0,
                        'risk_level'          => 'high',
                        'evidence_floor'      => 'stale_scaffold_marker:true',
                        'reason'              => $reason,
                    ];
                }
                continue;
            }

            if ($lineCount >= $growthThreshold) {
                if ($hasCoverage) {
                    $candidates[] = [
                        'candidate_id'        => 'simplify:'.$id,
                        'action'              => self::ACTION_SIMPLIFY,
                        'impacted_files'      => $files,
                        'expected_line_delta' => -(int) round($lineCount * 0.15),
                        'risk_level'          => 'low',
                        'evidence_floor'      => 'line_count:gte_'.$growthThreshold.' AND test_coverage:true',
                    ];
                } else {
                    $candidates[] = [
                        'candidate_id'        => 'keep:'.$id.':high_lines_no_coverage',
                        'action'              => self::ACTION_KEEP,
                        'impacted_files'      => $files,
                        'expected_line_delta' => 0,
                        'risk_level'          => 'medium',
                        'evidence_floor'      => 'line_count:gte_'.$growthThreshold,
                        'reason'              => 'missing_test_coverage_for_simplification',
                    ];
                }
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            $ao = self::ACTION_ORDER[$a['action']] ?? 4;
            $bo = self::ACTION_ORDER[$b['action']] ?? 4;

            return $ao !== $bo ? $ao <=> $bo : strcmp((string) $a['candidate_id'], (string) $b['candidate_id']);
        });

        return [
            'schema'     => self::SCHEMA,
            'candidates' => $candidates,
            'plan_hash'  => 'compression_'.substr(hash('sha256', (string) json_encode($candidates, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 32),
        ];
    }
}
