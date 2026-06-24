<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasLoopV2AuditJournalTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_append_three_events_and_verify_chain(): void
    {
        $journal = $this->journal();

        $first = $journal->append('created', ['id' => 1]);
        $second = $journal->append('decided', ['id' => 2]);
        $third = $journal->append('promoted', ['id' => 3]);

        $this->assertSame(AtlasLoopV2AuditJournal::GENESIS_HASH, $first['prev_hash']);
        $this->assertSame($first['line_hash'], $second['prev_hash']);
        $this->assertSame($second['line_hash'], $third['prev_hash']);
        $this->assertSame(['ts', 'event_type', 'payload', 'prev_hash', 'line_hash'], array_keys($first));
        $this->assertSame(['ok' => true, 'broken_at_line' => null, 'total_lines' => 3], $journal->verify());
    }

    public function test_tail_returns_last_events_in_order_and_zero_returns_empty(): void
    {
        $journal = $this->journal();
        $journal->append('first', ['n' => 1]);
        $journal->append('second', ['n' => 2]);
        $journal->append('third', ['n' => 3]);

        $tail = $journal->tail(2);

        $this->assertSame([], $journal->tail(0));
        $this->assertSame(['second', 'third'], array_column($tail, 'event_type'));
        $this->assertCount(3, $journal->tail(99));
    }

    public function test_verify_detects_middle_line_payload_corruption(): void
    {
        $path = $this->tmpPath();
        $journal = $this->journal($path);
        $journal->append('first', ['n' => 1]);
        $journal->append('second', ['n' => 2]);
        $journal->append('third', ['n' => 3]);

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $middle = json_decode((string) $lines[1], true, flags: JSON_THROW_ON_ERROR);
        $middle['payload']['n'] = 200;
        $lines[1] = json_encode($middle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, implode("\n", $lines)."\n");

        $this->assertSame(['ok' => false, 'broken_at_line' => 2, 'total_lines' => 3], $journal->verify());
    }

    public function test_payload_key_order_does_not_change_line_hash_when_inputs_match(): void
    {
        $clock = static fn (): string => '2026-06-24T16:50:00+00:00';
        $a = $this->journal(null, $clock)->append('ordered', ['b' => 2, 'a' => ['d' => 4, 'c' => 3]]);
        $b = $this->journal(null, $clock)->append('ordered', ['a' => ['c' => 3, 'd' => 4], 'b' => 2]);

        $this->assertSame($a['line_hash'], $b['line_hash']);
        $this->assertSame(['a' => ['c' => 3, 'd' => 4], 'b' => 2], $a['payload']);
    }

    public function test_empty_event_type_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->journal()->append('', ['valid' => true]);
    }

    public function test_append_uses_exclusive_flock(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/V2/AtlasLoopV2AuditJournal.php'));

        $this->assertStringContainsString('flock($handle, LOCK_EX)', $source);
        $this->assertStringContainsString('fflush($handle)', $source);
        $this->assertStringContainsString('fclose($handle)', $source);
    }

    /**
     * @param  null|callable():string  $clock
     */
    private function journal(?string $path = null, ?callable $clock = null): AtlasLoopV2AuditJournal
    {
        return new AtlasLoopV2AuditJournal($path ?? $this->tmpPath(), $clock);
    }

    private function tmpPath(): string
    {
        $path = sys_get_temp_dir().'/atlas-loop-v2-audit-journal-'.uniqid('', true).'.ndjson';
        $this->paths[] = $path;

        return $path;
    }
}
