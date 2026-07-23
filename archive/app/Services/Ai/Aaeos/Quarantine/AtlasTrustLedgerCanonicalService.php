<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Trust Ledger Canonical — append-only autonomy-gating score.
 *
 * Pure, deterministic implementation of the canonical Trust Ledger contract.
 * The ledger is an append-only history of promotions, demotes, certification
 * pass/fail, self-construction approvals, signature breaches, gate pass/fail,
 * incident resolutions and drift detections. A 0..1 score is folded over a
 * rolling 90-day window and gates autonomy promotion at L4 and above.
 *
 * This service NEVER updates a prior event, NEVER writes a row, NEVER calls a
 * store or a provider. It answers three contract questions deterministically:
 *
 *   "Score formula" + "Tabela de pesos" — {@see score()} computes
 *     sigmoid( sum(weight_i * sign_i) / norm ) over events_window(90d), where
 *     sign_i is +1 for success kinds and -1 for failure kinds, weight_i is taken
 *     from the frozen weight table, and norm = sum(|weight_i|). An empty window
 *     normalises to a neutral 0.5 (sigmoid of 0).
 *
 *   "Thresholds" — {@see eligibleLevel()} maps the score to the MAX eligible
 *     autonomy level: >=0.95 L7, >=0.90 L6, >=0.80 L5, >=0.70 L4, 0.50..0.70 L3
 *     max, <0.50 freeze runtime + Architect review.
 *
 *   "Fluxo" + "Regras para IA" — {@see gate()} decides whether a requested
 *     promotion to a target level is allowed. A promotion to L4 or above is
 *     allowed ONLY when the score is at/above that level's threshold AND the
 *     score was computed from the SAME day as the request ("Promote L4+ exige
 *     score do dia"). Below L4 the same-day rule does not apply.
 *
 *   "Regras para IA" / "forbidden_changes" — {@see classifyWrite()} enforces
 *     append-only: an `append` is allowed; any `update` or `delete` against the
 *     ledger is REJECTED ("Ledger NUNCA update; sempre append").
 *
 * @see docs/engineering-knowledge-base/atlas-trust-ledger-canonical.md
 */
final class AtlasTrustLedgerCanonicalService
{
    /** Canonical event schema id. */
    public const EVENT_SCHEMA = 'atlas.trust_ledger.event.v1';

    /** Stable schema id for the decisions this service emits. */
    public const DECISION_SCHEMA = 'atlas.trust_ledger.decision.v1';

    /** Rolling score window in days ("rolling 90d"). */
    public const WINDOW_DAYS = 90;

    /** L4 is the lowest tier whose promotion requires the same-day score. */
    public const SAME_DAY_FLOOR_LEVEL = 4;

    // SCORE_LOGIT_SCALE removido (03/07): era enxerto do auto-merge do Loop
    // de 13/06 (1471a00741, pré-O-3/reprove) que multiplicava o logit por 3 —
    // INFLANDO o trust score (sigmoid(3)≈0.95 vs sigmoid(1)≈0.73) e tornando
    // L7 de autonomia mais fácil de atingir, contra o contrato congelado de
    // 01/06 (sigmoid(weighted_sum/norm) puro). O merge também reescreveu o
    // docblock para justificar a si mesmo. Trust ledger governa autonomia:
    // calibração só muda com reconciliação explícita do teste congelado.

    /**
     * Frozen weight + sign table ("Tabela de pesos"). Sign is +1 for success
     * kinds and -1 for failure kinds. These are the ONLY recognised event kinds;
     * an unknown kind contributes nothing to the fold.
     *
     * @var array<string,array{weight:float,sign:int}>
     */
    private const WEIGHTS = [
        'cert_pass' => ['weight' => 1.0, 'sign' => 1],
        'cert_fail' => ['weight' => 1.5, 'sign' => -1],
        'promotion' => ['weight' => 0.5, 'sign' => 1],
        'demote' => ['weight' => 1.0, 'sign' => -1],
        'self_construction_approved' => ['weight' => 1.5, 'sign' => 1],
        'signature_breach' => ['weight' => 3.0, 'sign' => -1],
        'gate_pass' => ['weight' => 0.2, 'sign' => 1],
        'gate_fail' => ['weight' => 0.5, 'sign' => -1],
        'incident_resolved' => ['weight' => 0.8, 'sign' => 1],
        'drift_detected' => ['weight' => 0.5, 'sign' => -1],
    ];

    /**
     * Threshold ladder ("Thresholds"), ordered high to low. Each entry maps a
     * minimum score to the autonomy level it unlocks.
     *
     * @var list<array{min:float,level:int}>
     */
    private const THRESHOLDS = [
        ['min' => 0.95, 'level' => 7],
        ['min' => 0.90, 'level' => 6],
        ['min' => 0.80, 'level' => 5],
        ['min' => 0.70, 'level' => 4],
        ['min' => 0.50, 'level' => 3],
    ];

    /** Effect below the L3 floor. */
    public const EFFECT_FREEZE = 'freeze_runtime_architect_review';

    /** @var callable():string returns ISO-8601; injectable for deterministic tests. */
    private $clock;

    /**
     * @param  callable():string|null  $clock  returns an ISO-8601 timestamp.
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }

    /**
     * Compute the Trust Ledger score for a set of events as of $asOf
     * (defaults to "now"). Only events whose `at` falls within the rolling
     * 90-day window ending at $asOf are folded.
     *
     * Returns the score, the contributing event count, the raw fold numerator
     * and denominator, and the window boundary — a replayable receipt.
     *
     * @param  list<array<string,mixed>>  $events
     * @return array{
     *   schema:string,
     *   score:float,
     *   window_days:int,
     *   as_of:string,
     *   window_start:string,
     *   events_in_window:int,
     *   events_ignored:int,
     *   weighted_sum:float,
     *   norm:float
     * }
     */
    public function score(array $events, ?string $asOf = null): array
    {
        $asOf ??= ($this->clock)();
        $asOfTs = $this->toTimestamp($asOf);
        $windowStartTs = $asOfTs - (self::WINDOW_DAYS * 86400);

        $weightedSum = 0.0;
        $norm = 0.0;
        $inWindow = 0;
        $ignored = 0;

        foreach ($events as $event) {
            $kind = is_string($event['kind'] ?? null) ? $event['kind'] : '';
            $spec = self::WEIGHTS[$kind] ?? null;
            if ($spec === null) {
                $ignored++;

                continue;
            }

            $at = is_string($event['at'] ?? null) ? $event['at'] : null;
            if ($at === null) {
                $ignored++;

                continue;
            }
            if (! $this->isIsoTimestamp($at)) {
                $ignored++;

                continue;
            }
            $atTs = strtotime($at);
            if ($atTs === false) {
                $ignored++;

                continue;
            }
            // events_window(90d): strictly inside the rolling window, not future.
            if ($atTs < $windowStartTs || $atTs > $asOfTs) {
                $ignored++;

                continue;
            }

            $weightedSum += $spec['weight'] * $spec['sign'];
            $norm += abs($spec['weight']);
            $inWindow++;
        }

        // norm = sum(|weight_i|); an empty window folds to a neutral 0 input
        // (sigmoid(0) = 0.5) rather than dividing by zero.
        $x = $norm > 0.0 ? ($weightedSum / $norm) : 0.0;

        return [
            'schema' => self::DECISION_SCHEMA,
            'score' => $this->sigmoid($x),
            'window_days' => self::WINDOW_DAYS,
            'as_of' => $asOf,
            'window_start' => gmdate(DateTimeInterface::ATOM, $windowStartTs),
            'events_in_window' => $inWindow,
            'events_ignored' => $ignored,
            'weighted_sum' => round($weightedSum, 10),
            'norm' => round($norm, 10),
        ];
    }

    /**
     * Map a 0..1 score to the MAX eligible autonomy level and its effect, per
     * the "Thresholds" table. Returns level 0 with the freeze effect below 0.50.
     *
     * @return array{schema:string,score:float,eligible_level:int,effect:string,frozen:bool}
     */
    public function eligibleLevel(float $score): array
    {
        $score = $this->clamp01($score);
        foreach (self::THRESHOLDS as $tier) {
            if ($score >= $tier['min']) {
                return [
                    'schema' => self::DECISION_SCHEMA,
                    'score' => $score,
                    'eligible_level' => $tier['level'],
                    'effect' => $tier['level'] >= self::SAME_DAY_FLOOR_LEVEL
                        ? 'autonomy_eligible'
                        : 'capped',
                    'frozen' => false,
                ];
            }
        }

        return [
            'schema' => self::DECISION_SCHEMA,
            'score' => $score,
            'eligible_level' => 0,
            'effect' => self::EFFECT_FREEZE,
            'frozen' => true,
        ];
    }

    /**
     * Decide whether a requested promotion to $targetLevel is allowed.
     *
     * The promotion is allowed only when the eligible level (from the score)
     * is at/above the requested target. Additionally, "Promote L4+ exige score
     * do dia": a request for L4 or above is BLOCKED with reason `stale_score`
     * when the score's as-of day differs from the request day, regardless of how
     * high the score is. Below L4 the same-day rule does not apply.
     *
     * Pass a $scoreReceipt from a prior {@see score()} call to gate against a
     * pre-computed score (its `as_of` is what the same-day rule checks); omit it
     * to score the events fresh as of $requestAt.
     *
     * @param  list<array<string,mixed>>  $events
     * @param  array<string,mixed>|null  $scoreReceipt  optional pre-computed score receipt
     * @return array{
     *   schema:string,
     *   target_level:int,
     *   allowed:bool,
     *   reason:string,
     *   score:float,
     *   eligible_level:int,
     *   requires_same_day:bool,
     *   same_day:bool
     * }
     */
    public function gate(array $events, int $targetLevel, ?string $requestAt = null, ?array $scoreReceipt = null): array
    {
        if ($targetLevel < 1 || $targetLevel > 7) {
            return [
                'schema' => self::DECISION_SCHEMA,
                'target_level' => $targetLevel,
                'allowed' => false,
                'reason' => 'invalid_target_level',
                'score' => 0.0,
                'eligible_level' => 0,
                'requires_same_day' => false,
                'same_day' => false,
            ];
        }

        $requestAt ??= ($this->clock)();
        $scoreReceipt ??= $this->score($events, $requestAt);
        $score = (float) ($scoreReceipt['score'] ?? 0.0);
        $eligible = (int) $this->eligibleLevel($score)['eligible_level'];

        $requiresSameDay = $targetLevel >= self::SAME_DAY_FLOOR_LEVEL;
        $scoreAsOf = is_string($scoreReceipt['as_of'] ?? null) ? $scoreReceipt['as_of'] : null;
        $sameDay = $scoreAsOf !== null && $this->sameUtcDay($scoreAsOf, $requestAt);

        // Same-day freshness is checked FIRST for L4+: an out-of-date score may
        // not promote into the autonomous tiers even if its number qualifies.
        if ($requiresSameDay && ! $sameDay) {
            $allowed = false;
            $reason = 'stale_score';
        } elseif ($score < self::THRESHOLDS[count(self::THRESHOLDS) - 1]['min']) {
            $allowed = false;
            $reason = self::EFFECT_FREEZE;
        } elseif ($eligible < $targetLevel) {
            $allowed = false;
            $reason = 'score_below_threshold';
        } else {
            $allowed = true;
            $reason = 'promotion_allowed';
        }

        return [
            'schema' => self::DECISION_SCHEMA,
            'target_level' => $targetLevel,
            'allowed' => $allowed,
            'reason' => $reason,
            'score' => $score,
            'eligible_level' => $eligible,
            'requires_same_day' => $requiresSameDay,
            'same_day' => $sameDay,
        ];
    }

    /**
     * Enforce the append-only invariant. Only `append` is permitted; `update`
     * and `delete` (or any non-append operation) against the ledger are
     * REJECTED — "Ledger NUNCA update; sempre append".
     *
     * @return array{schema:string,operation:string,allowed:bool,reason:string}
     */
    public function classifyWrite(string $operation): array
    {
        $op = strtolower(trim($operation));
        $allowed = $op === 'append';

        return [
            'schema' => self::DECISION_SCHEMA,
            'operation' => $op,
            'allowed' => $allowed,
            'reason' => $allowed
                ? 'append_only_ok'
                : 'append_only_violation',
        ];
    }

    /**
     * The frozen weight/sign table, exposed for receipts and callers that need
     * to display the contract.
     *
     * @return array<string,array{weight:float,sign:int}>
     */
    public function weightTable(): array
    {
        return self::WEIGHTS;
    }

    /** Logistic sigmoid 1 / (1 + e^-x), clamped to [0,1]. */
    private function sigmoid(float $x): float
    {
        return $this->clamp01(1.0 / (1.0 + exp(-$x)));
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function sameUtcDay(string $a, string $b): bool
    {
        return gmdate('Y-m-d', $this->toTimestamp($a)) === gmdate('Y-m-d', $this->toTimestamp($b));
    }

    private function isIsoTimestamp(string $timestamp): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $timestamp) === 1;
    }

    private function toTimestamp(string $iso): int
    {
        $ts = strtotime($iso);

        return $ts === false ? 0 : $ts;
    }
}
