<?php

declare(strict_types=1);

namespace App\Services\Ai\Provider;

/**
 * Single inventory of provider keys for gateway auto/live routing.
 *
 * Full-pass defactor: Decide chooses *which* provider; this catalog is the
 * *inventory* of invocation-eligible and auto-live-worker keys. Surfaces must
 * not re-list these sets.
 */
final class ProviderCatalog
{
    /**
     * Providers that may be invoked for real execution (manual or auto after gates).
     *
     * @return list<string>
     */
    public static function invocationProviders(): array
    {
        $configured = self::configList('atlas.ai.invocation_providers');
        if ($configured !== []) {
            return $configured;
        }

        return ['hermes_cli', 'minimax_m27_cli', 'claude_cli', 'codex_cli', 'gemini_cli'];
    }

    /**
     * Providers that have a live draining worker for auto mode (never strand chats).
     *
     * @return list<string>
     */
    public static function autoLiveWorkerProviders(): array
    {
        $configured = self::configList('atlas.ai.auto_live_worker_providers');
        if ($configured !== []) {
            return $configured;
        }

        return ['hermes_cli', 'codex_cli'];
    }

    /**
     * Council dual-review pair.
     *
     * @return list<string>
     */
    public static function councilProviders(): array
    {
        $configured = self::configList('atlas.ai.council_providers');
        if ($configured !== []) {
            return $configured;
        }

        return ['claude_cli', 'codex_cli'];
    }

    /**
     * @return list<string>
     */
    private static function configList(string $key): array
    {
        try {
            if (! function_exists('config')) {
                return [];
            }
            $configured = config($key);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($configured) || $configured === []) {
            return [];
        }

        return array_values(array_map('strval', $configured));
    }

    public static function isInvocationProvider(string $provider): bool
    {
        return in_array($provider, self::invocationProviders(), true);
    }

    public static function isAutoLiveWorkerProvider(string $provider): bool
    {
        return in_array($provider, self::autoLiveWorkerProviders(), true);
    }
}
