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
            return $this->synthesizeFrameworkRefactorResult(
                rtrim($repoRoot, '/'),
                ltrim($targetRepoRelPath, '/'),
                $signals,
                $provider,
                $targetId,
                $extractClass,
            );
        } catch (Throwable) {
            return null; // fail-closed: never enqueue an unprovable refactor task
        }
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}|null
     */
    private function synthesizeFrameworkRefactorResult(string $repoRoot, string $targetRepoRelPath, array $signals, string $provider, string $targetId, bool $extractClass): ?array
    {
        $targetBody = $this->loadTargetBody($repoRoot, $targetRepoRelPath);
        if ($targetBody === null) {
            return null;
        }

        // (a) Complexity gate — only worth refactoring a genuinely complex file. Prefer the
        // AST signal already in the packet; re-measure from source if absent (fail-open).
        $minCyclomatic = max(1, (int) config('atlas.loop.framework_refactor_min_cyclomatic', 10));
        // The WORST METHOD NAME makes the objective surgically targetable (provider diffs that
        // refactor broadly but miss the worst method never drop the AST max -> fail cert). It is
        // not in the discovery signals, so re-measure when either the cyclomatic or the name is
        // absent (one AST parse covers both; fail-open -> null name just yields the file-level text).
        [$cyclomatic, $worstMethod] = $this->resolveTargetComplexity($signals, $targetBody);
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
        $sibling = $this->resolveSiblingSnapshot($repoRoot, $targetRepoRelPath);
        if ($sibling === null) {
            return null;
        }

        return $this->buildExtractClassObjective(
            $extractClass,
            $repoRoot,
            $targetRepoRelPath,
            $sibling['path'],
            $worstMethod,
            $cyclomatic,
            $provider,
            $targetBody,
            $sibling['content'],
            $targetId,
        ) ?? $this->buildInPlaceFrameworkRefactor(
            $targetRepoRelPath,
            $targetBody,
            $sibling['path'],
            $sibling['content'],
            $cyclomatic,
            $worstMethod,
            $provider,
            $targetId,
            $minCyclomatic,
        );
    }

    /**
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}
     */
    private function buildInPlaceFrameworkRefactor(string $targetRepoRelPath, string $targetBody, string $siblingRel, string $siblingBody, int $cyclomatic, ?string $worstMethod, string $provider, string $targetId, int $minCyclomatic): array
    {
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
            'revert_recheck' => true,
            'acceptance' => $acceptance,
            'allowed_files' => [$targetRepoRelPath],
            'validation_commands' => [$command],
            '_target_id' => $targetId,
        ];
        [$payload, $sequenceStep, $sequenceThreshold] = $this->applyExtractSequenceMetadata(
            $payload,
            $targetRepoRelPath,
            $targetBody,
            $minCyclomatic,
        );
        $objective = $this->objectiveText($targetRepoRelPath, $cyclomatic, $worstMethod, $sequenceStep, $sequenceThreshold);
        if ($provider !== '') {
            $payload['provider'] = $provider;
        }

        return ['objective' => $objective, 'payload' => $payload, 'acceptance_hash' => $acceptanceHash];
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

    private function loadTargetBody(string $repoRoot, string $targetRepoRelPath): ?string
    {
        $absTarget = $repoRoot.'/'.$targetRepoRelPath;
        if (! is_file($absTarget) || ! str_ends_with($targetRepoRelPath, '.php')) {
            return null;
        }

        $targetBody = (string) @file_get_contents($absTarget);

        return $targetBody !== '' ? $targetBody : null;
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return array{0: int, 1: ?string}
     */
    private function resolveTargetComplexity(array $signals, string $targetBody): array
    {
        $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
        $worstMethod = is_string($signals['worst_method'] ?? null) ? (string) $signals['worst_method'] : null;
        if ($cyclomatic > 0 && $worstMethod !== null) {
            return [$cyclomatic, $worstMethod];
        }

        $measure = $this->analyzer()->fileComplexity($targetBody);

        return [
            $cyclomatic > 0 ? $cyclomatic : (int) ($measure['max_per_method'] ?? 0),
            $worstMethod ?? (is_string($measure['worst_method'] ?? null) ? (string) $measure['worst_method'] : null),
        ];
    }

    /**
     * @return array{path:string,content:string}|null
     */
    private function resolveSiblingSnapshot(string $repoRoot, string $targetRepoRelPath): ?array
    {
        $resolver = $this->siblingTests ?? new AtlasLoopSiblingTestResolver($repoRoot);
        $sib = $resolver->resolve($targetRepoRelPath);
        if (! ($sib['has_sibling'] ?? false) || ! is_string($sib['sibling_path'] ?? null)) {
            return null;
        }

        $siblingRel = ltrim((string) $sib['sibling_path'], '/');
        $siblingAbs = $repoRoot.'/'.$siblingRel;
        if (! is_file($siblingAbs)) {
            return null;
        }

        $siblingBody = (string) @file_get_contents($siblingAbs);

        return $siblingBody !== ''
            ? ['path' => $siblingRel, 'content' => $siblingBody]
            : null;
    }

    private function buildExtractClassObjective(
        bool $extractClass,
        string $repoRoot,
        string $targetRepoRelPath,
        string $siblingRel,
        ?string $worstMethod,
        int $cyclomatic,
        string $provider,
        string $targetBody,
        string $siblingBody,
        string $targetId,
    ): ?array {
        if (! $extractClass) {
            return null;
        }

        // ACDE R3 — budgeted cross-file extract SEQUENCE: across discovery waves spin out distinct
        // Support / Support2 / Support3 classes (pick the lowest step whose file does not yet exist)
        // so a god-class is decomposed into a CHAIN of cohesive classes, not one. Default OFF =>
        // step 1 => the historical single `<Target>Support.php` => byte-identical. When the chain is
        // exhausted within budget, fall through to the in-place reduction below (a safe terminal).
        $sequenceOn = (bool) config('atlas.loop.extract_class_sequence_enabled', false);
        $step = $sequenceOn
            ? (new AtlasLoopExtractClassObjectiveBuilder())->nextAvailableStep(
                $targetRepoRelPath,
                static fn (string $rel): bool => is_file($repoRoot.'/'.$rel),
                max(1, (int) config('atlas.loop.extract_class_sequence_max_steps', 3)),
            )
            : 1;
        if ($step === null) {
            return null;
        }

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
        $built['payload']['revert_recheck'] = true;
        $built['payload']['acceptance']['quality_bar_gate'] = true;
        $built['payload']['acceptance']['quality_bar'] = $this->qualityBar();
        if ($sequenceOn) {
            $built['payload']['extract_sequence_id'] = (new AtlasLoopExtractSequencePlanner)->sequenceId($targetRepoRelPath);
            $built['payload']['extract_sequence_step'] = $step;
        }

        return $built;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0: array<string,mixed>, 1: array{target_method?:string,target_method_bare?:string,cyclomatic?:int}|null, 2: int|null}
     */
    private function applyExtractSequenceMetadata(array $payload, string $targetRepoRelPath, string $targetBody, int $minCyclomatic): array
    {
        // ACDE lever #6 — tag this in-place worst-method reduction as one STEP of a bounded extract
        // sequence. The weak engine cannot one-shot a whole god-class, but each grind wave's discovery
        // re-selects the still-complex file and the synthesizer pins its CURRENT worst method, so the
        // class is decomposed worst-first across waves — the composition of certified single-method
        // reductions IS the big delivery, all through the proven Path B framework-refactor cert (never
        // the blocking obra-DAG). The planner makes that sequence explicit + BOUNDED (the tractable
        // threshold) and the sequence_id groups the steps for observability. Default OFF => no tag =>
        // byte-identical. The chain ends naturally when the worst method drops below the threshold (the
        // re-measure is the discovery re-scan, so a step that simplified a DIFFERENT method self-corrects).
        if (! (bool) config('atlas.loop.extract_sequence_enabled', false)) {
            return [$payload, null, null];
        }

        $planner = new AtlasLoopExtractSequencePlanner;
        $census = $this->analyzer()->fileComplexity($targetBody);
        $perMethod = is_array($census['per_method'] ?? null) ? $census['per_method'] : [];
        $threshold = max(1, (int) config('atlas.loop.extract_sequence_tractable_cyclomatic', $minCyclomatic));
        $maxSteps = $this->resolveExtractSequenceMaxSteps($planner, $perMethod, $threshold);
        // ACDE R1 (slice 2/2): de-orphan nextStep() onto the LIVE synthesis path (it was dead
        // outside tests). It pins THIS step's worst-above-threshold method, carrying the bare name
        // (via the identity shim) that the objective builder + the in-lane re-discovery chain
        // consume; null => the file is already tractable (the chain is done). The objective itself
        // still uses worst_method, so the synthesised task is byte-identical — this only ADDS the
        // pinned-step record the corpus (R2) and the prior-read (R2-read) build on.
        return $this->support()->applyExtractSequenceMetadata(
            $payload,
            $planner,
            $perMethod,
            $threshold,
            $targetRepoRelPath,
            $maxSteps,
        );
    }

    /**
     * @param  array<string,mixed>  $perMethod
     */
    private function resolveExtractSequenceMaxSteps(AtlasLoopExtractSequencePlanner $planner, array $perMethod, int $threshold): int
    {
        $maxSteps = max(1, (int) config('atlas.loop.extract_sequence_max_steps', 6));
        // ACDE R2-read (the MULTIPLIER): ground the next sequence on the recorded prior (R2's writes).
        // If THIS shape (the full plan's fingerprint) has historically THRASHED, back the chain off to
        // a SINGLE worst-method step this round — a smaller, more-certifiable obra the weak engine is
        // likelier to land — so delivery N's recorded outcome makes delivery N+1 more certifiable (the
        // curve bends). Default-OFF (and thin corpus => UNKNOWN) => maxSteps unchanged => byte-identical.
        return $this->support()->resolveExtractSequenceMaxSteps(
            $planner,
            $perMethod,
            $threshold,
            $maxSteps,
            (bool) config('atlas.loop.extract_sequence_prior_read_enabled', false),
            (int) config('atlas.loop.extract_sequence_prior_min_samples', 4),
            (float) config('atlas.loop.extract_sequence_prior_target_rate', 0.5),
        );
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
        return $this->support()->buildObjectiveText(
            $targetRepoRelPath,
            $cyclomatic,
            $worstMethod,
            $sequenceStep,
            $sequenceThreshold,
        );
    }

    private function support(): AtlasLoopFrameworkRefactorSynthesizerSupport
    {
        return new AtlasLoopFrameworkRefactorSynthesizerSupport();
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
