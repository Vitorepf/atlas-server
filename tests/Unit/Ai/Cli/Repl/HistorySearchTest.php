<?php

namespace Tests\Unit\Ai\Cli\Repl;

use App\Services\Ai\Cli\Repl\HistorySearch;
use Tests\TestCase;

class HistorySearchTest extends TestCase
{
    public function test_finds_most_recent_match_first(): void
    {
        $search = new HistorySearch([
            'fix bug auth',
            'review pr 42',
            'add tests',
            'fix lint',
        ]);

        $search->appendQueryChar('f');
        $search->appendQueryChar('i');
        $search->appendQueryChar('x');

        $this->assertSame('fix lint', $search->currentMatch());
    }

    public function test_find_next_returns_older_match(): void
    {
        $search = new HistorySearch([
            'fix bug',
            'review',
            'fix lint',
        ]);

        $search->appendQueryChar('f');
        $search->appendQueryChar('i');
        $search->appendQueryChar('x');
        $this->assertSame('fix lint', $search->currentMatch());

        $search->findNext();
        $this->assertSame('fix bug', $search->currentMatch());
    }

    public function test_no_match_keeps_query_visible(): void
    {
        $search = new HistorySearch(['hello world']);

        $search->appendQueryChar('z');
        $search->appendQueryChar('z');

        $this->assertNull($search->currentMatch());
        $this->assertStringContainsString("failing", $search->statusLine());
        $this->assertStringContainsString('zz', $search->statusLine());
    }

    public function test_delete_query_char_recomputes_match(): void
    {
        $search = new HistorySearch(['fix bug', 'fix lint']);

        $search->appendQueryChar('f');
        $search->appendQueryChar('i');
        $search->appendQueryChar('x');
        $search->appendQueryChar('z');
        $this->assertNull($search->currentMatch());

        $search->deleteQueryChar();
        $this->assertSame('fix lint', $search->currentMatch());
    }

    public function test_status_line_shape_when_match(): void
    {
        $search = new HistorySearch(['fix bug auth']);
        $search->appendQueryChar('a');

        $line = $search->statusLine();

        $this->assertStringStartsWith('(reverse-i-search)', $line);
        $this->assertStringContainsString('fix bug auth', $line);
    }

    public function test_empty_history_yields_no_match(): void
    {
        $search = new HistorySearch([]);
        $search->appendQueryChar('a');

        $this->assertNull($search->currentMatch());
    }
}
