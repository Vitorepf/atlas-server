<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioDiffMetricsCalculator;
use Symfony\Component\Process\Process;

final class AtlasEvolutionScenarioDiffMetricsCalculatorHardeningTest extends TestCase
{
    /**
     * untrackedSize must not silently undercount when a file is unreadable
     * mid-scan — the file still counts but its lines are skipped.
     */
    public function test_unreadable_file_does_not_undercount(): void
    {
        $tmpDir = sys_get_temp_dir().'/diff-metrics-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        // Create two files: one readable, one unreadable.
        $readable = $tmpDir.'/readable.txt';
        $unreadable = $tmpDir.'/unreadable.txt';
        file_put_contents($readable, "line1\nline2\nline3\n");
        file_put_contents($unreadable, "line1\nline2\nline3\n");
        chmod($unreadable, 0o000);

        // Simulate the process output listing both files.
        $output = "readable.txt\nunreadable.txt\n";
        $process = $this->createMock(Process::class);
        $process->method('getOutput')->willReturn($output);

        $calc = new AtlasEvolutionScenarioDiffMetricsCalculator();
        $result = $calc->untrackedSize($tmpDir, $process);

        // Both files should be counted (2 files).
        // Only the readable file contributes lines (3 lines).
        $this->assertSame(2, $result['files']);
        $this->assertSame(3, $result['lines']);

        // Cleanup.
        chmod($unreadable, 0o644);
        @unlink($readable);
        @unlink($unreadable);
        @rmdir($tmpDir);
    }

    /**
     * Verify the source code has the is_string guard.
     */
    public function test_source_has_file_get_contents_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioDiffMetricsCalculator.php');

        $this->assertStringContainsString('is_string', $source, 'untrackedSize must guard file_get_contents with is_string');
        $this->assertStringNotContainsString('(string) file_get_contents(', $source, 'untrackedSize must not cast file_get_contents to string without checking');
    }

    /**
     * Normal readable files work correctly.
     */
    public function test_normal_files_count_correctly(): void
    {
        $tmpDir = sys_get_temp_dir().'/diff-metrics-'.bin2hex(random_bytes(4));
        mkdir($tmpDir, 0o755, true);

        file_put_contents($tmpDir.'/a.txt', "line1\nline2\n");
        file_put_contents($tmpDir.'/b.txt', "x\ny\nz\n");

        $output = "a.txt\nb.txt\n";
        $process = $this->createMock(Process::class);
        $process->method('getOutput')->willReturn($output);

        $calc = new AtlasEvolutionScenarioDiffMetricsCalculator();
        $result = $calc->untrackedSize($tmpDir, $process);

        $this->assertSame(2, $result['files']);
        $this->assertSame(5, $result['lines']);

        @unlink($tmpDir.'/a.txt');
        @unlink($tmpDir.'/b.txt');
        @rmdir($tmpDir);
    }
}
