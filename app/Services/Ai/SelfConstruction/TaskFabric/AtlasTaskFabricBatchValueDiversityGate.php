<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure gate. Rejects homogeneous task batches before enqueue.
 *
 * Checks per batch (skipped when batch size < 2):
 *   - objective_fingerprint_concentration  : >50% specs share the same 4-word objective fingerprint
 *   - acceptance_shape_concentration       : >50% specs share the same acceptance-criteria shape
 *   - one_file_concentration               : >50% specs touch only one file
 *   - insufficient_dimension_diversity     : fewer than MIN_DISTINCT_DIMENSIONS value dimensions
 *
 * NO process execution, NO filesystem, NO providers.
 * Output is DETERMINISTIC given the same input.
 */
final class AtlasTaskFabricBatchValueDiversityGate
{
    public const SCHEMA = 'atlas.task_fabric.batch_value_diversity_gate.v1';

    private const CONCENTRATION_THRESHOLD = 0.5;

    private const MIN_DISTINCT_DIMENSIONS = 2;

    private const DIMENSION_KEYWORDS = [
        'queue_health'   => ['queue', 'backlog', 'jam', 'stale', 'poison'],
        'learning'       => ['learn', 'knowledge', 'memory', 'capture', 'insight'],
        'verification'   => ['verify', 'test', 'proof', 'gate', 'certif'],
        'simplification' => ['simplif', 'refactor', 'clean', 'consolidat', 'deduplic'],
        'research'       => ['research', 'dissect', 'analys', 'survey', 'discover'],
        'implementation' => ['implement', 'add', 'build', 'create', 'ship'],
        'hardening'      => ['harden', 'robust', 'resilient', 'guard', 'protect'],
        'monitoring'     => ['monitor', 'observ', 'alert', 'metric', 'telemetry'],
    ];

    /**
     * @param  list<array<string,mixed>>  $specs
     * @param  array<string,mixed>        $options
     * @return array<string,mixed>
     */
    public function evaluate(array $specs, array $options = []): array
    {
        $total = count($specs);

        if ($total < 2) {
            return [
                'schema_version' => self::SCHEMA,
                'passed'         => true,
                'blockers'       => [],
                'repair_hints'   => [],
                'diversity_facts' => ['total' => $total, 'skipped_minimum_not_met' => true],
            ];
        }

        $blockers     = [];
        $repairHints  = [];
        $diversityFacts = ['total' => $total];

        // Objective fingerprint concentration.
        $fingerprints  = array_map(fn (array $s): string => $this->objectiveFingerprint((string) ($s['objective'] ?? '')), $specs);
        $fpCounts      = array_count_values($fingerprints);
        arsort($fpCounts);
        $topFp         = (string) array_key_first($fpCounts);
        $topFpCount    = $fpCounts[$topFp] ?? 0;
        $fpConcentration = $topFpCount / $total;
        $diversityFacts['objective_fingerprint_concentration'] = round($fpConcentration, 3);
        $diversityFacts['top_objective_fingerprint'] = $topFp;
        if ($fpConcentration > self::CONCENTRATION_THRESHOLD) {
            $blockers[]    = 'objective_fingerprint_concentration';
            $repairHints[] = "Too many specs share objective fingerprint '{$topFp}' ({$topFpCount}/{$total}). Vary objectives across the batch.";
        }

        // Acceptance shape concentration.
        $shapes        = array_map(fn (array $s): string => $this->acceptanceShape((array) ($s['acceptance_criteria'] ?? [])), $specs);
        $shapeCounts   = array_count_values($shapes);
        arsort($shapeCounts);
        $topShapeCount = (int) reset($shapeCounts);
        $shapeConcentration = $topShapeCount / $total;
        $diversityFacts['acceptance_shape_concentration'] = round($shapeConcentration, 3);
        if ($shapeConcentration > self::CONCENTRATION_THRESHOLD) {
            $blockers[]    = 'acceptance_shape_concentration';
            $repairHints[] = "Too many specs share the same acceptance criteria shape ({$topShapeCount}/{$total}). Diversify acceptance criteria wording and structure.";
        }

        // One-file concentration.
        $oneFileCount = count(array_filter($specs, fn (array $s): bool => count((array) ($s['allowed_files'] ?? [])) === 1));
        $oneFileConcentration = $oneFileCount / $total;
        $diversityFacts['one_file_concentration'] = round($oneFileConcentration, 3);
        if ($oneFileConcentration > self::CONCENTRATION_THRESHOLD) {
            $blockers[]    = 'one_file_concentration';
            $repairHints[] = "Too many specs touch only one file ({$oneFileCount}/{$total}). Include multi-file specs that cross module boundaries.";
        }

        // Value dimension diversity.
        $dimensions       = array_map(fn (array $s): string => $this->classifyDimension((string) ($s['objective'] ?? '')), $specs);
        $distinctDimensions = array_values(array_unique($dimensions));
        $diversityFacts['distinct_dimensions']     = $distinctDimensions;
        $diversityFacts['distinct_dimension_count'] = count($distinctDimensions);
        if (count($distinctDimensions) < self::MIN_DISTINCT_DIMENSIONS) {
            $blockers[]    = 'insufficient_dimension_diversity';
            $repairHints[] = 'Batch covers fewer than ' . self::MIN_DISTINCT_DIMENSIONS . ' distinct value dimensions (' . implode(', ', $distinctDimensions) . '). Add specs from other dimensions.';
        }

        return [
            'schema_version' => self::SCHEMA,
            'passed'         => $blockers === [],
            'blockers'       => $blockers,
            'repair_hints'   => $repairHints,
            'diversity_facts' => $diversityFacts,
        ];
    }

    private function objectiveFingerprint(string $objective): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9 ]/i', ' ', $objective));
        $words      = array_values(array_filter(explode(' ', $normalized)));

        return implode(' ', array_slice($words, 0, 4));
    }

    private function acceptanceShape(array $criteria): string
    {
        if ($criteria === []) {
            return '__empty__';
        }
        $parts = [];
        foreach (array_slice($criteria, 0, 3) as $c) {
            $normalized = strtolower((string) preg_replace('/[^a-z0-9 ]/i', ' ', (string) $c));
            $words      = array_values(array_filter(explode(' ', $normalized)));
            $parts[]    = implode(' ', array_slice($words, 0, 3));
        }

        return implode('|', $parts);
    }

    private function classifyDimension(string $objective): string
    {
        $lower = strtolower($objective);
        foreach (self::DIMENSION_KEYWORDS as $dimension => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $dimension;
                }
            }
        }

        return 'other';
    }
}
