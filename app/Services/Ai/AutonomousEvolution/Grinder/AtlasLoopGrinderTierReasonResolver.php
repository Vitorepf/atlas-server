<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Grinder;

/**
 * Result-reasoning helpers for the Atlas loop task grinder.
 *
 * Extracted from AtlasLoopTaskGrinder to reduce the god-class. Pure static
 * methods; no instance state.
 */
final class AtlasLoopGrinderTierReasonResolver
{
    /**
     * @param  array<string,mixed>  $result
     */
    public static function resultHasCertifiedWinner(array $result): bool
    {
        return is_array($result['proposals'] ?? null) && $result['proposals'] !== [];
    }

    /**
     * ACDE Tier-1 #5: a STABLE reason string for the conductor's attempt
     * ledger — 'certified' on a winner, else the first non-empty rejection
     * across explorations (kept stable so a recurring identical failure
     * trips the ledger's thrashing jump), else 'no_winner'.
     *
     * @param  array<string,mixed>  $result
     */
    public static function tierReason(array $result): string
    {
        if (self::resultHasCertifiedWinner($result)) {
            return 'certified';
        }
        foreach ((array) ($result['explorations'] ?? []) as $exploration) {
            foreach ((array) (is_array($exploration) ? ($exploration['rejected_reasons'] ?? []) : []) as $reason) {
                $reason = trim((string) $reason);
                if ($reason !== '') {
                    return $reason;
                }
            }
        }
        // ACDE Tier-1 #1: surface the cert's own reason when conductor escalation is on.
        if ((bool) config('atlas.loop.conductor_escalation_enabled', false)) {
            foreach (self::certificationRejectionReasons($result) as $reason) {
                if ($reason !== '') {
                    return $reason;
                }
            }
        }

        return 'no_winner';
    }

    /**
     * ACDE Tier-1 #1: harvest the rejection reasons the SEMANTIC implementation
     * certifier recorded for the no-winner round. Each non-certified report
     * contributes a stable 'cert:<level>: <first-reason>' line. Pure +
     * deterministic; ADVISORY — never gates, only enriches the conductor's
     * forward guidance.
     *
     * @param  array<string,mixed>  $result
     * @return list<string>
     */
    public static function certificationRejectionReasons(array $result): array
    {
        $cert = $result['semantic_implementation_certification'] ?? null;
        $reports = is_array($cert) && is_array($cert['reports'] ?? null) ? $cert['reports'] : [];
        $out = [];
        foreach ($reports as $report) {
            if (! is_array($report) || (bool) ($report['certified'] ?? false)) {
                continue;
            }
            $level = trim((string) ($report['level'] ?? ''));
            $first = '';
            foreach ((array) ($report['reasons'] ?? []) as $reason) {
                $first = trim((string) $reason);
                if ($first !== '') {
                    break;
                }
            }
            $line = $first !== '' && $level !== '' ? $level.': '.$first : ($first !== '' ? $first : $level);
            $line = trim($line);
            if ($line !== '') {
                $out[] = 'cert:'.$line;
            }
        }

        return $out;
    }
}