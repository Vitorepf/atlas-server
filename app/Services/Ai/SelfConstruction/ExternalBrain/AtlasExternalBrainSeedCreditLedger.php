<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ledger that counts valid seed credits only after enqueue success
 * plus healthy task health, malformed dry-run zero, and no new queued-target
 * collision for the emitted targets.
 *
 * Credits are granted ONLY when ALL of these pass:
 *   - enqueue_status = 'success'
 *   - post_round_health = 'healthy'
 *   - no malformed blockers
 *   - no new queued-target collision for the emitted targets
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainSeedCreditLedger
{
    public const SCHEMA = 'atlas.external_brain.seed_credit_ledger.v1';

    public const CREDIT_GRANTED = 'granted';
    public const CREDIT_DENIED = 'denied';

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function evaluate(array $record): array
    {
        $enqueueStatus = (string) ($record['enqueue_status'] ?? '');
        $postRoundHealth = (string) ($record['post_round_health'] ?? '');
        $malformedBlockers = (array) ($record['malformed_blockers'] ?? []);
        $targetCollisions = (array) ($record['target_collisions'] ?? []);
        $emittedTargets = (array) ($record['emitted_targets'] ?? []);
        $originatorId = (string) ($record['originator_id'] ?? '');
        $roundId = (string) ($record['round_id'] ?? '');

        $denialReasons = [];

        if ($enqueueStatus !== 'success') {
            $denialReasons[] = 'enqueue_not_successful:'.$enqueueStatus;
        }

        if ($postRoundHealth !== 'healthy') {
            $denialReasons[] = 'post_round_health_not_healthy:'.$postRoundHealth;
        }

        if ($malformedBlockers !== []) {
            $denialReasons[] = 'malformed_blockers_present:'.count($malformedBlockers);
        }

        // Check for new queued-target collisions on emitted targets.
        $collidingTargets = [];
        foreach ($targetCollisions as $collision) {
            $target = is_array($collision) ? (string) ($collision['target_family'] ?? '') : (string) $collision;
            if ($target !== '' && in_array($target, $emittedTargets, true)) {
                $collidingTargets[] = $target;
            }
        }
        if ($collidingTargets !== []) {
            $denialReasons[] = 'new_target_collision:'.implode(',', array_unique($collidingTargets));
        }

        $granted = $denialReasons === [];

        return [
            'schema_version' => self::SCHEMA,
            'originator_id' => $originatorId,
            'round_id' => $roundId,
            'credit_status' => $granted ? self::CREDIT_GRANTED : self::CREDIT_DENIED,
            'credits_granted' => $granted ? 1 : 0,
            'denial_reasons' => $denialReasons,
            'emitted_targets' => $emittedTargets,
            'colliding_targets' => $collidingTargets,
        ];
    }

    /**
     * Evaluate a batch of seed credit records.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    public function evaluateBatch(array $records): array
    {
        $results = [];
        $totalCredits = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
            $result = $this->evaluate($record);
            $results[] = $result;
            $totalCredits += $result['credits_granted'];
        }

        return [
            'schema_version' => self::SCHEMA,
            'results' => $results,
            'total_credits_granted' => $totalCredits,
            'total_records' => count($results),
        ];
    }
}
