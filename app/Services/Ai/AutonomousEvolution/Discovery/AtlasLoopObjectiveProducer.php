<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AtlasOpenBrainFileContextService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Throwable;

/**
 * THE RÉDEA — the autonomous high-leverage objective producer.
 *
 * Given the campaign's candidate files, it reads the Atlas brain (callers = breadth of
 * unblock, cyclomatic = structural-debt compounding, Open Brain file-context = strategic
 * alignment from memory/reality-graph), scores each with {@see AtlasLoopLeverageScorer},
 * and selects the single BIGGEST leap per least time that clears the ambition floor —
 * then emits a verifiable objective the existing claim→grind→gate→merge path executes.
 *
 * Design discipline:
 *  - select() is PURE (pre-gathered signal packets in, ranked pick out) so the choice
 *    freezes under test and can never be the thing that breaks a run.
 *  - gather() is fail-open per-signal: a brain read that errors degrades that one signal
 *    to a conservative default, never throws, never blocks the loop.
 *  - It builds ON existing machinery: leverage via AtlasLoopLeverageScorer, the refactor
 *    objective contract via AtlasLoopRefactorObjectiveSynthesizer. No new execution path.
 *
 * v1 emits REFACTOR objectives (verifiable by the existing complexity-drop gate without
 * authoring a RED test). Feature origination (authoring a genuinely-RED acceptance test
 * for a new capability) is the next milestone; the leverage selection here is already
 * cross-shape, so a feature candidate slots in unchanged once its contract builder lands.
 */
final class AtlasLoopObjectiveProducer
{
    public function __construct(
        private readonly AtlasLoopLeverageScorer $scorer = new AtlasLoopLeverageScorer,
        private readonly ?AtlasLoopSignalAnalyzer $analyzer = null,
        private readonly ?AtlasOpenBrainFileContextService $brainContext = null,
        private readonly ?AtlasLoopRefactorObjectiveSynthesizer $refactorSynth = null,
    ) {}

    /**
     * PURE selection: score the pre-gathered packets, rank by leverage, return the top
     * candidate that clears the ambition floor, or null if none does (no faxina enqueued).
     *
     * @param  list<array<string,mixed>>  $packets  one signal packet per candidate
     * @return array<string,mixed>|null the winning packet with its `_score`, or null
     */
    public function select(array $packets): ?array
    {
        $ranked = $this->scorer->rank($packets);
        foreach ($ranked as $candidate) {
            if ($this->scorer->passesAmbitionFloor($candidate['_score'])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Read the brain for each candidate path and build its leverage signal packet.
     * Fail-open per signal: any brain read that errors degrades to a conservative default.
     *
     * @param  list<string>  $relPaths  repo-relative candidate files
     * @return list<array<string,mixed>>
     */
    public function gather(string $repoRoot, array $relPaths): array
    {
        $callers = $this->callerCounts($repoRoot, $relPaths);
        $packets = [];

        foreach ($relPaths as $rel) {
            $abs = rtrim($repoRoot, '/').'/'.ltrim($rel, '/');
            $cyclomatic = $this->cyclomaticOf($abs);
            $hasSiblingTest = $this->hasSiblingTest($repoRoot, $rel);

            $packets[] = [
                'path' => $rel,
                'shape' => 'refactor',
                'caller_count' => $callers[$rel] ?? ($callers[ltrim($rel, '/')] ?? 0),
                'cyclomatic' => $cyclomatic,
                'strategic_impact' => $this->strategicImpact($rel),
                // cost grows with file size; risk drops when a behaviour anchor (sibling test) exists.
                'cost' => $this->costOf($abs),
                'risk' => $hasSiblingTest ? 0.4 : 0.85,
                // refactor is verifiable ONLY with a frozen sibling test (the complexity-drop gate).
                'verifiable' => $hasSiblingTest && $cyclomatic >= (int) config('atlas.loop.decision_min_refactor_cyclomatic', 10),
            ];
        }

        return $packets;
    }

    /**
     * Full produce: gather brain signals → select the biggest leap → build its objective
     * contract. Returns null when nothing clears the floor (the loop simply gets no
     * producer task that tick — never a trivial one).
     *
     * @param  list<string>  $relPaths
     * @return array{objective:string, payload:array<string,mixed>, acceptance_hash:string, target_path:string, leverage:float, rationale:string}|null
     */
    public function produce(string $repoRoot, array $relPaths, string $provider, string $targetId): ?array
    {
        if ($relPaths === []) {
            return null;
        }

        $winner = $this->select($this->gather($repoRoot, $relPaths));
        if ($winner === null) {
            return null;
        }

        $built = $this->buildRefactorObjective($repoRoot, (string) $winner['path'], $provider, $targetId);
        if ($built === null) {
            return null; // synthesizer fail-closed (not provably refactorable) → no enqueue
        }

        return [
            'objective' => (string) $built['objective'],
            'payload' => (array) $built['payload'],
            'acceptance_hash' => (string) ($built['acceptance_hash'] ?? ''),
            'target_path' => (string) $winner['path'],
            'leverage' => (float) $winner['_score']['leverage'],
            'rationale' => (string) $winner['_score']['rationale'],
        ];
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

    /**
     * Strategic alignment from the brain: a path with memory + reality-graph references is
     * where Atlas is actually living/going. Cheap (AtlasOpenBrainFileContextService is
     * hard-capped). Fail-open to the scorer's conservative default by returning null.
     */
    private function strategicImpact(string $rel): float
    {
        try {
            $ctx = ($this->brainContext ?? app(AtlasOpenBrainFileContextService::class))->contextFor($rel);
            $mem = is_countable($ctx['memory'] ?? null) ? count($ctx['memory']) : 0;
            $reality = is_countable($ctx['reality_graph_paths'] ?? null) ? count($ctx['reality_graph_paths']) : 0;
            $consumers = is_countable($ctx['consumers'] ?? null) ? count($ctx['consumers']) : 0;

            // memory + reality references dominate strategic weight; consumers add a little.
            $impact = min(1.0, 0.18 * $mem + 0.14 * $reality + 0.04 * $consumers);

            return $impact > 0.0 ? max(0.30, $impact) : 0.30;
        } catch (Throwable) {
            return 0.30;
        }
    }

    private function costOf(string $abs): float
    {
        $lines = is_file($abs) ? max(1, substr_count((string) @file_get_contents($abs), "\n")) : 1;

        // ~300 lines is a comfortable refactor unit (cost ~0.3); 1500+ saturates to 1.0.
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
                if ($f->getFilename() === $base.'Test.php') {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function buildRefactorObjective(string $repoRoot, string $rel, string $provider, string $targetId): ?array
    {
        try {
            return ($this->refactorSynth ?? new AtlasLoopRefactorObjectiveSynthesizer)
                ->synthesize($repoRoot, $rel, [], $provider, $targetId);
        } catch (Throwable) {
            return null;
        }
    }
}
