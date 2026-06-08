<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Compressors\DiffCompressor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for {@see DiffCompressor} (AP-813). No DB / container / I/O — the
 * compressor is a pure block-in / CompressionResult-out transform.
 */
final class DiffCompressorTest extends TestCase
{
    private function compressor(): DiffCompressor
    {
        return new DiffCompressor;
    }

    /**
     * Build a single-file unified diff whose hunk holds $contextLines unchanged
     * context lines around two UNIQUE changed lines (an anomaly + a marker) so the
     * lossless-signal property has something distinctive to survive.
     */
    private function sampleDiff(int $contextLines): string
    {
        $lines = [
            'diff --git a/src/Worker.php b/src/Worker.php',
            'index 1a2b3c4..5d6e7f8 100644',
            '--- a/src/Worker.php',
            '+++ b/src/Worker.php',
            '@@ -10,'.($contextLines + 2).' +10,'.($contextLines + 2).' @@ class Worker',
        ];

        // Leading context block.
        for ($i = 0; $i < $contextLines; $i++) {
            $lines[] = ' '.'    $untouched = '.$i.'; // boilerplate line '.$i;
        }

        // The two unique CHANGED lines — these are the "signal" that must never drop.
        $lines[] = '-        throw new RuntimeException("FATAL: ledger corrupt @42");';
        $lines[] = '+        // FIXME-ANOMALY-7 swallow until AP-999 lands';

        // Trailing context block.
        for ($i = 0; $i < $contextLines; $i++) {
            $lines[] = ' '.'    $alsoUntouched = '.$i.'; // tail boilerplate '.$i;
        }

        return implode("\n", $lines);
    }

    public function test_detect_true_on_diff_git_and_on_hunk_with_file_header(): void
    {
        $c = $this->compressor();

        $this->assertTrue($c->detect($this->sampleDiff(20)));

        // Hunk marker + a file-header marker, without "diff --git".
        $bare = "--- a/x\n+++ b/x\n@@ -1,2 +1,2 @@\n-old\n+new\n unchanged";
        $this->assertTrue($c->detect($bare));
    }

    public function test_detect_false_on_plain_text_and_weak_lookalikes(): void
    {
        $c = $this->compressor();

        $this->assertFalse($c->detect(''));
        $this->assertFalse($c->detect('Just some prose about a git workflow and @@ markers in a sentence.'));

        // "@@ " present but NO file-header marker -> conservative false.
        $this->assertFalse($c->detect("paragraph one\n@@ heading @@\nparagraph two with + and - signs"));
    }

    /**
     * The REALISTIC false-positive class the old substring-anywhere detect() tripped:
     * ordinary markdown / config / prose that merely CONTAINS a '---'/'+++' run and an
     * '@@ ' token somewhere. detect() MUST stay conservative (FALSE) for all of these,
     * because compress() would otherwise treat their space-indented lines as redundant
     * context and silently drop unique content. Each case carries BOTH a dash run and
     * an '@@ ' token to specifically defeat the old `@@ ` + (`--- `|`+++ `) heuristic.
     *
     * @return iterable<string,array{0:string}>
     */
    public static function nonDiffLookalikes(): iterable
    {
        // Markdown table: the separator row holds the '--- ' substring; '@@' in prose.
        yield 'markdown table + @@ prose' => [
            "# Notes\n\n| Setting | Default |\n| --- | --- |\n\nUse the @@ handler for inbound mail.\n",
        ];

        // YAML / front-matter '---' document separator, '@@ ' in a heading-like line.
        yield 'yaml front-matter separator' => [
            "---\ntitle: Release 2.4\nowner: ops\n---\n\n@@ Highlights @@\n\n- faster startup\n- fewer retries\n",
        ];

        // Email-style quoted reply: '---' divider lines and an '@@'/at-mention token.
        yield 'email divider + at token' => [
            "Hi team,\n\nSee the summary below.\n\n--- Original Message ---\nFrom: ops@@example\nSubject: +++ URGENT +++\n",
        ];

        // Release notes whose body is a long run of UNIQUE space-indented config lines
        // — the exact shape that lost 36/40 lines under the buggy detect+compress.
        yield 'release notes with indented config' => [
            self::releaseNotesLookalike(),
        ];
    }

    #[DataProvider('nonDiffLookalikes')]
    public function test_detect_false_on_realistic_markdown_config_prose(string $block): void
    {
        $this->assertFalse(
            $this->compressor()->detect($block),
            'detect() must be conservative (FALSE) for prose/markdown/config lookalikes',
        );
    }

