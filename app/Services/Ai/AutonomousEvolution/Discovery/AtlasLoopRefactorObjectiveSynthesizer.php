<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Throwable;

/**
 * GOVERNED REFACTOR (Phase 1 · within-file) — the STRUCTURAL objective builder.
 *
 * Unlike {@see \App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator} (which makes
 * the one provider call in discovery to author a RED test describing a NEW behavior), this
 * synthesizer makes ZERO provider call. A refactor objective is synthesized entirely from
 * code metrics: it is admissible ONLY when the target is a high-complexity self-contained
 * file that ALREADY has a real, human-authored sibling test. That sibling test — which the
 * loop can NEVER edit (it travels as a frozen test, tests/** is frozen) — IS the
 * behavior-preservation contract. The frozen judge then certifies the result ONLY when (a)
 * the frozen sibling test stays GREEN (behavior preserved) AND (b) a real AST cyclomatic
 * measure drops (Guard 4b — complexityEarned).
 *
 * Why no provider-written test: a generated test would freeze the LOOP's OWN behavior claim,
 * not the file's. The existing sibling is the only honest behavior anchor, so Phase 1 is
 * narrowed to test-backed files by design (acceptable and honest — see plan open_risks).
 *
 * The payload mirrors AtlasLoopQueueRefiller::snapshotPayload exactly so the materializer,
 * explorer, runner and judge consume it through the SAME path; the only differences are the
 * frozen behavior harness (the sibling test, path-rewritten to the materialized target) and
 * the acceptance contract keys (metric_kind=minimize, complexity_proof=true,
 * revert_recheck=false, objective_kind=refactor_reduce_complexity).
 */
final class AtlasLoopRefactorObjectiveSynthesizer
{
    public const OBJECTIVE_KIND = 'refactor_reduce_complexity';

    /** Min max-per-method cyclomatic for a file to be worth refactoring (avoid trivial targets). */
    private const MIN_CYCLOMATIC = 8;

    public function __construct(
        private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
    ) {}

