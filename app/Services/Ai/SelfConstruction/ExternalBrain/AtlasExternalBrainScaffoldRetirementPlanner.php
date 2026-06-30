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

    public const ACTION_RETIRE    = 'retire';
    public const ACTION_MERGE     = 'merge';
    public const ACTION_DOWNGRADE = 'downgrade';
    public const ACTION_KEEP      = 'keep';

    private const RETIRE_LIFT_FLOOR            = 0.20;
    private const RETIRE_FAILURE_CEILING       = 0.50;
    private const OVERLAP_RETIRE_CEILING       = 0.70;
    private const OVERLAP_MERGE_THRESHOLD      = 0.40;
    private const DOWNGRADE_LIFT_CEILING       = 0.40;
    private const DOWNGRADE_MAINTENANCE_FLOOR  = 0.60;
    private const DOWNGRADE_STALE_DAYS_FLOOR   = 30;

    /**
     * @param  array{scaffolds?: list<array<string,mixed>>}  $input
     * @return array{schema:string, plan:list<array<string,mixed>>, retire_count:int, merge_count:int, keep_count:int}
     */
    public function plan(array $input): array
    {
        $scaffolds = (array) ($input['scaffolds'] ?? []);

        $plan          = [];
        $retireCount   = 0;
        $mergeCount    = 0;
        $downgradeCount = 0;
        $keepCount     = 0;

        foreach ($scaffolds as $scaffold) {
            $entry = $this->classify($scaffold);
            $plan[] = $entry;

            match ($entry['action']) {
                self::ACTION_RETIRE    => $retireCount++,
                self::ACTION_MERGE     => $mergeCount++,
                self::ACTION_DOWNGRADE => $downgradeCount++,
                default                => $keepCount++,
            };
        }

        return [
            'schema'         => self::SCHEMA,
            'plan'           => $plan,
            'retire_count'   => $retireCount,
            'merge_count'    => $mergeCount,
            'downgrade_count' => $downgradeCount,
            'keep_count'     => $keepCount,
        ];
    }

    private function classify(array $scaffold): array
    {
        $id           = (string) ($scaffold['scaffold_id']              ?? 'unknown');
        $lift         = max(0.0, min(1.0, (float) ($scaffold['lift_score']                 ?? 0.0)));
        $failureRate  = max(0.0, min(1.0, (float) ($scaffold['failure_recurrence_rate']    ?? 0.0)));
        $overlap      = max(0.0, min(1.0, (float) ($scaffold['overlap_score']              ?? 0.0)));
        $recentWins   = max(0,   (int)   ($scaffold['recent_successful_outcomes']          ?? 0));
        $maintenanceCost = max(0.0, min(1.0, (float) ($scaffold['maintenance_cost']        ?? 0.0)));
        $staleUsageDays  = max(0,   (int)   ($scaffold['stale_usage_days']                 ?? 0));
        $hasLiftEvidence = (bool) ($scaffold['has_lift_evidence']                          ?? true);
        $replacement  = isset($scaffold['replacement_candidate'])
            ? (string) $scaffold['replacement_candidate']
            : null;

        // RETIRE — quality failure. A retire decision driven by low lift requires
        // lift evidence; without it we never assume deletion is safe.
        $liftRetireEligible = $hasLiftEvidence && $lift < self::RETIRE_LIFT_FLOOR;
        $failureRetireEligible = $failureRate > self::RETIRE_FAILURE_CEILING;
        if ($liftRetireEligible || $failureRetireEligible) {
            $reasons = [];
            if ($liftRetireEligible) {
                $reasons[] = sprintf('lift_score:%.4f<%.2f', $lift, self::RETIRE_LIFT_FLOOR);
            }
            if ($failureRetireEligible) {
                $reasons[] = sprintf('failure_recurrence_rate:%.4f>%.2f', $failureRate, self::RETIRE_FAILURE_CEILING);
            }

            return $this->entry($id, self::ACTION_RETIRE, $reasons, $replacement, null, $maintenanceCost, $lift, $failureRate);
        }

        // RETIRE — high overlap with a replacement ready
        if ($overlap > self::OVERLAP_RETIRE_CEILING && $replacement !== null) {
            return $this->entry($id, self::ACTION_RETIRE, [
                sprintf('overlap_score:%.4f>%.2f:superseded', $overlap, self::OVERLAP_RETIRE_CEILING),
            ], $replacement, null, $maintenanceCost, $lift, $failureRate);
        }

        // MERGE — moderate overlap with a replacement
        if ($overlap > self::OVERLAP_MERGE_THRESHOLD && $replacement !== null) {
            return $this->entry($id, self::ACTION_MERGE, [
                sprintf('overlap_score:%.4f>%.2f:merge_into_replacement', $overlap, self::OVERLAP_MERGE_THRESHOLD),
            ], $replacement, null, $maintenanceCost, $lift, $failureRate);
        }

        // DOWNGRADE — not bad enough to retire, but costly to maintain and stale.
        if (
            $hasLiftEvidence
            && $lift >= self::RETIRE_LIFT_FLOOR
            && $lift < self::DOWNGRADE_LIFT_CEILING
            && $maintenanceCost >= self::DOWNGRADE_MAINTENANCE_FLOOR
            && $staleUsageDays >= self::DOWNGRADE_STALE_DAYS_FLOOR
        ) {
            return $this->entry($id, self::ACTION_DOWNGRADE, [
                sprintf(
                    'lift_score:%.4f maintenance_cost:%.2f>=%.2f stale_usage_days:%d>=%d',
                    $lift,
                    $maintenanceCost,
                    self::DOWNGRADE_MAINTENANCE_FLOOR,
                    $staleUsageDays,
                    self::DOWNGRADE_STALE_DAYS_FLOOR,
                ),
            ], $replacement, null, $maintenanceCost, $lift, $failureRate);
        }

        // KEEP — explain why removal would reduce quality
        $keepRationale = ! $hasLiftEvidence
            ? sprintf(
                'lift_evidence_missing recent_wins=%d failure_rate=%.4f: refusing retirement without lift evidence preserves quality',
                $recentWins,
                $failureRate,
            )
            : sprintf(
                'lift_score=%.4f recent_wins=%d failure_rate=%.4f: removal would reduce origination quality',
                $lift,
                $recentWins,
                $failureRate,
            );

        $keepReason = $hasLiftEvidence ? 'sufficient_lift_and_low_failure' : 'lift_evidence_missing_refuse_retirement';

        return $this->entry($id, self::ACTION_KEEP, [$keepReason], null, $keepRationale, $maintenanceCost, $lift, $failureRate);
    }

    private function entry(
        string $id,
        string $action,
        array $reasons,
        ?string $replacement,
        ?string $keepRationale,
        float $maintenanceCost,
        float $lift,
        float $failureRate,
    ): array {
        $complexityReductionWeight = match ($action) {
            self::ACTION_RETIRE => 1.0,
            self::ACTION_MERGE => 0.6,
            self::ACTION_DOWNGRADE => 0.3,
            default => 0.0,
        };
        $expectedComplexityReduction = round($complexityReductionWeight * $maintenanceCost, 4);

        // capability_risk: how much real capability could be lost if this action is wrong.
        // Higher when the scaffold still has working lift and low recurring failure.
        $capabilityRisk = in_array($action, [self::ACTION_RETIRE, self::ACTION_MERGE, self::ACTION_DOWNGRADE], true)
            ? round($lift * (1.0 - $failureRate), 4)
            : 0.0;

        return [
            'scaffold_id'                   => $id,
            'action'                        => $action,
            'reasons'                       => $reasons,
            'replacement_candidate'         => $replacement,
            'keep_rationale'                => $keepRationale,
            'expected_complexity_reduction' => $expectedComplexityReduction,
            'capability_risk'               => $capabilityRisk,
        ];
    }
}
