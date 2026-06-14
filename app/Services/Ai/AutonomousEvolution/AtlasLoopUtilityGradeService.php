<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
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
        private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
    ) {}

    private function analyzer(): AtlasLoopSignalAnalyzer
    {
        return $this->signalAnalyzer ?? new AtlasLoopSignalAnalyzer();
    }

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
                $perFile = is_array($git['per_file'] ?? null) ? $git['per_file'] : [];
            } else {
                $changedFiles = $this->changedFilesFromDiff($diff);
                $touched = $this->touchedLinesFromDiff($diff);
                $perFile = $this->perFileLinesFromDiff($diff);
            }
            $target = $this->primaryTarget($changedFiles, (string) $proposal->target_path, $perFile);
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
                // A complexity-reducing refactor objective. For these the NON_TRIVIAL credit is
                // decided by a git-RE-MEASURED AST drop (authoritative), NEVER by the writable
                // objective/diff text — so a "refactor" whose diff merely mentions null/fix/error
                // cannot launder a non-trivial credit via the keyword path.
                'is_refactor_objective' => preg_match('/refactor.*complex|complex.*reduc|reduce.*complex/i', (string) $proposal->objective) === 1,
                'commit' => $commit,
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
        $reMeasuredRefactor = 0;
        foreach ($merges as $m) {
            $callers = (int) ($freshCallers[$m['target']] ?? 0);
            $isGenerated = in_array($m['target_kind'], ['generated', 'test', 'docs'], true);

            if ($callers >= 1 && ! $isGenerated) {
                $wired++;
            }
            if (! $isGenerated) {
                $realTarget++;
            }
            // NON_TRIVIAL = substantive, behavior-proven work. Two honest doors, BOTH requiring
            // >15 touched lines + a canary that ran and passed (strict: ran:true/passed:null is
            // NOT green):
            //   (a) a correctness/perf merge whose category the objective/diff declares; OR
            //   (b) a complexity-reducing REFACTOR whose AST max-per-method cyclomatic DROP is
            //       RE-MEASURED from the real merge commit (git show parent-vs-commit). For a
            //       refactor objective the re-measure is AUTHORITATIVE — the writable keyword path
            //       is NOT accepted — so a "refactor" that did not actually reduce complexity (or
            //       whose diff merely mentions null/fix/error) scores ZERO. Ungameable: the drop
            //       is re-derived from the immutable commit by the same AST measure the certifier
            //       used to gate the merge; fail-closed (null => not credited).
            $baseSubstantive = $m['touched_lines'] > 15 && $m['canary_ran'] === true && $m['canary_passed'] === true;
            if ($baseSubstantive && ($m['is_refactor_objective'] ?? false)) {
                if ($this->reMeasuredComplexityDrop((string) ($m['commit'] ?? ''), (string) $m['target']) === true) {
                    $nonTrivial++;
                    $reMeasuredRefactor++;
                }
            } elseif ($baseSubstantive && in_array($m['category'], ['bug', 'edge_case', 'perf'], true)) {
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
            're_measured_refactor_merges' => $reMeasuredRefactor,
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
     * The file the grade actually scores: the DOMINANT real .php file in the diff — the one with
     * the MOST changed lines (production, non-test, non-generated), NOT merely the first in diff
     * order. This closes the bundle mis-attribution the adversarial grade found (a 218-line/
     * 7-file commit was credited to a file that changed 4 lines), so the WIRED/NON_TRIVIAL axes
     * score the file the merge actually changed. Falls back to first real, then first changed,
     * then the stored target_path when the diff yields nothing parseable.
     *
     * @param  list<string>  $changedFiles
     * @param  array<string,int>  $perFile  changed-line count per path (numstat/diff); [] = unknown
     */
    private function primaryTarget(array $changedFiles, string $storedTarget, array $perFile = []): string
    {
        $real = array_values(array_filter(
            $changedFiles,
            static fn (string $f): bool => str_ends_with($f, '.php')
                && ! str_contains($f, '/tests/')
                && ! str_ends_with($f, 'Test.php')
                && preg_match('#(^|/)Generated(/|$)#', $f) !== 1,
        ));
        if ($real !== []) {
            // Dominant by changed lines; ties keep the earliest diff-order file (stable, so the
            // attribution is deterministic across re-grades of the same commit).
            $best = $real[0];
            $bestLines = (int) ($perFile[$best] ?? 0);
            foreach ($real as $f) {
                $lines = (int) ($perFile[$f] ?? 0);
                if ($lines > $bestLines) {
                    $best = $f;
                    $bestLines = $lines;
                }
            }

            return $best;
        }
        if ($changedFiles !== []) {
            return $changedFiles[0];
        }

        return trim(str_replace('\\', '/', $storedTarget));
    }

    /**
     * Per-file changed-line counts parsed from a unified diff — the fallback when git numstat is
     * unavailable. Counts +/- BODY lines (excluding the +++/--- file headers) per file section so
     * {@see primaryTarget} can pick the dominant file even off the stored diff.
     *
     * @return array<string,int>
     */
    private function perFileLinesFromDiff(string $diff): array
    {
        $perFile = [];
        $current = null;
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (preg_match('/^diff --git a\/(.+?) b\/(.+)$/', $line, $m) === 1) {
                $current = trim(str_replace('\\', '/', (string) $m[2]));
                $perFile[$current] ??= 0;

                continue;
            }
            if ($current === null || $line === '') {
                continue;
            }
            if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }
            if ($line[0] === '+' || $line[0] === '-') {
                $perFile[$current]++;
            }
        }

        return $perFile;
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
     * HONEST NON_TRIVIAL proof for a complexity-reducing refactor: re-measure the primary
     * target's AST max-per-method cyclomatic BEFORE the merge (parent commit) vs AFTER (the merge
     * commit), straight from the immutable git history — NEVER the objective text and NEVER a
     * writable receipt field. Returns true iff the candidate genuinely reduced the worst method's
     * complexity (candidate_max < baseline_max) without ballooning the file total
     * (candidate_total <= baseline_total) — the SAME rule the semantic certifier used to GATE the
     * merge, so grade and gate measure one fact. Null on any failure (non-.php target, git error,
     * missing parent, unparseable) => the caller does NOT credit (fail-closed). A "refactor" that
     * did not actually reduce complexity scores ZERO; the keyword path is never an escape hatch.
     */
    private function reMeasuredComplexityDrop(string $commit, string $primaryTarget): ?bool
    {
        if (! preg_match('/^[0-9a-f]{7,40}$/i', $commit) || ! str_ends_with($primaryTarget, '.php')) {
            return null;
        }
        // `<commit>^` = first parent (well-defined even for a merge commit); a root commit with no
        // parent fails and yields null => not credited.
        $baseline = $this->fileAtCommit($commit.'^', $primaryTarget);
        $candidate = $this->fileAtCommit($commit, $primaryTarget);
        if ($baseline === null || $candidate === null) {
            return null;
        }
        $before = $this->analyzer()->fileComplexity($baseline);
        $after = $this->analyzer()->fileComplexity($candidate);
        if (! $before['measured'] || ! $after['measured']) {
            return null;
        }

        return $after['max_per_method'] < $before['max_per_method']
            && $after['total'] <= $before['total'];
    }

    /**
     * A file's exact content at a git ref (`git show <ref>:<path>`), or null when the ref/path
     * does not resolve (file absent at that ref, or a root commit has no parent). Read-only;
     * bounded; PATH-safe env (launchd/cron). Hex/path are validated by the caller.
     */
    private function fileAtCommit(string $ref, string $relPath): ?string
    {
        try {
            $root = rtrim($this->repoRoot ?? base_path(), '/');
            if (! is_dir($root.'/.git')) {
                return null;
            }
            $process = new Process(['git', 'show', $ref.':'.$relPath], $root, $this->gitEnv(), null, 30.0);
            $process->run();

            return $process->isSuccessful() ? (string) $process->getOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Real changed files + touched lines of an actual merge commit (the ungameable truth
     * of what landed in main). null when the commit is missing/unreadable or git fails.
     *
     * @return array{files: list<string>, touched: int, per_file: array<string,int>}|null
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
            $perFile = [];
            $touched = 0;
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                // numstat: "<added>\t<deleted>\t<path>"
                if (preg_match('/^(\d+|-)\t(\d+|-)\t(.+)$/', trim($line), $m) !== 1) {
                    continue;
                }
                $path = trim(str_replace('\\', '/', $m[3]));
                $lines = (is_numeric($m[1]) ? (int) $m[1] : 0) + (is_numeric($m[2]) ? (int) $m[2] : 0);
                $files[] = $path;
                $perFile[$path] = ($perFile[$path] ?? 0) + $lines;
                $touched += $lines;
            }

            return $files === [] ? null : ['files' => array_values(array_unique($files)), 'touched' => $touched, 'per_file' => $perFile];
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
            if ($line[0] !== '+' && $line[0] !== '-') {
                continue;
            }
            // Anti-padding belt: a real substantive change cannot be bought with blank
            // lines or comments. Strip the diff marker, then skip blank and pure-comment
            // lines (// # /* * */) so the >15 floor measures CODE, not filler. (The
            // load-bearing substance gate is the canary behavioral delta, not this count.)
            $body = ltrim(substr($line, 1));
            if ($body === '') {
                continue;
            }
            // Skip ONLY genuine comment lines. NOT PHP 8 attributes (#[...] is code, not a
            // # comment), and a `/* ... */ code` inline-comment line still counts (it has
            // code). (Fallback path only — git numstat is the primary truth for fresh merges.)
            $isComment = str_starts_with($body, '//')
                || (str_starts_with($body, '#') && ! str_starts_with($body, '#['))
                || str_starts_with($body, '*/')
                || str_starts_with($body, '*')
                || ($body !== '' && str_starts_with($body, '/*') && ! str_contains($body, '*/'));
            if ($isComment) {
                continue;
            }
            $touched++;
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
