<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

final class SegmentImportanceRanker
{
    private const SCHEMA_VERSION = 'atlas.aaeos.segment_importance_ranking.v1';

    /**
     * Semantic weight per segment kind (composite base term).
     */
    private const KIND_WEIGHT = [
        'decision' => 1.0,
        'blocker' => 1.0,
        'dod' => 1.0,
        'risk_critical' => 1.0,
        'evidence' => 0.6,
        'decision_note' => 0.6,
        'fact' => 0.4,
        'stale_query' => 0.1,
        'low_score_ref' => 0.1,
        'duplicate' => 0.1,
    ];

    private const KIND_WEIGHT_UNKNOWN = 0.3;

    private const EVIDENCE_REF_BONUS = 0.20;

    private const DECISION_OR_BLOCKER_LINK_BONUS = 0.30;

    private const DEDUP_STEP_PENALTY = 0.5;

    private const DEDUP_PENALTY_CAP = 1.0;

    private const DROP_REASON_BUDGET_EXCEEDED = 'budget_exceeded';

    private const DROP_REASON_OVERSIZED_SEGMENT = 'oversized_segment';

    /**
     * Rank may-discard segments by composite signal and greedily fill a token budget.
     *
     * Composite score = kindWeight + 0.5/(1+recency_rank)
     *   + (has_evidence_ref ? +0.20 : 0)
     *   + (links_decision_or_blocker ? +0.30 : 0)
     *   + dedup_penalty (0 for first dup_group member, -0.5 each subsequent,
     *     cumulatively capped at -1.0; null dup_group never penalised).
     *
     * Sort: score DESC, then recency_rank ASC (newer first), then kindWeight DESC,
     * then id ASC. The sorted list is walked greedily-by-rank: a segment is kept
     * while running_total + token_estimate <= budget; a segment whose own
     * token_estimate exceeds the budget is dropped as oversized but ranking
     * continues, so smaller lower-ranked segments can still fit.
     *
     * @param  list<array<string,mixed>>  $maybeDiscard
     * @return array{
     *     schema_version:string,
     *     token_budget:int,
     *     ranked:list<array{
     *         id:string,
     *         kind:string,
     *         score:float,
     *         recency_rank:int,
     *         token_estimate:int,
     *         dedup_penalty:float,
     *         decision:string,
     *         drop_reason:?string
     *     }>,
     *     kept_ids:list<string>,
     *     dropped_ids:list<string>,
     *     boundary_index:int,
     *     tokens_kept:int,
     *     tokens_available:int,
     *     kept_count:int,
     *     dropped_count:int
     * }
     */
    public function select(array $maybeDiscard, int $tokenBudget): array
    {
        $budget = max($tokenBudget, 0);

        $scored = $this->scoreSegments($maybeDiscard);

        usort($scored, fn (array $a, array $b): int => $this->compare($a, $b));

        $ranked = [];
        $keptIds = [];
        $droppedIds = [];
        $runningTotal = 0;
        $tokensKept = 0;
        $boundaryIndex = null;

        foreach ($scored as $index => $segment) {
            $tokenEstimate = $segment['token_estimate'];

            if ($tokenEstimate > $budget) {
                $decision = 'drop';
                $dropReason = self::DROP_REASON_OVERSIZED_SEGMENT;
            } elseif ($runningTotal + $tokenEstimate <= $budget) {
                $decision = 'keep';
                $dropReason = null;
                $runningTotal += $tokenEstimate;
                $tokensKept += $tokenEstimate;
            } else {
                $decision = 'drop';
                $dropReason = self::DROP_REASON_BUDGET_EXCEEDED;

                if ($boundaryIndex === null) {
                    $boundaryIndex = $index;
                }
            }

            if ($decision === 'keep') {
                $keptIds[] = $segment['id'];
            } else {
                $droppedIds[] = $segment['id'];
            }

            $ranked[] = [
                'id' => $segment['id'],
                'kind' => $segment['kind'],
                'score' => $segment['score'],
                'recency_rank' => $segment['recency_rank'],
                'token_estimate' => $tokenEstimate,
                'dedup_penalty' => $segment['dedup_penalty'],
                'decision' => $decision,
                'drop_reason' => $dropReason,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'token_budget' => $budget,
            'ranked' => $ranked,
            'kept_ids' => $keptIds,
            'dropped_ids' => $droppedIds,
            'boundary_index' => $boundaryIndex ?? count($ranked),
            'tokens_kept' => $tokensKept,
            'tokens_available' => max($budget - $tokensKept, 0),
            'kept_count' => count($keptIds),
            'dropped_count' => count($droppedIds),
        ];
    }

    /**
     * Resolve the semantic weight for a segment kind.
     */
    public function kindWeight(string $kind): float
    {
        return self::KIND_WEIGHT[$kind] ?? self::KIND_WEIGHT_UNKNOWN;
    }

    /**
     * Normalise + score every segment, assigning cumulative dedup penalties in
     * input order so the first member of a dup_group is never penalised.
     *
     * @param  list<array<string,mixed>>  $maybeDiscard
     * @return list<array{
     *     id:string,
     *     kind:string,
     *     recency_rank:int,
     *     token_estimate:int,
     *     has_evidence_ref:bool,
     *     links_decision_or_blocker:bool,
     *     dup_group:?string,
     *     kind_weight:float,
     *     dedup_penalty:float,
     *     score:float
     * }>
     */
    private function scoreSegments(array $maybeDiscard): array
    {
        $dupGroupSeen = [];
        $scored = [];

        foreach (array_values($maybeDiscard) as $row) {
            $kind = $this->stringField($row, 'kind');
            $recencyRank = $this->intField($row, 'recency_rank');
            $hasEvidenceRef = $this->boolField($row, 'has_evidence_ref');
            $linksDecisionOrBlocker = $this->boolField($row, 'links_decision_or_blocker');
            $dupGroup = $this->nullableStringField($row, 'dup_group');

            $kindWeight = $this->kindWeight($kind);
            $dedupPenalty = $this->dedupPenalty($dupGroup, $dupGroupSeen);

            $base = $kindWeight + 0.5 / (1 + max($recencyRank, 0));

            if ($hasEvidenceRef) {
                $base += self::EVIDENCE_REF_BONUS;
            }

            if ($linksDecisionOrBlocker) {
                $base += self::DECISION_OR_BLOCKER_LINK_BONUS;
            }

            $score = round($base + $dedupPenalty, 4);

            $scored[] = [
                'id' => $this->stringField($row, 'id'),
                'kind' => $kind,
                'recency_rank' => $recencyRank,
                'token_estimate' => max($this->intField($row, 'token_estimate'), 0),
                'has_evidence_ref' => $hasEvidenceRef,
                'links_decision_or_blocker' => $linksDecisionOrBlocker,
                'dup_group' => $dupGroup,
                'kind_weight' => $kindWeight,
                'dedup_penalty' => $dedupPenalty,
                'score' => $score,
            ];
        }

        return $scored;
    }

    /**
     * Cumulative dedup penalty for the next member of a dup_group.
     * First member: 0.0; each subsequent member: -0.5, capped at -1.0.
     * A null dup_group is never grouped and never penalised.
     *
     * @param  array<string,int>  $dupGroupSeen
     */
    private function dedupPenalty(?string $dupGroup, array &$dupGroupSeen): float
    {
        if ($dupGroup === null) {
            return 0.0;
        }

        $previous = $dupGroupSeen[$dupGroup] ?? 0;
        $dupGroupSeen[$dupGroup] = $previous + 1;

        $penalty = self::DEDUP_STEP_PENALTY * $previous;

        return -min($penalty, self::DEDUP_PENALTY_CAP);
    }

    /**
     * Total-order comparator: score DESC, recency_rank ASC, kindWeight DESC, id ASC.
     *
     * @param  array{score:float,recency_rank:int,kind_weight:float,id:string}  $a
     * @param  array{score:float,recency_rank:int,kind_weight:float,id:string}  $b
     */
    private function compare(array $a, array $b): int
    {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }

        if ($a['recency_rank'] !== $b['recency_rank']) {
            return $a['recency_rank'] <=> $b['recency_rank'];
        }

        if ($a['kind_weight'] !== $b['kind_weight']) {
            return $b['kind_weight'] <=> $a['kind_weight'];
        }

        return strcmp($a['id'], $b['id']);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function nullableStringField(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function boolField(array $row, string $key): bool
    {
        return ($row[$key] ?? false) === true;
    }
}
