# Configuration

Atlas Server has 22 config files. The central switchboard is `config/atlas.php`, which is very large and contains the bulk of subsystem flags. Focused config files supplement it for specific areas.

## The config file landscape

| File | Purpose |
|------|---------|
| `config/atlas.php` | The central switchboard. Contains loop, brain, open_brain, aobg, code_graph, agents, task_serving, trust_ladder, self_construction, and many other blocks. Very large. |
| `config/atlas_ai.php` | AI runtime flags: self-improvement, ledger projection, kernel HTTP integration, context staleness assessment. |
| `config/atlas_dev.php` | Atlas Dev Efficient flags, confirmation-token TTL, provider timeout/default, mandatory RAG gate, Elevations E1-E6 (each tri-state off/advisory/hard). |
| `config/atlas_vault.php` | Vault config: `repo_docs_path`, `obsidian_vault_path`, cache seconds, recent changes limit. |
| `config/atlas_code_signing.php` | Ed25519 keypair for signing decision receipts. `keypair_base64`, `signer_id`, `audit_log_path`. |
| `config/atlas_code_verification.php` | Governed command allowlist for verification runs. `execute_enabled` (default false), timeout, regex patterns, evidence dir, operator override token. |
| `config/atlas_venture_foundry.php` | Venture Foundry config: weekly review, ideation, growth ladder, ARR target. |
| `config/atlas_projects.php` | Project management config. |
| `config/atlas_operator_intelligence.php` | Operator intelligence: comprehension extraction mode, daily comprehension, pattern detection. |
| `config/atlas_code_provider_governance.php` | Code provider governance. |
| `config/atlas_local_agent_ingestion.php` | Local agent ingestion config. |

## Key config sections in atlas.php

The central `config/atlas.php` contains blocks for each major subsystem:

- **`loop`** (~line 1937) — master switch, propose_only, recursive_self_improvement_auto_apply, author_judge_overlap_gate_enabled, overfit_probe_enabled, scenarios_per_task, search_patience, cross_model_triangulation_enabled, decision metrics, origination flags, receipt chain path.
- **`campaign`** (~line 3521) — campaign supervisor settings.
- **`open_brain.injection`** — context injection flags (enabled, budget_chars, include_memory_recall, include_reality_graph), all default OFF.
- **`open_brain.mcp`** — MCP server flags (http_enabled, allowed_origins).
- **`aobg`** — AOBG settings (semantic_retrieval, budget_chars, code_budget_chars, memory_budget_chars, auto_onboard, write_back caps).
- **`code_graph`** — auto_context, auto_context_budget.
- **`agents`** — fleet control plane (enabled, reconciler_enabled), default OFF.
- **`ai.tool_permissions`** — operator scope (allowed_roots, default_mode, allow_danger).
- **`ai.trust_ladder`** — per-change-class friction/trust ladder.
- **`ai.self_construction`** — promote_to_source_enabled (default false), tool_gap bridge scheduling.
- **`self_construction`** — recursive governed self-improvement (autonomous_enabled default false, relevance gates, max_branches_per_run, adversarial recheck, receipt log path).

## The .env variable reference

### Core

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_TOKEN` | (required) | Static auth token for all API requests |
| `ATLAS_STORAGE_PATH` | (required) | Local hash-based file storage path |
| `APP_KEY` | (required) | Laravel application key |
| `DB_CONNECTION` | pgsql | Database connection |
| `DB_HOST` | db | Database host |
| `DB_PORT` | 5432 | Database port (5433 mapped in Docker) |

### AI Gateway

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_AI_ENABLED` | false | Enable the AI Gateway |
| `ATLAS_AI_DEFAULT_PROVIDER` | hermes_cli | Default provider |
| `ATLAS_AI_DEFAULT_AGENT` | orquestrador | Default agent |
| `ATLAS_AI_WORKDIR` | - | AI working directory |
| `ATLAS_AI_WORKER_ID` | - | Worker identifier |
| `ATLAS_AI_TIMEOUT_SECONDS` | 300 | Provider call timeout |
| `ATLAS_AI_MAX_ATTEMPTS` | 2 | Max retry attempts |
| `ATLAS_AI_RETRY_DELAY_SECONDS` | 300 | Retry delay |
| `ATLAS_AI_CLAUDE_BIN` | claude | Claude CLI binary path |
| `ATLAS_AI_CODEX_BIN` | codex | Codex CLI binary path |
| `ATLAS_AI_CODEX_SANDBOX` | read-only | Codex sandbox mode |
| `ATLAS_AI_SCHEDULE_WORKER` | false | Let scheduler process jobs |

