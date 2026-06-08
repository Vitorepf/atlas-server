<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Compressors\SmartCrusherJsonCompressor;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for the JSON-array SmartCrusher (AP-813). No DB / no container.
 *
 * Contract under test = LOSSLESS-BY-GOVERNANCE: every ANOMALY (error / shape-change /
 * outlier / scalar) is kept in-compressor verbatim; homogeneous BULK is SAMPLED
 * (head + tail + even middle, up to the max_keep budget) with the dropped rows
 * recoverable in full via the CCR store; exact byte-duplicates never appear twice.
 */
final class SmartCrusherJsonCompressorTest extends TestCase
{
    private function compressor(): SmartCrusherJsonCompressor
    {
        return new SmartCrusherJsonCompressor;
    }

    private function options(array $override = []): array
    {
        return array_replace(['keep_head' => 8, 'keep_tail' => 4, 'max_keep' => 40], $override);
    }

    public function test_detect_true_on_a_json_array_of_six_or_more(): void
    {
        $sample = json_encode(array_fill(0, 8, ['id' => 1, 'v' => 'x']));

        $this->assertTrue($this->compressor()->detect($sample));
        $this->assertTrue($this->compressor()->detect("  \n\t".$sample)); // leading ws ok
    }

    public function test_detect_false_on_non_matching_blocks(): void
    {
        $c = $this->compressor();

        $this->assertFalse($c->detect('{"id":1,"status":"ok"}')); // object/map, not a list
        $this->assertFalse($c->detect('[1,2,3]')); // list but < 6 elements
        $this->assertFalse($c->detect('just some prose, not json at all'));
        $this->assertFalse($c->detect('[not, valid, json, here, at, all]')); // does not decode
        $this->assertFalse($c->detect(''));
        $this->assertFalse($c->detect('   '));
    }

