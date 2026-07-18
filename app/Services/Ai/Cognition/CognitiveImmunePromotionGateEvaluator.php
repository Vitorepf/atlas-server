<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure, deterministic domain classifier for the cognitive-immune promotion
 * gates G0..G8 (cognitive-immune-learning-kernel.md). Given the raw candidate
 * signals it computes a per-gate verdict and an overall promotion status using
 * the doc's gate questions plus a fail-safe rule:
 *
 *  - confirm-gates (G0,G1,G2,G5,G6,G7) stay `pending` until their positive
 *    evidence is present (unconfirmed never silently passes);
 *  - forbid-gates (G3,G4) default to `pass` once a real candidate exists, and
 *    `block` on any explicit unsafe / contradicting flag;
 *  - `trusted` requires every gate to pass (which in turn requires outcome
 *    validation via G5 and a cleared probation via G8).
 *
 * Zero constructor dependencies, no I/O, no facades: identical $signals always
 * produce identical output.
 */
final class CognitiveImmunePromotionGateEvaluator
{
    public const FIELD_COUNT = 'count';
    public const FIELD_RECALL_CONCENTRATION_V2 = 'recall_concentration_v2';
    public const SCHEMA_VERSION = 'atlas.cognition.cognitive_immune_promotion_gate.v1';

