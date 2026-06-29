<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionGraphSerializer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopComprehensionCommandTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                foreach ((array) scandir($path) as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        @unlink($path.'/'.$entry);
                    }
                }
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_snapshot_action_writes_snapshot_and_prints_fact_json(): void
    {
        $exit = Artisan::call('atlas:loop:comprehension', [
            'action' => 'snapshot',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $out = $this->jsonOutput();
        $this->assertArrayHasKey('snapshot_path', $out);
        $this->assertArrayHasKey('snapshot_sha256', $out);
        $this->assertArrayHasKey('classes_count', $out);
        $this->assertFileExists($out['snapshot_path']);
        $this->assertSame(64, strlen((string) $out['snapshot_sha256']));
        $this->assertGreaterThan(0, $out['classes_count']);
        $this->paths[] = (string) $out['snapshot_path'];
    }

    public function test_diff_action_emits_delta_tracker_fact_keys(): void
    {
        $old = $this->writeSnapshot('snap-a', []);
        $new = $this->writeSnapshot('snap-b', [
            [
                'fqcn' => 'App\\Loop\\NewClass',
                'rel_path' => 'app/Loop/NewClass.php',
                'is_orphan' => true,
            ],
        ]);

        $exit = Artisan::call('atlas:loop:comprehension', [
            'action' => 'diff',
            '--old' => $old,
            '--new' => $new,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $out = $this->jsonOutput();
        foreach ([
            'snapshot_before',
            'snapshot_after',
            'classes_added',
            'classes_removed',
            'orphan_wiring_added',
            'orphan_wiring_removed',
            'supply_seam_added',
            'supply_seam_removed',
            'doc_stated_gaps_added',
            'doc_stated_gaps_removed',
            'hub_centrality_shifts',
        ] as $key) {
            $this->assertArrayHasKey($key, $out);
        }
        $this->assertSame('snap-a', $out['snapshot_before']);
        $this->assertSame('snap-b', $out['snapshot_after']);
        $this->assertSame('App\\Loop\\NewClass', $out['classes_added'][0]['fqcn']);
    }

    public function test_stale_missing_path_is_fact_not_exception(): void
    {
        $exit = Artisan::call('atlas:loop:comprehension', [
            'action' => 'stale',
            '--path' => sys_get_temp_dir().'/atlas-loop-missing-'.bin2hex(random_bytes(4)).'.json',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $out = $this->jsonOutput();
        $this->assertTrue($out['is_stale']);
        $this->assertSame('no_snapshot', $out['reason']);
    }

    public function test_invalid_action_exits_non_zero(): void
    {
        $exit = Artisan::call('atlas:loop:comprehension', [
            'action' => 'bogus',
            '--json' => true,
        ]);

        $this->assertNotSame(0, $exit);
        $out = $this->jsonOutput();
        $this->assertSame('invalid_action', $out['error']);
    }

    public function test_doc_gaps_action_emits_sections_and_doc_stated_gaps(): void
    {
        $dir = $this->tmpDir();
        file_put_contents(
            $dir.'/loop-fixture.md',
            "# Loop Fixture\n\n## Pending Work\n\n- TODO: wire the dormant refiller organ\n- a routine note about the caching layer\n",
        );

        $exit = Artisan::call('atlas:loop:comprehension', [
            'action' => 'doc-gaps',
            '--path' => $dir,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $out = $this->jsonOutput();
        $this->assertSame('atlas.loop.comprehension.doc_gaps.v1', $out['schema']);
        $this->assertNotEmpty($out['sections']);

        $this->assertCount(1, $out['doc_stated_gaps']); // only the TODO bullet, not the normal one
        $this->assertSame('todo', $out['doc_stated_gaps'][0]['gap_marker_hit']);
        $this->assertContains('Pending Work', $out['doc_stated_gaps'][0]['heading_chain']);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $out = json_decode(Artisan::output(), true);
        $this->assertIsArray($out);

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $inventory
     */
    private function writeSnapshot(string $id, array $inventory): string
    {
        $dir = $this->tmpDir();
        $path = (new AtlasLoopComprehensionGraphSerializer)->writeSnapshot([
            'snapshot_id' => $id,
            'inventory' => $inventory,
            'doc_stated_gaps' => [],
            'supply_seams' => [],
            'hub_centrality' => [],
        ], $dir);
        $this->paths[] = $path;

        return $path;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-comprehension-command-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->paths[] = $dir;

        return $dir;
    }
}
