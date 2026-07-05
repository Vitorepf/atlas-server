<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Cortex;

/**
 * Projects queue health facts into Cortex as compact truth records for
 * originator decisions.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionCortexQueueTruthProjector
{
    public const SCHEMA = 'atlas.cortex.queue_truth_projector.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function project(array $input): array
    {
        $health = is_array($input['queue_health'] ?? null) ? $input['queue_health'] : [];
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');

        $claimable = (int) ($health['claimable_depth'] ?? 0);
        $claimed = (int) ($health['claimed_records'] ?? 0);
        $activeLeases = (int) ($health['active_leases'] ?? 0);
        $recoverable = (int) ($health['recoverable_candidates_total'] ?? 0);
        $malformed = (int) ($health['malformed_count'] ?? 0);
        $collisions = (int) ($health['collision_count'] ?? 0);
        $healthy = (bool) ($health['healthy'] ?? false);
        $leaseLeak = (bool) ($health['lease_leak_detected'] ?? false);
        $queueDry = (bool) ($health['dry_queue'] ?? false);

        $truth = [
            'claimable' => $claimable,
            'claimed' => $claimed,
            'active_leases' => $activeLeases,
            'recoverable' => $recoverable,
            'malformed' => $malformed,
            'collisions' => $collisions,
            'healthy' => $healthy,
            'lease_leak' => $leaseLeak,
            'queue_dry' => $queueDry,
        ];

        $signals = [];
        if ($malformed > 0) {
            $signals[] = 'malformed_present';
        }
        if ($leaseLeak) {
            $signals[] = 'lease_leak';
        }
        if ($queueDry) {
            $signals[] = 'queue_dry';
        }
        if ($collisions > 0) {
            $signals[] = 'collisions_present';
        }
        if ($signals === []) {
            $signals[] = 'queue_ok';
        }

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'truth' => $truth,
            'signals' => $signals,
            'compact_hash' => $this->compactHash($roundId, $truth),
        ];
    }

    /**
     * @param  array<string, mixed>  $truth
     */
    private function compactHash(string $roundId, array $truth): string
    {
        $canonical = [
            'round_id' => $roundId,
            'truth' => $truth,
        ];

        return 'qtp_'.substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 24);
    }
}
