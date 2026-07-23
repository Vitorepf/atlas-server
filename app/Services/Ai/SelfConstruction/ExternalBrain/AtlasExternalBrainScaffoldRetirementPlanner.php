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

    /** capability_risk above this ceiling means the action is NOT quality-floor-preserving. */
    private const CAPABILITY_RISK_CEILING = 0.30;

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
        $rollbackCondition = isset($scaffold['rollback_condition']) && $scaffold['rollback_condition'] !== ''
            ? (string) $scaffold['rollback_condition']
            : null;
        $requiredSections = array_values(array_map('strval', (array) ($scaffold['required_sections'] ?? [])));
        $replacementCoveredSections = array_values(array_map('strval', (array) ($scaffold['replacement_covered_sections'] ?? [])));
        // AC2/AC3: safety proofs required before a retire/merge action is greenlit.
        $behaviorParity        = (bool) ($scaffold['behavior_parity']         ?? false);
        $replacementCoverage   = (bool) ($scaffold['replacement_coverage']    ?? false);
        $rollbackPath          = (bool) ($scaffold['rollback_path']           ?? false);
        $knowledgeSyncPlan     = (bool) ($scaffold['knowledge_sync_plan']     ?? false);

        $action = null;
        $reasons = [];
        $keepRationale = null;

        // RETIRE — quality failure. A retire decision driven by low lift requires
        // lift evidence; without it we never assume deletion is safe.
        $liftRetireEligible = $hasLiftEvidence && $lift < self::RETIRE_LIFT_FLOOR;
        $failureRetireEligible = $failureRate > self::RETIRE_FAILURE_CEILING;
        if ($liftRetireEligible || $failureRetireEligible) {
            $action = self::ACTION_RETIRE;
            if ($liftRetireEligible) {
                $reasons[] = sprintf('lift_score:%.4f<%.2f', $lift, self::RETIRE_LIFT_FLOOR);
            }
            if ($failureRetireEligible) {
                $reasons[] = sprintf('failure_recurrence_rate:%.4f>%.2f', $failureRate, self::RETIRE_FAILURE_CEILING);
            }
        } elseif ($overlap > self::OVERLAP_RETIRE_CEILING && $replacement !== null) {
            // RETIRE — high overlap with a replacement ready
            $action = self::ACTION_RETIRE;
            $reasons[] = sprintf('overlap_score:%.4f>%.2f:superseded', $overlap, self::OVERLAP_RETIRE_CEILING);
        } elseif ($overlap > self::OVERLAP_MERGE_THRESHOLD && $replacement !== null) {
            // MERGE — moderate overlap with a replacement
            $action = self::ACTION_MERGE;
            $reasons[] = sprintf('overlap_score:%.4f>%.2f:merge_into_replacement', $overlap, self::OVERLAP_MERGE_THRESHOLD);
        } elseif (
            $hasLiftEvidence
            && $lift >= self::RETIRE_LIFT_FLOOR
            && $lift < self::DOWNGRADE_LIFT_CEILING
            && $maintenanceCost >= self::DOWNGRADE_MAINTENANCE_FLOOR
            && $staleUsageDays >= self::DOWNGRADE_STALE_DAYS_FLOOR
        ) {
            // DOWNGRADE — not bad enough to retire, but costly to maintain and stale.
            $action = self::ACTION_DOWNGRADE;
            $reasons[] = sprintf(
                'lift_score:%.4f maintenance_cost:%.2f>=%.2f stale_usage_days:%d>=%d',
                $lift,
                $maintenanceCost,
                self::DOWNGRADE_MAINTENANCE_FLOOR,
                $staleUsageDays,
                self::DOWNGRADE_STALE_DAYS_FLOOR,
            );
        }

        // AC: retirement/merge is blocked when required_sections were declared and the
        // replacement (or lack thereof) doesn't cover all of them — active workers must
        // never lose required guidance. Opt-in: skips entirely when required_sections
        // was never supplied, so legacy callers keep their existing behavior unchanged.
        if (in_array($action, [self::ACTION_RETIRE, self::ACTION_MERGE], true) && $requiredSections !== []) {
            $missingSections = array_values(array_diff($requiredSections, $replacementCoveredSections));
            if ($missingSections !== []) {
                $reasons = ['retirement_blocked_missing_fallback_coverage:'.implode(',', $missingSections)];
                $keepRationale = sprintf(
                    'fallback does not cover required sections [%s]: refusing retirement preserves worker guidance',
                    implode(', ', $missingSections),
                );
                $action = self::ACTION_KEEP;
            }
        }

        // AC2/AC3: block retire/merge when required safety proofs are missing —
        // retirement is only safe when behavior_parity, replacement_coverage,
        // rollback_path and knowledge_sync_plan are all present.
        if (in_array($action, [self::ACTION_RETIRE, self::ACTION_MERGE], true)) {
            $missingProofs = [];
            if (! $behaviorParity) {
                $missingProofs[] = 'behavior_parity';
            }
            if (! $replacementCoverage) {
                $missingProofs[] = 'replacement_coverage';
            }
            if (! $rollbackPath) {
                $missingProofs[] = 'rollback_path';
            }
            if (! $knowledgeSyncPlan) {
                $missingProofs[] = 'knowledge_sync_plan';
            }
            if ($missingProofs !== []) {
                $reasons = ['retirement_blocked_missing_proofs:'.implode(',', $missingProofs)];
                $keepRationale = sprintf(
                    'missing safety proofs [%s]: refusing retirement preserves scaffold capability',
                    implode(', ', $missingProofs),
                );
                $action = self::ACTION_KEEP;
            }
        }

        if ($action !== null) {
            return $this->entry($id, $action, $reasons, $replacement, $keepRationale, $maintenanceCost, $lift, $failureRate, $rollbackCondition, $requiredSections, $replacementCoveredSections, $behaviorParity, $replacementCoverage, $rollbackPath, $knowledgeSyncPlan);
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

        return $this->entry($id, self::ACTION_KEEP, [$keepReason], null, $keepRationale, $maintenanceCost, $lift, $failureRate, null, $requiredSections, $replacementCoveredSections);
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
        ?string $rollbackCondition = null,
        array $requiredSections = [],
        array $replacementCoveredSections = [],
        bool $behaviorParity = false,
        bool $replacementCoverage = false,
        bool $rollbackPath = false,
        bool $knowledgeSyncPlan = false,
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

        // quality_floor_preserved: keep is always floor-preserving by definition; a
        // destructive action only preserves the floor when the capability it could lose
        // is below the risk ceiling.
        $qualityFloorPreserved = $action === self::ACTION_KEEP || $capabilityRisk <= self::CAPABILITY_RISK_CEILING;

        // rollback_condition: only meaningful for destructive actions, and only when the
        // caller supplied one or a replacement exists to roll back to.
        $resolvedRollbackCondition = in_array($action, [self::ACTION_RETIRE, self::ACTION_MERGE], true)
            ? ($rollbackCondition ?? ($replacement !== null
                ? sprintf('restore %s if %s regresses lift or raises failure_recurrence_rate', $id, $replacement)
                : null))
            : null;

        // AC: migration_notes + worker_impact — every plan entry tells an active worker
        // exactly what changes and what (if anything) it must migrate to.
        $migrationNotes = match (true) {
            $action === self::ACTION_KEEP => ['no migration needed: scaffold retained'],
            $replacement !== null => [sprintf('workers relying on %s should migrate to %s', $id, $replacement)],
            default => [sprintf('workers relying on %s have no replacement; remove dependency on it', $id)],
        };
        if ($requiredSections !== []) {
            $migrationNotes[] = sprintf('required_sections=[%s]', implode(', ', $requiredSections));
        }

        $workerImpact = match (true) {
            $action === self::ACTION_KEEP => 'none',
            $replacement !== null && $requiredSections !== [] && array_diff($requiredSections, $replacementCoveredSections) === [] => 'redirect_to_replacement_fully_covered',
            $replacement !== null => 'redirect_to_replacement',
            default => 'requires_manual_review_no_replacement',
        };

        // AC2: retirement_ready only when all four safety proofs are present
        // and the action is retire/merge (non-destructive actions never retired).
        $retirementReady = in_array($action, [self::ACTION_RETIRE, self::ACTION_MERGE], true)
            && $behaviorParity && $replacementCoverage && $rollbackPath && $knowledgeSyncPlan;

        // AC4: preserved_capabilities — what the replacement continues to provide.
        $preservedCapabilities = [];
        if ($action === self::ACTION_KEEP) {
            $preservedCapabilities[] = $id;
        }
        if ($replacement !== null) {
            $preservedCapabilities[] = $replacement;
        }

        // AC4: rollback_steps — specific rollback instructions.
        $rollbackSteps = [];
        if ($resolvedRollbackCondition !== null) {
            $rollbackSteps[] = $resolvedRollbackCondition;
        }
        if ($replacement !== null) {
            $rollbackSteps[] = sprintf('fallback exists: %s', $replacement);
        }
        if ($rollbackSteps === []) {
            $rollbackSteps[] = 'no automated rollback; manual inspection required';
        }

        // AC4: retirement_plan — summary of the retirement action for this scaffold.
        $retirementPlan = $action !== self::ACTION_KEEP
            ? sprintf('%s: %s via %s', $id, $action, $replacement ?? 'no replacement')
            : null;

        return [
            'scaffold_id'                   => $id,
            'action'                        => $action,
            'reasons'                       => $reasons,
            'replacement_candidate'         => $replacement,
            'rollback_condition'            => $resolvedRollbackCondition,
            'keep_rationale'                => $keepRationale,
            'expected_complexity_reduction' => $expectedComplexityReduction,
            'capability_risk'               => $capabilityRisk,
            'quality_floor_preserved'       => $qualityFloorPreserved,
            'migration_notes'               => $migrationNotes,
            'worker_impact'                 => $workerImpact,
            'retirement_ready'              => $retirementReady,
            'retirement_plan'               => $retirementPlan,
            'preserved_capabilities'        => $preservedCapabilities,
            'rollback_steps'                => $rollbackSteps,
        ];
    }
}
