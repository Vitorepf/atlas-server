# Glossary

Terms specific to the Atlas Server codebase. Portuguese terms are marked with their language where they are the canonical name used by the team.

## Core concepts

**Atlas Server** — the Laravel monolith that started as a personal-data capture backend and grew into an autonomous AI engineering platform.

**Atlas CLI** (`bin/atlas`) — thin bash launcher that resolves PHP 8.4+, injects the caller's CWD as `--workspace`, and dispatches subcommands to artisan commands.

**AI Gateway** — the single mediated path to frontier models. The app never calls Claude/Codex/Gemini APIs directly; instead it creates an interaction that becomes a trace and a job, executed by a local worker running a provider CLI.

**Open Brain / AOBG** (Atlas Open Brain Gateway) — the local brain server that any external AI plugs into via MCP. Exposes a context pack fusing code-graph, reality graph (AURG), and semantic memory.

**Context pack** — one provider-bound, budgeted assembly fusing three brains for a task. Curated top-K, not exhaustive. Degrades to empty honestly when a source has no data.

## Data capture

**Capture** — a raw operator input (text/audio/photo) in a domain. Identified by client UUID. Soft-deletable.

**client_id** — client-generated UUID providing idempotent upsert across all ingestion endpoints and `/sync`.

**Domain** — business context of a capture: `blackink`, `saude`, `financas`, `outro`. Each domain has its own privacy policy.

**Check-in** — momentary self-report: state (focused/disperse/blocked/pause) + energy_level/mood_level (1-5) + note.

**Passive signal** — auto-collected metric from HealthKit or Rize: signal_type + numeric/text value + unit + time window.

**Sensor 4** — the digital activity sensor. Two layers: `digital_sessions` (granular per-app events) and `digital_activity_snapshots` (daily aggregates).

**Category class (1-10)** — intentionality taxonomy for digital use: 1=deep work, 3=curated input, 4=algorithmic input, 5=intentional entertainment, 6=default entertainment, 7=communication primary, 8=communication shallow, 9=market.

**Cognitive quarantine** — invariant that a raw capture is NOT memory/context/decision/learning-signal and is blocked from embedding/provider-export/open-brain until human review.

**Triage** — operator action routing a capture to archive/snooze/note/task/project/proposal/hypothesis.

**Sync** — the bidirectional `/sync` envelope: device uploads `*_to_upload`, server returns rows changed since `last_sync_at`.

## AI Gateway

**Interaction** — one operator request to the AI, created via `POST /ai/interactions`.

**Trace** (`ai_traces`) — the operator-facing record of an interaction (intent, agent, provider, model, status, response).

**Job** (`ai_jobs`) — the executable unit derived from a trace, queued for the worker.

**Attempt** (`ai_job_attempts`) — one provider invocation of a job (command, stdout/stderr, exit code, duration, status).

**Worker** — the local Mac process `atlas:ai:work` that claims jobs and runs provider CLIs.

**Provider** — a CLI/runtime driver implementing the `AiProvider` interface (claude_cli, codex_cli, gemini_cli, hermes_cli, minimax_m27_cli, jarvis_mlx).

**Hermes** — Atlas's executive provider/runtime (default), with ACP (persistent JSON-RPC) or CLI transport, capable of fanning out to the Executive Mesh.

**Council** — a deliberate multi-provider run (claude + codex) for deliberation, not auto-execution.

**AtlasDecide** — the sealed advisor that normalizes options and produces the operational provider/model decision.

**Fair mode** (FairClaudePolicy) — a locked provider/model contract that disables handoff/switch/downgrade.

**Handoff** — a context brief generated when the provider switches, injected into the next prompt.

**Pause for choice** (`awaiting_user_choice`) — job paused awaiting an operator decision (e.g. provider login required).

## Open Brain and memory

**Canonical memory** — `atlas_memory_entries` is the source of truth for decisions/learnings/context. Provider files are projections of it.