    /**
     * Synthesize a refactor task payload for a self-contained target, or null when the target
     * is not refactor-eligible (no sibling test / not complex enough / sibling not
     * plain-`php` runnable / IO error). Fail-closed: any doubt => null => the caller falls
     * through to the normal generator path, so the queue is never polluted with a refactor
     * task that cannot be honestly proven.
     *
     * @param  array<string,mixed>  $signals  the discovery signals packet for this target
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}|null
     */
    public function synthesize(string $repoRoot, string $targetRepoRelPath, array $signals, string $provider, string $targetId): ?array
    {
        try {
            $repoRoot = rtrim($repoRoot, '/');
            $targetRepoRelPath = ltrim($targetRepoRelPath, '/');
            $absTarget = $repoRoot.'/'.$targetRepoRelPath;
            if (! is_file($absTarget) || ! str_ends_with($targetRepoRelPath, '.php')) {
                return null;
            }

            // Complexity gate: only worth refactoring a genuinely complex file. Prefer the AST
            // signal already in the packet; re-measure from source if absent (fail-open).
            $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
            if ($cyclomatic <= 0) {
                $source = (string) @file_get_contents($absTarget);
                $cyclomatic = (int) ($this->analyzer()->fileComplexity($source)['max_per_method'] ?? 0);
            }
            if ($cyclomatic < self::MIN_CYCLOMATIC) {
                return null; // not complex enough — refactoring it is low-value noise
            }

            // Behavior anchor: the file MUST have a real sibling test (fail-closed in resolver).
            // Build the resolver against THIS campaign's repo root so "what the resolver finds"
            // == "what the canary runs" by construction (the resolver mirrors the canary glob).
            $sib = (new AtlasLoopSiblingTestResolver($repoRoot))->resolve($targetRepoRelPath);
            if (! ($sib['has_sibling'] ?? false) || ! is_string($sib['sibling_path'] ?? null)) {
                return null;
            }
            $siblingAbs = $repoRoot.'/'.ltrim((string) $sib['sibling_path'], '/');
            $siblingBody = is_file($siblingAbs) ? (string) @file_get_contents($siblingAbs) : '';
            if ($siblingBody === '') {
                return null;
            }

            $basename = basename($targetRepoRelPath);
            $targetRel = 'src/'.$basename; // the file the loop is allowed to edit
            $frozenTestRel = 'tests/'.basename((string) $sib['sibling_path']);

            // The frozen behavior harness must run under plain `php` in the isolated,
            // self-contained grind workspace (no framework boot): point any `require`/`include`
            // of the production file at the materialized target. If the sibling is NOT
            // plain-`php` runnable (a framework-booted Feature test), bail — Phase 1 only covers
            // files whose sibling can pin behavior without a framework, by design.
            $harness = $this->rewriteSiblingToWorkspace($siblingBody, $basename, $targetRel);
            if ($harness === null) {
                return null;
            }

            $command = 'php '.$frozenTestRel;
            $acceptance = [
                'commands' => [$command],
                'allowed_globs' => ['src/**', $targetRel],
                'frozen_globs' => ['tests/**', 'composer.json'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
                // The judge measures complexity itself by AST; metric_pattern is intentionally
                // absent because the gate decision NEVER reads a provider-printed number — the
                // candidate's own AST max-per-method is the ranking metric (computed in the judge).
                'complexity_proof' => true,
                'complexity_aggregation' => 'max_per_method_primary_total_non_increasing',
                'revert_recheck' => false, // refactors are behavior-PRESERVING; diffEarned (RED-earned) does not apply
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
                'objective_kind' => self::OBJECTIVE_KIND, // advisory only — never gates the judge
                'target_repo_path' => $targetRepoRelPath,
                'target_relative_path' => $targetRel,
                'target_content' => (string) @file_get_contents($absTarget),
                'frozen_tests' => [
                    ['path' => $frozenTestRel, 'content' => $harness],
                ],
                'acceptance' => $acceptance,
                'allowed_files' => [$targetRel], // Phase 1 is SINGLE-FILE only
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
     * Rewrite a sibling test body so it `require`s the MATERIALIZED target (src/<basename>)
     * from the workspace tests/ dir, and runs under plain `php`. Returns null when the
     * sibling is not plain-`php` runnable (no require of the production file we can retarget,
     * or it boots PHPUnit/Laravel), so Phase 1 only admits behavior anchors that can be
     * honestly re-run in isolation.
     */
    private function rewriteSiblingToWorkspace(string $body, string $basename, string $targetRel): ?string
    {
        // A framework/PHPUnit Feature test cannot pin behavior under plain `php` — bail.
        if (preg_match('/\b(extends\s+TestCase|use\s+(PHPUnit|Illuminate|Tests)\\\\|RefreshDatabase|->assert|\$this->)/i', $body) === 1) {
            return null;
        }
        // It must directly require/include the production file (the only seam we can retarget).
        if (preg_match('/\b(require|require_once|include|include_once)\b/', $body) !== 1) {
            return null;
        }

        // Retarget any require/include of a path ending in the production basename to the
        // materialized target, relative to the workspace tests/ dir.
        $rewritten = preg_replace(
            '/((?:require|require_once|include|include_once)\b[^;]*?)([\'"][^\'"]*'.preg_quote($basename, '/').'[\'"])/',
            '$1__DIR__ . '."'/../".$targetRel."'",
            $body,
        );
        if (! is_string($rewritten) || $rewritten === '') {
            return null;
        }
        // The rewrite MUST have changed something (a require of the basename existed).
        if ($rewritten === $body) {
            return null;
        }

        return $rewritten;
    }

    private function objectiveText(string $targetRepoRelPath, int $cyclomatic): string
    {
        // Stable per-kind template (dedupe is on objective text — keep refactor + edge-gap
        // tasks for the SAME file distinct, but stable across cycles so we don't bloat the queue).
        return 'Reduce the cyclomatic complexity of '.basename($targetRepoRelPath)
            .' (worst method = '.$cyclomatic.') WITHOUT changing behavior. The sibling test is FROZEN '
            .'and must stay green; simplify or extract the worst method so the AST cyclomatic measure drops.';
    }

    private function analyzer(): AtlasLoopSignalAnalyzer
    {
        return $this->signalAnalyzer ?? new AtlasLoopSignalAnalyzer();
    }
}
