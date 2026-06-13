<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The HONEST Utility/Impact grade for the Evolution Loop — earned from committed
 * evidence, never self-declared.
 *
 * The one anti-gaming property that makes the number trustworthy: for the WIRED and
 * COMPOUNDING axes it RE-RESOLVES the production caller graph FRESH at grade time
 * (via AtlasLoopWiredCallerService) and does NOT trust the count the loop stored in
 * the receipt. A tampered receipt or a relabeled orphan changes nothing — the grade
 * recomputes ground truth. The same query reproduces the measured ~3/10 baseline on
 * the pre-upgrade ledger (54% orphan, 79% trivial diffs), so any inflated claim is
 * auto-contradicted by its own re-resolved evidence.
 *
 * U = 10 * (0.35*WIRED + 0.20*REAL_TARGET + 0.15*NON_TRIVIAL + 0.20*COMPOUNDING + 0.10*SAFETY)
 *   WIRED       share of merged targets whose FRESH caller count >= 1 (re-resolved)
 *   REAL_TARGET share of merges NOT on generated/test/docs scaffolding
 *   NON_TRIVIAL share of merges with >15 touched lines, a correctness/perf category,
 *               and a canary that ran and did not fail
 *   COMPOUNDING share of merges on HUB targets (fresh caller fan-in >= hub threshold) —
 *               hardening connected code is the compounding-leverage proxy (honest:
 *               true fan-in DELTA needs temporal index snapshots, noted as future work)
 *   SAFETY      canary green-rate over the window (reverts are structurally zero)
 */
final class AtlasLoopUtilityGradeService
{
    public const SCHEMA_VERSION = 'atlas.loop.utility_grade.v1';

