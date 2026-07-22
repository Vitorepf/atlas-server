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

    public function test_guard_rejects_an_uppercase_residual_pass_subject_without_mutating_history(): void
    {
        $fixture = $this->gitSubjectFixture('RESIDUAL PASS 3');

        try {
            $process = $this->runCommand(['bash', 'scripts/god-debulk-guard.sh'], $fixture['environment']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_GUARD_FAIL residual_pass_commit', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_guard_accepts_a_word_that_only_contains_residual(): void
    {
        $fixture = $this->gitSubjectFixture('nonresidual pass 3');

        try {
            $process = $this->runCommand(['bash', 'scripts/god-debulk-guard.sh'], $fixture['environment']);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('GOD_DEBULK_GUARD_OK', $process->getOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_reports_its_stable_success_marker(): void
    {
        $process = $this->runCommand(['/opt/homebrew/bin/php', 'scripts/god-debulk-codemap-verify.php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_CODEMAP_OK', $process->getOutput());
    }

    public function test_codemap_verifier_rejects_a_navigation_target_mentioned_only_in_prose(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'god-debulk-codemap-');
        $this->assertNotFalse($fixture);
        file_put_contents($fixture, <<<'MARKDOWN'
# Fixture

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

This prose mentions `App\Services\Ai\Router\AtlasAiIntentKernelService::classify`,
but it is not a navigation table row.
MARKDOWN);

        try {
            $process = $this->runCommand(['/opt/homebrew/bin/php', 'scripts/god-debulk-codemap-verify.php'], [
                'GOD_DEBULK_CODEMAP_PATH' => $fixture,
            ]);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            @unlink($fixture);
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command, array $environment = []): Process
    {
        $process = new Process($command, base_path(), array_replace([
            'GOD_DEBULK_ENFORCE' => '0',
        ], $environment));
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /**
     * @return array{environment:array<string,string>,cleanup:callable():void}
     */
    private function gitSubjectFixture(string $subject): array
    {
        $directory = sys_get_temp_dir().'/god-debulk-git-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $git = $directory.'/git';
        file_put_contents($git, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "${GOD_DEBULK_TEST_SUBJECT:?}"
BASH);
        chmod($git, 0700);

        return [
            'environment' => [
                'GOD_DEBULK_TEST_SUBJECT' => $subject,
                'PATH' => $directory.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            ],
            'cleanup' => static function () use ($git, $directory): void {
                @unlink($git);
                @rmdir($directory);
            },
        ];
    }
}
