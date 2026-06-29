<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory\AtlasCortexMemoryEpisodicLedger;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the Cortex episodic ledger is live at the operator surface: seeded episodes are streamed in
 * deterministic (captured_at, cycle_id) order, the --limit is honored, and an absent ledger yields nothing.
 */
final class AtlasLoopCortexMemoryEpisodesCommandTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-cortex-episodes-'.bin2hex(random_bytes(5)).'.ndjson';
        $this->app->instance(
            AtlasCortexMemoryEpisodicLedger::class,
            new AtlasCortexMemoryEpisodicLedger($this->path),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function episode(string $cycleId, int $capturedAt): array
    {
        return [
            'cycle_id' => $cycleId,
            'captured_at' => $capturedAt,
            'scope_root' => 'app/Services/Ai/Marketing',
            'snapshot_digest' => 'dig-'.$cycleId,
            'inventory_items' => [],
            'blind_spots' => [],
            'intent_interpretations' => [],
            'source_facts_only' => true,
        ];
    }

    private function seedLedger(array $episodes): void
    {
        $lines = array_map(static fn (array $e): string => (string) json_encode($e), $episodes);
        file_put_contents($this->path, implode("\n", $lines)."\n");
    }

    public function test_streams_episodes_in_deterministic_order(): void
    {
        // written out of order — iterate() must sort by captured_at asc
        $this->seedLedger([$this->episode('cyc-b', 200), $this->episode('cyc-a', 100)]);

        $exit = Artisan::call('atlas:loop:cortex-memory-episodes', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.cortex_memory_episodes.v1', $decoded['schema']);
        $this->assertSame(2, $decoded['episode_count']);
        $this->assertSame(['cyc-a', 'cyc-b'], array_column($decoded['episodes'], 'cycle_id'));
    }

    public function test_limit_is_honored(): void
    {
        $this->seedLedger([$this->episode('cyc-a', 100), $this->episode('cyc-b', 200), $this->episode('cyc-c', 300)]);

        $exit = Artisan::call('atlas:loop:cortex-memory-episodes', ['--limit' => 2, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(2, $decoded['episode_count']);
        $this->assertSame(['cyc-a', 'cyc-b'], array_column($decoded['episodes'], 'cycle_id'));
    }

    public function test_absent_ledger_yields_no_episodes(): void
    {
        // no file written
        $exit = Artisan::call('atlas:loop:cortex-memory-episodes', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $decoded['episode_count']);
        $this->assertSame([], $decoded['episodes']);
    }
}
