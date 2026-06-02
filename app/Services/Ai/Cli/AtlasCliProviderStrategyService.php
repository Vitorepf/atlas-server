<?php

namespace App\Services\Ai\Cli;

use App\Models\AiProviderHealthSnapshot;
use App\Services\Ai\AiRuntimeBudgetService;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use Illuminate\Support\Facades\Schema;

class AtlasCliProviderStrategyService
{
    public function __construct(
        private readonly AtlasAiRuntimeSettings $settings,
        private readonly AiRuntimeBudgetService $budgets,
        private readonly ProviderPerformanceProjection $performance,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function recommend(string $mode = 'direct', bool $critical = false): array
    {
        $mode = in_array($mode, ['direct', 'plan', 'review', 'dev', 'debug', 'research'], true) ? $mode : 'direct';
        $providers = $this->providers();
        $preferred = $this->preferredOrder($mode);
        $autoEligible = fn (array $provider): bool => $this->allowsAuto((string) ($provider['provider'] ?? ''))
            && $this->budgetAllows((string) ($provider['provider'] ?? ''));
        $online = collect($providers)
            ->filter(fn (array $provider): bool => ($provider['status'] ?? null) === 'online')
            ->filter($autoEligible);
        $eligibleProviders = collect($providers)->filter($autoEligible);

        if ($critical && $online->whereIn('provider', ['claude_cli', 'codex_cli'])->pluck('provider')->unique()->count() >= 2) {
            return [
                'mode' => $mode,
                'critical' => true,
                'has_online_provider' => true,
                'recommended_provider' => 'claude_codex',
                'reason' => 'Tarefa critica: Claude e Codex online permitem dual-review/conselho.',
                'fallback_provider' => $this->firstAvailable($online, $preferred),
                'providers' => $providers,
                'empirical_performance' => $this->empiricalPerformance($mode),
            ];
        }

        $strictMode = in_array($mode, ['dev', 'debug'], true);
        $recommended = $this->firstAvailable($online, $preferred, $strictMode)
            ?: $this->firstAvailable($eligibleProviders, $preferred, $strictMode)
            ?: $this->firstConfiguredAutoProvider($preferred)
            ?: $this->settings->defaultProvider();

        return [
            'mode' => $mode,
            'critical' => $critical,
            'has_online_provider' => $online->isNotEmpty(),
            'recommended_provider' => $recommended,
            'reason' => $this->reason($recommended, $mode, $providers),
            'fallback_provider' => $this->fallback($recommended, $providers, $mode),
            'providers' => $providers,
            'empirical_performance' => $this->empiricalPerformance($mode),
            'policy' => [
                'automatic_respects_app_settings' => true,
                'default_provider' => $this->settings->defaultProvider(),
                'budget_mode' => data_get($this->budgets->payload(), 'mode'),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function providers(): array
    {
        if (! Schema::hasTable('ai_provider_health_snapshots')) {
            return [];
        }

        return AiProviderHealthSnapshot::query()
            ->orderByDesc('checked_at')
            ->limit(50)
            ->get()
            ->unique('provider')
            ->map(function (AiProviderHealthSnapshot $snapshot): array {
                $provider = (string) $snapshot->provider;

                return [
                    'provider' => $provider,
                    'status' => $snapshot->status,
                    'pain' => $snapshot->operational_pain_score,
                    'p50_latency_ms' => $snapshot->p50_latency_ms,
                    'checked_at' => $snapshot->checked_at?->toJSON(),
                    'message' => $snapshot->message,
                    'allow_auto' => (bool) ($this->settings->providerConfig($provider)['allow_auto'] ?? true),
                    'allow_manual' => (bool) ($this->settings->providerConfig($provider)['allow_manual'] ?? true),
                    'budget_allows' => $this->budgetAllows($provider),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function preferredOrder(string $mode): array
    {
        $defaultProvider = $this->defaultProvider();
        $devDefault = $defaultProvider === 'gemini_cli' ? null : $defaultProvider;

        return match ($mode) {
            'dev', 'debug' => $this->uniqueProviders([$devDefault, 'hermes_cli', 'minimax_m27_cli', 'codex_cli', 'claude_cli']),
            'review', 'plan', 'research' => $this->uniqueProviders([$defaultProvider, 'hermes_cli', 'minimax_m27_cli', 'claude_cli', 'gemini_cli', 'codex_cli']),
            default => $this->uniqueProviders([$defaultProvider, 'hermes_cli', 'minimax_m27_cli', 'claude_cli', 'codex_cli', 'gemini_cli']),
        };
    }

    private function defaultProvider(): string
    {
        return $this->settings->defaultProvider();
    }

    /**
     * @param  array<int,string>  $providers
     * @return array<int,string>
     */
    private function uniqueProviders(array $providers): array
    {
        return array_values(array_unique(array_filter(
            $providers,
            fn (?string $provider): bool => is_string($provider) && in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli', 'hermes_cli', 'minimax_m27_cli'], true),
        )));
    }

    private function firstAvailable($providers, array $preferred, bool $strictPreferred = false): ?string
    {
        foreach ($preferred as $provider) {
            if ($providers->contains(fn (array $item): bool => ($item['provider'] ?? null) === $provider)) {
                return $provider;
            }
        }

        if ($strictPreferred) {
            return null;
        }

        $leastPain = $providers
            ->sortBy([
                ['pain', 'asc'],
                ['p50_latency_ms', 'asc'],
            ])
            ->first();

        return is_array($leastPain) ? (string) $leastPain['provider'] : null;
    }

    private function fallback(string $recommended, array $providers, string $mode): ?string
    {
        return collect($providers)
            ->reject(fn (array $provider): bool => ($provider['provider'] ?? null) === $recommended)
            ->reject(fn (array $provider): bool => in_array($mode, ['dev', 'debug'], true) && ($provider['provider'] ?? null) === 'gemini_cli')
            ->filter(fn (array $provider): bool => $this->allowsAuto((string) ($provider['provider'] ?? ''))
                && $this->budgetAllows((string) ($provider['provider'] ?? '')))
            ->sortBy([
                ['status', 'asc'],
                ['pain', 'asc'],
                ['p50_latency_ms', 'asc'],
            ])
            ->value('provider');
    }

    private function reason(string $recommended, string $mode, array $providers): string
    {
        $default = $this->settings->defaultProvider();
        if ($recommended !== $default && ! $this->allowsAuto($default)) {
            return "Default {$default} esta bloqueado para automatico no app; usando {$recommended} para modo {$mode}.";
        }

        if ($recommended !== $default && ! $this->budgetAllows($default)) {
            return "Default {$default} esta bloqueado pelo budget em modo block; usando {$recommended} para modo {$mode}.";
        }

        $health = collect($providers)->first(fn (array $provider): bool => ($provider['provider'] ?? null) === $recommended);
        if (! $health) {
            return "Sem snapshot de provider; usando configuracao/default para modo {$mode}.";
        }

        $status = $health['status'] ?? 'unknown';
        $pain = $health['pain'] ?? 'n/a';

        if (collect($providers)->where('status', 'online')->isEmpty()) {
            return "Nenhum provider online; preferencia estrutural para modo {$mode}: {$recommended}. Rode atlas bootstrap --refresh-providers para diagnosticar e configurar os binarios.";
        }

        return "Provider recomendado para modo {$mode}: {$recommended}; status={$status}, pain={$pain}.";
    }

    private function allowsAuto(string $provider): bool
    {
        if (! in_array($provider, ['claude_cli', 'codex_cli', 'gemini_cli', 'hermes_cli', 'minimax_m27_cli'], true)) {
            return false;
        }

        return (bool) ($this->settings->providerConfig($provider)['allow_auto'] ?? true);
    }

    private function budgetAllows(string $provider): bool
    {
        $budget = $this->budgets->payload();
        if (! (bool) ($budget['enabled'] ?? false) || ($budget['mode'] ?? 'block') !== 'block') {
            return true;
        }

        $row = collect((array) ($budget['providers'] ?? []))
            ->first(fn (array $item): bool => ($item['provider'] ?? null) === $provider);

        return ! is_array($row) || ($row['status'] ?? null) !== 'blocked';
    }

    /**
     * @return array<string,mixed>
     */
    private function empiricalPerformance(string $mode): array
    {
        $domain = in_array($mode, ['dev', 'debug'], true) ? 'programming' : null;
        $taskType = match ($mode) {
            'review' => 'review',
            'research' => 'research',
            'plan' => 'plan',
            default => null,
        };

        return $this->performance->reportForWindow(now()->subHours(168), filters: array_filter([
            'domain' => $domain,
            'task_type' => $taskType,
        ]));
    }

    /**
     * @param  array<int,string>  $preferred
     */
    private function firstConfiguredAutoProvider(array $preferred): ?string
    {
        foreach ($preferred as $provider) {
            if ($this->allowsAuto($provider) && $this->budgetAllows($provider)) {
                return $provider;
            }
        }

        return null;
    }
}
