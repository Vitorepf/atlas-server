<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;

/**
 * Additive, transparent decorator over an {@see AiProvider} (AP-813).
 *
 * It transforms the OUTBOUND prompt through the {@see CompressionPipeline} before it
 * reaches the real provider — shrinking fenced data blocks and stabilizing the
 * cache prefix. Because compression is INPUT-only it is applied on BOTH run() and
 * runStreaming() (Hermes/Claude stream): shrinking the prompt never touches the
 * output stream, so — unlike response caching — streaming is safe to transform.
 *
 * key()/health() delegate verbatim. The pipeline is FAIL-OPEN, so on any error the
 * original prompt is forwarded unchanged and the provider behaves exactly as if the
 * decorator were absent.
 */
final class CompressionAiProvider implements AiProvider
{
    public function __construct(
        private readonly AiProvider $inner,
        private readonly CompressionPipeline $pipeline,
    ) {}

    public function key(): string
    {
        return $this->inner->key();
    }

    public function health(): AiProviderHealthCheck
    {
        return $this->inner->health();
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        return $this->inner->run($job, $this->transform($job, $prompt));
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        return $this->inner->runStreaming($job, $this->transform($job, $prompt), $onEvent);
    }

    private function transform(AiJob $job, string $prompt): string
    {
        $context = [
            'recorded_by' => 'atlas.compression.provider',
            'scope_type' => 'ai_job',
            'scope_id' => (string) ($job->trace_id ?? $job->getKey() ?? ''),
        ];

        $result = $this->pipeline->transformPrompt($prompt, $context);

        return is_string($result['prompt'] ?? null) ? $result['prompt'] : $prompt;
    }
}
