<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Balances repair, consolidation and expansion in each originator cycle from
 * current health and outcome facts.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasStrategyCouncilRepairExpansionBalancePolicy
{
    public const SCHEMA = 'atlas.self_construction.strategy_council.repair_expansion_balance_policy.v1';

    public const MODE_REPAIR = 'repair';
    public const MODE_CONSOLIDATION = 'consolidation';
    public const MODE_EXPANSION = 'expansion';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function balance(array $input): array
    {
        $health = is_array($input['health'] ?? null) ? $input['health'] : [];
        $outcomes = is_array($input['outcomes'] ?? null) ? $input['outcomes'] : [];
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');

        $healthy = (bool) ($health['healthy'] ?? false);
        $malformed = (int) ($health['malformed_count'] ?? 0);
        $leaseLeak = (bool) ($health['lease_leak_detected'] ?? false);
        $queueDry = (bool) ($health['dry_queue'] ?? false);
        $claimableDepth = (int) ($health['claimable_depth'] ?? 0);
        $activeWorkers = (int) ($health['active_workers'] ?? 0);

        $giveBackCount = (int) ($outcomes['give_back_count'] ?? 0);
        $duplicateCount = (int) ($outcomes['duplicate_count'] ?? 0);
        $successCount = (int) ($outcomes['success_count'] ?? 0);
        $totalOutcomes = max(1, $giveBackCount + $duplicateCount + $successCount);

        $giveBackRate = $giveBackCount / $totalOutcomes;
        $duplicateRate = $duplicateCount / $totalOutcomes;

        $mode = match (true) {
            ! $healthy || $malformed > 0 || $leaseLeak || $queueDry => self::MODE_REPAIR,
            $duplicateRate >= 0.20 || $giveBackRate >= 0.30 => self::MODE_CONSOLIDATION,
            default => self::MODE_EXPANSION,
        };

        $reasons = [];
        if (! $healthy) {
            $reasons[] = 'health_not_healthy';
        }
        if ($malformed > 0) {
            $reasons[] = 'malformed_present:'.$malformed;
        }
        if ($leaseLeak) {
            $reasons[] = 'lease_leak_detected';
        }
        if ($queueDry) {
            $reasons[] = 'queue_dry';
        }
        if ($duplicateRate >= 0.20) {
            $reasons[] = 'duplicate_pressure:'.round($duplicateRate, 2);
        }
        if ($giveBackRate >= 0.30) {
            $reasons[] = 'give_back_pressure:'.round($giveBackRate, 2);
        }
        if ($reasons === []) {
            $reasons[] = 'clean_drain_allows_expansion';
        }

        $workerFloorBreached = $activeWorkers > 0 && ($claimableDepth / $activeWorkers) <= 2.0;

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'mode' => $mode,
            'reasons' => $reasons,
            'repair_first' => $mode === self::MODE_REPAIR,
            'allow_expansion' => $mode === self::MODE_EXPANSION,
            'worker_floor_breached' => $workerFloorBreached,
            'health_healthy' => $healthy,
            'malformed_count' => $malformed,
            'give_back_rate' => round($giveBackRate, 3),
            'duplicate_rate' => round($duplicateRate, 3),
        ];
    }
}
