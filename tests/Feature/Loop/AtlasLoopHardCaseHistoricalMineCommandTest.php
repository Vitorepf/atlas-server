<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseDatasetRegistry;
use App\Services\Ai\AutonomousEvolution\HardCaseBench\AtlasLoopHardCaseHistoricalFailureMiner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the historical-failure miner is live at the operator surface: injected failure events become deduped
 * hard-case candidates, events from disallowed sources are dropped, and the registry is never mutated.
 */
final class AtlasLoopHardCaseHistoricalMineCommandTest extends TestCase
{
    private string $storageRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas-hardcase-mine-'.bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        @unlink($this->storageRoot.'/registry.json');
        @rmdir($this->storageRoot);
        parent::tearDown();
    }

    private function bindMiner(): void
    {
        $registry = new AtlasLoopHardCaseDatasetRegistry();
        $registry->setStorageRootForTesting($this->storageRoot); // fresh, empty registry — nothing deduped away

        $events = static fn (int $sinceDays): iterable => [
            ['source' => 'give_back', 'scope_root' => 'app/Services/Ai/Marketing', 'failure_reason' => 'judge timeout', 'diff_shape_hash' => 'h1', 'captured_at' => '2026-06-20T00:00:00Z', 'ledger_digest' => 'dig1', 'repro_seed' => ['k' => 'v'], 'expected_failure_mode' => 'give_back'],
            ['source' => 'cancellation', 'scope_root' => 'app/Services/Ai/Sales', 'failure_reason' => 'operator cancel', 'diff_shape_hash' => 'h2', 'captured_at' => '2026-06-21T00:00:00Z', 'ledger_digest' => 'dig2'],
            // same signature + source as the first event ⇒ clustered into ONE candidate
            ['source' => 'give_back', 'scope_root' => 'app/Services/Ai/Marketing', 'failure_reason' => 'judge timeout', 'diff_shape_hash' => 'h1', 'captured_at' => '2026-06-22T00:00:00Z', 'ledger_digest' => 'dig3'],
            // disallowed source ⇒ dropped
            ['source' => 'not_a_real_source', 'scope_root' => 'app/X', 'failure_reason' => 'whatever', 'diff_shape_hash' => 'h9', 'captured_at' => '2026-06-23T00:00:00Z'],
        ];

        $this->app->bind(
            AtlasLoopHardCaseHistoricalFailureMiner::class,
            fn (): AtlasLoopHardCaseHistoricalFailureMiner => new AtlasLoopHardCaseHistoricalFailureMiner($registry, $events),
        );
    }

    public function test_mines_deduped_candidates_and_drops_disallowed_sources(): void
    {
        $this->bindMiner();

        $exit = Artisan::call('atlas:loop:hard-case-historical-mine', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.hard_case_historical_mine.v1', $decoded['schema']);
        $this->assertSame(2, $decoded['candidate_count'], (string) json_encode($decoded));

        $sources = array_column($decoded['candidates'], 'source');
        sort($sources);
        $this->assertSame(['cancellation', 'give_back'], $sources);
        $this->assertNotContains('not_a_real_source', $sources);

        // candidate records carry the registry-ready shape
        $this->assertArrayHasKey('case_id', $decoded['candidates'][0]);
        $this->assertArrayHasKey('failure_signature', $decoded['candidates'][0]);

        // ANTI-GOODHART: mining never persists to the registry
        $this->assertFileDoesNotExist($this->storageRoot.'/registry.json');
    }

    public function test_empty_source_yields_zero_candidates(): void
    {
        // No miner bound + no events source ⇒ empty no-op mine.
        $exit = Artisan::call('atlas:loop:hard-case-historical-mine', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $decoded['candidate_count']);
        $this->assertSame([], $decoded['candidates']);
    }
}
