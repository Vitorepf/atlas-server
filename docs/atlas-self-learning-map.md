# Atlas Self-Learning — what it registers, learns, proposes, and now auto-applies

Operator-facing map of how Atlas learns from your use, what it saves, and the new
autonomous (no-approval) mode + the Sunday digest. Generated from a code-verified
discovery workflow (`.claude/workflows/atlas-self-learning-autonomy-map.js`,
re-runnable any time) + adversarial floor-review. This file is a projection, not canon.

## The pipeline (operator uses Atlas → memory/heuristic updated)

| Stage | What happens | Status |
|---|---|---|
| 1. Capture | Every interaction auto-stages a `ai_memory_deltas` row (pending, requires_confirmation) + opens an AEMOR episode. Cognitive-immune quarantine stamped, all flags born false. | **AUTO** |
| 2. Outcome | AEMOR forces `blocked` if evidence is empty; conductor appends `routing_memory.jsonl` on every LIVE run; RAG feedback + temporal certification recorded. | **AUTO** |
| 3. Distill | Outcome → learning candidate / `ai_learning_candidates` (promote iff evidence && confidence≥70). General-path AEMOR auto-distill is config-gated OFF (privacy). | **AUTO / dormant** |
| 4. Propose | Candidate → `ai_learning_proposals` ALWAYS `status=proposed`, `requires_human_review=true`. A pure classifier computes `may_auto_apply = admitted && !critical`. | **AUTO** |
| 5. Apply | Operator-gated by default. **NEW:** the autonomous consumer can auto-apply the SAFE classes (below). Routing heuristic + memory facts already auto-compound. | **GATED → opt-in AUTO** |
| 6. Report / prune | The Sunday digest reports everything saved + auto-applied, each with a reverse handle. | **AUTO (Sundays)** |

## What you verifiably already learn, every week

Run `php artisan atlas:ai:weekly-memory-digest` — it aggregates, read-only:
`atlas_memory_entries` · `ai_compounding_memories` · `ai_learning_proposals` (the
**review queue** = `status=proposed`) · `ai_memory_deltas` (staged captures) ·
`atlas_aemor_memory_candidates` · auto-applied routes. Scheduled **every Sunday 18:00**.

## Autonomous "Hermes mode" — auto-apply with no approval (DEFAULT OFF)

`atlas:ai:auto-apply-safe` (daily when enabled) auto-approves + auto-applies ONLY the
**safe, reversible, non-sensitive, non-critical** learning classes — `memory`,
`retrieval_hint`, `failure_pattern` — materialized as archivable `atlas_memory_entries`.
Everything else stays in the Sunday review queue.

It is a **fail-closed gate stack** — all four must pass, or the proposal is queued:
1. **G1 default-deny kind** — only kinds with both an applier AND a reverser, non-critical
   in *both* taxonomies. A critical kind can never auto-apply even though routing (a
   critical kind) does have a live applier.
2. **G2 privacy allowlist** — only `public`/`normal`. `sensitive`/`secret`/`cyber`/unknown → queue.
3. **G3 classifier** — the canon's `may_auto_apply` oracle must say yes.
4. **G4 admission floor** — the Constitutional Kernel + per-risk cap + privacy (nested
   `scope.privacy_class`) must return `allow_autonomous` with no human-approval flag.

Pétreo floor held for free: **never** git, **never** main, **never** critical/secret/cyber.
Every auto-apply is reversible and appears in the Sunday digest.

## Controls

```bash
# See everything saved + the review queue (read-only, safe any time):
php artisan atlas:ai:weekly-memory-digest [--days=7] [--json]

# Prune a saved memory entry (non-destructive; undo with --restore):
php artisan atlas:ai:memory-forget <id> [--restore]

# Turn on autonomous apply (a real behavior change — your opt-in):
#   ATLAS_AUTONOMOUS_AUTO_APPLY=true     (config/atlas.php → atlas.ai.autonomous_learning)
php artisan atlas:ai:auto-apply-safe [--limit=50] [--json]   # dry-view when OFF

# Re-understand the whole self-learning surface any time:
#   Workflow({name: 'atlas-self-learning-autonomy-map'})
```

## Deliberately NOT done (and why)

- **General-path AEMOR auto-distill** stays OFF — it would stage verbatim operator text
  with no privacy class. Capture already auto-stages; broadening waits on privacy carry-through.
- **Cognitive-immune G0-G8 promotion gate** is not wired as a 5th gate — it needs a richer
  signal context than a proposal carries; captured candidates already passed capture-side
  immune quarantine. Wiring it is the precondition before widening beyond the 3 safe kinds.
- **No auto-apply for critical classes** (policy/routing-override/gate/heuristic/eval_gate/
  benchmark) — operator-gated by canon, surfaced in the Sunday queue.
