<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopArmedCoverageReporter;
use Tests\TestCase;

/**
 * Proves the Armed-Coverage Reporter: flag-gated (OFF ⇒ []), per-primitive {intended, wired, missing} where
 * wired = exactly the consumer files whose contents contain the primitive class name (proven on a temp fixture
 * tree, no proxy metric), and read-only (recomputed each call, persists nothing).
 */
final class AtlasLoopArmedCoverageReporterTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = sys_get_temp_dir().'/atlas-armed-cov-'.bin2hex(random_bytes(6));
        mkdir($this->repoRoot.'/src', 0o755, true);
        // 2 consumers reference the primitive (wired), 1 does not (missing),
        // 1 references only a SUPERSTRING sibling (must NOT count as wired).
        file_put_contents($this->repoRoot.'/src/A.php', "<?php\n// uses new AtlasLoopLeverageSelector()\n");
        file_put_contents($this->repoRoot.'/src/B.php', "<?php\nclass B { public function f() { return AtlasLoopLeverageSelector::class; } }\n");
        file_put_contents($this->repoRoot.'/src/C.php', "<?php\nclass C { public function f() { return 1; } }\n");
        file_put_contents($this->repoRoot.'/src/D.php', "<?php\nclass D { public function f() { return AtlasLoopLeverageSelectorAdvanced::class; } }\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->repoRoot.'/src/*') ?: []);
        @rmdir($this->repoRoot.'/src');
        @rmdir($this->repoRoot);
        parent::tearDown();
    }

    private function stub(): \Closure
    {
        return static fn (): array => [[
            'primitive_id' => 'atlas_loop_leverage_selector',
            'file_path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopLeverageSelector.php',
            'config_flag' => 'atlas.loop.leverage_first_enabled',
            'status' => 'intended',
            'intended_consumer_paths' => ['src/A.php', 'src/B.php', 'src/C.php', 'src/D.php'],
        ]];
    }

    private function reporter(): AtlasLoopArmedCoverageReporter
    {
        return new AtlasLoopArmedCoverageReporter(null, $this->stub(), $this->repoRoot);
    }

    public function test_flag_off_returns_empty(): void
    {
        config(['atlas.loop.armed_coverage_reporter_enabled' => false]);

        $this->assertSame([], $this->reporter()->report());
    }

    public function test_wired_count_equals_grep_positive_consumers(): void
    {
        config(['atlas.loop.armed_coverage_reporter_enabled' => true]);

        $report = $this->reporter()->report();

        $this->assertArrayHasKey('atlas_loop_leverage_selector', $report);
        $row = $report['atlas_loop_leverage_selector'];
        $this->assertSame(4, $row['intended']);
        $this->assertSame(2, $row['wired'], 'exactly the 2 fixture files containing AtlasLoopLeverageSelector');
        $this->assertSame(['src/C.php', 'src/D.php'], $row['missing'], 'C is dark, D only references the superstring sibling');
    }

    public function test_superstring_sibling_not_counted_as_wired(): void
    {
        config(['atlas.loop.armed_coverage_reporter_enabled' => true]);

        $report = $this->reporter()->report();
        $row = $report['atlas_loop_leverage_selector'];

        $this->assertNotContains('src/A.php', $row['missing']);
        $this->assertNotContains('src/B.php', $row['missing']);
        $this->assertContains('src/D.php', $row['missing'], 'superstring sibling consumer must be missing, not wired');
        $this->assertSame(2, $row['wired'], 'wired must not be inflated by the superstring sibling');
    }

    public function test_is_read_only_and_recomputed_not_persisted(): void
    {
        config(['atlas.loop.armed_coverage_reporter_enabled' => true]);

        $before = glob($this->repoRoot.'/src/*');
        $run1 = $this->reporter()->report();
        $run2 = $this->reporter()->report();
        $after = glob($this->repoRoot.'/src/*');

        $this->assertSame($before, $after, 'reporter writes NOTHING — pure read-model');
        $this->assertSame(json_encode($run1), json_encode($run2), 'recomputed deterministically, no stored average');
    }
}
