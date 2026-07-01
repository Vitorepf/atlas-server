<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Concurrency;

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

    private function ledger(?callable $released = null, ?string $clockTime = '2026-06-24T12:00:00+00:00'): AtlasMaestroWorkerCheckpointLedger
    {
        $ledger = new AtlasMaestroWorkerCheckpointLedger($this->root, $released);
        if ($clockTime !== null) {
            $ledger->setClock(fn (): string => $clockTime);
        }

        return $ledger;
    }

    // ── AC1: resumeFrom returns the latest checkpoint only when payload_hash matches ──

    public function test_resume_from_returns_checkpoint_when_expected_payload_hash_matches(): void
    {
        $ledger = $this->ledger();
        $ledger->record('A', 'task-a1', 'hash-a1');

        $resume = $ledger->resumeFrom('A', 'hash-a1');

        self::assertNotNull($resume);
        self::assertSame('task-a1', $resume['task_packet_id']);
    }

    public function test_resume_from_returns_null_when_expected_payload_hash_mismatches(): void
    {
        $ledger = $this->ledger();
        $ledger->record('A', 'task-a1', 'hash-a1');

        self::assertNull($ledger->resumeFrom('A', 'a-different-hash'));
    }

    public function test_resume_from_without_expected_hash_preserves_legacy_behavior(): void
    {
        $ledger = $this->ledger();
        $ledger->record('A', 'task-a1', 'hash-a1');

        $resume = $ledger->resumeFrom('A');

        self::assertNotNull($resume);
        self::assertSame('task-a1', $resume['task_packet_id']);
        self::assertArrayNotHasKey('stale', $resume);
        self::assertArrayNotHasKey('age_seconds', $resume);
    }

    // ── AC2: stale checkpoints are marked stale with age_seconds ──

    public function test_resume_from_marks_stale_checkpoint_with_age_seconds(): void
    {
        $ledger = $this->ledger(null, '2026-06-24T12:00:00+00:00');
        $ledger->record('A', 'task-a1', 'hash-a1');

        // Advance the clock past the stale threshold.
        $ledger->setClock(fn (): string => '2026-06-24T13:00:00+00:00');

        $resume = $ledger->resumeFrom('A', null, 1800);

        self::assertNotNull($resume);
        self::assertTrue($resume['stale']);
        self::assertSame(3600, $resume['age_seconds']);
    }

    public function test_resume_from_marks_fresh_checkpoint_as_not_stale(): void
    {
        $ledger = $this->ledger(null, '2026-06-24T12:00:00+00:00');
        $ledger->record('A', 'task-a1', 'hash-a1');

        $ledger->setClock(fn (): string => '2026-06-24T12:00:10+00:00');

        $resume = $ledger->resumeFrom('A', null, 1800);

        self::assertNotNull($resume);
        self::assertFalse($resume['stale']);
        self::assertSame(10, $resume['age_seconds']);
    }

    // ── AC3: checkpoint records omit raw task objective or provider-sensitive payloads ──

    public function test_record_never_persists_unwhitelisted_meta_keys(): void
    {
        $ledger = $this->ledger();
        $ledger->record('A', 'task-a1', 'hash-a1', [
            'status' => 'success',
            'objective' => 'This is the full raw sensitive task objective text.',
            'api_key' => 'sk-super-secret-provider-key',
            'raw_payload' => 'entire provider request/response body',
        ]);

        $row = $ledger->latest('A');
        $encoded = (string) json_encode($row);

        self::assertStringNotContainsString('raw sensitive task objective', $encoded);
        self::assertStringNotContainsString('sk-super-secret-provider-key', $encoded);
        self::assertStringNotContainsString('entire provider request/response body', $encoded);
        self::assertArrayNotHasKey('objective', $row['meta'] ?? []);
        self::assertArrayNotHasKey('api_key', $row['meta'] ?? []);
        self::assertArrayNotHasKey('raw_payload', $row['meta'] ?? []);
        self::assertSame('success', $row['meta']['status']);
    }

    // ── Existing legacy contract sanity ──

    public function test_resume_from_still_skips_released_tasks_with_new_optional_args(): void
    {
        $released = ['A' => ['task-a2']];
        $ledger = $this->ledger(fn (string $client) => $released[$client] ?? []);

        $ledger->record('A', 'task-a1', 'h1');
        $ledger->record('A', 'task-a2', 'h2');

        $resume = $ledger->resumeFrom('A', null, 999999);

        self::assertNotNull($resume);
        self::assertSame('task-a1', $resume['task_packet_id']);
    }
}
