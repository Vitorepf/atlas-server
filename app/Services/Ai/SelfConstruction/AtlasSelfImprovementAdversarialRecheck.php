<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Throwable;

/**
 * S3.F4 — the ADVERSARIAL RE-CHECK (the out-of-process Goodhart guard of the loop).
 *
 * THE DISCIPLINE IT ENCODES: the loop-proposal-adversarial-verify spirit, in-code and
 * cheap. A self-improvement that passed GENERATION and the RELEVANCE GATE is not yet
 * trusted — an INDEPENDENT, default-refute re-check runs BEFORE it is surfaced to the
 * operator as worthy. Only a branch that survives BOTH the gate AND this re-check is
 * presented as accepted; one that passes the gate but fails the independent re-check is
 * marked needs_review (the branch is kept for the operator to inspect, but never claimed
 * as a vetted on-target improvement).
 *
 * WHY IT IS INDEPENDENT / NOT GAMEABLE BY THE GENERATION (nor by the first gate run):
 *   - it constructs a FRESH {@see AtlasSelfImprovementRelevanceGate} (no embedding
 *     engine — the deterministic, side-effect-free decision function) and RE-EVALUATES
 *     the SAME facts: the signal vs the delivery's touched files + content. A first-gate
 *     PASS that does not reproduce on the independent re-run (different verdict, a path
 *     that no longer matches, content that no longer clears the floor) is treated as
 *     UNSTABLE → refuse. The provider cannot influence a second deterministic comparison
 *     of fact sets it does not control any more than the first.
 *   - it CONFIRMS the success-metric: if the delivery carries a measure result
 *     (the focused test the materializer ran on the branch), the re-check requires it
 *     to have PASSED. A gate-passed branch whose own measure FAILED is not worthy —
 *     refuse (needs_review). An ABSENT measure is admitted (nothing to confirm) so the
 *     F1/F2 path-only envelopes keep working; only a PRESENT-AND-FAILED measure refutes.
 *   - DEFAULT-REFUTE-IF-UNCERTAIN: any exception, a malformed delivery, or an
 *     unverifiable state yields confirmed=false (needs_review), never a fabricated pass.
 *
 * COST: zero. No LLM call, no IO, no store access — a pure deterministic re-derivation
 * over data the loop already holds. Idempotent: same inputs ⇒ same verdict.
 *
 * SCOPE (deliberately narrow): this re-check NEVER merges, NEVER pushes, NEVER deletes a
 * branch and NEVER mutates the brain. It returns a verdict; the loop decides what to do
 * with it (surface as accepted, or hold as needs_review). It is a read-only adjudicator.
 */
final class AtlasSelfImprovementAdversarialRecheck
{
    public const SCHEMA = 'atlas.ai.self_improvement_adversarial_recheck.v1';

    /**
     * Independently re-verify a gate-passed self-improvement before it is surfaced.
     *
     * @param  array<string,mixed>  $signal  the detector signal that drove the cycle
     * @param  array<string,mixed>  $delivery  the mission/orchestrator envelope (delivered, delivery.files, measure)
     * @param  array<string,mixed>  $firstVerdict  the relevance gate's first verdict for this delivery
     * @return array{
     *     confirmed:bool,
     *     reason:string,
     *     recheck_relevant:bool,
     *     verdict_stable:bool,
     *     measure_confirmed:?bool,
     *     schema_version:string
     * }
     */
    public function recheck(array $signal, array $delivery, array $firstVerdict): array
    {
        // Default-refute baseline: nothing is confirmed until each independent check
        // affirmatively passes. Any early return below is therefore a refusal.
        try {
            // 1) INDEPENDENT relevance re-run — a FRESH gate (no engine: the
            //    deterministic decision function), re-evaluating the same facts. The
            //    first verdict is NOT trusted; it must REPRODUCE.
            $reVerdict = (new AtlasSelfImprovementRelevanceGate)->evaluate($signal, $delivery);
            $recheckRelevant = (bool) ($reVerdict['relevant'] ?? false);

            // Stability: the independent re-run must AGREE with the first verdict's
            // relevance. A flip (first pass, re-check fail — or vice versa) means the
            // verdict is not reproducible ⇒ refuse (needs_review).
            $firstRelevant = (bool) ($firstVerdict['relevant'] ?? false);
            $stable = $recheckRelevant === $firstRelevant;

            if (! $recheckRelevant) {
                // The independent gate does not consider it relevant — refuse,
                // regardless of the first verdict (default-refute).
                return $this->verdict(false, 'recheck_relevance_failed', false, $stable, null);
            }
            if (! $stable) {
                // It re-checks relevant but DISAGREED with the first verdict — an
                // unstable verdict is not trustworthy. Refuse.
                return $this->verdict(false, 'verdict_unstable', $recheckRelevant, false, null);
            }

            // 2) MEASURE confirmation — if the delivery ran a focused success-metric on
            //    the branch, it MUST have passed. Absent measure ⇒ nothing to confirm
            //    (admitted). Present-and-failed ⇒ refuse.
            $measureConfirmed = $this->confirmMeasure($delivery);
            if ($measureConfirmed === false) {
                return $this->verdict(false, 'measure_failed', $recheckRelevant, $stable, false);
            }

            // Survived BOTH independent checks → confirmed worthy.
            return $this->verdict(true, 'confirmed', $recheckRelevant, $stable, $measureConfirmed);
        } catch (Throwable) {
            // DEFAULT-REFUTE on any uncertainty — never a fabricated confirmation.
            return $this->verdict(false, 'recheck_exception', false, false, null);
        }
    }

    /**
     * Confirm the branch's own success-metric, if one was run.
     *
     * @param  array<string,mixed>  $delivery
     * @return bool|null true = measure ran and passed; false = ran and FAILED;
     *                   null = no measure to confirm (admitted — nothing refutes).
     */
    private function confirmMeasure(array $delivery): ?bool
    {
        // The materializer attaches the measure under materialization.measure; the
        // mission/orchestrator may also surface it at delivery.measure. Read both, but
        // ONLY treat it as present when it actually RAN (a measure that did not run is
        // not a failure — there is simply nothing to confirm).
        $materialization = (array) ($delivery['materialization'] ?? []);
        $measure = (array) ($materialization['measure'] ?? ($delivery['measure'] ?? []));

        if ($measure === []) {
            return null; // no measure attached → nothing to confirm
        }
        // A measure object that did not run carries no verdict to confirm/refute.
        if (array_key_exists('ran', $measure) && $measure['ran'] === false) {
            return null;
        }
        if (array_key_exists('passed', $measure)) {
            return (bool) $measure['passed'];
        }
        if (array_key_exists('ok', $measure)) {
            return (bool) $measure['ok'];
        }

        // A measure with no readable pass/fail signal is unverifiable → default-refute
        // requires we NOT confirm it. Treat as failed so an opaque measure cannot pass
        // unexamined (the operator reviews it as needs_review).
        return false;
    }

    /**
     * @return array{
     *     confirmed:bool,
     *     reason:string,
     *     recheck_relevant:bool,
     *     verdict_stable:bool,
     *     measure_confirmed:?bool,
     *     schema_version:string
     * }
     */
    private function verdict(
        bool $confirmed,
        string $reason,
        bool $recheckRelevant,
        bool $stable,
        ?bool $measureConfirmed,
    ): array {
        return [
            'confirmed' => $confirmed,
            'reason' => $reason,
            'recheck_relevant' => $recheckRelevant,
            'verdict_stable' => $stable,
            'measure_confirmed' => $measureConfirmed,
            'schema_version' => self::SCHEMA,
        ];
    }
}
