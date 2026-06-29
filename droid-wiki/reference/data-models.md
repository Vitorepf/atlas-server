# Data models

Atlas Server has 330 migrations and 404 Eloquent models. The schema grew rapidly over two months, with the autonomous loop adding migrations as it evolves scopes. This page gives an overview of the key table groups and the DB conventions that govern them.

## Key table groups

### Capture and ingestion (V1 data backend)

The original personal-data capture tables, created in the first migrations (`2026_04_27_000000_create_atlas_core_tables.php` through `2026_04_28_*`):

| Table | Purpose |
|-------|---------|
| `captures` | Raw operator inputs (text, audio, photo) with domain, kind, client_id |
| `checkins` | Momentary self-reports (state, energy_level, mood_level 1-5, note) |
| `passive_signals` | Auto-collected metrics from HealthKit or Rize |
| `health_snapshots` | Health snapshot records |
| `digital_sessions` | Sensor 4 granular per-app events |
| `digital_activity_snapshots` | Sensor 4 daily aggregates |
| `digital_category_mappings` | Category class mappings for digital activity |
| `procrastination_events` | Procrastination event records |
| `daily_missions` | The daily mission |
| `sync_log` | Sync cursor tracking |

### AI Gateway

Created in `2026_04_28_060000_create_ai_gateway_tables.php` and extended through many migrations:

| Table | Purpose |
|-------|---------|
| `ai_traces` | Operator-facing record of an interaction (intent, agent, provider, model, status, response) |
| `ai_jobs` | Executable unit derived from a trace, queued for the worker |
| `ai_job_attempts` | One provider invocation of a job (command, stdout/stderr, exit code, duration, status) |
| `ai_threads` | Conversation threads |
| `ai_sessions` | AI session state |
| `ai_stream_events` | Streaming events |
| `ai_quality_evaluations` | Quality evaluation records |
| `ai_quality_actions` | Quality action records |
| `ai_tool_events` | Tool execution events |
| `ai_permission_sessions` | Permission session state |
| `ai_worker_heartbeat_events` | Worker heartbeat events |

### Memory (Open Brain)

Created starting `2026_04_28_050000_create_semantic_memory_tables.php` and `2026_05_02_*`:

| Table | Purpose |
|-------|---------|
| `atlas_memory_entries` | Canonical memory: decisions, learnings, context (with privacy columns, superseded_by, temporal-truth, embedding) |
| `atlas_memory_entry_relations` | Memory relations with conflict verbs |
| `atlas_memory_entry_usages` | Usage tracking (last_used_at) |
| `atlas_memory_quality_snapshots` | Memory quality snapshots |
| `atlas_memory_provider_projection_audits` | Audit trail of CLAUDE.md/AGENTS.md generation |
| `ai_memory_deltas` | Proposed memory items pending review/promotion |
| `atlas_verbatim_memories` | Approved verbatim quotes |
| `atlas_open_brain_access_logs` | Context pack export audit (hash-only, no raw) |
| `atlas_aobg_blackboard` | Multi-engine claim/coordination table |
| `semantic_memory_tables` | Semantic note index |

### Loop (Autonomous Evolution)

Created starting `2026_06_02_000100_create_atlas_loop_runtime_tables.php`:

| Table | Purpose |
|-------|---------|
| `atlas_loop_campaigns` | Campaign records (24h wall-clock runs) |
| `atlas_loop_tasks` | Loop tasks |
| `atlas_loop_proposals` | Evolution proposals |
| `atlas_loop_explorations` | Exploration records |
| `atlas_loop_targets` | Evolution targets |
| `atlas_loop_pipeline_state` | Pipeline state |
| `atlas_loop_decomposition_outcomes` | Decomposition outcome records |
| `atlas_loop_origination_outcomes` | Origination outcome records |
| `atlas_loop_delivery_contracts` | Delivery contracts |
| `atlas_loop_clarification_requests` | Clarification requests |
| `atlas_loop_test_coverage_edges` | Test coverage edges |
| `atlas_loop_failure_handles` | Failure handle records |
| `atlas_loop_confidence_samples` | Confidence samples |
| `atlas_loop_goodhart_receipts` | Goodhart gate receipt records |

Cycle and unified receipt chains are JSONL files under `storage/app/atlas/loop/`, not DB tables.

### Engineering

Created starting `2026_05_01_010000_*`:

| Table | Purpose |
|-------|---------|
| `atlas_engineering_evidence` | Engineering evidence records |
| `atlas_engineering_blueprints` | Versioned project blueprints |
| `atlas_engineering_runner_tables` | Harness runner tables |
| `atlas_engineering_review_findings` | Review findings with confidence/category |
| `atlas_engineering_benchmark_*` | Benchmark suites, cases, runs |
| `atlas_engineering_knowledge_items` | Knowledge base items |
| `atlas_engineering_code_intelligence_*` | Code intelligence read model |
| `atlas_engineering_project_blueprints` | Project-level blueprints |
| `atlas_engineering_harnessability_calibrations` | Harnessability calibration data |

### Self-construction (agent control plane)

Created `2026_05_12_010000_*`:

| Table | Purpose |
|-------|---------|
| `atlas_self_construction_agent_runs` | Agent run records |
| `atlas_self_construction_agent_heartbeats` | Agent heartbeats |
| `atlas_self_construction_agent_cost_events` | Agent cost events |
| `atlas_self_construction_agent_work_products` | Work product records |
| `atlas_self_construction_agent_wakeup_items` | Wakeup items |
| `atlas_self_construction_agent_dispatch_receipts` | Dispatch receipts |
| `atlas_self_construction_agent_sandbox_bindings` | Sandbox bindings |
| `atlas_self_construction_agent_dispatch_executor_release_authorizations` | Release authorizations |

### Agent governance (fleet control plane)

Created `2026_06_22_000100_create_atlas_agent_governance_tables.php`:

| Table | Purpose |
|-------|---------|
| `atlas_agent_desired_state` | Single source of run/respawn authority (default OFF, TTL+budget FREIO) |
| `atlas_agent_events` | Agent event ledger |

## DB conventions

- **UUID primary keys** via `gen_random_uuid()`. No auto-incrementing integers.
- **`client_id` UNIQUE** on all ingestion tables for idempotent upsert. The client generates the UUID; replaying the same `client_id` updates rather than duplicates.
- **JSONB `metadata`** columns on most tables for extensible structured data.
- **Soft-deletes** (`deleted_at`) on most entities. The `/sync` endpoint uses `withTrashed` to sync deletions.
- **`set_updated_at()` trigger** in PostgreSQL maintains `updated_at` (used as the sync cursor). Tables that should not auto-update (like `sync_log`) lack this trigger.
- **DB-level CHECK constraints** for enums (kind, domain, state, category_class, status). Invariants live in the database, not just the ORM.
- **Migrations are idempotent.** Never manually `INSERT INTO migrations`. If the local DB diverges, rebuild with `migrate:fresh`.

See [patterns and conventions](../how-to-contribute/patterns-and-conventions.md) for the full conventions.

## Related pages

- [Configuration](configuration.md) — config files and .env variables
- [Dependencies](dependencies.md) — Composer packages and external tools
- [Patterns and conventions](../how-to-contribute/patterns-and-conventions.md) — full DB and ledger conventions
- [Capture and ingestion](../systems/capture-ingestion/index.md) — the V1 data backend
- [AI Gateway](../systems/ai-gateway/index.md) — the interaction lifecycle
- [Open Brain](../systems/open-brain/index.md) — canonical memory model
