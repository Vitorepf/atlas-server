<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSelfImprovementObjectiveBuilder;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Throwable;

/**
 * ACDE lever C1 — the self-improvement GROUNDING bridge (the dead builder's missing caller).
 *
 * The loss observer turns a dominant self-loss into a `self_improve` backlog intent whose objective is a vague
 * one-line natural-language directive ("atacar complexity_not_reduced em X"). The grounded spec authority —
 * {@see AtlasLoopSelfImprovementObjectiveBuilder} — has ZERO production callers, so the loop's own self-edit
 * never gets the AST-anchored, worst-method-named, ≥9-frozen extract-class contract it was built to produce. A
 * weak engine handed the vague directive simplifies SOME method and certifies nothing (observed live:
 * complexity_not_reduced on big diffs that missed the actual worst method).
 *
 * This bridge GROUNDS the intent against frozen truth (ground-vs-frozen-truth): it re-measures the harness file
 * with {@see AtlasLoopSignalAnalyzer::fileComplexity} (the worst method + its cyclomatic — the SAME census the
 * cert re-measures), resolves the behavioral sibling test, and hands those to the builder, returning its
 * admitted spec. The builder's OWN double-flag gate (`meta_harness_targets` AND
 * `meta_harness_self_improve.enabled`) and the petreous harness guard still decide admissibility — so this
 * bridge can NEVER admit a self-edit those gates would refuse. Fail-open: an unmeasurable file, no worst
 * method, no sibling test, a forbidden/non-harness target, or any error returns null (the caller keeps the
 * vague intent). It is pure read-only: no DB, no provider, no mutation.
 */
final class AtlasLoopSelfImprovementGroundingBridge
{
    public function __construct(
        private readonly ?AtlasLoopSignalAnalyzer $analyzer = null,
        private readonly ?AtlasLoopSelfImprovementObjectiveBuilder $builder = null,
    ) {}

    /**
     * Ground a self_improve target into the builder's admitted extract-class spec, or null when it cannot be
     * grounded / the gates refuse.
     *
     * @return array{admitted:bool, admission:string, target:string, objective?:string, payload?:array<string,mixed>, acceptance_hash?:string, quality_bar?:float}|null
     */
    public function ground(string $harnessFileRel, ?string $repoRoot = null, ?string $provider = null): ?array
    {
        try {
            $rel = ltrim(str_replace('\\', '/', trim($harnessFileRel)), '/');
            if ($rel === '' || ! str_ends_with($rel, '.php')) {
                return null;
            }
            $root = rtrim(($repoRoot !== null && $repoRoot !== '') ? $repoRoot : base_path(), '/');
            $abs = $root.'/'.$rel;
            if (! is_file($abs)) {
                return null;
            }
            $src = (string) @file_get_contents($abs);
            if ($src === '') {
                return null;
            }

            // Re-measure the worst method + its cyclomatic from the live source (the frozen-truth anchor).
            $cx = ($this->analyzer ?? new AtlasLoopSignalAnalyzer)->fileComplexity($src);
            if (($cx['measured'] ?? false) !== true) {
                return null;
            }
            $worst = is_string($cx['worst_method'] ?? null) ? (string) $cx['worst_method'] : '';
            $cyclomatic = max(0, (int) ($cx['max_per_method'] ?? 0));
            if ($worst === '' || $cyclomatic <= 0) {
                return null; // nothing concrete to name => keep the vague intent
            }

            // The behavior-preserving extract-class contract needs a sibling test to canary against.
            $sibling = (new AtlasLoopSiblingTestResolver($root))->resolve($rel);
            if (($sibling['has_sibling'] ?? false) !== true || ! is_string($sibling['sibling_path'] ?? null)) {
                return null;
            }

            $result = ($this->builder ?? new AtlasLoopSelfImprovementObjectiveBuilder)
                ->build($rel, (string) $sibling['sibling_path'], $worst, $cyclomatic, $provider);

            return (($result['admitted'] ?? false) === true) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }
}
