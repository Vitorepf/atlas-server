<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\Compressors\SmartCrusherJsonCompressor;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for the JSON-array SmartCrusher (AP-813). No DB / no container —
 * extends the plain PHPUnit TestCase by design (the compressor is a pure function).
 */
final class SmartCrusherJsonCompressorTest extends TestCase
{
    private function compressor(): SmartCrusherJsonCompressor
    {
        return new SmartCrusherJsonCompressor;
    }

    private function options(): array
    {
        return ['keep_head' => 8, 'keep_tail' => 4, 'max_keep' => 40];
    }

    /**
     * A redundant array: many exact-duplicate homogeneous rows, plus a few embedded
     * anomalies (an error row, a shape-different row, a length-outlier row) placed in
     * the MIDDLE so they are kept by the anomaly rule, not by head/tail.
     *
     * @return array{json:string, anomalyTokens:list<string>}
     */
    private function redundantSampleWithAnomalies(int $bulk = 80): array
    {
        $items = [];
        for ($i = 0; $i < $bulk; $i++) {
            // Exact duplicates → provably redundant homogeneous bulk.
            $items[] = ['id' => 1, 'status' => 'ok', 'kind' => 'row'];
        }

        // Distinctive anomaly payloads, each carrying a unique verbatim token.
        $errorRow = ['id' => 999, 'status' => 'error', 'kind' => 'row', 'note' => 'TOKEN_ERR_42 boom exception'];
        $shapeRow = ['totally' => 'different', 'shape' => 'TOKEN_SHAPE_7', 'extra' => true];
        $outlierRow = ['id' => 7, 'status' => 'ok', 'kind' => 'row', 'blob' => str_repeat('TOKEN_OUTLIER_X', 200)];
        $scalarRow = 'TOKEN_SCALAR_LONE_VALUE';

        // Inject them well away from the head/tail windows.
        array_splice($items, 30, 0, [$errorRow]);
        array_splice($items, 45, 0, [$shapeRow]);
        array_splice($items, 55, 0, [$outlierRow]);
        array_splice($items, 60, 0, [$scalarRow]);

        return [
            'json' => json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'anomalyTokens' => [
                'TOKEN_ERR_42',
                'TOKEN_SHAPE_7',
                'TOKEN_OUTLIER_X',
                'TOKEN_SCALAR_LONE_VALUE',
            ],
        ];
    }

