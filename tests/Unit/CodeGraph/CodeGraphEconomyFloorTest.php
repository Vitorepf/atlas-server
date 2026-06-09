<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Compression\Compressors\DiffCompressor;
use App\Services\Ai\Compression\Compressors\LogCompressor;
use App\Services\Ai\Compression\Compressors\SearchCompressor;
use App\Services\Ai\Compression\Compressors\SmartCrusherJsonCompressor;
use App\Services\Ai\Compression\Compressors\TextCompressor;
use App\Services\Ai\Compression\CompressionPipeline;
use App\Services\Ai\Compression\ContentRouter;
use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use App\Services\Engineering\CodeGraph\CodeGraphRetrievalCompressor;
use Tests\TestCase;

/**
 * AP-815 · E-1 (D6) — MEASUREMENT test: an ECONOMY FLOOR on compression-on-retrieval.
 *
 * The E-1 keystone claims retrieval text is made "bizarrely cheap" by running it through
 * the governed AP-813 {@see CompressionPipeline}. This test holds that claim to a
 * concrete, measured bar: a realistic large retrieval block must come out MEANINGFULLY
 * smaller — output_chars <= input_chars * {@see self::ECONOMY_CEILING} (0.70), i.e. at
 * least 30% smaller. The token figure uses the same ~4-chars/token estimate the layer
 * itself reports (it is a relative figure, never billing).
 *
 * The pipeline is constructed DIRECTLY with an explicit config (enabled, CCR off so no
 * DB is touched, cache-aligner off so the measurement is pure block compression) — it
 * does NOT depend on the `ATLAS_COMPRESSION_LAYER_ENABLED` env, so the measurement is
 * deterministic regardless of test-suite defaults.
 *
 * MEASURED on 2026-06-09 (PHP 8.5.5) via {@see CodeGraphRetrievalCompressor}:
 *   - homogeneous LOG block          : output ratio 0.0845  (~91.6% saved)
 *   - homogeneous JSON-array block   : output ratio 0.3485  (~65.2% saved)
 *   - context-pack of boilerplate    : output ratio 0.5656  (~43.4% saved)
 *
 * The 0.70 ceiling is the canon's efficiency threshold; all three real inputs clear it,
 * the log/JSON cases with a wide margin. Anti-over-claim: these are the measured ratios,
 * not a target. If the pipeline stops compressing (regression / disabled compressor), the
 * ratio climbs toward 1.0 and this test fails loudly. The honest scope note from the
 * layer applies: the compressors are LOSSLESS-BY-GOVERNANCE (the original is recoverable
 * from the CCR store), so this economy is safe sampling of provably-redundant bulk — it
 * is NOT free on heterogeneous content, which is exactly what the fail-open cases below
 * prove (a block with no redundancy passes through at ratio 1.0).
 */
class CodeGraphEconomyFloorTest extends TestCase
{
    /** Output must be at most this fraction of the input (<= 70% → >= 30% saved). */
    private const ECONOMY_CEILING = 0.70;

    /**
     * A fully wired compressor over a REAL pipeline (all 5 compressors registered),
     * compression ON, CCR + cache-aligner OFF for a deterministic, DB-free measurement.
     * `min_block_chars` is lowered to 200 so the realistic-but-test-sized blocks below
     * are eligible (the prod default of 800 is about gate noise, not the economy math).
     */
    private function compressor(): CodeGraphRetrievalCompressor
    {
        $router = new ContentRouter([
            new SmartCrusherJsonCompressor,
            new LogCompressor,
            new SearchCompressor,
            new DiffCompressor,
            new TextCompressor,
        ]);

        $pipeline = new CompressionPipeline(
            $router,
            new AtlasCcrStore(null), // null ledger; CCR disabled below so no DB write
            new VolatileTokenRelocator,
            [
                'enabled' => true,
                'min_block_chars' => 200,
                'ccr' => ['enabled' => false],
                'cache_aligner' => ['enabled' => false],
            ],
        );

        return new CodeGraphRetrievalCompressor($pipeline);
    }

