<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

/**
 * Pure, facts-only entropy monitor for a CANDIDATE BATCH (not a single packet). Measures whether the
 * batch is genuinely diverse or just the same template repeated with different class names, by scoring
 * objective vocabulary, allowed_files families, acceptance shapes, behavior verbs, and evidence
 * requirements. Performs no queue, git, or provider I/O — it only reads the in-memory candidate list.
 *
 * Input candidate shape: {objective:string, allowed_files?:list<string>, acceptance_criteria?:list<string>}
 */
final class AtlasTaskFabricSpecEntropyMonitor
{
    public const SCHEMA = 'atlas.self_construction.task_fabric.spec_entropy_monitor.v1';

    public const VERDICT_LOW_ENTROPY = 'low_entropy';

    public const VERDICT_DIVERSE = 'diverse';

    private const LOW_ENTROPY_THRESHOLD = 0.5;

    private const BEHAVIOR_VERB_VOCAB = [
        'rank', 'sequence', 'classify', 'detect', 'score', 'reject', 'admit', 'route', 'validate',
        'compute', 'sort', 'merge', 'filter', 'withhold', 'approve', 'escalate', 'verify', 'order',
        'block', 'allow',
    ];

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function monitor(array $candidates): array
    {
        $count = max(1, count($candidates));

        $objectiveSkeletons = [];
        $acceptanceShapes = [];
        $fileFamilySignatures = [];
        $combinedAcceptanceText = '';

        foreach ($candidates as $candidate) {
            $objectiveSkeletons[] = $this->skeleton((string) ($candidate['objective'] ?? ''));

            $criteria = array_map('strval', (array) ($candidate['acceptance_criteria'] ?? []));
            $shapeParts = array_map(fn (string $c): string => $this->skeleton($c), $criteria);
            sort($shapeParts);
            $acceptanceShapes[] = implode('|', $shapeParts);
            $combinedAcceptanceText .= ' '.implode(' ', $criteria);

            $files = array_map('strval', (array) ($candidate['allowed_files'] ?? []));
            $families = array_unique(array_map(static fn (string $f): string => dirname($f), $files));
            sort($families);
            $fileFamilySignatures[] = implode(',', $families);
        }

        $uniqueObjectiveSkeletons = array_unique($objectiveSkeletons);
        $uniqueAcceptanceShapes = array_unique($acceptanceShapes);
        $uniqueFileFamilies = array_unique($fileFamilySignatures);

        $haystack = strtolower($combinedAcceptanceText);
        $uniqueBehaviorVerbs = array_values(array_filter(
            self::BEHAVIOR_VERB_VOCAB,
            static fn (string $verb): bool => str_contains($haystack, $verb),
        ));
        sort($uniqueBehaviorVerbs);

        $objectiveRatio = count($uniqueObjectiveSkeletons) / $count;
        $acceptanceRatio = count($uniqueAcceptanceShapes) / $count;
        $familyRatio = count($uniqueFileFamilies) / $count;
        $verbRatio = min(1.0, count($uniqueBehaviorVerbs) / $count);

        $entropyScore = round(($objectiveRatio + $acceptanceRatio + $familyRatio + $verbRatio) / 4, 2);

        $skeletonCounts = array_count_values($objectiveSkeletons);
        $repeatedShapes = array_values(array_filter(array_keys($skeletonCounts), static fn (string $s): bool => $skeletonCounts[$s] > 1));
        sort($repeatedShapes);

        $verdict = $entropyScore < self::LOW_ENTROPY_THRESHOLD ? self::VERDICT_LOW_ENTROPY : self::VERDICT_DIVERSE;

        $recommendedBatchSize = $verdict === self::VERDICT_LOW_ENTROPY
            ? count($uniqueObjectiveSkeletons)
            : count($candidates);

        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'entropy_score' => $entropyScore,
            'repeated_shapes' => $repeatedShapes,
            'unique_behavior_verbs' => $uniqueBehaviorVerbs,
            'recommended_batch_size' => $recommendedBatchSize,
        ];
    }

    /**
     * Lowercases and replaces concrete class-name-looking tokens and numbers with placeholders, so two
     * strings that differ only by class name or a number collapse to the same "template skeleton".
     */
    private function skeleton(string $text): string
    {
        $withoutIdentifiers = preg_replace('/\b[A-Z][A-Za-z0-9]{2,}\b/', '<ID>', $text) ?? $text;
        $withoutNumbers = preg_replace('/\b\d+\b/', '<NUM>', $withoutIdentifiers) ?? $withoutIdentifiers;

        return trim(preg_replace('/\s+/', ' ', strtolower($withoutNumbers)) ?? '');
    }
}
