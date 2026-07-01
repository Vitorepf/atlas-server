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
 *   - template_farm_concentration          : ALL specs look like worker-floor top-up boilerplate
 *                                            (mention worker/claimable + top-up/replenish) AND
 *                                            >50% share the same TEMPLATE fingerprint (objective
 *                                            with numeric ids/counters stripped) — catches a batch
 *                                            that looks superficially varied (different counters)
 *                                            but is actually homogeneous padding.
 *   - template_width_not_value_diversity   : >50% specs share the same MECHANISM fingerprint
 *                                            (objective with numeric ids AND class-name-like
 *                                            identifiers stripped) — unlike template_farm_concentration
 *                                            this is unconditional (no worker-floor language gate),
 *                                            catching a batch of "renamed wrapper" specs whose only
 *                                            difference is the target class/file name (AC2 new).
 *
 * options.batch_purpose (e.g. 'replenish_soon') is informational only: a smaller emergency
 * top-up batch is allowed through on the SAME rules as any other batch — the dimension-diversity
 * check (>=2 distinct value dimensions) is what decides whether it is real, varied work or
 * homogeneous padding; there is no separate minimum-size rule to relax.
 *
 * diversity_facts.duplicate_mechanism_clusters (AC4 new): every mechanism-fingerprint group with
 * 2+ members, each naming its spec_indices and objectives, so the originator can see exactly which
 * weak/duplicate specs to replace instead of only a pass/fail verdict.
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
        $batchPurpose = trim((string) ($options['batch_purpose'] ?? ''));
        $diversityFacts = ['total' => $total, 'batch_purpose' => $batchPurpose];

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

        // Template-farm concentration: a batch that LOOKS varied (different counters/ids in the
        // objective) but is actually worker-floor top-up boilerplate repeated with the numbers
        // swapped out. Only evaluated when EVERY spec in the batch matches the worker-floor
        // boilerplate marker — a mixed batch is never penalized for containing some top-up specs.
        $boilerplateFlags = array_map(
            fn (array $s): bool => $this->isWorkerFloorBoilerplate((string) ($s['objective'] ?? '')),
            $specs,
        );
        if (! in_array(false, $boilerplateFlags, true)) {
            $templateFingerprints = array_map(
                fn (array $s): string => $this->templateFingerprint((string) ($s['objective'] ?? '')),
                $specs,
            );
            $tfpCounts = array_count_values($templateFingerprints);
            arsort($tfpCounts);
            $topTfp = (string) array_key_first($tfpCounts);
            $topTfpCount = $tfpCounts[$topTfp] ?? 0;
            $templateConcentration = $topTfpCount / $total;
            $diversityFacts['template_farm_concentration'] = round($templateConcentration, 3);
            if ($templateConcentration > self::CONCENTRATION_THRESHOLD) {
                $blockers[]    = 'template_farm_concentration';
                $repairHints[] = "Batch is worker-floor top-up boilerplate sharing the same structural template once ids/counters are stripped ({$topTfpCount}/{$total}). Vary the underlying task content, not just ids or counters.";
            }
        }

        // AC2: mechanism-fingerprint concentration — unconditional (no worker-floor language
        // gate), so a batch of "renamed wrapper" specs (same shape, different target class/file)
        // is caught even when it never mentions worker/claimable/top-up language.
        $mechanismFingerprints = array_map(
            fn (array $s): string => $this->mechanismFingerprint((string) ($s['objective'] ?? '')),
            $specs,
        );
        $mfCounts = array_count_values($mechanismFingerprints);
        arsort($mfCounts);
        $topMf = (string) array_key_first($mfCounts);
        $topMfCount = $mfCounts[$topMf] ?? 0;
        $mechanismConcentration = $topMfCount / $total;
        $diversityFacts['mechanism_fingerprint_concentration'] = round($mechanismConcentration, 3);
        if ($mechanismConcentration > self::CONCENTRATION_THRESHOLD) {
            $blockers[]    = 'template_width_not_value_diversity';
            $repairHints[] = "Batch is a renamed-wrapper template: {$topMfCount}/{$total} specs share the same structural shape once class/file names and ids are stripped. Vary the underlying leverage mechanism, not just the target name.";
        }

        // AC4: duplicate mechanism clusters — every group with 2+ members, so the originator can
        // see exactly which specs are duplicates and replace the weak ones.
        $mechanismGroups = [];
        foreach ($specs as $i => $s) {
            $mechanismGroups[$mechanismFingerprints[$i]][] = ['index' => $i, 'objective' => (string) ($s['objective'] ?? '')];
        }
        $duplicateClusters = [];
        foreach ($mechanismGroups as $fingerprint => $members) {
            if (count($members) > 1) {
                $duplicateClusters[] = [
                    'mechanism_fingerprint' => $fingerprint,
                    'count' => count($members),
                    'spec_indices' => array_column($members, 'index'),
                    'objectives' => array_column($members, 'objective'),
                ];
            }
        }
        usort($duplicateClusters, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $diversityFacts['duplicate_mechanism_clusters'] = $duplicateClusters;

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

    /**
     * True when the objective mentions worker-floor top-up language — the kind of text our own
     * worker-feed-risk auto-replenishment emits — so the template-farm check only ever evaluates
     * batches that are actually emergency top-up candidates.
     */
    private function isWorkerFloorBoilerplate(string $objective): bool
    {
        $lower = strtolower($objective);
        $mentionsWorkerOrClaimable = str_contains($lower, 'worker') || str_contains($lower, 'claimable');
        $mentionsTopUp = str_contains($lower, 'top up') || str_contains($lower, 'top-up')
            || str_contains($lower, 'topup') || str_contains($lower, 'replenish');

        return $mentionsWorkerOrClaimable && $mentionsTopUp;
    }

    /**
     * Structural template of an objective with numeric ids/counters stripped, so "top up worker 1"
     * and "top up worker 2" collapse to the same template even though their raw text — and their
     * 4-word objective_fingerprint — differ.
     */
    private function templateFingerprint(string $objective): string
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9 ]/i', ' ', $objective));
        $normalized = (string) preg_replace('/\b[0-9]+\b/', '', $normalized);
        $words = array_values(array_filter(explode(' ', $normalized)));

        return implode(' ', $words);
    }

    /**
     * Structural mechanism shape of an objective with class/file-name-like identifiers (words
     * containing a second capital letter, e.g. "AtlasFooWidget") AND numeric ids/counters both
     * stripped — so "Harden AtlasFooWidget so it validates" and "Harden AtlasBarGadget so it
     * validates" collapse to the same fingerprint even though every other fingerprint in this
     * file (which only strips digits or matches raw words) would treat them as distinct.
     */
    private function mechanismFingerprint(string $objective): string
    {
        $withPlaceholders = (string) preg_replace('/\b[A-Za-z][a-z0-9]*[A-Z][a-zA-Z0-9]*\b/', 'IDENT', $objective);
        $normalized = strtolower((string) preg_replace('/[^a-z0-9 ]/i', ' ', $withPlaceholders));
        $normalized = (string) preg_replace('/\b[0-9]+\b/', '', $normalized);
        $words = array_values(array_filter(explode(' ', $normalized)));

        return implode(' ', $words);
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
