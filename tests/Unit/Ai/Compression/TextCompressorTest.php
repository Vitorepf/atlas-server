<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Compressors\TextCompressor;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for {@see TextCompressor} (AP-813). No DB / container / I/O — the
 * compressor is a deterministic string transform, so this extends the bare PHPUnit
 * TestCase. Proves the near-lossless contract: only mechanical byte-redundancy is
 * removed, every unique line survives verbatim, and it never grows a block.
 */
final class TextCompressorTest extends TestCase
{
    private function make(): TextCompressor
    {
        return new TextCompressor();
    }

    public function test_content_type_is_text(): void
    {
        $this->assertSame('text', $this->make()->contentType());
    }

    public function test_detect_is_always_true_as_the_conservative_fallback(): void
    {
        $c = $this->make();

        // Matching "prose" sample.
        $this->assertTrue($c->detect("hello world\nthis is some text"));
        // Clearly-non-matching (structured) sample — still true: text is the
        // catch-all consulted last, and every transform it makes is safe.
        $this->assertTrue($c->detect('{"a":1,"b":[2,3]}'));
        // Even the empty string detects (the router would still try it last).
        $this->assertTrue($c->detect(''));
    }

    public function test_compress_makes_a_large_redundant_block_strictly_smaller(): void
    {
        // 200 identical consecutive lines + a big run of blank lines: hugely
        // redundant, must collapse far below the original size.
        $line = 'the quick brown fox jumps over the lazy dog';
        $block = str_repeat($line . "\n", 200) . str_repeat("\n", 50) . 'tail line';

        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed, 'redundant block should compress');
        $this->assertLessThan(
            $result->originalChars,
            $result->compressedChars,
            'output must be strictly smaller'
        );
        // The 200-run collapses to one line + marker; the 50 blanks to one blank.
        $this->assertStringContainsString($line . ' [x200]', $result->output);
        $this->assertStringContainsString('tail line', $result->output);
        $this->assertSame(200 + 50 + 1, $result->meta['lines_total']);
        $this->assertLessThan($result->meta['lines_total'], $result->meta['lines_after']);
    }

    public function test_lossless_signal_property_every_error_and_unique_item_survives_verbatim(): void
    {
        // A homogeneous bulk of identical "OK" lines, but interleaved with UNIQUE
        // errors / anomalies / outliers that must ALL survive verbatim and in order.
        $signals = [
            'ERROR: database connection refused at 10.0.0.5',
            'WARN: retry budget exhausted for shard 7',
            'FATAL: out of memory while indexing batch #4291',
            'anomaly: latency spike 12384ms on /checkout',
            'outlier: balance -0.00000001 BTC after settle',
            'unique-trace-id: 019ea671-005b-72a7-9977-a19159e181ae',
        ];

        $parts = [];
        foreach ($signals as $i => $sig) {
            // Bulk redundant homogeneous noise around each signal.
            $parts[] = str_repeat("OK healthy heartbeat tick\n", 30);
            $parts[] = $sig . "\n";
        }
        $block = implode('', $parts);

        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed, 'the redundant OK bulk should collapse');

        // EVERY signal line must appear verbatim in the compressed output.
        foreach ($signals as $sig) {
            $this->assertStringContainsString(
                $sig,
                $result->output,
                "lost a unique signal line: {$sig}"
            );
        }

        // And the homogeneous bulk must actually have been collapsed (proving the
        // savings came from redundancy, not from dropping signal).
        $this->assertStringContainsString('OK healthy heartbeat tick [x30]', $result->output);
    }

    public function test_unique_non_duplicate_lines_are_never_touched(): void
    {
        // No duplicates, no over-long blank runs -> nothing to safely remove, so
        // the result must be unchanged (changed === false), byte-identical.
        $block = "alpha\nbeta\ngamma\ndelta\nepsilon";

        $result = $this->make()->compress($block);

        $this->assertFalse($result->changed);
        $this->assertSame($block, $result->output);
    }

    public function test_unique_lines_keep_their_trailing_whitespace_verbatim(): void
    {
        // CONTRACT: a unique (non-duplicated) line is NEVER rewritten — its trailing
        // whitespace is content the compressor must keep unconditionally, because it
        // can be signal (Markdown hard break, TSV empty field, protocol bytes). Each
        // of these three lines is unique, so the block is returned byte-identical.
        $block = "keep me   \nand me\t\t\nclean";
        $result = $this->make()->compress($block);

        $this->assertFalse($result->changed, 'unique lines must not be rewritten');
        $this->assertSame($block, $result->output);
    }

    public function test_indentation_and_midline_whitespace_is_preserved(): void
    {
        // Leading indent and inner spacing are always preserved; and because these
        // lines are unique, their TRAILING whitespace is preserved too (never
        // trimmed) — the whole block is byte-identical.
        $block = "    indented code line  \n        deeper  \nplain";
        $result = $this->make()->compress($block);

        $this->assertFalse($result->changed);
        $this->assertSame($block, $result->output);
    }

    public function test_markdown_hard_break_and_trailing_nuls_on_unique_lines_survive(): void
    {
        // The two regressions called out in the AP-813 review: a Markdown hard line
        // break (two trailing spaces => <br>) and a line ending in NUL bytes are
        // both SIGNAL on a unique line and must NOT be silently mutated.
        $hardBreak = "line one  \nline two"; // "line one" + 2 trailing spaces
        $nulBytes = "data\x00\x00\nmore";

        $rHard = $this->make()->compress($hardBreak);
        $rNul = $this->make()->compress($nulBytes);

        $this->assertFalse($rHard->changed, 'markdown <br> trailing spaces are signal');
        $this->assertSame($hardBreak, $rHard->output);

        $this->assertFalse($rNul->changed, 'trailing NUL bytes on a unique line are signal');
        $this->assertSame($nulBytes, $rNul->output);
    }

    public function test_collapses_three_or_more_blank_lines_into_one(): void
    {
        // 5 blank lines between two content lines -> exactly one blank line.
        $block = "head\n\n\n\n\n\ntail";
        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed);
        $this->assertSame("head\n\ntail", $result->output);
    }

    public function test_one_or_two_blank_lines_are_left_untouched(): void
    {
        // A single and a double blank gap are normal paragraph spacing: keep them.
        $singleGap = "a\n\nb";
        $doubleGap = "a\n\n\nb"; // two blank lines

        $rSingle = $this->make()->compress($singleGap);
        $rDouble = $this->make()->compress($doubleGap);

        // Single blank: nothing to remove -> unchanged.
        $this->assertFalse($rSingle->changed);
        $this->assertSame($singleGap, $rSingle->output);

        // Two blanks: still under the 3+ threshold -> unchanged.
        $this->assertFalse($rDouble->changed);
        $this->assertSame($doubleGap, $rDouble->output);
    }

    public function test_blank_lines_are_not_turned_into_duplicate_markers(): void
    {
        // A long blank run must never become "[xN]" — it collapses to one blank.
        $block = "x\n\n\n\n\n\n\n\n\n\ny";
        $result = $this->make()->compress($block);

        $this->assertStringNotContainsString('[x', $result->output);
        $this->assertSame("x\n\ny", $result->output);
    }

    public function test_only_consecutive_identical_lines_collapse_not_separated_ones(): void
    {
        // Same text appearing non-consecutively must stay as separate lines.
        $block = "dup\nother\ndup\nother\ndup";
        $result = $this->make()->compress($block);

        // No consecutive run -> nothing collapses -> unchanged.
        $this->assertFalse($result->changed);
        $this->assertSame($block, $result->output);
        $this->assertStringNotContainsString('[x', $result->output);
    }

    public function test_duplicate_run_count_is_preserved_in_marker(): void
    {
        $block = "boom\nboom\nboom\nboom\ndone";
        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed);
        // The count "4" is preserved -> the repetition fact is lossless.
        $this->assertSame("boom [x4]\ndone", $result->output);
    }

    public function test_lines_differing_only_by_trailing_whitespace_are_not_merged(): void
    {
        // CONTRACT (corrected in the AP-813 review): dedup compares EXACT bytes, so a
        // trailing-whitespace-only distinction is a real distinction and must survive
        // — these three lines are NOT collapsed into one "[x3]" marker, because that
        // would erase the (possibly significant) trailing-whitespace difference and
        // dishonestly claim three byte-identical lines. They are kept verbatim.
        $block = "same\nsame   \nsame\t\nnext";
        $result = $this->make()->compress($block);

        $this->assertFalse($result->changed, 'whitespace-distinct lines must not merge');
        $this->assertSame($block, $result->output);
        $this->assertStringNotContainsString('[x', $result->output);
    }

    public function test_trailing_whitespace_distinct_line_is_not_absorbed_into_a_run(): void
    {
        // The exact merge-bug from the review: 5 byte-identical lines followed by a
        // trailing-space variant and a clean variant. The whitespace-distinct line
        // must NOT be swallowed into the run, and the marker must count only the
        // genuinely byte-identical run (5), not an inflated 7.
        $block = str_repeat("amount: 100\n", 5) . "amount: 100 \n" . 'amount: 100';
        $result = $this->make()->compress($block);

        // The 5-run collapses honestly; the space-distinct line and the final clean
        // line are each preserved verbatim as their own lines.
        $this->assertSame("amount: 100 [x5]\namount: 100 \namount: 100", $result->output);
        $this->assertStringNotContainsString('[x7]', $result->output);
        $this->assertStringNotContainsString('[x6]', $result->output);
    }

    public function test_collapsed_run_representative_is_right_trimmed(): void
    {
        // When a run IS genuinely byte-identical (including identical trailing
        // whitespace), collapsing to one representative + marker is pure redundancy
        // removal, and the single representative is right-trimmed (the trailing bytes
        // were redundant across the whole run and are CCR-recoverable).
        $line = 'this row is long enough that collapsing it is a net win';
        $block = str_repeat($line . "  \n", 4) . 'tail';
        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed);
        $this->assertSame($line . " [x4]\ntail", $result->output);
    }

    public function test_marker_is_ambiguous_against_literal_marker_text_by_design(): void
    {
        // The "[xN]" marker is a literal suffix, not a reversible encoding: a literal
        // line that already ends in "[x2]", repeated, becomes "foo [x2] [x2]". This
        // is acceptable per the CCR-recovery contract (exact bytes come from the
        // store); the marker only has to make the run-count legible. Documented so
        // the behavior is an asserted choice, not an accident.
        $block = "foo [x2]\nfoo [x2]\nend";
        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed);
        $this->assertSame("foo [x2] [x2]\nend", $result->output);
    }

    public function test_empty_block_returns_unchanged(): void
    {
        $result = $this->make()->compress('');

        $this->assertFalse($result->changed);
        $this->assertSame('', $result->output);
        $this->assertSame('text', $result->contentType);
    }

    public function test_small_non_matching_block_returns_unchanged(): void
    {
        // A short ordinary string with no redundancy to remove.
        $block = 'just a single short line';
        $result = $this->make()->compress($block);

        $this->assertFalse($result->changed);
        $this->assertSame($block, $result->output);
    }

    public function test_short_duplicate_that_would_grow_is_downgraded_to_unchanged(): void
    {
        // "a\na" (3 chars) -> "a [x2]" (6 chars): the global guard must reject the
        // larger candidate and fall back to the original verbatim.
        $block = "a\na";
        $result = $this->make()->compress($block);

        $this->assertFalse($result->changed);
        $this->assertSame($block, $result->output);
    }

    public function test_preserves_presence_of_trailing_newline(): void
    {
        // Trailing newline (an empty final field) must be preserved when present.
        $withTrailing = "one\ntwo\ntwo\ntwo\n";
        $result = $this->make()->compress($withTrailing);

        $this->assertTrue($result->changed);
        $this->assertStringEndsWith("\n", $result->output);
        $this->assertSame("one\ntwo [x3]\n", $result->output);
    }

    public function test_is_deterministic_same_input_yields_identical_output(): void
    {
        $block = str_repeat("repeat line\n", 12)
            . "\n\n\n\n\n"
            . "ERROR: something unique\n"
            . str_repeat("another repeat\n", 8)
            . 'end';

        $c = $this->make();
        $first = $c->compress($block);
        $second = $c->compress($block);

        $this->assertSame($first->output, $second->output);
        $this->assertSame($first->changed, $second->changed);
        $this->assertSame($first->meta, $second->meta);

        // A fresh instance must also produce the identical output (no shared state).
        $third = (new TextCompressor())->compress($block);
        $this->assertSame($first->output, $third->output);
    }

    public function test_meta_reports_line_counts(): void
    {
        // Lines long enough that collapsing the 4-run genuinely shrinks the block,
        // so meta is populated (a non-shrinking result downgrades to empty meta —
        // see test_short_duplicate_that_would_grow_is_downgraded_to_unchanged).
        $repeated = 'this line is long enough that a [xN] marker is a net win';
        $block = $repeated . "\n" . $repeated . "\n" . $repeated . "\n" . $repeated . "\nb";
        $result = $this->make()->compress($block);

        $this->assertTrue($result->changed);
        $this->assertArrayHasKey('lines_total', $result->meta);
        $this->assertArrayHasKey('lines_after', $result->meta);
        $this->assertSame(5, $result->meta['lines_total']);
        $this->assertSame(2, $result->meta['lines_after']); // "<repeated> [x4]" + "b"
    }
}
