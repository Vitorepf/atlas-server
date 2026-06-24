<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopReceiptReplayException;
use App\Services\Ai\AutonomousEvolution\Recovery\AtlasLoopReceiptReplayer;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use Tests\TestCase;

final class AtlasLoopReceiptReplayerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir().'/atlas-loop-replay-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o755, true);
        $this->path = $dir.'/ledger.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    public function test_replay_reconstructs_state_in_strict_timestamp_order(): void
    {
        $this->writeLedger([
            $this->event(['proposal_id' => 'proposal-1', 'timestamp' => 30, 'event' => 'finalized', 'changes' => ['status' => 'merged']]),
            $this->event(['attempt_id' => 'attempt-1', 'timestamp' => 10, 'event' => 'started', 'changes' => ['status' => 'queued']]),
            $this->event(['proposal_id' => 'proposal-1', 'timestamp' => 20, 'event' => 'created', 'changes' => ['status' => 'draft']]),
        ]);

        $result = (new AtlasLoopReceiptReplayer)->replay($this->path);

        $this->assertSame(
            [
                'attempt-1' => ['status' => 'queued', 'entity_id' => 'attempt-1', 'event' => 'started', 'timestamp' => 10],
                'proposal-1' => ['status' => 'merged', 'entity_id' => 'proposal-1', 'event' => 'finalized', 'timestamp' => 30],
            ],
            $result['state'],
        );
        $this->assertSame([10, 20, 30], array_column($result['audit_trail'], 'timestamp'));
    }

    public function test_tampered_receipt_throws_with_offending_line_number(): void
    {
        $events = [
            $this->event(['attempt_id' => 'attempt-1', 'timestamp' => 10, 'event' => 'started', 'changes' => ['status' => 'queued']]),
            $this->event(['attempt_id' => 'attempt-1', 'timestamp' => 20, 'event' => 'finished', 'changes' => ['status' => 'done']]),
        ];
        $this->writeLedger($events);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $row = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
        $row['event'] = 'tampered';
        $lines[1] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($this->path, implode(PHP_EOL, $lines).PHP_EOL);

        $this->expectException(AtlasLoopReceiptReplayException::class);
        $this->expectExceptionMessage('line 2');

        (new AtlasLoopReceiptReplayer)->replay($this->path);
    }

    public function test_replay_up_to_timestamp_matches_full_replay_truncated_at_same_boundary(): void
    {
        $this->writeLedger([
            $this->event(['attempt_id' => 'attempt-1', 'timestamp' => 10, 'event' => 'started', 'changes' => ['status' => 'queued']]),
            $this->event(['attempt_id' => 'attempt-1', 'timestamp' => 20, 'event' => 'running', 'changes' => ['status' => 'running']]),
            $this->event(['attempt_id' => 'attempt-1', 'timestamp' => 30, 'event' => 'finished', 'changes' => ['status' => 'done']]),
        ]);

        $full = (new AtlasLoopReceiptReplayer)->replay($this->path);
        $truncated = (new AtlasLoopReceiptReplayer)->replay($this->path, 20);

        $expected = array_values(array_filter(
            $full['audit_trail'],
            static fn (array $row): bool => $row['timestamp'] <= 20,
        ));

        $this->assertSame(
            ['attempt-1' => ['status' => 'running', 'entity_id' => 'attempt-1', 'event' => 'running', 'timestamp' => 20]],
            $truncated['state'],
        );
        $this->assertSame($expected, $truncated['audit_trail']);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function writeLedger(array $events): void
    {
        $lines = array_map(
            static fn (array $event): string => json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $events,
        );
        file_put_contents($this->path, implode(PHP_EOL, $lines).PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function event(array $payload): array
    {
        $event = $payload;
        $event['receipt_hash'] = hash('sha256', CanonicalJson::encode($payload));

        return $event;
    }
}
