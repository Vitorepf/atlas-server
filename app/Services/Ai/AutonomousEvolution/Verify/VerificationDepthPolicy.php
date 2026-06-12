<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

/**
 * O-9 (Verification OS): proof depth proportional to risk, as a STRUCTURAL stage — not a
 * per-session judgment. Given a change's class + risk, it returns the required proof
 * obligations a candidate must satisfy before it can be certified/merged. Pure +
 * deterministic so the obligation set is auditable and frozen.
 *
 * The ladder of obligations (each level ADDS to the ones below):
 *   FROZEN_TEST          — a frozen acceptance test passes (the floor; always required).
 *   ADVERSARIAL_REVIEW   — an out-of-process adversarial pass (a second engine tries to
 *                          refute the change), for non-trivial / shared-surface changes.
 *   INDEPENDENT_REFUTERS — N independent refuters must FAIL to break it (high risk).
 *   OPERATOR_KEY         — the operator turns the key; never automatable.
 *
 * Two invariants that encode the merge-livre policy (O-3):
 *   - JUDGE/GATE/IMMUNE code (the measurement system itself) ALWAYS escalates to
 *     reinforced verification — a broken judge silently blinds fix-forward (exception 1).
 *   - AUTONOMY-WIDENING changes ALWAYS require the operator key (exception 2); the system
 *     never grants itself more autonomy.
 */
final class VerificationDepthPolicy
{
    public const SCHEMA = 'atlas.ai.verification_depth_policy.v1';

    public const FROZEN_TEST = 'frozen_test';
    public const ADVERSARIAL_REVIEW = 'adversarial_review';
    public const INDEPENDENT_REFUTERS = 'independent_refuters';
    public const OPERATOR_KEY = 'operator_key';

    /** Change classes whose code IS the measurement/judge surface — always reinforced. */
    private const JUDGE_SURFACE_CLASSES = ['gate', 'judge', 'immune', 'measurement', 'eval_gate', 'verification'];

    /** Change classes that widen autonomy — always operator-keyed. */
    private const AUTONOMY_CLASSES = ['autonomy', 'autonomy_widening', 'policy', 'merge_policy'];

    /**
     * @param  string  $changeClass  e.g. docs, tests, bugfix, cleanup, code, gate, autonomy
     * @param  string  $risk         low | medium | high | critical
     * @return array{schema_version:string, change_class:string, risk:string, obligations:list<string>, refuter_count:int, requires_operator_key:bool, reason:string}
     */
    public function obligationsFor(string $changeClass, string $risk = 'low'): array
    {
        $class = strtolower(trim($changeClass));
        $risk = strtolower(trim($risk));

        $obligations = [self::FROZEN_TEST]; // floor: always
        $refuters = 0;
        $reason = 'baseline_frozen_test';

        $isJudgeSurface = in_array($class, self::JUDGE_SURFACE_CLASSES, true);
        $isAutonomy = in_array($class, self::AUTONOMY_CLASSES, true);

        // Risk escalation (medium+ adds adversarial review; high/critical adds refuters).
        if (in_array($risk, ['medium', 'high', 'critical'], true) || ! $this->isTrivialClass($class)) {
            $obligations[] = self::ADVERSARIAL_REVIEW;
            $reason = 'non_trivial_or_elevated_risk';
        }
        if (in_array($risk, ['high', 'critical'], true)) {
            $obligations[] = self::INDEPENDENT_REFUTERS;
            $refuters = $risk === 'critical' ? 3 : 2;
            $reason = 'high_risk_independent_refuters';
        }

        // Exception 1 — judge/gate/immune code: ALWAYS reinforced, regardless of stated risk.
        if ($isJudgeSurface) {
            $this->ensure($obligations, self::ADVERSARIAL_REVIEW);
            $this->ensure($obligations, self::INDEPENDENT_REFUTERS);
            $refuters = max($refuters, 3);
            $reason = 'judge_surface_reinforced';
        }

        // Exception 2 — autonomy-widening: ALWAYS the operator key (never automatable).
        $requiresOperatorKey = $isAutonomy;
        if ($isAutonomy) {
            $this->ensure($obligations, self::OPERATOR_KEY);
            $reason = 'autonomy_widening_operator_key';
        }

        return [
            'schema_version' => self::SCHEMA,
            'change_class' => $class,
            'risk' => $risk,
            'obligations' => array_values($obligations),
            'refuter_count' => $refuters,
            'requires_operator_key' => $requiresOperatorKey,
            'reason' => $reason,
        ];
    }

    /** Trivial classes whose floor is just a frozen test (docs/tests/formatting). */
    private function isTrivialClass(string $class): bool
    {
        return in_array($class, ['docs', 'documentation', 'documentation_only', 'tests', 'tests_only', 'formatting', 'comment'], true);
    }

    /**
     * @param  list<string>  $obligations
     */
    private function ensure(array &$obligations, string $obligation): void
    {
        if (! in_array($obligation, $obligations, true)) {
            $obligations[] = $obligation;
        }
    }
}
