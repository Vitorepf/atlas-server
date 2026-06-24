<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Quaternity;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentStreamReader;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\IntentParseError;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentMessage;
use Tests\TestCase;

final class AtlasLoopOperatorIntentStreamReaderTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/atlas-intent-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_two_reads_of_the_same_file_are_byte_identical(): void
    {
        $this->writeLines([
            '{"ts":100,"raw_text":"first goal","source":"goal"}',
            '', // blank line is skipped, not a message
            '{"ts":200,"raw_text":"a chat","source":"chat"}',
            '{"ts":300,"raw_text":"cli ping","source":"cli"}',
        ]);

        $reader = new AtlasLoopOperatorIntentStreamReader($this->file);
        $first = $reader->read();
        $second = $reader->read();

        $this->assertCount(3, $first);
        $this->assertContainsOnlyInstancesOf(OperatorIntentMessage::class, $first);
        $ids = static fn (array $m): array => array_map(static fn (OperatorIntentMessage $x): string => $x->id, $m);
        $this->assertSame($ids($first), $ids($second), 'ids must be stable across reads');

        // ids are the sha256 of the raw line.
        $this->assertSame(hash('sha256', '{"ts":100,"raw_text":"first goal","source":"goal"}'), $first[0]->id);
        $this->assertSame(100, $first[0]->ts);
        $this->assertSame('operator', $first[0]->author);
        $this->assertSame('first goal', $first[0]->rawText);
        $this->assertSame('goal', $first[0]->source);
    }

    public function test_malformed_line_surfaces_as_parse_error_without_corrupting_later_lines(): void
    {
        $this->writeLines([
            '{"ts":1,"raw_text":"ok before","source":"chat"}', // line 1 — valid
            '{ this is not json',                                // line 2 — malformed
            '{"ts":3,"raw_text":"ok after","source":"goal"}',   // line 3 — valid, must still parse
        ]);

        $out = (new AtlasLoopOperatorIntentStreamReader($this->file))->tailSince(0);

        $this->assertCount(2, $out['messages'], 'valid lines before AND after the malformed line are parsed');
        $this->assertSame('ok before', $out['messages'][0]->rawText);
        $this->assertSame('ok after', $out['messages'][1]->rawText);

        $this->assertCount(1, $out['errors']);
        $this->assertInstanceOf(IntentParseError::class, $out['errors'][0]);
        $this->assertSame(2, $out['errors'][0]->lineNumber, 'the exact malformed line number is reported');
    }

    public function test_tail_since_returns_only_lines_after_the_offset_and_advances_next_offset(): void
    {
        $line1 = '{"ts":1,"raw_text":"first","source":"chat"}';
        $line2 = '{"ts":2,"raw_text":"second","source":"chat"}';
        $this->writeLines([$line1, $line2]);

        $reader = new AtlasLoopOperatorIntentStreamReader($this->file);

        // From the byte offset just after line 1, only line 2 is returned.
        $afterLine1 = strlen($line1) + 1; // + "\n"
        $tail = $reader->tailSince($afterLine1);
        $this->assertCount(1, $tail['messages']);
        $this->assertSame('second', $tail['messages'][0]->rawText);

        // A full read advances next_offset to EOF; tailing from there yields nothing new.
        $full = $reader->tailSince(0);
        $this->assertCount(2, $full['messages']);
        $this->assertSame(filesize($this->file), $full['next_offset']);

        $empty = $reader->tailSince($full['next_offset']);
        $this->assertSame([], $empty['messages']);
        $this->assertSame($full['next_offset'], $empty['next_offset']);
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLines(array $lines): void
    {
        file_put_contents($this->file, implode("\n", $lines)."\n");
    }
}
