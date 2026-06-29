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

    public function test_extract_emits_real_docblock_purpose_from_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'atlas_extract_').'.php';
        $purposeText = 'Extracted purpose for testing extract verb wiring.';
        file_put_contents($tempFile, <<<PHP
<?php
namespace AtlasExtractTest;

/**
 * Atlas extract fixture.
 *
 * @purpose {$purposeText}
 * @design Inline design note for the extract test.
 */
final class ExtractFixture
{
    public function ping(): string
    {
        return 'pong';
    }
}
PHP);

        try {
            $exit = Artisan::call('atlas:loop:cortex:intent', [
                'action' => 'extract',
                '--file' => $tempFile,
                '--json' => true,
            ]);

            $this->assertSame(0, $exit);
            $decoded = json_decode(Artisan::output(), true);
            $this->assertIsArray($decoded);
            $this->assertSame($purposeText, $decoded['docblock_purpose']);
            $this->assertSame('high', $decoded['confidence']);
            $this->assertStringContainsString('ExtractFixture', $decoded['fqcn']);
            $this->assertSame($tempFile, $decoded['source_file']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function test_extract_without_file_returns_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'extract']);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('extract requires --file', Artisan::output());
    }

    public function test_triangulate_emits_fused_intent_fact_from_real_legs(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'atlas_triangulate_').'.php';
        $purposeText = 'Triangulated purpose fusing the docblock extractor and decision history legs.';
        file_put_contents($tempFile, <<<PHP
<?php
namespace AtlasTriangulateTest;

/**
 * Atlas triangulate fixture.
 *
 * @purpose {$purposeText}
 * @design Inline design note for the triangulate test.
 */
final class TriangulateFixture
{
    public function ping(): string
    {
        return 'pong';
    }
}
PHP);

        try {
            $exit = Artisan::call('atlas:loop:cortex:intent', [
                'action' => 'triangulate',
                '--file' => $tempFile,
                '--json' => true,
            ]);

            $this->assertSame(0, $exit);
            $decoded = json_decode(Artisan::output(), true);
            $this->assertIsArray($decoded);
            // The fused fact carries a non-empty purpose from the extractor leg (never the refresh placeholder).
            $this->assertSame($purposeText, $decoded['purpose_statement']);
            // confidence_score is derived from the fused legs — neither 0 (no evidence) nor the refresh path's hardcoded 50.
            $this->assertNotContains($decoded['confidence_score'], [0, 50]);
            $this->assertNotEmpty($decoded['evidence']['extractor'], 'the extractor leg fed real tokens into the fusion');
            $this->assertArrayHasKey('conflicts', $decoded);
            $this->assertStringContainsString('TriangulateFixture', $decoded['fqcn']);
        } finally {
            @unlink($tempFile);
        }
    }

    public function test_triangulate_without_file_returns_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:cortex:intent', ['action' => 'triangulate']);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('triangulate requires --file', Artisan::output());
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
