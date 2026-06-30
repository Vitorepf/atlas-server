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
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope (facts sorted by class).
 *   - REPORTS one row per declared class with status ∈ {confirmed, unknown, blocked} + reason.
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

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_BLOCKED = 'blocked';

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

        $valueFacts = [];

        // capability_lift: receipt + verification + non-empty capability_delta (before+after).
        $capLiftFact = $this->classFact(
            self::CLASS_CAPABILITY_LIFT,
            $this->findReceipt($receipts, 'new_capability'),
            (bool) ($verification['server_side_green'] ?? false),
            'verification ref absent or not server_side_green',
            (string) ($verification['ref'] ?? ''),
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
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_AUTONOMY_LIFT,
            $this->findReceipt($receipts, 'autonomy_added'),
            $this->hasLearningKind($learning, 'autonomy_lift'),
            'autonomy_lift learning ref absent',
            $this->firstLearningRef($learning, 'autonomy_lift'),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_SIMPLIFICATION,
            $this->findReceipt($receipts, 'simplification'),
            (bool) ($verification['server_side_green'] ?? false),
            'verification ref absent or not server_side_green',
            (string) ($verification['ref'] ?? ''),
        );
        $valueFacts[] = $this->classFact(
            self::CLASS_REUSE,
            $this->findReceipt($receipts, 'reuse_existing'),
            $this->hasPassingGate($gates, 'code_index'),
            'code_index gate absent or not passing',
            $this->firstRef($gates, 'code_index'),
        );

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

        usort($valueFacts, static fn (array $a, array $b): int => strcmp($a['class'], $b['class']));

        return [
            'schema' => self::SCHEMA,
            'value_facts' => $valueFacts,
        ];
    }

    /**
     * @return array{class:string, status:string, reason:string, evidence_refs:list<string>}
     */
    private function classFact(string $class, ?string $receiptRef, bool $supportingRefOk, string $blockedReason, string $supportingRef): array
    {
        if ($receiptRef === null) {
            return ['class' => $class, 'status' => self::STATUS_UNKNOWN, 'reason' => 'no receipt of required kind', 'evidence_refs' => []];
        }
        if (! $supportingRefOk || $supportingRef === '') {
            return ['class' => $class, 'status' => self::STATUS_BLOCKED, 'reason' => $blockedReason, 'evidence_refs' => [$receiptRef]];
        }
        $refs = array_values(array_filter([$receiptRef, $supportingRef], static fn (string $r): bool => $r !== ''));

        return ['class' => $class, 'status' => self::STATUS_CONFIRMED, 'reason' => 'receipt + supporting ref present', 'evidence_refs' => $refs];
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
