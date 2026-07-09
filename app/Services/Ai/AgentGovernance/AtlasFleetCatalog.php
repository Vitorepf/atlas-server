<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * THE FLEET — the catalog of every autonomous provider-consumer Atlas can run.
 *
 * Autônomos (brain+task) is the live self-evolution surface. The legacy agent key
 * `loop` is accepted as an alias of `autonomos` for one migration period
 * ({@see normalizeKey()}).
 */
final class AtlasFleetCatalog
{
    /** Live Autônomos master-gate agent (brain+task). No ACDE campaign process. */
    public const AUTONOMOS = 'autonomos';

    /**
     * @deprecated Use AUTONOMOS. Same value — kept so callers/tests using LOOP keep compiling.
     */
    public const LOOP = self::AUTONOMOS;

    public const AI_WORKER_CODEX = 'ai-worker.codex';
    public const AI_WORKER_CLAUDE = 'ai-worker.claude';
    public const FINANCE_STRATEGY_LOOP = 'finance.strategy-loop';
    public const MAC_AGENT = 'mac-agent';

    /** Legacy desired-state / CLI key accepted for one period. */
    public const LEGACY_LOOP_ALIAS = 'loop';

    /**
     * Map operator/CLI keys onto the catalog key (alias `loop` → `autonomos`).
     */
    public static function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === self::LEGACY_LOOP_ALIAS) {
            return self::AUTONOMOS;
        }

        return $key;
    }

    /**
     * @return array<string,FleetAgentDefinition> keyed by agent key
     */
    public static function all(): array
    {
        $defs = [
            new FleetAgentDefinition(
                key: self::AUTONOMOS,
                label: 'Atlas Autônomos (brain + task)',
                account: 'GLM 5.2 / MiniMax-M3 (via Hermes)',
                kind: FleetAgentDefinition::KIND_AUTONOMOS,
                providerSpending: true,
            ),
            new FleetAgentDefinition(
                key: self::AI_WORKER_CODEX,
                label: 'AI Worker — Codex',
                account: 'Codex',
                kind: FleetAgentDefinition::KIND_AI_WORKER,
                providerSpending: true,
            ),
            new FleetAgentDefinition(
                key: self::AI_WORKER_CLAUDE,
                label: 'AI Worker — Claude',
                account: 'Claude',
                kind: FleetAgentDefinition::KIND_AI_WORKER,
                providerSpending: true,
            ),
            new FleetAgentDefinition(
                key: self::FINANCE_STRATEGY_LOOP,
                label: 'Finança — loop de estratégia',
                account: 'GLM 5.2 / MiniMax-M3 (via Hermes)',
                kind: FleetAgentDefinition::KIND_FINANCE,
                providerSpending: true,
            ),
            new FleetAgentDefinition(
                key: self::MAC_AGENT,
                label: 'Mac / Host Agent',
                account: 'variável (provider conforme tarefa)',
                kind: FleetAgentDefinition::KIND_HOST_AGENT,
                providerSpending: true,
            ),
        ];

        $byKey = [];
        foreach ($defs as $def) {
            $byKey[$def->key] = $def;
        }

        return $byKey;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $key): ?FleetAgentDefinition
    {
        return self::all()[self::normalizeKey($key)] ?? null;
    }

    public static function has(string $key): bool
    {
        $normalized = self::normalizeKey($key);

        return isset(self::all()[$normalized]);
    }

    public static function isAutonomosKey(string $key): bool
    {
        return self::normalizeKey($key) === self::AUTONOMOS;
    }
}
