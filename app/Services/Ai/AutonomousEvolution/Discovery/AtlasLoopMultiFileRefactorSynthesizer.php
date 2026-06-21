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
            $support = new AtlasLoopMultiFileRefactorSynthesizerSupport();
            $artifacts = $support->collectCoveredArtifacts($files, $hub, $repoRoot, $guard, $resolver);
            if ($artifacts === null) {
                return null;
            }

            return $support->synthesizeFromArtifacts(
                $artifacts,
                $files,
                $hub,
                $cluster,
                $provider,
                (bool) config('atlas.loop.multi_file_hub_first_enabled', false),
                (bool) config('atlas.loop.cluster_framing_degrade_enabled', false),
                fn (array $covered): bool => $this->clusterFramingThrashes($covered),
            );
        } catch (Throwable) {
            return null; // fail-closed: never enqueue an unprovable multi-file refactor
        }
    }

    /**
     * ACDE MF5 — PURE threshold over the decomposition-corpus history for a cluster framing: thrashing iff it
     * has been EXECUTED at least 4 times with a certified-rate below 30%. Unit-testable without a database.
     *
     * @param  array{certified?:int, total?:int}  $history
     */
    public function framingThrashesFromHistory(array $history): bool
    {
        $total = (int) ($history['total'] ?? 0);
        if ($total < 4) {
            return false; // never degrade on thin evidence
        }

        return ((int) ($history['certified'] ?? 0)) / $total < 0.3;
    }

    /**
     * ACDE MF5 — read the corpus history for this cluster framing (fingerprinted like an extract sequence) and
     * decide whether it thrashes. Fail-closed: any error / disabled corpus (history empty) returns false.
     *
     * @param  list<string>  $covered
     */
    private function clusterFramingThrashes(array $covered): bool
    {
        try {
            $nodes = array_map(static fn (string $f): array => ['target_area' => $f], $covered);
            $fingerprint = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint(['nodes' => $nodes]);
            $history = (new \App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder)
                ->history((string) ($fingerprint['hash'] ?? ''));

            return $this->framingThrashesFromHistory($history);
        } catch (Throwable) {
            return false;
        }
    }

}
