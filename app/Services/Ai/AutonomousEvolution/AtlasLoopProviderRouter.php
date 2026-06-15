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
     * @return array{provider:string, model:?string, tier:string}
     */
    public function route(string $objectiveKind, string $defaultProvider, ?string $defaultModel, array $routing, callable $isConfigured): array
    {
        $strong = ['provider' => $defaultProvider, 'model' => $defaultModel, 'tier' => 'strong'];

        if (! (bool) ($routing['enabled'] ?? false)) {
            return $strong;
        }

        $cheapClasses = array_map('strval', (array) ($routing['cheap_classes'] ?? []));
        if (! in_array($objectiveKind, $cheapClasses, true)) {
            return $strong; // load-bearing class => strong tier
        }

        $cheapProvider = trim((string) ($routing['cheap_provider'] ?? ''));
        if ($cheapProvider === '' || ! $isConfigured($cheapProvider)) {
            return $strong; // fail-safe: cheap tier unconfigured => fall back to strong (never block)
        }

        $cheapModel = trim((string) ($routing['cheap_model'] ?? ''));

        return ['provider' => $cheapProvider, 'model' => $cheapModel !== '' ? $cheapModel : null, 'tier' => 'cheap'];
    }
}
