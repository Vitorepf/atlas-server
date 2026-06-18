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
