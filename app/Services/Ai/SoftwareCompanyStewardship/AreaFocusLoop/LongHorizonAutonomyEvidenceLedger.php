<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reports L5/L6 wall-clock autonomy evidence from observation events.
 *
 * L5 (Department Owner) requires 90 wall-clock days of one department running
 * autonomously with zero human interventions outside gestures. L6
 * (Multi-Department Conductor) requires 30 wall-clock days with 3+ departments
 * active simultaneously.
 *
 * Wall-clock days are real elapsed time only. Dry-run and test_mode observations
 * never increment the day counter (no fake months); they are excluded entirely
 * from day, department and intervention accumulation because they are not real
 * evidence.
 *
 * Pure and deterministic: every returned field is computed from the events
 * array. Date arithmetic is performed against an observation anchor string
 * supplied by the caller; no wall clock, randomness or I/O is consulted.
 */
final class LongHorizonAutonomyEvidenceLedger
{
    private const SCHEMA_VERSION = 'atlas.autonomy.long_horizon_evidence_ledger.v1';

    private const L5_REQUIRED_DAYS = 90;

    private const L5_REQUIRED_DEPARTMENTS = 1;

    private const L6_REQUIRED_DAYS = 30;

    private const L6_REQUIRED_DEPARTMENTS = 3;

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array{
     *     schema_version: string,
     *     wall_clock_days_observed: int,
     *     dept_count_active: int,
     *     intervention_count: int,
     *     earliest_possible_at: string|null,
     *     real_event_count: int,
     *     excluded_event_count: int,
     *     l5_satisfied: bool,
     *     l6_satisfied: bool,
     *     blockers: list<string>
     * }
     */
    public function report(array $events): array
    {
        $wallClockDaysObserved = 0;
        $interventionCount = 0;
        $realEventCount = 0;
        $excludedEventCount = 0;
        $departments = [];
        $maxActiveDepartments = 0;
        $anchor = null;

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if ($this->isExcluded($event)) {
                $excludedEventCount++;

                continue;
            }

            $realEventCount++;
            // Saturating addition keeps both counters typed as int. A raw int+int
            // sum that crosses PHP_INT_MAX silently promotes to float, which would
            // both break the int output contract and throw a TypeError when the
            // float is passed to the int-typed blockers() helper.
            $wallClockDaysObserved = $this->addSaturating($wallClockDaysObserved, $this->daysOf($event));
            $interventionCount = $this->addSaturating($interventionCount, $this->interventionsOf($event));

            $eventDepartments = $this->departmentsOf($event);
            foreach ($eventDepartments as $department) {
                $departments[$department] = true;
            }

            $activeInEvent = count($eventDepartments);
            if ($activeInEvent > $maxActiveDepartments) {
                $maxActiveDepartments = $activeInEvent;
            }

            $anchor = $this->earlierAnchor($anchor, $event);
        }

        $deptCountActive = count($departments);

        $l5Satisfied = $wallClockDaysObserved >= self::L5_REQUIRED_DAYS
            && $deptCountActive >= self::L5_REQUIRED_DEPARTMENTS
            && $interventionCount === 0;

        $l6Satisfied = $wallClockDaysObserved >= self::L6_REQUIRED_DAYS
            && $maxActiveDepartments >= self::L6_REQUIRED_DEPARTMENTS;

