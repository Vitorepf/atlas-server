<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the clone detector is live at the operator surface: the command emits the deterministic clone-pair
 * report (count + {a, b, similarity} records) over the AutonomousEvolution tree.
 */
final class AtlasLoopCloneScanCommandTest extends TestCase
{
    public function test_clone_scan_emits_clone_pair_report(): void
    {
        $exit = Artisan::call('atlas:loop:clone-scan', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.clone_scan.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['clones']);
        $this->assertSame(count($decoded['clones']), $decoded['clone_pairs_count']);

        if ($decoded['clones'] !== []) {
            foreach (['a', 'b', 'similarity'] as $key) {
                $this->assertArrayHasKey($key, $decoded['clones'][0]);
            }
        }
    }
}
