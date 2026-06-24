<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopCortexIntentCommandTest extends TestCase
{
    private string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotPath = sys_get_temp_dir().'/atlas-cortex-command-'.bin2hex(random_bytes(5)).'/intent-snapshot.json';
        config(['atlas.cortex.intent_snapshot_path' => $this->snapshotPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotPath);
        @rmdir(dirname($this->snapshotPath));
        parent::tearDown();
    }

    public function test_inspect_known_fqcn_json_outputs_triangulated_intent_fact(): void
    {
        $this->writeSnapshot([
            'App\\Known' => [
                'fqcn' => 'App\\Known',
                'purpose_statement' => 'Known purpose.',
                'evidence' => ['extractor' => [], 'history' => [], 'siblings' => []],
                'confidence_score' => 80,
                'conflicts' => [],
            ],
        ]);

        $exit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'inspect', '--fqcn' => 'App\\Known', '--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('Known purpose.', $decoded['purpose_statement']);
        $this->assertSame(80, $decoded['confidence_score']);
        $this->assertArrayHasKey('conflicts', $decoded);
    }

    public function test_refresh_rebuilds_snapshot_and_reports_items_total(): void
    {
        config(['atlas.cortex.intent_items' => [['fqcn' => 'App\\One'], ['fqcn' => 'App\\Two']]]);

        $exit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'refresh']);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->snapshotPath);
        $this->assertStringContainsString('items_total=2', Artisan::output());
        $decoded = json_decode((string) file_get_contents($this->snapshotPath), true);
        $this->assertSame(2, $decoded['summary']['items_total']);
    }

    public function test_diff_exits_one_when_stale_items_exist_and_zero_when_empty(): void
    {
        $this->writeSnapshot(['App\\Known' => ['fqcn' => 'App\\Known', 'purpose_statement' => null, 'evidence' => [], 'confidence_score' => 0, 'conflicts' => []]]);
        config([
            'atlas.cortex.intent_staleness_items' => [['path' => 'app/Known.php', 'fqcn' => 'App\\Known', 'last_extract_commit_count' => 1, 'last_extract_head_sha' => 'OLD']],
            'atlas.cortex.intent_commit_counts' => ['app/Known.php' => 3],
        ]);

        $staleExit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'diff', '--json' => true]);
        $stale = json_decode(Artisan::output(), true);

        config([
            'atlas.cortex.intent_staleness_items' => [['path' => 'app/Known.php', 'fqcn' => 'App\\Known', 'last_extract_commit_count' => 3, 'last_extract_head_sha' => 'HEAD']],
            'atlas.cortex.intent_commit_counts' => ['app/Known.php' => 3],
        ]);
        $freshExit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'diff', '--json' => true]);

        $this->assertSame(1, $staleExit);
        $this->assertNotSame([], $stale['stale_items']);
        $this->assertSame(0, $freshExit);
    }

    public function test_invalid_action_returns_usage_error_and_does_not_rebuild_snapshot(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'bogus']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
        $this->assertFileDoesNotExist($this->snapshotPath);
    }

    /**
     * @param  array<string,array<string,mixed>>  $items
     */
    private function writeSnapshot(array $items): void
    {
        if (! is_dir(dirname($this->snapshotPath))) {
            mkdir(dirname($this->snapshotPath), 0o775, true);
        }
        file_put_contents($this->snapshotPath, json_encode([
            'schema' => 'atlas.cortex.intent_snapshot.v1',
            'generated_at' => '2026-06-24T00:00:00+00:00',
            'items' => $items,
            'summary' => [
                'items_total' => count($items),
                'items_with_purpose' => 1,
                'items_with_conflicts' => 0,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
