<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the idea-draft admission rubric is live at the operator surface: a draft missing a PROBE BLOCK is
 * rejected (ok=false, reasons carry missing_probe_block), and a missing --file is a usage_error.
 */
final class AtlasLoopRubricCheckCommandTest extends TestCase
{
    public function test_draft_without_probe_block_is_rejected(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'atlas_rubric_').'.md';
        file_put_contents($tempFile, "# Draft idea\n\nIncrease the retries threshold to make it more robust.\n");

        try {
            $exit = Artisan::call('atlas:loop:rubric-check', ['--file' => $tempFile, '--json' => true]);
            $decoded = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exit);
            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['ok']);
            $this->assertContains('missing_probe_block', $decoded['reasons']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function test_missing_file_returns_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:rubric-check');

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
