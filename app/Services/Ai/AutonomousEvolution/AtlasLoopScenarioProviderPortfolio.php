<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Lever 4 — the PROVIDER PORTFOLIO for cross-provider best-of-N.
 *
 * The scenario explorer already runs N divergent attempts (strategy-diverse) and lets the frozen judge
 * pick the cert-passing best. The single biggest correctness win on top of that is DECORRELATING the
 * attempts by ENGINE: codex and MiniMax fail in different ways, so spreading the N attempts across a
 * provider portfolio makes it far likelier that at least one lands a correct, certifiable diff — a real
 * one-shot success-rate lift, not just a strategy reshuffle.
 *
 * Resolution order is deliberately config-LAST so a pure unit test that pins a provider never touches the
 * container (mirrors {@see AtlasEvolutionScenarioExplorer::resolveProvider}):
 *   1. task `scenario_providers` (explicit per-task portfolio);
 *   2. task `provider` (explicit single override) — no config read;
 *   3. config `atlas.loop.scenario_provider_portfolio` (the production portfolio);
 *   4. the resolved default provider (byte-identical single-provider behavior; '' lets the engine pick).
 */
final class AtlasLoopScenarioProviderPortfolio
{
    /**
     * @param  array<string,mixed>  $task
     * @return list<string> non-empty; each entry is a provider key ('' = engine default)
     */
    public function resolve(array $task, string $defaultProvider): array
    {
        $taskList = $this->cleanList($task['scenario_providers'] ?? null);
        if ($taskList !== []) {
            return $taskList;
        }
        $taskProvider = trim((string) ($task['provider'] ?? ''));
        if ($taskProvider !== '') {
            return [$taskProvider]; // explicit single override — never reads config
        }
        $configured = $this->cleanList(config('atlas.loop.scenario_provider_portfolio'));
        if ($configured !== []) {
            return $configured;
        }
        $default = trim($defaultProvider);

        return [$default]; // single-provider (possibly '' => engine/Atlas-Decide picks)
    }

    /**
     * The provider for attempt #$i: cycles the portfolio so attempt 0,1,2,... rotate through the engines.
     *
     * @param  array<string,mixed>  $task
     */
    public function providerFor(array $task, int $i, string $defaultProvider): string
    {
        $portfolio = $this->resolve($task, $defaultProvider);

        return $portfolio[$i % count($portfolio)];
    }

    /**
     * @return list<string>
     */
    private function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            $entry = is_string($entry) ? trim($entry) : '';
            if ($entry !== '') {
                $out[] = $entry;
            }
        }

        return array_values(array_unique($out));
    }
}
