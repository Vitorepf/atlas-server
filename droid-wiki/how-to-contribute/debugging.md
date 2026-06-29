# Debugging

## Where logs go

### Laravel logs

Application logs go to `storage/logs/laravel.log` (or stderr in Docker production, per `LOG_CHANNEL=stderr`). The log level is configurable via `LOG_LEVEL` in `.env`. For local development, `APP_DEBUG=true` gives full stack traces.

### Atlas ledger files

Many subsystems persist as append-only JSONL/NDJSON ledger files under `storage/app/atlas/` rather than database tables. These are the runtime truth for the Loop and the brain:

```
storage/app/atlas/loop/          # cycle receipts, goodhart receipts, merge receipts, leap receipts
storage/app/atlas/brain/         # brain heartbeat and perception ledgers
```

Each ledger line is a signed receipt with a chain hash. To inspect the cycle receipt chain:

```bash
atlas:loop:audit                 # audit the receipt chain integrity
atlas:loop:delivery-dossier      # delivery contracts and their fulfillment
atlas:loop:overview              # cycle overview with receipt status
```

## The Evidence Ledger

The Evidence Ledger is the runtime truth of the system. It records what actually ran: job attempts, gate verdicts, merge decisions, rollbacks. When debugging whether a change was certified and merged, check the Evidence Ledger, not a status field.

The `merged_sha` in a cycle receipt proves that close-on-main actually merged. Its absence means the cycle aborted. See [evidence and receipts](../concepts/evidence-and-receipts.md) for the receipt chain structure.

## Health endpoints and commands

### GET /health

The only public endpoint (no auth required):

```bash
curl http://localhost:3737/health
```

Returns a basic health check. Use this to verify the server is running.

### atlas doctor

```bash
atlas doctor --strict
```

Runs readiness gates: provider binaries, provider health, council capacity (Claude + Codex), permission scope, skills health, scheduler cron, provider projection, clipboard visual input, workspace quality. Returns `{passed|needs_review|failed}` with next actions.

### atlas health

```bash
atlas health
# or
atlas:ai:health
```

Checks the AI Gateway health: provider status, worker status, queue depth.

### Provider health

```bash
curl -H "X-Atlas-Token: $ATLAS_TOKEN" http://localhost:3737/ai/providers/status
```

Returns the status of each provider CLI (claude, codex, gemini, hermes). The `POST /ai/providers/check` endpoint runs a live check.

## AI worker events

The AI Gateway records every job attempt in the `ai_job_attempts` table: command, stdout, stderr, exit code, duration, status. When a provider call fails, check the attempt record:

```bash
# List recent jobs
curl -H "X-Atlas-Token: $ATLAS_TOKEN" http://localhost:3737/ai/jobs

# Get a specific job with attempts
curl -H "X-Atlas-Token: $ATLAS_TOKEN" http://localhost:3737/ai/jobs/{id}
```

The `ai_worker_events` table records worker lifecycle events (start, stop, crash, claim). The telemetry endpoints expose observability:

```bash
curl -H "X-Atlas-Token: $ATLAS_TOKEN" http://localhost:3737/ai/observability
curl -H "X-Atlas-Token: $ATLAS_TOKEN" http://localhost:3737/ai/telemetry/health
```

## Common errors and troubleshooting

### Database divergence

If the local database diverges from expected schema, rebuild from scratch. Do not write repair migrations:

```bash
php artisan migrate:fresh
```

See [patterns and conventions](patterns-and-conventions.md) for why migrations are idempotent and why manual `INSERT INTO migrations` is prohibited.

### Master switch is OFF

If the loop or brain is not running, check the master switches. They default to OFF and are fail-closed:

```bash
grep ATLAS_LOOP_MASTER_ENABLED .env
grep ATLAS_BRAIN_MASTER_ENABLED .env
```

The loop can never re-enable itself. Only the operator can turn these on by editing `.env`. See [earned autonomy](../concepts/earned-autonomy.md).

### MCP transport closed

If native MCP tools report "Transport closed" after a server crash, the raw MCP success proves the server is fine. Restart the provider client (Claude Code, Codex, Cursor) to re-register tools. This is the AOBG MCP transport restart rule.

### Provider not found

If `atlas doctor` reports a missing provider binary, verify the CLI is installed and on PATH:

```bash
which claude
which codex
which gemini
```

Re-run bootstrap to diagnose and write `.env`:

```bash
./bin/atlas bootstrap --refresh-providers --strict
```

### Stale context pack

If the context pack returns outdated information, the Postgres KB or Code Intelligence may be stale. Rebuild the read models:

```bash
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```

See [knowledge governance](../concepts/knowledge-governance.md) for why read models go stale.

## Related pages

- [Evidence and receipts](../concepts/evidence-and-receipts.md) — the receipt chain and `merged_sha`
- [Tooling](tooling.md) — CI workflows and quality tools
- [Development workflow](development-workflow.md) — the work cycle
- [CLI and operator surface](../systems/cli-operator/index.md) — doctor, health, and readiness gates
- [AI Gateway](../systems/ai-gateway/index.md) — job lifecycle and worker events