    /** Canonical gate ids, ordered G0..G8. */
    public const GATE_IDS = ['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];

    public const STATUS_PASS = 'pass';

    public const STATUS_BLOCK = 'block';

    public const STATUS_PENDING = 'pending';

    /** Recognised promotion scopes (G6). */
    public const KNOWN_SCOPES = ['global', 'workspace', 'project', 'task', 'domain', 'session'];

    /** Recognised non-blocking promotion modes (G7). */
    public const ALLOWED_PROMOTION_MODES = ['auto', 'review', 'human_review', 'proposal'];

    /** Promotion modes that explicitly forbid promotion (G7). */
    public const BLOCKED_PROMOTION_MODES = [self::STATUS_BLOCK, self::TRUST_BAND_BLOCKED];

    /** ASI-12 guard: a single loud actor cannot graduate probation alone. */
    public const PROBATION_MIN_RECALL_ACTORS = 2;

    public const TRUST_BAND_BLOCKED = 'blocked';

    public const TRUST_BAND_TRUSTED = 'trusted';

    public const TRUST_BAND_UNCLASSIFIED = 'unclassified';

    public const TRUST_BAND_WATCH = 'watch';

    public const TRUST_BAND_CANDIDATE = 'candidate';
    public const FIELD_RECALLS = 'recalls';
    public const FIELD_PER_ACTOR = 'per_actor';
    public const FIELD_POSITIVE_ACTOR_COUNT = 'positive_actor_count';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_GATE_STATUSES = 'gate_statuses';
    public const FIELD_PROMOTION_STATUS = 'promotion_status';
    public const FIELD_BLOCKING_GATE_IDS = 'blocking_gate_ids';
    public const FIELD_PENDING_GATE_IDS = 'pending_gate_ids';
    public const FIELD_AUTONOMOUS_PROMOTION_ALLOWED = 'autonomous_promotion_allowed';
    public const FIELD_REASONS = 'reasons';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_PROBATION_WATCH_AGE_DAYS = 'probation_watch_age_days';
    public const FIELD_ATOMIC_CLAIM_PRESENT = 'atomic_claim_present';
    public const FIELD_CONTRADICTS_NEWER = 'contradicts_newer';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_CLAIM_SOURCE_PRESENT = 'claim_source_present';
    public const FIELD_CLAIM_TYPE = 'claim_type';
    public const FIELD_CONSENT_GRANTED = 'consent_granted';
    public const FIELD_CONTAINS_SECRET = 'contains_secret';
    public const FIELD_CONTAINS_SENSITIVE_UNNECESSARY = 'contains_sensitive_unnecessary';
    public const FIELD_EVALUATED_AT = 'evaluated_at';
    public const FIELD_FUTURE_UTILITY = 'future_utility';
    public const FIELD_NEGATIVE_FEEDBACK_COUNT = 'negative_feedback_count';
    public const FIELD_NOVELTY = 'novelty';
    public const FIELD_OUTCOME_VALIDATED = 'outcome_validated';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_PROBATION_ENTERED_AT = 'probation_entered_at';
    public const FIELD_PROBATION_EVALUATED_AT = 'probation_evaluated_at';
    public const FIELD_PROBATION_STARTED_AT = 'probation_started_at';
    public const FIELD_PROBATION_SUPERVENING_CONTRADICTION = 'probation_supervening_contradiction';
    public const FIELD_PROBATION_SUPERVENING_CONTRADICTION_COUNT = 'probation_supervening_contradiction_count';
    public const FIELD_PROMOTION_MODE_HINT = 'promotion_mode_hint';
    public const FIELD_PROVENANCE_CYCLE_DETECTED = 'provenance_cycle_detected';
    public const FIELD_PROVENANCE_TRACES_TO_REVERTED = 'provenance_traces_to_reverted';
    public const FIELD_PROVIDER_SAFE = 'provider_safe';
    public const FIELD_RECALL_ACTOR_COUNTS = 'recall_actor_counts';
    public const FIELD_RECALL_NEGATIVE_FEEDBACK = 'recall_negative_feedback';
    public const FIELD_ALL_TIME_RECALL_NEGATIVE_FEEDBACK = 'all_time_recall_negative_feedback';
    public const FIELD_RECALLS_BY_ACTOR = 'recalls_by_actor';
    public const FIELD_RECURRENCE_COUNT = 'recurrence_count';
    public const FIELD_RETENTION_OK = 'retention_ok';
    public const FIELD_WATCH_STARTED_AT = 'watch_started_at';
    public const FIELD_ATOMIC_CLAIM_INCOMPLETE = 'atomic_claim_incomplete';
    public const FIELD_CONSENT_PRIVACY_RETENTION_UNCONFIRMED = 'consent_privacy_retention_unconfirmed';
    public const FIELD_CONTRADICTION_UNEVALUATED = 'contradiction_unevaluated';
    public const FIELD_CONTRADICTS_NEWER_AUTHORITY = 'contradicts_newer_authority';
    public const FIELD_FUTURE_SIGNAL_UNCONFIRMED = 'future_signal_unconfirmed';
    public const FIELD_GATE_UNKNOWN = 'gate_unknown';
    public const FIELD_OUTCOME_NOT_VALIDATED = 'outcome_not_validated';
    public const FIELD_PROBATION_NEGATIVE_FEEDBACK_COUNT = 'probation_negative_feedback_count';
    public const FIELD_PROBATION_NEGATIVE_FEEDBACK_PRESENT = 'probation_negative_feedback_present';
    public const FIELD_PROBATION_RECALL_ACTOR_COUNTS = 'probation_recall_actor_counts';
    public const FIELD_PROBATION_RECALL_SINGLE_ACTOR_INFLATED = 'probation_recall_single_actor_inflated';
    public const FIELD_PROBATION_UNEVALUATED = 'probation_unevaluated';
    public const FIELD_PROMOTION_BLOCKED_BY_POLICY = 'promotion_blocked_by_policy';
    public const FIELD_PROMOTION_MODE_UNRESOLVED = 'promotion_mode_unresolved';
    public const FIELD_SAFETY_UNEVALUATED = 'safety_unevaluated';
    public const FIELD_SCOPE_UNRESOLVED = 'scope_unresolved';
    public const FIELD_SECRET_OR_SENSITIVE_PRESENT = 'secret_or_sensitive_present';

    /**
     * @param  array<string,mixed>  $signals
     * @return array{
     *     schema_version: string,
     *     gate_statuses: array<string,string>,
     *     promotion_status: string,
     *     blocking_gate_ids: list<string>,
     *     pending_gate_ids: list<string>,
     *     reasons: array<string,string>,
     *     autonomous_promotion_allowed: bool
     * }
     */
    public function evaluate(array $signals): array
    {
        $candidatePresent = $this->flag($signals, self::FIELD_ATOMIC_CLAIM_PRESENT);

        $gateStatuses = [];
        $reasons = [];

        foreach (self::GATE_IDS as $gateId) {
            [$status, $reason] = $this->evaluateGate($gateId, $signals, $candidatePresent);
            $gateStatuses[$gateId] = $status;

            if ($status !== self::STATUS_PASS) {
                $reasons[$gateId] = $reason;
            }
        }

        $blockingGateIds = array_keys(array_filter(
            $gateStatuses,
            static fn (string $status): bool => $status === self::STATUS_BLOCK,
        ));
        $pendingGateIds = array_keys(array_filter(
            $gateStatuses,
            static fn (string $status): bool => $status === self::STATUS_PENDING,
        ));

        $promotionStatus = $this->resolvePromotionStatus(
            $gateStatuses,
            $blockingGateIds,
            $pendingGateIds,
        );

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GATE_STATUSES => $gateStatuses,
            self::FIELD_PROMOTION_STATUS => $promotionStatus,
            self::FIELD_BLOCKING_GATE_IDS => $blockingGateIds,
            self::FIELD_PENDING_GATE_IDS => $pendingGateIds,
            self::FIELD_REASONS => $reasons,
            self::FIELD_AUTONOMOUS_PROMOTION_ALLOWED => $promotionStatus === self::TRUST_BAND_TRUSTED,
        ];
    }

