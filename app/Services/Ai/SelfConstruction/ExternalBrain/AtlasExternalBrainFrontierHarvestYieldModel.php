<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Decides whether a frontier still has genuine high-leverage tasks (harvest_more),
 * needs a different harvesting method (change_method), or is exhausted (retire).
 *
 * DECISION PRIORITY:
 *   harvest_more — yield >= yield_threshold AND duplicate_rate < dup_threshold AND give_back_rate < gb_threshold
 *   change_method — NOT harvest_more AND untried_methods is non-empty → pick first untried method
 *   retire        — NOT harvest_more AND NO untried methods remain
 *
 * NOTE: A frontier is NEVER retired solely because the last wave found few tasks
 *       when untried harvest methods remain. change_method is returned in that case.
 *
 * untried_methods = available_methods \ tried_methods
 *
 * INPUT:
 *   frontiers: list<{
 *     frontier_id:       string
 *     verified_yield:    int     (tasks found and verified)
 *     duplicate_rate:    float   (0..1)
 *     give_back_rate:    float   (0..1)
 *     compounding_impact?: float (0..1, informational)
 *     tried_methods?:    list<string>
 *     available_methods?: list<string>
 *   }>
 *   yield_threshold?:           int   (default 3)
 *   duplicate_rate_threshold?:  float (default 0.50)
 *   give_back_rate_threshold?:  float (default 0.40)
 *
 * OUTPUT:
 *   { schema, results }
 *
 *   results: list<{
 *     frontier_id, decision, next_harvest_method, evidence_counts, reasons
 *   }>
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainFrontierHarvestYieldModel
{
    public const SCHEMA = 'atlas.external_brain.frontier_harvest_yield_model.v1';

    public const DECISION_HARVEST_MORE   = 'harvest_more';
    public const DECISION_CHANGE_METHOD  = 'change_method';
    public const DECISION_RETIRE         = 'retire';

    private const DEFAULT_YIELD_THRESHOLD       = 3;
    private const DEFAULT_DUPLICATE_THRESHOLD   = 0.50;
    private const DEFAULT_GIVE_BACK_THRESHOLD   = 0.40;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function model(array $input): array
    {
        $frontiers         = is_array($input['frontiers'] ?? null) ? $input['frontiers'] : [];
        $yieldThreshold    = max(1, (int) ($input['yield_threshold'] ?? self::DEFAULT_YIELD_THRESHOLD));
        $dupThreshold      = (float) ($input['duplicate_rate_threshold'] ?? self::DEFAULT_DUPLICATE_THRESHOLD);
        $giveBackThreshold = (float) ($input['give_back_rate_threshold'] ?? self::DEFAULT_GIVE_BACK_THRESHOLD);

        $results = [];

        foreach ($frontiers as $f) {
            if (! is_array($f) || ! isset($f['frontier_id'])) {
                continue;
            }

            $frontierId         = (string) $f['frontier_id'];
            $verifiedYield      = max(0, (int) ($f['verified_yield'] ?? 0));
            $duplicateRate      = (float) ($f['duplicate_rate'] ?? 0.0);
            $giveBackRate       = (float) ($f['give_back_rate'] ?? 0.0);
            $triedMethods       = is_array($f['tried_methods'] ?? null) ? array_map('strval', $f['tried_methods']) : [];
            $availableMethods   = is_array($f['available_methods'] ?? null) ? array_map('strval', $f['available_methods']) : [];

            $untriedMethods = array_values(array_diff($availableMethods, $triedMethods));
            $reasons        = [];

            $isHighYield    = $verifiedYield >= $yieldThreshold;
            $isLowDup       = $duplicateRate < $dupThreshold;
            $isLowGiveBack  = $giveBackRate < $giveBackThreshold;

            if ($isHighYield && $isLowDup && $isLowGiveBack) {
                $decision          = self::DECISION_HARVEST_MORE;
                $nextMethod        = null;
                $reasons[]         = sprintf('verified_yield=%d >= threshold=%d', $verifiedYield, $yieldThreshold);
                $reasons[]         = sprintf('duplicate_rate=%.2f < threshold=%.2f', $duplicateRate, $dupThreshold);
                $reasons[]         = sprintf('give_back_rate=%.2f < threshold=%.2f', $giveBackRate, $giveBackThreshold);
            } elseif ($untriedMethods !== []) {
                $decision   = self::DECISION_CHANGE_METHOD;
                $nextMethod = $untriedMethods[0];
                if (! $isHighYield) {
                    $reasons[] = sprintf('verified_yield=%d < threshold=%d', $verifiedYield, $yieldThreshold);
                }
                if (! $isLowDup) {
                    $reasons[] = sprintf('duplicate_rate=%.2f >= threshold=%.2f', $duplicateRate, $dupThreshold);
                }
                if (! $isLowGiveBack) {
                    $reasons[] = sprintf('give_back_rate=%.2f >= threshold=%.2f', $giveBackRate, $giveBackThreshold);
                }
                $reasons[] = sprintf('%d untried method(s) remain; switching to: %s', count($untriedMethods), $nextMethod);
            } else {
                $decision   = self::DECISION_RETIRE;
                $nextMethod = null;
                if (! $isHighYield) {
                    $reasons[] = sprintf('verified_yield=%d < threshold=%d', $verifiedYield, $yieldThreshold);
                }
                if (! $isLowDup) {
                    $reasons[] = sprintf('duplicate_rate=%.2f >= threshold=%.2f', $duplicateRate, $dupThreshold);
                }
                if (! $isLowGiveBack) {
                    $reasons[] = sprintf('give_back_rate=%.2f >= threshold=%.2f', $giveBackRate, $giveBackThreshold);
                }
                $reasons[] = 'no untried harvest methods remain';
            }

            $results[] = [
                'frontier_id'         => $frontierId,
                'decision'            => $decision,
                'next_harvest_method' => $nextMethod,
                'evidence_counts'     => [
                    'verified_yield'        => $verifiedYield,
                    'tried_methods_count'   => count($triedMethods),
                    'untried_methods_count' => count($untriedMethods),
                ],
                'reasons'             => $reasons,
            ];
        }

        return [
            'schema'  => self::SCHEMA,
            'results' => $results,
        ];
    }
}
