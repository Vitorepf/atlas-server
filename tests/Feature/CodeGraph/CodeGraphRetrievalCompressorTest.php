<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Compression\CompressionPipeline;
use App\Services\Ai\Compression\ContentRouter;
use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use App\Services\Engineering\CodeGraph\CodeGraphRetrievalCompressor;
use Tests\TestCase;

/**
 * AP-815 · E-1 — compression-on-retrieval wiring contract: passthrough when the layer is
 * off, never grows the payload when on, fails open, and serializes an E-3 pack. The actual
 * compression magnitude is proven by the AP-813 suite; this proves E-1 wires it SAFELY.
 */
final class CodeGraphRetrievalCompressorTest extends TestCase
{
    private function pipeline(bool $enabled): CompressionPipeline
    {
        // Mirror AppServiceProvider's resilient router build (real compressors, resolved
        // defensively) so the enabled path exercises the actual AP-813 pipeline.
        $router = new ContentRouter;
        foreach ([
            \App\Services\Ai\Compression\Compressors\SmartCrusherJsonCompressor::class,
            \App\Services\Ai\Compression\Compressors\LogCompressor::class,
            \App\Services\Ai\Compression\Compressors\SearchCompressor::class,
            \App\Services\Ai\Compression\Compressors\DiffCompressor::class,
            \App\Services\Ai\Compression\Compressors\TextCompressor::class,
        ] as $class) {
            if (class_exists($class)) {
                try {
                    $router->register(app($class));
                } catch (\Throwable) {
                }
            }
        }

        return new CompressionPipeline(
            $router,
            app(AtlasCcrStore::class),
            new VolatileTokenRelocator,
            [
                'enabled' => $enabled,
                'min_block_chars' => 20,
                'ccr' => ['enabled' => false], // no DB in this test
                'cache_aligner' => ['enabled' => true],
            ],
        );
    }

    public function test_passthrough_when_compression_disabled(): void
    {
        $svc = new CodeGraphRetrievalCompressor($this->pipeline(false));
        $text = str_repeat('the quick brown fox jumps over the lazy dog. ', 30);

        $out = $svc->compress($text);

        $this->assertSame($text, $out['output'], 'disabled layer must pass the text through untouched');
        $this->assertFalse($out['changed']);
        $this->assertSame(0, $out['approx_tokens_saved']);
        $this->assertSame($out['original_chars'], $out['compressed_chars']);
    }

    public function test_never_grows_the_payload_when_enabled(): void
    {
        $svc = new CodeGraphRetrievalCompressor($this->pipeline(true));
        $block = (string) json_encode(array_map(static fn (int $i): array => [
            'id' => $i, 'level' => 'INFO', 'msg' => 'request handled ok', 'ms' => 12,
        ], range(1, 80)));

        $out = $svc->compress($block);

        $this->assertIsString($out['output']);
        $this->assertNotSame('', $out['output']);
        $this->assertLessThanOrEqual($out['original_chars'], $out['compressed_chars'], 'compression-on-retrieval must never grow the payload');
        $this->assertGreaterThanOrEqual(0, $out['approx_tokens_saved']);
        $this->assertGreaterThanOrEqual(0.0, $out['ratio']);
        $this->assertLessThanOrEqual(1.0, $out['ratio']);
    }

    public function test_fail_open_on_empty(): void
    {
        $svc = new CodeGraphRetrievalCompressor($this->pipeline(true));
        $out = $svc->compress('');

        $this->assertSame('', $out['output']);
        $this->assertFalse($out['changed']);
        $this->assertSame(0, $out['approx_tokens_saved']);
    }

    public function test_compress_pack_serializes_included_nodes(): void
    {
        $svc = new CodeGraphRetrievalCompressor($this->pipeline(false));
        $pack = [
            'included' => [
                ['id' => 'sym:App\\Alpha', 'content' => 'class Alpha { public function run() {} }'],
                ['id' => 'sym:App\\Beta', 'signature' => 'function beta(): void'],
                'raw string node',
            ],
            'excluded' => [],
        ];

        $out = $svc->compressPack($pack);

        $this->assertSame(3, $out['nodes']);
        $this->assertStringContainsString('Alpha', $out['output']);
        $this->assertStringContainsString('beta', $out['output']);
        $this->assertStringContainsString('raw string node', $out['output']);
        $this->assertGreaterThan(0, $out['original_chars']);
    }
}
