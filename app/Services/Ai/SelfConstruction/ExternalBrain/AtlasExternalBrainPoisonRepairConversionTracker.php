<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure tracker that turns blocked work (poisoned/quarantined/repeated give_back packets) from
 * dead inventory into a measurable recovery signal.
 *
 * Groups packet-failure events by root_cause into families, and reports whether repair attempts
 * are converting into successes, stalling unchanged, or dead-ending in retirement.
 *
 * Input event shape: {root_cause:string, status:'repaired_success'|'repaired_unchanged'|'retired'|'pending',
 *                      repair_attempts?:int}
 *
 * Pure — no I/O, no provider calls, no enqueue.
 */
final class AtlasExternalBrainPoisonRepairConversionTracker
{
    public const SCHEMA = 'atlas.self_construction.external_brain.poison_repair_conversion_tracker.v1';

    public const STATUS_SUCCESS = 'repaired_success';

    public const STATUS_UNCHANGED = 'repaired_unchanged';

    public const STATUS_RETIRED = 'retired';

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array{schema:string, family_metrics:array<string,array<string,mixed>>, top_repair_candidate:?string, dead_end_families:list<string>, learning_notes:list<string>}
     */
    public function track(array $events): array
    {
        $byFamily = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }
            $rootCause = trim((string) ($event['root_cause'] ?? ''));
            if ($rootCause === '') {
                continue;
            }
            $byFamily[$rootCause][] = $event;
        }

        $familyMetrics = [];
        $deadEndFamilies = [];

        foreach ($byFamily as $family => $rows) {
            $count = count($rows);
            $success = 0;
            $unchanged = 0;
            $retired = 0;
            $pending = 0;
            $repairAttempts = 0;

            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? '');
                $repairAttempts += max(0, (int) ($row['repair_attempts'] ?? 1));
                match ($status) {
                    self::STATUS_SUCCESS => $success++,
                    self::STATUS_UNCHANGED => $unchanged++,
                    self::STATUS_RETIRED => $retired++,
                    default => $pending++,
                };
            }

            $conversionRate = $count > 0 ? round($success / $count, 4) : 0.0;

            $signals = [];
            if ($unchanged > 0 && $success === 0) {
                $signals[] = 'stop_retrying_unchanged';
            }

            $repairStatus = match (true) {
                $success === $count => 'fully_converted',
                $retired === $count => 'dead_end',
                $unchanged === $count => 'stuck_unchanged',
                $success > 0 => 'partially_converted',
                default => 'in_progress',
            };

            $isDeadEnd = $retired === $count || ($unchanged === $count && $success === 0);

            $familyMetrics[$family] = [
                'count' => $count,
                'repair_status' => $repairStatus,
                'repair_attempts' => $repairAttempts,
                'success_count' => $success,
                'unchanged_count' => $unchanged,
                'retired_count' => $retired,
                'pending_count' => $pending,
                'conversion_rate' => $conversionRate,
                'signals' => $signals,
            ];

            if ($isDeadEnd) {
                $deadEndFamilies[] = $family;
            }
        }

        ksort($familyMetrics, SORT_STRING);
        sort($deadEndFamilies, SORT_STRING);

        $topCandidate = $this->topRepairCandidate($familyMetrics, $deadEndFamilies);
        $learningNotes = $this->learningNotes($familyMetrics, $deadEndFamilies);

        $retryStopSignals = [];
        foreach ($familyMetrics as $family => $metrics) {
            if (in_array('stop_retrying_unchanged', $metrics['signals'], true)) {
                $retryStopSignals[] = $family;
            }
        }

        $recoveryLeverageRank = $this->recoveryLeverageRank($familyMetrics, $deadEndFamilies);

        return [
            'schema' => self::SCHEMA,
            'family_metrics' => $familyMetrics,
            'top_repair_candidate' => $topCandidate,
            'dead_end_families' => $deadEndFamilies,
            'learning_notes' => $learningNotes,
            'retry_stop_signals' => $retryStopSignals,
            'recovery_leverage_rank' => $recoveryLeverageRank,
        ];
    }

    /**
     * Ranks non-dead-end, non-fully-converted families by recoverable leverage (packet count
     * descending, family name ascending as deterministic tiebreak) — the same ordering
     * top_repair_candidate uses, but the full ranked list instead of just the winner.
     *
     * @param  array<string,array<string,mixed>>  $familyMetrics
     * @param  list<string>  $deadEndFamilies
     * @return list<string>
     */
    private function recoveryLeverageRank(array $familyMetrics, array $deadEndFamilies): array
    {
        $candidates = [];
        foreach ($familyMetrics as $family => $metrics) {
            if (in_array($family, $deadEndFamilies, true) || $metrics['repair_status'] === 'fully_converted') {
                continue;
            }
            $candidates[] = ['family' => $family, 'count' => (int) $metrics['count']];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['family'], $b['family']));

        return array_column($candidates, 'family');
    }

    /**
     * The non-dead-end, not-yet-fully-converted family with the most packets — fixing its root
     * cause yields the largest recovery from a single respec. Ties broken alphabetically.
     *
     * @param  array<string,array<string,mixed>>  $familyMetrics
     * @param  list<string>  $deadEndFamilies
     */
    private function topRepairCandidate(array $familyMetrics, array $deadEndFamilies): ?string
    {
        $best = null;
        $bestCount = -1;

        foreach ($familyMetrics as $family => $metrics) {
            if (in_array($family, $deadEndFamilies, true)) {
                continue;
            }
            if ($metrics['repair_status'] === 'fully_converted') {
                continue;
            }
            $count = (int) $metrics['count'];
            if ($count > $bestCount || ($count === $bestCount && ($best === null || $family < $best))) {
                $bestCount = $count;
                $best = $family;
            }
        }

        return $best;
    }

    /**
     * @param  array<string,array<string,mixed>>  $familyMetrics
     * @param  list<string>  $deadEndFamilies
     * @return list<string>
     */
    private function learningNotes(array $familyMetrics, array $deadEndFamilies): array
    {
        $notes = [];
        foreach ($familyMetrics as $family => $metrics) {
            if (in_array($family, $deadEndFamilies, true)) {
                $notes[] = sprintf(
                    '%s: dead end after %d repair attempt(s) across %d packet(s) — stop respeccing, retire.',
                    $family,
                    $metrics['repair_attempts'],
                    $metrics['count'],
                );

                continue;
            }
            if ($metrics['repair_status'] === 'fully_converted') {
                $notes[] = sprintf(
                    '%s: fully converted (%d/%d repaired).',
                    $family,
                    $metrics['success_count'],
                    $metrics['count'],
                );

                continue;
            }
            if (in_array('stop_retrying_unchanged', $metrics['signals'], true)) {
                $notes[] = sprintf(
                    '%s: %d unchanged retry(ies) with zero success — respec needed before retrying.',
                    $family,
                    $metrics['unchanged_count'],
                );
            }
        }

        return $notes;
    }
}