        $blockers = $this->blockers(
            $wallClockDaysObserved,
            $deptCountActive,
            $maxActiveDepartments,
            $interventionCount,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'wall_clock_days_observed' => $wallClockDaysObserved,
            'dept_count_active' => $deptCountActive,
            'intervention_count' => $interventionCount,
            'earliest_possible_at' => $this->earliestPossibleAt($anchor, $wallClockDaysObserved),
            'real_event_count' => $realEventCount,
            'excluded_event_count' => $excludedEventCount,
            'l5_satisfied' => $l5Satisfied,
            'l6_satisfied' => $l6Satisfied,
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isExcluded(array $event): bool
    {
        return ($event['dry_run'] ?? false) === true
            || ($event['test_mode'] ?? false) === true;
    }

    /**
     * Adds two non-negative day/intervention counts without ever overflowing
     * into a float. The result stays a clean int, capped at PHP_INT_MAX.
     */
    private function addSaturating(int $current, int $increment): int
    {
        if ($increment > 0 && $current > PHP_INT_MAX - $increment) {
            return PHP_INT_MAX;
        }

        return $current + $increment;
    }

    /**
     * Coerces a day/intervention scalar to a clean int without ever wrapping.
     *
     * A bare (int) cast of an out-of-range float wraps to a meaningless value:
     * a huge positive float can land on a small or negative int, and a negative
     * float can flip to a large positive one — defeating the max(0, ...) floor
     * and manufacturing fake elapsed time (or hiding real interventions). Floats
     * are therefore clamped to the int range before casting, and non-finite
     * floats are mapped to their honest saturated bound. Numeric strings keep
     * PHP's own saturating string-to-int conversion.
     */
    private function intOf(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value)) {
                return 0;
            }

            if ($value >= (float) PHP_INT_MAX) {
                return PHP_INT_MAX;
            }

            if ($value <= (float) PHP_INT_MIN) {
                return PHP_INT_MIN;
            }

            return (int) $value;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function daysOf(array $event): int
    {
        return max(0, $this->intOf($event['days'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function interventionsOf(array $event): int
    {
        if (array_key_exists('interventions', $event)) {
            return max(0, $this->intOf($event['interventions']));
        }

        return ($event['intervention'] ?? false) === true ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function departmentsOf(array $event): array
    {
        $raw = $event['departments'] ?? null;

        if (is_array($raw)) {
            $names = [];
            foreach ($raw as $department) {
                if (is_string($department) && $department !== '') {
                    $names[$department] = true;
                }
            }

            return array_keys($names);
        }

        $single = $event['department'] ?? null;
        if (is_string($single) && $single !== '') {
            return [$single];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function earlierAnchor(?DateTimeImmutable $current, array $event): ?DateTimeImmutable
    {
        $observedFrom = $event['observed_from'] ?? null;
        if (! is_string($observedFrom) || $observedFrom === '') {
            return $current;
        }

        $candidate = DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $observedFrom,
            new DateTimeZone('UTC'),
        );

        if ($candidate === false) {
            return $current;
        }

        $candidate = $candidate->setTime(0, 0, 0);

        if ($current === null || $candidate < $current) {
            return $candidate;
        }

        return $current;
    }

    private function earliestPossibleAt(?DateTimeImmutable $anchor, int $wallClockDaysObserved): ?string
    {
        if ($anchor === null) {
            return null;
        }

        $remainingDays = self::L5_REQUIRED_DAYS - $wallClockDaysObserved;
        if ($remainingDays < 0) {
            $remainingDays = 0;
        }

        return $anchor
            ->modify(sprintf('+%d days', $remainingDays))
            ->format('Y-m-d');
    }

    /**
     * @return list<string>
     */
    private function blockers(
        int $wallClockDaysObserved,
        int $deptCountActive,
        int $maxActiveDepartments,
        int $interventionCount,
    ): array {
        $blockers = [];

        if ($wallClockDaysObserved < self::L5_REQUIRED_DAYS) {
            $blockers[] = 'l5_wall_clock_days_below_threshold';
        }

        if ($deptCountActive < self::L5_REQUIRED_DEPARTMENTS) {
            $blockers[] = 'l5_no_autonomous_department';
        }

        if ($interventionCount > 0) {
            $blockers[] = 'l5_human_intervention_present';
        }

        if ($maxActiveDepartments < self::L6_REQUIRED_DEPARTMENTS) {
            $blockers[] = 'l6_insufficient_active_departments';
        }

        return $blockers;
    }
}
