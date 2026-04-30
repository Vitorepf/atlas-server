<?php

namespace App\Services\Ai\Cli;

use App\Models\AiProviderHealthSnapshot;
use Illuminate\Support\Facades\Schema;

class AtlasCliProviderStrategyService
{
    /**
     * @return array<string,mixed>
     */
    public function recommend(string $mode = 'direct', bool $critical = false): array
    {
        $mode = in_array($mode, ['direct', 'plan', 'review', 'dev', 'debug', 'research'], true) ? $mode : 'direct';
        $providers = $this->providers();
        $preferred = $this->preferredOrder($mode);
        $online = collect($providers)->filter(fn (array $provider): bool => ($provider['status'] ?? null) === 'online');

        if ($critical && $online->whereIn('provider', ['claude_cli', 'codex_cli'])->pluck('provider')->unique()->count() >= 2) {
            return [
                'mode' => $mode,
                'critical' => true,
                'has_online_provider' => true,
                'recommended_provider' => 'claude_codex',
                'reason' => 'Tarefa critica: Claude e Codex online permitem dual-review/conselho.',
                'fallback_provider' => $this->firstAvailable($online, $preferred),
                'providers' => $providers,
            ];
        }

        $recommended = $this->firstAvailable($online, $preferred)
            ?: $this->firstAvailable(collect($providers), $preferred)
            ?: (string) config('atlas.ai.default_provider', 'claude_cli');

        return [
            'mode' => $mode,
            'critical' => $critical,
            'has_online_provider' => $online->isNotEmpty(),
            'recommended_provider' => $recommended,
            'reason' => $this->reason($recommended, $mode, $providers),
            'fallback_provider' => $this->fallback($recommended, $providers),
            'providers' => $providers,
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
            ->map(fn (AiProviderHealthSnapshot $snapshot): array => [
                'provider' => $snapshot->provider,
                'status' => $snapshot->status,
                'pain' => $snapshot->operational_pain_score,
                'p50_latency_ms' => $snapshot->p50_latency_ms,
                'checked_at' => $snapshot->checked_at?->toJSON(),
                'message' => $snapshot->message,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function preferredOrder(string $mode): array
    {
        return match ($mode) {
            'dev', 'debug' => ['codex_cli', 'claude_cli'],
            'review', 'plan', 'research' => ['claude_cli', 'codex_cli'],
            default => [(string) config('atlas.ai.default_provider', 'claude_cli'), 'claude_cli', 'codex_cli'],
        };
    }

    private function firstAvailable($providers, array $preferred): ?string
    {
        foreach ($preferred as $provider) {
            if ($providers->contains(fn (array $item): bool => ($item['provider'] ?? null) === $provider)) {
                return $provider;
            }
        }

        $leastPain = $providers
            ->sortBy([
                ['pain', 'asc'],
                ['p50_latency_ms', 'asc'],
            ])
            ->first();

        return is_array($leastPain) ? (string) $leastPain['provider'] : null;
    }

    private function fallback(string $recommended, array $providers): ?string
    {
        return collect($providers)
            ->reject(fn (array $provider): bool => ($provider['provider'] ?? null) === $recommended)
            ->sortBy([
                ['status', 'asc'],
                ['pain', 'asc'],
                ['p50_latency_ms', 'asc'],
            ])
            ->value('provider');
    }

    private function reason(string $recommended, string $mode, array $providers): string
    {
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
}
