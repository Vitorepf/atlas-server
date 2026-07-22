<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioDiffMetricsCalculator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasEvolutionScenarioDiffMetricsCalculatorTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/atlas-diff-metrics-'.bin2hex(random_bytes(4));
        mkdir($this->workspace, 0o755, true);
        $this->git(['git', 'init', '-q']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '--allow-empty', '-m', 'init']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace], null, null, null, 30.0))->run();
        }
        parent::tearDown();
    }

    public function test_diff_size_counts_unstaged_staged_and_untracked(): void
    {
        file_put_contents($this->workspace.'/tracked.txt', "alpha\n");
        $this->git(['git', 'add', '.']);
        $this->git(['git', '-c', 'user.email=t@t', '-c', 'user.name=t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'seed']);

        // 1 unstaged edit (1 line changed)
        file_put_contents($this->workspace.'/tracked.txt', "beta\n");
        // 1 staged edit
        file_put_contents($this->workspace.'/staged.txt', "x\ny\n");
        $this->git(['git', 'add', 'staged.txt']);
        // 1 untracked file
        file_put_contents($this->workspace.'/new.txt', "one\ntwo\nthree\n");

        $calc = new AtlasEvolutionScenarioDiffMetricsCalculator();
        $size = $calc->diffSize($this->workspace);

        $this->assertSame(3, $size['files']);
        $this->assertGreaterThan(0, $size['lines']);
    }

    public function test_is_smaller_diff_compares_files_then_lines(): void
    {
        $calc = new AtlasEvolutionScenarioDiffMetricsCalculator();
        $this->assertTrue($calc->isSmallerDiff(['files' => 1, 'lines' => 100], ['files' => 2, 'lines' => 1]));
        $this->assertFalse($calc->isSmallerDiff(['files' => 2, 'lines' => 1], ['files' => 1, 'lines' => 100]));
        $this->assertTrue($calc->isSmallerDiff(['files' => 1, 'lines' => 5], ['files' => 1, 'lines' => 6]));
        $this->assertFalse($calc->isSmallerDiff(['files' => 1, 'lines' => 5], ['files' => 1, 'lines' => 5]));
    }

    public function test_numstat_size_parses_numstat_output(): void
    {
        $stat = new Process(['printf', "5\t3\tfoo.php\n10\t0\tbar.php\n-\t-\timg.png\n"], null, null, null, 5.0);
        $stat->run();

        $calc = new AtlasEvolutionScenarioDiffMetricsCalculator();
        $size = $calc->numstatSize($stat);
        // 2 numeric rows counted; img.png with '-' skipped from line totals but counted as file (the original regex DOES match the '-' pair).
        $this->assertSame(3, $size['files']);
        $this->assertSame(18, $size['lines']);
    }

    public function test_untracked_size_counts_each_untracked_file_and_its_lines(): void
    {
        file_put_contents($this->workspace.'/a.txt', "1\n2\n3\n");
        file_put_contents($this->workspace.'/b.txt', 'no-newline');

        $others = new Process(['printf', "a.txt\nb.txt\n"], null, null, null, 5.0);
        $others->run();

        $calc = new AtlasEvolutionScenarioDiffMetricsCalculator();
        $size = $calc->untrackedSize($this->workspace, $others);
        $this->assertSame(2, $size['files']);
        // a.txt = 3 lines; b.txt = 1 line (no trailing newline -> +1)
        $this->assertSame(4, $size['lines']);
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(array $argv): void
    {
        $proc = new Process($argv, $this->workspace, null, null, 15.0);
        $proc->run();
    }
}
