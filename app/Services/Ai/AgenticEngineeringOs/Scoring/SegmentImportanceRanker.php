<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Support\AtlasArrayFieldReader;
use App\Services\Ai\Support\AiValueNormalizer;

final class SegmentImportanceRanker
{
    public const SCHEMA_VERSION = 'atlas.aaeos.segment_importance_ranking.v1';

    /**
     * Semantic weight per segment kind (composite base term).
     *
     * @var array<string, float>
     */
    public const KIND_WEIGHT = [
        self::FIELD_DECISION => self::FLOAT_1_0,
        self::FIELD_BLOCKER => self::FLOAT_1_0,
        self::FIELD_DOD => self::FLOAT_1_0,
        self::FIELD_RISK_CRITICAL => self::FLOAT_1_0,
        self::FIELD_EVIDENCE => self::FLOAT_0_6,
        self::FIELD_DECISION_NOTE => self::FLOAT_0_6,
        self::FIELD_FACT => self::FLOAT_0_4,
        self::FIELD_STALE_QUERY => self::FLOAT_0_1,
        self::FIELD_LOW_SCORE_REF => self::FLOAT_0_1,
        self::FIELD_DUPLICATE => self::FLOAT_0_1,
    ];

    public const KIND_WEIGHT_UNKNOWN = 0.3;

    public const EVIDENCE_REF_BONUS = 0.20;

    public const DECISION_OR_BLOCKER_LINK_BONUS = 0.30;

    public const DEDUP_STEP_PENALTY = 0.5;

    public const DEDUP_PENALTY_CAP = 1.0;

    public const DROP_REASON_BUDGET_EXCEEDED = 'budget_exceeded';

    public const DROP_REASON_OVERSIZED_SEGMENT = 'oversized_segment';

    public const DECISION_KEEP = 'keep';

    public const DECISION_DROP = 'drop';
    public const FIELD_SCORE = 'score';
    public const FIELD_RECENCY_RANK = 'recency_rank';
    public const FIELD_KIND_WEIGHT = 'kind_weight';
    public const FIELD_DECISION = 'decision';
    public const FIELD_DROP_REASON = 'drop_reason';
    public const FIELD_KIND = 'kind';
    public const FIELD_STATUS = 'status';
    public const FIELD_SEGMENTS = 'segments';
    public const FIELD_TOKEN_ESTIMATE = 'token_estimate';
    public const FIELD_DEDUP_PENALTY = 'dedup_penalty';
    public const FIELD_BLOCKER = 'blocker';
    public const FIELD_DOD = 'dod';
    public const FIELD_RISK_CRITICAL = 'risk_critical';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_DECISION_NOTE = 'decision_note';
    public const FIELD_FACT = 'fact';
    public const FIELD_BOUNDARY_INDEX = 'boundary_index';
    public const FIELD_DROPPED_COUNT = 'dropped_count';
    public const FIELD_DROPPED_IDS = 'dropped_ids';
    public const FIELD_DUP_GROUP = 'dup_group';
    public const FIELD_DUPLICATE = 'duplicate';
    public const FIELD_HAS_EVIDENCE_REF = 'has_evidence_ref';
    public const FIELD_STALE_QUERY = 'stale_query';
    public const FIELD_LOW_SCORE_REF = 'low_score_ref';
    public const FIELD_ID = 'id';
    public const FIELD_TOKENS_KEPT = 'tokens_kept';
    public const FIELD_KEPT_COUNT = 'kept_count';
    public const FIELD_KEPT_IDS = 'kept_ids';
    public const FIELD_LINKS_DECISION_OR_BLOCKER = 'links_decision_or_blocker';
    public const FIELD_RANKED = 'ranked';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_TOKEN_BUDGET = 'token_budget';
    public const FIELD_TOKENS_AVAILABLE = 'tokens_available';
    public const FIELD_IMPORTANCE = 'importance';
    public const FLOAT_0_6 = 0.6;
    public const FLOAT_0_4 = 0.4;
    public const FLOAT_1_0 = 1.0;
    public const FLOAT_0_1 = 0.1;

