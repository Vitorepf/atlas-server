# Atlas External Brain — Provider Portability

The external brain (`atlas:brain:next` / `atlas:brain:seed` / `atlas:brain:worker-prompt`) is **portable
by construction**: any agent session that can run a shell can be the brain's decider. This doc is the honest
account of *why* it is portable, *what is proven*, and the *exact paste-and-go* steps per provider — plus the
short list of things still **gated-on** before the brain actually RUNS against a live scope.

## Portable by construction

The brain never branches on which engine drives it. Three structural facts make the provider an interchangeable
muscle:

1. **Opaque client id.** `atlas:brain:worker-prompt --client=<id>` forwards a single opaque string. The brain
   does nothing with it but stamp the temp-file name; there is no per-provider code path. Omit it and a unique
   id is generated. A Claude session, a Codex session, and a Cursor session are byte-identical callers.
2. **Config-selected provider, never hardcoded.** The brain's frontier provider is `config('atlas.provider_defaults.brain_default')`
   = `env('ATLAS_BRAIN_PROVIDER', 'codex_cli')`. No model id is pinned in code (canon: `loop-hermes-native-no-model-pin`).
   Swapping the engine is an env flip, not a code change.
3. **No engine branch in the loop.** The decider loop is plain natural language + `php artisan atlas:brain:*`
   shell calls. The author≠judge invariant (the brain writes only to `docs/` + the serving queue; never `app/`,
   never commit/merge) is enforced in PHP — the same regardless of who pastes the prompt.

The unit served is the **serving queue** (Atlas-owned), and the STOP is the **dry-probe's** (not the model
self-judging). So a weaker or stronger provider cannot change *what counts as work* or *when the scope is dry*
— it can only originate better or worse candidates, all of which pass the same gates.

## Proven / runnable status (honest)

| Provider    | Status                | Notes |
|-------------|-----------------------|-------|
| **codex_cli** (default) | **proven / runnable** | The configured `brain_default`. Connectable via a subscription account; the frontier the brain was designed against. |
| **claude_cli** | **blocked: 401 over subscription** | The CLI does not authenticate via the subscription path today (`claude_cli` returns 401). Usable as the decider session that *pastes* the prompt, but not as the configured `ATLAS_BRAIN_PROVIDER` engine until auth is sorted. |
| **cursor**  | **unverified**        | No structural blocker — same opaque-client contract — but not exercised end-to-end. Treat as unproven until a live run confirms it. |

This table is about the **engine** (`ATLAS_BRAIN_PROVIDER`). Any of the three can be the **decider session** that
runs the worker-prompt loop, because that role is pure shell + NL.

## Paste-and-go

For all three, first print the prompt, then paste its output into the session. The prompt is < 4000 chars (fits
the paste limit) and self-contained.

```
php artisan atlas:brain:worker-prompt --scope=loop --client=<your-session-id>
```

### Claude Code
1. Open a Claude Code session in the repo root.
2. Run the command above; copy the printed prompt.
3. Paste it as the first message. The session now runs the decide-loop: `atlas:brain:next` → comprehend → write
   the temp specs file under `/tmp` → `atlas:brain:seed --dry-run` → `atlas:brain:seed` — looping until `dry`
   or `disabled`.

### Codex
1. Open a Codex session in the repo root (this is the default `ATLAS_BRAIN_PROVIDER` engine).
2. Run the command; paste the printed prompt.
3. Same loop. Because Codex is the configured engine, an enqueued packet is also what the worker swarm executes.

### Cursor
1. Open a Cursor session in the repo root.
2. Run the command; paste the printed prompt.
3. Same loop. Unverified end-to-end — confirm the first run by hand before trusting it unattended.

The worker-prompt hard-states author≠judge: the decider **never** edits `app/`, **never** commits/merges, and on
a `disabled` result it **prints the disabled line and STOPS** — it never re-enables the switch (operator-only).

## GATED-ON to actually RUN

Portable ≠ armed. Before the brain seeds real work into the live serving queue, ALL of these must hold:

1. **A Codex token** (or another working `ATLAS_BRAIN_PROVIDER` engine). `claude_cli` is 401-blocked today.
2. **`ATLAS_BRAIN_MASTER_ENABLED` flipped on** — default FALSE / fail-closed, operator-only. With it off,
   `atlas:brain:next` is a clean `disabled` no-op and `atlas:brain:seed` gates but enqueues nothing.
3. **Per-run operator OK.** The brain is implement-only until the operator explicitly green-lights a run
   (canon: `loop-implement-only-defer-running`). Do not start a campaign/soak unattended.
4. **Multi-file handoff validation.** A served brain spec can name multiple `allowed_files`; the worker that
   executes it must be validated on real multi-file work before the brain is trusted to seed such packets
   autonomously (the known grind-single-file gap — canon: `loop-cannot-deliver-on-own-mature-code`).

Until all four hold, the brain stays DESLIGADO: portable and proven by the frozen tests
(`tests/Feature/Ai/AutonomousEvolution/Brain/`), but not running.
