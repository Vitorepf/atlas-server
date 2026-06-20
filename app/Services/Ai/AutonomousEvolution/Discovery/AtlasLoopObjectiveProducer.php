<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopExecutionContract;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternCompiler;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternDecisionDriver;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSelector;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Throwable;

/**
 * THE RÉDEA — the autonomous high-leverage objective producer.
 *
 * Given the campaign's candidate files and the {@see StateOfAtlas} (read once per cycle from the
 * brain), it: skips forbidden/petreo targets, reads structural signals (callers=breadth,
 * cyclomatic=debt/compounding), takes strategic alignment from the State, scores each with
 * {@see AtlasLoopLeverageScorer}, keeps only the floor-passers, runs the {@see AtlasLoopAdversarialCritic}
 * ("biggest leap, not easiest-looking?"), and hands the winner to {@see AtlasLoopOriginationBuilder}
 * which originates a BIG verifiable objective (heavy refactor OR a RED-verified feature). Returns null
 * when nothing clears the floor — the loop never gets a trivia task from the rédea.
 *
 * Design: select() is PURE (frozen under test). The expensive brain comprehension is the State, read
 * ONCE per cycle (not per file). Builds ON existing machinery; adds only the decision orchestration.
 */
final class AtlasLoopObjectiveProducer
{
    public function __construct(
        private readonly AtlasLoopLeverageScorer $scorer = new AtlasLoopLeverageScorer,
        private readonly ?AtlasLoopSignalAnalyzer $analyzer = null,
        private readonly ?AtlasLoopStateOfAtlasReader $stateReader = null,
        private readonly ?AtlasLoopAdversarialCritic $critic = null,
        private readonly ?AtlasLoopOriginationBuilder $origination = null,
        // LOOP-OS Fase 4 — the EV brain (Slices 6/7/7.5). Nullable; Laravel does NOT auto-inject
        // `?Type $x = null` (AppServiceProvider:305-306), so these resolve to null and the `?? new`
        // accessors below are the mandatory backstop, NOT optional.
        private readonly ?AtlasLoopSystemAxisService $axis = null,
        private readonly ?AtlasLoopTouchesAxesProducer $touches = null,
        private readonly ?AtlasLoopExpectedValueDecider $ev = null,
        // LOOP-PATTERN-REGISTRY Slice 1 — advisory pattern selection + ExecutionContract. Nullable (same
        // DI caveat as above: `?Type $x = null` is not auto-injected, so the `?? new` accessors are the
        // mandatory backstop). Read-only/advisory: they never reorder or gate origination.
        private readonly ?AtlasLoopPatternRegistry $patternRegistry = null,
        private readonly ?AtlasLoopPatternSelector $patternSelector = null,
        private readonly ?AtlasLoopPatternCompiler $patternCompiler = null,
        // LOOP-PATTERN-REGISTRY A0 — the DECISION DRIVER. Same nullable DI caveat (the `?? new` accessor is
        // the mandatory backstop). When atlas.loop.pattern_driver_enabled is ON this DRIVES origination:
        // the selector/registry filter/reorder/gate the floor-passers BEFORE a goal is built. OFF (default)
        // it never runs and the legacy advisory path below is byte-identical.
        private readonly ?AtlasLoopPatternDecisionDriver $patternDriver = null,
    ) {}

