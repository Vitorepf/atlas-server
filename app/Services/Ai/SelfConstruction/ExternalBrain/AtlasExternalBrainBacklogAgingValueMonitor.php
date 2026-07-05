<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use Carbon\CarbonImmutable;

/**
 * Pure backlog-aging monitor. A queued task is not assumed valuable
 * forever: this scores value decay by age and priority, then routes stale
 * tasks to revalidate, consolidate, or retire so the originator stops
 * authoring more tasks on top of a backlog nobody re-checked.
 *
 * retire_candidates    — superseded by a newer task, or already implemented.
 * consolidate_candidates — multiple stale tasks sharing the same theme+target
 *                          (duplicated backlog, should merge into one).
 * revalidate_candidates — stale on its own, not superseded or duplicated;
 *                          needs a fresh look before counting as live work.
 *
 * Recent high-priority tasks decay slower and stay out of every candidate
 * list as long as they remain under the stale threshold.
 *
 * Pure: no I/O, never mutates the task queue.
 */
final class AtlasExternalBrainBacklogAgingValueMonitor
{
    public const SCHEMA = 'atlas.external_brain.backlog_aging_value_monitor.v1';

    private const STALE_THRESHOLD_DAYS = 14.0;
    private const DECAY_HORIZON_DAYS = 60.0;
    private const HIGH_PRIORITY_FLOOR = 8.0;

    public const ACTION_KEEP = 'keep';
    public const ACTION_REFRESH = 'refresh';
    public const ACTION_CONSOLIDATE = 'consolidate';
    public const ACTION_RETIRE = 'retire';
    public const ACTION_RESPEC = 'respec';
    public const ACTION_PROMOTE = 'promote';

    /** Beyond this age, stale-and-unproven backlog is retired rather than merely refreshed. */
    private const VERY_STALE_THRESHOLD_DAYS = 45.0;