**Provider projection** — generated `CLAUDE.md` / `AGENTS.md`: compact, provider-safe bootstrap that can never override canonical repo docs.

**Reality graph / AURG** — fused Unified Reality Graph (code+memory+domain+evidence+strategic) with cross-layer paths and provenance.

**Code-graph** — BM25 + embedding symbol index (classes/methods/routes/migrations/tests), workspace-scoped.

**Blackboard** — claim table where multiple AI engines coordinate. Idempotent, TTL-expiring, conflict-aware, advisory (never blocks).

**Write-back** — governed ingestion of external AI output: size-capped, quality+safety gated, branch-only, never auto-merged.

**Brain-delta** — the per-file slice of the brain: decisions/missions/memories/neighbors touching one path.

**Provider-safe / provider-bound** — content cleared to cross to an external AI: no raw sensitive/secret bodies, redacted memory, ids/hashes only.

**Evidence Ledger** — append-only runtime truth (events proven by ids/hashes). High authority for events, not for specs.

**Knowledge authority hierarchy** — canonical repo docs > APs > code/tests/migrations > Evidence Ledger > Postgres KB / Code Intelligence > Obsidian > provider projections > chat.

## Engineering

**Blueprint** — versioned plan for a project with phases, acceptance matrix, scenario inventory, review gates.

**Task contract** — per-task specification (goal, scope, acceptance criteria, verification method, likely files, edge cases, definition of done).

**Code intelligence** — the symbol index read model parsed from the workspace. Powers catalog, symbols, module lookups, and a freshness gate.

**Code graph** — the edge layer over symbols (calls, types, framework edges, adjacency, reachability). Serves budgeted BM25 context packs.

**Reality cartography (AURC)** — a navigable map of the real system composed from documentation reality + code reality usage + derived structure.

**Documentation reality (ADRS)** — self-evaluating system that reconciles canonical docs against code and proves drift/duplication.

**Harness** — the sandboxed execution runner that takes a task through contract, blueprint, workspace, provider, docker, controls, tests, and scoring.

**Atlas-Bench** — the promoted benchmark corpus: real engineering runs promoted to benchmark cases for Atlas-vs-Claude-Code measurement.

**Software twin** — predictive impact simulator that models a change's blast radius, owners, and quality before mutation.

**Read model** — a derived, non-authoritative projection (Postgres KB, Code Intelligence, code graph) rebuilt from the authoring source.

## Autonomous Evolution Loop

**Loop** (ACDE / Autopoiesis-Evolution Engine) — the crown-jewel organ that grinds a scope 24/7 to evolve it exponentially. Runs a strict 8-phase cycle.

**8-phase cycle** — orient, comprehend, decide-leverage, architect, decompose, implement, certify, close-on-main. Each phase failure aborts the cycle.

**Ambition faculty** (faculdade de ambição) — the doctrine that a scope has no ceiling. When reactive work dries up, the loop originates the next real leap rather than stopping.

**Anti-Goodhart / no-proxy** — never optimize a measurable surrogate (line/test/cyclomatic count) instead of real capability. Refactor that preserves behavior = zero improvement.

**Frozen judge** — out-of-process verifier that re-runs frozen acceptance itself (Guards 1-4e). Cannot be edited by the loop.

**Anti-farm floor** — merge-eligibility floor requiring BITES (load-bearing diff) + PRODUCTION-PATH-PROVEN (wired into a real caller).

**Diff-earned** — a diff is real iff reverting it turns a frozen check RED (kills false-green).

**Cross-model triangulation** — 3+ distinct providers judge the same frozen bundle. Categorical agreement only, never a smoothed scalar.

**Obra** — a multi-step/multi-file work item routed off the single-step path to the Obra bridge.

**merged_sha** — the commit hash proving close-on-main actually merged. Its absence means the cycle aborted (no proxy success).

**Receipt** — signed, append-only, tamper-evident evidence of a phase/cycle/merge outcome.

**Campaign** — a 24h wall-clock autonomous run: refill, claim, grind, persist, loop-back, with lock/lease/heartbeat/crash-recovery.

