<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compression;

use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Compression\CompressionPipeline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * End-to-end proof of the whole AP-813 layer wired through the container:
 * config -> CompressionPipeline (real ContentRouter + the 5 DI-registered
 * compressors) -> AtlasCcrStore -> atlas_ccr_retrieve round-trip. This is the
 * integration counterpart to the per-compressor unit tests.
 */
final class CompressionLayerIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ccr_originals');
        Schema::create('atlas_ccr_originals', function (Blueprint $table) {
            $table->id();
            $table->string('original_hash', 64)->unique();
            $table->string('content_type', 40)->default('text');
            $table->string('codec', 16)->default('gzip');
            $table->unsignedBigInteger('original_bytes')->default(0);
            $table->unsignedBigInteger('compressed_bytes')->default(0);
            $table->longText('compressed_blob');
            $table->string('privacy_class', 40)->default('internal');
            $table->string('ledger_event_id', 32)->nullable()->index();
            $table->string('scope_type', 40)->nullable();
            $table->string('scope_id', 80)->nullable();
            $table->string('recorded_by', 120)->default('atlas.compression');
            $table->unsignedInteger('retrieved_count')->default(0);
            $table->timestamp('last_retrieved_at')->nullable();
            $table->timestamps();
            $table->index(['content_type', 'created_at']);
        });

        config()->set('atlas.compression_layer', [
            'enabled' => true,
            'min_block_chars' => 200,
            'cache_aligner' => ['enabled' => true],
            'ccr' => ['enabled' => true, 'codec' => 'gzip'],
            'smart_crusher' => ['keep_head' => 8, 'keep_tail' => 4, 'max_keep' => 40],
            'compressors' => ['json' => true, 'log' => true, 'search' => true, 'diff' => true, 'text' => true],
        ]);

        // Force fresh resolution under the test config.
        app()->forgetInstance(CompressionPipeline::class);
        app()->forgetInstance(AtlasCcrStore::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ccr_originals');
        parent::tearDown();
    }

    public function test_container_wires_all_five_compressors(): void
    {
        $pipeline = app(CompressionPipeline::class);
        $this->assertInstanceOf(CompressionPipeline::class, $pipeline);
        $this->assertTrue($pipeline->enabled());
    }

    public function test_fenced_json_tool_output_compresses_and_ccr_round_trips_lossless(): void
    {
        $pipeline = app(CompressionPipeline::class);

        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['id' => $i, 'status' => 'ok', 'value' => 'homogeneous-row-payload'];
        }
        $rows[] = ['id' => 999, 'status' => 'error', 'message' => 'boom-unique-anomaly-marker'];
        $json = json_encode($rows, JSON_PRETTY_PRINT);

        $prompt = "Here is the tool output:\n```json\n{$json}\n```\nNow analyze it.";
        $result = $pipeline->transformPrompt($prompt);

        // It compressed exactly one fenced block and the prompt shrank.
        $this->assertTrue($result['report']['changed']);
        $this->assertSame(1, $result['report']['blocks_compressed']);
        $this->assertLessThan(strlen($prompt), strlen($result['prompt']));

        // The anomaly survives verbatim in the compressed prompt (lossless signal).
        $this->assertStringContainsString('boom-unique-anomaly-marker', $result['prompt']);
        // A retrieval marker + hash were left behind.
        $this->assertMatchesRegularExpression('/atlas:ccr/', $result['prompt']);
        $this->assertSame(1, preg_match('/hash=([a-f0-9]{64})/', $result['prompt'], $m));

        // CCR round-trip: the ORIGINAL block is recoverable EXACTLY (lossless-by-governance).
        $store = app(AtlasCcrStore::class);
        $retrieved = $store->retrieve($m[1]);
        $this->assertTrue($retrieved['found']);
        $this->assertStringContainsString($json, (string) $retrieved['original']);
    }

    public function test_fenced_log_block_keeps_errors_and_round_trips(): void
    {
        $pipeline = app(CompressionPipeline::class);

        $lines = [];
        for ($i = 0; $i < 80; $i++) {
            $lines[] = '2026-06-08 10:00:'.str_pad((string) ($i % 60), 2, '0', STR_PAD_LEFT).' INFO heartbeat ok tick';
        }
        $lines[] = '2026-06-08 10:05:00 ERROR unique-failure-signature-xyz at Service::boom()';
        $log = implode("\n", $lines);

        $prompt = "Build log:\n```\n{$log}\n```\nDiagnose.";
        $result = $pipeline->transformPrompt($prompt);

        $this->assertTrue($result['report']['changed']);
        $this->assertLessThan(strlen($prompt), strlen($result['prompt']));
        // The single ERROR line is never dropped.
        $this->assertStringContainsString('unique-failure-signature-xyz', $result['prompt']);
    }

    public function test_disabled_layer_is_a_passthrough(): void
    {
        config()->set('atlas.compression_layer.enabled', false);
        app()->forgetInstance(CompressionPipeline::class);

        $pipeline = app(CompressionPipeline::class);
        $rows = array_fill(0, 60, ['id' => 1, 'status' => 'ok']);
        $prompt = "x\n```json\n".json_encode($rows)."\n```\ny";

        $result = $pipeline->transformPrompt($prompt);

        $this->assertSame($prompt, $result['prompt']);
        $this->assertFalse($result['report']['changed']);
    }
}
