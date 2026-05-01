<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderModelResolver;
use Illuminate\Support\Facades\Schema;

class AiCostEstimator
{
    public function __construct(private readonly AiProviderModelResolver $models)
    {
    }

    /**
     * @return array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int,estimated_tokens:?int,token_source:?string,cost_microusd:?int,cost_confidence:string,cost_source:string,cost_mode:string}
     */
    public function estimate(AiTrace $trace, ?AiJob $job = null): array
    {
        $usage = $this->actualUsage($trace, $job);
        $promptTokens = $usage['prompt_tokens'];
        $completionTokens = $usage['completion_tokens'];
        $totalTokens = $usage['total_tokens'];
        $tokenSource = $usage['source'];

        $estimatedTokens = null;
        if ($totalTokens === null) {
            $estimatedPrompt = $this->estimateTokens((string) ($job?->prompt ?: $trace->operator_input));
            $estimatedCompletion = $this->estimateTokens((string) ($trace->response_text ?: $job?->result_text));
            $estimatedTokens = $estimatedPrompt + $estimatedCompletion;
            $promptTokens = $estimatedPrompt;
            $completionTokens = $estimatedCompletion;
            $totalTokens = $estimatedTokens;
            $tokenSource = 'estimated_chars';
        }

        $provider = $job?->provider ?: $trace->provider;
        $model = $this->models->resolve($provider, $job?->model ?: $trace->model);
        $rate = $this->rateFor($provider, $model);
        if (! $rate) {
            return [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_tokens' => $estimatedTokens,
                'token_source' => $tokenSource,
                'cost_microusd' => null,
                'cost_confidence' => 'unknown',
                'cost_source' => $this->costSource($provider, $tokenSource, false),
                'cost_mode' => 'unknown',
            ];
        }

        $cost = (int) round(
            (($promptTokens ?? 0) / 1000) * $rate->input_microusd_per_1k
            + (($completionTokens ?? 0) / 1000) * $rate->output_microusd_per_1k
        );

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'estimated_tokens' => $estimatedTokens,
            'token_source' => $tokenSource,
            'cost_microusd' => $cost,
            'cost_confidence' => $this->costConfidence($provider, $estimatedTokens),
            'cost_source' => $this->costSource($provider, $tokenSource, true),
            'cost_mode' => $this->costMode($provider, $estimatedTokens),
        ];
    }

    /**
     * @return array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int,source:?string}
     */
    private function actualUsage(AiTrace $trace, ?AiJob $job): array
    {
        $sources = [
            $trace->metadata ?? [],
            $job?->metadata ?? [],
            $job?->result_json ?? [],
            $job?->payload ?? [],
        ];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $prompt = $this->positiveInt(data_get($source, 'usage.prompt_tokens'))
                ?? $this->positiveInt(data_get($source, 'token_usage.prompt_tokens'))
                ?? $this->positiveInt(data_get($source, 'prompt_tokens'));
            $completion = $this->positiveInt(data_get($source, 'usage.completion_tokens'))
                ?? $this->positiveInt(data_get($source, 'token_usage.completion_tokens'))
                ?? $this->positiveInt(data_get($source, 'completion_tokens'));
            $total = $this->positiveInt(data_get($source, 'usage.total_tokens'))
                ?? $this->positiveInt(data_get($source, 'token_usage.total_tokens'))
                ?? $this->positiveInt(data_get($source, 'total_tokens'));

            if ($prompt !== null || $completion !== null || $total !== null) {
                $total ??= ($prompt ?? 0) + ($completion ?? 0);

                return [
                    'prompt_tokens' => $prompt,
                    'completion_tokens' => $completion,
                    'total_tokens' => $total,
                    'source' => 'provider_usage',
                ];
            }
        }

        return [
            'prompt_tokens' => null,
            'completion_tokens' => null,
            'total_tokens' => null,
            'source' => null,
        ];
    }

    private function rateFor(?string $provider, ?string $model): ?AiProviderCostRate
    {
        if (! $provider || ! $model || ! Schema::hasTable('ai_provider_cost_rates')) {
            return null;
        }

        return AiProviderCostRate::query()
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('effective_from', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function costConfidence(?string $provider, ?int $estimatedTokens): string
    {
        if ($this->isCliProvider($provider)) {
            return 'estimated';
        }

        return $estimatedTokens === null ? 'actual' : 'estimated';
    }

    private function costSource(?string $provider, ?string $tokenSource, bool $hasRate): string
    {
        if (! $hasRate) {
            return 'missing_cost_rate';
        }

        if ($this->isCliProvider($provider)) {
            return $tokenSource === 'provider_usage'
                ? 'cli_provider_usage_estimate'
                : 'cli_token_estimate';
        }

        return $tokenSource === 'provider_usage' ? 'provider_usage_rate' : 'estimated_chars_rate';
    }

    private function costMode(?string $provider, ?int $estimatedTokens): string
    {
        if ($this->isCliProvider($provider) || $estimatedTokens !== null) {
            return 'operational_estimate';
        }

        return 'metered_estimate';
    }

    private function isCliProvider(?string $provider): bool
    {
        return in_array($provider, ['claude_cli', 'codex_cli', 'claude_codex'], true);
    }

    private function estimateTokens(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value >= 0 ? $value : null;
    }
}
