<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure retention policy. Evaluates a list of learning records and classifies each
 * into retained | decaying | retired.
 *
 * Classification priority (first match wins):
 *   1. retired:              overridden === true                     → overridden_by_newer_learning
 *   2. retired:              age_days > MAX_AGE_DAYS (180)           → exceeded_maximum_age
 *   3. retained:             type is a POISON type AND actionable AND
 *                            age_days <= POISON_RETENTION_DAYS (90)  → poison_pattern_longevity
 *   3.5 revalidation_needed: utility >= HIGH_UTILITY AND age > RECENT_DAYS AND !confirmed
 *                                                                     → high_utility_stale_needs_revalidation (AC3 new)
 *   4. decaying:             !confirmed AND age_days > UNCONFIRMED_DECAY_DAYS (14) → old_unconfirmed_hint
 *   4.5 decaying:            contradiction_evidence non-empty         → contradicted_by_outcome_evidence (AC2 new)
 *   5. retained:             utility_score >= HIGH_UTILITY (0.7) AND
 *                            age_days <= RECENT_DAYS (30)             → high_utility_recent
 *   6. decaying:             utility_score < LOW_UTILITY (0.3)        → low_utility_learning
 *   7. decaying:             default (conservative)                   → default_decay
 *
 * AC3 new: high-utility stale unconfirmed records go to revalidation_needed, not retired/decayed.
 * AC2 new: records with contradiction_evidence are decayed with contradicted_by_outcome_evidence reason.
 *
 * Pure read-only over supplied records. NO process execution, NO filesystem, NO providers.
 * DETERMINISTIC.
 */
final class AtlasExternalBrainLearningRetentionPolicy
{
    public const SCHEMA = 'atlas.external_brain.learning_retention_policy.v1';

    private const MAX_AGE_DAYS           = 180;
    private const POISON_RETENTION_DAYS  = 90;
    private const UNCONFIRMED_DECAY_DAYS = 14;
    private const RECENT_DAYS            = 30;
    private const HIGH_UTILITY           = 0.7;
    private const LOW_UTILITY            = 0.3;

    private const POISON_TYPES = ['poison_pattern', 'give_back_hint', 'failure_pattern'];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $records = is_array($facts['learning_records'] ?? null) ? $facts['learning_records'] : [];

        $retained           = [];
        $decaying           = [];
        $retired            = [];
        $revalidationNeeded = [];

        foreach ($records as $rec) {
            $id                  = (string) ($rec['id'] ?? '');
            $type                = strtolower(trim((string) ($rec['type'] ?? 'unknown')));
            $utility             = max(0.0, min(1.0, (float) ($rec['utility_score'] ?? 0.0)));
            $ageDays             = max(0, (int) ($rec['age_days'] ?? 0));
            $confirmed           = (bool) ($rec['confirmed'] ?? false);
            $overridden          = (bool) ($rec['overridden'] ?? false);
            $actionable          = (bool) ($rec['actionable'] ?? true);
            $contradictionEvidence = array_values(array_filter(
                array_map('trim', (array) ($rec['contradiction_evidence'] ?? [])),
                static fn (string $s): bool => $s !== '',
            ));

            [$disposition, $reason] = $this->classify(
                $type, $utility, $ageDays, $confirmed, $overridden, $actionable, $contradictionEvidence,
            );

            $entry = ['id' => $id, 'type' => $type, 'utility_score' => $utility, 'age_days' => $ageDays, 'reason' => $reason];
            match ($disposition) {
                'retained'            => $retained[]           = $entry,
                'decaying'            => $decaying[]           = $entry,
                'revalidation_needed' => $revalidationNeeded[] = $entry,
                default               => $retired[]            = $entry,
            };
        }

        return [
            'schema_version'          => self::SCHEMA,
            'retained'                => $retained,
            'decaying'                => $decaying,
            'retired'                 => $retired,
            'revalidation_needed'     => $revalidationNeeded,
            'retained_count'          => count($retained),
            'decaying_count'          => count($decaying),
            'retired_count'           => count($retired),
            'revalidation_needed_count' => count($revalidationNeeded),
        ];
    }

    /**
     * @param  list<string>  $contradictionEvidence
     * @return array{string, string}  [disposition, reason]
     */
    private function classify(
        string $type, float $utility, int $ageDays,
        bool $confirmed, bool $overridden, bool $actionable,
        array $contradictionEvidence = [],
    ): array {
        // 1. Overridden → retire.
        if ($overridden) {
            return ['retired', 'overridden_by_newer_learning'];
        }

        // 2. Too old → retire.
        if ($ageDays > self::MAX_AGE_DAYS) {
            return ['retired', 'exceeded_maximum_age'];
        }

        // 3. Poison pattern still actionable and within its extended window → retain.
        if (in_array($type, self::POISON_TYPES, true) && $actionable && $ageDays <= self::POISON_RETENTION_DAYS) {
            return ['retained', 'poison_pattern_longevity'];
        }

        // 3.5. High-utility stale unconfirmed → revalidation_needed (AC3 new).
        if ($utility >= self::HIGH_UTILITY && $ageDays > self::RECENT_DAYS && ! $confirmed) {
            return ['revalidation_needed', 'high_utility_stale_needs_revalidation'];
        }

        // 4. Unconfirmed and too old → decay.
        if (! $confirmed && $ageDays > self::UNCONFIRMED_DECAY_DAYS) {
            return ['decaying', 'old_unconfirmed_hint'];
        }

        // 4.5. Contradicted by newer outcome evidence → decay (AC2 new).
        if ($contradictionEvidence !== []) {
            return ['decaying', 'contradicted_by_outcome_evidence'];
        }

        // 5. High utility and recent → retain.
        if ($utility >= self::HIGH_UTILITY && $ageDays <= self::RECENT_DAYS) {
            return ['retained', 'high_utility_recent'];
        }

        // 6. Low utility → decay.
        if ($utility < self::LOW_UTILITY) {
            return ['decaying', 'low_utility_learning'];
        }

        // 7. Default: decay (conservative).
        return ['decaying', 'default_decay'];
    }
}
