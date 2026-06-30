<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure novelty gate. Each candidate task spec must prove novelty against
 * queued targets, recently authored specs, and existing capability/class
 * names BEFORE it is safe to enqueue.
 *
 * Similarity between a candidate and an existing item is a weighted blend:
 *   0.5 × objective token Jaccard overlap
 *   0.25 × allowed_files Jaccard overlap
 *   0.25 × acceptance-criteria token Jaccard overlap
 *
 * A candidate is a DUPLICATE when EITHER:
 *   - its class_name exactly collides with an existing class name, OR
 *   - its best similarity against any existing item >= DUPLICATE_THRESHOLD
 *     (so a near-duplicate is caught even when the class name differs —
 *     same objective/acceptance/impact_class dressed up under a new name).
 *
 * safe_to_enqueue = NOT duplicate.
 * novelty_score    = 1 - best_similarity (1.0 = fully novel).
 *
 * INPUT:
 *   candidates: list<{
 *     class_name?:    string
 *     objective?:     string
 *     acceptance?:    list<string>
 *     allowed_files?: list<string>
 *     impact_class?:  string
 *   }>
 *   queued_targets:        list<{class_name?, objective?, acceptance?, allowed_files?}>
 *   recent_authored_specs: list<{class_name?, objective?, acceptance?, allowed_files?}>
 *   existing_class_names:  list<string>
 *
 * Pure: no I/O, no side effects, never enqueues anything itself.
 */
final class AtlasExternalBrainOriginatorSpecNoveltyGate
{
    public const SCHEMA = 'atlas.external_brain.originator_spec_novelty_gate.v1';

    public const DUPLICATE_THRESHOLD = 0.60;

    private const STOPWORDS = ['that', 'this', 'with', 'from', 'into', 'have', 'does', 'when', 'then', 'each', 'will', 'should', 'must'];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $pool = array_merge(
            $this->normalizePool($input['queued_targets'] ?? null, 'queued_target'),
            $this->normalizePool($input['recent_authored_specs'] ?? null, 'recent_authored_spec'),
        );
        $existingClassNames = is_array($input['existing_class_names'] ?? null)
            ? array_map('strval', $input['existing_class_names'])
            : [];

        $results = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $results[] = $this->evaluateOne($candidate, $pool, $existingClassNames);
        }

        return [
            'schema' => self::SCHEMA,
            'results' => $results,
            'candidate_count' => count($results),
            'safe_to_enqueue_count' => count(array_filter($results, static fn (array $r): bool => $r['safe_to_enqueue'])),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  list<array<string,mixed>>  $pool
     * @param  list<string>  $existingClassNames
     * @return array<string,mixed>
     */
    private function evaluateOne(array $candidate, array $pool, array $existingClassNames): array
    {
        $className = (string) ($candidate['class_name'] ?? '');
        $classNameCollision = $className !== '' && in_array($className, $existingClassNames, true);

        $bestMatch = null;
        $bestSimilarity = 0.0;
        foreach ($pool as $item) {
            $similarity = $this->similarity($candidate, $item);
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = $item;
            }
        }

        $semanticDuplicate = $bestSimilarity >= self::DUPLICATE_THRESHOLD;
        $duplicate = $classNameCollision || $semanticDuplicate;

        $duplicateEvidence = [];
        if ($classNameCollision) {
            $duplicateEvidence[] = [
                'matched_against' => $className,
                'similarity' => 1.0,
                'reason' => 'class_name_collision',
            ];
        }
        if ($semanticDuplicate && $bestMatch !== null) {
            $duplicateEvidence[] = [
                'matched_against' => $bestMatch['label'],
                'similarity' => round($bestSimilarity, 4),
                'reason' => 'semantic_overlap',
            ];
        }

        $suggestedAction = match (true) {
            ! $duplicate => null,
            $bestMatch !== null => 'merge_with_'.$bestMatch['label'],
            default => 'pivot_objective_or_scope',
        };

        return [
            'class_name' => $className,
            'novelty_score' => round(1.0 - $bestSimilarity, 4),
            'duplicate_evidence' => $duplicateEvidence,
            'safe_to_enqueue' => ! $duplicate,
            'suggested_merge_or_pivot_action' => $suggestedAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $item
     */
    private function similarity(array $candidate, array $item): float
    {
        $objectiveSim = $this->jaccard(
            $this->tokens((string) ($candidate['objective'] ?? '')),
            $item['objective_tokens'],
        );
        $filesSim = $this->jaccard(
            $this->toStringSet($candidate['allowed_files'] ?? null),
            $item['allowed_files'],
        );
        $acceptanceSim = $this->jaccard(
            $this->tokens(implode(' ', $this->toStringSet($candidate['acceptance'] ?? null))),
            $item['acceptance_tokens'],
        );

        return round(0.5 * $objectiveSim + 0.25 * $filesSim + 0.25 * $acceptanceSim, 6);
    }

    /**
     * @return list<array{label:string,objective_tokens:list<string>,allowed_files:list<string>,acceptance_tokens:list<string>}>
     */
    private function normalizePool(mixed $rawList, string $labelPrefix): array
    {
        if (! is_array($rawList)) {
            return [];
        }

        $pool = [];
        foreach ($rawList as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $label = (string) ($item['class_name'] ?? ($labelPrefix.'_'.$i));
            $pool[] = [
                'label' => $label,
                'objective_tokens' => $this->tokens((string) ($item['objective'] ?? '')),
                'allowed_files' => $this->toStringSet($item['allowed_files'] ?? null),
                'acceptance_tokens' => $this->tokens(implode(' ', $this->toStringSet($item['acceptance'] ?? null))),
            ];
        }

        return $pool;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];
        $words = array_filter($words, static fn (string $w): bool => strlen($w) >= 4 && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_unique($words));
    }

    /** @return list<string> */
    private function toStringSet(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== '')));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
