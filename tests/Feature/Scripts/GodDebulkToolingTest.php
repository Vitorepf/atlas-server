<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GodDebulkToolingTest extends TestCase
{
    public function test_audit_reports_the_stable_baseline_markers(): void
    {
        $process = $this->runCommand(['/opt/homebrew/bin/php', 'scripts/god-debulk-audit.php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_AUDIT_OK', $process->getOutput());
        $this->assertStringContainsString('godfiles_gt_5k=', $process->getOutput());
        $this->assertStringContainsString('godfiles_gt_2k=', $process->getOutput());
    }

    public function test_guard_reports_its_stable_success_marker_without_mutating_history(): void
    {
        $process = $this->runCommand(['bash', 'scripts/god-debulk-guard.sh']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_GUARD_OK', $process->getOutput());
    }

    public function test_codemap_verifier_reports_its_stable_success_marker(): void
    {
        $process = $this->runCommand(['/opt/homebrew/bin/php', 'scripts/god-debulk-codemap-verify.php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_CODEMAP_OK', $process->getOutput());
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command): Process
    {
        $process = new Process($command, base_path());
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}
