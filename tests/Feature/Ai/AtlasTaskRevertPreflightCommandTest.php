<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskRevertCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasTaskRevertPreflightCommandTest extends TestCase
{
    public function test_revert_fails_closed_for_missing_task(): void
    {
        $exit = Artisan::call('atlas:task:revert', [
            '--task' => 'nonexistent-task-id',
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertTrue($output['refused'] ?? false);
        $this->assertSame('ambiguous_sha', $output['reason'] ?? null);
    }

    public function test_revert_preflight_without_task_option(): void
    {
        $exit = Artisan::call('atlas:task:revert', [
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('--task', $output);
    }

    public function test_rollback_preflight_fails_closed_for_missing_target(): void
    {
        $exit = Artisan::call('atlas:cli:rollback', [
            '--to' => 'nonexistent-ref-12345',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertFalse($output['ok'] ?? true);
        $this->assertSame('target_not_found', $output['status'] ?? null);
    }

    public function test_rollback_dry_run_does_not_mutate(): void
    {
        $exit = Artisan::call('atlas:cli:rollback', [
            '--steps' => 1,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = json_decode(Artisan::output(), true);
        $this->assertTrue($output['ok'] ?? false);
        $this->assertSame('dry_run', $output['status'] ?? null);
        $this->assertArrayHasKey('head', $output);
        $this->assertArrayHasKey('target', $output);
        $this->assertArrayHasKey('migration_files_since_target', $output);
    }
}
