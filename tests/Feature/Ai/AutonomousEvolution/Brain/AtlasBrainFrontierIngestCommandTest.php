<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainFrontierSourceRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
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

    public function test_fetch_dry_run_builds_provider_safe_outbound_payload_without_network(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        [$exit, $payload] = $this->callJson([
            '--scope' => 'loop',
            '--limit' => 3,
            '--captured-at' => '2026-07-12T00:00:00Z',
            '--fetch' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertSame('dry_run', $payload['fetch_status']);
        self::assertFalse($payload['network_attempted']);
        self::assertSame(0, $payload['appended']);
        self::assertSame(0, app(AtlasBrainFrontierSourceRegistry::class)->count('loop'));
        Http::assertNothingSent();

        self::assertSame(['discover', 'read', 'ground'], array_column($payload['outbound_payloads'], 'stage'));
        self::assertTrue($payload['egress_safety']['provider_safe']);
        $outboundJson = json_encode($payload['outbound_payloads'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($outboundJson);
        foreach ([base_path(), storage_path(), 'app/Services/', 'tests/Feature/', 'ATLAS_TOKEN', 'DB_PASSWORD'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $outboundJson);
        }
        foreach ($payload['outbound_payloads'] as $outbound) {
            self::assertSame('static_public_allowlist', $outbound['payload_origin']);
            self::assertFalse($outbound['class_gate']['repo_derived_content_allowed']);
            self::assertSame(['sensitive', 'secret', 'cyber', 'workspace_local'], $outbound['class_gate']['blocked_classes']);
        }
    }

    public function test_fetch_without_dry_run_is_default_gated_and_does_not_send_network(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config()->set('atlas.brain.frontier_fetcher_enabled', false);

        [$exit, $payload] = $this->callJson([
            '--scope' => 'loop',
            '--limit' => 3,
            '--captured-at' => '2026-07-12T00:00:00Z',
            '--fetch' => true,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertSame('gated_off', $payload['fetch_status']);
        self::assertFalse($payload['fetch_enabled']);
        self::assertFalse($payload['network_attempted']);
        self::assertSame(0, $payload['appended']);
        Http::assertNothingSent();
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function callJson(array $args): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('atlas:brain:frontier-ingest', $args, $buffer);

        return [$exit, json_decode(trim($buffer->fetch()), true)];
    }
}
