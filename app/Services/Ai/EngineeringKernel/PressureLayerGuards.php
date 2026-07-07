<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use App\Services\Ai\Obra\AtlasBlastRadiusService;

/**
 * Cognitive Pressure Layer — the 3 initial ADVISORY guard roles (Atlas Orchestrator
 * Canon + acos-cognitive-role-matrix-8-minimal). Each guard is width/deterministic —
 * it NEVER calls a model and NEVER fabricates a verdict. It REUSES an existing live
 * signal, produces a pass/fail verdict, BLOCKS a bad land in the moment (gate), and
 * records the verdict into the SAME proof-gated outcome ledger the Decision Core
 * (ADML, Goal 2) weighs — so the verdicts compose via proven_real, not only block.
 *
 *   - runtime_verifier      : a new organ must be invoked (>0 callers). 0 = orphan (VETO #20).
 *   - context_cartographer  : a cited symbol must resolve in the code index. 0 hits = hallucination.
 *   - boundary_wiring_guard : the diff must be ⊆ the declared blast radius. Outside = scope creep / wiper.
 *
 * Reuse-first (no rebuilt signal): AtlasLoopWiredCallerService (T-caller counts),
 * AtlasLoopComprehensionGroundingGate (code-index symbol resolve),
 * AtlasBlastRadiusService (WO-17-T3 deterministic radius).
 *
 * Canon: docs/engineering-knowledge-base/atlas-orchestrator-canon.md.
 */
final class PressureLayerGuards
{
    public const RUNTIME_VERIFIER = 'runtime_verifier';

    public const CONTEXT_CARTOGRAPHER = 'context_cartographer';

    public const BOUNDARY_WIRING_GUARD = 'boundary_wiring_guard';

    /** The 3 initial advisory guard roles + the failure each prevents + the reused signal. */
    public const ADVISORY_ROLES = [
        [
            'role_id' => self::RUNTIME_VERIFIER,
            'prevents' => 'orphan_organ_zero_invocation',
            'signal' => 'wired_caller_count_gt_0',
            'tier' => 'width',
        ],
        [
            'role_id' => self::CONTEXT_CARTOGRAPHER,
            'prevents' => 'hallucinated_symbol',
            'signal' => 'symbol_resolves_in_code_index',
            'tier' => 'width',
        ],
        [
            'role_id' => self::BOUNDARY_WIRING_GUARD,
            'prevents' => 'edit_outside_blast_radius',
            'signal' => 'diff_subset_of_blast_radius',
            'tier' => 'width',
        ],
    ];

    /**
     * @param  null|\Closure(string):?int  $callerCountOverride  test seam for the FINAL wired-caller
     *                                                           service (null in production → real service)
     */
    public function __construct(
        private readonly AtlasLoopWiredCallerService $wiredCaller,
        private readonly AtlasLoopComprehensionGroundingGate $grounding,
        private readonly AtlasBlastRadiusService $blastRadius,
        private readonly AtlasDecideLiveOutcomeFeedbackService $feedback,
        private readonly ?\Closure $callerCountOverride = null,
    ) {}

    /**
     * The advisory guard roles for the AAWR role_roster (Pressure Layer).
     *
     * @return list<array<string,mixed>>
     */
    public static function advisoryRoles(): array
    {
        return self::ADVISORY_ROLES;
    }

    /**
     * runtime_verifier — a newly-added organ must be invoked at least once. REUSES
     * AtlasLoopWiredCallerService::callerCount (grep + code-graph, tri-state):
     * null (unmeasured) → fail-OPEN (pass, not proven); 0 → orphan (FAIL); >0 → invoked (pass, proven).
     *
     * @return array{guard:string,pass:bool,proven_real:bool,detail:string,evidence:array<string,mixed>}
     */
    public function runtimeVerifier(string $relPath): array
    {
        $count = $this->callerCount($relPath);

        if ($count === null) {
            return $this->verdict(self::RUNTIME_VERIFIER, true, false, 'unmeasured_fail_open', ['rel_path' => $relPath, 'caller_count' => null]);
        }
        if ($count <= 0) {
            return $this->verdict(self::RUNTIME_VERIFIER, false, false, 'orphan_zero_invocation', ['rel_path' => $relPath, 'caller_count' => 0]);
        }

        return $this->verdict(self::RUNTIME_VERIFIER, true, true, 'invoked', ['rel_path' => $relPath, 'caller_count' => $count]);
    }

