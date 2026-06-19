<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

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

            $packets[] = [
                'path' => $rel,
                'caller_count' => $callers[$rel] ?? ($callers[ltrim($rel, '/')] ?? 0),
                'cyclomatic' => $cyclomatic,
                'strategic_impact' => $state->strategicWeightFor($rel),
                'cost' => $this->costOf($abs),
                'risk' => $hasSiblingTest ? 0.4 : 0.85,
                'verifiable' => $hasSiblingTest && $cyclomatic >= (int) config('atlas.loop.decision_min_refactor_cyclomatic', 10),
            ];
        }

        return $packets;
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

        // LOOP-OS Fase 4 — EV / theory-of-constraints refinement (flag-gated, default-OFF ⇒ byte-identical).
        // Reorders the floor-passers so the candidate that most relieves the BINDING system axis (Slice 6
        // fresh axis vector × Slice 7.5 machine touches_axes) leads; the adversarial critic below still
        // confirms the leap. Fail-open: any axis/EV hiccup leaves the leverage order untouched.
        $evPick = null;
        if ((bool) config('atlas.loop.producer_ev_pick_enabled', false)) {
            [$floorPassers, $evPick] = $this->reorderByExpectedValue($floorPassers, $repoRoot);
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

        return [
            'objective' => (string) $built['objective'],
            'payload' => (array) $built['payload'],
            'acceptance_hash' => (string) $built['acceptance_hash'],
            'target_path' => (string) $built['target_path'],
            'shape' => (string) $built['shape'],
            'self_contained' => (bool) $built['self_contained'],
            'leverage' => (float) $winner['_score']['leverage'],
            'rationale' => $rationale,
        ];
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
