<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition;

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
    private const SCHEMA_VERSION = 'atlas.cognition.cognitive_immune_promotion_gate.v1';

    /** Canonical gate ids, ordered G0..G8. */
    private const GATE_IDS = ['G0', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];

    private const STATUS_PASS = 'pass';

    private const STATUS_BLOCK = 'block';

    private const STATUS_PENDING = 'pending';

    /** Forbid-gates: default pass on a real candidate, block on an explicit unsafe flag. */
    private const FORBID_GATE_IDS = ['G3', 'G4'];

    /** Recognised promotion scopes (G6). */
    private const KNOWN_SCOPES = ['global', 'workspace', 'project', 'task', 'domain', 'session'];

    /** Recognised non-blocking promotion modes (G7). */
    private const ALLOWED_PROMOTION_MODES = ['auto', 'review', 'human_review', 'proposal'];

    /** Promotion modes that explicitly forbid promotion (G7). */
    private const BLOCKED_PROMOTION_MODES = ['block', 'blocked'];

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
        $candidatePresent = $this->flag($signals, 'atomic_claim_present');

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
            'schema_version' => self::SCHEMA_VERSION,
            'gate_statuses' => $gateStatuses,
            'promotion_status' => $promotionStatus,
            'blocking_gate_ids' => array_values($blockingGateIds),
            'pending_gate_ids' => array_values($pendingGateIds),
            'reasons' => $reasons,
            'autonomous_promotion_allowed' => $promotionStatus === 'trusted',
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
            default => [self::STATUS_PENDING, 'gate_unknown'],
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
        $confirmed = $this->flag($signals, 'consent_granted')
            && $this->flag($signals, 'retention_ok')
            && $this->nonEmptyString($signals, 'privacy_class');

        return $confirmed
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'consent_privacy_retention_unconfirmed'];
    }

    /**
     * G1 Extraction: atomic claim with type, scope and source.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function extractionGate(array $signals): array
    {
        $confirmed = $this->flag($signals, 'atomic_claim_present')
            && $this->nonEmptyString($signals, 'claim_type')
            && $this->flag($signals, 'claim_source_present')
            && $this->nonEmptyString($signals, 'scope');

        return $confirmed
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'atomic_claim_incomplete'];
    }

    /**
     * G2 Signal: future utility, novelty or recurrence.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function signalGate(array $signals): array
    {
        $confirmed = $this->flag($signals, 'future_utility')
            || $this->flag($signals, 'novelty')
            || $this->intValue($signals, 'recurrence_count') >= 2;

        return $confirmed
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'future_signal_unconfirmed'];
    }

    /**
     * G3 Safety (forbid-gate): provider-safe, no secret, no needless sensitive data.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function safetyGate(array $signals, bool $candidatePresent): array
    {
        $unsafe = $this->flag($signals, 'contains_secret')
            || $this->flag($signals, 'contains_sensitive_unnecessary')
            || $this->explicitlyFalse($signals, 'provider_safe');

        if ($unsafe) {
            return [self::STATUS_BLOCK, 'secret_or_sensitive_present'];
        }

        return $candidatePresent
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'safety_unevaluated'];
    }

    /**
     * G4 Contradiction (forbid-gate): conflicts with newer memory/code/docs/decision.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function contradictionGate(array $signals, bool $candidatePresent): array
    {
        if ($this->flag($signals, 'contradicts_newer')) {
            return [self::STATUS_BLOCK, 'contradicts_newer_authority'];
        }

        return $candidatePresent
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'contradiction_unevaluated'];
    }

    /**
     * G5 Outcome: validated by feedback, test, replay or use.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function outcomeGate(array $signals): array
    {
        return $this->flag($signals, 'outcome_validated')
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'outcome_not_validated'];
    }

    /**
     * G6 Scope: resolves to a recognised scope.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function scopeGate(array $signals): array
    {
        $scope = $this->stringValue($signals, 'scope');

        return in_array($scope, self::KNOWN_SCOPES, true)
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'scope_unresolved'];
    }

    /**
     * G7 Promotion Mode: auto, review, proposal or explicit block.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function promotionModeGate(array $signals): array
    {
        $mode = $this->stringValue($signals, 'promotion_mode_hint');

        if (in_array($mode, self::BLOCKED_PROMOTION_MODES, true)) {
            return [self::STATUS_BLOCK, 'promotion_blocked_by_policy'];
        }

        return in_array($mode, self::ALLOWED_PROMOTION_MODES, true)
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'promotion_mode_unresolved'];
    }

    /**
     * G8 Probation: enters as `watch` before `trusted`; passes once probation cleared.
     *
     * @param  array<string,mixed>  $signals
     * @return array{0: string, 1: string}
     */
    private function probationGate(array $signals, bool $candidatePresent): array
    {
        if (! $candidatePresent) {
            return [self::STATUS_PENDING, 'probation_unevaluated'];
        }

        return $this->explicitlyFalse($signals, 'on_probation')
            ? [self::STATUS_PASS, '']
            : [self::STATUS_PENDING, 'probation_not_cleared'];
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
            return 'blocked';
        }

        if ($pendingGateIds === []) {
            return 'trusted';
        }

        // No blocks and no captured atomic claim yet -> nothing to classify.
        if ($gateStatuses['G0'] !== self::STATUS_PASS || $gateStatuses['G1'] !== self::STATUS_PASS) {
            return 'unclassified';
        }

        // Clean candidate held back solely by an open probation gate -> watch.
        if ($pendingGateIds === ['G8']) {
            return 'watch';
        }

        return 'candidate';
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
        $value = $signals[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param  array<string,mixed>  $signals
     */
    private function intValue(array $signals, string $key): int
    {
        $value = $signals[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }
}
