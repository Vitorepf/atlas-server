<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder;
use App\Services\Ai\AutonomousEvolution\AtlasLoopQualityGrader;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Throwable;

/**
 * FRAMEWORK REFACTOR (heavy, behavior-preserving) — the STRUCTURAL objective builder for
 * framework-reach targets (real Atlas services), the sibling of
 * {@see AtlasLoopRefactorObjectiveSynthesizer} (which only covers self-contained plain-`php`
 * files).
 *
 * Like the Phase-1 synthesizer it makes ZERO provider call: a refactor objective is admissible
 * ONLY when the target is (a) a HIGH-COMPLEXITY framework file (AST max-per-method cyclomatic at
 * or above a configurable floor), (b) WIRED — at least one real production caller, so the
 * simplification pays back on code that runs — and (c) backed by a REAL PHPUnit test (the
 * convention sibling), which is the behavior-preservation contract: the loop can never edit it
 * (tests/** is frozen). It does NOT compile a provider-written RED verifier (that is the
 * edge-gap path) — the existing human-authored test suite is the honest behavior anchor.
 *
 * The certification is a CONJUNCTION enforced downstream: the framework path re-runs the REAL
 * test (behavior preserved) AND {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier}
 * proves a REAL AST cyclomatic DROP (candidate_max < baseline_max, file total not increasing) by
 * the judge's OWN measure in the gate workspace — never a provider-claimed number. A no-op /
 * complexity-non-reducing diff is dropped; a behavior change turns the real test RED.
 *
 * Fail-closed by construction: any doubt (no sibling, below floor, not wired, IO error) returns
 * null so the caller falls through to the edge-gap framework path, byte-identical to today.
 */
final class AtlasLoopFrameworkRefactorSynthesizer
{
    public const OBJECTIVE_KIND = 'refactor_reduce_complexity';

    public function __construct(
        private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
        private readonly ?AtlasLoopWiredCallerService $wiredCallers = null,
        private readonly ?AtlasLoopSiblingTestResolver $siblingTests = null,
    ) {}

