<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the comprehension-staleness detector is live at the operator surface: a freshly written snapshot is not
 * stale, a missing snapshot reports no_snapshot, and a missing --path is a usage_error.
 */
final class AtlasLoopComprehensionStalenessCommandTest extends TestCase
{
    public function test_fresh_snapshot_is_not_stale(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas_comp_').'.json';
        file_put_contents($tmp, '{}');

        try {
            $exit = Artisan::call('atlas:loop:comprehension-staleness', ['--path' => $tmp, '--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exit);
            $this->assertFalse($decoded['is_stale']);
            $this->assertSame('fresh', $decoded['reason']);
            $this->assertArrayHasKey('threshold_seconds', $decoded);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_missing_snapshot_reports_no_snapshot(): void
    {
        $exit = Artisan::call('atlas:loop:comprehension-staleness', [
            '--path' => sys_get_temp_dir().'/atlas-nonexistent-'.bin2hex(random_bytes(4)).'.json',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['is_stale']);
        $this->assertSame('no_snapshot', $decoded['reason']);
    }

    public function test_missing_path_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:comprehension-staleness', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