### Master switches (fail-closed, operator-only)

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_LOOP_MASTER_ENABLED` | false | Autonomous loop master switch |
| `ATLAS_BRAIN_MASTER_ENABLED` | false | Brain master switch |
| `ATLAS_FLEET_ENABLED` | false | Fleet control plane master switch |
| `ATLAS_AGENTS_RECONCILER_ENABLED` | false | Fleet reconciler (babá) |
| `ATLAS_LOOP_KEEPALIVE_ENABLED` | false | Loop keepalive |

### Brain / Loop watchdog

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_LOOP_WATCHDOG_INTERVAL` | - | Watchdog tick interval |
| `ATLAS_LOOP_GRIND_MAX_SECONDS` | - | Max grind duration before kill |
| `BRAIN_PROVIDER_CMD` | - | Brain soak provider command |

### Operator / tool permissions

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_AI_TOOL_ALLOWED_ROOTS` | - | Operator-mode trusted roots |
| `ATLAS_AI_TOOL_DEFAULT_MODE` | - | Default tool permission mode |
| `ATLAS_AI_TOOL_ALLOW_DANGER` | false | Allow dangerous tool operations |

### Dev

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_DEV_EFFICIENT_ENABLED` | - | Enable Atlas Dev Efficient |
| `ATLAS_DEV_DEFAULT_PATH` | efficient | Default dev path |
| `ATLAS_DEV_DEFAULT_PROVIDER` | - | Default dev provider |
| `ATLAS_DEV_PROVIDER_TIMEOUT_SECONDS` | - | Dev provider timeout |
| `ATLAS_DEV_ELEVATION_E{1..6}_MODE` | off | Elevation gate modes (off/advisory/hard) |
| `ATLAS_DEV_MANDATORY_RAG_GATE_BYPASS_ENABLED` | - | RAG gate bypass |

### Code signing / verification

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_DECISION_SIGNING_KEYPAIR_BASE64` | - | Ed25519 signing keypair |
| `ATLAS_DECISION_SIGNER_ID` | atlas-code-operator | Signer identity |
| `ATLAS_VERIFICATION_EXECUTE_ENABLED` | false | Allow verification command execution |
| `ATLAS_VERIFICATION_OPERATOR_TOKEN` | - | Operator override token for verification |

### Rize

| Variable | Default | Purpose |
|----------|---------|---------|
| `RIZE_API_KEY` | - | Rize GraphQL API key (server-only) |
| `RIZE_WEBHOOK_SECRET` | - | Rize webhook secret |
| `RIZE_GRAPHQL_ENDPOINT` | - | Rize GraphQL endpoint |
| `RIZE_TIMEZONE` | America/Sao_Paulo | Rize timezone |
| `RIZE_SYNC_ENABLED` | false | Enable scheduled Rize pull sync |
| `RIZE_SYNC_LOOKBACK_DAYS` | 2 | Sync lookback window |
| `RIZE_SYNC_PAGE_SIZE` | 100 | Sync page size |

### PHP / launcher

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_PHP_BIN` | - | Override PHP binary path |
| `ATLAS_MIN_PHP_VERSION` | - | Minimum PHP version |

## Config caching

Master switches are read from `.env` directly, not from the config cache. This is deliberate: `config:cache` compiles config files into a single PHP file, and reading from the cache would make the switches unchangeable without rebuilding the cache. Reading `.env` directly keeps the fail-closed behavior robust.

## Related pages

- [Data models](data-models.md) — migrations, models, and DB conventions
- [Dependencies](dependencies.md) — Composer packages and external tools
- [Getting started](../overview/getting-started.md) — install and .env setup
- [Earned autonomy](../concepts/earned-autonomy.md) — master switch design
- [Patterns and conventions](../how-to-contribute/patterns-and-conventions.md) — config conventions
