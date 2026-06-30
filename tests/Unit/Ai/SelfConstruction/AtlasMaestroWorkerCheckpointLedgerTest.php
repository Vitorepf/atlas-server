<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Concurrency\AtlasMaestroWorkerCheckpointLedger;
use Tests\TestCase;

final class AtlasMaestroWorkerCheckpointLedgerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-checkpoints-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function ledger(?callable $released = null): AtlasMaestroWorkerCheckpointLedger
    {
        $ledger = new AtlasMaestroWorkerCheckpointLedger($this->root, $released);
        $ledger->setClock(fn (): string => '2026-06-24T12:00:00+00:00');

        return $ledger;
    }

    public function test_latest_returns_the_highest_sequence_per_client(): void
    {
        $ledger = $this->ledger();
        $ledger->record('A', 'task-a1', 'hash-a1');
        $ledger->record('A', 'task-a2', 'hash-a2');
        $ledger->record('A', 'task-a3', 'hash-a3');
        $ledger->record('B', 'task-b1', 'hash-b1');
        $ledger->record('B', 'task-b2', 'hash-b2');

        $latestA = $ledger->latest('A');
        $latestB = $ledger->latest('B');
        $latestC = $ledger->latest('C');

        $this->assertSame(3, $latestA['sequence']);
        $this->assertSame('task-a3', $latestA['task_packet_id']);
        $this->assertSame(2, $latestB['sequence']);
        $this->assertSame('task-b2', $latestB['task_packet_id']);
        $this->assertNull($latestC);
    }

    public function test_record_is_append_only_never_rewrites_prior_bytes(): void
    {
        $ledger = $this->ledger();
        $path = $ledger->path('A');

        $ledger->record('A', 'task-a1', 'h1');
        $bytes1 = (string) file_get_contents($path);

        $ledger->record('A', 'task-a2', 'h2');
        $bytes2 = (string) file_get_contents($path);
        $this->assertStringStartsWith($bytes1, $bytes2, 'second record() must START with the previous full content');
        $this->assertSame(1, substr_count(substr($bytes2, strlen($bytes1)), "\n"), 'exactly one trailing line was appended');

        $ledger->record('A', 'task-a3', 'h3');
        $bytes3 = (string) file_get_contents($path);
        $this->assertStringStartsWith($bytes2, $bytes3);
        $this->assertSame(1, substr_count(substr($bytes3, strlen($bytes2)), "\n"));
    }

    public function test_resume_from_skips_a_released_task_and_returns_the_prior_open_checkpoint(): void
    {
        $released = ['A' => ['task-a3']];
        $ledger = $this->ledger(fn (string $client) => $released[$client] ?? []);

        $ledger->record('A', 'task-a1', 'h1');
        $ledger->record('A', 'task-a2', 'h2');
        $ledger->record('A', 'task-a3', 'h3'); // newest, but RELEASED ⇒ resume must skip it

        $resume = $ledger->resumeFrom('A');

        $this->assertNotNull($resume);
        $this->assertSame('task-a2', $resume['task_packet_id'], 'resume must return the prior STILL-OPEN checkpoint');
        $this->assertSame(2, $resume['sequence']);
    }

    public function test_resume_from_returns_latest_when_no_tasks_are_released(): void
    {
        $ledger = $this->ledger();
        $ledger->record('A', 'task-a1', 'h1');
        $ledger->record('A', 'task-a2', 'h2');

        $this->assertSame('task-a2', $ledger->resumeFrom('A')['task_packet_id']);
    }

    public function test_resume_from_returns_null_when_all_checkpoints_are_released(): void
    {
        $ledger = $this->ledger(fn (): array => ['task-a1', 'task-a2']);
        $ledger->record('A', 'task-a1', 'h1');
        $ledger->record('A', 'task-a2', 'h2');

        $this->assertNull($ledger->resumeFrom('A'));
    }

    public function test_record_with_metadata_stores_normalized_scalar_fields(): void
    {
        $ledger = $this->ledger();
        $ledger->record('W', 'task-w1', 'h1', [
            'status' => 'success',
            'give_back_reason' => '',
            'test_command_hash' => 'abc123',
            'committed_hash' => 'def456',
            'task_family' => 'smoke',
            'unknown_key' => 'ignored',
        ]);

        $row = $ledger->latest('W');
        $this->assertIsArray($row['meta'] ?? null);
        $meta = $row['meta'];
        $this->assertSame('success', $meta['status']);
        $this->assertSame('abc123', $meta['test_command_hash']);
        $this->assertSame('def456', $meta['committed_hash']);
        $this->assertSame('smoke', $meta['task_family']);
        $this->assertArrayNotHasKey('unknown_key', $meta);
    }

    public function test_record_without_metadata_omits_meta_key_for_old_rows(): void
    {
        $ledger = $this->ledger();
        $ledger->record('V', 'task-v1', 'h1');

        $row = $ledger->latest('V');
        $this->assertArrayNotHasKey('meta', $row, 'rows without metadata must not carry a meta key');
    }

    public function test_latest_and_resume_from_tolerate_rows_without_meta(): void
    {
        $ledger = $this->ledger();
        $ledger->record('M', 'task-m1', 'h1');
        $ledger->record('M', 'task-m2', 'h2', ['status' => 'give_back', 'give_back_reason' => 'impossible', 'test_command_hash' => '', 'committed_hash' => '', 'task_family' => '']);

        $latest = $ledger->latest('M');
        $this->assertSame('task-m2', $latest['task_packet_id']);
        $this->assertSame('give_back', $latest['meta']['status']);

        $resume = $ledger->resumeFrom('M');
        $this->assertNotNull($resume);
    }
}
