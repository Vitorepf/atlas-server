<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Promotes repeated successful task outcomes into reusable originator
 * constraints without turning one-off wins into permanent rules.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasLearningTransferOutcomePatternPromoter
{
    public const SCHEMA = 'atlas.self_construction.learning_transfer_outcome_pattern_promoter.v1';

    public const PROMOTION_THRESHOLD = 3;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $patternId = (string) ($input['pattern_id'] ?? '');
        $successCount = (int) ($input['success_count'] ?? 0);
        $giveBackCount = (int) ($input['give_back_count'] ?? 0);
        $constraint = (string) ($input['constraint'] ?? '');

        $promoted = false;
        $advisory = false;
        $blocked = false;
        $reason = '';

        if ($giveBackCount > 0) {
            $blocked = true;
            $reason = 'conflicting_give_back_evidence:'.$giveBackCount;
        } elseif ($successCount >= self::PROMOTION_THRESHOLD) {
            $promoted = true;
            $reason = 'repeated_success:'.$successCount;
        } elseif ($successCount > 0) {
            $advisory = true;
            $reason = 'one_off_success_stays_advisory';
        } else {
            $reason = 'no_evidence';
        }

        return [
            'schema' => self::SCHEMA,
            'pattern_id' => $patternId,
            'promoted' => $promoted,
            'advisory' => $advisory,
            'blocked' => $blocked,
            'reason' => $reason,
            'success_count' => $successCount,
            'give_back_count' => $giveBackCount,
            'constraint' => $constraint,
            'promotion_threshold' => self::PROMOTION_THRESHOLD,
        ];
    }
}
