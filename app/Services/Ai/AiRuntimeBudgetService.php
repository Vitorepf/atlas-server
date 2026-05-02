<?php

namespace App\Services\Ai;

use App\Models\AiTraceMetricSummary;
use Illuminate\Support\Facades\Schema;

class AiRuntimeBudgetService
{
    public function __construct(
        private readonly AtlasAiRuntimeSettings $settings,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        $budget = $this->settings->effective()['budget'] ?? [];
        $windowHours = (int) ($budget['window_hours'] ?? 24);
        $providers = [];

        foreach ($this->providerKeys() as $provider) {
            $providers[] = $this->providerPayload($provider, $budget, $windowHours);
        }

        $totalUsage = $this->usage(null, null, $windowHours);
        $max = $this->positiveInt($budget['max_visible_tokens'] ?? null);
        $warn = $this->positiveInt($budget['warn_visible_tokens'] ?? null);

        return [
            'available' => Schema::hasTable('ai_trace_metric_summaries'),
            'enabled' => (bool) ($budget['enabled'] ?? false),
            'mode' => (string) ($budget['mode'] ?? 'block'),
            'window_hours' => $windowHours,
            'totals' => [
                ...$totalUsage,
                'max_visible_tokens' => $max,
                'warn_visible_tokens' => $warn,
                'remaining_visible_tokens' => $max !== null ? max(0, $max - $totalUsage['visible_tokens']) : null,
                'status' => $this->statusFor($totalUsage['visible_tokens'], $max, $warn),
            ],
            'providers' => $providers,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function assertAllows(string $provider, ?string $model, array $options = []): void
    {
        $budget = $this->settings->effective()['budget'] ?? [];
        if (! (bool) ($budget['enabled'] ?? false) || ($budget['mode'] ?? 'block') !== 'block') {
            return;
        }

        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        $windowHours = (int) ($budget['window_hours'] ?? 24);
        $usage = $this->usage($provider, null, $windowHours);
        $max = $this->limitFor($budget, $provider, 'max_visible_tokens');
        if ($max === null || $usage['visible_tokens'] < $max) {
            return;
        }

        $label = $provider === 'codex_cli' ? 'Codex' : ($provider === 'claude_cli' ? 'Claude' : $provider);
        throw new \RuntimeException("Atlas bloqueou {$label}: budget de {$max} tokens visiveis em {$windowHours}h ja foi atingido ({$usage['visible_tokens']}). Ajuste o limite em Configuracoes > Atlas.");
    }

    /**
     * @return array<int,string>
     */
    private function providerKeys(): array
    {
        return collect(['claude_cli', 'codex_cli'])
            ->merge(array_keys((array) config('atlas.ai.providers', [])))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $budget
     * @return array<string,mixed>
     */
    private function providerPayload(string $provider, array $budget, int $windowHours): array
    {
        $usage = $this->usage($provider, null, $windowHours);
        $max = $this->limitFor($budget, $provider, 'max_visible_tokens');
        $warn = $this->limitFor($budget, $provider, 'warn_visible_tokens');

        return [
            'provider' => $provider,
            ...$usage,
            'max_visible_tokens' => $max,
            'warn_visible_tokens' => $warn,
            'remaining_visible_tokens' => $max !== null ? max(0, $max - $usage['visible_tokens']) : null,
            'status' => $this->statusFor($usage['visible_tokens'], $max, $warn),
        ];
    }

    /**
     * @return array{visible_tokens:int,total_tokens:int,estimated_tokens:int,traces:int}
     */
    private function usage(?string $provider, ?string $model, int $windowHours): array
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            return [
                'visible_tokens' => 0,
                'total_tokens' => 0,
                'estimated_tokens' => 0,
                'traces' => 0,
            ];
        }

        $query = AiTraceMetricSummary::query()
            ->where('computed_at', '>=', now()->subHours($windowHours));

        if ($provider !== null) {
            $query->where('provider', $provider);
        }

        if ($model !== null) {
            $query->where('model', $model);
        }

        $row = $query
            ->selectRaw('COUNT(*) AS traces')
            ->selectRaw('SUM(COALESCE(total_tokens, 0)) AS total_tokens')
            ->selectRaw('SUM(COALESCE(estimated_tokens, 0)) AS estimated_tokens')
            ->selectRaw('SUM(COALESCE(total_tokens, estimated_tokens, 0)) AS visible_tokens')
            ->first();

        return [
            'visible_tokens' => (int) ($row?->visible_tokens ?? 0),
            'total_tokens' => (int) ($row?->total_tokens ?? 0),
            'estimated_tokens' => (int) ($row?->estimated_tokens ?? 0),
            'traces' => (int) ($row?->traces ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $budget
     */
    private function limitFor(array $budget, string $provider, string $key): ?int
    {
        $providerLimit = $this->positiveInt(data_get($budget, "providers.{$provider}.{$key}"));

        return $providerLimit ?? $this->positiveInt($budget[$key] ?? null);
    }

    private function statusFor(int $used, ?int $max, ?int $warn): string
    {
        if ($max !== null && $used >= $max) {
            return 'blocked';
        }

        if ($warn !== null && $used >= $warn) {
            return 'warning';
        }

        return 'ok';
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
