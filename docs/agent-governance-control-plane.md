# Agent Governance — the Fleet Control Plane

**Status: built + proven, shipped OFF (2026-06-22).** The definitive fix for "autonomous Atlas agents running
without the operator knowing, burning provider accounts, coming back by themselves."

## The invariant (non-negotiable)

> **If the operator turned a loop ON, it runs. If he didn't, nothing runs.** Desired-state changes ONLY by an
> explicit operator action — never by the system. Default OFF, fail-closed (no explicit record = OFF).

## The architecture (declarative control plane, Kubernetes-style)

```
operator ──set desired──▶ DESIRED-STATE  ──read──▶ RECONCILER (the babá) ──converge──▶ real processes
                         (default OFF,             starts desired+gated,
                          per-agent, TTL/budget)   stops everything else
```

- **Desired-state** (`atlas_agent_desired_state`, `AtlasAgentDesiredStateStore`) — the single source of
  run/respawn authority. Default OFF / fail-closed (missing row, missing table, any exception ⇒ OFF). Carries
  an optional **FREIO**: `ttl_expires_at` (auto-OFF after N seconds) and `budget_limit_usd`. Mutated ONLY by
  operator-facing commands/endpoints.
- **The babá** (`AtlasAgentReconciler`) — each tick converges the real world toward desired-state and **only**
  toward it. It iterates the catalog + desired-state, **never** `atlas_loop_campaigns WHERE status=running`, so
  an orphan row can never originate a start. `not-desired & alive ⇒ STOP` (always); `desired & dead ⇒ START`
  (only behind the per-agent HARD GATE, default OFF); FREIO tripped ⇒ auto-OFF then stop. START is gated +
  default-suppressed, STOP always runs ⇒ **by default the babá can only REDUCE unsanctioned spend.**
- **Hard gates** — the loop keeps its pétreo §0 `AtlasLoopMasterSwitch` (`.env ATLAS_LOOP_MASTER_ENABLED`); the
  rest of the fleet shares `AtlasFleetMasterSwitch` (`.env ATLAS_FLEET_ENABLED`). Both default OFF, fail-closed,
  robust under `config:cache` (parsed straight from `.env`).
- **Registry** (`AtlasAgentRegistry`) — truthful read-only snapshot (desired vs actual, account, pids, uptime,
  TTL). Safe to call from HTTP; never starts/stops anything.
- **History** (`atlas_agent_events`, `AtlasAgentEventLedger`) — append-only audit of every flip + the babá's
  every start/stop/expire.

### The root-cause cut

The incident: `AtlasLoopKeepaliveCommand` respawned **any** `status=running` row with a stale heartbeat, so the
instant the loop master went ON, the whole campaign graveyard came back. Now every respawn/recycle/revive
branch is gated by `desiredStore->authorizesCampaign(id, launchedAt)` — authority comes from what the operator
explicitly launched (loop desired-ON, not braked, AND `target_ref == id` OR launched at/after the `set_at`
floor), **never** from the row's status. Orphan rows become logged no-ops. Proven pétreo:
`tests/Feature/AgentGovernance/KeepaliveDesiredStateGateTest.php` — master ON + orphan running rows + no
desired-state ⇒ **0 respawns**.

## How to turn the fleet on / off (operator)

```bash
# Loop (keeps its §0 master switch; on/off now also drive desired-state)
php artisan atlas:loop:on  --ttl=7200 --reason="manual run"   # master ON + desired ON + FREIO 2h
php artisan atlas:loop:off                                    # master OFF + desired cleared

# Any fleet agent (declarative — the babá then runs/stops it)
php artisan atlas:agents:on  finance.strategy-loop --ttl=3600
php artisan atlas:agents:off finance.strategy-loop
php artisan atlas:agents:off --all          # PANIC: desired-OFF for all + both master switches OFF
php artisan atlas:agents:reconcile           # run the babá once (apply now)
php artisan atlas:agents:status              # the full fleet: running/desired/off + account + uptime + TTL
```

The babá is also scheduled (`atlas:agents:reconcile`, every minute) but **gated by
`atlas.agents.reconciler_enabled`, default OFF** — inert until the operator arms it.

## The apps (mobile + desktop)

Both poll `/api/agents/*` (under the existing `atlas.token` auth):

- `GET /api/agents/active` · `GET /api/agents/status` · `GET /api/agents/history`
- `POST /api/agents/{key}/off` · `POST /api/agents/off-all` (the DESLIGAR buttons)

There is **no turn-ON endpoint** — starting an agent stays a deliberate CLI/operator act, so a tapped app can
only ever reduce spend.

- **Mobile** (`atlas-app`): a persistent red badge "🔴 N ativos gastando [conta]" on every screen
  (`components/ActiveAgentsBadge.tsx`, mounted in `AtlasShell`) → opens `app/agents.tsx` (active + fleet +
  history + DESLIGAR + DESLIGAR-TUDO).
- **Desktop** (`atlas-desktop`): a fixed badge on every surface (`components/ActiveLoopsBadge.tsx`) → the
  **Frota** surface (`⌘0`, `surfaces/active-loops/`).

## Current state

Everything ships **OFF**: `ATLAS_LOOP_MASTER_ENABLED=false`, `ATLAS_LOOP_KEEPALIVE_ENABLED=false`,
`ATLAS_FLEET_ENABLED=false`, `ATLAS_AGENTS_RECONCILER_ENABLED=false`. Nothing runs or respawns until the
operator explicitly turns it on.

## Tests (58 green)

`tests/Unit/AgentGovernance/` + `tests/Feature/AgentGovernance/` — desired-state (default OFF / FREIO / floor /
fail-closed), the babá invariant (incl. "orphan rows never start anything"), the registry, the pétreo keepalive
gate, the operator commands, and the HTTP endpoints. All proven deterministically with a `FakeFleetDriver` —
**never** by launching a real campaign.
