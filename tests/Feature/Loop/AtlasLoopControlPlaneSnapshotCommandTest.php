<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V3 control-plane snapshot is live at the operator surface from a supplied input: empty input
 * yields the full snapshot envelope with no evidence and no errors. A missing --input is a usage error.
 */
final class AtlasLoopControlPlaneSnapshotCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_input(): void
    {
        $exit = Artisan::call('atlas:loop:control-plane-snapshot', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_empty_input_yields_full_no_evidence_snapshot(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cps_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode((object) []));

        $exit = Artisan::call('atlas:loop:control-plane-snapshot', ['--input' => $path, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.v3.self_construction_control_plane.v1', $decoded['schema']);
        foreach (['graders_promotable', 'lever', 'strategy', 'transfers_ready', 'ready_actions', 'errors'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
        $this->assertSame([], $decoded['ready_actions']);
        $this->assertFalse($decoded['has_evidence']);
        $this->assertSame([], $decoded['errors']);
    }
}
