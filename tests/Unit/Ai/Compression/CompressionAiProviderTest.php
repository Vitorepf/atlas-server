<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Compression\CompressionAiProvider;
use App\Services\Ai\Compression\CompressionPipeline;
use App\Services\Ai\Compression\ContentRouter;
use App\Services\Ai\Compression\Support\VolatileTokenRelocator;
use PHPUnit\Framework\TestCase;

final class CompressionAiProviderTest extends TestCase
{
    /** A capturing inner provider: records the prompt it actually received. */
    private function capturingInner(): AiProvider
    {
        return new class implements AiProvider
        {
            public ?string $seen = null;

            public function key(): string
            {
                return 'stub-provider';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                $this->seen = $prompt;

                return new AiProviderResult(true, 'ok', [], 0, 0, '', '', null, null, []);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                $this->seen = $prompt;

                return new AiProviderResult(true, 'ok', [], 0, 0, '', '', null, null, []);
            }

            public function health(): AiProviderHealthCheck
            {
                throw new \RuntimeException('not used in this test');
            }
        };
    }

    private function pipeline(bool $enabled): CompressionPipeline
    {
        return new CompressionPipeline(
            new ContentRouter,
            new AtlasCcrStore(null, 'gzip'),
            new VolatileTokenRelocator,
            ['enabled' => $enabled, 'cache_aligner' => ['enabled' => true], 'ccr' => ['enabled' => false]],
        );
    }

    public function test_key_delegates_to_inner(): void
    {
        $decorator = new CompressionAiProvider($this->capturingInner(), $this->pipeline(true));
        $this->assertSame('stub-provider', $decorator->key());
    }

    public function test_run_transforms_the_prompt_when_enabled(): void
    {
        $inner = $this->capturingInner();
        $decorator = new CompressionAiProvider($inner, $this->pipeline(true));

        $decorator->run(new AiJob(['trace_id' => 't-1']), 'id 550e8400-e29b-41d4-a716-446655440000 plus trailing work text here');

        $this->assertNotNull($inner->seen);
        $this->assertStringContainsString('<<ctx1>>', (string) $inner->seen);
    }

    public function test_run_streaming_also_transforms_the_prompt(): void
    {
        $inner = $this->capturingInner();
        $decorator = new CompressionAiProvider($inner, $this->pipeline(true));

        $decorator->runStreaming(new AiJob(['trace_id' => 't-2']), 'uuid 550e8400-e29b-41d4-a716-446655440000 then a long enough body of work');

        $this->assertNotNull($inner->seen);
        $this->assertStringContainsString('<<ctx1>>', (string) $inner->seen);
    }

    public function test_passes_prompt_unchanged_when_disabled(): void
    {
        $inner = $this->capturingInner();
        $decorator = new CompressionAiProvider($inner, $this->pipeline(false));
        $prompt = 'id 550e8400-e29b-41d4-a716-446655440000 unchanged';

        $decorator->run(new AiJob(['trace_id' => 't-3']), $prompt);

        $this->assertSame($prompt, $inner->seen);
    }
}
