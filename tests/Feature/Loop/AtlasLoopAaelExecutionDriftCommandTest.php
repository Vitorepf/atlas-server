<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the AAEL execution-drift auditor is live at the operator surface: a faithful execution shows no drift;
 * a path outside the plan + a skipped acceptance command are flagged; an untouched objective anchor file is
 * detected.
 */
final class AtlasLoopAaelExecutionDriftCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-aael-drift-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function audit(array $task, array $exploration): array
    {
        file_put_contents($this->input, (string) json_encode(['task' => $task, 'exploration' => $exploration]));
        $exit = Artisan::call('atlas:loop:aael-execution-drift', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_faithful_execution_has_no_drift(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->audit(
            ['objective' => 'Edit app/Foo.php', 'allowed_files' => ['app/Foo.php'], 'acceptance' => ['commands' => ['phpunit']]],
            ['diff_paths' => ['app/Foo.php'], 'commands_exercised' => ['phpunit']],
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.aael_execution_drift.v1', $d['schema']);
        $this->assertFalse($d['drift_detected'], (string) json_encode($d));
        $this->assertSame([], $d['extra_paths_outside_plan']);
        $this->assertSame([], $d['acceptance_commands_skipped']);
        $this->assertFalse($d['anchor_file_untouched']);
    }

    public function test_extra_path_and_skipped_command_are_drift(): void
    {
        ['d' => $d] = $this->audit(
            ['objective' => 'Edit app/Foo.php', 'allowed_files' => ['app/Foo.php'], 'acceptance' => ['commands' => ['phpunit']]],
            ['diff_paths' => ['app/Foo.php', 'app/Bar.php'], 'commands_exercised' => []],
        );

        $this->assertTrue($d['drift_detected']);
        $this->assertSame(['app/Bar.php'], $d['extra_paths_outside_plan']);
        $this->assertSame(['phpunit'], $d['acceptance_commands_skipped']);
    }

    public function test_untouched_anchor_file_is_detected(): void
    {
        ['d' => $d] = $this->audit(
            ['objective' => 'Edit app/Foo.php', 'allowed_files' => ['app/Foo.php'], 'acceptance' => ['commands' => []]],
            ['diff_paths' => ['app/Other.php'], 'commands_exercised' => []],
        );

        $this->assertTrue($d['drift_detected']);
        $this->assertTrue($d['anchor_file_untouched'], (string) json_encode($d));
        $this->assertSame(['app/Foo.php'], $d['missing_planned_paths']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:aael-execution-drift', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
