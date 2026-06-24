<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringIntentLedger;
use Tests\TestCase;

/**
 * Proves the Wiring-Intent Ledger: flag-gated (OFF ⇒ null/[]/no file), append-only (prior lines never mutated),
 * round-trips in insertion order, and fail-closed (unwritable target ⇒ null, no throw).
 */
final class AtlasLoopWiringIntentLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-wiring-ledger-'.bin2hex(random_bytes(6)).'/ledger.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    private function ledger(): AtlasLoopWiringIntentLedger
    {
        return new AtlasLoopWiringIntentLedger($this->path);
    }

    /** @return array<string,mixed> */
    private function intent(string $consumer, string $status): array
    {
        return [
            'primitive_id' => 'atlas_loop_leverage_selector',
            'consumer_path' => $consumer,
            'seam_anchor' => '__construct',
            'status' => $status,
            'snapshot_sha' => 'abc123',
        ];
    }

    public function test_record_and_read_round_trip_in_insertion_order(): void
    {
        config(['atlas.loop.wiring_intent_ledger_enabled' => true]);

        $this->assertNotNull($this->ledger()->record($this->intent('app/A.php', 'wired')));
        $this->assertNotNull($this->ledger()->record($this->intent('app/B.php', 'intended')));

        $rows = $this->ledger()->read();
        $this->assertCount(2, $rows);
        $this->assertSame('app/A.php', $rows[0]['consumer_path']);
        $this->assertSame('app/B.php', $rows[1]['consumer_path']);
        $this->assertSame('wired', $rows[0]['status']);
        $this->assertSame('abc123', $rows[0]['snapshot_sha']);
        $this->assertSame('atlas.loop.wiring_intent_ledger.v1', $rows[0]['schema']);
    }

    public function test_flag_off_is_a_no_op_and_never_creates_the_file(): void
    {
        config(['atlas.loop.wiring_intent_ledger_enabled' => false]);

        $this->assertNull($this->ledger()->record($this->intent('app/A.php', 'wired')));
        $this->assertSame([], $this->ledger()->read());
        $this->assertFileDoesNotExist($this->path, 'OFF ⇒ the ledger file is never created');
    }

    public function test_append_only_first_line_is_byte_identical_after_second_write(): void
    {
        config(['atlas.loop.wiring_intent_ledger_enabled' => true]);

        $this->ledger()->record($this->intent('app/A.php', 'wired'));
        $firstLineBefore = explode("\n", trim((string) file_get_contents($this->path)))[0];

        $this->ledger()->record($this->intent('app/B.php', 'intended'));
        $lines = explode("\n", trim((string) file_get_contents($this->path)));

        $this->assertCount(2, $lines, 'two records ⇒ two lines');
        $this->assertSame($firstLineBefore, $lines[0], 'append-only: the first line is never mutated');
    }

    public function test_fail_closed_when_target_is_unwritable(): void
    {
        config(['atlas.loop.wiring_intent_ledger_enabled' => true]);

        // Put a FILE where the ledger's parent directory should be ⇒ mkdir cannot create the dir.
        $blocker = sys_get_temp_dir().'/atlas-wiring-blocker-'.bin2hex(random_bytes(6));
        file_put_contents($blocker, 'x');
        try {
            $ledger = new AtlasLoopWiringIntentLedger($blocker.'/ledger.jsonl');
            $this->assertNull($ledger->record($this->intent('app/A.php', 'wired')), 'unwritable target ⇒ null, no throw');
        } finally {
            @unlink($blocker);
        }
    }
}