    private const PRIORITY_NAME_VALUES = [
        'critical' => 10.0,
        'high' => 8.0,
        'medium' => 5.0,
        'normal' => 5.0,
        'low' => 2.0,
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function evaluate(array $facts): array
    {
        $tasks = array_values((array) ($facts['tasks'] ?? []));
        $now = $this->resolveNow((string) ($facts['now'] ?? ''));

        $rows = array_map(fn (array $task): array => $this->row((array) $task, $now), $tasks);

        // Pass 2: detect duplicate stale (theme, target) groups for consolidation.
        $staleGroupCounts = [];
        foreach ($rows as $row) {
            if (! $row['is_stale'] || $row['retire_reason'] !== null) {
                continue;
            }
            $key = json_encode([$row['theme'], $row['target']], JSON_THROW_ON_ERROR);
            $staleGroupCounts[$key] = ($staleGroupCounts[$key] ?? 0) + 1;
        }

        $retireCandidates = [];
        $consolidateCandidates = [];
        $revalidateCandidates = [];
        $staleButProven = [];
        $staleProxyRisk = [];
        $staleNeedsResearch = [];
        $staleCount = 0;
        $usefulDepth = 0;

        foreach ($rows as $row) {
            if ($row['is_stale']) {
                $staleCount++;

                // Categorize stale tasks
                $isProxy = (bool) ($row['is_proxy'] ?? false);
                $hasProof = (bool) ($row['has_value_proof'] ?? false);

                if ($hasProof) {
                    $staleButProven[] = ['task_id' => $row['task_id'], 'reason' => 'stale_but_proven'];
                } elseif ($isProxy) {
                    $staleProxyRisk[] = ['task_id' => $row['task_id'], 'reason' => 'stale_proxy_risk'];
                } else {
                    $staleNeedsResearch[] = ['task_id' => $row['task_id'], 'reason' => 'stale_needs_research'];
                }
            } else {
                $usefulDepth++;
            }
            if ($row['retire_reason'] !== null || $row['aging_action'] === self::ACTION_RETIRE) {
                $retireCandidates[] = ['task_id' => $row['task_id'], 'reason' => $row['retire_reason'] ?? $row['aging_reason']];

                continue;
            }
            if (! $row['is_stale']) {
                continue;
            }

            $groupKey = json_encode([$row['theme'], $row['target']], JSON_THROW_ON_ERROR);
            if (($staleGroupCounts[$groupKey] ?? 0) > 1) {
                $consolidateCandidates[] = [
                    'task_id' => $row['task_id'],
                    'reason' => 'duplicate_stale_same_theme_target',
                    'group_key' => $groupKey,
                ];

                continue;
            }

            $revalidateCandidates[] = ['task_id' => $row['task_id'], 'reason' => 'stale_needs_revalidation'];
        }

        $taskCount = count($rows);
        $valueDecayRisk = $taskCount === 0
            ? 0.0
            : round(array_sum(array_column($rows, 'value_decay_risk')) / $taskCount, 6);

        // ranked_tasks: value_score DESC (proven old work outranks unproven younger work),
        // tie-broken by age ASC so equally-valued tasks keep a stable, deterministic order.
        $rankedRows = $rows;
        usort($rankedRows, static fn (array $a, array $b): int =>
            $b['value_score'] <=> $a['value_score'] ?: $a['age_days'] <=> $b['age_days']);
        $rankedTasks = array_column($rankedRows, 'task_id');

        return [
            'schema_version' => self::SCHEMA,
            'task_count' => $taskCount,
            'stale_count' => $staleCount,
            'value_decay_risk' => $valueDecayRisk,
            'task_rows' => array_map(
                static fn (array $row): array => [
                    'task_id' => $row['task_id'],
                    'age_days' => $row['age_days'],
                    'value_decay_risk' => $row['value_decay_risk'],
                    'is_high_priority' => $row['is_high_priority'],
                    'is_stale' => $row['is_stale'],
                    'aging_action' => $row['aging_action'],
                    'evidence_needed' => $row['evidence_needed'],
                    'reason' => $row['aging_reason'],
                    'value_score' => $row['value_score'],
                    'leverage_score' => $row['leverage_score'],
                    'evidence_strength' => $row['evidence_strength'],
                    'give_back_risk' => $row['give_back_risk'],
                    'implementation_risk' => $row['implementation_risk'],
                ],
                $rows,
            ),
            'ranked_tasks' => $rankedTasks,
            'revalidate_candidates' => $revalidateCandidates,
            'consolidate_candidates' => $consolidateCandidates,
            'retire_candidates' => $retireCandidates,
            'useful_depth_after_decay' => $usefulDepth,
            'stale_value_actions' => [
                'stale_but_proven' => $staleButProven,
                'stale_proxy_risk' => $staleProxyRisk,
                'stale_needs_research' => $staleNeedsResearch,
            ],
            'mutates_queue' => false,
        ];
    }

    /** @param array<string,mixed> $task */
    private function row(array $task, CarbonImmutable $now): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $theme = trim((string) ($task['theme'] ?? ''));
        $target = trim((string) ($task['target'] ?? ''));
        $implementationStatus = strtolower(trim((string) ($task['implementation_status'] ?? '')));
        $dependencyStatus = strtolower(trim((string) ($task['dependency_status'] ?? '')));
        $supersededBy = trim((string) ($task['superseded_by'] ?? ''));
        $priorityValue = $this->priorityValue($task['priority'] ?? null);
        $isHighPriority = $priorityValue >= self::HIGH_PRIORITY_FLOOR;

        $enqueuedAt = $this->parseDate((string) ($task['enqueued_at'] ?? ''));
        $ageDays = $enqueuedAt === null ? 0.0 : max(0.0, $enqueuedAt->diffInHours($now) / 24.0);
        $isStale = $ageDays >= self::STALE_THRESHOLD_DAYS;
        $isVeryStale = $ageDays >= self::VERY_STALE_THRESHOLD_DAYS;

        $decaySlowdown = $isHighPriority ? 0.5 : 1.0;
        $valueDecayRisk = round(min(1.0, ($ageDays / self::DECAY_HORIZON_DAYS) * $decaySlowdown), 6);

        $downstreamUnlockCount = max(0, (int) ($task['downstream_unlock_count'] ?? 0));
        $valueProofFresh = (bool) ($task['value_proof_fresh'] ?? false);
        $acceptanceEvidenceStale = (bool) ($task['acceptance_evidence_stale'] ?? false);
        $hasValueProof = (bool) ($task['has_value_proof'] ?? false) || ($downstreamUnlockCount > 0 && $valueProofFresh);

        // AC2/AC3: value-aware classification inputs.
        $leverageScore = max(0.0, min(1.0, (float) ($task['leverage_score'] ?? 0.0)));
        $evidenceStrength = max(0.0, min(1.0, (float) ($task['evidence_strength'] ?? ($hasValueProof ? 0.8 : 0.0))));
        $giveBackRisk = max(0.0, min(1.0, (float) ($task['give_back_risk'] ?? 0.0)));
        $implementationRisk = max(0.0, min(1.0, (float) ($task['implementation_risk'] ?? 0.0)));

        $retireReason = match (true) {
            $supersededBy !== '' => 'superseded_by_newer_task',
            $implementationStatus === 'done' => 'already_implemented',
            default => null,
        };

        // aging_action/evidence_needed/reason: never silently keep a stale, unproven task —
        // downstream-unlock-with-fresh-proof always wins, stale-acceptance-with-no-proof is
        // routed to refresh (or retire once very old), otherwise stale defers to the existing
        // duplicate/revalidate routing below.
        //
        // AC2/AC3: value-aware classification — age alone never determines the action.
        // High-age + low-evidence + low-leverage → retire or respec.
        // Old but high-leverage unblocker → promote rather than discarded.
        [$agingAction, $evidenceNeeded, $agingReason] = match (true) {
            $retireReason !== null => [self::ACTION_RETIRE, [], $retireReason],
            ! $isStale => [self::ACTION_KEEP, [], 'fresh_or_not_yet_stale'],
            // High-leverage unblocker: promote even if old — it unblocks other work.
            $leverageScore >= 0.7 && $evidenceStrength >= 0.5 => [self::ACTION_PROMOTE, [], 'high_leverage_unblocker'],
            // Stale + low evidence + low leverage + high give_back_risk → retire.
            $isVeryStale && $evidenceStrength < 0.3 && $leverageScore < 0.3 && $giveBackRisk >= 0.5 => [
                self::ACTION_RETIRE, ['fresh_value_proof', 'leverage_evidence'], 'very_stale_low_evidence_low_leverage_high_giveback_risk',
            ],
            // Very stale + low evidence + low leverage → retire.
            $isVeryStale && $evidenceStrength < 0.3 && $leverageScore < 0.3 => [
                self::ACTION_RETIRE, ['fresh_value_proof', 'downstream_unlock_evidence'], 'very_stale_low_evidence_low_leverage',
            ],
            // Stale + low evidence + low leverage + high implementation risk → respec (rethink the approach).
            $evidenceStrength < 0.3 && $leverageScore < 0.3 && $implementationRisk >= 0.7 => [
                self::ACTION_RESPEC, ['revised_spec', 'feasibility_evidence'], 'stale_low_evidence_low_leverage_high_impl_risk',
            ],
            $hasValueProof => [self::ACTION_KEEP, [], 'downstream_unlock_with_fresh_value_proof'],
            $acceptanceEvidenceStale && ! $hasValueProof && $isVeryStale => [
                self::ACTION_RETIRE, ['fresh_value_proof', 'downstream_unlock_evidence'], 'stale_acceptance_evidence_no_value_proof_very_old',
            ],
            $acceptanceEvidenceStale && ! $hasValueProof => [
                self::ACTION_REFRESH, ['fresh_acceptance_evidence', 'value_proof'], 'stale_acceptance_evidence_no_value_proof',
            ],
            default => [self::ACTION_REFRESH, ['revalidation'], 'stale_needs_revalidation'],
        };

        // value_score: ranks proven, unlock-carrying old tasks ahead of younger tasks with no
        // proven downstream value — age alone must never determine ranking.
        $valueScore = round(
            $downstreamUnlockCount * 10.0 + ($valueProofFresh ? 5.0 : 0.0) - $valueDecayRisk * 10.0,
            4,
        );

        return [
            'task_id' => $taskId,
            'theme' => $theme,
            'target' => $target,
            'dependency_status' => $dependencyStatus,
            'priority_value' => $priorityValue,
            'is_high_priority' => $isHighPriority,
            'age_days' => round($ageDays, 4),
            'is_stale' => $isStale,
            'value_decay_risk' => $valueDecayRisk,
            'retire_reason' => $retireReason,
            'downstream_unlock_count' => $downstreamUnlockCount,
            'value_proof_fresh' => $valueProofFresh,
            'is_proxy' => (bool) ($task['is_proxy'] ?? false),
            'has_value_proof' => $hasValueProof,
            'leverage_score' => $leverageScore,
            'evidence_strength' => $evidenceStrength,
            'give_back_risk' => $giveBackRisk,
            'implementation_risk' => $implementationRisk,
            'aging_action' => $agingAction,
            'evidence_needed' => $evidenceNeeded,
            'aging_reason' => $agingReason,
            'value_score' => $valueScore,
        ];
    }

    private function priorityValue(mixed $priority): float
    {
        if (is_numeric($priority)) {
            return (float) $priority;
        }

        $name = strtolower(trim((string) $priority));

        return self::PRIORITY_NAME_VALUES[$name] ?? 5.0;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveNow(string $value): CarbonImmutable
    {
        $parsed = $value === '' ? null : $this->parseDate($value);

        return $parsed ?? CarbonImmutable::now();
    }
}
