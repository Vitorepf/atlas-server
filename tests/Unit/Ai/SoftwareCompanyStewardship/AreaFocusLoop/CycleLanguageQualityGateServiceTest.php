<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CycleLanguageQualityGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CyclePhpTierRunner;
use Tests\TestCase;

/**
 * In-memory fake runner — records calls, returns scripted statuses. NO shell.
 */
final class FakeCyclePhpTierRunner implements CyclePhpTierRunner
{
    /** @var list<array{tool:string,files:list<string>}> */
    public array $calls = [];

    /** @param array<string,string> $statusByTool */
    public function __construct(private array $statusByTool = [], private string $output = '') {}

    public function run(string $tool, string $repoRoot, string $worktree, array $files): array
    {
        $this->calls[] = ['tool' => $tool, 'files' => $files];
        $status = $this->statusByTool[$tool] ?? 'passed';
        $exit = match ($status) { 'passed', 'nothing_to_analyze' => 0, 'failed' => 1, 'timeout' => 124, default => 255 };

        return ['tool' => $tool, 'command' => $tool, 'status' => $status, 'exit_code' => $exit, 'output' => $this->output, 'analyzed' => count($files)];
    }
}

/**
 * HARD LAW: EXTREME diff-scoped language-quality gate. Fail-closed, PHP-wired,
 * no-op unless enforce. These pin every decision path with a fake runner.
 */
final class CycleLanguageQualityGateServiceTest extends TestCase
{
    private function enforce(bool $on = true): void
    {
        config(['atlas.software_company_stewardship.language_quality.enforcement' => $on ? 'enforce' : 'off']);
        config(['atlas.software_company_stewardship.language_quality.toolchains' => ['php' => ['tools' => ['phpstan']]]]);
    }

    public function test_enforcement_off_is_a_noop_runs_nothing_blocks_nothing(): void
    {
        $this->enforce(false);
        $fake = new FakeCyclePhpTierRunner;
        $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', ['app/Foo.php']);

        $this->assertTrue($r['passed']);
        $this->assertNull($r['blocker']);
        $this->assertSame([], $fake->calls, 'off => zero shell, so the existing suite is byte-identical');
    }

    public function test_php_changed_file_runs_phpstan_diff_scoped_and_passes_when_clean(): void
    {
        $this->enforce();
        $fake = new FakeCyclePhpTierRunner(['phpstan' => 'passed']);
        $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', ['app/Foo.php', 'README.md', 'config/x.yaml']);

        $this->assertTrue($r['passed']);
        $this->assertSame(['php'], $r['languages']);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('phpstan', $fake->calls[0]['tool']);
        $this->assertSame(['app/Foo.php'], $fake->calls[0]['files'], 'diff-scoped: only the changed .php file, never the whole app');
    }

    public function test_phpstan_errors_block_and_carry_exact_output(): void
    {
        $this->enforce();
        $fake = new FakeCyclePhpTierRunner(['phpstan' => 'failed'], 'app/Foo.php:42: should return int but returns string');
        $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', ['app/Foo.php']);

        $this->assertFalse($r['passed']);
        $this->assertSame(CycleLanguageQualityGateService::BLOCKER, $r['blocker']);
        $this->assertStringContainsString('returns string', $r['tool_results'][0]['output_excerpt']);
        $this->assertFalse($r['tool_results'][0]['ok']);
    }

    public function test_phpstan_crash_or_timeout_fails_closed(): void
    {
        $this->enforce();
        foreach (['crashed', 'timeout'] as $status) {
            $fake = new FakeCyclePhpTierRunner(['phpstan' => $status]);
            $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', ['app/Foo.php']);
            $this->assertFalse($r['passed'], "phpstan {$status} must fail closed (cannot verify => block)");
        }
    }

    public function test_non_php_language_is_fail_closed_blocked(): void
    {
        $this->enforce();
        $fake = new FakeCyclePhpTierRunner;
        $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', ['resources/js/app.ts']);

        $this->assertFalse($r['passed']);
        $this->assertSame(CycleLanguageQualityGateService::BLOCKER, $r['blocker']);
        $this->assertContains('ts', $r['fail_closed_languages']);
        $this->assertSame([], $fake->calls, 'no wired toolchain => block without running anything');
    }

    public function test_unknown_executable_class_is_fail_closed_blocked(): void
    {
        $this->enforce();
        $fake = new FakeCyclePhpTierRunner;
        $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', ['crates/x.rs']);

        $this->assertFalse($r['passed']);
        $this->assertSame(CycleLanguageQualityGateService::BLOCKER, $r['blocker']);
        $this->assertSame(['crates/x.rs'], $r['unknown_files']);
    }

    public function test_docs_and_ignored_paths_never_gate(): void
    {
        $this->enforce();
        $fake = new FakeCyclePhpTierRunner;
        $r = (new CycleLanguageQualityGateService($fake))->assess('/root', '/wt', [
            'docs/x.md', 'config/y.yaml', 'vendor/z.php', 'node_modules/a.js', 'public/build/app.min.js',
        ]);

        $this->assertTrue($r['passed']);
        $this->assertSame([], $r['languages']);
        $this->assertSame([], $fake->calls);
    }
}
