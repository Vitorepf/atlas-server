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

        foreach ($tasks as $task) {
            $task = (array) $task;
            $selfReportedStatus = strtolower(trim((string) ($task['self_reported_status'] ?? '')));
            $commitHash = trim((string) ($task['commit_hash'] ?? ''));
            $testsPassed = $task['tests_passed'] ?? null;
            $giveBackReason = trim((string) ($task['give_back_reason'] ?? ''));
            $unlocks = array_values(array_map('strval', (array) ($task['unlocks_task_ids'] ?? [])));

            $isGreenCommit = $commitHash !== '' && $testsPassed === true;
            if ($isGreenCommit) {
                $greenCommitCount++;
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

        $baseScore = $taskCount === 0
            ? 0.0
            : ($greenCommitCount + self::UNLOCK_WEIGHT * $unlockCount) / $taskCount;
        $wastePenalty = $taskCount === 0 ? 0.0 : ($wastedTaskCount / $taskCount) * self::WASTE_PENALTY_WEIGHT;
        $repeatedGiveBackPenalty = $repeatedGiveBackRootCauses !== [] ? self::REPEATED_GIVE_BACK_PENALTY : 0.0;
        $waveValueScore = max(0.0, round($baseScore - $wastePenalty - $repeatedGiveBackPenalty, 6));

        return [
            'schema_version' => self::SCHEMA,
            'task_count' => $taskCount,
            'completed_count' => $completedCount,
            'green_commit_count' => $greenCommitCount,
            'give_back_count' => $giveBackCount,
            'repair_count' => $repairCount,
            'unlock_count' => $unlockCount,
            'wasted_task_count' => $wastedTaskCount,
            'repeated_give_back_root_causes' => array_values($repeatedGiveBackRootCauses),
            'wave_value_score' => $waveValueScore,
        ];
    }
}
