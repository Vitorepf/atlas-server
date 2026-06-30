<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure retention policy. Evaluates a list of learning records and classifies each
 * into retained | decaying | retired.
 *
 * Classification priority (first match wins):
 *   1. retired:   overridden === true                     → overridden_by_newer_learning
 *   2. retired:   age_days > MAX_AGE_DAYS (180)           → exceeded_maximum_age
 *   3. retained:  type is a POISON type AND actionable AND
 *                 age_days <= POISON_RETENTION_DAYS (90)   → poison_pattern_longevity (AC3)
 *   4. decaying:  !confirmed AND age_days > UNCONFIRMED_DECAY_DAYS (14) → old_unconfirmed_hint (AC2)
 *   5. retained:  utility_score >= HIGH_UTILITY (0.7) AND
 *                 age_days <= RECENT_DAYS (30)             → high_utility_recent (AC2)
 *   6. decaying:  utility_score < LOW_UTILITY (0.3)        → low_utility_learning
 *   7. decaying:  default (conservative)                   → default_decay
 *
 * AC3: poison-pattern and give_back_hint types are preserved for POISON_RETENTION_DAYS
 *      (90 d) while ordinary success_note records default-decay after RECENT_DAYS (30 d).
 *      This asymmetry is the primary signal that makes poison patterns persist longer.
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

        $retained = [];
        $decaying = [];
        $retired  = [];

        foreach ($records as $rec) {
            $id         = (string) ($rec['id'] ?? '');
            $type       = strtolower(trim((string) ($rec['type'] ?? 'unknown')));
            $utility    = max(0.0, min(1.0, (float) ($rec['utility_score'] ?? 0.0)));
            $ageDays    = max(0, (int) ($rec['age_days'] ?? 0));
            $confirmed  = (bool) ($rec['confirmed'] ?? false);
            $overridden = (bool) ($rec['overridden'] ?? false);
            $actionable = (bool) ($rec['actionable'] ?? true);

            [$disposition, $reason] = $this->classify($type, $utility, $ageDays, $confirmed, $overridden, $actionable);

            $entry = ['id' => $id, 'type' => $type, 'utility_score' => $utility, 'age_days' => $ageDays, 'reason' => $reason];
            match ($disposition) {
                'retained' => $retained[] = $entry,
                'decaying' => $decaying[] = $entry,
                default    => $retired[]  = $entry,
            };
        }

        return [
            'schema_version'  => self::SCHEMA,
            'retained'        => $retained,
            'decaying'        => $decaying,
            'retired'         => $retired,
            'retained_count'  => count($retained),
            'decaying_count'  => count($decaying),
            'retired_count'   => count($retired),
        ];
    }

    /**
     * @return array{string, string}  [disposition, reason]
     */
    private function classify(
        string $type, float $utility, int $ageDays,
        bool $confirmed, bool $overridden, bool $actionable,
    ): array {
        // 1. Overridden → retire.
        if ($overridden) {
            return ['retired', 'overridden_by_newer_learning'];
        }

        // 2. Too old → retire.
        if ($ageDays > self::MAX_AGE_DAYS) {
            return ['retired', 'exceeded_maximum_age'];
        }

        // 3. Poison pattern still actionable and within its extended window → retain (AC3).
        if (in_array($type, self::POISON_TYPES, true) && $actionable && $ageDays <= self::POISON_RETENTION_DAYS) {
            return ['retained', 'poison_pattern_longevity'];
        }

        // 4. Unconfirmed and too old → decay (AC2).
        if (! $confirmed && $ageDays > self::UNCONFIRMED_DECAY_DAYS) {
            return ['decaying', 'old_unconfirmed_hint'];
        }

        // 5. High utility and recent → retain (AC2).
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
