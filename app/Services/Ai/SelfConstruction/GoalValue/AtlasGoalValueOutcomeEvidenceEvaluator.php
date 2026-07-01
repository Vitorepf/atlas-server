<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\GoalValue;

/**
 * Pure evaluator that maps bounded receipt / gate / rollback / verification / learning refs into VALUE
 * FACTS. NEVER infers success from absence — missing evidence is reported as 'unknown' or 'blocked',
 * never silently converted into a positive value claim.
 *
 * VALUE CLASSES (each requires an EXPLICIT ref):
 *   capability_lift     — needs a receipt with kind=new_capability AND a passing verification ref
 *   failure_removal     — needs a receipt with kind=fix_failure AND a regression-test gate ref
 *   autonomy_lift       — needs a receipt with kind=autonomy_added AND a learning ref
 *   simplification      — needs a receipt with kind=simplification AND a passing verification ref
 *   reuse               — needs a receipt with kind=reuse_existing AND a code-index gate ref
 *   risk_reduction      — needs a receipt with kind=risk_reduced AND a passing verification ref
 *   quality_improvement — needs a receipt with kind=quality_improved AND a regression-test gate ref
 *
 * STATUS (real/partial/proxy/blocked/unknown impact — AC2):
 *   confirmed (real impact)   — receipt + supporting ref present, evidence_strength=strong (default)
 *   partial   (partial impact) — receipt + supporting ref present, but the receipt declares
 *                                 evidence_strength=weak: real but not fully proven
 *   proxy     (proxy impact)   — would otherwise be confirmed, but a discount_signals entry
 *                                 (AC4: cosmetic_wrapper, count_only_commit, proofless_green_test)
 *                                 applies to it — discounted OUT of goal value, never counted as real
 *   blocked   (blocked impact) — receipt present but supporting ref/delta missing or a regression
 *                                 signal fired
 *   unknown   (unknown impact) — no receipt of the required kind at all
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (facts sorted by class).
 *   - REPORTS one row per declared class with status ∈ {confirmed, partial, proxy, unknown, blocked} + reason.
 *   - NO scalar score / rank.
 */
final class AtlasGoalValueOutcomeEvidenceEvaluator
{
    public const SCHEMA = 'atlas.goalvalue.outcome_evidence.v1';

    public const CLASS_CAPABILITY_LIFT = 'capability_lift';

    public const CLASS_FAILURE_REMOVAL = 'failure_removal';

    public const CLASS_AUTONOMY_LIFT = 'autonomy_lift';

    public const CLASS_SIMPLIFICATION = 'simplification';

    public const CLASS_REUSE = 'reuse';

    public const CLASS_WORKER_CONTINUITY = 'worker_continuity';

    public const CLASS_RISK_REDUCTION = 'risk_reduction';

    public const CLASS_QUALITY_IMPROVEMENT = 'quality_improvement';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PROXY = 'proxy';