    public function __construct(
        private readonly AtlasLoopWiredCallerService $wiredCallers,
        private readonly ?string $repoRoot = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function grade(?int $window = null): array
    {
        $window ??= (int) config('atlas.ai.loop.utility_grade_window', 50);
        $window = max(1, min(2000, $window));
        $hubThreshold = max(2, (int) config('atlas.ai.loop.utility_grade_hub_callers', 3));

        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'grade' => 0.0,
            'window' => $window,
            'graded_merges' => 0,
            'axes' => [
                'wired' => 0.0,
                'real_target' => 0.0,
                'non_trivial' => 0.0,
                'compounding' => 0.0,
                'safety' => 0.0,
            ],
            'evidence' => [
                'wired_merges' => 0,
                'real_target_merges' => 0,
                'non_trivial_merges' => 0,
                'hub_merges' => 0,
                'canary_ran' => 0,
                'canary_green' => 0,
            ],
            'method' => 'caller graph re-resolved fresh at grade time (stored receipt counts NOT trusted)',
            'generated_at' => now()->toIso8601String(),
        ];

        if (! DatabaseTableAvailability::has('atlas_loop_proposals')
            || ! DatabaseTableAvailability::hasColumn('atlas_loop_proposals', 'quality')) {
            return $base;
        }

        try {
            $rows = AtlasLoopProposal::query()
                ->where('merged_to_main', true)
                ->whereNotNull('quality')
                ->orderByDesc('reviewed_at')
                ->limit($window)
                ->get();
        } catch (Throwable) {
            return $base;
        }

        $merges = [];
        $targets = [];
        foreach ($rows as $proposal) {
            $quality = is_array($proposal->quality) ? $proposal->quality : [];
            $receipt = is_array($quality['_impact_receipt'] ?? null) ? $quality['_impact_receipt'] : [];
            $canary = is_array($quality['_canary'] ?? null) ? $quality['_canary'] : (is_array($receipt['canary'] ?? null) ? $receipt['canary'] : []);

            // RE-DERIVE from what ACTUALLY merged, not the generator-writable receipt
            // fields (target_kind/category/touched_lines/target_path are all in $fillable).
            // Truth source priority: (1) the real git commit (`git show` of the merge — the
            // materialised diff carries the real production path, the snippet having been
            // rewritten to the target before commit); (2) the stored diff_text (a snippet
            // diff); (3) the stored target_path. target_kind is always RE-CLASSIFIED from
            // the resolved path, never trusted from the stored field.
            $diff = (string) $proposal->diff_text;
            $commit = is_string($receipt['commit'] ?? null) ? $receipt['commit'] : null;
            $git = $commit !== null ? $this->gitDiffStat($commit) : null;
            if (is_array($git) && $git['files'] !== []) {
                $changedFiles = $git['files'];
                $touched = $git['touched'];
            } else {
                $changedFiles = $this->changedFilesFromDiff($diff);
                $touched = $this->touchedLinesFromDiff($diff);
            }
            $target = $this->primaryTarget($changedFiles, (string) $proposal->target_path);
            if ($target === '') {
                continue;
            }
            $merges[] = [
                'target' => $target,
                'target_kind' => $this->targetKindOfPath($target),
                'category' => $this->deriveCategory((string) $proposal->objective, $diff),
                'touched_lines' => $touched,
                'canary_ran' => (bool) ($canary['ran'] ?? false),
                'canary_passed' => $canary['passed'] ?? null,
            ];
            $targets[$target] = true;
        }

        $graded = count($merges);
        if ($graded === 0) {
            return $base;
        }
        $base['graded_merges'] = $graded;

        // RE-RESOLVE caller graph fresh — the ungameable core.
        $freshCallers = $this->wiredCallers->callerCounts(array_keys($targets));

        $wired = 0;
        $realTarget = 0;
        $nonTrivial = 0;
        $hub = 0;
        $canaryRan = 0;
        $canaryGreen = 0;
        foreach ($merges as $m) {
            $callers = (int) ($freshCallers[$m['target']] ?? 0);
            $isGenerated = in_array($m['target_kind'], ['generated', 'test', 'docs'], true);

            if ($callers >= 1 && ! $isGenerated) {
                $wired++;
            }
            if (! $isGenerated) {
                $realTarget++;
            }
            $substantive = $m['touched_lines'] > 15
                && in_array($m['category'], ['bug', 'edge_case', 'perf'], true)
                && $m['canary_ran'] === true
                && $m['canary_passed'] === true; // strict: ran:true/passed:null is NOT green
            if ($substantive) {
                $nonTrivial++;
            }
            // Compounding leverage: a wired HUB whose improvement protects many callers.
            if ($callers >= $hubThreshold && ! $isGenerated) {
                $hub++;
            }
            if ($m['canary_ran']) {
                $canaryRan++;
                if ($m['canary_passed'] === true) { // strict: unknown (null) != pass
                    $canaryGreen++;
                }
            }
        }

        $wiredAxis = $wired / $graded;
        $realAxis = $realTarget / $graded;
        $nonTrivialAxis = $nonTrivial / $graded;
        $compoundingAxis = $hub / $graded;
        // Safety: canary green-rate where it ran; if no canary ran in the window, treat
        // as neutral 1.0 (reverts are structurally zero — fix-forward-only), but require
        // that nothing observed actually failed.
        $safetyAxis = $canaryRan > 0 ? ($canaryGreen / $canaryRan) : 1.0;

        $u = 10.0 * (
            0.35 * $wiredAxis
            + 0.20 * $realAxis
            + 0.15 * $nonTrivialAxis
            + 0.20 * $compoundingAxis
            + 0.10 * $safetyAxis
        );

        $base['axes'] = [
            'wired' => round($wiredAxis, 4),
            'real_target' => round($realAxis, 4),
            'non_trivial' => round($nonTrivialAxis, 4),
            'compounding' => round($compoundingAxis, 4),
            'safety' => round($safetyAxis, 4),
        ];
        $base['evidence'] = [
            'wired_merges' => $wired,
            'real_target_merges' => $realTarget,
            'non_trivial_merges' => $nonTrivial,
            'hub_merges' => $hub,
            'hub_threshold' => $hubThreshold,
            'canary_ran' => $canaryRan,
            'canary_green' => $canaryGreen,
        ];
        $base['grade'] = round(max(0.0, min(10.0, $u)), 2);

        return $base;
    }

