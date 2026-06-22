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

## 2. Clean start (preflight) — never resurrect a graveyard

Before arming, reap any abandoned `running` rows from prior sessions. If you skip this, the master-ON
keepalive will RESURRECT every stale row (dead process, row still says `running`) into live processes — the
exact auto-respawn that burned tokens. (Proven live: arming with 27 stale rows spawned 45 processes.)

```
php artisan atlas:loop:reap-orphans --grace-minutes=30 --json   # stops dead orphan rows; spares fresh ones
```
For the soak itself, also tighten the keepalive's own reaper so a killed soak is reaped within hours, not
24h: `ATLAS_LOOP_KEEPALIVE_REAP_AFTER_MINUTES=180` (a genuine crash with a fresh heartbeat is still resumed
within 5 min — only hours-dead zombies are reaped).

## 3. Launch ONE campaign (scoped, bounded)

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

## 5b. REQUIRED for a soak: run the watchdog (it kills HUNG grinds)

The circuit-breaker catches a provider that is DOWN (grinds fail fast → pause after N). It does NOT catch a
provider that HANGS — a `hermes chat` call that blocks forever. The supervisor does not enforce a wall-clock
grind timeout on the hermes_cli path (it bounds by `--max-turns`, not time), so a hung grind freezes the
campaign's `elapsed` clock and it never hits its `--max-seconds` budget. **Proven live: a refactor grind hung
on a `hermes chat --quiet` call for 15+ min, supervisor blocked, 0 progress** (the same live-hermes hang the
materializer-sandbox investigation found).

So launch the soak WITH the watchdog, which kills any grind older than a wall-clock ceiling. **Prefer the
SUPERVISOR wrapper** — it respawns the watchdog if the watchdog process itself is OOM-killed or crashes
mid-soak (otherwise hung grinds would never be reaped again and the machine thrashes silently):
```
nohup bin/atlas-loop-watchdog-supervised.sh <campaign-id> > /tmp/loop-watchdog-sup.log 2>&1 &   # master-gated
```
(The bare `bin/atlas-loop-watchdog.sh <campaign-id>` is the un-supervised form — fine for a quick check, but a
multi-hour soak should use the supervised wrapper.) Either runs `ATLAS_LOOP_GRIND_MAX_SECONDS` (default 1800)
and SIGKILLs an over-budget grind + its hermes call, so the supervisor records the attempt failed and moves on.
Without the watchdog, a single hung hermes call stalls the soak. **To stop a supervised run:** `atlas:loop:off`
(the canonical kill — the supervisor exits, respawns nothing) or `touch storage/atlas-loop/WATCHDOG_SUPERVISOR_STOP`.

## 5c. Provider-FREE soak-readiness check (deterministic — run it anytime, even master-OFF)

Before (or alongside) a provider soak, prove the loop CERTIFIES real value without the provider:
```
php artisan atlas:loop:deadcode-sweep --json          # or --path=<dir> --limit=N
```
It mills the scope, surgically removes provably-dead `private` members (AST, fail-closed — never a live
sibling), and runs the holdout cert — all with ZERO provider calls. PROVEN live over the loop's own ~276-file
scope: 2 certified removals, `provider_used=false`.

**IN-CAMPAIGN form (attaches certified removals to a campaign as propose-only proposals):**
```
php artisan atlas:loop:deadcode-sweep --persist --campaign=<id> --json
```
Each certified removal is persisted `status=certified_for_review` (OPERATOR REVIEW — never auto-merges; it
carries no `_acceptance_contract`, so the drain re-prove fails closed even if auto-merge were ON). PROVEN
live end-to-end: a real campaign milled 276 files → certified + persisted 2 dead-code-removal proposals
(`provider=deterministic`, `provider_used=false`), no thrash, clean. This is `1 campanha mói→certifica valor
real ao vivo` for the DETERMINISTIC work-type — no hermes. (The provider work-types' in-campaign live proof
is unblocked by the separately-landing hermes one-shot fix; the operational ring above keeps either safe.)

## 6. Caveats (honest)

- **Provider:** the loop runs on Hermes (GLM-5.2 / MiniMax fallback), NOT Claude. A soak consumes the Hermes
  provider quota, not the Claude 5h quota. If the whole fallback chain is down, the circuit-breaker pauses the
  soak (by design) — re-arm when the provider recovers.
- **Authoring is model-bound:** the deterministic certs (frozen judge, anti-farm floor, grounding-veto) are the
  gates; the actual code the loop writes is the frontier model's. A weak provider ⇒ fewer certified winners.
- **Propose-only:** with auto-merge OFF, certified proposals park for your review; nothing lands on main
  unattended. Flip auto-merge ON only when you trust the cert chain for unattended landing.
