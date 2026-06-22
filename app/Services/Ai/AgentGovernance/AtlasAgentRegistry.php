<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

/**
 * THE single window into the fleet — read-only. Combines the catalog (what exists), desired-state (what the
 * operator declared), and live process facts (what is actually running) into one truthful snapshot. This is
 * what `atlas:agents:status`, `/api/agents/active`, and the mobile/desktop "loops ativos" screens render.
 *
 * Pure observation: the registry NEVER starts or stops anything (that is the reconciler's job). So it is safe
 * to call from an HTTP request without any chance of spending a provider account.
 */
final class AtlasAgentRegistry
{
    public function __construct(
        private readonly AtlasAgentDesiredStateStore $store,
        private readonly FleetDriver $driver,
    ) {}

    /**
     * Snapshot of every fleet agent.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $now = now()->timestamp;
        $agents = [];
        $activeCount = 0;
        $spendingAccounts = [];

        foreach (AtlasFleetCatalog::all() as $key => $def) {
            $record = $this->store->record($key);
            $spent = $this->driver->spentUsd($key);
            $alive = $this->driver->isAlive($key);
            $pids = $this->driver->pids($key);
            $startedAt = $this->driver->startedAtEpoch($key);
            $authorized = $record !== null && $record->effectivelyOn($now, $spent);

            if ($alive) {
                $activeCount++;
                if ($def->providerSpending) {
                    $spendingAccounts[$def->account] = true;
                }
            }

            $agents[] = [
                'key' => $key,
                'label' => $def->label,
                'account' => $def->account,
                'kind' => $def->kind,
                'provider_spending' => $def->providerSpending,
                // What the operator declared:
                'desired' => $record !== null ? $record->on : false,
                'authorized' => $authorized,
                'set_by' => $record?->setBy,
                'set_at' => $record?->setAtEpoch !== null ? date('c', $record->setAtEpoch) : null,
                'ttl_remaining_seconds' => $record?->ttlRemainingSeconds($now),
                'budget_limit_usd' => $record?->budgetLimitUsd,
                'target_ref' => $record?->targetRef,
                'reason' => $record?->reason,
                // What is actually happening:
                'status' => $this->statusLabel($alive, $record),
                'alive' => $alive,
                'pids' => $pids,
                'uptime_seconds' => $startedAt !== null ? max(0, $now - $startedAt) : null,
                'spent_usd' => $spent,
            ];
        }

        return [
            'schema_version' => 'atlas.agents.status.v1',
            'generated_at' => now()->toIso8601String(),
            'fleet_master' => AtlasFleetMasterSwitch::state(),
            'active_count' => $activeCount,
            'spending_accounts' => array_keys($spendingAccounts),
            'agents' => $agents,
        ];
    }

    /**
     * Only the agents actually running right now (what the "🔴 N ativos" badge counts).
     *
     * @return array<string,mixed>
     */
    public function active(): array
    {
        $snap = $this->snapshot();
        $snap['agents'] = array_values(array_filter($snap['agents'], static fn (array $a): bool => $a['alive'] === true));

        return $snap;
    }

    private function statusLabel(bool $alive, ?AgentDesiredState $record): string
    {
        if ($alive) {
            return 'running';
        }
        if ($record !== null && $record->on) {
            return 'desired_dead'; // operator wants it on but it isn't running (the babá will respawn if the hard gate is on)
        }

        return 'off';
    }
}
