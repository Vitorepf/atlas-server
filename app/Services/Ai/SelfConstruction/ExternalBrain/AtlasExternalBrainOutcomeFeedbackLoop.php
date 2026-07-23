<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure feedback loop. Turns muscle outcome reports (success, give_back, quarantine, weak_green,
 * commit) into ranking_adjustments the next originator batch can consume directly — outcomes are
 * never passive reports the external brain can ignore.
 *
 * PER-FAMILY SIGNAL PRIORITY (a single family may see multiple outcomes; priority decides the
 * final adjustment — one quarantine anywhere taints the family regardless of successes):
 *   1. quarantine            -> block  (any quarantine for the family)
 *   2. give_back | weak_green -> demote (no quarantine, but at least one demotion signal)
 *   3. success | commit with required_evidence_present + commit_sha -> promote
 *   4. otherwise             -> hold (no signal strong enough to move ranking)
 *
 * INPUT per outcome record:
 *   {task_family, outcome_type:'success'|'commit'|'give_back'|'quarantine'|'weak_green',
 *    required_evidence_present?:bool, commit_sha?:string, reason?:string}
 *
 * A success/commit record only counts as proof when BOTH required_evidence_present=true AND
 * commit_sha is non-empty — a report that merely claims success without evidence never promotes.
 *
 * OUTPUT: {schema, ranking_adjustments, promoted_families, demoted_families, blocked_families}
 * ranking_adjustments: list<{task_family, adjustment, reasons}> sorted by task_family — directly
 * consumable by the originator, no raw log parsing required.
 *
 * Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainOutcomeFeedbackLoop
{
    public const SCHEMA = 'atlas.external_brain.outcome_feedback_loop.v1';

    public const ADJUSTMENT_PROMOTE = 'promote';
    public const ADJUSTMENT_DEMOTE  = 'demote';
    public const ADJUSTMENT_BLOCK   = 'block';
    public const ADJUSTMENT_HOLD    = 'hold';

    private const SUCCESS_LIKE_TYPES = ['success', 'commit'];

    /**
     * @param  list<array<string,mixed>>  $outcomes
     * @return array{schema:string, ranking_adjustments:list<array<string,mixed>>, promoted_families:list<string>, demoted_families:list<string>, blocked_families:list<string>}
     */
    public function process(array $outcomes): array
    {
        $families = [];

        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }

            $family = trim((string) ($outcome['task_family'] ?? ''));
            if ($family === '') {
                continue;
            }
            $type   = strtolower(trim((string) ($outcome['outcome_type'] ?? '')));
            $reason = trim((string) ($outcome['reason'] ?? ''));

            $families[$family] ??= [
                'has_quarantine' => false,
                'has_demote_signal' => false,
                'has_proven_success' => false,
                'reasons' => [],
            ];

            if (in_array($type, self::SUCCESS_LIKE_TYPES, true)) {
                $evidencePresent = (bool) ($outcome['required_evidence_present'] ?? false);
                $commitSha = trim((string) ($outcome['commit_sha'] ?? ''));
                if ($evidencePresent && $commitSha !== '') {
                    $families[$family]['has_proven_success'] = true;
                    $families[$family]['reasons'][] = 'proven_'.$type.'_with_evidence:'.$commitSha;
                } else {
                    $families[$family]['reasons'][] = $type.'_without_required_evidence';
                }
            } elseif ($type === 'quarantine') {
                $families[$family]['has_quarantine'] = true;
                $families[$family]['reasons'][] = 'quarantine:'.($reason !== '' ? $reason : 'unspecified_reason');
            } elseif ($type === 'give_back' || $type === 'weak_green') {
                $families[$family]['has_demote_signal'] = true;
                $families[$family]['reasons'][] = $type.':'.($reason !== '' ? $reason : 'unspecified_reason');
            } else {
                $families[$family]['reasons'][] = 'unknown_outcome_type:'.$type;
            }
        }

        ksort($families);

        $rankingAdjustments = [];
        $promoted = [];
        $demoted = [];
        $blocked = [];

        foreach ($families as $family => $facts) {
            $reasons = array_values(array_unique($facts['reasons']));
            sort($reasons, SORT_STRING);

            $adjustment = match (true) {
                $facts['has_quarantine'] => self::ADJUSTMENT_BLOCK,
                $facts['has_demote_signal'] => self::ADJUSTMENT_DEMOTE,
                $facts['has_proven_success'] => self::ADJUSTMENT_PROMOTE,
                default => self::ADJUSTMENT_HOLD,
            };

            $rankingAdjustments[] = [
                'task_family' => $family,
                'adjustment'  => $adjustment,
                'reasons'     => $reasons,
            ];

            match ($adjustment) {
                self::ADJUSTMENT_PROMOTE => $promoted[] = $family,
                self::ADJUSTMENT_DEMOTE => $demoted[] = $family,
                self::ADJUSTMENT_BLOCK => $blocked[] = $family,
                default => null,
            };
        }

        return [
            'schema'              => self::SCHEMA,
            'ranking_adjustments' => $rankingAdjustments,
            'promoted_families'   => $promoted,
            'demoted_families'    => $demoted,
            'blocked_families'    => $blocked,
        ];
    }
}