    public function test_detect_true_on_a_json_array_of_six_or_more(): void
    {
        $sample = json_encode(array_fill(0, 8, ['id' => 1, 'v' => 'x']));

        $this->assertTrue($this->compressor()->detect($sample));
        // Leading whitespace must not defeat detection.
        $this->assertTrue($this->compressor()->detect("  \n\t".$sample));
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

    public function test_compress_makes_a_large_redundant_array_strictly_smaller(): void
    {
        $sample = $this->redundantSampleWithAnomalies();

        $result = $this->compressor()->compress($sample['json'], $this->options());

        $this->assertTrue($result->changed, 'a highly-redundant array should compress');
        $this->assertLessThan(
            $result->originalChars,
            $result->compressedChars,
            'the compressed form must be strictly smaller'
        );
        $this->assertSame('json', $result->contentType);

        // Honest, item-based meta for the pipeline marker.
        $this->assertArrayHasKey('items_total', $result->meta);
        $this->assertArrayHasKey('items_kept', $result->meta);
        $this->assertArrayHasKey('anomalies_kept', $result->meta);
        $this->assertGreaterThan($result->meta['items_kept'], $result->meta['items_total']);
        $this->assertGreaterThanOrEqual(4, $result->meta['anomalies_kept']);
    }

    public function test_lossless_signal_property_every_anomaly_survives_verbatim(): void
    {
        $sample = $this->redundantSampleWithAnomalies();

        $result = $this->compressor()->compress($sample['json'], $this->options());

        $this->assertTrue($result->changed);

        // The crux of "qualidade ao extremo": every error / shape-change / outlier /
        // structurally-unique item is still present, byte-for-byte, in the output.
        foreach ($sample['anomalyTokens'] as $token) {
            $this->assertStringContainsString(
                $token,
                $result->output,
                "anomaly token {$token} must be preserved verbatim — no signal may be dropped"
            );
        }

        // The kept rows must still parse as a JSON array (the note line is appended
        // after the array, on its own line).
        $firstLine = strstr($result->output, "\n", true);
        $this->assertIsString($firstLine);
        $decoded = json_decode($firstLine, true);
        $this->assertIsArray($decoded);
        $this->assertTrue(array_is_list($decoded));
        $this->assertSame($result->meta['items_kept'], count($decoded));

        // And an honest omission note is present.
        $this->assertStringContainsString('kept', $result->output);
        $this->assertStringContainsString('of', $result->output);
        $this->assertStringContainsString('items', $result->output);
    }

    public function test_all_distinct_items_are_preserved_when_nothing_is_redundant(): void
    {
        // 7 fully-distinct items (each a different shape/value) — there is no provable
        // redundancy to drop, so the crusher must NOT lose anything. It either returns
        // unchanged or keeps every item.
        $items = [
            ['a' => 1],
            ['b' => 2, 'c' => 3],
            ['d' => 'TOKEN_D'],
            ['e' => [1, 2, 3]],
            'TOKEN_SCALAR',
            ['f' => 'error TOKEN_F'],
            ['g' => 'TOKEN_G', 'h' => 'extra'],
        ];
        $json = json_encode($items);

        $result = $this->compressor()->compress($json, $this->options());

        foreach (['TOKEN_D', 'TOKEN_SCALAR', 'TOKEN_F', 'TOKEN_G'] as $token) {
            $this->assertStringContainsString($token, $result->output);
        }
    }

    /**
     * REGRESSION (AP-813 adversarial review). The earlier knee/budget design dropped
     * distinct rows once a ~12-item budget was hit, even though they were neither exact
     * nor near duplicates of any kept row. This is the textbook target payload: many
     * same-SHAPE rows, each with a UNIQUE `id`, no error markers, near-identical length
     * — classified as non-anomaly "bulk" and silently evicted. They MUST all survive,
     * because each unique id is unrecoverable from the output once dropped.
     */
    public function test_distinct_same_shape_rows_with_unique_ids_are_all_kept_even_past_budget(): void
    {
        $uniqueCount = 60; // far beyond any keep_head+keep_tail budget (12)

        $items = [];
        for ($i = 0; $i < $uniqueCount; $i++) {
            // Same shape, near-identical length, NO error marker → not an anomaly,
            // and byte-distinct from every other row (unique id) → not a duplicate.
            $items[] = ['id' => 1_000_000 + $i, 'shape' => 'row', 'flag' => false];
        }
        // Add genuinely-redundant exact-duplicate filler so a real compression happens
        // (and the unchanged short-circuit is NOT what makes the test pass).
        for ($i = 0; $i < 40; $i++) {
            $items[] = ['id' => -1, 'shape' => 'row', 'flag' => true, 'filler' => 'BULK_FILLER'];
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        // A real collapse happened (the 40 identical fillers collapsed to 1)...
        $this->assertTrue($result->changed, 'the exact-duplicate filler should compress');
        $this->assertGreaterThan($result->meta['items_kept'], $result->meta['items_total']);

        // ...yet EVERY one of the 60 byte-unique rows survives verbatim.
        for ($i = 0; $i < $uniqueCount; $i++) {
            $id = 1_000_000 + $i;
            $this->assertStringContainsString(
                "\"id\":{$id}",
                $result->output,
                "unique id {$id} must be preserved — a budget cap must never evict distinct content"
            );
        }

        // Sanity: the kept distinct count is at least the 60 uniques + 1 filler rep.
        $this->assertGreaterThanOrEqual($uniqueCount + 1, $result->meta['items_kept']);
    }

    /**
     * REGRESSION: 20 same-shape rows each with a unique `name_N` value. None are exact
     * or near duplicates of a kept row; all 20 distinct names must appear in the output.
     */
    public function test_distinct_same_shape_rows_with_unique_names_are_all_kept(): void
    {
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = ['kind' => 'user', 'name' => "name_{$i}", 'active' => true];
        }
        // Exact-duplicate bulk so a compression actually occurs.
        for ($i = 0; $i < 30; $i++) {
            $items[] = ['kind' => 'user', 'name' => 'DUP', 'active' => false];
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        for ($i = 0; $i < 20; $i++) {
            $this->assertStringContainsString(
                "name_{$i}",
                $result->output,
                "unique token name_{$i} must survive"
            );
        }
    }

    /**
     * REGRESSION: 50 unique scalar STRINGS. Every distinct scalar is signal and must be
     * kept; only the exact-duplicate scalars collapse.
     */
    public function test_distinct_unique_scalar_strings_are_all_kept(): void
    {
        $items = [];
        for ($i = 0; $i < 50; $i++) {
            $items[] = "UNIQUE_SCALAR_{$i}";
        }
        // Exact-duplicate scalar bulk to trigger a real compression.
        for ($i = 0; $i < 20; $i++) {
            $items[] = 'DUP_SCALAR';
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertTrue($result->changed, 'duplicate scalars should collapse');
        for ($i = 0; $i < 50; $i++) {
            $this->assertStringContainsString(
                "UNIQUE_SCALAR_{$i}",
                $result->output,
                "unique scalar UNIQUE_SCALAR_{$i} must survive"
            );
        }
    }

    /**
     * REGRESSION (second lossy mechanism): the old SimHash near-duplicate filter
     * (hamming <= 3) collapsed semantically-DISTINCT near-strings — e.g. `aaaab` amid
     * `aaaaa` bulk, or `status:passed` vs `status:paused`. Those are different content
     * and must both survive verbatim. No near-duplicate collapse may occur.
     */
    public function test_near_but_distinct_values_are_not_collapsed(): void
    {
        $items = [];
        // Exact-duplicate bulk that the old code's SimHash would have used as the anchor.
        for ($i = 0; $i < 30; $i++) {
            $items[] = ['v' => 'aaaaa'];
        }
        // A one-byte-different sibling: distinct content, hamming-near under the old rule.
        $items[] = ['v' => 'aaaab'];
        // Real-world near-but-distinct pairs.
        for ($i = 0; $i < 10; $i++) {
            $items[] = ['status' => 'passed'];
        }
        $items[] = ['status' => 'paused'];
        $items[] = ['amount' => 1000];
        $items[] = ['amount' => 1001];
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        // The duplicate bulk collapsed, so a compression happened...
        $this->assertTrue($result->changed);

        // ...but no distinct near-sibling was treated as redundant.
        $this->assertStringContainsString('"v":"aaaab"', $result->output, 'aaaab is distinct from aaaaa');
        $this->assertStringContainsString('"status":"paused"', $result->output, 'paused is distinct from passed');
        $this->assertStringContainsString('"amount":1000', $result->output);
        $this->assertStringContainsString('"amount":1001', $result->output, '1001 is distinct from 1000');
    }

    /**
     * The strongest single statement of the contract: across an arbitrary mix of
     * redundant bulk + distinct rows, EVERY distinct compact-JSON encoding present in
     * the input is present in the output. Nothing distinct is ever lost.
     */
    public function test_lossless_signal_every_distinct_encoding_survives(): void
    {
        $items = [];
        // Heavy exact-duplicate bulk.
        for ($i = 0; $i < 100; $i++) {
            $items[] = ['t' => 'bulk', 'n' => 0];
        }
        // A spread of distinct rows: unique ids, a shape change, a scalar, an error,
        // a length outlier, near-but-distinct siblings.
        $items[] = ['t' => 'bulk', 'n' => 1];
        $items[] = ['t' => 'bulk', 'n' => 2];
        $items[] = ['t' => 'bulk', 'n' => 3];
        $items[] = ['weird' => 'shape'];
        $items[] = 'lone-scalar';
        $items[] = ['t' => 'bulk', 'n' => 0, 'error' => 'kaboom'];
        $items[] = ['t' => 'bulk', 'n' => 0, 'big' => str_repeat('X', 300)];
        $items[] = ['t' => 'bulk', 'n' => 11];
        $items[] = ['t' => 'bulk', 'n' => 111];
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        // Compute the set of distinct encodings in the INPUT and assert each is present
        // in the output verbatim.
        $distinct = [];
        foreach ($items as $item) {
            $distinct[json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] = true;
        }
        foreach (array_keys($distinct) as $encoding) {
            $this->assertStringContainsString(
                $encoding,
                $result->output,
                'every distinct row encoding must survive: '.$encoding
            );
        }

        // And it actually compressed (the 100 identical bulk rows collapsed to one).
        $this->assertTrue($result->changed);
        $this->assertSame(count($distinct), $result->meta['items_kept']);
    }

    public function test_small_or_non_matching_block_returns_unchanged(): void
    {
        $c = $this->compressor();

        // Below the 6-element floor.
        $small = json_encode([['id' => 1], ['id' => 2], ['id' => 3]]);
        $r1 = $c->compress($small, $this->options());
        $this->assertFalse($r1->changed);
        $this->assertSame($small, $r1->output);

        // Not a JSON array at all.
        $r2 = $c->compress('this is not json', $this->options());
        $this->assertFalse($r2->changed);
        $this->assertSame('this is not json', $r2->output);

        // An object/map is out of scope.
        $obj = '{"a":1,"b":2,"c":3,"d":4,"e":5,"f":6,"g":7}';
        $r3 = $c->compress($obj, $this->options());
        $this->assertFalse($r3->changed);
        $this->assertSame($obj, $r3->output);

        // Empty.
        $r4 = $c->compress('', $this->options());
        $this->assertFalse($r4->changed);
    }

    public function test_a_list_of_all_unique_anomalies_does_not_grow(): void
    {
        // 8 distinct error-ish rows: every one is an anomaly, none droppable. The
        // result must never be larger than the input (compressed() self-downgrades).
        $items = [];
        for ($i = 0; $i < 8; $i++) {
            $items[] = ['id' => $i, 'status' => "error_{$i}", 'msg' => "failure number {$i}"];
        }
        $json = json_encode($items);

        $result = $this->compressor()->compress($json, $this->options());

        $this->assertLessThanOrEqual($result->originalChars, $result->compressedChars);
        // Every unique row still present.
        for ($i = 0; $i < 8; $i++) {
            $this->assertStringContainsString("failure number {$i}", $result->output);
        }
    }

    /**
     * A list of fully-distinct (non-anomaly) rows must NOT be made larger and must not
     * lose any row — with no exact duplicates to collapse, the crusher returns unchanged.
     */
    public function test_all_distinct_non_anomaly_rows_return_unchanged(): void
    {
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = ['id' => $i, 'shape' => 'row', 'v' => "val_{$i}"];
        }
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $result = $this->compressor()->compress($json, $this->options());

        // No exact duplicates → nothing provably redundant → pass through unchanged.
        $this->assertFalse($result->changed);
        $this->assertSame($json, $result->output);
        for ($i = 0; $i < 20; $i++) {
            $this->assertStringContainsString("val_{$i}", $result->output);
        }
    }

    public function test_compression_is_deterministic(): void
    {
        $sample = $this->redundantSampleWithAnomalies();

        $a = $this->compressor()->compress($sample['json'], $this->options());
        $b = $this->compressor()->compress($sample['json'], $this->options());

        $this->assertSame($a->output, $b->output);
        $this->assertSame($a->changed, $b->changed);
        $this->assertSame($a->meta, $b->meta);
    }
}
