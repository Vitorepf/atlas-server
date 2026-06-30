<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainFrontierIngestCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-frontier-ingest-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
        config()->set('atlas.brain.frontier_root', $this->root);
        config()->set('atlas.brain.default_scope', 'loop');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*.ndjson') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_ingests_discover_sources_without_duplicating_urls(): void
    {
        $args = [
            '--scope' => 'loop',
            '--limit' => 2,
            '--captured-at' => '2026-06-29T00:00:00Z',
            '--json' => true,
        ];

        [$exit, $payload] = $this->callJson($args);

        self::assertSame(0, $exit);
        self::assertSame(2, $payload['appended']);
        self::assertSame(0, $payload['skipped_existing']);
        self::assertSame('research-source-registry', $payload['candidates'][0]['source']);
        self::assertSame(2, app(AtlasBrainFrontierSourceRegistry::class)->count('loop'));

        [, $payload] = $this->callJson($args);

        self::assertSame(2, $payload['appended']);
        self::assertSame(2, $payload['skipped_existing']);
        $rows = app(AtlasBrainFrontierSourceRegistry::class)->topK('loop', 10);
        self::assertSame(4, count($rows));
        self::assertSame(4, count(array_unique(array_column($rows, 'url'))));
    }

    public function test_limit_zero_appends_zero_rows(): void
    {
        [$exit, $payload] = $this->callJson([
            '--scope' => 'loop',
            '--limit' => 0,
            '--captured-at' => '2026-06-29T00:00:00Z',
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertSame(0, $payload['appended'], '--limit=0 must append 0 rows, not 1');
        self::assertSame(0, app(AtlasBrainFrontierSourceRegistry::class)->count('loop'));
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function callJson(array $args): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('atlas:brain:frontier-ingest', $args, $buffer);

        return [$exit, json_decode(trim($buffer->fetch()), true)];
    }
}
