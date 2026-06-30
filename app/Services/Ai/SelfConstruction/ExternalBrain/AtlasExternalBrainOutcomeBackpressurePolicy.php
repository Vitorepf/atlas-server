<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure backpressure policy: evaluates a task family's outcome history and
 * recommends whether to promote, continue, respec, block, or retire it.
 *
 * DECISION PRIORITY (first match wins):
 *   1. retire   — poison_rate > retire_threshold (default 0.30)
 *   2. block    — quarantine_count > 0
 *   3. respec   — give_back_rate > respec_threshold (default 0.30)
 *   4. promote  — success_rate >= promote_threshold (default 0.75)
 *   5. continue — default (not enough signal yet)
 *
 * confidence_score = success_count / max(1, total_count)
 * safe_to_promote  = recommendation ∈ {promote, continue}
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainOutcomeBackpressurePolicy
{
    public const SCHEMA = 'atlas.external_brain.outcome_backpressure_policy.v1';

    public const RECOMMENDATION_PROMOTE  = 'promote';
    public const RECOMMENDATION_CONTINUE = 'continue';
    public const RECOMMENDATION_RESPEC   = 'respec';
    public const RECOMMENDATION_BLOCK    = 'block';
    public const RECOMMENDATION_RETIRE   = 'retire';

    public const OUTCOME_SUCCESS    = 'success';
    public const OUTCOME_GIVE_BACK  = 'give_back';
    public const OUTCOME_QUARANTINE = 'quarantine';
    public const OUTCOME_POISON     = 'poison';

    private const DEFAULT_PROMOTE_THRESHOLD = 0.75;
    private const DEFAULT_RESPEC_THRESHOLD  = 0.30;
    private const DEFAULT_RETIRE_THRESHOLD  = 0.30;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $taskFamily     = (string) ($input['task_family'] ?? 'unknown');
        $history        = is_array($input['outcome_history'] ?? null) ? $input['outcome_history'] : [];
        $promoteThresh  = (float) ($input['promote_threshold'] ?? self::DEFAULT_PROMOTE_THRESHOLD);
        $respecThresh   = (float) ($input['respec_threshold']  ?? self::DEFAULT_RESPEC_THRESHOLD);
        $retireThresh   = (float) ($input['retire_threshold']  ?? self::DEFAULT_RETIRE_THRESHOLD);

        // Tally outcomes.
        $counts = [
            self::OUTCOME_SUCCESS    => 0,
            self::OUTCOME_GIVE_BACK  => 0,
            self::OUTCOME_QUARANTINE => 0,
            self::OUTCOME_POISON     => 0,
        ];

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $outcome = (string) ($entry['outcome'] ?? '');
            if (array_key_exists($outcome, $counts)) {
                $counts[$outcome]++;
            }
        }

        $total          = max(1, array_sum($counts));
        $successRate    = $counts[self::OUTCOME_SUCCESS]    / $total;
        $giveBackRate   = $counts[self::OUTCOME_GIVE_BACK]  / $total;
        $poisonRate     = $counts[self::OUTCOME_POISON]     / $total;
        $quarantineCount = $counts[self::OUTCOME_QUARANTINE];
        $confidenceScore = round($counts[self::OUTCOME_SUCCESS] / $total, 4);

        $recommendation = match (true) {
            $poisonRate     > $retireThresh  => self::RECOMMENDATION_RETIRE,
            $quarantineCount > 0             => self::RECOMMENDATION_BLOCK,
            $giveBackRate   > $respecThresh  => self::RECOMMENDATION_RESPEC,
            $successRate   >= $promoteThresh => self::RECOMMENDATION_PROMOTE,
            default                          => self::RECOMMENDATION_CONTINUE,
        };

        $safeToPromote = in_array($recommendation, [self::RECOMMENDATION_PROMOTE, self::RECOMMENDATION_CONTINUE], true);

        return [
            'schema'          => self::SCHEMA,
            'task_family'     => $taskFamily,
            'recommendation'  => $recommendation,
            'confidence_score' => $confidenceScore,
            'safe_to_promote' => $safeToPromote,
            'outcome_summary' => $counts,
        ];
    }
}
