<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeConflictDetector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the auto-merge conflict detector is live at the operator surface: a clean merge-tree probe reports
 * clean, a conflicting probe reports the conflicting paths; missing args are a usage_error. The probe is a
 * fake so the test depends on no git state.
 */
final class AtlasLoopAutoMergeConflictCommandTest extends TestCase
{
    private function bindProbe(callable $probe): void
    {
        $this->app->bind(
            AtlasLoopAutoMergeConflictDetector::class,
            static fn (): AtlasLoopAutoMergeConflictDetector => new AtlasLoopAutoMergeConflictDetector($probe),
        );
    }

    public function test_clean_merge_reports_clean(): void
    {
        $this->bindProbe(static fn (string $r, string $m, string $b): array => [
            'conflicted_files' => [],
            'conflicted_hunks' => [],
            'runner_error' => null,
        ]);

        $exit = Artisan::call('atlas:loop:auto-merge-conflict', ['--main' => 'mainsha', '--branch' => 'feature', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['clean']);
        $this->assertSame([], $decoded['paths_in_conflict']);
    }

    public function test_conflicting_merge_reports_dirty(): void
    {
        $this->bindProbe(static fn (string $r, string $m, string $b): array => [
            'conflicted_files' => ['app/Foo.php'],
            'conflicted_hunks' => [['path' => 'app/Foo.php', 'start' => 1, 'end' => 5]],
            'runner_error' => null,
        ]);

        $exit = Artisan::call('atlas:loop:auto-merge-conflict', ['--main' => 'mainsha', '--branch' => 'feature', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['clean']);
        $this->assertContains('app/Foo.php', $decoded['paths_in_conflict']);
    }

    public function test_missing_args_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:auto-merge-conflict', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
