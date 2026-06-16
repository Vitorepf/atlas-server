<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\WorkspaceProviderLoopExecutionDriver;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE B2 — the AGGREGATE prompt budget for the CURRENT FILE CONTENTS dump. Each file is capped individually,
 * but without a total cap a many-file obra swamps the weak engine's small window. The aggregate cap (0 =
 * unlimited = byte-identical) clips the total and marks the clip with [TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET].
 */
final class WorkspaceProviderPromptBudgetTest extends TestCase
{
    private string $ws = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ws = sys_get_temp_dir().'/atlas-b2-'.bin2hex(random_bytes(4));
        @mkdir($this->ws.'/app', 0o755, true);
        file_put_contents($this->ws.'/app/A.php', str_repeat('a', 100));
        file_put_contents($this->ws.'/app/B.php', str_repeat('b', 100));
    }

    protected function tearDown(): void
    {
        if ($this->ws !== '' && is_dir($this->ws)) {
            (new Process(['rm', '-rf', $this->ws]))->run();
        }
        parent::tearDown();
    }

    private function fileContents(): string
    {
        $driver = $this->app->make(WorkspaceProviderLoopExecutionDriver::class);
        $m = new ReflectionMethod($driver, 'currentFileContents');
        $m->setAccessible(true);

        return implode("\n", (array) $m->invoke($driver, ['app/A.php', 'app/B.php'], $this->ws));
    }

    public function test_unlimited_budget_is_byte_identical_both_files_no_marker(): void
    {
        config(['atlas.loop.prompt_file_contents_budget_chars' => 0]); // default
        $out = $this->fileContents();

        $this->assertStringContainsString('app/A.php', $out);
        $this->assertStringContainsString('app/B.php', $out);
        $this->assertStringNotContainsString('TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET', $out, 'budget 0 => no truncation (byte-identical)');
    }

    public function test_aggregate_budget_clips_the_dump_and_marks_it(): void
    {
        config(['atlas.loop.prompt_file_contents_budget_chars' => 100]); // exactly A's size
        $out = $this->fileContents();

        $this->assertStringContainsString('app/A.php', $out, 'the first file fits the budget');
        $this->assertStringNotContainsString('app/B.php', $out, 'the second file overflows the aggregate budget and is omitted');
        $this->assertStringContainsString('[TRUNCATED_BY_ATLAS_OPEN_BRAIN_BUDGET]', $out);
    }
}