    /**
     * PURE selection: score the pre-gathered packets, rank by leverage, return the top candidate
     * that clears the ambition floor, or null if none does.
     *
     * @param  list<array<string,mixed>>  $packets
     * @return array<string,mixed>|null
     */
    public function select(array $packets): ?array
    {
        foreach ($this->scorer->rank($packets) as $candidate) {
            if ($this->scorer->passesAmbitionFloor($candidate['_score'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Cheap signal pass over the candidates (no per-file brain call — strategic comes from the
     * State, read once). Forbidden/petreo targets are dropped up front (the alignment floor).
     *
     * @param  list<string>  $relPaths
     * @return list<array<string,mixed>>
     */
    public function gather(string $repoRoot, array $relPaths, StateOfAtlas $state): array
    {
        $callers = $this->callerCounts($repoRoot, $relPaths);
        $packets = [];

        foreach ($relPaths as $rel) {
            $rel = ltrim((string) $rel, '/');
            if ($rel === '' || $state->isForbidden($rel)) {
                continue;
            }
            $abs = rtrim($repoRoot, '/').'/'.$rel;
            $cyclomatic = $this->cyclomaticOf($abs);
            $hasSiblingTest = $this->hasSiblingTest($repoRoot, $rel);
            $callerCount = $callers[$rel] ?? ($callers[ltrim($rel, '/')] ?? 0);
            $verifiable = $hasSiblingTest && $cyclomatic >= (int) config('atlas.loop.decision_min_refactor_cyclomatic', 10);

            $packets[] = [
                'path' => $rel,
                'caller_count' => $callerCount,
                'cyclomatic' => $cyclomatic,
                'strategic_impact' => $state->strategicWeightFor($rel),
                'cost' => $this->costOf($abs),
                'risk' => $hasSiblingTest ? 0.4 : 0.85,
                'verifiable' => $verifiable,
                // A — honest value signal (feeds the DecisionDriver's pre-origination veto). Computed from
                // REAL signals: a refactor candidate is MATERIAL only with a behaviour anchor + real
                // complexity + wiredness; else proxy/cosmetic with auditable reasons.
            ] + $this->classifyCandidateValue($cyclomatic, (int) $callerCount, $verifiable);
        }

        return $packets;
    }

    /**
     * A — classify a refactor candidate's WORK VALUE from real signals, the input the DecisionDriver's
     * anti-cosmetic veto needs (without it the veto is starved and rubber-stamps every floor-passer).
     *
     * Honest rule (matches the goal's own "valor real É: refactor material com queda real de
     * complexidade/acoplamento e prova de comportamento"):
     *   - MATERIAL (real value) ⟺ behaviour anchor (verifiable) AND real complexity to reduce
     *     (cyclomatic ≥ material_refactor_min_cyclomatic) AND wired (≥1 caller).
     *   - everything else is PROXY (behaviour-preserving without proof/materiality) with auditable reasons;
     *     the unambiguously-trivial subset (no complexity AND orphan) is additionally COSMETIC.
     * A candidate with no reliable signal tends to rejection, never approval.
     *
     * @return array{proxy:bool, cosmetic:bool, work_value_class:string, shape:string, proxy_reasons:list<string>}
     */
    private function classifyCandidateValue(int $cyclomatic, int $callerCount, bool $verifiable): array
    {
        $materialMin = max(1, (int) config('atlas.loop.material_refactor_min_cyclomatic', 12));

        $reasons = [];
        if (! $verifiable) {
            $reasons[] = 'no_behavior_anchor'; // no sibling test / sub-threshold cyclomatic ⇒ gate can't prove it
        }
        if ($cyclomatic < $materialMin) {
            $reasons[] = 'low_complexity_not_material';
        }
        if ($callerCount < 1) {
            $reasons[] = 'orphan_not_wired';
        }

        $material = $reasons === [];
        // Cosmetic = the unambiguous faxina: nothing material to reduce AND not a wired target.
        $cosmetic = $cyclomatic < $materialMin && $callerCount < 1;

        return [
            'proxy' => ! $material,
            'cosmetic' => $cosmetic,
            'work_value_class' => $material ? 'material_refactor' : ($cosmetic ? 'cosmetic' : 'proxy_refactor'),
            'shape' => 'refactor', // gather() originates refactors; the feature shape is decided downstream
            'proxy_reasons' => $reasons,
        ];
    }

    /**
     * Full produce: read State (once) → gather → score → floor → adversarial critic → originate the
     * biggest verifiable leap. Returns null when nothing clears the floor (never a trivial task).
     *
     * @param  list<string>  $relPaths
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string, target_path:string, shape:string, self_contained:bool, leverage:float, rationale:string}|null
     */
    public function produce(string $repoRoot, array $relPaths, string $provider, string $targetId, ?StateOfAtlas $state = null): ?array
    {
        if ($relPaths === []) {
            return null;
        }

        $state ??= $this->stateReader()->read($repoRoot);
        $packets = $this->gather($repoRoot, $relPaths, $state);
        if ($packets === []) {
            return null;
        }

        $ranked = $this->scorer->rank($packets);
        $floorPassers = array_values(array_filter(
            $ranked,
            fn (array $c): bool => $this->scorer->passesAmbitionFloor($c['_score']),
        ));
        if ($floorPassers === []) {
            return null;
        }

        // LOOP-OS Fase 4 — EV / theory-of-constraints refinement.
        // Reorders the floor-passers so the candidate that most relieves the BINDING system axis (Slice 6
        // fresh axis vector × Slice 7.5 machine touches_axes) leads; the adversarial critic below still
        // confirms the leap. Fail-open: any axis/EV hiccup leaves the leverage order untouched.
        //
        // S1 (ev_live_decision_enabled, default ON) — the EV ranking runs on the LIVE default, not just behind
        // the legacy producer_ev_pick_enabled flag. The legacy reorder path is byte-identical: when only
        // producer_ev_pick_enabled is set the SAME reorder runs and the new PARK/shape block is skipped.
        $evLive = (bool) config('atlas.loop.ev_live_decision_enabled', true);
        $evPick = null;
        if ($evLive || (bool) config('atlas.loop.producer_ev_pick_enabled', false)) {
            [$floorPassers, $evPick] = $this->reorderByExpectedValue($floorPassers, $repoRoot);
        }

        // S1 — HONEST PARK + shape hint on the live default. Capture the FULL EV decision: the binding axis
        // (from the reorder) and EACH floor-passer's REAL machine touches_axes. If the binding axis is a VALUE
        // axis (non_trivial/compounding/real_target) and NO originatable objective relieves it — i.e. every
        // floor-passer is a behaviour-preserving proxy refactor with no value contribution — RETURN null
        // (emit NO proxy refactor this cycle). When a value candidate exists, pass an explicit shape hint:
        // 'feature' ONLY when feature signals say so AND producer_feature_origination_enabled is armed.
        if ($evLive && $evPick !== null) {
            $bindingAxis = (string) ($evPick['binding_axis'] ?? '');
            $valueAxes = ['non_trivial', 'compounding', 'real_target'];
            if (in_array($bindingAxis, $valueAxes, true)) {
                $relieves = false;
                foreach ($floorPassers as $packet) {
                    if (in_array($bindingAxis, $this->touchesProducer()->forPacket($packet), true)) {
                        $relieves = true;
                        break;
                    }
                }
                if (! $relieves) {
                    return null; // PARK — every floor-passer is proxy-only; no value candidate to originate.
                }
            }

            // Explicit shape hint on the EV winner: feature only when the area says so AND the arm is on.
            if ((bool) config('atlas.loop.producer_feature_origination_enabled', false)
                && $this->featureSignalsSay($state, (string) ($floorPassers[0]['path'] ?? ''))) {
                $floorPassers[0]['shape'] = 'feature';
            }
        }

        // LOOP-PATTERN-REGISTRY A0 — the PatternRegistry DECISION DRIVER (flag pattern_driver_enabled).
        // When ON, the selector/registry DRIVE the choice over the EV-ordered floor-passers BEFORE any goal
        // is originated: a cosmetic / negligible-impact / non-finite-impact top candidate is REJECTED and the
        // next acceptable candidate wins; if NONE is acceptable the producer emits NO objective (governed
        // null, never an invented one). Fail-CLOSED: a selector/compile failure under the driver returns
        // null rather than falling through to ungoverned origination. When the flag is OFF this returns the
        // DISABLED terminal and the legacy advisory path below runs byte-identically.
        $driverDecision = $this->driveCandidateDecision($floorPassers);
        if ((string) ($driverDecision['state'] ?? '') !== AtlasLoopPatternDecisionDriver::STATE_DISABLED) {
            return $this->produceViaPatternDriver($state, $driverDecision, $repoRoot, $provider, $targetId, $evPick);
        }

        // Adversarial self-critique: pick the biggest genuine leap, not the cheapest-looking one.
        $verdict = $this->critic()->challenge($floorPassers[0], $floorPassers);
        $winner = $verdict['pick'];

        // Originate the BIG objective (refactor or RED-verified feature) for the winner.
        $built = $this->origination()->build($state, $winner, $repoRoot, $provider, $targetId);
        if ($built === null) {
            return null;
        }

        $rationale = (string) $winner['_score']['rationale'];
        if ($verdict['challenged']) {
            $rationale .= ' [critic: '.$verdict['reason'].']';
        }
        if ($evPick !== null) {
            $rationale .= ' [ev: binding='.$evPick['binding_axis'].' relief='.round($evPick['relief'], 3).']';
        }

        // LOOP-PATTERN-REGISTRY Slice 1 — advisory pattern + ExecutionContract on the originated objective.
        // Read-only: the selected pattern and compiled contract are ATTACHED, never used to reorder or gate
        // (the EV brain + adversarial critic above remain the sole deciders). Fail-open: any hiccup leaves
        // the objective exactly as it was.
        [$patternAttachment, $contractAttachment] = $this->attachPatternAdvisory($winner, $built);

        $payload = (array) $built['payload'];
        if ($patternAttachment !== null) {
            $payload['pattern'] = $patternAttachment;
            $payload['execution_contract'] = $contractAttachment;
        }

        return [
            'objective' => (string) $built['objective'],
            'payload' => $payload,
            'acceptance_hash' => (string) $built['acceptance_hash'],
            'target_path' => (string) $built['target_path'],
            'shape' => (string) $built['shape'],
            'self_contained' => (bool) $built['self_contained'],
            'leverage' => (float) $winner['_score']['leverage'],
            'rationale' => $rationale,
            'pattern' => $patternAttachment,
            'execution_contract' => $contractAttachment,
        ];
    }

    /**
     * LOOP-PATTERN-REGISTRY Slice 1 — advisory selection. Maps the originated winner to a selector
     * descriptor, asks the selector for the best SELECTABLE pattern, and compiles it into an
     * ExecutionContract. Returns [pattern|null, contract|null]. Pure-advisory and fail-OPEN: disabled by
     * flag or any throw yields [null, null], never altering origination. When the selector REJECTS
     * (e.g. cosmetic), it returns the honest rejection note as the pattern attachment with a null contract.
     *
     * @param  array<string,mixed>  $winner
     * @param  array<string,mixed>  $built
     * @return array{0:?array<string,mixed>, 1:?array<string,mixed>}
     */
    private function attachPatternAdvisory(array $winner, array $built): array
    {
        if (! (bool) config('atlas.loop.pattern_advisory_enabled', true)) {
            return [null, null];
        }

        try {
            $descriptor = $this->patternDescriptor($winner, $built);
            $selection = $this->patternSelector()->select($descriptor, $this->patternRegistry());

            $pattern = $selection['pattern'] ?? null;
            if ($pattern === null) {
                return [[
                    'selected' => null,
                    'rejected' => (bool) ($selection['rejected'] ?? true),
                    'reason' => (string) ($selection['reason'] ?? ''),
                ], null];
            }

            $contract = $this->patternCompiler()->compile($pattern, [
                'objective' => (string) ($built['objective'] ?? ''),
                'allowed_scope' => [(string) ($built['target_path'] ?? '')],
                'required_inputs' => ['target_path'],
                'expected_outputs' => $pattern->outputSchema,
                'budget' => [],
            ]);

            return [
                [
                    'selected' => $pattern->id,
                    'version' => $pattern->version,
                    'score' => (float) ($selection['score'] ?? 0.0),
                    'spec' => $pattern->toArray(),
                ],
                $contract->toArray(),
            ];
        } catch (Throwable) {
            return [null, null]; // fail-open — the advisory never blocks a real objective.
        }
    }

    /**
     * LOOP-PATTERN-REGISTRY A0 — run the DECISION DRIVER over the floor-passers (EV order). Gated by
     * atlas.loop.pattern_driver_enabled: OFF returns the DISABLED terminal (the caller falls back to the
     * legacy advisory path, byte-identical); ON hands the candidates + a PRE-ORIGINATION descriptor builder
     * to {@see AtlasLoopPatternDecisionDriver::decide()}, which returns the first selector-acceptable
     * candidate (rejecting cosmetic / negligible / non-finite-impact ones) or a governed terminal.
     *
     * Public so the decision can be driven directly under test with REAL packet signals — exactly the code
     * produce() runs — proving the driver changes the chosen candidate BEFORE origination, provider-free.
     *
     * @param  list<array<string,mixed>>  $floorPassers
     * @return array{state:string, selected:?array<string,mixed>, selected_index:?int, pattern:?AtlasLoopPatternSpec, score:float, rejections:list<array{index:int,path:string,reason:string}>}
     */
    public function driveCandidateDecision(array $floorPassers): array
    {
        if (! (bool) config('atlas.loop.pattern_driver_enabled', false)) {
            return [
                'state' => AtlasLoopPatternDecisionDriver::STATE_DISABLED,
                'selected' => null,
                'selected_index' => null,
                'pattern' => null,
                'score' => 0.0,
                'rejections' => [],
            ];
        }

        return $this->patternDriver()->decide(
            $floorPassers,
            fn (array $candidate): array => $this->driverDescriptorFor($candidate),
            $this->patternSelector(),
            $this->patternRegistry(),
        );
    }

    /**
     * LOOP-PATTERN-REGISTRY A0 — origination on the DRIVER's chosen candidate, then compile the contract
     * with the DRIVER-selected pattern (never re-selecting a different pattern post-origination, which would
     * mask the decision). Fail-CLOSED: any non-SELECTED terminal, a null build, or a compile failure returns
     * null so the loop never emits ungoverned work under the driver.
     *
     * @param  array{state:string, selected:?array<string,mixed>, pattern:?AtlasLoopPatternSpec, score:float, rejections:list<array<string,mixed>>}  $decision
     * @param  array{binding_axis:string, relief:float}|null  $evPick
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string, target_path:string, shape:string, self_contained:bool, leverage:float, rationale:string, pattern:array<string,mixed>, execution_contract:array<string,mixed>}|null
     */
    private function produceViaPatternDriver(StateOfAtlas $state, array $decision, string $repoRoot, string $provider, string $targetId, ?array $evPick): ?array
    {
        $winner = $decision['selected'] ?? null;
        $pattern = $decision['pattern'] ?? null;
        if (($decision['state'] ?? '') !== AtlasLoopPatternDecisionDriver::STATE_SELECTED
            || ! is_array($winner) || ! $pattern instanceof AtlasLoopPatternSpec) {
            return null; // governed: rejected_all / selector_failed / no_candidates — emit NO objective.
        }

        $built = $this->origination()->build($state, $winner, $repoRoot, $provider, $targetId);
        if ($built === null) {
            return null;
        }

        // Compile the contract with the SAME pattern the driver selected pre-origination — fail-closed.
        [$contract, $compileError] = $this->patternDriver()->compileContract($pattern, [
            'objective' => (string) ($built['objective'] ?? ''),
            'allowed_scope' => [(string) ($built['target_path'] ?? '')],
            'required_inputs' => ['target_path'],
            'expected_outputs' => $pattern->outputSchema,
            'budget' => [],
        ], $this->patternCompiler());

        if (! $contract instanceof AtlasLoopExecutionContract) {
            return null; // FAIL-CLOSED: driver ON + compile failed ⇒ no ungoverned objective ($compileError).
        }

        $rejections = is_array($decision['rejections'] ?? null) ? (array) $decision['rejections'] : [];
        $patternAttachment = [
            'mode' => 'driver',
            'selected' => $pattern->id,
            'version' => $pattern->version,
            'score' => (float) ($decision['score'] ?? 0.0),
            'spec' => $pattern->toArray(),
            'rejection_count' => count($rejections),
            'rejections' => array_values($rejections),
        ];
        $contractAttachment = $contract->toArray();

        $rationale = (string) ($winner['_score']['rationale'] ?? '');
        $rationale .= ' [pattern-driver: selected='.$pattern->id.' rejected='.count($rejections).']';
        if ($evPick !== null) {
            $rationale .= ' [ev: binding='.$evPick['binding_axis'].' relief='.round((float) $evPick['relief'], 3).']';
        }

        $payload = (array) $built['payload'];
        $payload['pattern'] = $patternAttachment;
        $payload['execution_contract'] = $contractAttachment;

        return [
            'objective' => (string) $built['objective'],
            'payload' => $payload,
            'acceptance_hash' => (string) $built['acceptance_hash'],
            'target_path' => (string) $built['target_path'],
            'shape' => (string) $built['shape'],
            'self_contained' => (bool) $built['self_contained'],
            'leverage' => (float) ($winner['_score']['leverage'] ?? 0.0),
            'rationale' => $rationale,
            'pattern' => $patternAttachment,
            'execution_contract' => $contractAttachment,
        ];
    }

    /**
     * LOOP-PATTERN-REGISTRY A0 — the PRE-ORIGINATION selector descriptor, built from the candidate packet
     * (NOT from $built, which only exists after origination). Unlike {@see patternDescriptor()} (the
     * advisory descriptor that forces cosmetic=false because the EV PARK already removed proxy-only work),
     * this one DETECTS cosmetic/proxy from the candidate's own signals and lets the selector veto it. A
     * non-finite leverage maps to ZERO expected_impact so the selector's negligible-impact gate rejects it
     * (absence of reliable impact tends to rejection, never approval — same NaN/INF stance as the selector).
     *
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function driverDescriptorFor(array $candidate): array
    {
        $shape = (string) ($candidate['shape'] ?? 'refactor');
        $kind = match (true) {
            str_contains($shape, 'feature') => 'feature',
            str_contains($shape, 'bug') => 'bug',
            default => 'refactor',
        };

        $leverage = (float) ($candidate['_score']['leverage'] ?? 0.0);
        $expectedImpact = is_finite($leverage) ? ($this->leverageToValue($leverage) / 100.0) : 0.0;
        $cosmetic = (bool) ($candidate['cosmetic'] ?? false) || (bool) ($candidate['proxy'] ?? false);

        return [
            'objective_kind' => $kind,
            'expected_impact' => $expectedImpact,
            'evidence' => ((bool) ($candidate['verifiable'] ?? false)) ? 0.8 : 0.3,
            'risk' => (float) ($candidate['risk'] ?? 0.5),
            'cost' => (float) ($candidate['cost'] ?? 0.5),
            'cosmetic' => $cosmetic,
            'touches_loop' => true,
        ];
    }

    private function patternDriver(): AtlasLoopPatternDecisionDriver
    {
        return $this->patternDriver ?? new AtlasLoopPatternDecisionDriver;
    }

    /**
     * Build the selector descriptor from the originated winner. expected_impact comes from the producer's
     * OWN leverage measure (so a real floor/EV/critic-cleared objective reads as real impact, not a guess);
     * cosmetic is false because proxy-only candidates were already PARKed upstream by the EV brain.
     *
     * @param  array<string,mixed>  $winner
     * @param  array<string,mixed>  $built
     * @return array<string,mixed>
     */
    private function patternDescriptor(array $winner, array $built): array
    {
        $shape = (string) ($built['shape'] ?? 'refactor');
        $kind = match (true) {
            str_contains($shape, 'feature') => 'feature',
            str_contains($shape, 'bug') => 'bug',
            default => 'refactor',
        };

        return [
            'objective_kind' => $kind,
            'expected_impact' => $this->leverageToValue((float) ($winner['_score']['leverage'] ?? 0.0)) / 100.0,
            'evidence' => ((bool) ($winner['verifiable'] ?? false)) ? 0.8 : 0.3,
            'risk' => (float) ($winner['risk'] ?? 0.5),
            'cost' => (float) ($winner['cost'] ?? 0.5),
            'cosmetic' => false,
            'touches_loop' => true,
        ];
    }

    private function patternRegistry(): AtlasLoopPatternRegistry
    {
        return $this->patternRegistry ?? new AtlasLoopPatternRegistry;
    }

    private function patternSelector(): AtlasLoopPatternSelector
    {
        return $this->patternSelector ?? new AtlasLoopPatternSelector;
    }

    private function patternCompiler(): AtlasLoopPatternCompiler
    {
        return $this->patternCompiler ?? new AtlasLoopPatternCompiler;
    }

    /**
     * S1 — do the area's feature signals say this target is a NEW-capability leap (not a refactor)? Mirrors
     * {@see AtlasLoopOriginationBuilder::decideShape()}: a strategically-aligned target in an area with
     * maturity HEADROOM is where a feature is the bigger jump. Read-only over the State; never a hard-coded
     * 'feature' — when this is false the shape stays unset and build() falls back to a refactor.
     */
    private function featureSignalsSay(StateOfAtlas $state, string $path): bool
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return false;
        }

        return $state->strategicWeightFor($path) >= 0.60 && $state->maturityFor($path) <= 0.70;
    }

    private function stateReader(): AtlasLoopStateOfAtlasReader
    {
        return $this->stateReader ?? app(AtlasLoopStateOfAtlasReader::class);
    }

    private function critic(): AtlasLoopAdversarialCritic
    {
        return $this->critic ?? new AtlasLoopAdversarialCritic;
    }

    private function origination(): AtlasLoopOriginationBuilder
    {
        return $this->origination ?? app(AtlasLoopOriginationBuilder::class);
    }

    /**
     * LOOP-OS Slice 7 — reorder the floor-passers by EXPECTED VALUE so the candidate that most relieves
     * the BINDING system axis leads. Reads the FRESH per-cycle axis vector (Slice 6) ONCE, derives each
     * candidate's machine touches_axes (Slice 7.5), and asks the EV decider (theory-of-constraints, Slice 6
     * weights) for the argmax. Fail-OPEN: on any error the leverage order is returned untouched, so a hiccup
     * in the axis/EV layer never blocks a refill.
     *
     * Public so the re-pivot proof can drive it with REAL packet signals (caller_count/cyclomatic/
     * verifiable/leverage) and let the REAL touches-axes deriver + EV decider run — never by injecting
     * pre-derived touches_axes (which would bypass the very logic this slice adds).
     *
     * @param  list<array<string,mixed>>  $floorPassers
     * @return array{0:list<array<string,mixed>>, 1:?array{binding_axis:string, relief:float}}
     */
    public function reorderByExpectedValue(array $floorPassers, string $repoRoot): array
    {
        try {
            $vector = $this->axisService()->vector($repoRoot);
            $axisValues = is_array($vector['axis_values'] ?? null) ? (array) $vector['axis_values'] : [];

            $candidates = [];
            $byId = [];
            foreach ($floorPassers as $i => $packet) {
                $path = (string) ($packet['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $id = $path.'#'.$i;
                $byId[$id] = $packet;
                $candidates[] = [
                    'candidateId' => $id,
                    'class' => ((bool) ($packet['verifiable'] ?? false)) ? 'refactor' : 'feature',
                    'value' => $this->leverageToValue((float) ($packet['_score']['leverage'] ?? 0.0)),
                    'touches_axes' => $this->touchesProducer()->forPacket($packet),
                    'node_count' => 1, // produce() originates SINGLE-target objectives — an honest node count.
                ];
            }
            if ($candidates === []) {
                return [$floorPassers, null];
            }

            $decision = $this->evDecider()->decide($candidates, [
                'axis_values' => $axisValues,
                // Uniform P=0.5 unless a calibrated class prior is wired — keeps the pick driven by
                // value·bottleneck-relief (the discriminator the re-pivot proof isolates), not a guessed P.
                'class_stats' => [],
                'max_node_count' => 1,
            ]);
            $winnerId = (string) ($decision['winner']['candidateId'] ?? '');
            if ($winnerId === '' || ! isset($byId[$winnerId])) {
                return [$floorPassers, null];
            }

            // Stable reorder: EV winner first; the rest keep their original leverage order.
            $reordered = [$byId[$winnerId]];
            foreach ($floorPassers as $i => $packet) {
                if (((string) ($packet['path'] ?? '')).'#'.$i !== $winnerId) {
                    $reordered[] = $packet;
                }
            }

            return [$reordered, [
                'binding_axis' => (string) ($decision['bottleneck']['binding_axis'] ?? '?'),
                'relief' => (float) ($decision['winner']['relief'] ?? 0.0),
            ]];
        } catch (Throwable) {
            return [$floorPassers, null]; // fail-open — the EV refinement never blocks the rédea.
        }
    }

    /**
     * Monotonic, INJECTIVE leverage→[0,100) map (Michaelis–Menten): distinct leverage ⇒ distinct value,
     * NEVER a universal clamp to 100 (the value-scale-collapse an unbounded leverage ratio would cause).
     */
    private function leverageToValue(float $leverage): float
    {
        $leverage = max(0.0, $leverage);
        $k = max(0.01, (float) config('atlas.loop.producer_ev_leverage_halfsat', 8.0));

        return 100.0 * ($leverage / ($leverage + $k));
    }

    private function axisService(): AtlasLoopSystemAxisService
    {
        return $this->axis ?? new AtlasLoopSystemAxisService;
    }

    private function touchesProducer(): AtlasLoopTouchesAxesProducer
    {
        return $this->touches ?? new AtlasLoopTouchesAxesProducer;
    }

    private function evDecider(): AtlasLoopExpectedValueDecider
    {
        return $this->ev ?? new AtlasLoopExpectedValueDecider;
    }

    /** @return array<string,int> */
    private function callerCounts(string $repoRoot, array $relPaths): array
    {
        try {
            return (new AtlasLoopWiredCallerService($repoRoot))->callerCounts($relPaths);
        } catch (Throwable) {
            return [];
        }
    }

    private function cyclomaticOf(string $abs): int
    {
        try {
            if (! is_file($abs)) {
                return 0;
            }
            $src = (string) @file_get_contents($abs);
            if ($src === '') {
                return 0;
            }
            $cx = ($this->analyzer ?? new AtlasLoopSignalAnalyzer)->fileComplexity($src);

            return ($cx['measured'] ?? false) === true ? max(0, (int) ($cx['max_per_method'] ?? 0)) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function costOf(string $abs): float
    {
        $lines = is_file($abs) ? max(1, substr_count((string) @file_get_contents($abs), "\n")) : 1;

        return max(0.15, min(1.0, $lines / 1500.0));
    }

    private function hasSiblingTest(string $repoRoot, string $rel): bool
    {
        $base = basename($rel, '.php');
        $root = rtrim($repoRoot, '/');
        foreach (["$root/tests", "$root/Tests"] as $dir) {
            if (is_dir($dir) && $this->globHasTest($dir, $base)) {
                return true;
            }
        }

        return false;
    }

    private function globHasTest(string $dir, string $base): bool
    {
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $name = $f->getFilename();
                if (str_starts_with($name, $base) && str_ends_with($name, 'Test.php')) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