    /**
     * Thin contract wrapper; the pure array->array method above stays the core.
     *
     * @return array{
     *     schema_version: string,
     *     gate_statuses: array<string,string>,
     *     promotion_status: string,
     *     blocking_gate_ids: list<string>,
     *     pending_gate_ids: list<string>,
     *     reasons: array<string,string>,
     *     autonomous_promotion_allowed: bool
     * }
     */
    public function evaluateContract(CognitiveImmuneCheckContract $c): array
    {
        return $this->evaluate($c->toArray());
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function evaluateGate(string $gateId, array $signals, bool $candidatePresent): array
    {
        return match ($gateId) {
            'G0' => $this->captureGate($signals),
            'G1' => $this->extractionGate($signals),
            'G2' => $this->signalGate($signals),
            'G3' => $this->safetyGate($signals, $candidatePresent),
            'G4' => $this->contradictionGate($signals, $candidatePresent),
            'G5' => $this->outcomeGate($signals),
            'G6' => $this->scopeGate($signals),
            'G7' => $this->promotionModeGate($signals),
            'G8' => $this->probationGate($signals, $candidatePresent),
            default => [self::STATUS_PENDING, self::FIELD_GATE_UNKNOWN],
        };
    }

    /**
     * G0 Capture: consent, privacy class and retention all in order.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function captureGate(array $signals): array
    {
        $confirmed = $this->flag($signals, self::FIELD_CONSENT_GRANTED)
            && $this->flag($signals, self::FIELD_RETENTION_OK)
            && $this->nonEmptyString($signals, self::FIELD_PRIVACY_CLASS);

        return $confirmed
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_CONSENT_PRIVACY_RETENTION_UNCONFIRMED];
    }

    /**
     * G1 Extraction: atomic claim with type, scope and source.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function extractionGate(array $signals): array
    {
        $confirmed = $this->flag($signals, self::FIELD_ATOMIC_CLAIM_PRESENT)
            && $this->nonEmptyString($signals, self::FIELD_CLAIM_TYPE)
            && $this->flag($signals, self::FIELD_CLAIM_SOURCE_PRESENT)
            && $this->nonEmptyString($signals, self::FIELD_SCOPE);

        return $confirmed
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_ATOMIC_CLAIM_INCOMPLETE];
    }

    /**
     * G2 Signal: future utility, novelty or recurrence.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function signalGate(array $signals): array
    {
        $confirmed = $this->flag($signals, self::FIELD_FUTURE_UTILITY)
            || $this->flag($signals, self::FIELD_NOVELTY)
            || $this->intValue($signals, self::FIELD_RECURRENCE_COUNT) >= 2;

        return $confirmed
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_FUTURE_SIGNAL_UNCONFIRMED];
    }

    /**
     * G3 Safety (forbid-gate): provider-safe, no secret, no needless sensitive data.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function safetyGate(array $signals, bool $candidatePresent): array
    {
        $unsafe = $this->flag($signals, self::FIELD_CONTAINS_SECRET)
            || $this->flag($signals, self::FIELD_CONTAINS_SENSITIVE_UNNECESSARY)
            || $this->explicitlyFalse($signals, self::FIELD_PROVIDER_SAFE);

        if ($unsafe) {
            return [self::STATUS_BLOCK, self::FIELD_SECRET_OR_SENSITIVE_PRESENT];
        }

        return $candidatePresent
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_SAFETY_UNEVALUATED];
    }

    /**
     * G4 Contradiction (forbid-gate): conflicts with newer memory/code/docs/decision.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function contradictionGate(array $signals, bool $candidatePresent): array
    {
        if ($this->flag($signals, self::FIELD_CONTRADICTS_NEWER)) {
            return [self::STATUS_BLOCK, self::FIELD_CONTRADICTS_NEWER_AUTHORITY];
        }

        if ($this->flag($signals, self::FIELD_PROVENANCE_TRACES_TO_REVERTED)) {
            return [self::STATUS_BLOCK, 'provenance_traces_to_reverted'];
        }

        if ($this->flag($signals, self::FIELD_PROVENANCE_CYCLE_DETECTED)) {
            return [self::STATUS_BLOCK, 'provenance_cycle_detected'];
        }

        return $candidatePresent
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_CONTRADICTION_UNEVALUATED];
    }

    /**
     * G5 Outcome: validated by feedback, test, replay or use.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function outcomeGate(array $signals): array
    {
        return $this->flag($signals, self::FIELD_OUTCOME_VALIDATED)
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_OUTCOME_NOT_VALIDATED];
    }

    /**
     * G6 Scope: resolves to a recognised scope.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function scopeGate(array $signals): array
    {
        $scope = $this->stringValue($signals, self::FIELD_SCOPE);

        return in_array($scope, self::KNOWN_SCOPES, true)
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_SCOPE_UNRESOLVED];
    }

    /**
     * G7 Promotion Mode: auto, review, proposal or explicit block.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function promotionModeGate(array $signals): array
    {
        $mode = $this->stringValue($signals, self::FIELD_PROMOTION_MODE_HINT);

        if (in_array($mode, self::BLOCKED_PROMOTION_MODES, true)) {
            return [self::STATUS_BLOCK, self::FIELD_PROMOTION_BLOCKED_BY_POLICY];
        }

        return in_array($mode, self::ALLOWED_PROMOTION_MODES, true)
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, self::FIELD_PROMOTION_MODE_UNRESOLVED];
    }

    /**
     * G8 Probation: `watch` graduates to `trusted` only through calibrated evidence.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function probationGate(array $signals, bool $candidatePresent): array
    {
        if (! $candidatePresent) {
            return [self::STATUS_PENDING, self::FIELD_PROBATION_UNEVALUATED];
        }

        if ($this->hasProbationNegativeFeedback($signals)) {
            return [self::STATUS_PENDING, self::FIELD_PROBATION_NEGATIVE_FEEDBACK_PRESENT];
        }

        if ($this->hasProbationSuperveningContradiction($signals)) {
            return [self::STATUS_PENDING, 'probation_supervening_contradiction_present'];
        }

        if ($this->probationWatchAgeDays($signals) < ImmuneCalibrationService::TTL_DAYS) {
            return [self::STATUS_PENDING, 'probation_watch_time_below_calibrated_threshold'];
        }

        $recallEvidence = $this->probationRecallEvidence($signals);
        if ($recallEvidence[self::FIELD_POSITIVE_ACTOR_COUNT] < self::PROBATION_MIN_RECALL_ACTORS) {
            return [self::STATUS_PENDING, self::FIELD_PROBATION_RECALL_SINGLE_ACTOR_INFLATED];
        }

        if ($recallEvidence[self::FIELD_RECALLS] < ImmuneCalibrationService::DENOMINATOR_MIN) {
            return [self::STATUS_PENDING, 'probation_recall_below_calibrated_threshold'];
        }

        return [self::STATUS_PASS, ''];
    }

    /**
     * Ordered promotion-status resolution.
     *
     * @param  array<string,string>  $gateStatuses
     * @param  list<string>  $blockingGateIds
     * @param  list<string>  $pendingGateIds
     */
    private function resolvePromotionStatus(
        array $gateStatuses,
        array $blockingGateIds,
        array $pendingGateIds,
    ): string {
        if ($blockingGateIds !== []) {
            return self::TRUST_BAND_BLOCKED;
        }

        if ($pendingGateIds === []) {
            return self::TRUST_BAND_TRUSTED;
        }

        // No blocks and no captured atomic claim yet -> nothing to classify.
        if ($gateStatuses['G0'] !== self::STATUS_PASS || $gateStatuses['G1'] !== self::STATUS_PASS) {
            return self::TRUST_BAND_UNCLASSIFIED;
        }

        // Clean candidate held back solely by an open probation gate -> watch.
        if ($pendingGateIds === ['G8']) {
            return self::TRUST_BAND_WATCH;
        }

        return self::TRUST_BAND_CANDIDATE;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function flag(array $signals, string $key): bool
    {
        return ($signals[$key] ?? false) === true;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function explicitlyFalse(array $signals, string $key): bool
    {
        return array_key_exists($key, $signals) && $signals[$key] === false;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function nonEmptyString(array $signals, string $key): bool
    {
        return $this->stringValue($signals, $key) !== '';
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function stringValue(array $signals, string $key): string
    {
        return AiValueNormalizer::trimmedStringOrNull($signals[$key] ?? null) ?? '';
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function intValue(array $signals, string $key): int
    {
        return (int) (AiValueNormalizer::finiteFloatOrNull($signals[$key] ?? null) ?? 0);
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function hasProbationNegativeFeedback(array $signals): bool
    {
        foreach ([
            self::FIELD_PROBATION_NEGATIVE_FEEDBACK_COUNT,
            self::FIELD_RECALL_NEGATIVE_FEEDBACK,
            self::FIELD_ALL_TIME_RECALL_NEGATIVE_FEEDBACK,
            self::FIELD_NEGATIVE_FEEDBACK_COUNT,
        ] as $key) {
            if ($this->intValue($signals, $key) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function hasProbationSuperveningContradiction(array $signals): bool
    {
        return $this->flag($signals, self::FIELD_CONTRADICTS_NEWER)
            || $this->flag($signals, self::FIELD_PROBATION_SUPERVENING_CONTRADICTION)
            || $this->intValue($signals, self::FIELD_PROBATION_SUPERVENING_CONTRADICTION_COUNT) > 0;
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function probationWatchAgeDays(array $signals): int
    {
        if (array_key_exists(self::FIELD_PROBATION_WATCH_AGE_DAYS, $signals)) {
            return max(0, $this->intValue($signals, self::FIELD_PROBATION_WATCH_AGE_DAYS));
        }

        $startedAt = $this->timestampValue($signals, self::FIELD_PROBATION_ENTERED_AT)
            ?? $this->timestampValue($signals, self::FIELD_WATCH_STARTED_AT)
            ?? $this->timestampValue($signals, self::FIELD_PROBATION_STARTED_AT);
        $evaluatedAt = $this->timestampValue($signals, self::FIELD_PROBATION_EVALUATED_AT)
            ?? $this->timestampValue($signals, self::FIELD_EVALUATED_AT);

        if ($startedAt === null || $evaluatedAt === null || $evaluatedAt < $startedAt) {
            return 0;
        }

        return intdiv($evaluatedAt - $startedAt, 86400);
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function timestampValue(array $signals, string $key): ?int
    {
        $value = $signals[$key] ?? null;
        $value = AiValueNormalizer::trimmedStringOrNull($value);
        if ($value === null) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{recalls:int, positive_actor_count:int}
     */
    private function probationRecallEvidence(array $signals): array
    {
        $counts = $this->probationRecallActorCounts($signals);
        $recalls = 0;
        $positiveActorCount = 0;

        foreach ($counts as $count) {
            $count = max(0, $count);
            if ($count === 0) {
                continue;
            }

            $positiveActorCount++;
            $recalls += $count;
        }

        return [
            self::FIELD_RECALLS => $recalls,
            self::FIELD_POSITIVE_ACTOR_COUNT => $positiveActorCount,
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array<string,int>
     */
    private function probationRecallActorCounts(array $signals): array
    {
        foreach ([
            self::FIELD_PROBATION_RECALL_ACTOR_COUNTS,
            self::FIELD_RECALL_ACTOR_COUNTS,
            self::FIELD_RECALLS_BY_ACTOR,
        ] as $key) {
            $counts = $this->actorCountsFromValue($signals[$key] ?? null);
            if ($counts !== []) {
                return $counts;
            }
        }

        return $this->actorCountsFromConcentrationV2($signals[self::FIELD_RECALL_CONCENTRATION_V2] ?? null);
    }

    /**
     * @return array<string,int>
     */
    private function actorCountsFromValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $counts = [];
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $actor = $this->stringFromMixed($entry[self::FIELD_ACTOR] ?? $key);
                $count = $this->intFromMixed($entry[self::FIELD_COUNT] ?? $entry[self::FIELD_RECALLS] ?? 0);
            } else {
                $actor = $this->stringFromMixed($key);
                $count = $this->intFromMixed($entry);
            }

            if ($actor !== '') {
                $counts[$actor] = ($counts[$actor] ?? 0) + $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string,int>
     */
    private function actorCountsFromConcentrationV2(mixed $value): array
    {
        if (! is_array($value) || ! is_array($value[self::FIELD_PER_ACTOR] ?? null)) {
            return [];
        }

        return $this->actorCountsFromValue($value[self::FIELD_PER_ACTOR]);
    }

    private function stringFromMixed(mixed $value): string
    {
        return AiValueNormalizer::trimmedScalarStringOrNull($value) ?? '';
    }

    private function intFromMixed(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
