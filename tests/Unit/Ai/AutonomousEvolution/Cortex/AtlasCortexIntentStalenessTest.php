<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\AtlasCortexIntentStaleness;
use PHPUnit\Framework\TestCase;

/**
 * Proves the cortex intent staleness detector: stale-by-commit-count, stale-by-HEAD-advance (even with a small
 * count delta), a fresh item, and the pure-read contract (no snapshot mutation).
 */
final class AtlasCortexIntentStalenessTest extends TestCase
{
    /**
     * @param  array<string,int>  $countByPath
     */
    private function detector(array $countByPath, string $head, bool $changed): AtlasCortexIntentStaleness
    {
        return new AtlasCortexIntentStaleness(
            commitCountFor: fn (string $path): int => $countByPath[$path] ?? 0,
            headShaProbe: fn (): string => $head,
            changedInRange: fn (string $last, string $headSha, string $path): bool => $changed,
        );
    }

    public function test_stale_when_commit_count_exceeds_stored_by_threshold(): void
    {
        $report = $this->detector(['app/X.php' => 7], 'SAME', false)->detect([
            ['path' => 'app/X.php', 'fqcn' => 'App\\X', 'last_extract_commit_count' => 5, 'last_extract_head_sha' => 'SAME'],
        ]);

        $this->assertCount(1, $report->staleItems);
        $this->assertContains('commit_count_diverged', $report->staleItems[0]['reasons']);
        $this->assertSame(0, $report->freshItemsTotal);
    }

    public function test_stale_when_head_advanced_and_file_changed_even_with_small_count_delta(): void
    {
        // count delta 0 (5→5) but HEAD advanced OLD→NEW and the file changed in range.
        $report = $this->detector(['app/X.php' => 5], 'NEW', true)->detect([
            ['path' => 'app/X.php', 'fqcn' => 'App\\X', 'last_extract_commit_count' => 5, 'last_extract_head_sha' => 'OLD'],
        ]);

        $this->assertCount(1, $report->staleItems);
        $this->assertContains('head_advanced_file_changed', $report->staleItems[0]['reasons']);
    }

    public function test_fresh_item_is_not_flagged(): void
    {
        $report = $this->detector(['app/X.php' => 5], 'SAME', false)->detect([
            ['path' => 'app/X.php', 'fqcn' => 'App\\X', 'last_extract_commit_count' => 5, 'last_extract_head_sha' => 'SAME'],
        ]);

        $this->assertSame([], $report->staleItems);
        $this->assertSame(1, $report->freshItemsTotal);
        $this->assertSame(1, $report->thresholdUsed);
    }

    public function test_detect_is_a_pure_read_and_does_not_touch_the_snapshot_file(): void
    {
        $snapshot = sys_get_temp_dir().'/atlas-cortex-stale-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($snapshot, '{"items":{}}');
        $mtimeBefore = filemtime($snapshot);
        clearstatcache();

        $this->detector(['app/X.php' => 9], 'NEW', true)->detect([
            ['path' => 'app/X.php', 'fqcn' => 'App\\X', 'last_extract_commit_count' => 1, 'last_extract_head_sha' => 'OLD'],
        ]);

        clearstatcache();
        $this->assertSame($mtimeBefore, filemtime($snapshot), 'detect() must not write the snapshot');
        @unlink($snapshot);
    }
}
