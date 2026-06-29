<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeConflictDetector;
use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergePreFlightGate;
use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the WAVE-14 conflict detector + its wiring through {@see AtlasLoopAutoMergeService}: clean probes
 * report clean, overlapping-hunk probes report exact path+range, the auto-merge service refuses on a non-clean
 * report with reason=conflict, the detector contains no numeric thresholds, and a same-file disjoint hunk case
 * is preserved as a single path-in-conflict (with no synthetic ranges).
 */
final class AtlasLoopAutoMergeConflictDetectorTest extends TestCase
{
    /**
     * @param  array{conflicted_files?:list<string>, conflicted_hunks?:list<array{path:string,start:int,end:int}>, runner_error?:?string}  $result
     */
    private function detector(array $result): AtlasLoopAutoMergeConflictDetector
    {
        return new AtlasLoopAutoMergeConflictDetector(static fn (string $r, string $m, string $b): array => $result + ['conflicted_files' => [], 'conflicted_hunks' => [], 'runner_error' => null]);
    }

    public function test_disjoint_file_change_yields_clean_report(): void
    {
        $report = $this->detector(['conflicted_files' => [], 'conflicted_hunks' => []])->detect('/repo', 'main-sha', 'branch-sha');

        $this->assertTrue($report->clean);
        $this->assertSame([], $report->pathsInConflict);
        $this->assertSame([], $report->overlappingHunks);
    }

    public function test_overlapping_hunk_on_same_file_yields_non_clean_with_exact_path_and_range(): void
    {
        $report = $this->detector([
            'conflicted_files' => ['app/Foo.php'],
            'conflicted_hunks' => [['path' => 'app/Foo.php', 'start' => 42, 'end' => 58]],
        ])->detect('/repo', 'main-sha', 'branch-sha');

        $this->assertFalse($report->clean);
        $this->assertSame(['app/Foo.php'], $report->pathsInConflict);
        $this->assertSame([['path' => 'app/Foo.php', 'start' => 42, 'end' => 58]], $report->overlappingHunks);
    }

    public function test_same_file_with_no_hunk_range_still_listed_as_conflicted_path(): void
    {
        // git merge-tree --name-only does not emit hunks; the detector must still report the path.
        $report = $this->detector([
            'conflicted_files' => ['app/Bar.php'],
            'conflicted_hunks' => [],
        ])->detect('/repo', 'main-sha', 'branch-sha');

        $this->assertFalse($report->clean);
        $this->assertSame(['app/Bar.php'], $report->pathsInConflict);
        $this->assertSame([], $report->overlappingHunks, 'no synthetic hunks when the probe did not emit any');
    }

    public function test_runner_error_yields_non_clean_with_reason_fail_closed(): void
    {
        $report = $this->detector(['runner_error' => 'git_merge_tree_exit:128'])->detect('/repo', 'main-sha', 'branch-sha');

        $this->assertFalse($report->clean, 'fail-closed: probe error must NEVER report clean');
        $this->assertStringContainsString('git_merge_tree_exit:128', (string) $report->reason);
    }

    public function test_probe_throwing_is_caught_and_yields_non_clean(): void
    {
        $detector = new AtlasLoopAutoMergeConflictDetector(static function (): array {
            throw new \RuntimeException('boom');
        });

        $report = $detector->detect('/repo', 'main-sha', 'branch-sha');

        $this->assertFalse($report->clean);
        $this->assertStringContainsString('probe_threw', (string) $report->reason);
    }

    public function test_exit_1_with_no_parsed_paths_reports_conflict_not_clean(): void
    {
        // git merge-tree signals a conflict via exit 1 but may emit 1 or fewer output lines that the
        // runner cannot parse into paths. The detector must fail-closed on the exit code, not the
        // parsed path count — otherwise a real conflict auto-merges over shared main.
        $report = $this->detector(['conflicted_files' => [], 'conflicted_hunks' => [], 'exit_code' => 1])->detect('/repo', 'main-sha', 'branch-sha');

        $this->assertFalse($report->clean, 'exit-1 conflict signal must NEVER report clean even with zero parsed paths');
        $this->assertStringContainsString('probe_conflict_signal_unparsed', (string) $report->reason);
    }

    public function test_auto_merge_service_refuses_when_detector_says_non_clean(): void
    {
        $preFlight = $this->preFlightAllowing('main-sha');
        $detector = $this->detector(['conflicted_files' => ['app/Conflict.php']]);
        $service = $this->newAutoMergeService($preFlight, $detector);

        $mergerInvoked = false;
        $result = $service->autoMerge(
            ['base_sha' => $this->realHeadSha(), 'branch' => 'feature-x'],
            $this->realRepoPath(),
            static function () use (&$mergerInvoked): array {
                $mergerInvoked = true;

                return [];
            },
        );

        $this->assertFalse($result['merged']);
        $this->assertSame('conflict', $result['reason']);
        $this->assertNotNull($result['conflict_report']);
        $this->assertSame(['app/Conflict.php'], $result['conflict_report']['paths_in_conflict']);
        $this->assertFalse($mergerInvoked, 'merge executor must NEVER be called when conflict detector refuses');
    }

