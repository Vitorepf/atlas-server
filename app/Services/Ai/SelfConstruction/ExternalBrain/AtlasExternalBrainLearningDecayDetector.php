<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Detects when lessons learned from prior worker outcomes are stale, contradicted by recent
 * results, or overfit to one campaign, so future task ranking does not rely on obsolete memory.
 *
 * STATUS PRIORITY (highest wins):
 *   contradicted — recent outcomes contradict this lesson → demote
 *   decayed      — lesson is older than decay_threshold_seconds → revalidate
 *   overfit      — lesson observed in <= overfit_max_campaigns campaigns → revalidate
 *   fresh        — none of the above → keep
 *
 * NOTE: The detector NEVER deletes a lesson; it only assigns a recommendation.
 *   demote requires explicit contrary evidence (contradicted_by non-empty).
 *
 * INPUT:
 *   lessons: list<{
 *     lesson_id:               string
 *     created_at_seconds_ago:  int
 *     confidence?:             float  (0..1)
 *     campaign_ids?:           list<string>
 *     contradicted_by?:        list<string>  (recent outcome IDs; empty = not contradicted)
 *   }>
 *   decay_threshold_seconds?:  int   (default 604800 = 7 days)
 *   overfit_max_campaigns?:    int   (default 1)
 *
 * OUTPUT:
 *   { schema, lessons, total, contradicted_count, decayed_count, overfit_count, fresh_count }
 *
 *   Each lesson entry: { lesson_id, status, recommendation, decay_reason }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainLearningDecayDetector
{
    public const SCHEMA = 'atlas.external_brain.learning_decay_detector.v1';

    public const STATUS_FRESH                    = 'fresh';
    public const STATUS_DECAYED                  = 'decayed';
    public const STATUS_CONTRADICTED             = 'contradicted';
    public const STATUS_OVERFIT                  = 'overfit';
    public const STATUS_ARCHITECTURE_INCOMPATIBLE = 'architecture_incompatible';

    public const REC_KEEP       = 'keep';
    public const REC_REVALIDATE = 'revalidate';
    public const REC_DEMOTE     = 'demote';
    public const REC_QUARANTINE = 'quarantine';
    public const REC_DOWNRANK   = 'downrank';

    private const DEFAULT_DECAY_THRESHOLD_SECONDS       = 604800; // 7 days
    private const DEFAULT_OVERFIT_MAX_CAMPAIGNS          = 1;
    private const DEFAULT_MIN_CONFIRMATIONS_FOR_RELIABLE = 3;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function detect(array $input): array
    {
        $rawLessons              = is_array($input['lessons'] ?? null) ? $input['lessons'] : [];
        $decayThreshold          = max(1, (int) ($input['decay_threshold_seconds']       ?? self::DEFAULT_DECAY_THRESHOLD_SECONDS));
        $overfitMaxCampaigns     = max(1, (int) ($input['overfit_max_campaigns']         ?? self::DEFAULT_OVERFIT_MAX_CAMPAIGNS));
        $minConfirmations        = max(1, (int) ($input['min_confirmations_for_reliable'] ?? self::DEFAULT_MIN_CONFIRMATIONS_FOR_RELIABLE));
        $currentArchVersion      = trim((string) ($input['current_architecture_version'] ?? ''));

        $lessons                    = [];
        $contradictedCount          = 0;
        $decayedCount               = 0;
        $overfitCount               = 0;
        $freshCount                 = 0;
        $architectureIncompatibleCount = 0;

        foreach ($rawLessons as $raw) {
            if (! is_array($raw) || ! isset($raw['lesson_id'])) {
                continue;
            }

            $lessonId          = (string) $raw['lesson_id'];
            $ageSeconds        = max(0, (int) ($raw['created_at_seconds_ago'] ?? 0));
            $campaignIds       = is_array($raw['campaign_ids'] ?? null) ? $raw['campaign_ids'] : [];
            $contradictedBy    = is_array($raw['contradicted_by'] ?? null) ? $raw['contradicted_by'] : [];
            $lessonArchVersion = trim((string) ($raw['architecture_version'] ?? ''));
            $confirmationCount = max(0, (int) ($raw['confirmation_count'] ?? 0));

            $archIncompatible = $lessonArchVersion !== ''
                && $currentArchVersion !== ''
                && $lessonArchVersion !== $currentArchVersion;

            // Determine status (priority: contradicted > architecture_incompatible > decayed > overfit > fresh).
            if ($contradictedBy !== []) {
                $status      = self::STATUS_CONTRADICTED;
                $rec         = self::REC_DEMOTE;
                $decayReason = sprintf(
                    'contradicted_by %d outcome(s): %s',
                    count($contradictedBy),
                    implode(', ', array_slice($contradictedBy, 0, 3)),
                );
                $contradictedCount++;
            } elseif ($archIncompatible) {
                $status      = self::STATUS_ARCHITECTURE_INCOMPATIBLE;
                $rec         = self::REC_QUARANTINE;
                $decayReason = sprintf(
                    'architecture_version:%s is incompatible with current:%s',
                    $lessonArchVersion,
                    $currentArchVersion,
                );
                $architectureIncompatibleCount++;
            } elseif ($ageSeconds > $decayThreshold) {
                $status      = self::STATUS_DECAYED;
                $rec         = self::REC_REVALIDATE;
                $decayReason = sprintf('age=%ds > decay_threshold=%ds', $ageSeconds, $decayThreshold);
                $decayedCount++;
            } elseif (count($campaignIds) <= $overfitMaxCampaigns) {
                $status      = self::STATUS_OVERFIT;
                // High confirmation count softens the punishment to a downrank.
                $rec         = $confirmationCount >= $minConfirmations ? self::REC_DOWNRANK : self::REC_REVALIDATE;
                $decayReason = sprintf(
                    'observed in only %d campaign(s) (<= overfit_max=%d)',
                    count($campaignIds),
                    $overfitMaxCampaigns,
                );
                $overfitCount++;
            } else {
                $status      = self::STATUS_FRESH;
                $rec         = self::REC_KEEP;
                $decayReason = null;
                $freshCount++;
            }

            $lessons[] = [
                'lesson_id'              => $lessonId,
                'status'                 => $status,
                'recommendation'         => $rec,
                'decay_reason'           => $decayReason,
                'decay_score'            => $this->computeDecayScore(
                    $ageSeconds, $decayThreshold,
                    $confirmationCount, $minConfirmations,
                    $contradictedBy !== [],
                    $archIncompatible,
                ),
                'architecture_compatible' => ! $archIncompatible,
                'confirmation_count'      => $confirmationCount,
            ];
        }

        return [
            'schema'                         => self::SCHEMA,
            'lessons'                        => $lessons,
            'total'                          => count($lessons),
            'contradicted_count'             => $contradictedCount,
            'decayed_count'                  => $decayedCount,
            'overfit_count'                  => $overfitCount,
            'fresh_count'                    => $freshCount,
            'architecture_incompatible_count' => $architectureIncompatibleCount,
        ];
    }

    private function computeDecayScore(
        int $ageSeconds,
        int $decayThreshold,
        int $confirmationCount,
        int $minConfirmations,
        bool $contradicted,
        bool $archIncompatible,
    ): float {
        $score = 1.0;

        // Age penalty: linear up to 40% loss at the decay threshold.
        $score -= min(1.0, $ageSeconds / $decayThreshold) * 0.40;

        // Confirmation boost: up to +20%.
        $score += min(1.0, $confirmationCount / max(1, $minConfirmations)) * 0.20;

        if ($contradicted)     { $score -= 0.50; }
        if ($archIncompatible) { $score -= 0.40; }

        return round(max(0.0, min(1.0, $score)), 4);
    }
}