    /**
     * Synthesize a framework refactor task payload, or null when the target is not
     * refactor-eligible. The returned payload routes through the framework materializer
     * (worktree + real autoload + hermetic test env) and carries a `complexity_proof`
     * acceptance so the certifier enforces the cyclomatic drop.
     *
     * @param  array<string,mixed>  $signals  the discovery signals packet for this target
     * @param  bool  $extractClass  when true, emit a MULTI-FILE extract-class objective (target + a
     *   new <Target>Support.php) routed to the normal grind via the structural cert — reusing the
     *   SAME complexity/wired/sibling gates as the single-file refactor. Default false = unchanged.
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}|null
     */
    public function synthesizeFrameworkRefactor(string $repoRoot, string $targetRepoRelPath, array $signals, string $provider, string $targetId, bool $extractClass = false): ?array
    {
        try {
            $repoRoot = rtrim($repoRoot, '/');
            $targetRepoRelPath = ltrim($targetRepoRelPath, '/');
            $absTarget = $repoRoot.'/'.$targetRepoRelPath;
            if (! is_file($absTarget) || ! str_ends_with($targetRepoRelPath, '.php')) {
                return null;
            }
            $targetBody = (string) @file_get_contents($absTarget);
            if ($targetBody === '') {
                return null;
            }

            // (a) Complexity gate — only worth refactoring a genuinely complex file. Prefer the
            // AST signal already in the packet; re-measure from source if absent (fail-open).
            $minCyclomatic = max(1, (int) config('atlas.loop.framework_refactor_min_cyclomatic', 10));
            $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
            // The WORST METHOD NAME makes the objective surgically targetable (provider diffs that
            // refactor broadly but miss the worst method never drop the AST max -> fail cert). It is
            // not in the discovery signals, so re-measure when either the cyclomatic or the name is
            // absent (one AST parse covers both; fail-open -> null name just yields the file-level text).
            $worstMethod = is_string($signals['worst_method'] ?? null) ? (string) $signals['worst_method'] : null;
            if ($cyclomatic <= 0 || $worstMethod === null) {
                $measure = $this->analyzer()->fileComplexity($targetBody);
                if ($cyclomatic <= 0) {
                    $cyclomatic = (int) ($measure['max_per_method'] ?? 0);
                }
                if ($worstMethod === null) {
                    $worstMethod = is_string($measure['worst_method'] ?? null) ? (string) $measure['worst_method'] : null;
                }
            }
            if ($cyclomatic < $minCyclomatic) {
                return null; // not complex enough — refactoring it is low-value noise
            }

            // (b) Wired gate — the refactor must pay back on code that runs. Prefer the caller
            // count already resolved by discovery's impact ranking; re-measure if absent.
            // Tri-state: null = unmeasured => fail-closed (we cannot prove it is wired).
            $minCallers = max(1, (int) config('atlas.loop.framework_refactor_min_callers', 1));
            $callers = $this->resolveCallers($repoRoot, $targetRepoRelPath, $signals);
            if ($callers === null || $callers < $minCallers) {
                return null;
            }

            // (c) Behavior anchor — the target MUST have a real convention sibling test. Build the
            // resolver against THIS campaign's repo root so the sibling the resolver finds is the
            // one the materialized worktree runs.
            $sib = ($this->siblingTests ?? new AtlasLoopSiblingTestResolver($repoRoot))->resolve($targetRepoRelPath);
            if (! ($sib['has_sibling'] ?? false) || ! is_string($sib['sibling_path'] ?? null)) {
                return null;
            }
            $siblingRel = ltrim((string) $sib['sibling_path'], '/');
            $siblingAbs = $repoRoot.'/'.$siblingRel;
            if (! is_file($siblingAbs)) {
                return null;
            }
            $siblingBody = (string) @file_get_contents($siblingAbs);
            if ($siblingBody === '') {
                return null;
            }

            // EXTRACT-CLASS (multi-file, Path B): same gates above (complex + wired + real sibling),
            // but emit a 2-file extract-class objective routed to the NORMAL grind (structural cert,
            // anti-relocation, cross-file census) instead of the single-file in-place reduction. The
            // builder's payload carries allowed_globs=[target,newClass] + structural_proof; enrich it
            // with the frozen sibling snapshot + target id so it is materializer-identical to below.
            if ($extractClass) {
                // ACDE R3 — budgeted cross-file extract SEQUENCE: across discovery waves spin out distinct
                // Support / Support2 / Support3 classes (pick the lowest step whose file does not yet exist)
                // so a god-class is decomposed into a CHAIN of cohesive classes, not one. Default OFF =>
                // step 1 => the historical single `<Target>Support.php` => byte-identical. When the chain is
                // exhausted within budget, fall through to the in-place reduction below (a safe terminal).
                $step = 1;
                $sequenceOn = (bool) config('atlas.loop.extract_class_sequence_enabled', false);
                $exhausted = false;
                if ($sequenceOn) {
                    $next = (new AtlasLoopExtractClassObjectiveBuilder())->nextAvailableStep(
                        $targetRepoRelPath,
                        static fn (string $rel): bool => is_file($repoRoot.'/'.$rel),
                        max(1, (int) config('atlas.loop.extract_class_sequence_max_steps', 3)),
                    );
                    if ($next === null) {
                        $exhausted = true;
                    } else {
                        $step = $next;
                    }
                }
                if (! $exhausted) {
                    $built = (new AtlasLoopExtractClassObjectiveBuilder())->build(
                        $targetRepoRelPath,
                        $siblingRel,
                        $worstMethod ?? '',
                        $cyclomatic,
                        $provider !== '' ? $provider : null,
                        $step,
                    );
                    $built['payload']['target_content'] = $targetBody;
                    $built['payload']['frozen_tests'] = [['path' => $siblingRel, 'content' => $siblingBody]];
                    $built['payload']['_target_id'] = $targetId;
                    $built['payload']['acceptance']['quality_bar_gate'] = true;
                    $built['payload']['acceptance']['quality_bar'] = $this->qualityBar();
                    if ($sequenceOn) {
                        $built['payload']['extract_sequence_id'] = (new AtlasLoopExtractSequencePlanner)->sequenceId($targetRepoRelPath);
                        $built['payload']['extract_sequence_step'] = $step;
                    }

                    return $built;
                }
                // chain exhausted within budget -> fall through to in-place reduction (terminal).
            }

            // The acceptance runs the REAL PHPUnit sibling test through the worktree's phpunit
            // (the materializer regenerates the worktree autoloader so App\ resolves to the EDITED
            // target, then provisions a hermetic :memory: env). The test path is FROZEN — the loop
            // can never edit it (tests/** is frozen). revert_recheck is FALSE: a behavior-PRESERVING
            // refactor would stay green with the diff reverted (it earns no NEW behavior), so the
            // anti-fake proof here is the complexity DROP, not diff-earned.
            $command = './vendor/bin/phpunit '.escapeshellarg($siblingRel);
            $acceptance = [
                'commands' => [$command],
                'allowed_globs' => [$targetRepoRelPath],
                'frozen_globs' => ['tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
                'complexity_proof' => true,
                'complexity_aggregation' => 'max_per_method_primary_total_non_increasing',
                'quality_bar_gate' => true,
                'quality_bar' => $this->qualityBar(),
                'revert_recheck' => false,
                'timeout_seconds' => max(60, (int) config('atlas.loop.framework_refactor_timeout_seconds', 300)),
            ];

            $acceptanceHash = hash('sha256', json_encode([
                'commands' => $acceptance['commands'],
                'allowed_globs' => $acceptance['allowed_globs'],
                'frozen_globs' => $acceptance['frozen_globs'],
                'metric_kind' => $acceptance['metric_kind'],
                'objective_kind' => self::OBJECTIVE_KIND,
            ], JSON_THROW_ON_ERROR));

            $payload = [
                'materializer' => 'framework',
                // NOTE: NO intent_verifier_factory — that path compiles a NEW provider RED test;
                // a refactor's anchor is the EXISTING human-authored sibling, supplied here frozen.
                'objective_kind' => self::OBJECTIVE_KIND,
                'target_relative_path' => $targetRepoRelPath,
                'target_repo_path' => $targetRepoRelPath,
                'target_content' => $targetBody,
                'frozen_tests' => [
                    ['path' => $siblingRel, 'content' => $siblingBody],
                ],
                'acceptance' => $acceptance,
                'allowed_files' => [$targetRepoRelPath],
                'validation_commands' => [$command],
                '_target_id' => $targetId,
            ];
            $sequenceStep = null;
            $sequenceThreshold = null;
            // ACDE lever #6 — tag this in-place worst-method reduction as one STEP of a bounded extract
            // sequence. The weak engine cannot one-shot a whole god-class, but each grind wave's discovery
            // re-selects the still-complex file and the synthesizer pins its CURRENT worst method, so the
            // class is decomposed worst-first across waves — the composition of certified single-method
            // reductions IS the big delivery, all through the proven Path B framework-refactor cert (never
            // the blocking obra-DAG). The planner makes that sequence explicit + BOUNDED (the tractable
            // threshold) and the sequence_id groups the steps for observability. Default OFF => no tag =>
            // byte-identical. The chain ends naturally when the worst method drops below the threshold (the
            // re-measure is the discovery re-scan, so a step that simplified a DIFFERENT method self-corrects).
            if ((bool) config('atlas.loop.extract_sequence_enabled', false)) {
                $planner = new AtlasLoopExtractSequencePlanner;
                $census = $this->analyzer()->fileComplexity($targetBody);
                $perMethod = is_array($census['per_method'] ?? null) ? $census['per_method'] : [];
                $threshold = max(1, (int) config('atlas.loop.extract_sequence_tractable_cyclomatic', $minCyclomatic));
                $maxSteps = max(1, (int) config('atlas.loop.extract_sequence_max_steps', 6));
                // ACDE R2-read (the MULTIPLIER): ground the next sequence on the recorded prior (R2's writes).
                // If THIS shape (the full plan's fingerprint) has historically THRASHED, back the chain off to
                // a SINGLE worst-method step this round — a smaller, more-certifiable obra the weak engine is
                // likelier to land — so delivery N's recorded outcome makes delivery N+1 more certifiable (the
                // curve bends). Default-OFF (and thin corpus => UNKNOWN) => maxSteps unchanged => byte-identical.
                if ((bool) config('atlas.loop.extract_sequence_prior_read_enabled', false)) {
                    $fullPlan = $planner->plan($perMethod, $threshold, $maxSteps);
                    if ($fullPlan !== []) {
                        $hash = (string) (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint(['nodes' => $fullPlan])['hash'];
                        $hist = (new AtlasLoopDecompositionOutcomeRecorder)->history($hash);
                        if (AtlasLoopExtractSequencePlanner::priorBacksOff(
                            (int) ($hist['certified'] ?? 0),
                            (int) ($hist['total'] ?? 0),
                            (int) config('atlas.loop.extract_sequence_prior_min_samples', 4),
                            (float) config('atlas.loop.extract_sequence_prior_target_rate', 0.5),
                        )) {
                            $maxSteps = 1; // hard shape -> attempt only the worst method this round
                        }
                    }
                }
                $payload['extract_sequence_id'] = $planner->sequenceId($targetRepoRelPath);
                $payload['extract_sequence_plan'] = $planner->plan($perMethod, $threshold, $maxSteps);
                $payload['extract_sequence_tractable_cyclomatic'] = $threshold;
                $sequenceThreshold = $threshold;
                // ACDE R1 (slice 2/2): de-orphan nextStep() onto the LIVE synthesis path (it was dead
                // outside tests). It pins THIS step's worst-above-threshold method, carrying the bare name
                // (via the identity shim) that the objective builder + the in-lane re-discovery chain
                // consume; null => the file is already tractable (the chain is done). The objective itself
                // still uses worst_method, so the synthesised task is byte-identical — this only ADDS the
                // pinned-step record the corpus (R2) and the prior-read (R2-read) build on.
                $step = $planner->nextStep($perMethod, $threshold);
                if ($step !== null) {
                    $payload['extract_sequence_step'] = $step;
                    $sequenceStep = $step;
                }
            }
            $objective = $this->objectiveText($targetRepoRelPath, $cyclomatic, $worstMethod, $sequenceStep, $sequenceThreshold);
            if ($provider !== '') {
                $payload['provider'] = $provider;
            }

            return ['objective' => $objective, 'payload' => $payload, 'acceptance_hash' => $acceptanceHash];
        } catch (Throwable) {
            return null; // fail-closed: never enqueue an unprovable refactor task
        }
    }

    /**
     * Resolve the real production caller count. Prefer the value discovery's impact ranking
     * already stamped (impact_real_callers); re-measure via the wired-caller service when
     * absent. Tri-state: null = unmeasured (fail-closed), int = measured (0 = confirmed orphan).
     *
     * @param  array<string,mixed>  $signals
     */
    private function resolveCallers(string $repoRoot, string $targetRepoRelPath, array $signals): ?int
    {
        if (array_key_exists('impact_real_callers', $signals) && is_int($signals['impact_real_callers'])) {
            return $signals['impact_real_callers'];
        }

        $service = $this->wiredCallers ?? new AtlasLoopWiredCallerService($repoRoot);

        return $service->callerCount($targetRepoRelPath);
    }

    /**
     * @param  array{target_method?:string,target_method_bare?:string,cyclomatic?:int}|null  $sequenceStep
     */
    private function objectiveText(string $targetRepoRelPath, int $cyclomatic, ?string $worstMethod, ?array $sequenceStep = null, ?int $sequenceThreshold = null): string
    {
        // Stable per-kind template (dedupe is on objective text). The worst-method NAME + its cyclomatic
        // are deterministic for a given file state, so re-measuring the SAME file yields the SAME
        // objective (no queue bloat); once that method is simplified a DIFFERENT method becomes worst →
        // a distinct objective → progressive refactoring. Naming the exact method + giving concrete
        // decision-count-reduction techniques is the lever that turns broad no-AST-drop diffs into
        // certifiable ones (the cert independently re-measures the drop, so this text only AIMS the work).
        $base = basename($targetRepoRelPath);
        $stepMethod = is_string($sequenceStep['target_method_bare'] ?? null) && trim((string) $sequenceStep['target_method_bare']) !== ''
            ? trim((string) $sequenceStep['target_method_bare'])
            : null;
        $stepCyclomatic = isset($sequenceStep['cyclomatic']) ? max(1, (int) $sequenceStep['cyclomatic']) : null;
        $method = $stepMethod ?? $worstMethod;
        $methodCyclomatic = $stepCyclomatic ?? $cyclomatic;
        $where = $method !== null ? $base.'::'.$method.'()' : 'the file\'s most complex method';
        $sequenceInstruction = '';
        if ($sequenceStep !== null) {
            $target = $sequenceThreshold !== null ? ' toward the tractable threshold '.$sequenceThreshold : '';
            $sequenceInstruction = 'This is ONE bounded extract-sequence step'.$target.': do not redesign the whole file. '
                .'Land the smallest certifiable behavior-preserving edit that lowers '.$where.' below its current '
                .'cyclomatic '.$methodCyclomatic.' while keeping the file total cyclomatic/branch count flat or lower. ';
        }

        return 'Refactor '.$base.' to REDUCE the cyclomatic complexity of its worst method, '.$where
            .' (cyclomatic '.$cyclomatic.', the file max). Drive DOWN the decision/branch count of THAT '
            .'method specifically — first try the smallest in-method reduction: collapse repeated boolean-chain guards '
            .'(`||` / `&&`) into equivalent data-driven checks such as lookup sets or `in_array`, then prefer replacing long '
            .'if/elseif or switch chains with a lookup/dispatch table. When collapsing guards that use truthiness or '
            .'null-coalescing (`??`), preserve the exact falsey behavior (for example with `empty(...)`) instead of '
            .'placing raw nullable/falsey values in a strict `in_array`, '
            .'and only extract helper methods when they are branch-free or remove enough existing branches to keep total complexity flat — so the file\'s AST '
            .'max-per-method drops below '.$cyclomatic.'. '.$sequenceInstruction
            // ALIGN-WITH-GATE (in the prompt, not just a comment): the certifier scores DECISION POINTS
            // (the file's TOTAL branch count, extract-method-neutral), not raw method count. A refactor
            // that drops the max but ADDS net conditionals is rejected as complexity_not_reduced. This
            // constraint is exactly what made the controlled cx19->cx4 run certify (decisions 21->21).
            .'CRITICAL CONSTRAINT: do NOT increase the file\'s TOTAL decision/branch count. Do not add new '
            .'conditionals, guard clauses, loops, ternaries, or && / || beyond those already present — only '
            .'collapse if/elseif chains into a single lookup/dispatch table or move branches only when the '
            .'same change removes at least as much total complexity. The file\'s total cyclomatic count and '
            .'total number of branches must stay flat or fall; '
            .'only the worst method\'s SHARE of them should shrink. A version that lowers the max but adds '
            .'net branches will be REJECTED. '
            .'Edit ONLY '.$base.'; do not modify any other '
            .'file. PRESERVE behavior exactly — the existing tests must stay green.';
    }

    private function analyzer(): AtlasLoopSignalAnalyzer
    {
        return $this->signalAnalyzer ?? new AtlasLoopSignalAnalyzer();
    }

    private function qualityBar(): float
    {
        return (float) config('atlas.loop.quality_bar', AtlasLoopQualityGrader::DEFAULT_BAR);
    }
}
