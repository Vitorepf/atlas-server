<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure consensus normalizer. Merges outputs from multiple models or scaffolds
 * by evidence quality, non-duplication, and leverage, NOT by naive majority vote.
 *
 * AC2: a minority proposal with stronger evidence is preferred over a majority
 *      of duplicated, vague, or low-leverage proposals.
 *
 * AC3: unresolved high-severity contradictions are surfaced in `disagreements`
 *      and the conflicting proposals are withheld from `selected_proposals`.
 *
 * Rejection order (first match wins per proposal):
 *   1. duplicate   — is_duplicate=true
 *   2. vague       — is_vague=true
 *   3. low_leverage — leverage_score < LOW_LEVERAGE_THRESHOLD
 *
 * High-severity contradictions (contradiction_severity='high') between two
 * non-rejected proposals block BOTH from selection.
 *
 * AC4: output always includes selected_proposals, rejected_proposals,
 *      evidence_ranking, disagreements, and merge_notes.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainCrossModelConsensusNormalizer
{
    public const SCHEMA = 'atlas.external_brain.cross_model_consensus_normalizer.v1';

    public const REJECTION_DUPLICATE    = 'duplicate';
    public const REJECTION_VAGUE        = 'vague';
    public const REJECTION_LOW_LEVERAGE = 'low_leverage';

    public const DISAGREEMENT_HIGH_SEVERITY = 'unresolved_high_severity_contradiction';

    private const LOW_LEVERAGE_THRESHOLD = 0.30;

    /**
     * @param  array{proposals?: list<array<string,mixed>>}  $input
     * @return array{schema:string, selected_proposals:list<array<string,mixed>>, rejected_proposals:list<array<string,mixed>>, evidence_ranking:list<string>, disagreements:list<array<string,mixed>>, merge_notes:list<string>}
     */
    public function normalize(array $input): array
    {
        $proposals = (array) ($input['proposals'] ?? []);

        $candidates = [];
        $rejected   = [];

        foreach ($proposals as $idx => $proposal) {
            $id        = (string) ($proposal['proposal_id'] ?? "proposal_{$idx}");
            $isDup     = (bool) ($proposal['is_duplicate']   ?? false);
            $isVague   = (bool) ($proposal['is_vague']       ?? false);
            $leverage  = max(0.0, min(1.0, (float) ($proposal['leverage_score'] ?? 1.0)));
            $evidence  = max(0.0, min(1.0, (float) ($proposal['evidence_strength'] ?? 0.0)));

            $rejectionReason = $this->rejectionReason($isDup, $isVague, $leverage);

            if ($rejectionReason !== null) {
                $rejected[] = [
                    'proposal_id'      => $id,
                    'rejection_reason' => $rejectionReason,
                    'evidence_strength' => $evidence,
                ];
                continue;
            }

            $candidates[$id] = [
                'proposal_id'            => $id,
                'objective'              => (string) ($proposal['objective'] ?? ''),
                'evidence_strength'      => $evidence,
                'leverage_score'         => $leverage,
                'model_source'           => (string) ($proposal['model_source'] ?? ''),
                'contradiction_with'     => (string) ($proposal['contradiction_with'] ?? ''),
                'contradiction_severity' => (string) ($proposal['contradiction_severity'] ?? ''),
            ];
        }

        // Detect high-severity contradictions between surviving candidates
        $blockedByConflict = [];
        $disagreements     = [];

        foreach ($candidates as $id => $candidate) {
            $contraId  = $candidate['contradiction_with'];
            $severity  = $candidate['contradiction_severity'];

            if ($severity === 'high' && $contraId !== '' && isset($candidates[$contraId])) {
                $pairKey = $this->pairKey($id, $contraId);
                if (! isset($disagreements[$pairKey])) {
                    $disagreements[$pairKey] = [
                        'type'        => self::DISAGREEMENT_HIGH_SEVERITY,
                        'proposal_a'  => $id,
                        'proposal_b'  => $contraId,
                        'resolution'  => 'withheld_from_selection_pending_operator_review',
                    ];
                    $blockedByConflict[$id]      = true;
                    $blockedByConflict[$contraId] = true;
                }
            }
        }

        // Build selected (not blocked) sorted by evidence DESC
        $selected = [];
        foreach ($candidates as $id => $candidate) {
            if (! isset($blockedByConflict[$id])) {
                $selected[] = $candidate;
            }
        }
        usort($selected, fn (array $a, array $b): int
            => $b['evidence_strength'] <=> $a['evidence_strength']);

        // Evidence ranking: all candidates (including blocked) by evidence DESC
        $allForRanking = array_values($candidates);
        usort($allForRanking, fn (array $a, array $b): int
            => $b['evidence_strength'] <=> $a['evidence_strength']);
        $evidenceRanking = array_column($allForRanking, 'proposal_id');

        $mergeNotes = $this->buildMergeNotes(count($selected), count($rejected), array_values($disagreements));

        return [
            'schema'              => self::SCHEMA,
            'selected_proposals'  => $selected,
            'rejected_proposals'  => $rejected,
            'evidence_ranking'    => $evidenceRanking,
            'disagreements'       => array_values($disagreements),
            'merge_notes'         => $mergeNotes,
        ];
    }

    private function rejectionReason(bool $isDup, bool $isVague, float $leverage): ?string
    {
        if ($isDup) {
            return self::REJECTION_DUPLICATE;
        }

        if ($isVague) {
            return self::REJECTION_VAGUE;
        }

        if ($leverage < self::LOW_LEVERAGE_THRESHOLD) {
            return self::REJECTION_LOW_LEVERAGE;
        }

        return null;
    }

    private function pairKey(string $a, string $b): string
    {
        $sorted = [$a, $b];
        sort($sorted);

        return implode('|', $sorted);
    }

    /** @return list<string> */
    private function buildMergeNotes(int $selected, int $rejected, array $disagreements): array
    {
        $notes = [
            sprintf('%d proposal(s) selected by evidence quality; %d rejected.', $selected, $rejected),
        ];

        if ($disagreements !== []) {
            $notes[] = sprintf(
                '%d unresolved high-severity disagreement(s) withheld from selection — operator review required.',
                count($disagreements),
            );
        }

        if ($rejected > 0) {
            $notes[] = 'Evidence-based selection: minority proposals with stronger evidence may be preferred over rejected majority.';
        }

        return $notes;
    }
}
