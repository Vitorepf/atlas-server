<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * §5.6 · ORPHAN-WIRING — the SUPPLY lane: the brain ORIGINATING wiring directives from the comprehension
 * model's orphans (built-but-unwired capabilities the proxy cyclomatic/coverage scan never surfaces as work).
 *
 * Unlike the dedup lane, the acceptance is NOT pre-baked — the wired-behavior test cannot pre-exist, so this
 * lane mints a DIRECTIVE (orphan + its proven sibling) that {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopOrphanWiringExecutionAdapter}
 * + the engine turn into an earned-RED test → wiring → Guard-4e-certified evolution. The lane mints SPECS only
 * (never enqueues — the refiller does that), and the cert chain remains the sole authority on realness.
 *
 * ADMISSIBILITY (so a directive is a genuine evolution, never "wire dead code" or "wire the judge"):
 *   1. a REAL orphan — the model reports the class at ZERO production callers (its OWN non-gameable caller grep);
 *   2. NOT pétreo/forbidden — never wire the judge/merge/constitution organs;
 *   3. exposes ≥1 PUBLIC method — there is behavior to invoke;
 *   4. has a frozen sibling test with ≥1 real assertion ({@see AtlasLoopSiblingTestResolver}) — a TESTED, working
 *      capability worth wiring. A bare/untested orphan is dead scaffolding (delete, don't wire) ⇒ NEVER minted.
 */
final class AtlasLoopOrphanWiringSupplyLane
{
    public const OBJECTIVE_KIND = 'orphan_wiring';

    public function __construct(private readonly ?AtlasLoopSiblingTestResolver $siblings = null) {}

    /**
     * Mint one orphan-wiring directive per admissible orphan in the model.
     *
     * @return list<array{objective:string, payload:array<string,mixed>, members:list<string>}>
     */
    public function mint(AtlasLoopScopeComprehensionModel $model, string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $siblings = $this->siblings ?? new AtlasLoopSiblingTestResolver($repoRoot);
        $orphanFqcns = array_fill_keys($model->orphans, true);
        $forbidden = array_fill_keys($model->forbidden, true);

        $specs = [];
        foreach ($model->inventory as $node) {
            $rel = ltrim((string) ($node['rel_path'] ?? ''), '/');
            $fqcn = ltrim((string) ($node['fqcn'] ?? ''), '\\');
            if ($rel === '' || $fqcn === '') {
                continue;
            }

            // (1) real orphan + (2) not pétreo (model flag AND rel_path forbidden list, defense-in-depth).
            $isOrphan = ($node['is_orphan'] ?? false) === true && isset($orphanFqcns[$fqcn]);
            if (! $isOrphan || ($node['is_forbidden'] ?? false) === true || isset($forbidden[$rel])) {
                continue;
            }

            // (3) something to invoke.
            $publicMethods = array_values(array_filter((array) ($node['public_methods'] ?? []), 'is_string'));
            if ($publicMethods === []) {
                continue;
            }

            // (4) tested, working capability (not dead scaffolding to delete).
            $sib = $siblings->resolve($rel);
            if (($sib['has_sibling'] ?? false) !== true || ($sib['asserted_methods'] ?? []) === []) {
                continue;
            }

            $specs[] = [
                'objective' => sprintf('Wire the orphaned capability %s into a production caller and prove it load-bearing.', $fqcn),
                'payload' => [
                    'objective_kind' => self::OBJECTIVE_KIND,
                    'source' => self::OBJECTIVE_KIND,
                    'orphan_path' => $rel,
                    'orphan_fqcn' => $fqcn,
                    'public_methods' => $publicMethods,
                    'sibling_test' => $sib['sibling_path'],
                    // The executor + Guard 4e contract; the acceptance commands are engine-authored (earned-RED).
                    'wired_proof' => true,
                    'wired_target' => ['orphan_path' => $rel],
                ],
                'members' => [$rel],
            ];
        }

        return $specs;
    }
}
