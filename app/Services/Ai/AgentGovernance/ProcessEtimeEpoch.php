<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * Pure, timezone-free conversion of a `ps -o etime=` ELAPSED string to a boot epoch.
 * Extracted from the deleted AtlasLoopKeepaliveCommand so SystemFleetDriver keeps working
 * after ACDE loop-command hard-delete.
 */
final class ProcessEtimeEpoch
{
    /**
     * Accepts POSIX formats `MM:SS`, `HH:MM:SS`, and `[DD-]HH:MM:SS`.
     * Returns null on malformed input (fail-safe).
     */
    public static function fromEtime(string $etime, int $now): ?int
    {
        $etime = trim($etime);
        if ($etime === '') {
            return null;
        }
        $days = 0;
        if (str_contains($etime, '-')) {
            [$d, $etime] = explode('-', $etime, 2);
            if ($d === '' || ! ctype_digit($d)) {
                return null;
            }
            $days = (int) $d;
        }
        $parts = explode(':', $etime);
        if (count($parts) < 2 || count($parts) > 3) {
            return null;
        }
        foreach ($parts as $part) {
            if ($part === '' || ! ctype_digit($part)) {
                return null;
            }
        }
        $secs = (int) array_pop($parts);
        $mins = (int) array_pop($parts);
        $hours = $parts !== [] ? (int) array_pop($parts) : 0;
        if ($secs >= 60 || $mins >= 60) {
            return null;
        }

        return $now - ($days * 86400 + $hours * 3600 + $mins * 60 + $secs);
    }
}
