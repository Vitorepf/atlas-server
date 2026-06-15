<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopQualityGrader;

/**
 * Lever 4 — the SELF-IMPROVEMENT seam ("ADEP improves ADEP"): the loop refactors its OWN over-complex
 * pipeline methods, behaviour-preserved, held to the ≥9 quality bar.
 *
 * This is the most dangerous thing the loop can do, so it is the most guarded:
 *
 *   1. SAFETY (the harness guard is the single chokepoint). The petreous set — frozen judge, promotion/
 *      merge gates, the certifier, the never-merge layer, this guard — is NEVER a target: the defendant
 *      cannot be sent to edit the judge. And a self-edit is admissible ONLY when BOTH meta flags are ON
 *      (`meta_harness_targets` AND `meta_harness_self_improve.enabled`). A forbidden/non-harness target
 *      or a flag OFF returns `admitted=false` and builds nothing.
 *   2. THE ≥9 BAR. We REUSE the proven extract-class contract (a behaviour-preserving, complexity-
 *      reducing refactor — exactly the axis the grader scores) and STAMP `quality_bar_gate=true` +
 *      `quality_bar` onto its acceptance. The certifier honours that per-task bar even when the global
 *      quality-bar flag is OFF, so a self-edit that does not genuinely simplify the pipeline (≥9) is
 *      refused. `objective_kind` stays `refactor_extract_class` so all materializer/diff-earned routing
 *      is unchanged; the self-improvement nature rides the `is_self_improvement` marker + the bar keys.
 *
 * The fully-autonomous feed (loss-observer self_improve intent -> this builder -> enqueued task) is the
 * gated next step; this builder is the safe, tested seam that proves the contract.
 */
final class AtlasLoopSelfImprovementObjectiveBuilder
{
    public function __construct(
        private readonly ?AtlasLoopHarnessGuard $guard = null,
        private readonly ?AtlasLoopExtractClassObjectiveBuilder $extractBuilder = null,
    ) {}

    /**
     * @return array{admitted:bool, admission:string, target:string, objective?:string, payload?:array<string,mixed>, acceptance_hash?:string, quality_bar?:float}
     */
    public function build(string $harnessFileRel, string $siblingTestRel, string $worstMethod, int $cyclomatic, ?string $provider = null): array
    {
        $rel = ltrim(str_replace('\\', '/', $harnessFileRel), '/');
        $guard = $this->guard ?? new AtlasLoopHarnessGuard;

        // Both meta flags must be ON for the loop to edit its own pipeline (anti-runaway).
        $metaEnabled = (bool) config('atlas.loop.meta_harness_targets', false)
            && (bool) config('atlas.loop.meta_harness_self_improve.enabled', false);

        if ($guard->isForbiddenSelfTarget($rel)) {
            return ['admitted' => false, 'admission' => 'forbidden', 'target' => $rel];
        }
        if (! $guard->isHarnessTarget($rel)) {
            // Self-improvement only ever targets the loop's OWN harness; an ordinary app file is not a
            // self-edit and must go through the normal refactor lane, not this ≥9-gated self seam.
            return ['admitted' => false, 'admission' => 'not_harness', 'target' => $rel];
        }
        if (! $metaEnabled) {
            return ['admitted' => false, 'admission' => 'harness_gated', 'target' => $rel];
        }

        $built = ($this->extractBuilder ?? new AtlasLoopExtractClassObjectiveBuilder)
            ->build($rel, $siblingTestRel, $worstMethod, $cyclomatic, $provider);

        $bar = (float) config('atlas.loop.quality_bar', AtlasLoopQualityGrader::DEFAULT_BAR);
        // Stamp the ≥9 contract onto the acceptance the certifier reads, and mark the task as a self-edit.
        $built['payload']['acceptance']['quality_bar_gate'] = true;
        $built['payload']['acceptance']['quality_bar'] = $bar;
        $built['payload']['is_self_improvement'] = true;

        return [
            'admitted' => true,
            'admission' => 'admissible',
            'target' => $rel,
            'objective' => $built['objective'],
            'payload' => $built['payload'],
            'acceptance_hash' => $built['acceptance_hash'],
            'quality_bar' => $bar,
        ];
    }
}
