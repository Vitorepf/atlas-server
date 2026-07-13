<?php

namespace App\Services\Ai\Rivals\Support;

/**
 * Shared flight-recorder lifecycle contract for claim gates.
 * Adjudicator / enterprise / autopsy must agree on the same set.
 */
final class EventsLifecycleContract
{
    /** @var list<string> */
    public const UNIT_FINISHED = [
        'unit_finished',
        'native_execution_finished',
        'case_finished',
    ];

    /** @var list<string> */
    public const RUN_COMPLETE = [
        'pipeline_finished',
        'battery_finished',
        'run_state_changed',
    ];

    /**
     * Claim-grade completeness: at least one unit/native/case finished.
     * Run-complete events alone are never enough.
     */
    public static function isComplete(?string $eventsPath): bool
    {
        return self::isClaimGradeComplete($eventsPath);
    }

    public static function isClaimGradeComplete(?string $eventsPath): bool
    {
        if ($eventsPath === null || $eventsPath === '' || ! is_file($eventsPath) || filesize($eventsPath) <= 0) {
            return false;
        }
        $raw = (string) file_get_contents($eventsPath);
        if (trim($raw) === '') {
            return false;
        }
        foreach (preg_split("/\n/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $type = (string) ($event['event_type'] ?? '');
            if (in_array($type, self::UNIT_FINISHED, true)) {
                return true;
            }
        }

        return false;
    }
}