    public function test_auto_merge_service_proceeds_when_detector_says_clean(): void
    {
        $preFlight = $this->preFlightAllowing('main-sha');
        $detector = $this->detector(['conflicted_files' => [], 'conflicted_hunks' => []]);
        $service = $this->newAutoMergeService($preFlight, $detector);

        $result = $service->autoMerge(
            ['base_sha' => $this->realHeadSha(), 'branch' => 'feature-y'],
            $this->realRepoPath(),
            static fn (): array => ['status' => 'merged'],
        );

        $this->assertTrue($result['merged']);
        $this->assertNull($result['reason']);
        $this->assertTrue($result['conflict_report']['clean']);
    }

    public function test_detector_source_contains_no_numeric_threshold_comparisons(): void
    {
        // Pétreo: no scalar score / no threshold / no file-count gate. Only set-membership and hunk-ranges.
        $reflection = new ReflectionClass(AtlasLoopAutoMergeConflictDetector::class);
        $source = (string) file_get_contents($reflection->getFileName());

        // Banned tokens: anything that smells like a configurable score/threshold.
        foreach (['threshold', 'min_score', 'max_files', 'max_changes', 'churn_cap', 'risk_score'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "conflict detector must NOT carry a $banned (it is fact-only)");
        }
    }

    /**
     * Detector + AutoMergeService both depend on `Merge\AtlasLoopAutoMergeReceiptLedger` having a stable
     * shape, but we don't use it here — just confirm `AtlasLoopAutoMergeService` accepts the new nullable
     * detector argument while remaining back-compat (no detector ⇒ old behavior).
     */
    public function test_auto_merge_service_remains_back_compat_without_a_detector(): void
    {
        $preFlight = $this->preFlightAllowing('main-sha');
        $service = new AtlasLoopAutoMergeService($preFlight); /* @phpstan-ignore-line back-compat single-arg */

        $result = $service->autoMerge(['base_sha' => $this->realHeadSha(), 'branch' => 'feature-z'], $this->realRepoPath(), static fn (): array => ['status' => 'merged']);

        $this->assertTrue($result['merged']);
        $this->assertNull($result['conflict_report'], 'no detector ⇒ no conflict_report field beyond null');
    }

    private function newAutoMergeService(AtlasLoopAutoMergePreFlightGate $preFlight, AtlasLoopAutoMergeConflictDetector $detector): AtlasLoopAutoMergeService
    {
        return new AtlasLoopAutoMergeService($preFlight, $detector);
    }

    /**
     * The PreFlightGate is `final` so it can't be doubled. We build a real one and pair it with a real tiny
     * git repo whose HEAD sha we capture, so check() actually returns allow=true. Returns the gate; the caller
     * uses {@see realRepoPath()} and {@see realHeadSha()} as the matching repoRoot / base_sha.
     */
    private function preFlightAllowing(string $unused): AtlasLoopAutoMergePreFlightGate
    {
        $this->ensureRealRepo();

        return new AtlasLoopAutoMergePreFlightGate;
    }

    private ?string $realRepoPath = null;

    private ?string $realHeadSha = null;

    private function ensureRealRepo(): void
    {
        if ($this->realRepoPath !== null) {
            return;
        }
        $this->realRepoPath = sys_get_temp_dir().'/atlas_automerge_conflict_test_'.bin2hex(random_bytes(6));
        mkdir($this->realRepoPath, 0775, true);
        $cmds = [
            'cd '.escapeshellarg($this->realRepoPath).' && git init -q -b main',
            'cd '.escapeshellarg($this->realRepoPath).' && git config user.email t@t && git config user.name t',
            'cd '.escapeshellarg($this->realRepoPath).' && git commit --allow-empty -q -m init',
        ];
        foreach ($cmds as $cmd) {
            shell_exec($cmd);
        }
        $this->realHeadSha = trim((string) shell_exec('cd '.escapeshellarg($this->realRepoPath).' && git rev-parse HEAD'));
        if ($this->realHeadSha === '' || $this->realHeadSha === false) {
            $this->markTestSkipped('git not available or repo init failed');
        }
    }

    private function realRepoPath(): string
    {
        $this->ensureRealRepo();

        return (string) $this->realRepoPath;
    }

    private function realHeadSha(): string
    {
        $this->ensureRealRepo();

        return (string) $this->realHeadSha;
    }

    protected function tearDown(): void
    {
        if ($this->realRepoPath !== null && is_dir($this->realRepoPath)) {
            shell_exec('rm -rf '.escapeshellarg($this->realRepoPath));
        }
        parent::tearDown();
    }
}