    /**
     * @param  array{
     *     receipts?:list<array{kind?:string, ref?:string}>,
     *     gates?:list<array{kind?:string, ref?:string, passed?:bool}>,
     *     rollback?:array{conformant?:bool},
     *     verification?:array{server_side_green?:bool, ref?:string},
     *     learning?:list<array{kind?:string, ref?:string}>
     * }  $facts
     * @return array{schema:string, value_facts:list<array{class:string, status:string, reason:string, evidence_refs:list<string>}>}
     */
    public function evaluate(array $facts): array
    {
        $receipts = is_array($facts['receipts'] ?? null) ? $facts['receipts'] : [];
        $gates = is_array($facts['gates'] ?? null) ? $facts['gates'] : [];
        $verification = is_array($facts['verification'] ?? null) ? $facts['verification'] : [];
        $learning = is_array($facts['learning'] ?? null) ? $facts['learning'] : [];
        $regressionSignals = is_array($facts['regression_signals'] ?? null)
            ? array_values(array_filter($facts['regression_signals'], 'is_array'))
            : [];
        $capabilityDelta = is_array($facts['capability_delta'] ?? null) ? $facts['capability_delta'] : [];
        $queueContinuityDelta = is_array($facts['queue_continuity_delta'] ?? null) ? $facts['queue_continuity_delta'] : [];
        $discountSignals = is_array($facts['discount_signals'] ?? null)
            ? array_values(array_filter($facts['discount_signals'], 'is_array'))
            : [];

        $valueFacts = [];

        // capability_lift: receipt + verification + non-empty capability_delta (before+after).
        $capLiftFact = $this->classFact(
            self::CLASS_CAPABILITY_LIFT,
            $this->findReceipt($receipts, 'new_capability'),
            (bool) ($verification['server_side_green'] ?? false),
            'verification ref absent or not server_side_green',
            (string) ($verification['ref'] ?? ''),
            $this->receiptEvidenceStrength($receipts, 'new_capability'),
        );
        if ($capLiftFact['status'] === self::STATUS_CONFIRMED) {
            $before = trim((string) ($capabilityDelta['before'] ?? ''));
            $after = trim((string) ($capabilityDelta['after'] ?? ''));
            if ($before === '' || $after === '') {
                $capLiftFact = ['class' => self::CLASS_CAPABILITY_LIFT, 'status' => self::STATUS_BLOCKED, 'reason' => 'capability_delta missing: before/after required', 'evidence_refs' => $capLiftFact['evidence_refs']];
            }
        }
        $valueFacts[] = $capLiftFact;

        $valueFacts[] = $this->classFact(
            self::CLASS_FAILURE_REMOVAL,
            $this->findReceipt($receipts, 'fix_failure'),
            $this->hasPassingGate($gates, 'regression_test'),
            'regression_test gate absent or not passing',
            $this->firstRef($gates, 'regression_test'),
            $this->receiptEvidenceStrength($receipts, 'fix_failure'),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_AUTONOMY_LIFT,
            $this->findReceipt($receipts, 'autonomy_added'),
            $this->hasLearningKind($learning, 'autonomy_lift'),
            'autonomy_lift learning ref absent',
            $this->firstLearningRef($learning, 'autonomy_lift'),
            $this->receiptEvidenceStrength($receipts, 'autonomy_added'),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_SIMPLIFICATION,
            $this->findReceipt($receipts, 'simplification'),
            (bool) ($verification['server_side_green'] ?? false),
            'verification ref absent or not server_side_green',
            (string) ($verification['ref'] ?? ''),
            $this->receiptEvidenceStrength($receipts, 'simplification'),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_REUSE,
            $this->findReceipt($receipts, 'reuse_existing'),
            $this->hasPassingGate($gates, 'code_index'),
            'code_index gate absent or not passing',
            $this->firstRef($gates, 'code_index'),
            $this->receiptEvidenceStrength($receipts, 'reuse_existing'),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_RISK_REDUCTION,
            $this->findReceipt($receipts, 'risk_reduced'),
            (bool) ($verification['server_side_green'] ?? false),
            'verification ref absent or not server_side_green',
            (string) ($verification['ref'] ?? ''),
            $this->receiptEvidenceStrength($receipts, 'risk_reduced'),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_QUALITY_IMPROVEMENT,
            $this->findReceipt($receipts, 'quality_improved'),
            $this->hasPassingGate($gates, 'regression_test'),
            'regression_test gate absent or not passing',
            $this->firstRef($gates, 'regression_test'),
            $this->receiptEvidenceStrength($receipts, 'quality_improved'),
        );

        // worker_continuity: receipt + passing queue_continuity gate + measured
        // before/after improvement. NEVER inferred from absence — missing queue
        // evidence blocks the claim rather than confirming it.
        $workerContinuityFact = $this->classFact(
            self::CLASS_WORKER_CONTINUITY,
            $this->findReceipt($receipts, 'worker_continuity'),
            $this->hasPassingGate($gates, 'queue_continuity'),
            'queue_continuity gate absent or not passing',
            $this->firstRef($gates, 'queue_continuity'),
        );
        if ($workerContinuityFact['status'] === self::STATUS_CONFIRMED) {
            if (! $this->queueContinuityImproved($queueContinuityDelta)) {
                $workerContinuityFact = [
                    'class' => self::CLASS_WORKER_CONTINUITY,
                    'status' => self::STATUS_BLOCKED,
                    'reason' => 'queue_continuity_delta missing or does not show claimable_per_active_worker improvement or no_claimable_task incident drop',
                    'evidence_refs' => $workerContinuityFact['evidence_refs'],
                ];
            }
        }
        $valueFacts[] = $workerContinuityFact;

        // Regression signals block any confirmed verdict and preserve evidence_refs.
        if ($regressionSignals !== []) {
            $regRefs = array_values(array_filter(array_map(static fn (array $s): string => (string) ($s['ref'] ?? ''), $regressionSignals)));
            $valueFacts = array_map(static function (array $fact) use ($regRefs): array {
                if ($fact['status'] === self::STATUS_CONFIRMED) {
                    $fact['status'] = self::STATUS_BLOCKED;
                    $fact['reason'] = 'regression_signal_present';
                    $fact['evidence_refs'] = array_values(array_unique(array_merge($fact['evidence_refs'], $regRefs)));
                }

                return $fact;
            }, $valueFacts);
        }

        // AC4: discount cosmetic wrappers, count-only commits and proofless green tests from goal
        // value — a class that would otherwise be confirmed is downgraded to proxy_impact, never
        // counted as real. Runs after the regression-signal pass so a genuine regression always
        // wins over a cosmetic discount when both fire on the same class.
        if ($discountSignals !== []) {
            $discountRefs = array_values(array_filter(array_map(static fn (array $s): string => (string) ($s['ref'] ?? ''), $discountSignals)));
            $applicableClasses = array_values(array_filter(array_map(static fn (array $s): string => (string) ($s['applies_to_class'] ?? ''), $discountSignals)));
            $discountKinds = array_values(array_unique(array_map(static fn (array $s): string => (string) ($s['kind'] ?? 'cosmetic_wrapper'), $discountSignals)));

            $valueFacts = array_map(static function (array $fact) use ($discountRefs, $applicableClasses, $discountKinds): array {
                if ($fact['status'] !== self::STATUS_CONFIRMED && $fact['status'] !== self::STATUS_PARTIAL) {
                    return $fact;
                }
                if ($applicableClasses !== [] && ! in_array($fact['class'], $applicableClasses, true)) {
                    return $fact;
                }
                $fact['status'] = self::STATUS_PROXY;
                $fact['reason'] = 'discounted: '.implode(',', $discountKinds);
                $fact['evidence_refs'] = array_values(array_unique(array_merge($fact['evidence_refs'], $discountRefs)));

                return $fact;
            }, $valueFacts);
        }

        usort($valueFacts, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return [
            'schema' => self::SCHEMA,
            'value_facts' => $valueFacts,
        ];
    }

    /**
     * @param  array<string,mixed>  $delta  may contain claimable_per_active_worker_before/_after
     *   and/or no_claimable_task_incidents_before/_after; both numeric pairs must be PRESENT
     *   (never assumed) for an improvement claim.
     */
    private function queueContinuityImproved(array $delta): bool
    {
        $claimableBefore = $delta['claimable_per_active_worker_before'] ?? null;
        $claimableAfter = $delta['claimable_per_active_worker_after'] ?? null;
        if (is_numeric($claimableBefore) && is_numeric($claimableAfter) && (float) $claimableAfter > (float) $claimableBefore) {
            return true;
        }

        $incidentsBefore = $delta['no_claimable_task_incidents_before'] ?? null;
        $incidentsAfter = $delta['no_claimable_task_incidents_after'] ?? null;
        if (is_numeric($incidentsBefore) && is_numeric($incidentsAfter) && (float) $incidentsAfter < (float) $incidentsBefore) {
            return true;
        }

        return false;
    }

    /**
     * @return array{class:string, status:string, reason:string, evidence_refs:list<string>}
     */
    private function classFact(string $class, ?string $receiptRef, bool $supportingRefOk, string $blockedReason, string $supportingRef, string $evidenceStrength = 'strong'): array
    {
        if ($receiptRef === null) {
            return ['class' => $class, 'status' => self::STATUS_UNKNOWN, 'reason' => 'no receipt of required kind', 'evidence_refs' => []];
        }
        if (! $supportingRefOk || $supportingRef === '') {
            return ['class' => $class, 'status' => self::STATUS_BLOCKED, 'reason' => $blockedReason, 'evidence_refs' => [$receiptRef]];
        }
        $refs = array_values(array_filter([$receiptRef, $supportingRef], static fn (string $r): bool => $r !== ''));

        if ($evidenceStrength === 'weak') {
            return ['class' => $class, 'status' => self::STATUS_PARTIAL, 'reason' => 'receipt + supporting ref present but evidence_strength=weak', 'evidence_refs' => $refs];
        }

        return ['class' => $class, 'status' => self::STATUS_CONFIRMED, 'reason' => 'receipt + supporting ref present', 'evidence_refs' => $refs];
    }

    /**
     * @param  list<array<string,mixed>>  $receipts
     */
    private function receiptEvidenceStrength(array $receipts, string $kind): string
    {
        foreach ($receipts as $r) {
            if (is_array($r) && (string) ($r['kind'] ?? '') === $kind) {
                return (string) ($r['evidence_strength'] ?? 'strong');
            }
        }

        return 'strong';
    }

    /**
     * @param  list<array<string,mixed>>  $receipts
     */
    private function findReceipt(array $receipts, string $kind): ?string
    {
        foreach ($receipts as $r) {
            if (is_array($r) && (string) ($r['kind'] ?? '') === $kind && (string) ($r['ref'] ?? '') !== '') {
                return (string) $r['ref'];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $gates
     */
    private function hasPassingGate(array $gates, string $kind): bool
    {
        foreach ($gates as $g) {
            if (is_array($g) && (string) ($g['kind'] ?? '') === $kind && ($g['passed'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $gates
     */
    private function firstRef(array $gates, string $kind): string
    {
        foreach ($gates as $g) {
            if (is_array($g) && (string) ($g['kind'] ?? '') === $kind) {
                return (string) ($g['ref'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  list<array<string,mixed>>  $learning
     */
    private function hasLearningKind(array $learning, string $kind): bool
    {
        foreach ($learning as $l) {
            if (is_array($l) && (string) ($l['kind'] ?? '') === $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $learning
     */
    private function firstLearningRef(array $learning, string $kind): string
    {
        foreach ($learning as $l) {
            if (is_array($l) && (string) ($l['kind'] ?? '') === $kind) {
                return (string) ($l['ref'] ?? '');
            }
        }

        return '';
    }
}
