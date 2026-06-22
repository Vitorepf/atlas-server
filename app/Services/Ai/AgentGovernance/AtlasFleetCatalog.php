<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * THE FLEET — the catalog of every autonomous provider-consumer Atlas can run. This is the inventory the
 * operator discovered the hard way: Atlas is not "one loop" — it's a fleet, several of them launchd-respawned
 * 24/7, all drawing the same small provider quotas with nothing in the apps showing it.
 *
 * The catalog is the SET of things that exist; whether each is allowed to run is the operator's
 * desired-state ({@see AtlasAgentDesiredStateStore}), default OFF. Adding a new autonomous agent = add it
 * here, and it is automatically governed (status, on/off, history, reconciler) — nothing runs unless the
 * operator explicitly turns it on.
 */
final class AtlasFleetCatalog
{
    public const LOOP = 'loop';
    public const AI_WORKER_CODEX = 'ai-worker.codex';
    public const AI_WORKER_CLAUDE = 'ai-worker.claude';
    public const FINANCE_STRATEGY_LOOP = 'finance.strategy-loop';
    public const MAC_AGENT = 'mac-agent';

    /**
     * @return array<string,FleetAgentDefinition> keyed by agent key
     */
    public static function all(): array
    {
        $defs = [
            new FleetAgentDefinition(
                key: self::LOOP,
                label: 'Atlas Loop (auto-evolução)',
                account: 'GLM 5.2 / MiniMax-M3 (via Hermes)',
                kind: FleetAgentDefinition::KIND_LOOP,
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
        return self::all()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }
}
