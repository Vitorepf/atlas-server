<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Compression\CompressionPipeline;
use App\Services\Ai\Compression\CompressionResult;
use App\Services\Ai\Compression\ContentRouter;
use App\Services\Ai\Compression\Contracts\Compressor;
use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CompressionPipelineTest extends TestCase
{
    private function pipeline(Compressor $compressor, array $configOverride = []): CompressionPipeline
    {
        $config = array_replace_recursive([
            'enabled' => true,
            'min_block_chars' => 20,
            'cache_aligner' => ['enabled' => false],
            'ccr' => ['enabled' => false], // keep this unit pure (no DB)
        ], $configOverride);

        return new CompressionPipeline(
            new ContentRouter([$compressor]),
            new AtlasCcrStore(null, 'gzip'),
            new VolatileTokenRelocator,
            $config,
        );
    }

    private function halver(): Compressor
    {
        return new class implements Compressor
        {
            public function contentType(): string
            {
                return 'json';
            }

            public function detect(string $block): bool
            {
                return true;
            }

            public function compress(string $block, array $options = []): CompressionResult
            {
                $candidate = substr($block, 0, intdiv(strlen($block), 2))."\n[half]";

                return CompressionResult::compressed($block, $candidate, 'json', ['items_total' => 10, 'items_kept' => 5]);
            }
        };
    }

    public function test_disabled_returns_prompt_unchanged(): void
    {
        $prompt = "before\n```json\n".str_repeat('x', 80)."\n```\nafter";
        $result = $this->pipeline($this->halver(), ['enabled' => false])->transformPrompt($prompt);

        $this->assertSame($prompt, $result['prompt']);
        $this->assertFalse($result['report']['changed']);
    }

    public function test_compresses_a_fenced_block_and_leaves_a_marker(): void
    {
        $prompt = "intro text\n```json\n".str_repeat('x', 80)."\n```\noutro text";
        $result = $this->pipeline($this->halver())->transformPrompt($prompt);

        $this->assertTrue($result['report']['changed']);
        $this->assertSame(1, $result['report']['blocks_compressed']);
        $this->assertStringContainsString('[half]', $result['prompt']);
        $this->assertStringContainsString('[atlas:ccr 10 items compressed to 5', $result['prompt']);
        // Structure around the fence is preserved.
        $this->assertStringContainsString('intro text', $result['prompt']);
        $this->assertStringContainsString('outro text', $result['prompt']);
    }

    public function test_is_fail_open_when_a_compressor_throws(): void
    {
        $thrower = new class implements Compressor
        {
            public function contentType(): string
            {
                return 'json';
            }

            public function detect(string $block): bool
            {
                return true;
            }

            public function compress(string $block, array $options = []): CompressionResult
            {
                throw new RuntimeException('boom');
            }
        };

        $prompt = "x\n```json\n".str_repeat('y', 80)."\n```\nz";
        $result = $this->pipeline($thrower)->transformPrompt($prompt);

        // A throwing block is skipped; the prompt is returned intact, no exception.
        $this->assertSame($prompt, $result['prompt']);
        $this->assertSame(0, $result['report']['blocks_compressed']);
    }

    public function test_respects_min_block_chars(): void
    {
        // Fenced inner content shorter than min_block_chars -> not compressed.
        $prompt = "a\n```\ntiny\n```\nb";
        $result = $this->pipeline($this->halver(), ['min_block_chars' => 200])->transformPrompt($prompt);

        $this->assertSame($prompt, $result['prompt']);
        $this->assertFalse($result['report']['changed']);
    }

    public function test_leaves_unfenced_content_untouched(): void
    {
        // No fences -> the conservative live path does not touch it.
        $prompt = str_repeat('plain unfenced content. ', 50);
        $result = $this->pipeline($this->halver())->transformPrompt($prompt);

        $this->assertSame($prompt, $result['prompt']);
    }

    public function test_compress_block_compresses_a_raw_block(): void
    {
        $result = $this->pipeline($this->halver())->compressBlock(str_repeat('z', 80));

        $this->assertTrue($result['report']['changed']);
        $this->assertStringContainsString('[half]', $result['output']);
        $this->assertStringContainsString('[atlas:ccr', $result['output']);
    }

    public function test_cache_aligner_runs_when_enabled(): void
    {
        $prompt = 'trace 550e8400-e29b-41d4-a716-446655440000 then plenty of following text to work on';
        $result = $this->pipeline($this->halver(), ['cache_aligner' => ['enabled' => true]])->transformPrompt($prompt);

        $this->assertTrue($result['report']['changed']);
        $this->assertGreaterThan(0, $result['report']['relocated_tokens']);
        $this->assertStringContainsString('<<ctx1>>', $result['prompt']);
    }
}
