<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the weekly-digest chain is live at the operator surface: two injected ledger sources with rows inside
 * the 7-day window roll up into one composed snapshot, and the exporter writes the rendered markdown.
 */
final class AtlasLoopWeeklyDigestCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-weekly-digest-'.bin2hex(random_bytes(5));
        config([
            'atlas.loop.master_enabled' => true,
            'atlas.loop.weekly_digest.export_root' => $this->root,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function test_weekly_digest_rolls_up_injected_sources_and_writes(): void
    {
        $recent = gmdate(DATE_ATOM, time() - 86400);
        $this->app->bind('atlas.loop.weekly_digest.sources', fn (): array => [
            'evolution' => fn (string $from, string $to): array => [
                ['occurred_at' => $recent, 'event_kind' => 'served', 'payload' => ['task_packet_id' => 'pkt-1']],
            ],
            'certification' => fn (string $from, string $to): array => [
                ['occurred_at' => $recent, 'event_kind' => 'certified', 'payload' => ['task_packet_id' => 'pkt-2']],
            ],
        ]);

        $exit = Artisan::call('atlas:loop:weekly-digest', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['written'], 'the exporter wrote the digest');
        $this->assertNotNull($decoded['path']);
        $this->assertFileExists($decoded['path']);
        $this->assertSame(2, $decoded['rows_count'], 'one row rolled up from each source');

        // Both sources appear in the rendered markdown with their event kinds.
        $this->assertStringContainsString('## evolution', $decoded['markdown']);
        $this->assertStringContainsString('## certification', $decoded['markdown']);
        $this->assertStringContainsString('served', $decoded['markdown']);
        $this->assertStringContainsString('certified', $decoded['markdown']);
    }
}
