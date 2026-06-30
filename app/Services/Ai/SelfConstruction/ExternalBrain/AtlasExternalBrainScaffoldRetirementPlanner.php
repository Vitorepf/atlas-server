<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure scaffold retirement planner. Classifies each reasoning scaffold as
 * retire / merge / keep based on observed quality metrics, preventing scaffold
 * sprawl without deleting files (plan-only, governed implementation later).
 *
 * Classification (first match per scaffold):
 *   retire — lift < RETIRE_LIFT_FLOOR OR failure_recurrence_rate > RETIRE_FAILURE_CEILING
 *   retire — overlap_score > OVERLAP_RETIRE_CEILING AND replacement_candidate set
 *   merge  — overlap_score > OVERLAP_MERGE_THRESHOLD AND replacement_candidate set
 *   keep   — otherwise (explains why deletion reduces quality)
 *
 * AC4: pure PHP, deterministic, no file mutations.
 */
final class AtlasExternalBrainScaffoldRetirementPlanner
{
    public const SCHEMA = 'atlas.external_brain.scaffold_retirement_planner.v1';

    public const ACTION_RETIRE = 'retire';
    public const ACTION_MERGE  = 'merge';
    public const ACTION_KEEP   = 'keep';

    private const RETIRE_LIFT_FLOOR            = 0.20;
    private const RETIRE_FAILURE_CEILING       = 0.50;
    private const OVERLAP_RETIRE_CEILING       = 0.70;
    private const OVERLAP_MERGE_THRESHOLD      = 0.40;

    /**
     * @param  array{scaffolds?: list<array<string,mixed>>}  $input
     * @return array{schema:string, plan:list<array<string,mixed>>, retire_count:int, merge_count:int, keep_count:int}
     */
    public function plan(array $input): array
    {
        $scaffolds = (array) ($input['scaffolds'] ?? []);

        $plan        = [];
        $retireCount = 0;
        $mergeCount  = 0;
        $keepCount   = 0;

        foreach ($scaffolds as $scaffold) {
            $entry = $this->classify($scaffold);
            $plan[] = $entry;

            match ($entry['action']) {
                self::ACTION_RETIRE => $retireCount++,
                self::ACTION_MERGE  => $mergeCount++,
                default             => $keepCount++,
            };
        }

        return [
            'schema'       => self::SCHEMA,
            'plan'         => $plan,
            'retire_count' => $retireCount,
            'merge_count'  => $mergeCount,
            'keep_count'   => $keepCount,
        ];
    }

    private function classify(array $scaffold): array
    {
        $id           = (string) ($scaffold['scaffold_id']              ?? 'unknown');
        $lift         = max(0.0, min(1.0, (float) ($scaffold['lift_score']                 ?? 0.0)));
        $failureRate  = max(0.0, min(1.0, (float) ($scaffold['failure_recurrence_rate']    ?? 0.0)));
        $overlap      = max(0.0, min(1.0, (float) ($scaffold['overlap_score']              ?? 0.0)));
        $recentWins   = max(0,   (int)   ($scaffold['recent_successful_outcomes']          ?? 0));
        $replacement  = isset($scaffold['replacement_candidate'])
            ? (string) $scaffold['replacement_candidate']
            : null;

        // RETIRE — quality failure
        if ($lift < self::RETIRE_LIFT_FLOOR || $failureRate > self::RETIRE_FAILURE_CEILING) {
            $reasons = [];
            if ($lift < self::RETIRE_LIFT_FLOOR) {
                $reasons[] = sprintf('lift_score:%.4f<%.2f', $lift, self::RETIRE_LIFT_FLOOR);
            }
            if ($failureRate > self::RETIRE_FAILURE_CEILING) {
                $reasons[] = sprintf('failure_recurrence_rate:%.4f>%.2f', $failureRate, self::RETIRE_FAILURE_CEILING);
            }
            return $this->entry($id, self::ACTION_RETIRE, $reasons, $replacement, null);
        }

        // RETIRE — high overlap with a replacement ready
        if ($overlap > self::OVERLAP_RETIRE_CEILING && $replacement !== null) {
            return $this->entry($id, self::ACTION_RETIRE, [
                sprintf('overlap_score:%.4f>%.2f:superseded', $overlap, self::OVERLAP_RETIRE_CEILING),
            ], $replacement, null);
        }

        // MERGE — moderate overlap with a replacement
        if ($overlap > self::OVERLAP_MERGE_THRESHOLD && $replacement !== null) {
            return $this->entry($id, self::ACTION_MERGE, [
                sprintf('overlap_score:%.4f>%.2f:merge_into_replacement', $overlap, self::OVERLAP_MERGE_THRESHOLD),
            ], $replacement, null);
        }

        // KEEP — explain why removal would reduce quality
        $keepRationale = sprintf(
            'lift_score=%.4f recent_wins=%d failure_rate=%.4f: removal would reduce origination quality',
            $lift,
            $recentWins,
            $failureRate,
        );

        return $this->entry($id, self::ACTION_KEEP, ['sufficient_lift_and_low_failure'], null, $keepRationale);
    }

    private function entry(string $id, string $action, array $reasons, ?string $replacement, ?string $keepRationale): array
    {
        return [
            'scaffold_id'          => $id,
            'action'               => $action,
            'reasons'              => $reasons,
            'replacement_candidate' => $replacement,
            'keep_rationale'       => $keepRationale,
        ];
    }
}
