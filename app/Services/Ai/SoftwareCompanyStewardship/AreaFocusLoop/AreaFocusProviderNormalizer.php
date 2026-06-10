<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusProviderNormalizer
{
    public static function providerId(string $provider, string $emptyFallback = '', bool $includeMinimaxM3 = false): string
    {
        $provider = strtolower(trim($provider));

        $normalized = match ($provider) {
            'cursor', 'cursor-agent', 'cursor_agent', 'composer', 'composer_2_5' => 'cursor_cli',
            'claude', 'claude-code', 'claude_code', 'sonnet', 'opus' => 'claude_cli',
            'codex', 'openai_codex' => 'codex_cli',
            'gemini' => 'gemini_cli',
            'minimax', 'minimax_m27', 'minimax_m27_cli' => 'minimax_m27_cli',
            default => $provider,
        };

        if ($includeMinimaxM3 && in_array($provider, ['minimax_m3', 'minimax_m3_cli'], true)) {
            $normalized = 'minimax_m3_cli';
        }

        return $normalized !== '' ? $normalized : $emptyFallback;
    }

    public static function quarantineProviderId(string $provider): string
    {
        $provider = strtolower(trim($provider));
        $provider = str_replace(['-', ' '], '_', $provider);

        return match ($provider) {
            'claude', 'claude_code', 'claude_cli', 'sonnet', 'sonnet_4_6', 'claude_sonnet_4_6' => 'claude_cli',
            'minimax', 'minimax_cli', 'minimax_m3', 'minimax_m27', 'minimax_m27_cli' => 'minimax_m27_cli',
            default => $provider,
        };
    }
}