    /**
     * Rank may-discard segments by composite signal and greedily fill a token budget.
     *
     * Composite score = kindWeight + 0.5/(1+recency_rank)
     *   + (has_evidence_ref ? +0.20 : 0)
     *   + (links_decision_or_blocker ? +0.30 : 0)
     *   + explicit importance hint (importance / 100, capped 0..1)
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
            $tokenEstimate = $segment[self::FIELD_TOKEN_ESTIMATE];

            if ($tokenEstimate > $budget) {
                $decision = self::DECISION_DROP;
                $dropReason = self::DROP_REASON_OVERSIZED_SEGMENT;
            } elseif ($runningTotal + $tokenEstimate <= $budget) {
                $decision = self::DECISION_KEEP;
                $dropReason = null;
                $runningTotal += $tokenEstimate;
                $tokensKept += $tokenEstimate;
            } else {
                $decision = self::DECISION_DROP;
                $dropReason = self::DROP_REASON_BUDGET_EXCEEDED;

                if ($boundaryIndex === null) {
                    $boundaryIndex = $index;
                }
            }

            if ($decision === self::DECISION_KEEP) {
                $keptIds[] = $segment[self::FIELD_ID];
            } else {
                $droppedIds[] = $segment[self::FIELD_ID];
            }

            $ranked[] = [
                self::FIELD_ID => $segment[self::FIELD_ID],
                self::FIELD_KIND => $segment[self::FIELD_KIND],
                self::FIELD_SCORE => $segment[self::FIELD_SCORE],
                self::FIELD_RECENCY_RANK => $segment[self::FIELD_RECENCY_RANK],
                self::FIELD_TOKEN_ESTIMATE => $tokenEstimate,
                self::FIELD_DEDUP_PENALTY => $segment[self::FIELD_DEDUP_PENALTY],
                self::FIELD_DECISION => $decision,
                self::FIELD_DROP_REASON => $dropReason,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_TOKEN_BUDGET => $budget,
            self::FIELD_RANKED => $ranked,
            self::FIELD_KEPT_IDS => $keptIds,
            self::FIELD_DROPPED_IDS => $droppedIds,
            self::FIELD_BOUNDARY_INDEX => $boundaryIndex ?? count($ranked),
            self::FIELD_TOKENS_KEPT => $tokensKept,
            self::FIELD_TOKENS_AVAILABLE => max($budget - $tokensKept, 0),
            self::FIELD_KEPT_COUNT => count($keptIds),
            self::FIELD_DROPPED_COUNT => count($droppedIds),
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
            $kind = AtlasArrayFieldReader::stringField($row, self::FIELD_KIND);
            $recencyRank = $this->intField($row, self::FIELD_RECENCY_RANK);
            $hasEvidenceRef = $this->boolField($row, self::FIELD_HAS_EVIDENCE_REF);
            $linksDecisionOrBlocker = $this->boolField($row, self::FIELD_LINKS_DECISION_OR_BLOCKER);
            $dupGroup = $this->nullableStringField($row, self::FIELD_DUP_GROUP);
            $importance = AiValueNormalizer::clampUnit($this->numericField($row, self::FIELD_IMPORTANCE) / 100.0);

            $kindWeight = $this->kindWeight($kind);
            $dedupPenalty = $this->dedupPenalty($dupGroup, $dupGroupSeen);

            $base = $kindWeight + 0.5 / (1 + max($recencyRank, 0)) + $importance;

            if ($hasEvidenceRef) {
                $base += self::EVIDENCE_REF_BONUS;
            }

            if ($linksDecisionOrBlocker) {
                $base += self::DECISION_OR_BLOCKER_LINK_BONUS;
            }

            $score = round($base + $dedupPenalty, 4);

            $scored[] = [
                self::FIELD_ID => AtlasArrayFieldReader::stringField($row, self::FIELD_ID),
                self::FIELD_KIND => $kind,
                self::FIELD_RECENCY_RANK => $recencyRank,
                self::FIELD_TOKEN_ESTIMATE => max($this->intField($row, self::FIELD_TOKEN_ESTIMATE), 0),
                self::FIELD_HAS_EVIDENCE_REF => $hasEvidenceRef,
                self::FIELD_LINKS_DECISION_OR_BLOCKER => $linksDecisionOrBlocker,
                self::FIELD_DUP_GROUP => $dupGroup,
                self::FIELD_KIND_WEIGHT => $kindWeight,
                self::FIELD_DEDUP_PENALTY => $dedupPenalty,
                self::FIELD_SCORE => $score,
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
        if ($a[self::FIELD_SCORE] !== $b[self::FIELD_SCORE]) {
            return $b[self::FIELD_SCORE] <=> $a[self::FIELD_SCORE];
        }

        if ($a[self::FIELD_RECENCY_RANK] !== $b[self::FIELD_RECENCY_RANK]) {
            return $a[self::FIELD_RECENCY_RANK] <=> $b[self::FIELD_RECENCY_RANK];
        }

        if ($a[self::FIELD_KIND_WEIGHT] !== $b[self::FIELD_KIND_WEIGHT]) {
            return $b[self::FIELD_KIND_WEIGHT] <=> $a[self::FIELD_KIND_WEIGHT];
        }

        return strcmp($a[self::FIELD_ID], $b[self::FIELD_ID]);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function nullableStringField(array $row, string $key): ?string
    {
        return AiValueNormalizer::trimmedScalarStringOrNull($row[$key] ?? null);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        $value = AiValueNormalizer::finiteFloatOrNull($row[$key] ?? 0);

        return $value === null ? 0 : (int) $value;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function numericField(array $row, string $key): float
    {
        return AiValueNormalizer::finiteFloatOrNull($row[$key] ?? 0.0) ?? 0.0;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function boolField(array $row, string $key): bool
    {
        return ($row[$key] ?? false) === true;
    }
}
