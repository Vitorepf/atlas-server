<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopFactConfidenceBoundsCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopFactConfidenceBoundsCommandTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-fact-bounds-'.bin2hex(random_bytes(6)).'.jsonl';
        $this->app->instance(AtlasLoopFactConfidenceBoundsCommand::LEDGER_PATH_BINDING, $this->ledgerPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     */
    private function bindSnapshot(array $facts): void
    {
        $this->app->instance(
            AtlasLoopFactConfidenceBoundsCommand::SNAPSHOT_SOURCE_BINDING,
            static fn (): array => $facts,
        );
    }

    public function test_inspect_emits_legacy_and_bounded_facts_with_required_fields(): void
    {
        $this->bindSnapshot([
            [
                'fact_key' => 'fact.legacy',
                'value' => 0.5,
                'sample_size' => 1,
                'source_count' => 1,
                'emission_path' => 'fact.legacy',
            ],
            [
                'fact_key' => 'fact.bounded',
                'value' => 0.9,
                'sample_size' => 12,
                'source_count' => 3,
                'emission_path' => 'fact.bounded',
                'confidence_bounds' => ['lower' => 0.8, 'upper' => 0.95],
            ],
        ]);

        $exit = Artisan::call('atlas:loop:fact:bounds', ['action' => 'inspect', '--json' => true]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('inspect', $payload['action']);
        $this->assertCount(2, $payload['facts']);

        $byKey = array_column($payload['facts'], null, 'fact_key');
        $this->assertTrue($byKey['fact.legacy']['legacy']);
        $this->assertSame(1, $byKey['fact.legacy']['sample_size']);
        $this->assertSame(1, $byKey['fact.legacy']['source_count']);
        $this->assertFalse($byKey['fact.bounded']['legacy']);
    }

    public function test_validate_returns_zero_when_no_critical_missing_and_one_when_enforce_active_with_missing(): void
    {
        // OK branch: enforce off (default), validator returns silently.
        $this->bindSnapshot([
            ['fact_key' => 'fact.x', 'value' => 1, 'sample_size' => 5, 'source_count' => 2, 'emission_path' => 'fact.x'],
        ]);
        $okExit = Artisan::call('atlas:loop:fact:bounds', ['action' => 'validate', '--json' => true]);
        $this->assertSame(0, $okExit);

        // Failed branch: enforce on, critical path missing envelope ⇒ exit 1.
        config([
            'atlas.loop.fact_confidence' => [
                'enforce' => true,
                'critical_paths' => ['fact.critical'],
            ],
        ]);
        $this->bindSnapshot([
            ['fact_key' => 'fact.critical', 'value' => 0.7, 'sample_size' => 1, 'source_count' => 1, 'emission_path' => 'fact.critical'],
        ]);
        $failExit = Artisan::call('atlas:loop:fact:bounds', ['action' => 'validate', '--json' => true]);
        $this->assertSame(1, $failExit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('failed', $payload['outcome']);
        $this->assertNotEmpty($payload['violations']);
    }

    public function test_history_returns_at_most_limit_entries_filtered_by_fact_key_most_recent_first(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $row = ['fact_key' => 'fact.X', 'seq' => $i, 'value' => $i];
            file_put_contents($this->ledgerPath, json_encode($row).PHP_EOL, FILE_APPEND);
        }
        // Add a noise row for a different fact_key.
        file_put_contents($this->ledgerPath, json_encode(['fact_key' => 'fact.OTHER', 'seq' => 99]).PHP_EOL, FILE_APPEND);

        $exit = Artisan::call('atlas:loop:fact:bounds', [
            'action' => 'history',
            '--fact-key' => 'fact.X',
            '--limit' => 5,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertCount(5, $payload['rows']);
        $seqs = array_map(static fn (array $r): int => (int) $r['seq'], (array) $payload['rows']);
        $this->assertSame([7, 6, 5, 4, 3], $seqs, 'most-recent-first slice expected');
    }

    public function test_unknown_action_exits_two(): void
    {
        $exit = Artisan::call('atlas:loop:fact:bounds', ['action' => 'bogus', '--json' => true]);
        $this->assertSame(2, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('refused', $payload['outcome']);
    }
}
