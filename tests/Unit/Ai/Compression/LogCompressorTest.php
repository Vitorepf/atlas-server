<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Compressors\LogCompressor;
use PHPUnit\Framework\TestCase;

class LogCompressorTest extends TestCase
{
    private LogCompressor $compressor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compressor = new LogCompressor();
    }

    public function test_content_type_is_log(): void
    {
        $this->assertSame('log', $this->compressor->contentType());
    }

    public function test_detect_true_on_log_shaped_block(): void
    {
        $block = implode("\n", [
            '2026-06-08 10:00:01 INFO worker started',
            '2026-06-08 10:00:02 INFO processing job 1',
            '2026-06-08 10:00:03 DEBUG cache miss',
            '2026-06-08 10:00:04 INFO processing job 2',
        ]);

        $this->assertTrue($this->compressor->detect($block));
    }

    public function test_detect_true_on_test_runner_markers(): void
    {
        $block = implode("\n", [
            'PASS tests/UnitA',
            'PASS tests/UnitB',
            '✓ it does the thing',
            'FAILED tests/UnitC',
        ]);

        $this->assertTrue($this->compressor->detect($block));
    }

    public function test_detect_false_on_prose(): void
    {
        $block = "This is a paragraph of ordinary prose.\n"
            ."It has several sentences and no log structure.\n"
            .'Nothing here resembles a timestamp or a level token at all.';

        $this->assertFalse($this->compressor->detect($block));
    }

    public function test_detect_false_on_too_few_lines(): void
    {
        $block = "2026-06-08 10:00:01 INFO one\n2026-06-08 10:00:02 INFO two";

        $this->assertFalse($this->compressor->detect($block), 'needs >= 3 lines');
    }

    public function test_compress_makes_large_redundant_log_strictly_smaller(): void
    {
        $lines = [
            '2026-06-08 10:00:00 INFO worker booting up now',
        ];
        // 200 near-identical heartbeat lines (only a counter varies a little).
        for ($i = 0; $i < 200; $i++) {
            $lines[] = '2026-06-08 10:00:05 DEBUG heartbeat tick ok ok ok';
        }
        $lines[] = '2026-06-08 10:09:99 INFO worker shutting down cleanly';
        $block = implode("\n", $lines);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertTrue($res->changed, 'a 200-line redundant run must compress');
        $this->assertLessThan($res->originalChars, $res->compressedChars);
        $this->assertSame('log', $res->contentType);
        $this->assertSame(count($lines), $res->meta['items_total']);
        $this->assertArrayHasKey('items_kept', $res->meta);
        $this->assertLessThan($res->meta['items_total'], $res->meta['items_kept']);
        $this->assertStringContainsString('similar lines omitted', $res->output);
    }

    /**
     * Keyword-matched signal lines (error / failure / exception / fatal) survive
     * VERBATIM through a big collapsible bulk. This proves the SIGNAL-keyword half of
     * the contract only. The harder, keyword-FREE half (unique non-keyword content,
     * and order-sensitivity) is proven by the dedicated tests below — those are the
     * cases an order-insensitive / saturating SimHash silently dropped.
     */
    public function test_lossless_signal_property_every_keyword_anomaly_survives_verbatim(): void
    {
        $anomalies = [
            '2026-06-08 10:03:11 ERROR database connection refused: 10.0.0.5:5432',
            '2026-06-08 10:03:12 WARNING retry budget nearly exhausted (1 left)',
            'Traceback (most recent call last): ValueError at app/svc.py:88',
            '2026-06-08 10:03:40 FATAL panic: nil pointer dereference in handler',
            'FAILED tests/Feature/CheckoutTest::it_charges_the_card',
        ];

        $lines = ['2026-06-08 10:00:00 INFO service started, pid=4242'];
        // Interleave a big homogeneous bulk with the anomalies scattered through it.
        for ($i = 0; $i < 30; $i++) {
            $lines[] = '2026-06-08 10:01:00 INFO handled request 200 OK same same';
            if (isset($anomalies[$i % 5]) && $i % 6 === 0) {
                $lines[] = $anomalies[$i % 5];
            }
        }
        // Guarantee each anomaly appears at least once in the input.
        foreach ($anomalies as $a) {
            $lines[] = $a;
            for ($j = 0; $j < 20; $j++) {
                $lines[] = '2026-06-08 10:05:00 DEBUG flushing buffer noop noop noop';
            }
        }
        $lines[] = '2026-06-08 10:09:00 INFO service stopped cleanly';
        $block = implode("\n", $lines);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertTrue($res->changed, 'there is plenty of redundant bulk to collapse');
        foreach ($anomalies as $anomaly) {
            $this->assertStringContainsString(
                $anomaly,
                $res->output,
                "anomaly must survive verbatim: {$anomaly}"
            );
        }
        // And the bulk really was collapsed (omitted count is honest & > 0).
        $this->assertGreaterThan(0, $res->meta['lines_omitted']);
    }

    /**
     * THE invariant the suite previously failed to prove: a unique line that does NOT
     * contain any signal keyword must STILL survive verbatim — it must never be
     * collapsed into a "similar lines omitted" breadcrumb.
     *
     * Regression for the SimHash saturation data-loss class: when a line is dominated
     * by a repeated token, the unique trailing value contributes ~zero net bits to a
     * 64-bit bag-of-tokens hash (hamming 0), so the unique line was silently dropped.
     * The two `value=...` lines below carry no keyword; only an EXACT redundancy proof
     * keeps them.
     */
    public function test_unique_non_keyword_line_survives_collapse_saturation(): void
    {
        $lines = ['2026-06-08 10:00:00 INFO run starting up now please'];
        // A long homogeneous bulk dominated by a repeated token, with two UNIQUE
        // trailing values buried in it. No line here matches the SIGNAL keyword regex.
        for ($i = 0; $i < 15; $i++) {
            $lines[] = '2026-06-08 10:00:05 DEBUG retry retry retry retry retry retry value=AAAA';
        }
        $lines[] = '2026-06-08 10:00:06 DEBUG retry retry retry retry retry retry value=ZZZZ';
        for ($i = 0; $i < 15; $i++) {
            $lines[] = '2026-06-08 10:00:07 DEBUG retry retry retry retry retry retry value=AAAA';
        }
        $lines[] = '2026-06-08 10:00:08 DEBUG correlation-id=7f3a-unique-one done';
        for ($i = 0; $i < 15; $i++) {
            $lines[] = '2026-06-08 10:00:09 DEBUG retry retry retry retry retry retry value=AAAA';
        }
        $lines[] = '2026-06-08 10:09:00 INFO run finished cleanly bye now';
        $block = implode("\n", $lines);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        // The homogeneous AAAA bulk really did collapse...
        $this->assertTrue($res->changed, 'the repeated AAAA bulk must collapse');
        $this->assertGreaterThan(0, $res->meta['lines_omitted']);
        // ...but the UNIQUE non-keyword values must each survive verbatim.
        $this->assertStringContainsString('value=ZZZZ', $res->output, 'unique value=ZZZZ must not be collapsed');
        $this->assertStringContainsString('correlation-id=7f3a-unique-one', $res->output, 'unique correlation-id must survive');
    }

    /**
     * Regression for the order-insensitive data-loss class: a bag-of-tokens SimHash
     * gives two lines with the SAME token multiset in a DIFFERENT order an identical
     * hash (hamming 0), so a genuinely different event (a different permission set /
     * path order) was treated as a duplicate and dropped. The reversed-order line has
     * no token repetition and no keyword; only an order-sensitive proof keeps it.
     */
    public function test_order_different_line_is_not_collapsed(): void
    {
        // Note: these bulk lines carry no ISO timestamp prefix on purpose, so the ONLY
        // difference between the anchor and the reordered line is token ORDER — exactly
        // the pure form of the bag-of-tokens collapse (old SimHash hamming 0). The
        // leading "DEBUG" keeps them log-shaped for detect().
        $lines = ['2026-06-08 10:00:00 INFO acl sync starting up here now'];
        for ($i = 0; $i < 20; $i++) {
            $lines[] = 'DEBUG granted read write exec';
        }
        // Same tokens, different order — a genuinely different permission event.
        $lines[] = 'DEBUG granted exec write read';
        for ($i = 0; $i < 20; $i++) {
            $lines[] = 'DEBUG granted read write exec';
        }
        // Same idea for an ordered path list.
        $lines[] = 'DEBUG moved fileA to dirZ then dirY then dirX';
        for ($i = 0; $i < 20; $i++) {
            $lines[] = 'DEBUG granted read write exec';
        }
        $lines[] = '2026-06-08 10:09:00 INFO acl sync finished here now ok';
        $block = implode("\n", $lines);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertTrue($res->changed, 'the repeated "read write exec" bulk must collapse');
        $this->assertGreaterThan(0, $res->meta['lines_omitted']);
        $this->assertStringContainsString('granted exec write read', $res->output, 'reversed-order permission event must survive');
        $this->assertStringContainsString('moved fileA to dirZ then dirY then dirX', $res->output, 'reversed-order path list must survive');
    }

    /**
     * Stronger general statement of losslessness: collapse the input, then verify that
     * EVERY distinct input line is still recoverable from the output. The only thing a
     * collapse may hide is a line that is byte-identical to a surviving line apart from
     * its timestamp/clock field — so masking timestamps in BOTH input and output, the
     * set of surviving content must cover every distinct masked input line.
     */
    public function test_every_distinct_line_modulo_timestamp_survives(): void
    {
        $lines = ['2026-06-08 10:00:00 INFO booting subsystem alpha now'];
        // Bulk of two interleaved homogeneous templates...
        for ($i = 0; $i < 40; $i++) {
            $lines[] = '2026-06-08 10:00:05 DEBUG heartbeat ok ok ok ok';
            $lines[] = '2026-06-08 10:00:06 DEBUG flushing buffer noop noop';
        }
        // ...with a scatter of genuinely-unique non-keyword lines mixed in.
        $unique = [
            '2026-06-08 10:01:00 DEBUG cache key=user:42 region=eu hit',
            '2026-06-08 10:02:00 DEBUG shipment 9920 carrier dhl eta 3d',
            '2026-06-08 10:03:00 DEBUG settings currency=BRL locale=pt-BR',
            '2026-06-08 10:04:00 DEBUG token rotated for client xyz-987',
        ];
        foreach ($unique as $u) {
            $lines[] = $u;
            for ($k = 0; $k < 5; $k++) {
                $lines[] = '2026-06-08 10:05:00 DEBUG heartbeat ok ok ok ok';
            }
        }
        $lines[] = '2026-06-08 10:09:00 INFO subsystem alpha stopped now';
        $block = implode("\n", $lines);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertTrue($res->changed, 'there is homogeneous bulk to collapse');

        // Mask timestamps the same way the compressor's redundancy proof does.
        $mask = static function (string $s): string {
            $s = (string) preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?/', '<TS>', $s);
            $s = (string) preg_replace('/\b\d{2}:\d{2}:\d{2}(?:[.,]\d+)?\b/', '<TS>', $s);

            return trim((string) preg_replace('/\s+/', ' ', $s));
        };

        $survivors = [];
        foreach (explode("\n", $res->output) as $outLine) {
            $survivors[$mask($outLine)] = true;
        }

        foreach (explode("\n", $block) as $inLine) {
            $this->assertArrayHasKey(
                $mask($inLine),
                $survivors,
                "every distinct input line (modulo timestamp) must survive: {$inLine}"
            );
        }

        // Each unique line, specifically, survives verbatim (not just modulo-mask).
        foreach ($unique as $u) {
            $this->assertStringContainsString($u, $res->output, "unique line dropped: {$u}");
        }
    }

    public function test_detect_false_on_source_code_with_log_like_tokens(): void
    {
        // A code block that mentions FAIL / OK / INFO / ERROR / PASS mid-line. detect()
        // must err toward FALSE so the collapse logic never runs on non-log text.
        $block = implode("\n", [
            'function handle($status) {',
            '    if ($status === self::ERROR) {',
            '        return INFO_LEVEL;',
            '    }',
            '    $ok = checkFailover(); // FAIL? OK?',
            '    return $ok ? PASS_FLAG : FAIL_FLAG;',
            '}',
        ]);

        $this->assertFalse(
            $this->compressor->detect($block),
            'source code that merely mentions log-level words must not be treated as a log'
        );
    }

    public function test_head_and_tail_boundary_lines_are_always_kept(): void
    {
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = '2026-06-08 10:00:00 INFO identical bulk line forever and ever';
        }
        // Make head/tail lines uniquely identifiable.
        $lines[0] = '2026-06-08 09:59:59 INFO HEAD-SENTINEL-UNIQUE booting';
        $lines[49] = '2026-06-08 10:10:10 INFO TAIL-SENTINEL-UNIQUE done';
        $block = implode("\n", $lines);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertStringContainsString('HEAD-SENTINEL-UNIQUE', $res->output);
        $this->assertStringContainsString('TAIL-SENTINEL-UNIQUE', $res->output);
    }

    public function test_structurally_distinct_lines_are_not_collapsed(): void
    {
        // Each line is genuinely different — nothing is provably redundant, so the
        // compressor must fail open (unchanged) rather than drop unique content.
        $block = implode("\n", [
            '2026-06-08 10:00:01 INFO user alice logged in from 10.0.0.1',
            '2026-06-08 10:00:02 INFO order 7781 created for $42.10 in region eu',
            '2026-06-08 10:00:03 INFO shipment 9920 dispatched via carrier dhl',
            '2026-06-08 10:00:04 INFO invoice 3310 emailed to bob@example.com',
            '2026-06-08 10:00:05 INFO settings updated: currency=BRL locale=pt',
        ]);

        $res = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertFalse($res->changed, 'distinct lines are not redundant');
        $this->assertSame($block, $res->output);
    }

    public function test_small_block_returns_unchanged(): void
    {
        $block = "2026-06-08 10:00:01 INFO only\n2026-06-08 10:00:02 INFO two lines";

        $res = $this->compressor->compress($block);

        $this->assertFalse($res->changed);
        $this->assertSame($block, $res->output);
    }

    public function test_empty_block_returns_unchanged(): void
    {
        $res = $this->compressor->compress('');

        $this->assertFalse($res->changed);
        $this->assertSame('', $res->output);
        $this->assertFalse($this->compressor->detect(''));
    }

    public function test_compression_is_deterministic(): void
    {
        $lines = ['2026-06-08 10:00:00 INFO start of the run here'];
        for ($i = 0; $i < 120; $i++) {
            $lines[] = '2026-06-08 10:00:05 DEBUG repeated repeated repeated line';
        }
        $lines[] = '2026-06-08 10:03:00 ERROR something specific broke once';
        for ($i = 0; $i < 120; $i++) {
            $lines[] = '2026-06-08 10:04:05 DEBUG another repeated repeated block';
        }
        $lines[] = '2026-06-08 10:09:00 INFO end of the run here';
        $block = implode("\n", $lines);

        $a = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);
        $b = $this->compressor->compress($block, ['keep_head' => 8, 'keep_tail' => 4]);

        $this->assertSame($a->output, $b->output);
        $this->assertSame($a->meta, $b->meta);
        // The lone ERROR is signal -> must survive in the deterministic output.
        $this->assertStringContainsString('something specific broke once', $a->output);
    }
}