    public function test_homogeneous_bulk_is_sampled_smaller_keeping_head_and_tail(): void
    {
        // 200 same-shape rows, each with a UNIQUE id, no error markers, equal length:
        // pure homogeneous bulk -> SAMPLED down to ~max_keep (recoverable via CCR).
        $items = [];
        for ($i = 0; $i < 200; $i++) {
            $items[] = ['id' => 1000 + $i, 'status' => 'ok', 'value' => 'row'];
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertTrue($result->changed, 'a large homogeneous array must compress');
        $this->assertLessThan($result->originalChars, $result->compressedChars);
        $this->assertSame(200, $result->meta['items_total']);
        $this->assertLessThan(200, $result->meta['items_kept']);
        $this->assertLessThanOrEqual(40, $result->meta['items_kept']); // within budget
        // Head and tail rows are kept for context.
        $this->assertStringContainsString('"id":1000', $result->output);
        $this->assertStringContainsString('"id":1199', $result->output);
        // It genuinely dropped rows (not every id survived).
        preg_match_all('/"id":\d+/', $result->output, $m);
        $this->assertGreaterThan(0, count($m[0]));
        $this->assertLessThan(200, count($m[0]));
    }

    public function test_every_anomaly_survives_verbatim(): void
    {
        // Heavy exact-duplicate bulk + four distinctive anomalies in the MIDDLE.
        $items = [];
        for ($i = 0; $i < 80; $i++) {
            $items[] = ['id' => 1, 'status' => 'ok', 'kind' => 'row'];
        }
        array_splice($items, 30, 0, [['id' => 999, 'status' => 'error', 'kind' => 'row', 'note' => 'TOKEN_ERR_42 boom exception']]);
        array_splice($items, 45, 0, [['totally' => 'different', 'shape' => 'TOKEN_SHAPE_7', 'extra' => true]]);
        array_splice($items, 55, 0, [['id' => 7, 'status' => 'ok', 'kind' => 'row', 'blob' => str_repeat('TOKEN_OUTLIER_X', 200)]]);
        array_splice($items, 60, 0, ['TOKEN_SCALAR_LONE_VALUE']);
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertTrue($result->changed);
        foreach (['TOKEN_ERR_42', 'TOKEN_SHAPE_7', 'TOKEN_OUTLIER_X', 'TOKEN_SCALAR_LONE_VALUE'] as $token) {
            $this->assertStringContainsString($token, $result->output, "anomaly {$token} must survive verbatim");
        }
        $this->assertGreaterThanOrEqual(4, $result->meta['anomalies_kept']);

        // The kept rows still parse as a JSON array (the note is on its own trailing line).
        $firstLine = strstr($result->output, "\n", true);
        $decoded = json_decode((string) $firstLine, true);
        $this->assertIsArray($decoded);
        $this->assertTrue(array_is_list($decoded));
        $this->assertSame($result->meta['items_kept'], count($decoded));
    }

    public function test_a_high_max_keep_keeps_every_distinct_row_dropping_only_exact_dups(): void
    {
        // 60 distinct rows + 40 EXACT duplicates. With a huge budget the dial is in
        // "near-lossless" mode: every distinct row survives; only exact dups collapse.
        $items = [];
        for ($i = 0; $i < 60; $i++) {
            $items[] = ['id' => 1000 + $i, 'status' => 'ok', 'value' => 'row'];
        }
        for ($i = 0; $i < 40; $i++) {
            $items[] = ['id' => -1, 'status' => 'ok', 'value' => 'row']; // exact dup of each other
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options(['max_keep' => 100000]));

        $this->assertTrue($result->changed, 'the 40 exact dups should still collapse');
        for ($i = 0; $i < 60; $i++) {
            $this->assertStringContainsString('"id":'.(1000 + $i), $result->output, 'high max_keep keeps every distinct row');
        }
    }

    public function test_exact_duplicates_collapse(): void
    {
        $json = json_encode(array_fill(0, 50, ['id' => 1, 'status' => 'ok']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertTrue($result->changed);
        $this->assertLessThan($result->originalChars, $result->compressedChars);
        $this->assertSame(50, $result->meta['items_total']);
    }

    public function test_a_list_of_all_unique_anomalies_does_not_grow_and_keeps_all(): void
    {
        // 8 distinct error rows: every one is an anomaly. None droppable; the result
        // must not be larger than the input and every row survives.
        $items = [];
        for ($i = 0; $i < 8; $i++) {
            $items[] = ['id' => $i, 'status' => "error_{$i}", 'msg' => "failure number {$i}"];
        }
        $json = json_encode($items);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertLessThanOrEqual($result->originalChars, $result->compressedChars);
        for ($i = 0; $i < 8; $i++) {
            $this->assertStringContainsString("failure number {$i}", $result->output);
        }
    }

    public function test_small_or_non_matching_block_returns_unchanged(): void
    {
        $c = $this->compressor();

        $small = json_encode([['id' => 1], ['id' => 2], ['id' => 3]]);
        $r1 = $c->compress($small, $this->options());
        $this->assertFalse($r1->changed);
        $this->assertSame($small, $r1->output);

        $r2 = $c->compress('this is not json', $this->options());
        $this->assertFalse($r2->changed);
        $this->assertSame('this is not json', $r2->output);

        $obj = '{"a":1,"b":2,"c":3,"d":4,"e":5,"f":6,"g":7}';
        $r3 = $c->compress($obj, $this->options());
        $this->assertFalse($r3->changed);
        $this->assertSame($obj, $r3->output);
    }

    public function test_marker_note_is_honest_about_omissions(): void
    {
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['id' => 2000 + $i, 'k' => 'v'];
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertStringContainsString('kept', $result->output);
        $this->assertStringContainsString('of 100 items', $result->output);
        $this->assertStringContainsString('recoverable', $result->output);
    }

    public function test_compression_is_deterministic(): void
    {
        $items = [];
        for ($i = 0; $i < 120; $i++) {
            $items[] = ['id' => 5000 + $i, 'status' => 'ok'];
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $a = $this->compressor()->compress($json, $this->options());
        $b = $this->compressor()->compress($json, $this->options());

        $this->assertSame($a->output, $b->output);
        $this->assertSame($a->meta, $b->meta);
    }
}
