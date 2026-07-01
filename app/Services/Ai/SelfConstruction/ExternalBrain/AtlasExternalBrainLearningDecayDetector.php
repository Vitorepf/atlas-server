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
 *     recent_violations?:      list<string>  (recent outcome/task IDs where the SAME mistake this
 *                                             lesson warns against happened again — distinct from
 *                                             contradicted_by, which means the lesson's CLAIM was
 *                                             wrong; a violation means the lesson was right but ignored)
 *     derived_from_failure?:   bool  (default false — whether this lesson originated from an actual
 *                                     negative/failed outcome rather than a stylistic preference)
 *   }>
 *   decay_threshold_seconds?:  int   (default 604800 = 7 days)
 *   overfit_max_campaigns?:    int   (default 1)
 *
 * `signal` (per lesson, orthogonal to `status`) names WHY this lesson needs attention in the AC2
 * vocabulary — computed independently of status priority so a lesson can be simultaneously
 * `fresh` (its claim still holds) yet `repeated_error` (the mistake it warns against keeps
 * happening because nobody applied it):
 *   ignored_negative_outcome <- recent_violations non-empty AND derived_from_failure == true
 *   repeated_error           <- recent_violations non-empty (otherwise)
 *   stale_lesson             <- status == decayed
 *   obsolete_policy          <- status == architecture_incompatible
 *   null                     <- none of the above
 *
 * `decay_findings` reports one entry per lesson with a non-null signal:
 *   { source_lesson, recent_violation, severity, refresh_action }
 *
 * OUTPUT:
 *   { schema, lessons, total, contradicted_count, decayed_count, overfit_count, fresh_count,
 *     decay_findings }
 *
 *   Each lesson entry: { lesson_id, status, recommendation, decay_reason, signal }
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

    public const SIGNAL_REPEATED_ERROR          = 'repeated_error';
    public const SIGNAL_STALE_LESSON            = 'stale_lesson';
    public const SIGNAL_IGNORED_NEGATIVE_OUTCOME = 'ignored_negative_outcome';
    public const SIGNAL_OBSOLETE_POLICY         = 'obsolete_policy';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_MEDIUM   = 'medium';

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
        $decayFindings              = [];

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
            $recentViolations  = array_values(array_map('strval', is_array($raw['recent_violations'] ?? null) ? $raw['recent_violations'] : []));
            $derivedFromFailure = (bool) ($raw['derived_from_failure'] ?? false);

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

            $signal = match (true) {
                $recentViolations !== [] && $derivedFromFailure => self::SIGNAL_IGNORED_NEGATIVE_OUTCOME,
                $recentViolations !== [] => self::SIGNAL_REPEATED_ERROR,
                $status === self::STATUS_DECAYED => self::SIGNAL_STALE_LESSON,
                $status === self::STATUS_ARCHITECTURE_INCOMPATIBLE => self::SIGNAL_OBSOLETE_POLICY,
                default => null,
            };

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
                'signal'                  => $signal,
                'recent_violations'       => $recentViolations,
            ];

            if ($signal !== null) {
                $decayFindings[] = [
                    'source_lesson'    => $lessonId,
                    'recent_violation' => $recentViolations[0] ?? null,
                    'severity'         => $this->severityFor($signal),
                    'refresh_action'   => $this->refreshActionFor($signal, $recentViolations, $currentArchVersion),
                ];
            }
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
            'decay_findings'                  => $decayFindings,
        ];
    }

    private function severityFor(string $signal): string
    {
        return match ($signal) {
            self::SIGNAL_IGNORED_NEGATIVE_OUTCOME => self::SEVERITY_CRITICAL,
            self::SIGNAL_REPEATED_ERROR => self::SEVERITY_HIGH,
            default => self::SEVERITY_MEDIUM,
        };
    }

    /** @param  list<string>  $recentViolations */
    private function refreshActionFor(string $signal, array $recentViolations, string $currentArchVersion): string
    {
        $violationList = implode(', ', array_slice($recentViolations, 0, 3));

        return match ($signal) {
            self::SIGNAL_IGNORED_NEGATIVE_OUTCOME => "escalate this lesson to a hard gate; a failure-derived lesson was ignored in: {$violationList}",
            self::SIGNAL_REPEATED_ERROR => "re-surface this lesson at task-authoring time; the same mistake recurred in: {$violationList}",
            self::SIGNAL_STALE_LESSON => 'revalidate against recent outcomes before continuing to rely on this lesson',
            self::SIGNAL_OBSOLETE_POLICY => "retire or rewrite this lesson for the current architecture_version".($currentArchVersion !== '' ? ":{$currentArchVersion}" : ''),
            default => 'no action required',
        };
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
