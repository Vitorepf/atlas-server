<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Provenance;

/**
 * Pure ledger that records collision delta receipts for originator rounds
 * without raw prompts so future rounds can prove whether they introduced
 * new collisions.
 *
 * Receipt stores:
 *   - baseline_count: collisions before the round
 *   - post_count: collisions after the round
 *   - emitted_targets: targets emitted in this round
 *   - new_collisions: only the NEW collisions introduced by this round
 *
 * Provider-safe: never stores raw prompts or traces.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroCollisionDeltaReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.collision_delta_receipt_ledger.v1';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function record(array $input): array
    {
        $roundId = (string) ($input['round_id'] ?? '');
        $originatorId = (string) ($input['originator_id'] ?? '');
        $baselineCollisions = (array) ($input['baseline_collisions'] ?? []);
        $postCollisions = (array) ($input['post_collisions'] ?? []);
        $emittedTargets = (array) ($input['emitted_targets'] ?? []);

        $baselineSet = array_flip(array_map('strval', $baselineCollisions));
        $postSet = array_flip(array_map('strval', $postCollisions));

        // New collisions = in post but NOT in baseline.
        $newCollisions = array_keys(array_diff_key($postSet, $baselineSet));
        sort($newCollisions, SORT_STRING);

        $baselineCount = count($baselineCollisions);
        $postCount = count($postCollisions);
        $newCount = count($newCollisions);

        $delta = $postCount - $baselineCount;

        $receipt = [
            'schema_version' => self::SCHEMA,
            'round_id' => $roundId,
            'originator_id' => $originatorId,
            'baseline_count' => $baselineCount,
            'post_count' => $postCount,
            'new_collision_count' => $newCount,
            'delta' => $delta,
            'emitted_targets' => array_values(array_unique($emittedTargets)),
            'new_collisions' => $newCollisions,
            'introduced_new_collisions' => $newCount > 0,
            'provider_safe' => true,
        ];

        $receipt['receipt_hash'] = hash('sha256', (string) json_encode([
            'round_id' => $roundId,
            'baseline_count' => $baselineCount,
            'post_count' => $postCount,
            'new_collisions' => $newCollisions,
            'emitted_targets' => $receipt['emitted_targets'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $receipt;
    }
}
