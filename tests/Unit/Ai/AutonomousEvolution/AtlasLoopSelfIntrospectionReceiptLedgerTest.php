<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfIntrospectionReceiptLedger;
use Carbon\Carbon;
use RuntimeException;
use Tests\TestCase;

final class AtlasLoopSelfIntrospectionReceiptLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-introspection-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
        AtlasLoopSelfIntrospectionReceiptLedger::setRootForTesting($this->root);
    }

    protected function tearDown(): void
    {
        AtlasLoopSelfIntrospectionReceiptLedger::setRootForTesting(null);
        Carbon::setTestNow();
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }

    public function test_payload_sha_is_deterministic_and_second_record_is_idempotent(): void
    {
        Carbon::setTestNow('2026-06-25T12:00:00Z');
        $ledger = new AtlasLoopSelfIntrospectionReceiptLedger();
        $payload = ['inventory' => ['a' => 1, 'b' => 2], 'orphans' => ['x']];
        $sha = $ledger->payloadSha($payload);

        $first = $ledger->record('scanner', $payload, ['inventory_count' => 2], 'abc123');
        $this->assertSame($sha, $first['payload_sha256']);

        $second = $ledger->record('scanner', $payload, ['inventory_count' => 2], 'abc123');
        $this->assertSame($first['payload_sha256'], $second['payload_sha256']);
        $this->assertSame($first['taken_at_utc'], $second['taken_at_utc']);
    }

    public function test_append_only_violation_when_disk_payload_sha_mismatches_attempted_payload(): void
    {
        Carbon::setTestNow('2026-06-25T12:00:00Z');
        $ledger = new AtlasLoopSelfIntrospectionReceiptLedger();
        $payload = ['a' => 1];
        $sha = $ledger->payloadSha($payload);
        $path = $this->root.'/'.Carbon::now('UTC')->format('Y').'/'.Carbon::now('UTC')->format('m').'/'.$sha.'.json';
        @mkdir(\dirname($path), 0o755, true);
        // Plant a tampered file at the payload's path with a mismatched payload_sha256 marker —
        // simulating a corrupted or rogue earlier write.
        file_put_contents($path, json_encode(['payload_sha256' => 'mismatched-sha', 'tampered' => true]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.AtlasLoopSelfIntrospectionReceiptLedger::APPEND_ONLY_VIOLATION.'/');
        $ledger->record('scanner', $payload, [], 'git-sha');
    }

    public function test_list_filters_by_since_with_test_now_injection(): void
    {
        $ledger = new AtlasLoopSelfIntrospectionReceiptLedger();

        Carbon::setTestNow('2026-06-20T12:00:00Z');
        $ledger->record('scanner', ['x' => 1], [], 'g1');
        Carbon::setTestNow('2026-06-23T12:00:00Z');
        $ledger->record('scanner', ['x' => 2], [], 'g2');
        Carbon::setTestNow('2026-06-25T12:00:00Z');
        $ledger->record('scanner', ['x' => 3], [], 'g3');

        $since = Carbon::parse('2026-06-22T00:00:00Z');
        $rows = $ledger->list($since);
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-06-23T12:00:00Z', '2026-06-25T12:00:00Z'], array_column($rows, 'taken_at_utc'));
    }

    public function test_receipt_round_trips_byte_identically_through_canonical_json(): void
    {
        Carbon::setTestNow('2026-06-25T12:00:00Z');
        $ledger = new AtlasLoopSelfIntrospectionReceiptLedger();
        $receipt = $ledger->record('scanner', ['k' => 'v'], ['summary' => 'fine'], 'sha-X');
        $path = '';
        foreach ((array) glob($this->root.'/*/*/*.json') as $candidate) {
            if (is_string($candidate)) {
                $path = $candidate;
                break;
            }
        }
        $this->assertNotSame('', $path);
        $onDisk = json_decode((string) file_get_contents($path), true);

        foreach (['taken_at_utc', 'reporter', 'atlas_git_sha', 'payload_sha256', 'payload_summary'] as $field) {
            $this->assertArrayHasKey($field, $onDisk, "receipt JSON missing field {$field}");
        }
        $this->assertSame($receipt['payload_sha256'], $onDisk['payload_sha256']);
        $this->assertSame($receipt['reporter'], $onDisk['reporter']);
    }

    public function test_latest_returns_most_recent_for_a_given_reporter(): void
    {
        $ledger = new AtlasLoopSelfIntrospectionReceiptLedger();
        Carbon::setTestNow('2026-06-20T12:00:00Z');
        $ledger->record('scanner', ['a' => 1], [], 'g1');
        Carbon::setTestNow('2026-06-21T12:00:00Z');
        $ledger->record('dep_graph', ['b' => 1], [], 'g2');
        Carbon::setTestNow('2026-06-22T12:00:00Z');
        $ledger->record('scanner', ['a' => 2], [], 'g3');

        $latest = $ledger->latest('scanner');
        $this->assertNotNull($latest);
        $this->assertSame('2026-06-22T12:00:00Z', $latest['taken_at_utc']);
    }
}