    /**
     * A realistic large log/test-runner retrieval block: 200 near-identical worker
     * heartbeats (differing only by timestamp) surrounding a single ERROR line. This is
     * the canonical homogeneous-bulk shape an MCP "show me the run output" retrieval
     * returns, and the LogCompressor collapses it hard while keeping the error verbatim.
     */
    private function largeLogBlock(): string
    {
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $ts = sprintf('2026-06-09 10:%02d:%02d', intdiv($i, 60), $i % 60);
            $lines[] = "[{$ts}] INFO worker heartbeat ok queue=default";
        }
        // A genuine signal line in the middle — must survive verbatim (proved below).
        $lines[80] = '[2026-06-09 10:01:20] ERROR job failed: timeout connecting to redis';

        return implode("\n", $lines);
    }

    /**
     * A realistic JSON-array retrieval block: 120 homogeneous symbol rows (a paginated
     * code-graph query result) with one anomaly row carrying an `error` key. The
     * SmartCrusher samples the bulk and keeps the anomaly unconditionally.
     */
    private function largeJsonBlock(): string
    {
        $rows = [];
        for ($i = 0; $i < 120; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => 'node_'.$i,
                'kind' => 'function',
                'visibility' => 'public',
                'line' => 10 + $i,
            ];
        }
        $rows[50] = [
            'id' => 50, 'name' => 'boom', 'kind' => 'function',
            'visibility' => 'public', 'line' => 60, 'error' => 'parse failed',
        ];

        return (string) json_encode($rows);
    }

    /**
     * THE HEADLINE FLOOR: a large homogeneous LOG retrieval compresses to <= 70% of its
     * input. Also asserts the report is self-consistent and the error line survives.
     */
    public function test_log_retrieval_meets_economy_floor(): void
    {
        $block = $this->largeLogBlock();
        $result = $this->compressor()->compress($block);

        $this->assertGreaterThan(0, $result['original_chars']);
        $this->assertTrue($result['changed'], 'A homogeneous log block must actually compress.');

        $outputRatio = $result['compressed_chars'] / $result['original_chars'];
        $this->assertLessThanOrEqual(
            self::ECONOMY_CEILING,
            $outputRatio,
            sprintf(
                'Log economy ratio %.4f exceeded ceiling %.2f (measured ~0.0845 on 2026-06-09). '
                .'Compression regressed; if scope changed, raise the ceiling to the new measured value and say so.',
                $outputRatio,
                self::ECONOMY_CEILING
            )
        );

        // Report internal consistency: compressed < original, saved math + token estimate.
        $this->assertLessThan($result['original_chars'], $result['compressed_chars']);
        $expectedSaved = (int) ceil(($result['original_chars'] - $result['compressed_chars']) / 4);
        $this->assertSame($expectedSaved, $result['approx_tokens_saved']);
        $this->assertGreaterThan(0, $result['approx_tokens_saved']);
        // `ratio` is the SAVED fraction (1 - outputRatio); it must agree with the chars.
        $this->assertEqualsWithDelta(1.0 - $outputRatio, $result['ratio'], 0.0001);

        // Lossless-by-construction signal survival: the ERROR line is kept verbatim.
        $this->assertStringContainsString(
            'ERROR job failed: timeout connecting to redis',
            $result['output'],
            'The compressor must keep the signal (error) line verbatim.'
        );
    }

    /**
     * The JSON-array retrieval path also clears the floor, and the anomaly row is kept.
     */
    public function test_json_retrieval_meets_economy_floor(): void
    {
        $block = $this->largeJsonBlock();
        $result = $this->compressor()->compress($block);

        $this->assertTrue($result['changed'], 'A homogeneous JSON array must actually compress.');

        $outputRatio = $result['compressed_chars'] / $result['original_chars'];
        $this->assertLessThanOrEqual(
            self::ECONOMY_CEILING,
            $outputRatio,
            sprintf(
                'JSON economy ratio %.4f exceeded ceiling %.2f (measured ~0.3485 on 2026-06-09).',
                $outputRatio,
                self::ECONOMY_CEILING
            )
        );

        // The anomaly row (error key) is preserved verbatim — economy never drops signal.
        $this->assertStringContainsString('parse failed', $result['output']);
    }

    /**
     * The E-3 context-pack path (compressPack → serialize → compress) also clears the
     * floor on realistic homogeneous nodes (repeated auto-generated boilerplate, a very
     * common real-codebase shape), and the node count is reported.
     */
    public function test_context_pack_retrieval_meets_economy_floor(): void
    {
        $included = [];
        for ($i = 0; $i < 80; $i++) {
            $included[] = [
                'id' => "App\\Generated\\Stub{$i}",
                // Repeated identical license/auto-gen header lines: provably redundant bulk.
                'content' => "<?php\n// AUTO-GENERATED — DO NOT EDIT\n// AUTO-GENERATED — DO NOT EDIT\n// AUTO-GENERATED — DO NOT EDIT\nreturn [];",
            ];
        }

        $result = $this->compressor()->compressPack(['included' => $included]);

        $this->assertSame(80, $result['nodes']);
        $this->assertTrue($result['changed'], 'A pack of boilerplate-heavy nodes must compress.');

        $outputRatio = $result['compressed_chars'] / $result['original_chars'];
        $this->assertLessThanOrEqual(
            self::ECONOMY_CEILING,
            $outputRatio,
            sprintf(
                'Context-pack economy ratio %.4f exceeded ceiling %.2f (measured ~0.5656 on 2026-06-09).',
                $outputRatio,
                self::ECONOMY_CEILING
            )
        );
    }

    /**
     * The exact measured ratios are asserted as upper bounds (with a small tolerance) so
     * a silent drift AWAY from the documented numbers is caught even while it stays under
     * the 0.70 ceiling — forcing the docblock measurements to be refreshed, not left
     * stale. Anti-over-claim: the documented economy is a real, reproducible figure.
     */
    public function test_measured_ratios_match_documented_values(): void
    {
        $c = $this->compressor();

        $log = $c->compress($this->largeLogBlock());
        $this->assertLessThanOrEqual(
            0.10,
            $log['compressed_chars'] / $log['original_chars'],
            'Documented log ratio is ~0.0845; if this drifts above 0.10 the docblock is stale.'
        );

        $json = $c->compress($this->largeJsonBlock());
        $this->assertLessThanOrEqual(
            0.40,
            $json['compressed_chars'] / $json['original_chars'],
            'Documented JSON ratio is ~0.3485; if this drifts above 0.40 the docblock is stale.'
        );
    }

    /**
     * FAIL-OPEN #1: when the layer is DISABLED, retrieval passes through byte-identical
     * (compression must never be a hidden mutation when off). Ratio is exactly 1.0.
     */
    public function test_disabled_layer_is_passthrough_identity(): void
    {
        $router = new ContentRouter([new LogCompressor, new TextCompressor]);
        $pipeline = new CompressionPipeline(
            $router,
            new AtlasCcrStore(null),
            new VolatileTokenRelocator,
            ['enabled' => false], // OFF
        );
        $compressor = new CodeGraphRetrievalCompressor($pipeline);

        $block = $this->largeLogBlock();
        $result = $compressor->compress($block);

        $this->assertFalse($result['changed']);
        $this->assertSame($block, $result['output'], 'Disabled layer returns the input unchanged.');
        $this->assertSame($result['original_chars'], $result['compressed_chars']);
        $this->assertSame(0, $result['approx_tokens_saved']);
        $this->assertSame(0.0, $result['ratio']);
    }

    /**
     * FAIL-OPEN #2: a HETEROGENEOUS block with no provable redundancy is passed through
     * unchanged (ratio 1.0). This is the honest counter-claim to the headline economy:
     * the layer only shrinks redundant bulk; it never invents savings on unique content.
     */
    public function test_non_redundant_input_is_passed_through_not_grown(): void
    {
        // 60 DISTINCT prose lines, each unique → nothing is provably redundant.
        $lines = [];
        for ($i = 0; $i < 60; $i++) {
            $lines[] = "Unique sentence number {$i} describing a different concept entirely, value=".bin2hex(pack('N', $i * 7919));
        }
        $block = implode("\n", $lines);

        $result = $this->compressor()->compress($block);

        $this->assertFalse($result['changed'], 'No redundancy → no compression.');
        $this->assertSame($block, $result['output']);
        // The compressor guarantees output is never LARGER than input.
        $this->assertLessThanOrEqual($result['original_chars'], $result['compressed_chars']);
    }

    /**
     * Empty input is a safe, zeroed no-op (never throws, never divides by zero).
     */
    public function test_empty_input_is_safe_zeroed(): void
    {
        $result = $this->compressor()->compress('');

        $this->assertSame('', $result['output']);
        $this->assertSame(0, $result['original_chars']);
        $this->assertSame(0, $result['compressed_chars']);
        $this->assertSame(0, $result['approx_tokens_saved']);
        $this->assertSame(0.0, $result['ratio']);
        $this->assertFalse($result['changed']);
    }
}
