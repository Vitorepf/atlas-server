<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

/**
 * THE BABÁ — the reconciler. Every tick it converges the real world toward the operator's DESIRED-STATE,
 * and ONLY toward it. This is the structural fix for "the loop comes back by itself with no brake": the
 * reconciler iterates the {@see AtlasFleetCatalog} (the declared fleet) and each agent's desired-state — it
 * NEVER scans `atlas_loop_campaigns WHERE status=running`, so an orphan row can never become a reason to start
 * anything.
 *
 * Per agent, given desired-state D and liveness L:
 *   - FREIO first: D is ON but TTL/budget tripped ⇒ persist auto-OFF (the brake), then treat as not-desired.
 *   - authorized (D effectively ON) AND dead  ⇒ START — but only if the agent's HARD GATE is ON (loop: the §0
 *     master switch; everyone else: the fleet master switch). Default OFF ⇒ start is suppressed. Fail-closed.
 *   - NOT authorized AND alive               ⇒ STOP, always. This is what enforces "se não ligou, nada roda"
 *     even against a process the operator never sanctioned.
 *   - otherwise                              ⇒ noop.
 *
 * START is gated and default-suppressed; STOP always runs. So the babá can only ever REDUCE unsanctioned
 * spend by default — it can never originate a run on its own.
 */
final class AtlasAgentReconciler
{
    public function __construct(
        private readonly AtlasAgentDesiredStateStore $store,
        private readonly FleetDriver $driver,
        private readonly ?AtlasAgentEventLedger $events = null,
    ) {}

    private function events(): AtlasAgentEventLedger
    {
        return $this->events ?? new AtlasAgentEventLedger();
    }

    /**
     * @return array<string,mixed> a structured report of every decision (for --json + history)
     */
    public function reconcile(): array
    {
        $now = now()->timestamp;
        $report = [
            'schema_version' => 'atlas.agents.reconcile.v1',
            'fleet_master' => AtlasFleetMasterSwitch::state(),
            'loop_master' => AtlasLoopMasterSwitch::state(),
            'checked' => 0,
            'started' => [],
            'stopped' => [],
            'auto_off' => [],
            'noop' => [],
        ];

        foreach (AtlasFleetCatalog::all() as $key => $def) {
            $report['checked']++;
            $spent = $this->driver->spentUsd($key);
            $alive = $this->driver->isAlive($key);
            $record = $this->store->record($key);

            // 1) FREIO — a desired-ON run that blew its TTL/budget is auto-OFF'd (persisted) before anything else.
            if ($record !== null && $record->on && $record->isExpired($now, $spent)) {
                $reason = $record->expiryReason($now, $spent) ?? 'expired';
                $this->store->setOff($key, by: 'reconciler', reason: $reason);
                $this->events()->append($key, AtlasAgentEventLedger::EVENT_EXPIRED, by: 'reconciler', reason: $reason, account: $def->account);
                $report['auto_off'][] = ['agent' => $key, 'reason' => $reason];
                $record = $this->store->record($key); // now OFF
            }

            $authorized = $record !== null && $record->effectivelyOn($now, $spent);

            if ($authorized && ! $alive) {
                if ($this->hardGateEnabled($key)) {
                    $this->driver->start($key, $record?->targetRef);
                    $this->events()->append($key, AtlasAgentEventLedger::EVENT_STARTED, by: 'reconciler', account: $def->account, reason: 'desired_alive');
                    $report['started'][] = ['agent' => $key, 'target_ref' => $record?->targetRef];
                } else {
                    $report['noop'][] = ['agent' => $key, 'reason' => 'start_suppressed_hard_gate_off'];
                }

                continue;
            }

            if (! $authorized && $alive) {
                $this->driver->stop($key);
                $this->events()->append($key, AtlasAgentEventLedger::EVENT_STOPPED, by: 'reconciler', account: $def->account, reason: 'not_desired');
                $report['stopped'][] = ['agent' => $key, 'reason' => 'not_desired'];

                continue;
            }

            $report['noop'][] = ['agent' => $key, 'alive' => $alive, 'authorized' => $authorized];
        }

        $report['generated_at'] = now()->toIso8601String();

        return $report;
    }

    /**
     * The HARD GATE for an agent — the fail-closed .env permission that must also be ON to START it. The loop
     * keeps its own pétreo §0 master switch; the rest of the fleet shares the fleet master switch. Either way:
     * default OFF, so the reconciler starts NOTHING until the operator explicitly enabled the gate.
     */
    private function hardGateEnabled(string $key): bool
    {
        if ($key === AtlasFleetCatalog::LOOP) {
            return AtlasLoopMasterSwitch::enabled();
        }

        return AtlasFleetMasterSwitch::enabled();
    }
}
