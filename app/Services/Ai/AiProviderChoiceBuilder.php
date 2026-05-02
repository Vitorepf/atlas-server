<?php

namespace App\Services\Ai;

use Carbon\CarbonImmutable;

class AiProviderChoiceBuilder
{
    private const FALLBACK_MAP = [
        'claude_cli' => 'codex_cli',
        'codex_cli' => 'claude_cli',
        'gemini_cli' => 'claude_cli',
    ];

    private const PROVIDER_LABEL = [
        'claude_cli' => 'Claude (claude_cli)',
        'codex_cli' => 'Codex (codex_cli)',
        'gemini_cli' => 'Gemini (gemini_cli)',
    ];

    private const WAIT_HORIZON_HOURS = 24;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(string $errorCode, string $currentProvider, ?string $currentModel, ?CarbonImmutable $resetAt): array
    {
        return match ($errorCode) {
            'rate_limited' => $this->rateLimitedOptions($currentProvider, $resetAt),
            'auth_expired' => $this->authExpiredOptions($currentProvider),
            default => [$this->cancelOption()],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rateLimitedOptions(string $currentProvider, ?CarbonImmutable $resetAt): array
    {
        $options = [];

        $fallback = self::FALLBACK_MAP[$currentProvider] ?? null;
        if ($fallback) {
            $options[] = [
                'id' => 'switch_provider',
                'label' => 'Migrar para '.(self::PROVIDER_LABEL[$fallback] ?? $fallback),
                'description' => 'Continua a sessão no provider alternativo. Disponível agora.',
                'action' => 'switch_provider',
                'provider' => $fallback,
                'model' => null,
            ];
        }

        $fallbackModel = $currentProvider === 'gemini_cli'
            ? null
            : config("atlas.ai.providers.{$currentProvider}.fallback_model");
        if (is_string($fallbackModel) && $fallbackModel !== '') {
            $options[] = [
                'id' => 'downgrade_model',
                'label' => "Continuar no {$currentProvider} com {$fallbackModel} (modelo menor)",
                'description' => 'Mesma conta. Disponível agora. Mais rápido, mais barato, menos capaz.',
                'action' => 'downgrade_model',
                'provider' => $currentProvider,
                'model' => $fallbackModel,
            ];
        }

        if ($resetAt && $this->withinHorizon($resetAt)) {
            $options[] = [
                'id' => 'wait_for_reset',
                'label' => 'Aguardar reset ('.$resetAt->diffForHumans().')',
                'description' => 'Mantém o provider atual e reenfileira quando a janela liberar.',
                'action' => 'wait',
                'available_at_iso' => $resetAt->toIso8601String(),
            ];
        }

        $options[] = $this->cancelOption();

        $options[] = [
            'id' => 'retry_same',
            'label' => 'Já liberei (comprei créditos / upgrade) — tentar de novo agora',
            'description' => 'Reenfileira com mesmo provider e modelo. Se ainda bloqueado, o menu volta.',
            'action' => 'retry_same',
            'provider' => $currentProvider,
            'model' => null,
        ];

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function authExpiredOptions(string $currentProvider): array
    {
        $cliName = match ($currentProvider) {
            'claude_cli' => 'claude login',
            'codex_cli' => 'codex login',
            'gemini_cli' => 'gemini',
            default => 'login',
        };

        return [
            [
                'id' => 'login_required',
                'label' => "Marcar como bloqueado por login ({$cliName})",
                'description' => 'Falha o job com instrução clara. Operador roda o login no terminal e pede retry.',
                'action' => 'fail',
                'reason' => 'login_required',
                'cli_command' => $cliName,
            ],
            $this->cancelOption(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelOption(): array
    {
        return [
            'id' => 'cancel',
            'label' => 'Cancelar job',
            'description' => 'Encerra a tentativa. Conversa fica preservada.',
            'action' => 'cancel',
        ];
    }

    private function withinHorizon(CarbonImmutable $resetAt): bool
    {
        $now = CarbonImmutable::now();
        if ($resetAt->lessThanOrEqualTo($now)) {
            return false;
        }

        return abs($resetAt->diffInHours($now)) < self::WAIT_HORIZON_HOURS;
    }
}
