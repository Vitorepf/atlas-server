<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Throwable;

/**
 * OPTION 3 · PRODUCE half — the deterministic, provider-free synthesizer that turns a coherent
 * multi-file CLUSTER ({@see AtlasLoopObraClusterCandidate}: a wired hub + its measured production
 * callers) into a multi-file `refactor_reduce_complexity` TASK objective, the sibling of the
 * single-file {@see AtlasLoopFrameworkRefactorSynthesizer}.
 *
 * The objective spans >=2 genuinely-coupled files and carries a multi-file complexity_proof
 * acceptance. The certification is a CONJUNCTION enforced downstream: the obra/framework path
 * re-runs the REAL convention sibling test of EVERY file in the cluster (behavior preserved across
 * the whole cluster — the loop can never edit tests/**) AND the certifier proves a REAL AST
 * cyclomatic DROP aggregated over the cluster (candidate max-per-method < baseline, total not
 * increasing) by the judge's OWN measure. This synthesizer NEVER measures complexity itself — it
 * inherits the measured cyclomatic_total from the cluster's leverage signals; the judge decides.
 *
 * FAIL-CLOSED by construction: the HUB must have a real readable sibling test (the primary
 * behavior anchor); a caller without a readable sibling is DROPPED (an unanchored change cannot be
 * proven safe); any forbidden self-target member, or fewer than 2 covered files remaining, returns
 * null so the caller falls through byte-identical to today. PROVIDER-FREE: no provider call, no
 * repo mutation; the actual coordinated multi-file change is made later on a throwaway worktree by
 * the grinder, and the change NEVER auto-merges (operator approval is required for every merge).
 */
final class AtlasLoopMultiFileRefactorSynthesizer
{
    public const OBJECTIVE_KIND = 'refactor_reduce_complexity';

    public function __construct(
        private readonly ?AtlasLoopSiblingTestResolver $siblingTests = null,
        private readonly ?AtlasLoopHarnessGuard $harnessGuard = null,
    ) {}

    /**
     * Synthesize a multi-file refactor task payload from a detected cluster, or null when the
     * cluster cannot honestly anchor a behavior-preserving multi-file refactor.
     *
     * @param  array<string,mixed>  $signals  optional packet (carries `provider` when set)
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string}|null
     */
    public function synthesizeMultiFileRefactor(AtlasLoopObraClusterCandidate $cluster, string $repoRoot, array $signals = [], string $provider = ''): ?array
    {
        try {
            $repoRoot = rtrim($repoRoot, '/');
            $hub = ltrim($cluster->hubPath, '/');
            $files = array_values(array_unique(array_map(static fn (string $f): string => ltrim($f, '/'), $cluster->allowedFiles)));
            if ($hub === '' || count($files) < 2) {
                return null;
            }

            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard();
            $resolver = $this->siblingTests ?? new AtlasLoopSiblingTestResolver($repoRoot);

            $covered = [];
            $frozen = [];
            $siblingRels = [];
            foreach ($files as $file) {
                // PÉTREO belt: a forbidden self-target ANYWHERE in the cluster kills the candidate
                // (the loop never refactors its own gates/judge/never-merge, even transitively).
                if ($guard->isForbiddenSelfTarget($file)) {
                    return null;
                }
                $sib = $resolver->resolve($file);
                $sibRel = is_string($sib['sibling_path'] ?? null) ? ltrim((string) $sib['sibling_path'], '/') : '';
                $body = '';
                if (($sib['has_sibling'] ?? false) && $sibRel !== '' && is_file($repoRoot.'/'.$sibRel)) {
                    $body = (string) @file_get_contents($repoRoot.'/'.$sibRel);
                }
                if ($body === '') {
                    // No readable behavior anchor: the HUB is REQUIRED (its sibling is the primary
                    // anchor); a caller without one is DROPPED, not guessed.
                    if ($file === $hub) {
                        return null;
                    }

                    continue;
                }
                $covered[] = $file;
                $frozen[] = ['path' => $sibRel, 'content' => $body];
                $siblingRels[] = $sibRel;
            }

            // Need the hub + at least one covered caller = a genuinely multi-file (>=2) anchored set.
            $covered = array_values(array_unique($covered));
            sort($covered);
            if (count($covered) < 2 || ! in_array($hub, $covered, true)) {
                return null;
            }
            $siblingRels = array_values(array_unique($siblingRels));
            sort($siblingRels);

            // One PHPUnit invocation over EVERY covered file's frozen sibling — behavior preserved
            // across the whole cluster. The path is FROZEN (tests/** the loop can never edit).
            // revert_recheck=false: a behavior-PRESERVING refactor stays green reverted; the
            // anti-fake proof here is the aggregated complexity DROP, not diff-earned.
            $command = './vendor/bin/phpunit '.implode(' ', array_map('escapeshellarg', $siblingRels));
            $acceptance = [
                'commands' => [$command],
                'allowed_globs' => $covered,
                'frozen_globs' => ['tests/**', 'phpunit.xml', 'phpunit.xml.dist', 'composer.json'],
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
                'complexity_proof' => true,
                'complexity_aggregation' => 'max_per_method_primary_total_non_increasing',
                'revert_recheck' => false,
                'timeout_seconds' => max(60, (int) config('atlas.loop.multi_file_refactor_timeout_seconds', 600)),
            ];

            $objective = 'Refactor a coupled cluster ('.basename($hub).' + '.(count($covered) - 1).' caller(s)) to '
                .'substantially REDUCE complexity ACROSS the files (simplify/extract/dedupe shared logic) while '
                .'PRESERVING behavior — every file\'s existing tests must stay green.';

            $acceptanceHash = hash('sha256', json_encode([
                'commands' => $acceptance['commands'],
                'allowed_globs' => $acceptance['allowed_globs'],
                'frozen_globs' => $acceptance['frozen_globs'],
                'metric_kind' => $acceptance['metric_kind'],
                'objective_kind' => self::OBJECTIVE_KIND,
                'multi_file' => true,
            ], JSON_THROW_ON_ERROR));

            $payload = [
                'materializer' => 'framework',
                'objective_kind' => self::OBJECTIVE_KIND,
                'multi_file' => true,
                'cluster_hash' => $cluster->clusterHash,
                'target_relative_path' => $hub,
                'target_repo_path' => $hub,
                'frozen_tests' => $frozen,
                'acceptance' => $acceptance,
                'allowed_files' => $covered,
                'validation_commands' => [$command],
                'leverage_signals' => $cluster->leverageSignals,
            ];
            if ($provider !== '') {
                $payload['provider'] = $provider;
            }

            return ['objective' => $objective, 'payload' => $payload, 'acceptance_hash' => $acceptanceHash];
        } catch (Throwable) {
            return null; // fail-closed: never enqueue an unprovable multi-file refactor
        }
    }
}
