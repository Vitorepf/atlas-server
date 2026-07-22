<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopResilienceReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the resilience receipt ledger: append-only with deterministic event_id (duplicate writes coalesce),
 * canonical-key-sorted output, query filters by campaign + time window, schema_version stamped on every entry,
 * and the source uses no network/shell calls — pure file I/O.
 */
final class AtlasLoopResilienceReceiptLedgerTest extends TestCase
{
    private string $root;

    private int $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas_resilience_ledger_'.bin2hex(random_bytes(8));
        $this->clock = (int) strtotime('2026-06-24T12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*.jsonl') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function ledger(): AtlasLoopResilienceReceiptLedger
    {
        $clock = $this->clock;

        return new AtlasLoopResilienceReceiptLedger($this->root, fn (): int => $clock);
    }

    public function test_appends_one_line_per_logical_event_and_no_op_on_duplicates(): void
    {
        $ledger = $this->ledger();
        $event = ['campaign_id' => 'c1', 'kind' => 'hung_grind', 'pid' => 123, 'recorded_at' => $this->clock];

        $id1 = $ledger->append($event);
        $id2 = $ledger->append($event); // identical body ⇒ no-op

        $this->assertSame($id1, $id2, 'identical body yields identical event_id');

        $path = $this->root.'/2026-06-24.jsonl';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines, 'duplicate logical event MUST persist exactly one line');
    }

    public function test_persisted_entry_carries_schema_version_and_event_id(): void
    {
        $ledger = $this->ledger();
        $id = $ledger->append(['campaign_id' => 'c1', 'kind' => 'respawn_admitted', 'recorded_at' => $this->clock]);

        $line = (array) json_decode((string) file($this->root.'/2026-06-24.jsonl')[0], true);
        $this->assertSame(AtlasLoopResilienceReceiptLedger::SCHEMA_VERSION, $line['schema_version']);
        $this->assertSame($id, $line['event_id']);
    }

    public function test_query_filters_by_campaign_and_time_window_chronological(): void
    {
        $ledger = $this->ledger();
        $ledger->append(['campaign_id' => 'c1', 'kind' => 'a', 'recorded_at' => $this->clock - 100]);
        $ledger->append(['campaign_id' => 'c1', 'kind' => 'b', 'recorded_at' => $this->clock + 100]);
        $ledger->append(['campaign_id' => 'c2', 'kind' => 'c', 'recorded_at' => $this->clock + 50]);

        $c1All = $ledger->query('c1');
        $this->assertCount(2, $c1All, 'campaign filter excludes c2');
        $this->assertSame('a', $c1All[0]['kind']);
        $this->assertSame('b', $c1All[1]['kind'], 'chronological order: earliest first');

        $window = $ledger->query(null, $this->clock, $this->clock + 200);
        $this->assertCount(2, $window, 'time window excludes the t-100 event');
    }

    public function test_query_returns_canonical_key_sorted_entries(): void
    {
        $ledger = $this->ledger();
        $ledger->append([
            'z_marker' => 'last',
            'campaign_id' => 'c1',
            'a_marker' => 'first',
            'recorded_at' => $this->clock,
        ]);

        $events = $ledger->query('c1');
        $keys = array_keys($events[0]);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'query output keys are canonical-sorted');
    }

    public function test_only_writes_to_resilience_ledger_directory_and_no_network_or_shell_calls(): void
    {
        $reflection = new ReflectionClass(AtlasLoopResilienceReceiptLedger::class);
        $source = (string) file_get_contents($reflection->getFileName());

        // No process spawn / network use anywhere in the source.
        foreach (['shell_exec', 'exec(', 'proc_open', 'popen(', 'passthru', 'system(', 'curl_exec', 'Http::', '\\Http\\', 'Hermes', 'fsockopen', 'fopen(\'http', 'file_get_contents(\'http'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "ledger must NOT use $banned");
        }
        // The only persistent storage path mentioned is the resilience-ledger directory.
        $this->assertStringContainsString('atlas-loop/resilience-ledger', $source, 'ledger writes only under the resilience-ledger path');
    }

    public function test_different_event_bodies_produce_different_event_ids(): void
    {
        $ledger = $this->ledger();
        $a = $ledger->append(['campaign_id' => 'c1', 'kind' => 'reap_applied', 'recorded_at' => $this->clock]);
        $b = $ledger->append(['campaign_id' => 'c1', 'kind' => 'reap_skipped', 'recorded_at' => $this->clock]);

        $this->assertNotSame($a, $b, 'different bodies must hash differently');
        $lines = file($this->root.'/2026-06-24.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);
    }
}
