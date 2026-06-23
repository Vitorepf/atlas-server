<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * §5.6 · LAYER 2 — the full "decide by ORIGINATING, then DESIGN" flow.
 *
 * {@see AtlasLoopComprehensionOriginator} ORIGINATES a grounded evolution (writer ≠ judge, every citation an
 * inventory member). But an origination is only WORK once it has been DESIGNED as a principal engineer. This
 * pipeline composes the two: originate → resolve the primary cited symbol to its real scope path → run the
 * {@see AtlasLoopArchitectPhaseGate} so the origination carries a converged design contract (caller
 * protection + the work-type's mandatory proof) or is suppressed (pétreo / blast-radius). So the brain's
 * "decide" phase ORIGINATES new work AND designs it before it can grind — never a raw, undesigned proposal.
 *
 * Fail-closed at every seam: no grounded origination, an unresolvable target, or a non-converged design ⇒ no
 * produced work. Deterministic except the writer (the model-bound origination, §9-fenced inside the originator).
 */
final class AtlasLoopOriginationPipeline
{
    public function __construct(
        private readonly ?AtlasLoopComprehensionOriginator $originator = null,
        private readonly ?AtlasLoopArchitectPhaseGate $gate = null,
    ) {
    }

    /**
     * @param  list<string>  $priorAttempts  campaign targets that did not converge — passed to the originator
     *                                        as CONTEXT (informs the writer, never vetoes). §5 learning.
     * @return array{produced:bool, objective:?string, target_path:?string, obligations:list<array<string,mixed>>, reason:?string}
     */
    public function produce(AtlasLoopScopeComprehensionModel $model, string $repoRoot, array $priorAttempts = []): array
    {
        $origination = ($this->originator ?? new AtlasLoopComprehensionOriginator)->originate($model, $priorAttempts);
        if (($origination['originated'] ?? false) !== true) {
            return $this->refuse((string) ($origination['reason'] ?? 'not_originated'));
        }

        $target = $this->resolveTarget($model, (array) ($origination['cited_symbols'] ?? []));
        if ($target === null) {
            return $this->refuse('no_resolvable_inventory_target'); // cited a real symbol but none maps to a scope path
        }

        // DESIGN the originated evolution as a feature (red→green) — caller protection + work-type proof, or PARK.
        $verdict = ($this->gate ?? new AtlasLoopArchitectPhaseGate)->admit($model, $target, 'feature', $repoRoot);
        if (($verdict['admitted'] ?? false) !== true) {
            return $this->refuse((string) ($verdict['reason'] ?? 'design_not_converged'));
        }

        // §5 ABSTAIN-AND-ASK — the frontier cerca. A free cross-model origination is grounded + designed, but
        // it is a NOVEL decision (the model proposed it freely). The honest move is to PARK + ASK the operator
        // unless the target already has real consumers (a modification WITH precedent, not greenfield). The
        // loop never fabricates a confident "proceed" on a greenfield origination.
        $hasPrecedent = count((array) ($verdict['consumer_contracts'] ?? [])) > 0;
        // The operator's autonomous-self-engineer directive: on a GREEN scope the loop ORIGINATES the next
        // material leap instead of parking-and-asking. With proceed_on_grounded_novelty ON, a grounded +
        // designed (architect-admitted) novel origination PROCEEDS; the red→green obligation + cert/refute
        // downstream are the Goodhart floor. Default OFF ⇒ byte-identical (novelty parks-and-asks).
        $proceedOnNovelty = (bool) config('atlas.loop.proceed_on_grounded_novelty_enabled', false);
        $frontier = (new AtlasLoopAbstainAndAsk(0.7, $proceedOnNovelty))->evaluate([
            'grounded' => true,             // it cleared the inventory grounding-veto
            'confidence' => 1.0,            // the deterministic gates (grounding + design) are satisfied
            'novel' => true,               // a free origination has no supply-lane precedent of its own
            'has_precedent' => $hasPrecedent,
            'summary' => (string) ($origination['objective'] ?? ''),
        ]);

        return [
            'produced' => true,
            'action' => $frontier['action'],                       // proceed | abstain (park + ask the operator)
            'operator_question' => $frontier['operator_question'], // non-null ⇒ the loop is asking, not guessing
            'objective' => (string) ($origination['objective'] ?? ''),
            'target_path' => $target,
            'obligations' => array_values((array) ($verdict['obligations'] ?? [])),
            'reason' => null,
        ];
    }

    /**
     * @return array{produced:false, objective:null, target_path:null, obligations:list<never>, reason:string}
     */
    private function refuse(string $reason): array
    {
        return ['produced' => false, 'objective' => null, 'target_path' => null, 'obligations' => [], 'reason' => $reason];
    }

    /**
     * Resolve the FIRST cited symbol that maps to a real inventory member's rel-path (by fqcn, rel-path, or
     * class-name). Deterministic; null when none of the (already inventory-grounded) citations names a file.
     *
     * @param  list<string>  $cited
     */
    private function resolveTarget(AtlasLoopScopeComprehensionModel $model, array $cited): ?string
    {
        foreach ($cited as $symbol) {
            $needle = strtolower(ltrim(trim((string) $symbol), '\\/'));
            $needleClass = $this->classOf($needle);
            foreach ($model->inventory as $item) {
                $rel = (string) ($item['rel_path'] ?? '');
                $fqcn = strtolower(ltrim((string) ($item['fqcn'] ?? ''), '\\'));
                if ($rel === '') {
                    continue;
                }
                if ($needle === strtolower(ltrim($rel, '/')) || $needle === $fqcn || $needleClass === $this->classOf($fqcn) || $needleClass === $this->classOf(strtolower($rel))) {
                    return ltrim($rel, '/');
                }
            }
        }

        return null;
    }

    private function classOf(string $value): string
    {
        foreach (['\\', '/'] as $sep) {
            $pos = strrpos($value, $sep);
            if ($pos !== false) {
                $value = substr($value, $pos + 1);
            }
        }

        return str_ends_with($value, '.php') ? substr($value, 0, -4) : $value;
    }
}
