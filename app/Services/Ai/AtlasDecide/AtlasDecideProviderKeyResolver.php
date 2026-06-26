<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

/**
 * PROVIDER/MODEL CANONICALIZATION concern, extracted from the god-class
 * {@see AtlasDecideMetaLearningService}.
 *
 * Owns every provider/model normalization: numericCost (the >0 cost guard),
 * canonicalProviderKey (alias map → canonical CLI key), configuredProviderAliases
 * (config-driven alias overlay), canonicalModelForProvider (per-provider model
 * normalization) and isKnownProviderKey (the resolvable-provider whitelist).
 *
 * These methods are pure / stateless and read only from config() — no
 * constructor dependencies needed.
 */
class AtlasDecideProviderKeyResolver
{
    public function numericCost(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $cost = (float) $value;

        return $cost > 0.0 ? $cost : null;
    }

    public function canonicalProviderKey(mixed $provider): ?string
    {
        $normalized = strtolower(trim((string) ($provider ?? '')));
        if ($normalized === '') {
            return null;
        }

        $aliases = array_merge([
            'anthropic_claude' => 'claude_cli',
            'claude' => 'claude_cli',
            'claude_code' => 'claude_cli',
            'openai_codex' => 'codex_cli',
            'openai_gpt' => 'codex_cli',
            'codex' => 'codex_cli',
            'minimax' => 'minimax_m27_cli',
            'minimax_m3' => 'minimax_m27_cli',
            'minimax-m3' => 'minimax_m27_cli',
            'minimax_m27' => 'minimax_m27_cli',
            'minimax_m27_cli' => 'minimax_m27_cli',
            'hermes' => 'hermes_cli',
            'hermes_cli' => 'hermes_cli',
            'gemini' => 'gemini_cli',
            'google_gemini' => 'gemini_cli',
        ], $this->configuredProviderAliases());

        return $aliases[$normalized] ?? $normalized;
    }

    /**
     * @return array<string,string>
     */
    public function configuredProviderAliases(): array
    {
        $configured = function_exists('config') ? (array) config('atlas.patamar4.adml_provider_aliases', []) : [];
        $aliases = [];
        foreach ($configured as $from => $to) {
            $from = strtolower(trim((string) $from));
            $to = strtolower(trim((string) $to));
            if ($from !== '' && $to !== '') {
                $aliases[$from] = $to;
            }
        }

        return $aliases;
    }

    public function canonicalModelForProvider(?string $provider, mixed $model): ?string
    {
        $model = trim((string) ($model ?? ''));
        if ($model === '') {
            return null;
        }
        $lower = strtolower($model);
        if ($provider === 'minimax_m27_cli' && ($lower === 'm3' || str_contains($lower, 'minimax'))) {
            return (string) (function_exists('config') ? config('atlas.ai.providers.minimax_m27_cli.model', 'MiniMax-M3') : 'MiniMax-M3');
        }

        return $model;
    }

    public function isKnownProviderKey(string $provider): bool
    {
        $known = [
            'claude_cli',
            'codex_cli',
            'gemini_cli',
            'jarvis_cli',
            'hermes_cli',
            'minimax_m27_cli',
        ];
        if (function_exists('config')) {
            $providers = config('atlas.ai.providers', []);
            if (is_array($providers)) {
                $known = array_values(array_unique(array_merge($known, array_map('strval', array_keys($providers)))));
            }
        }

        return in_array($provider, $known, true);
    }
}
