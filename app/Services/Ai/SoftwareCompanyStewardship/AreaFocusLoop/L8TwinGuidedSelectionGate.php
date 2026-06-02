<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P3 twin-guided selection gate.
 *
 * The predictive twin (L8-P3) is allowed to PRIORITISE which candidate evolutions are
 * worth trying first, but it is never allowed to decide which evolution may be CLAIMED:
 * "Twin chooses what to try, never what to claim." Real verification therefore stays
 * mandatory regardless of any twin score, so this gate always returns
 * verification_required=true and stamps every ordered candidate with
 * measured_or_reverted_required=true.
 *
 * Guidance itself is gated on the twin being trustworthy. When the twin model is stale
 * or its measured accuracy is below the staleness floor, guidance is BLOCKED: the gate
 * refuses to reorder by an untrusted prediction and falls back to the deterministic
 * input order, recording the reason in blocked_reasons. A blocked twin never relaxes
 * verification — it only loses the right to reprioritise.
 *
 * When guidance is allowed, candidates are ordered by their twin priority (descending),
 * with the original input index as a stable, deterministic tie-break. The gate is pure:
 * it computes everything from candidates + twin_score and performs no I/O, so selection
 * remains a governed, reproducible proposal.
 */
final class L8TwinGuidedSelectionGate
{
    public const SCHEMA_VERSION = 'atlas.aaeos.l8.twin_guided_selection.v1';

    /**
     * Measured twin accuracy must be at least this to be trusted for guidance. Mirrors the
     * twin staleness floor used by L8SystemEvolutionTwinPredictionScorer so a model deemed
     * stale there cannot silently re-acquire guidance authority here.
     */
    public const MIN_TWIN_ACCURACY = 0.6;

    public const REASON_NO_CANDIDATES = 'no_candidates';

    public const REASON_TWIN_ACCURACY_BELOW_FLOOR = 'twin_accuracy_below_floor';

    public const REASON_TWIN_MODEL_STALE = 'twin_model_stale';