    /**
     * context_cartographer — every cited symbol must resolve in the code index. REUSES
     * AtlasLoopComprehensionGroundingGate::ground; grounded=false with unresolved symbols
     * → hallucination (FAIL). A grounded fail-open (no citations / unreadable root) passes
     * but is NOT proven.
     *
     * @param  list<string>  $citedSymbols
     * @return array{guard:string,pass:bool,proven_real:bool,detail:string,evidence:array<string,mixed>}
     */
    public function contextCartographer(string $statedObjective, array $citedSymbols, ?string $repoRoot = null): array
    {
        $root = $repoRoot ?? (function_exists('base_path') ? base_path() : (getcwd() ?: '.'));
        $g = $this->grounding->ground($statedObjective, $citedSymbols, (string) $root);

        $grounded = ($g['grounded'] ?? true) === true;
        $ungrounded = is_array($g['ungrounded'] ?? null) ? $g['ungrounded'] : [];
        $citationCount = (int) ($g['citation_count'] ?? 0);

        if (! $grounded && $ungrounded !== []) {
            return $this->verdict(self::CONTEXT_CARTOGRAPHER, false, false, 'hallucinated_symbol', ['ungrounded' => $ungrounded, 'note' => (string) ($g['note'] ?? '')]);
        }

        // Proven only when symbols were actually cited AND all resolved. Fail-open passes are unproven.
        $proven = $grounded && $citationCount > 0 && $ungrounded === [];

        return $this->verdict(self::CONTEXT_CARTOGRAPHER, true, $proven, $proven ? 'symbols_resolve' : 'grounded_fail_open', ['resolved' => $g['resolved'] ?? [], 'note' => (string) ($g['note'] ?? '')]);
    }

    /**
     * boundary_wiring_guard — every changed file must be ⊆ the declared blast radius. REUSES
     * AtlasBlastRadiusService::radiusFor (deterministic first-order call graph, WO-17-T3). A
     * diff file outside (declared ∪ affected ∪ tests) → scope overreach / wiper (FAIL).
     *
     * @param  list<string>  $declaredTargets  repo-relative paths the work declared it would touch
     * @param  list<string>  $diffFiles  repo-relative paths the diff actually touched
     * @return array{guard:string,pass:bool,proven_real:bool,detail:string,evidence:array<string,mixed>}
     */
    public function boundaryWiringGuard(array $declaredTargets, array $diffFiles, ?string $repoPath = null): array
    {
        $radius = $this->blastRadius->radiusFor(array_values($declaredTargets), $repoPath);

        $allowed = array_flip(array_merge(
            $this->normPaths(is_array($radius['changed'] ?? null) ? $radius['changed'] : []),
            $this->normPaths(is_array($radius['affected'] ?? null) ? $radius['affected'] : []),
            $this->normPaths(is_array($radius['tests'] ?? null) ? $radius['tests'] : []),
            $this->normPaths($declaredTargets),
        ));

        $outside = [];
        foreach ($this->normPaths($diffFiles) as $f) {
            if (! isset($allowed[$f])) {
                $outside[] = $f;
            }
        }

        if ($outside !== []) {
            return $this->verdict(self::BOUNDARY_WIRING_GUARD, false, false, 'edit_outside_blast_radius', ['outside' => $outside, 'radius_size' => count($allowed)]);
        }

        return $this->verdict(self::BOUNDARY_WIRING_GUARD, true, $diffFiles !== [], 'diff_within_radius', ['radius_size' => count($allowed), 'diff_size' => count($this->normPaths($diffFiles))]);
    }

    /**
     * The consumer/gate: a FAILED verdict BLOCKS the land + routes to human_review.
     *
     * @param  array<string,mixed>  $verdict
     * @return array{blocked:bool,guard:string,reason:string,requires_human_review:bool}
     */
    public function gate(array $verdict): array
    {
        $pass = ($verdict['pass'] ?? false) === true;

        return [
            'blocked' => ! $pass,
            'guard' => (string) ($verdict['guard'] ?? ''),
            'reason' => (string) ($verdict['detail'] ?? ''),
            'requires_human_review' => ! $pass,
        ];
    }

    /**
     * The outcome: persist the verdict into the SAME live outcome ledger the Decision Core
     * (ADML, Goal 2) weighs. role = the guard id, provider = the runtime whose candidate was
     * judged (so the loop learns which runtime produces clean work), result = success|failure,
     * proven_real = the deterministic verdict. NEVER fabricates proven_real — a failing guard
     * is a real failure (never proven); only a confirmed guard is proven_real.
     *
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed> the ledger entry
     */
    public function recordVerdict(array $verdict, string $taskCategory, string $runtime, string $actor = 'pressure_layer'): array
    {
        return $this->feedback->record([
            'task_category' => $taskCategory,
            'role' => (string) ($verdict['guard'] ?? ''),
            'provider' => $runtime,
            'result' => ($verdict['pass'] ?? false) === true
                ? AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS
                : AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
            'proven_real' => ($verdict['proven_real'] ?? false) === true,
            'actor' => $actor,
        ]);
    }

