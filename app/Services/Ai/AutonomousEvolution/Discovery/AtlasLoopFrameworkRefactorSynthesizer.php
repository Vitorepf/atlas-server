<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
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
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}|null
     */
    public function synthesizeFrameworkRefactor(string $repoRoot, string $targetRepoRelPath, array $signals, string $provider, string $targetId): ?array
    {
        try {
            $repoRoot = rtrim($repoRoot, '/');
            $targetRepoRelPath = ltrim($targetRepoRelPath, '/');
            $absTarget = $repoRoot.'/'.$targetRepoRelPath;
            if (! is_file($absTarget) || ! str_ends_with($targetRepoRelPath, '.php')) {
                return null;
            }

            // (a) Complexity gate — only worth refactoring a genuinely complex file. Prefer the
            // AST signal already in the packet; re-measure from source if absent (fail-open).
            $minCyclomatic = max(1, (int) config('atlas.loop.framework_refactor_min_cyclomatic', 10));
            $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
            if ($cyclomatic <= 0) {
                $source = (string) @file_get_contents($absTarget);
                $cyclomatic = (int) ($this->analyzer()->fileComplexity($source)['max_per_method'] ?? 0);
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
                'revert_recheck' => false,
                'timeout_seconds' => max(60, (int) config('atlas.loop.framework_refactor_timeout_seconds', 300)),
            ];

            $objective = $this->objectiveText($targetRepoRelPath, $cyclomatic);
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
                'frozen_tests' => [
                    ['path' => $siblingRel, 'content' => $siblingBody],
                ],
                'acceptance' => $acceptance,
                'allowed_files' => [$targetRepoRelPath],
                'validation_commands' => [$command],
                '_target_id' => $targetId,
            ];
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

    private function objectiveText(string $targetRepoRelPath, int $cyclomatic): string
    {
        // Stable per-kind template (dedupe is on objective text — keep refactor + edge-gap tasks
        // for the SAME file distinct, but stable across cycles so the queue is not bloated). The
        // baseline complexity number is included, never a time-measured value, so re-measuring the
        // SAME file does not produce a "distinct" objective.
        return 'Refactor '.basename($targetRepoRelPath).' to substantially REDUCE complexity '
            .'(simplify/extract/dedupe; worst method = '.$cyclomatic.') while PRESERVING behavior — '
            .'its existing tests must stay green.';
    }

    private function analyzer(): AtlasLoopSignalAnalyzer
    {
        return $this->signalAnalyzer ?? new AtlasLoopSignalAnalyzer();
    }
}
