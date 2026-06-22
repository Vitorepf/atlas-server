# Loop self-evolution soak — operator runbook

How to run a **measurable, safe** self-evolution soak of the loop on itself, and how to read whether it is
**evolving** (compounding) or just **busy** (linear/faxina). Everything below is **one command at a time**;
you (the operator) flip the switches — the loop never turns itself on.

> The Fibonacci wiring is built + proven (flag-OFF byte-identical). This runbook is the arm sequence + the
> brake + exactly what to watch. See `loop-fibonacci-compounding-gap` for the wiring detail.

---

## The ladder (do NOT skip rungs)

1. **Propose-only soak** (auto-merge OFF) — see quality + proxy% with **zero risk to main**.
2. If clean (rung climbing, proxy% low, 0 regressions) → **arm L7** (self-merge).
3. **Merge soak** — now watch the rung actually climb cycle-to-cycle on main.
4. Only then → **24h**.

---

## Step 0 — preflight (launches nothing)

```
php artisan atlas:loop:soak --hours=2 --budget-usd=5
```

Prints the **brake**, the **arm-check** (master? the 6 seams? faxina OFF? propose-only vs auto-merge?), and the
exact launch line. It launches **nothing** without `--confirm`, and `--confirm` refuses unless the arm-check is
green. `--json` for machine output.

## Step 1 — arm the Fibonacci seams (`.env`)

```
php artisan atlas:loop:on            # master switch ON (the loop can never flip this itself)

# the 6 compounding seams — all default OFF; arming all of them is what makes it Fibonacci, not linear:
ATLAS_LOOP_ORIGINATION_ON_STARVATION_ENABLED=true     # L4 — exhaustion ORIGINATES, never stalls
ATLAS_LOOP_CAPABILITY_TREND_ENABLED=true              # L3 — the capability signal (must be on for the trend)
ATLAS_LOOP_CAPABILITY_AMBITION_ENABLED=true           # L3 — proven capability => bigger rung dared
ATLAS_LOOP_COMPOUNDING_FRONTIER_ENABLED=true          # L2/L5 — a merge expands the selectable frontier
ATLAS_LOOP_REGRESSION_SENTINEL_ENABLED=true           # L6 — post-merge regressions => fix-forward
ATLAS_LOOP_TERRITORY_WIDENED_ROOTS_DRIVE_REFILL=true  # L3 supply — proven leaps widen the scope
# optional tuning: ATLAS_LOOP_CAPABILITY_SLOPE_FULL=0.1  (lower => a real bend bites sooner)
# scope to the loop's own area for a self-soak:
ATLAS_LOOP_DISCOVERY_ROOTS=app/Services/Ai/AutonomousEvolution
# (territory rungs are operator-released, one frozen-protected level up, e.g.):
# ATLAS_LOOP_TERRITORY_LADDER_RUNGS=app/Services
```

**Keep the faxina magnet OFF** (it is OFF by default — do not set it):
`ATLAS_LOOP_DETERMINISTIC_DEADCODE_SUPPLY_ENABLED` stays unset/false. The soak runs MATERIAL work, not cleanup.

**Propose-only (step 1 of the ladder): leave ALL auto-merge flags OFF.** Do NOT set
`ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED` or `ATLAS_LOOP_OBRA_AUTO_MERGE_ENABLED`.

## Step 2 — launch (one command)

```
php artisan atlas:loop:soak --hours=2 --budget-usd=5 --confirm
```

Re-runs the arm-check (fail-closed) and, if green, dispatches `atlas:loop:campaign` with the brake:
TTL `--max-seconds`, spend `--max-usd-cents`, grind cap `--max-tasks`, `--idle-on-starvation`, `--no-shadow`.

**Brake defaults:** `--hours=2` (TTL, clamps to 24h), `--budget-usd=5` (spend ceiling; 0 = none),
`--grind-cap=0` (tasks; 0 = unbounded).

## Step 3 — watch it hourly (the instrument)

```
php artisan atlas:loop:soak-report --hours=1            # human table
php artisan atlas:loop:soak-report --hours=1 --json     # machine
```

**The three things that say "evolving WELL":**

| Watch | Good | Alarm |
|---|---|---|
| **Compounding** — dared RUNG-SIZE climbing? + capability slope bending up | `FIBONACCI: YES`, rung slope `> 0` | rung flat across hours = activity, not evolution |
| **Quality** — material vs proxy | `proxy_alarm: no`, proxy% low + steady | `proxy_alarm: YES` or proxy% rising hour-over-hour = **faxina drift** |
| **Regressions** | `regressions caught: 0` | `> 0` = a lower rung got knocked down (sentinel filed a fix-forward) |

If proxy% rises or the rung goes flat → **stop and investigate** (`atlas:loop:campaign:stop`). That is the
loop drifting to proxy/Goodhart — the thing the whole design forbids.

## Step 4 — arm L7 (self-merge) only after a clean propose-only soak

```
ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=true
php artisan atlas:loop:soak --hours=2 --budget-usd=5 --with-self-merge --confirm
```

**L7 is moat-bounded (pétreo, proven):** even armed, a self-edit on any `FORBIDDEN_SELF_TARGETS` organ (frozen
judge, certifier, auto-merge, materializer, prioritizers) is **rejected and parked** — the flag only unlocks
self-edits OUTSIDE the cert-moat. So the loop can improve its own non-judge harness autonomously, and can
never edit its own judge. Now the rung climb in `soak-report` is real movement on main.

## Stop / inspect

```
php artisan atlas:loop:campaign:stop        # stop (or --pause to freeze budget)
php artisan atlas:loop:campaign:status      # heartbeat / lock / budget / proposal stack
php artisan atlas:loop:off                   # master OFF
```