    /**
     * PRODUCER — run all 3 advisory guards over a just-landed slice and record each verdict
     * into the outcome ledger (this is what makes the guards produce CADENCE). ADVISORY: it
     * NEVER blocks (the commit already happened) and is fail-open per guard (a guard error is
     * swallowed so a landed slice is never disturbed).
     *
     * Inputs the land seam provides: the declared scoped paths, the actually-committed files,
     * and the objective. runtime_verifier runs per landed app/**\/*.php organ (orphan signal =
     * VETO #20); boundary_wiring_guard checks committed ⊆ declared radius (commit-scope
     * integrity); context_cartographer grounds any FQCN cited in the objective (hallucination),
     * fail-open when the objective cites none.
     *
     * @param  list<string>  $declaredPaths  the paths the slice declared it would land
     * @param  list<string>  $committedFiles  the files actually committed
     * @return array{schema:string,advisory:bool,blocked:bool,ran:int,recorded:int,verdicts:list<array<string,mixed>>}
     */
    public function observeLandedSlice(array $declaredPaths, array $committedFiles, string $objective, string $runtime, string $taskCategory = 'programming'): array
    {
        $verdicts = [];
        $recorded = 0;

        $emit = function (array $verdict) use (&$verdicts, &$recorded, $taskCategory, $runtime): void {
            $verdicts[] = $verdict;
            try {
                $this->recordVerdict($verdict, $taskCategory, $runtime, 'pressure_layer_on_land');
                $recorded++;
            } catch (\Throwable) {
                // fail-open: a ledger write must never disturb a landed slice.
            }
        };

        // runtime_verifier — one verdict per landed organ (app/**/*.php). Console commands are
        // framework-invoked (auto-discovered, never code-referenced), so "0 callers" is expected,
        // not an orphan — skip them to keep the cadence honest (no systematic false-positive).
        foreach ($this->normPaths($committedFiles) as $file) {
            if (! str_starts_with($file, 'app/') || ! str_ends_with($file, '.php')) {
                continue;
            }
            if (str_starts_with($file, 'app/Console/')) {
                continue;
            }
            try {
                $emit($this->runtimeVerifier($file));
            } catch (\Throwable) {
            }
        }

        // boundary_wiring_guard — the committed files must stay within the declared scope's radius.
        try {
            $emit($this->boundaryWiringGuard($declaredPaths, $committedFiles));
        } catch (\Throwable) {
        }

        // context_cartographer — ground any FQCN the objective explicitly cites (fail-open otherwise).
        try {
            $emit($this->contextCartographer($objective, $this->citedFqcns($objective)));
        } catch (\Throwable) {
        }

        return [
            'schema' => 'atlas.pressure_layer.on_land_observation.v1',
            'advisory' => true,
            'blocked' => false, // advisory-first: the guards never block a land yet
            'ran' => count($verdicts),
            'recorded' => $recorded,
            'verdicts' => $verdicts,
        ];
    }

    /**
     * FQCN-shaped citations in prose = tokens containing a namespace separator. Requiring a
     * backslash avoids false-positives on ordinary Capitalized words, so the cartographer only
     * fires on a REAL symbol citation and never fabricates a hallucination out of prose.
     *
     * @return list<string>
     */
    private function citedFqcns(string $objective): array
    {
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $objective, $m) === false) {
            return [];
        }

        return array_values(array_unique($m[0] ?? []));
    }

    /** Tri-state caller count via the real service, or the test override when supplied. */
    private function callerCount(string $relPath): ?int
    {
        return $this->callerCountOverride !== null
            ? ($this->callerCountOverride)($relPath)
            : $this->wiredCaller->callerCount($relPath);
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return array{guard:string,pass:bool,proven_real:bool,detail:string,evidence:array<string,mixed>}
     */
    private function verdict(string $guard, bool $pass, bool $provenReal, string $detail, array $evidence): array
    {
        return [
            'guard' => $guard,
            'pass' => $pass,
            'proven_real' => $provenReal,
            'detail' => $detail,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<int,mixed>  $paths
     * @return list<string>
     */
    private function normPaths(array $paths): array
    {
        return array_values(array_map(static fn ($p): string => ltrim((string) $p, '/'), $paths));
    }
}
