<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes LearningDecayDetector::detect + LearningRetentionPolicy::evaluate
 * into one retention verdict.
 *
 * Input may be in either format:
 *   - { lessons: [...] } (decay detector format) — runs detect, then maps
 *     detected lessons as learning_records for the retention policy.
 *   - { learning_records: [...] } (retention policy format) — skips the
 *     decay detector and passes records directly to the policy.
 */
final class AtlasExternalBrainLearningRetentionRunner
{
    public const SCHEMA = 'atlas.external_brain.learning_retention_runner.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $decayDetector = new AtlasExternalBrainLearningDecayDetector;
        $retentionPolicy = new AtlasExternalBrainLearningRetentionPolicy;

        // Run decay detector if lessons are provided in its format.
        $decayInput = is_array($input['lessons'] ?? null) ? $input : [];
        $decay = $decayInput !== [] ? $decayDetector->detect($decayInput) : ['lessons' => []];

        // Prepare learning_records for the retention policy.
        // Either from input directly or mapped from decay detector output.
        $learningRecords = $input['learning_records'] ?? $decay['lessons'] ?? [];

        $retention = $retentionPolicy->evaluate([
            'learning_records' => $learningRecords,
        ]);

        // Extract decay summary if detector ran.
        $totalLearnings = (int) ($decay['total'] ?? 0);
        $decayedCount = (int) ($decay['decayed_count'] ?? 0);
        $contradictedCount = (int) ($decay['contradicted_count'] ?? 0);
        $overfitCount = (int) ($decay['overfit_count'] ?? 0);
        $freshCount = (int) ($decay['fresh_count'] ?? 0);

        $retainedCount = (int) ($retention['retained_count'] ?? 0);
        $refreshedCount = (int) ($retention['revalidation_needed_count'] ?? 0);
        $retiredCount = (int) ($retention['retired_count'] ?? 0);

        return [
            'schema_version' => self::SCHEMA,
            'decay_summary' => [
                'total_learnings' => $totalLearnings,
                'decayed_count' => $decayedCount,
                'contradicted_count' => $contradictedCount,
                'overfit_count' => $overfitCount,
                'fresh_count' => $freshCount,
            ],
            'retention_summary' => [
                'retained_count' => $retainedCount,
                'refreshed_count' => $refreshedCount,
                'retired_count' => $retiredCount,
            ],
            'retained' => (array) ($retention['retained'] ?? []),
            'refreshed' => (array) ($retention['revalidation_needed'] ?? []),
            'retired' => (array) ($retention['retired'] ?? []),
        ];
    }
}
