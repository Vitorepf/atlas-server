<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure reducer that converts task outcomes into concrete next-batch seed constraints.
 *
 * Completed outcomes produce amplify constraints (preserve proven high-leverage veins).
 * Give_back, blocked and quarantined outcomes produce avoid_or_repair constraints
 * tied to target family and reason (prevent repeating failed target families).
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainOutcomeToSeedFeedbackReducer
{
    public const SCHEMA = 'atlas.external_brain.outcome_to_seed_feedback_reducer.v1';

    public const CONSTRAINT_AMPLIFY = 'amplify';
    public const CONSTRAINT_AVOID_OR_REPAIR = 'avoid_or_repair';

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     * @return array<string, mixed>
     */
    public function reduce(array $outcomes): array
    {
        $constraints = [];

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            $result = (string) ($outcome['result'] ?? '');
            $targetFamily = (string) ($outcome['target_family'] ?? '');
            $reason = (string) ($outcome['reason'] ?? '');
            $taskId = (string) ($outcome['task_id'] ?? '');

            if ($targetFamily === '') {
                continue;
            }

            $constraint = match ($result) {
                'completed', 'success', 'delivered' => [
                    'constraint' => self::CONSTRAINT_AMPLIFY,
                    'target_family' => $targetFamily,
                    'reason' => 'proven_high_leverage_vein',
                    'source_task_id' => $taskId,
                ],
                'give_back', 'blocked', 'quarantined' => [
                    'constraint' => self::CONSTRAINT_AVOID_OR_REPAIR,
                    'target_family' => $targetFamily,
                    'reason' => $reason !== '' ? $reason : $result.'_outcome_requires_avoid_or_repair',
                    'source_task_id' => $taskId,
                ],
                default => null,
            };

            if ($constraint !== null) {
                $constraints[] = $constraint;
            }
        }

        // Deduplicate by constraint + target_family + reason.
        $seen = [];
        $deduped = [];
        foreach ($constraints as $c) {
            $key = $c['constraint'].':'.$c['target_family'].':'.$c['reason'];
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $c;
            }
        }

        // Sort deterministically by constraint, target_family, reason.
        usort($deduped, static function (array $a, array $b): int {
            return strcmp($a['constraint'], $b['constraint'])
                ?: strcmp($a['target_family'], $b['target_family'])
                ?: strcmp($a['reason'], $b['reason']);
        });

        $amplify = array_values(array_filter($deduped, static fn (array $c): bool => $c['constraint'] === self::CONSTRAINT_AMPLIFY));
        $avoidOrRepair = array_values(array_filter($deduped, static fn (array $c): bool => $c['constraint'] === self::CONSTRAINT_AVOID_OR_REPAIR));

        return [
            'schema_version' => self::SCHEMA,
            'constraints' => $deduped,
            'amplify_constraints' => $amplify,
            'avoid_or_repair_constraints' => $avoidOrRepair,
            'total_constraints' => count($deduped),
        ];
    }
}
