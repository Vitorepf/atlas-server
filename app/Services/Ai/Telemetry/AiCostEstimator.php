<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Models\AiTrace;
use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\AiProviderResult;
use Illuminate\Support\Facades\Schema;

class AiCostEstimator
{
    /**
     * cost_confidence vocabulary.
     *
     * METERED:   tokens reported by the provider × manually-configured rate.
     *            Closest the system gets to a real bill — but still an estimate
     *            because the rate is a snapshot configured by the operator,
     *            not the provider's invoiced amount.
     * ESTIMATED: tokens estimated from chars OR provider is a CLI placeholder.
     * UNKNOWN:   no rate available; cost is null.
     *
     * Legacy alias: 'actual' meant METERED but the name implied invoice-grade
     * accuracy, which it never had. Readers must accept both 'actual' and
     * 'metered' until the data migration ('actual' → 'metered') has run on
     * every environment.
     */
    public const COST_CONFIDENCE_METERED = 'metered';

    public const COST_CONFIDENCE_ESTIMATED = 'estimated';

    public const COST_CONFIDENCE_UNKNOWN = 'unknown';

    /**
     * Legacy value still recognized on read for back-compat. Migration
     * 2026_05_01_004000_normalize_cost_confidence_actual_to_metered rewrites
     * stored rows. Retire once the migration has run on every environment.
     */
    public const LEGACY_COST_CONFIDENCE_ACTUAL = 'actual';

    /** @var array<int,string> */
    public const COST_CONFIDENCE_METERED_ACCEPTED = [
        self::COST_CONFIDENCE_METERED,
        self::LEGACY_COST_CONFIDENCE_ACTUAL,
    ];

    public function __construct(private readonly AiProviderModelResolver $models) {}

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
        if ($this->isProviderNotApplicableLedgerProjection($trace, $job)) {
            return [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_tokens' => $estimatedTokens,
                'token_source' => $tokenSource,
                'cost_microusd' => null,
                'cost_confidence' => self::COST_CONFIDENCE_ESTIMATED,
                'cost_source' => 'provider_not_applicable',
                'cost_mode' => 'not_applicable',
            ];
        }

        $rate = $this->rateFor($provider, $model);
        if (! $rate) {
            return [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_tokens' => $estimatedTokens,
                'token_source' => $tokenSource,
                'cost_microusd' => null,
                'cost_confidence' => self::COST_CONFIDENCE_UNKNOWN,
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
     * @return array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int,estimated_tokens:?int,token_source:?string,cost_microusd:?int,cost_confidence:string,cost_source:string,cost_mode:string}
     */
    public function estimateProviderResult(AiJob $job, ?AiProviderResult $result = null, ?string $providerOverride = null, ?string $modelOverride = null): array
    {
        $usage = $this->providerResultUsage($job, $result);
        $promptTokens = $usage['prompt_tokens'];
        $completionTokens = $usage['completion_tokens'];
        $totalTokens = $usage['total_tokens'];
        $tokenSource = $usage['source'];

        $estimatedTokens = null;
        if ($totalTokens === null) {
            $estimatedPrompt = $this->estimateTokens((string) ($job->prompt ?: $job->input_text));
            $estimatedCompletion = $this->estimateTokens((string) ($result?->output ?: $job->result_text));
            $estimatedTokens = $estimatedPrompt + $estimatedCompletion;
            $promptTokens = $estimatedPrompt;
            $completionTokens = $estimatedCompletion;
            $totalTokens = $estimatedTokens;
            $tokenSource = 'estimated_chars';
        }

        $provider = $providerOverride ?: $job->provider;
        $model = $this->models->resolve($provider, $modelOverride ?: $job->model);
        $rate = $this->rateFor($provider, $model);
        if (! $rate) {
            return [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'estimated_tokens' => $estimatedTokens,
                'token_source' => $tokenSource,
                'cost_microusd' => null,
                'cost_confidence' => self::COST_CONFIDENCE_UNKNOWN,
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

    /**
     * @return array{prompt_tokens:?int,completion_tokens:?int,total_tokens:?int,source:?string}
     */
    private function providerResultUsage(AiJob $job, ?AiProviderResult $result): array
    {
        $sources = [
            $result?->metadata ?? [],
            $job->metadata ?? [],
            $job->result_json ?? [],
            $job->payload ?? [],
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
            return self::COST_CONFIDENCE_ESTIMATED;
        }

        return $estimatedTokens === null
            ? self::COST_CONFIDENCE_METERED
            : self::COST_CONFIDENCE_ESTIMATED;
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
        return in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli', 'claude_codex'], true);
    }

    private function isProviderNotApplicableLedgerProjection(AiTrace $trace, ?AiJob $job): bool
    {
        $provider = $job?->provider ?: $trace->provider;
        $model = $job?->model ?: $trace->model;

        if ($provider || $model) {
            return false;
        }

        $metadata = is_array($trace->metadata) ? $trace->metadata : [];

        return data_get($metadata, 'schema_version') === 'atlas.ledger_projection.metadata.v1'
            && data_get($metadata, 'projection_id') === 'ai_traces';
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
