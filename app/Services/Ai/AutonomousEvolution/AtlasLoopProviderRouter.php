<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Lever 5 — provider routing ("use MiniMax when you should, codex as you should").
 *
 * Picks the provider/model by task class: the CHEAP tier (MiniMax-M3) for high-volume / exploratory
 * / verification work (characterization tests, edge-fixes, review fan-out), the STRONG tier
 * (codex/gpt-5.5) for the load-bearing implementation (refactors, features) where quality decides.
 * Quality where it matters, cost on the bulk — maximizing attempts-per-budget without spending the
 * strong model on cheap work.
 *
 * Pure + fail-safe: routing OFF, an unmapped class, or a cheap provider that is NOT configured all
 * fall back to the default provider/model (byte-identical). So enabling it can only REDIRECT eligible
 * classes to a configured cheap provider — never block or break a task.
 */
final class AtlasLoopProviderRouter
{
    /**
     * @param  array<string,mixed>  $routing  atlas.loop.provider_routing config
     * @param  callable(string):bool  $isConfigured  provider-key configured probe
     * @param  array<string,array{failure_rate?:float,give_back_rate?:float}>  $providerStats  optional observed stats keyed by provider name
     * @return array{provider:string, model:?string, tier:string, reason:string, observed_fit:array<string,float>|null}
     */
    public function route(string $objectiveKind, string $defaultProvider, ?string $defaultModel, array $routing, callable $isConfigured, array $providerStats = []): array
    {
        $strong = static fn (string $reason): array => ['provider' => $defaultProvider, 'model' => $defaultModel, 'tier' => 'strong', 'reason' => $reason, 'observed_fit' => null];

        if (! (bool) ($routing['enabled'] ?? false)) {
            return $strong('routing_disabled');
        }

        $cheapClasses = array_map('strval', (array) ($routing['cheap_classes'] ?? []));
        if (! in_array($objectiveKind, $cheapClasses, true)) {
            return $strong('load_bearing_class');
        }

        $cheapProvider = trim((string) ($routing['cheap_provider'] ?? ''));
        if ($cheapProvider === '' || ! $isConfigured($cheapProvider)) {
            return $strong('cheap_provider_unconfigured');
        }

        $stats = is_array($providerStats[$cheapProvider] ?? null) ? $providerStats[$cheapProvider] : [];
        $failureRate = isset($stats['failure_rate']) ? max(0.0, min(1.0, (float) $stats['failure_rate'])) : null;
        $giveBackRate = isset($stats['give_back_rate']) ? max(0.0, min(1.0, (float) $stats['give_back_rate'])) : null;
        $threshold = max(0.0, min(1.0, (float) ($routing['cheap_failure_threshold'] ?? 0.4)));

        $observedFit = ($failureRate !== null || $giveBackRate !== null)
            ? ['failure_rate' => $failureRate ?? 0.0, 'give_back_rate' => $giveBackRate ?? 0.0]
            : null;

        if (($failureRate !== null && $failureRate > $threshold) || ($giveBackRate !== null && $giveBackRate > $threshold)) {
            return array_merge($strong('cheap_provider_stats_breach'), ['observed_fit' => $observedFit]);
        }

        $cheapModel = trim((string) ($routing['cheap_model'] ?? ''));

        return ['provider' => $cheapProvider, 'model' => $cheapModel !== '' ? $cheapModel : null, 'tier' => 'cheap', 'reason' => 'cheap_class_fit', 'observed_fit' => $observedFit];
    }

    /**
     * Provider-specific pre-prompt context cap used by {@see AtlasLoopProviderContextOptimizer}.
     *
     * @param  array<string,mixed>  $routing
     */
    public function contextTokenLimit(string $provider, array $routing = []): int
    {
        $provider = trim($provider) !== '' ? trim($provider) : 'default';
        $limits = (array) ($routing['context_token_limits'] ?? $routing['provider_context_token_limits'] ?? []);
        $raw = $limits[$provider] ?? $limits['default'] ?? $routing['context_token_limit'] ?? 6000;

        return max(256, min(200000, (int) $raw));
    }
}
