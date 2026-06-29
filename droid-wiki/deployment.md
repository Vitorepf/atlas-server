# Deployment

Atlas Server is a local-first, single-tenant system. It runs on a Mac, behind Tailscale, with no public internet exposure. The deployment model is deliberately simple: one server, one database, one operator. The autonomous loop runs 24/7 on the same machine, kept alive by launchd agents and shell watchdogs.

## Docker Compose

The `docker-compose.yml` defines services for local and production use:

| Service | Port | Purpose |
|---------|------|---------|
| `db` | 5433 -> 5432 | PostgreSQL 16 with pgvector (`pgvector/pgvector:pg16`) |
| `backend` | 3737 | Laravel API (production: config cache + route cache) |
| `app` | 3737 | Laravel API (local: config clear, auto-migrate) |
| `queue` | - | transcription worker |

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d --build
```

The database uses the `pgvector/pgvector:pg16` image, which includes the pgvector extension for semantic memory embeddings. The healthcheck uses `pg_isready` with 10s intervals.

The `backend` service (production) runs a boot sequence: `config:clear` to ensure migrations read current env, `migrate --force` to update schema, `config:cache` and `route:cache` for faster boot, then `php -S` to serve. The `app` service (local) runs a simpler sequence without caching.

## launchd agents

macOS launchd agents and daemons keep the system running 24/7. Install scripts live in `scripts/`:

| Agent/Daemon | Label | Script | Purpose |
|-------------|-------|--------|---------|
| Mac agent | `com.atlas.mac-agent` | `install-mac-agent-launch-agent.sh` | Native Swift edge: power, wake, background, voice |
| Power helper | `com.atlas.power-helper` | `install-power-helper-launch-daemon.sh` | Root-level power management (caffeinate, pmset) |
| AI worker | `com.atlas.ai-worker.claude` / `.codex` | `install-ai-worker-launch-agent.sh` | AI Gateway worker per provider |
| Vault snapshot | `com.vitorepf.atlas-vault-snapshot` | `install-atlas-vault-snapshot-launch-agent.sh` | Periodic AtlasVault snapshot |

Each install script writes a plist to `~/Library/LaunchAgents/` (user agents) or `/Library/LaunchDaemons/` (root daemons). Uninstall scripts remove them.

## The scheduler

`routes/console.php` (502 lines) defines the Laravel scheduler entries. These are `Schedule::command(...)` definitions, each gated by a config flag (default OFF). The scheduler runs via launchd's `schedule:run` tick every 60 seconds.

Key scheduled commands:

| Command | Cadence | Gate | Purpose |
|---------|---------|------|---------|
| `atlas:software-company-stewardship native-obra-runner` | Every 15 min | `native_obra_runner.enabled` | Native obra runner |
| `queue:work database-long --queue=folder-intel` | Every minute | `code_folder_intelligence.auto_assemble` | Drain folder-intel queue (stop-when-empty) |
| `queue:work database-long --queue=missions` | Every minute | `mission.http_delivery_enabled` | Drain mission delivery queue |
| `atlas:harness propose` | Daily 07:00 | `harness_autopilot.enabled` | Self-harness autopilot proposals |
| `atlas:harness autopilot` | Daily 07:10 | `harness_autopilot.enabled` | Self-harness autopilot execution |
| `atlas:loop:automerge --limit=10` | Every 15 min | Master switch + `auto_merge_to_main` | Merge-livre drain (certified to main) |
| `atlas:aurg:ingest --prune` | Daily 05:50 | `aurg.enabled` + `aurg.schedule_enabled` | AURG full fused-store sync |
| `atlas:venture review-cycle` | Weekly Monday 06:30 | `weekly_review_enabled` | Venture Foundry strategist review |

Install the scheduler launchd agent:

```bash
atlas:scheduler:install-launchd
```

## Watchdog installation

The autonomous loop watchdogs are shell scripts in `bin/` that keep the loop alive 24/7 without a human. Install them via the unified launchd command:

```bash
atlas:loop:unified:install-launchd
```

This installs the watchdog launchd agents. The watchdogs grep the `ATLAS_LOOP_MASTER_ENABLED` .env line before respawning a campaign, so they never start the loop when the master switch is OFF.

The watchdog stack:

| Script | Role |
|--------|------|
| `bin/atlas-loop-watchdog.sh` | Kill over-budget grinds, automerge, main-health, keepalive, backlog-feed |
| `bin/atlas-loop-watchdog-supervised.sh` | Respawn the watchdog on crash while master switch is ON |
| `bin/atlas-loop-babysit-watchdog.sh` | Propose-only 24h self-heal (no automerge) |
| `bin/atlas-brain-soak.sh` | Drive the brain cycle per provider invocation |
| `bin/atlas-loop-report-recorder.sh` | Regenerate the merge-facts log from git history every 2 min |

See [watchdogs and master switch](../systems/cli-operator/watchdogs-and-master-switch.md) for the full watchdog lifecycle.

## The atlas CLI bootstrap

The bootstrap command sets up the full local environment:

```bash
./bin/atlas bootstrap --refresh-providers --strict
```

Bootstrap diagnoses provider binaries (Claude, Codex, Gemini), writes `.env` with backup, installs the launcher symlink to `~/.local/bin/atlas`, optionally writes the shell profile, optionally installs the scheduler cron, and runs the final doctor check.

For operator mode:

```bash
./bin/atlas bootstrap --operator-mode --operator-root=/Users/<user> --refresh-providers --strict
```

Verify readiness:

```bash
atlas doctor --strict
atlas final --strict
```

## Release process

The release flow is dogfood to final to release:

```bash
# Record real usage evidence across required scenarios
atlas dogfood run                    # safe smoke (not real evidence)
atlas dogfood record --scenario=dev_task --provider=codex_cli --result=passed --duration-minutes=90
atlas dogfood report --strict        # requires 3-day real-usage cover

# Final product readiness gate
atlas final --strict

# Release
atlas release --version=v2.0.0
```

`atlas dogfood run` is a safe smoke that validates integration and cleans up artifacts. `atlas dogfood report --strict` requires real (non-smoke) usage before release. The release gate checks version, worktree cleanliness, docs, CI, dogfood, and final.

Preflight-only (structure check without full gates):

```bash
atlas release --version=v2.0.0 --preflight --no-final --skip-dogfood --allow-dirty
```

## Local-first single-tenant model

The deployment is local-first. The server, database, loop, and workers all run on one Mac. There is no cloud deployment, no load balancer, no multi-region replication. The operator accesses the server over Tailscale from their iPhone and Mac. External AI tools (Claude Code, Codex, Cursor) connect to the Open Brain MCP server on the same machine.

This model is intentional. Atlas is a personal system that builds itself. The trust model, the security boundaries, and the autonomy scope ladder all assume a single operator on a single machine.

## Related pages

- [Security](security.md) — trust boundaries, auth, and the security model
- [CLI and operator surface](../systems/cli-operator/index.md) — bootstrap, doctor, release
- [Watchdogs and master switch](../systems/cli-operator/watchdogs-and-master-switch.md) — the 24/7 watchdog stack
- [Earned autonomy](../concepts/earned-autonomy.md) — master switches and the fail-closed model
- [Getting started](../overview/getting-started.md) — install and first run
- [Configuration](../reference/configuration.md) — the config file landscape and .env variables