    /**
     * The contract-level guarantee, proven over detect()'s ACTUAL acceptance behaviour
     * rather than only over hand-crafted diffs: for every realistic non-diff lookalike,
     * routing through compress() must NOT make any unique line vanish. With the
     * conservative detect() this holds because compress() passes the block through
     * unchanged; the test asserts the OBSERVABLE invariant (no unique line dropped),
     * so it would fail loudly if detect() ever wrongly accepted one of these and the
     * context-collapse silently ate its indented body.
     */
    #[DataProvider('nonDiffLookalikes')]
    public function test_compress_never_drops_a_unique_line_from_a_non_diff_lookalike(string $block): void
    {
        $res = $this->compressor()->compress($block);

        // Whatever the path, every distinct non-empty source line must still be present
        // verbatim somewhere in the output (no silent middle-of-run collapse of unique
        // content). This is the real "keep unique content UNCONDITIONALLY" assertion.
        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->assertStringContainsString(
                $line,
                $res->output,
                "a unique line was silently dropped from a non-diff block: {$line}",
            );
        }
    }

    /**
     * A 40-unique-indented-line "release notes" block: markdown table (gives the
     * '--- ' substring) + an '@@ ' prose token + a long run of UNIQUE space-indented
     * config lines. This is the precise reproduction of the live finding (36/40 unique
     * lines dropped) that the tightened detect() must now refuse.
     */
    private static function releaseNotesLookalike(): string
    {
        $lines = [
            '# Release Notes v2.4.0',
            '',
            '| Setting | Default | Notes |',
            '| --- | --- | --- |',
            '',
            'Mention of the @@ symbol in a sentence about email handlers.',
            '',
            'Config block (each line UNIQUE):',
        ];
        for ($i = 0; $i < 40; $i++) {
            $lines[] = '    config.option_'.$i.' = value_'.$i.'_unique_'.dechex($i * 7919);
        }

        return implode("\n", $lines);
    }

    public function test_compress_makes_large_redundant_diff_strictly_smaller(): void
    {
        $diff = $this->sampleDiff(40);
        $res = $this->compressor()->compress($diff);

        $this->assertTrue($res->changed, 'a 40-context-line diff should compress');
        $this->assertSame('diff', $res->contentType);
        $this->assertLessThan(strlen($diff), strlen($res->output));
        $this->assertLessThan($res->originalChars, $res->compressedChars);
    }

    public function test_lossless_signal_every_change_and_header_survives_verbatim(): void
    {
        $diff = $this->sampleDiff(60);
        $res = $this->compressor()->compress($diff);
        $out = $res->output;

        // All file headers + the hunk header are kept verbatim.
        foreach ([
            'diff --git a/src/Worker.php b/src/Worker.php',
            'index 1a2b3c4..5d6e7f8 100644',
            '--- a/src/Worker.php',
            '+++ b/src/Worker.php',
            '@@ -10,62 +10,62 @@ class Worker',
        ] as $header) {
            $this->assertStringContainsString($header, $out, "header dropped: {$header}");
        }

        // Both UNIQUE changed lines (the anomaly + the marker) survive verbatim.
        $this->assertStringContainsString('-        throw new RuntimeException("FATAL: ledger corrupt @42");', $out);
        $this->assertStringContainsString('+        // FIXME-ANOMALY-7 swallow until AP-999 lands', $out);

        // No changed line was silently lost: same count of +/- change lines in/out.
        $this->assertSame(
            $this->countChangeLines($diff),
            $this->countChangeLines($out),
            'a changed (+/-) line was dropped',
        );

        // It actually collapsed redundant bulk and left an honest note.
        $this->assertStringContainsString('unchanged lines ...]', $out);
        $this->assertGreaterThan($res->meta['items_kept'], $res->meta['items_total']);
    }

    public function test_anomaly_changed_line_is_kept_even_in_a_dense_redundant_block(): void
    {
        // 200 nearly-identical context lines with ONE buried change — the change must
        // never be sampled away as "redundant".
        $lines = ['diff --git a/log.txt b/log.txt', '--- a/log.txt', '+++ b/log.txt', '@@ -1,201 +1,201 @@'];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = ' INFO heartbeat ok';
        }
        $lines[] = '-ERROR unique-disk-failure-XYZ at sector 9001';
        for ($i = 0; $i < 100; $i++) {
            $lines[] = ' INFO heartbeat ok';
        }
        $diff = implode("\n", $lines);

        $res = $this->compressor()->compress($diff);

        $this->assertTrue($res->changed);
        $this->assertStringContainsString('-ERROR unique-disk-failure-XYZ at sector 9001', $res->output);
    }

    public function test_small_empty_and_nonmatching_blocks_return_unchanged(): void
    {
        $c = $this->compressor();

        // Empty.
        $this->assertFalse($c->compress('')->changed);

        // Non-matching prose.
        $prose = 'This is a normal paragraph with no diff structure whatsoever.';
        $r = $c->compress($prose);
        $this->assertFalse($r->changed);
        $this->assertSame($prose, $r->output);

        // A valid diff with nothing collapsible (context run <= 2*keep) -> not smaller.
        $tiny = $this->sampleDiff(2);
        $rt = $c->compress($tiny);
        $this->assertFalse($rt->changed, 'a diff with no redundant context must pass through unchanged');
        $this->assertSame($tiny, $rt->output);
    }

    public function test_output_is_deterministic(): void
    {
        $diff = $this->sampleDiff(50);
        $c = $this->compressor();

        $a = $c->compress($diff);
        $b = $c->compress($diff);

        $this->assertSame($a->output, $b->output);
        $this->assertSame($a->meta, $b->meta);
        $this->assertSame($a->changed, $b->changed);
    }

    private function countChangeLines(string $text): int
    {
        $count = 0;
        foreach (explode("\n", $text) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === '+' && ! str_starts_with($line, '+++')) {
                $count++;
            } elseif ($line[0] === '-' && ! str_starts_with($line, '---')) {
                $count++;
            }
        }

        return $count;
    }
}
