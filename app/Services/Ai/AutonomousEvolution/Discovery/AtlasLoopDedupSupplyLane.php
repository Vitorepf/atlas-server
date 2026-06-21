<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;

/**
 * §5.6 · DEDUP — the SUPPLY lane: the brain ORIGINATING certifiable clone-unification tasks from the
 * comprehension model's clone clusters. This is net-new work the proxy discovery (cyclomatic/coverage scan of
 * files-that-exist) STRUCTURALLY cannot produce — and it deliberately does NOT route through the rédea's
 * cyclomatic ambition floor (which would drop a low-complexity true clone): the value signal is
 * duplication-removed (`dedup_proof`), proven by the frozen judge's Guard 4d count-drop, not cyclomatic.
 *
 * ADMISSIBILITY (so a minted task is genuinely certifiable + not a false-clone farm), per cluster:
 *   1. STRICT clone — the lossy discovery cluster must be a TRUE clone under the order+literal-aware normalizer
 *      ({@see AtlasLoopDedupProof}); a literal/order-divergent false clone is NEVER minted.
 *   2. PER-MEMBER behavior anchor — every member file has a frozen sibling test that executes ≥1 real assertion
 *      ({@see AtlasLoopSiblingTestResolver}); a member without a real anchor cannot be safely unified.
 *   3. petreo/FORBIDDEN members are excluded (never unify the judge/merge organs).
 * A cluster reduced below 2 admissible members is dropped. Pure of side effects (reads the tree + resolves
 * siblings; mints SPECS, never enqueues — the refiller does that). The minted acceptance carries `dedup_proof`
 * + `clone_target` + the frozen member siblings, so the cert chain (Guard 3 behavior + Guard 4d count-drop)
 * is the sole authority on whether the unification is real.
 */
final class AtlasLoopDedupSupplyLane
{
    public const OBJECTIVE_KIND = 'refactor_dedup';

    public function __construct(
        private readonly ?AtlasLoopSiblingTestResolver $siblings = null,
        private readonly ?AtlasLoopDedupProof $dedupProof = null,
    ) {
    }

    /**
     * Mint a certifiable dedup task SPEC per admissible clone cluster in the model.
     *
     * @return list<array{objective:string, payload:array<string,mixed>, acceptance_hash:string, members:list<string>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $siblings = $this->siblings ?? new AtlasLoopSiblingTestResolver($repoRoot);
        $proof = $this->dedupProof ?? new AtlasLoopDedupProof;
        $forbidden = array_fill_keys($model->forbidden, true);

        $specs = [];
        foreach ($model->cloneClusters as $cluster) {
            $members = [];
            foreach (($cluster['members'] ?? []) as $m) {
                $rel = ltrim((string) ($m['path'] ?? ''), '/');
                if ($rel !== '' && ! isset($forbidden[$rel])) {
                    $members[$rel] = true;
                }
            }
            $members = array_keys($members);
            if (count($members) < 2) {
                continue;
            }

            // (2) PER-MEMBER anchor: every member has a sibling with >=1 asserting method.
            $frozen = [];
            $commands = [];
            $anchored = true;
            foreach ($members as $rel) {
                $r = $siblings->resolve($rel);
                if (($r['has_sibling'] ?? false) !== true || ($r['asserted_methods'] ?? []) === []) {
                    $anchored = false;
                    break;
                }
                $frozen[] = (string) $r['sibling_path'];
                $commands[] = './vendor/bin/phpunit '.escapeshellarg((string) $r['sibling_path']);
            }
            if (! $anchored) {
                continue;
            }

            // (1) STRICT clone: evaluate(sources, sources) is non-null iff >=2 members share a strict body hash.
            $sources = [];
            foreach ($members as $rel) {
                $sources[$rel] = (string) @file_get_contents($repoRoot.'/'.$rel);
            }
            $ev = $proof->evaluate($sources, $sources);
            if ($ev === null) {
                continue; // lossy-only false clone — never mint (would unify non-equivalent bodies)
            }

            $acceptance = [
                'commands' => $commands,
                'allowed_globs' => array_values(array_unique(array_map(
                    static fn (string $r): string => trim(\dirname($r), '/').'/**',
                    $members,
                ))),
                'frozen_globs' => array_values(array_unique($frozen)),
                'metric_kind' => AtlasEvolutionFrozenJudge::METRIC_MINIMIZE,
                'dedup_proof' => true,
                'clone_target' => [
                    'clone_hash' => (string) $ev['target_hash'],
                    'members' => array_map(static fn (string $r): array => ['path' => $r], $members),
                ],
                'revert_recheck' => false,
            ];
            $objective = 'Unify the structural clone across '.implode(', ', $members)
                .' behind one implementation: remove the duplication (behavior preserved on every member sibling).';
            $payload = [
                'objective_kind' => self::OBJECTIVE_KIND,
                'materializer' => 'framework',
                'dedup_proof' => true,                  // the material key isProxyRefactorTask recognises
                'comprehension_originated' => true,     // provenance: the brain, not the proxy scan
                'acceptance' => $acceptance,
                'allowed_files' => $members,
                'clone_target' => $acceptance['clone_target'],
            ];

            $specs[] = [
                'objective' => $objective,
                'payload' => $payload,
                'acceptance_hash' => hash('sha256', (string) (json_encode($acceptance) ?: $objective)),
                'members' => $members,
            ];
        }

        return $specs;
    }
}
