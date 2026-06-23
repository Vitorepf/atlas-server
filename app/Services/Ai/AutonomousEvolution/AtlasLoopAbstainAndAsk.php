<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * §5 · ABSTAIN-AND-ASK — the model-bound frontier cerca (honest, NEVER Goodhart).
 *
 * The loop's deterministic gates (grounding-veto, anti-farm floor, frozen judge) prove what is REAL. But at
 * the frontier — a NOVEL evolution with no precedent, a decision the model is not confident in, a citation the
 * inventory can't ground — the only honest moves are: park it for the operator, or proceed. The forbidden move
 * is to FABRICATE a confident decision to keep the funnel moving (the exact Goodhart failure the operator
 * drilled: "nunca finge"). This gate makes the honest choice mechanical: ANY uncertainty trigger ⇒ ABSTAIN
 * (park + one precise operator question), and it can NEVER emit a fabricated "proceed" — an abstain always
 * carries a question, a proceed only fires when the decision is grounded AND confident AND not novel-without-
 * precedent.
 *
 * Pure + deterministic (no provider, no I/O). It does not DECIDE the frontier; it decides whether the loop is
 * ALLOWED to decide it autonomously, or must hand the wheel to the operator.
 */
final class AtlasLoopAbstainAndAsk
{
    /** Proceed autonomously — grounded, confident, and not novel-without-precedent. */
    public const ACTION_PROCEED = 'proceed';

    /** Hand the wheel to the operator (park + ask) — the loop is at its honest frontier. NEVER fabricate. */
    public const ACTION_ABSTAIN = 'abstain';

    /**
     * @param  bool  $proceedOnGroundedNovelty  When true, NOVELTY alone no longer forces an abstain: a GROUNDED
     *   + CONFIDENT novel decision PROCEEDS (the loop ORIGINATES the leap — green scope is the trigger to
     *   originate, not to ask). The honest floor moves DOWNSTREAM: the architect gate designs it red→green and
     *   the cert/refute pipeline proves it is actually behaviour-changing — a fabricated leap fails there.
     *   Genuine ambiguity (ungrounded / low-confidence) STILL abstains. Default false = byte-identical: novelty
     *   parks-and-asks. This is the operator's autonomous-self-engineer directive (2026-06-22).
     */
    public function __construct(
        private readonly float $confidenceFloor = 0.7,
        private readonly bool $proceedOnGroundedNovelty = false,
    ) {
    }

    /**
     * @param  array{grounded?:bool, confidence?:float, novel?:bool, has_precedent?:bool, summary?:string}  $decision
     * @return array{action:string, reasons:list<string>, operator_question:?string}
     */
    public function evaluate(array $decision): array
    {
        $grounded = ($decision['grounded'] ?? false) === true;
        $confidence = (float) ($decision['confidence'] ?? 0.0);
        $novel = ($decision['novel'] ?? false) === true;
        $hasPrecedent = ($decision['has_precedent'] ?? false) === true;
        $summary = trim((string) ($decision['summary'] ?? ''));

        // Honesty triggers — ANY one forces an abstain. A frontier decision is never faked.
        $reasons = [];
        if (! $grounded) {
            $reasons[] = 'ungrounded'; // a citation/claim the deterministic gates couldn't ground
        }
        if ($confidence < $this->confidenceFloor) {
            $reasons[] = 'low_confidence:'.rtrim(rtrim(number_format($confidence, 2), '0'), '.').'<'.$this->confidenceFloor;
        }
        if ($novel && ! $hasPrecedent && ! $this->proceedOnGroundedNovelty) {
            // greenfield with no certified precedent. By default this parks-and-asks. With
            // proceedOnGroundedNovelty the loop ORIGINATES it instead (still grounded + confident above);
            // the architect red→green design + cert/refute downstream are what prove it is real material.
            $reasons[] = 'novel_no_precedent';
        }

        if ($reasons !== []) {
            return [
                'action' => self::ACTION_ABSTAIN,
                'reasons' => $reasons,
                'operator_question' => $this->question($summary, $reasons),
            ];
        }

        return ['action' => self::ACTION_PROCEED, 'reasons' => [], 'operator_question' => null];
    }

    /** @param list<string> $reasons */
    private function question(string $summary, array $reasons): string
    {
        $what = $summary !== '' ? $summary : 'an unspecified loop decision';

        return sprintf(
            'Operator decision needed on "%s" — the loop ABSTAINED (%s) rather than guess. Approve, redirect, or reject?',
            $what,
            implode(', ', $reasons),
        );
    }
}
