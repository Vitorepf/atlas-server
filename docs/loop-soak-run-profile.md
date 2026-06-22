# Atlas Loop — Soak Run Profile (how to run a watched soak)

The loop ships **OFF** (master switch `ATLAS_LOOP_MASTER_ENABLED=false`, fail-closed). Nothing runs or
respawns until you arm it. This is the tested profile for a **single watched soak**: arm → launch 1 campaign →
it grinds for hours producing **propose-only** value, self-heals, doesn't thrash the Mac, doesn't burn CPU on
a dead provider, and stops clean.

## 1. The run profile (what to turn ON / keep OFF)

Set in `.env`, then `php artisan config:clear`.

**ARM (turn ON for the soak):**
```
ATLAS_LOOP_MASTER_ENABLED=true              # the master gate (operator-only; the loop can never set this)
ATLAS_LOOP_PROVIDER_CIRCUIT_BREAKER_ENABLED=true   # soak-safety: pause+alert if the provider dies (no CPU-burn)
ATLAS_LOOP_DEDUP_SUPPLY_ENABLED=true        # comprehension work: clone unification
ATLAS_LOOP_ORPHAN_WIRING_SUPPLY_ENABLED=true # comprehension work: wire built-but-orphan code
ATLAS_LOOP_DOC_GAP_SUPPLY_ENABLED=true      # comprehension work: capabilities the docs demand
ATLAS_LOOP_DECOMPOSE_SUPPLY_ENABLED=true    # heavy-refactor work: decompose god-methods
```

**KEEP OFF (propose-only — the soak proposes, you review; nothing auto-lands on main):**
```
ATLAS_LOOP_AUTO_MERGE_TO_MAIN=false
ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=false
ATLAS_LOOP_PROPOSE_ONLY=true
```

Or just use the CLI: `php artisan atlas:loop:on` flips the master flag; arm the supply lanes in `.env`.

## 2. Launch ONE campaign (scoped, bounded)

```
php artisan atlas:loop:campaign \
  --goal="evolve <scope>" \
  --workers=3 --scenarios=3 \
  --max-seconds=21600        # 6h hard ceiling (clean stop at budget)
```
Scope the work with `ATLAS_LOOP_CAMPAIGN_DISCOVERY_ROOTS` (CSV of dirs, e.g. `app/Services/Ai/AutonomousEvolution`).
Run it **detached** (`nohup … &` or the watchdog below) so it survives the terminal.

## 3. What keeps it safe (the operational ring — all live)

- **Provider circuit-breaker** — N consecutive provider-down grinds (no winner, 0 scenarios) ⇒ the supervisor
  pauses the campaign + emits a `provider_circuit_open` ledger event. A paused campaign is NOT keepalive-
  respawned. So a dead provider stops the soak cleanly instead of burning CPU for hours.
- **Resource gate** (`AtlasLoopResourceGate`) — refuses a new scenario below a free-disk floor or above a live-
  workspace cap, and reaps leaked workspaces. The Mac never fills its disk or thrashes on workspaces.
- **Cost governor** — throttles scenarios-per-task as spend approaches the campaign's `--max-usd-cents`.
- **Self-heal** — the keepalive (every 5 min, master-gated) revives a dead supervisor and reaps orphan/
  zombie grinds; `bin/atlas-loop-watchdog.sh <campaign-id>` adds an external babysitter (also master-gated:
  it self-exits when the master switch is OFF).
- **Clean stop** — the `--max-seconds` budget ceiling, the circuit-breaker pause, and the kill-switch all end
  the run with a recorded reason. No silent zombies.

## 4. Watch it (real value, not a vanity funnel)

```
php artisan atlas:loop:real-work-scorecard            # the anti-Goodhart score: REAL value, not coverage padding
php artisan atlas:loop:campaign-status --campaign-id=<id>
php artisan atlas:loop:morning-digest                  # "what did Atlas do overnight" funnel + cost + keepalive
```
The real-work-scorecard is the honest gauge: a campaign can run a perfect discovered→…→merged funnel while
every diff is a behaviour-preserving refactor. The scorecard refuses to call that "real value".

## 5. Stop it (and leave the loop SAFE)

```
php artisan atlas:loop:campaign-stop --campaign-id=<id>   # graceful kill-switch
php artisan atlas:loop:off                                 # master OFF — nothing can run/respawn again
```
Always finish with `atlas:loop:off`. The loop can never turn its own master switch back on (it's pétreo in the
constitution), so once OFF it stays OFF until you arm it again.

## 6. Caveats (honest)

- **Provider:** the loop runs on Hermes (GLM-5.2 / MiniMax fallback), NOT Claude. A soak consumes the Hermes
  provider quota, not the Claude 5h quota. If the whole fallback chain is down, the circuit-breaker pauses the
  soak (by design) — re-arm when the provider recovers.
- **Authoring is model-bound:** the deterministic certs (frozen judge, anti-farm floor, grounding-veto) are the
  gates; the actual code the loop writes is the frontier model's. A weak provider ⇒ fewer certified winners.
- **Propose-only:** with auto-merge OFF, certified proposals park for your review; nothing lands on main
  unattended. Flip auto-merge ON only when you trust the cert chain for unattended landing.