    /**
     * @param list<array{
     *     candidate_id?: string,
     *     id?: string,
     *     twin_priority?: int|float,
     *     predicted_lift?: int|float
     * }> $candidates candidate evolutions the twin may prioritise
     * @param array{
     *     twin_accuracy?: int|float,
     *     stale_model?: bool,
     *     priorities?: array<string, int|float>
     * } $twinScore measured twin quality + optional per-candidate priorities
     *
     * @return array{
     *     schema_version: string,
     *     ordered_candidates: list<array{
     *         candidate_id: string,
     *         twin_priority: float,
     *         rank: int,
     *         measured_or_reverted_required: true
     *     }>,
     *     verification_required: true,
     *     guidance_applied: bool,
     *     twin_accuracy: float,
     *     blocked_reasons: list<string>
     * }
     */
    public function decide(array $candidates, array $twinScore): array
    {
        $twinAccuracy = $this->clampUnit($this->floatValue($twinScore['twin_accuracy'] ?? 0.0));

        $normalised = $this->normaliseCandidates($candidates, $twinScore);

        $blockedReasons = [];

        if ($normalised === []) {
            $blockedReasons[] = self::REASON_NO_CANDIDATES;
        }

        if (($twinScore['stale_model'] ?? false) === true) {
            $blockedReasons[] = self::REASON_TWIN_MODEL_STALE;
        }

        if ($twinAccuracy < self::MIN_TWIN_ACCURACY) {
            $blockedReasons[] = self::REASON_TWIN_ACCURACY_BELOW_FLOOR;
        }

        // Guidance is applied only when the twin is trusted AND there is something to order.
        // A blocked twin loses reprioritisation rights but never relaxes verification.
        $guidanceApplied = $normalised !== []
            && ($twinScore['stale_model'] ?? false) !== true
            && $twinAccuracy >= self::MIN_TWIN_ACCURACY;

        $ordered = $guidanceApplied
            ? $this->orderByTwinPriority($normalised)
            : $this->keepInputOrder($normalised);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ordered_candidates' => $ordered,
            'verification_required' => true,
            'guidance_applied' => $guidanceApplied,
            'twin_accuracy' => $twinAccuracy,
            'blocked_reasons' => $blockedReasons,
        ];
    }

    /**
     * Resolve each candidate to {id, index, priority}. The priority is the candidate's own
     * twin_priority/predicted_lift, else the twin_score.priorities entry keyed by id, else 0.
     * Candidates without a usable id are dropped (they cannot be selected or claimed).
     *
     * @param list<array<string, mixed>> $candidates
     * @param array{priorities?: array<string, int|float>} $twinScore
     *
     * @return list<array{id: string, index: int, priority: float}>
     */
    private function normaliseCandidates(array $candidates, array $twinScore): array
    {
        $priorities = $twinScore['priorities'] ?? [];
        if (! is_array($priorities)) {
            $priorities = [];
        }

        $normalised = [];
        $index = 0;

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = $this->candidateId($candidate);
            if ($id === null) {
                continue;
            }

            $normalised[] = [
                'id' => $id,
                'index' => $index,
                'priority' => $this->resolvePriority($candidate, $priorities, $id),
            ];

            $index++;
        }

        return $normalised;
    }

    /**
     * @param array{candidate_id?: string, id?: string} $candidate
     */
    private function candidateId(array $candidate): ?string
    {
        foreach (['candidate_id', 'id'] as $key) {
            $value = $candidate[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array{twin_priority?: int|float, predicted_lift?: int|float} $candidate
     * @param array<string, int|float> $priorities
     */
    private function resolvePriority(array $candidate, array $priorities, string $id): float
    {
        foreach (['twin_priority', 'predicted_lift'] as $key) {
            if (array_key_exists($key, $candidate)) {
                return $this->floatValue($candidate[$key]);
            }
        }

        if (array_key_exists($id, $priorities)) {
            return $this->floatValue($priorities[$id]);
        }

        return 0.0;
    }

    /**
     * Order by twin priority descending, stable on the original input index for ties, then
     * stamp the verification contract onto every entry.
     *
     * @param list<array{id: string, index: int, priority: float}> $normalised
     *
     * @return list<array{candidate_id: string, twin_priority: float, rank: int, measured_or_reverted_required: true}>
     */
    private function orderByTwinPriority(array $normalised): array
    {
        usort($normalised, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority']
                ?: $a['index'] <=> $b['index'];
        });

        return $this->stamp($normalised, true);
    }

    /**
     * Deterministic fallback when guidance is blocked: preserve the input order exactly, but
     * report each effective twin_priority as 0.0 so the envelope never implies the (untrusted)
     * twin ranked the candidates.
     *
     * @param list<array{id: string, index: int, priority: float}> $normalised
     *
     * @return list<array{candidate_id: string, twin_priority: float, rank: int, measured_or_reverted_required: true}>
     */
    private function keepInputOrder(array $normalised): array
    {
        return $this->stamp($normalised, false);
    }

    /**
     * @param list<array{id: string, index: int, priority: float}> $normalised
     *
     * @return list<array{candidate_id: string, twin_priority: float, rank: int, measured_or_reverted_required: true}>
     */
    private function stamp(array $normalised, bool $useTwinPriority): array
    {
        $ordered = [];
        $rank = 1;

        foreach ($normalised as $entry) {
            $ordered[] = [
                'candidate_id' => $entry['id'],
                'twin_priority' => $useTwinPriority ? $entry['priority'] : 0.0,
                'rank' => $rank,
                'measured_or_reverted_required' => true,
            ];

            $rank++;
        }

        return $ordered;
    }

    private function clampUnit(float $value): float
    {
        // A non-finite (NaN) accuracy is not a trustworthy measurement, so it fails
        // closed to the lower bound: the gate then records twin_accuracy_below_floor and
        // blocks guidance rather than passing NaN through (NaN < 0.6 / NaN >= 0.6 are both
        // false, which would otherwise silently block guidance with no recorded reason and
        // leak NaN into the 0..1 twin_accuracy field).
        if (! is_finite($value) || $value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function floatValue(mixed $value): float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        // A non-finite (NaN/INF) priority is not a usable ordering key: it makes the
        // descending <=> comparator non-transitive (NAN <=> anything === 1), so usort would
        // no longer be a consistent, reproducible sort, and a NaN would leak into the
        // twin_priority output. Such values fall back to the 0.0 default the gate already
        // uses for an absent priority.
        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }

        if (is_string($value) && is_numeric($value)) {
            $float = (float) $value;

            return is_finite($float) ? $float : 0.0;
        }

        return 0.0;
    }
}
