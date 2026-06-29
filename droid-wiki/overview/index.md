# Atlas Server overview

Atlas Server is a Laravel 13 / PHP 8.4 monolith running on PostgreSQL 16 with pgvector. It started as a single-tenant personal-data capture backend (text, audio, photo, check-ins, passive signals, digital activity tracking) synced from a local-first iPhone/Mac app over Tailscale, and grew into an autonomous AI software-engineering platform that builds and evolves itself 24/7.

The codebase is large and grew fast: ~7,100 PHP files and ~1.73M lines of application code, ~5,000 test files, 1,227 artisan commands, 404 Eloquent models, and 330 migrations, all written in roughly two months (April-June 2026). A significant fraction of the commits were authored by the autonomous evolution loop itself.

## What Atlas does

Atlas has eight major subsystems, each documented under [systems/](../systems/index.md):

1. **Capture and ingestion** — the original V1 product surface. Receives captures (text/audio/photo), check-ins, passive health signals, and Sensor 4 digital activity from the operator's devices. Audio is transcribed locally with whisper.cpp. All raw captures are cognitively quarantined until a human ratifies a curation proposal.

2. **AI Gateway** — the single mediated path to frontier models. The app never calls Claude, Codex, or Gemini APIs directly. An HTTP request creates an interaction; the server persists a trace and a job; a local worker (`atlas:ai:work`) runs the already-authenticated provider CLI as a child process and streams the result back.

3. **Open Brain** — a governed knowledge store and MCP server that any external AI (Claude Code, Codex, Cursor, in any project) can plug into. Its flagship tool fuses three brains (code-graph, reality graph, semantic memory) into one provider-safe context pack. Canonical memory is the source of truth; `CLAUDE.md` and `AGENTS.md` are generated projections.

4. **Engineering** — the agentic software-engineering plane: versioned blueprints, a code-intelligence read model with BM25-ranked context packs, reality cartography that reconciles docs against code, a sandboxed harness runner, and an Atlas-vs-Claude-Code benchmark.

5. **Autonomous Evolution Loop** — the crown jewel. Given a scope, it grinds 24/7 to evolve that scope exponentially through a strict 8-phase cycle (orient, comprehend, decide-leverage, architect, decompose, implement, certify, close-on-main). It replaces the human reviewer with a frozen out-of-process judge, cross-model triangulation, mutation testing, and a fail-closed merge gate.

6. **Self-Construction OS** — the meta-architecture that governs the Loop. Sixteen organs with separation of powers: no single agent originates, implements, judges, merges, and promotes its own work. A Kubernetes-style fleet control plane (default OFF) authorizes which agents may run. Autonomy is earned, not assumed.

7. **Business domains** — the same agent machinery pointed at money-making scopes: a generic domain-manifest engine, a live trading strategy loop (Finance), a Conversion OS and Search/Keyword OS (Marketing, the most actively developed), Venture Foundry, and a Holding/Strategic-OS company stack.

8. **CLI and operator surface** — the `atlas` terminal product (`bin/atlas`), a Dev Cockpit with clipboard image analysis, bootstrap-to-release readiness gates, an operator approval gate with Ed25519-signed decision receipts, and shell watchdogs that keep the Loop alive without a human supervising.

## Who uses it

A single operator (the codebase owner) uses Atlas as both a personal-data capture system and an autonomous engineering assistant. The autonomous loop runs 24/7 on a Mac, evolving the codebase, merging certified changes to main, and recording evidence. External AI tools (Claude Code, Codex, Cursor) plug into the Open Brain via MCP for governed context.

## Quick links

- [Architecture](architecture.md) — system architecture with diagrams
- [Getting started](getting-started.md) — install, build, test, run
- [Glossary](glossary.md) — project-specific terms and domain vocabulary
- [By the numbers](../by-the-numbers.md) — codebase statistics snapshot
- [Systems overview](../systems/index.md) — the 8 subsystems in detail
- [Concepts](../concepts/index.md) — cross-cutting doctrine (anti-Goodhart, separation of powers, evidence)
- [How to contribute](../how-to-contribute/index.md) — working in this codebase
