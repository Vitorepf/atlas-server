<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure ROI ranker for deletion-first simplification candidates. Ranks candidates by a numeric
 * net_roi that rewards line removal with preserved capability and low risk, and REFUSES any
 * candidate that lacks a replacement owner or test coverage — no static recommendation, every
 * decision is computed from the candidate's own facts.
 *
 * REFUSAL (any triggers, first match wins per rule but all applicable reasons are collected):
 *   missing_replacement_owner — replacement_owner absent or empty string
 *   missing_test_coverage     — test_coverage is explicitly false
 * A refused candidate gets net_roi=0 regardless of lines_removed — an unsafe deletion is worth
 * zero, no matter how many lines it would remove.
 *
 * NET ROI (safe candidates only):
 *   base            = lines_removed
 *   capability_bonus = lines_removed * 1.0 when capability_preserved=true, else lines_removed * 0.3
 *   risk_penalty     = high:50, medium:20, low:0
 *   net_roi = max(0.0, capability_bonus - risk_penalty)
 *
 * INPUT per candidate:
 *   {candidate_id, lines_removed:int, capability_preserved:bool, replacement_owner?:string,
 *    test_coverage:bool, risk_level?:'low'|'medium'|'high'}
 *
 * OUTPUT: list of ranked entries {candidate_id, net_roi, safe, refusal_reasons, required_prework},
 * sorted by net_roi descending (refused candidates — net_roi=0 — sort after any safe candidate
 * with positive ROI), candidate_id as deterministic tie-break.
 *
 * Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainDeletionRoiRanker
{
    public const SCHEMA = 'atlas.external_brain.deletion_roi_ranker.v1';

    private const RISK_PENALTY = [
        'high'   => 50.0,
        'medium' => 20.0,
        'low'    => 0.0,
    ];

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array{schema:string, ranked:list<array<string,mixed>>}
     */
    public function rank(array $candidates): array
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id                  = (string) ($candidate['candidate_id'] ?? '');
            $linesRemoved         = max(0, (int) ($candidate['lines_removed'] ?? 0));
            $capabilityPreserved  = (bool) ($candidate['capability_preserved'] ?? false);
            $replacementOwner     = trim((string) ($candidate['replacement_owner'] ?? ''));
            $testCoverage         = (bool) ($candidate['test_coverage'] ?? false);
            $riskLevel            = strtolower(trim((string) ($candidate['risk_level'] ?? 'low')));

            $refusalReasons = [];
            $requiredPrework = [];

            if ($replacementOwner === '') {
                $refusalReasons[] = 'missing_replacement_owner';
                $requiredPrework[] = 'assign_replacement_owner_for:'.$id;
            }
            if (! $testCoverage) {
                $refusalReasons[] = 'missing_test_coverage';
                $requiredPrework[] = 'add_test_coverage_for:'.$id;
            }

            $safe = $refusalReasons === [];

            if ($safe) {
                $capabilityBonus = $linesRemoved * ($capabilityPreserved ? 1.0 : 0.3);
                $riskPenalty     = self::RISK_PENALTY[$riskLevel] ?? 0.0;
                $netRoi          = max(0.0, $capabilityBonus - $riskPenalty);
            } else {
                $netRoi = 0.0;
            }

            $ranked[] = [
                'candidate_id'      => $id,
                'net_roi'           => round($netRoi, 2),
                'safe'              => $safe,
                'refusal_reasons'   => $refusalReasons,
                'required_prework'  => $requiredPrework,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int =>
            $b['net_roi'] !== $a['net_roi']
                ? $b['net_roi'] <=> $a['net_roi']
                : strcmp((string) $a['candidate_id'], (string) $b['candidate_id'])
        );

        return [
            'schema' => self::SCHEMA,
            'ranked' => $ranked,
        ];
    }
}