    /**
     * Changed files parsed from the committed unified diff (the truth, not the receipt).
     *
     * @return list<string>
     */
    private function changedFilesFromDiff(string $diff): array
    {
        $files = [];
        if (preg_match_all('/^diff --git a\/(.+?) b\/(.+)$/m', $diff, $m) > 0) {
            foreach ($m[2] as $f) {
                $f = trim(str_replace('\\', '/', (string) $f));
                if ($f !== '') {
                    $files[] = $f;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * The file the grade actually scores: the largest-looking REAL .php file in the diff
     * (production, non-test, non-generated). Falls back to the stored target_path only if
     * the diff yielded nothing parseable.
     *
     * @param  list<string>  $changedFiles
     */
    private function primaryTarget(array $changedFiles, string $storedTarget): string
    {
        $real = array_values(array_filter(
            $changedFiles,
            static fn (string $f): bool => str_ends_with($f, '.php')
                && ! str_contains($f, '/tests/')
                && ! str_ends_with($f, 'Test.php')
                && preg_match('#(^|/)Generated(/|$)#', $f) !== 1,
        ));
        if ($real !== []) {
            return $real[0];
        }
        if ($changedFiles !== []) {
            return $changedFiles[0];
        }

        return trim(str_replace('\\', '/', $storedTarget));
    }

    /**
     * Classify a single resolved target path (re-derived, never trusts the stored field).
     */
    private function targetKindOfPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#(^|/)Generated(/|$)#', $path) === 1) {
            return 'generated';
        }
        if (str_contains($path, '/tests/') || str_starts_with($path, 'tests/') || str_ends_with($path, 'Test.php')) {
            return 'test';
        }
        if (str_starts_with($path, 'docs/') || str_ends_with($path, '.md')) {
            return 'docs';
        }

        return 'real';
    }

    /**
     * Real changed files + touched lines of an actual merge commit (the ungameable truth
     * of what landed in main). null when the commit is missing/unreadable or git fails.
     *
     * @return array{files: list<string>, touched: int}|null
     */
    private function gitDiffStat(string $commit): ?array
    {
        if (! preg_match('/^[0-9a-f]{7,40}$/i', $commit)) {
            return null;
        }
        try {
            $root = rtrim($this->repoRoot ?? base_path(), '/');
            if (! is_dir($root.'/.git')) {
                return null;
            }
            $process = new Process(
                ['git', 'show', '--numstat', '--format=', '--no-color', $commit],
                $root,
                $this->gitEnv(),
                null,
                30.0,
            );
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $files = [];
            $touched = 0;
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                // numstat: "<added>\t<deleted>\t<path>"
                if (preg_match('/^(\d+|-)\t(\d+|-)\t(.+)$/', trim($line), $m) !== 1) {
                    continue;
                }
                $files[] = trim(str_replace('\\', '/', $m[3]));
                $touched += (is_numeric($m[1]) ? (int) $m[1] : 0) + (is_numeric($m[2]) ? (int) $m[2] : 0);
            }

            return $files === [] ? null : ['files' => array_values(array_unique($files)), 'touched' => $touched];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,string>
     */
    private function gitEnv(): array
    {
        $binDir = \dirname(PHP_BINARY);
        $base = getenv('PATH');
        $base = is_string($base) && $base !== '' ? $base : '/usr/bin:/bin:/usr/sbin:/sbin';

        return ['PATH' => '/usr/bin'.PATH_SEPARATOR.'/bin'.PATH_SEPARATOR.$binDir.PATH_SEPARATOR.$base];
    }

    private function touchedLinesFromDiff(string $diff): int
    {
        $touched = 0;
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            if ($line[0] === '+' || $line[0] === '-') {
                $touched++;
            }
        }

        return $touched;
    }

    private function deriveCategory(string $objective, string $diff): string
    {
        $text = mb_strtolower($objective.' '.$diff);
        if (preg_match('/\b(perf|performance|latency|throughput|cache|memory|slow|timeout|n\+1)\b/', $text) === 1) {
            return 'perf';
        }
        if (preg_match('/\b(edge|corner|null|empty|bounds?|boundary|missing|fallback)\b/', $text) === 1) {
            return 'edge_case';
        }
        if (preg_match('/\b(bug|fix|fail|failure|error|exception|broken|regression|correct|repair)\b/', $text) === 1) {
            return 'bug';
        }

        return 'unknown';
    }
}