## Self-Construction Government

**Atlas Autonomous Engineering Government** — the final 24/7 architecture above the OS. Governs separation of powers, scope admission, and multi-project stewardship.

**Atlas Self-Construction OS** — the governed operating system inside the government for "Atlas building Atlas."

**Separation of powers** — observe / decide-value / architect / decompose / schedule / execute / verify / merge / learn are separate organs. One agent must not span originate through promote.

**Constitution / Kernel** — the laws: forbidden scopes, autonomy levels, change-class trust ladder, rollback requirements.

**Control Plane** — decides what evolves, when, at what risk/budget/scope/mode. Owns the task-packet queue, leases, and certification.

**Task Fabric** — converts approved architecture into conflict-free, self-sufficient task packets with scope, dependencies, gates, rollback, and evidence.

**Maestro** — the scheduler that serves, leases, and retries packets with worker affinity, cost/fairness/decay/priority scheduling.

**Worker Swarm** — replaceable execution muscles (Atlas-native workers as destination; Codex/Claude/Cursor as bootstrap) that edit only allowed-file scope.

**Verification Court** — independent server-side re-run of gates + evidence validation + false-green/Goodhart detection.

**Merge Governor** — controls integration into main, rollback, canary, and blast-radius. Never trusts worker self-report.

**Learning Transfer** — promotes proven lessons into future packets/templates/context/docs after evidence. No narrative memory without proof.

**Earned autonomy** — no scope receives 24/7 authority by ambition. It is earned through receipts, server-side gates, rollback, low waste, and real value, climbing the Scope Ladder.

**Fleet Control Plane** — Kubernetes-style declarative control where the operator sets default-OFF desired-state and the reconciler converges processes toward it.

**Baba** (Portuguese: "nanny") — the fleet reconciler (`AtlasAgentReconciler`) that converges real processes toward desired-state. START is hard-gated, STOP always runs.

**FREIO** (Portuguese: "brake") — the desired-state safety brake: `ttl_expires_at` (auto-OFF) + `budget_limit_usd`.

**FORBIDDEN / petreo** (Portuguese: "stone") — the immutable cert/merge/switch/guard organ set the loop can never edit.

## Business domains

**Domain runtime** — the generic engine that registers business-domain manifests, tracks capabilities, assesses maturity (stage 1-5), and exposes a control-plane snapshot.

**Conversion OS** — the provider-free Marketing pipeline that turns a VSL asset into a high-converting bridge/advertorial page by auditing and injecting elite conversion patterns to a target grade.

**Search / Keyword OS** — the Marketing Google-Ads keyword system: intent ladder, investment gate, negative mining, qualified-keyword dossier, learning loop.

**Venture Foundry** — the create/grow-a-company subsystem: ideate, promote to a venture, run business rules, strategist reviews, growth ladder, and success evaluation toward an ARR target.

**VSL** (Video Sales Letter) — a video asset that the Conversion OS dissects and turns into a bridge page.

## Operator surface

**Operator mode** — governed local control over everything under the user's home directory, enabled at bootstrap and bounded by the permission gate + Operator Approval Gate.

**Operator Approval Gate** — decides allow_auto vs require_confirmation vs require_review vs block vs escalate_to_forge per action prefix and risk level.

**Decision receipt** — Ed25519-signed record of a human/operator decision. Reports "unavailable" when no keypair is present, never silently faked.

**Watchdog / soak** — shell scripts that run a long soak of the Loop or brain and self-heal it: kill over-budget grinds, automerge, respawn dead campaigns.

**Master switch** — operator-only, fail-closed `.env` flag. The loop/brain/fleet can never re-enable themselves.

**Elevations E1-E6** — Atlas Dev quality gates (intent probe, definition of done, mutation testing, differential testing, regression baseline, constitution gate), each tri-state off/advisory/hard.

**Mac agent** — native Swift edge for power/wake/background/voice. Laravel kernel decides, Swift executes, governed by a decision receipt.
