<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the signal analyzer is live at the operator surface: the command emits the deterministic signal
 * fact-sheet (schema_version + flags + summary) over the AutonomousEvolution tree with a non-zero file scan.
 */
final class AtlasLoopSignalScanCommandTest extends TestCase
{
    public function test_signal_scan_emits_schema_flags_and_summary(): void
    {
        $exit = Artisan::call('atlas:loop:signal-scan', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.signal_analyzer.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['flags']);
        $this->assertIsArray($decoded['summary']);
        $this->assertGreaterThan(0, $decoded['summary']['php_files_scanned'], 'the AutonomousEvolution tree has scannable php files');
        $this->assertArrayHasKey('complexity_hotspot_flags', $decoded['summary']);
    }
}
