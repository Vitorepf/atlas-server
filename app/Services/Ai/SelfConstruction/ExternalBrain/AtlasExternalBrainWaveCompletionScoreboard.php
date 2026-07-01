<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure scoreboard for one execution wave. A muscle's own "completed" claim
 * is NEVER trusted alone — green_commit_count only counts tasks with a real
 * commit hash AND passing tests; completed_count is kept as the raw
 * self-reported figure so the gap between claim and proof is visible.
 *
 * wave_value_score rewards verified green commits and downstream unlocks,
 * and is downgraded by wasted (claimed-but-unverified) tasks and by
 * give_back root causes that repeat within the wave — a repeating cause
 * means the same broken thing keeps eating cycles.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainWaveCompletionScoreboard
{
    public const SCHEMA = 'atlas.external_brain.wave_completion_scoreboard.v1';

    private const REPEATED_GIVE_BACK_THRESHOLD = 2;
    private const WASTE_PENALTY_WEIGHT = 0.5;
    private const REPEATED_GIVE_BACK_PENALTY = 0.2;
    private const UNLOCK_WEIGHT = 0.5;
    private const CAPABILITY_DELTA_WEIGHT = 0.5;
    private const PROOF_STRENGTH_WEIGHT = 0.5;
    private const GIVE_BACK_RATE_PENALTY_WEIGHT = 0.3;
    private const SIMPLIFICATION_WEIGHT = 0.1;

    private const GIVE_BACK_RATE_GAP_THRESHOLD    = 0.20;
    private const PROOF_STRENGTH_GAP_THRESHOLD    = 0.60;
    private const CAPABILITY_DELTA_GAP_THRESHOLD  = 0.30;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function score(array $facts): array
    {
        $tasks = array_values((array) ($facts['tasks'] ?? []));
        $taskCount = count($tasks);

        $completedCount = 0;
        $greenCommitCount = 0;
        $giveBackCount = 0;
        $repairCount = 0;
        $wastedTaskCount = 0;
        $unlockedTaskIds = [];
        $giveBackReasonCounts = [];
        $capabilityDeltaSum = 0.0;
        $proofStrengthSum = 0.0;
        $simplificationImpactTotal = 0.0;

        foreach ($tasks as $task) {
            $task = (array) $task;
            $selfReportedStatus = strtolower(trim((string) ($task['self_reported_status'] ?? '')));
            $commitHash = trim((string) ($task['commit_hash'] ?? ''));
            $testsPassed = $task['tests_passed'] ?? null;
            $giveBackReason = trim((string) ($task['give_back_reason'] ?? ''));
            $unlocks = array_values(array_map('strval', (array) ($task['unlocks_task_ids'] ?? [])));
            $capabilityDelta = max(0.0, min(1.0, (float) ($task['capability_delta'] ?? 0.0)));
            $proofStrength = max(0.0, min(1.0, (float) ($task['proof_strength'] ?? 0.0)));
            $simplificationImpact = (float) ($task['simplification_impact'] ?? 0.0);

            $isGreenCommit = $commitHash !== '' && $testsPassed === true;
            if ($isGreenCommit) {
                $greenCommitCount++;
                // AC3: capability delta and proof strength are only credited from verified
                // (green-commit) tasks — an unproven claim earns no capability credit.
                $capabilityDeltaSum += $capabilityDelta;
                $proofStrengthSum += $proofStrength;
                $simplificationImpactTotal += $simplificationImpact;
            }

            if ($selfReportedStatus === 'completed') {
                $completedCount++;
                if (! $isGreenCommit) {
                    $wastedTaskCount++;
                }
            }
            if ($selfReportedStatus === 'give_back') {
                $giveBackCount++;
                if ($giveBackReason !== '') {
                    $giveBackReasonCounts[$giveBackReason] = ($giveBackReasonCounts[$giveBackReason] ?? 0) + 1;
                }
            }
            if ($selfReportedStatus === 'repair') {
                $repairCount++;
            }

            foreach ($unlocks as $unlockedId) {
                if ($unlockedId !== '') {
                    $unlockedTaskIds[$unlockedId] = true;
                }
            }
        }

        $unlockCount = count($unlockedTaskIds);
        $repeatedGiveBackRootCauses = array_keys(array_filter(
            $giveBackReasonCounts,
            static fn (int $count): bool => $count >= self::REPEATED_GIVE_BACK_THRESHOLD,
        ));

        $capabilityDeltaAvg = $greenCommitCount > 0 ? round($capabilityDeltaSum / $greenCommitCount, 6) : 0.0;
        $proofStrengthAvg   = $greenCommitCount > 0 ? round($proofStrengthSum / $greenCommitCount, 6) : 0.0;
        $giveBackRate        = $taskCount === 0 ? 0.0 : round($giveBackCount / $taskCount, 6);

        $baseScore = $taskCount === 0
            ? 0.0
            : ($greenCommitCount + self::UNLOCK_WEIGHT * $unlockCount) / $taskCount;
        // AC3: capability delta and proof strength scale the base score up — a wave that proves
        // real capability gain with strong evidence is worth more than the same green-commit
        // count with no capability signal at all.
        $qualityMultiplier = 1.0
            + self::CAPABILITY_DELTA_WEIGHT * $capabilityDeltaAvg
            + self::PROOF_STRENGTH_WEIGHT * $proofStrengthAvg;
        $wastePenalty = $taskCount === 0 ? 0.0 : ($wastedTaskCount / $taskCount) * self::WASTE_PENALTY_WEIGHT;
        $repeatedGiveBackPenalty = $repeatedGiveBackRootCauses !== [] ? self::REPEATED_GIVE_BACK_PENALTY : 0.0;
        $giveBackRatePenalty = $giveBackRate * self::GIVE_BACK_RATE_PENALTY_WEIGHT;
        $simplificationBonus = $taskCount === 0 ? 0.0 : ($simplificationImpactTotal / $taskCount) * self::SIMPLIFICATION_WEIGHT;
        $waveValueScore = max(0.0, round(
            ($baseScore * $qualityMultiplier) + $simplificationBonus - $wastePenalty - $repeatedGiveBackPenalty - $giveBackRatePenalty,
            6,
        ));

        [$nextGap, $nextWaveHint] = $this->diagnoseNextGap(
            $repeatedGiveBackRootCauses,
            $giveBackRate,
            $greenCommitCount,
            $proofStrengthAvg,
            $capabilityDeltaAvg,
        );

        return [
            'schema_version' => self::SCHEMA,
            'task_count' => $taskCount,
            'completed_count' => $completedCount,
            'green_commit_count' => $greenCommitCount,
            'give_back_count' => $giveBackCount,
            'give_back_rate' => $giveBackRate,
            'repair_count' => $repairCount,
            'unlock_count' => $unlockCount,
            'wasted_task_count' => $wastedTaskCount,
            'repeated_give_back_root_causes' => array_values($repeatedGiveBackRootCauses),
            'capability_delta_avg' => $capabilityDeltaAvg,
            'proof_strength_avg' => $proofStrengthAvg,
            'simplification_impact_total' => round($simplificationImpactTotal, 6),
            'wave_value_score' => $waveValueScore,
            'next_gap' => $nextGap,
            'next_wave_hint' => $nextWaveHint,
        ];
    }

    /**
     * AC4: names the single biggest thing currently limiting this wave's value, so the
     * originator has a concrete target for the next wave instead of a raw score alone.
     * Priority order: a real recurring root cause outranks a generic rate, which outranks
     * missing proof, which outranks low capability signal.
     *
     * @param  list<string>  $repeatedGiveBackRootCauses
     * @return array{0:string,1:string}
     */
    private function diagnoseNextGap(
        array $repeatedGiveBackRootCauses,
        float $giveBackRate,
        int $greenCommitCount,
        float $proofStrengthAvg,
        float $capabilityDeltaAvg,
    ): array {
        if ($repeatedGiveBackRootCauses !== []) {
            return ['repeated_give_back_root_causes', 'fix_the_repeating_root_cause_before_originating_more_in_this_family:'.implode(',', $repeatedGiveBackRootCauses)];
        }
        if ($giveBackRate > self::GIVE_BACK_RATE_GAP_THRESHOLD) {
            return ['give_back_rate', 'reduce_give_back_rate_by_improving_packet_quality_before_next_wave'];
        }
        if ($greenCommitCount === 0) {
            return ['no_verified_completions', 'require_a_real_commit_hash_and_passing_tests_before_marking_any_task_completed'];
        }
        if ($proofStrengthAvg < self::PROOF_STRENGTH_GAP_THRESHOLD) {
            return ['proof_strength', 'strengthen_evidence_quality_in_next_wave_tasks'];
        }
        if ($capabilityDeltaAvg < self::CAPABILITY_DELTA_GAP_THRESHOLD) {
            return ['capability_delta', 'target_higher_leverage_capability_gaps_in_next_wave'];
        }

        return ['none', 'wave_is_healthy_continue_current_strategy'];
    }
}
