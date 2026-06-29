# Patterns and conventions

## Database conventions

- **UUID primary keys** via `gen_random_uuid()`. No auto-incrementing integers.
- **`client_id` UNIQUE** on all ingestion tables for idempotent upsert. The client generates the UUID; replaying the same `client_id` updates rather than duplicates.
- **JSONB `metadata`** columns on most tables for extensible structured data.
- **Soft-deletes** (`deleted_at`) on most entities. The `/sync` endpoint uses `withTrashed` to sync deletions.
- **`set_updated_at()` trigger** in PostgreSQL automatically maintains `updated_at` (used as the sync cursor). Tables that should not auto-update (like `sync_log`) lack this trigger.
- **DB-level CHECK constraints** for enums (kind, domain, state, category_class, status). Invariantes live in the database, not just the ORM.
- **Migrations are idempotent.** Never manually `INSERT INTO migrations`. If the local DB diverges, rebuild with `migrate:fresh` rather than writing repair migrations.

## Append-only ledgers

Many subsystems (especially the Loop brain) persist as JSONL/NDJSON `*ReceiptLedger` files under `storage/app/atlas/` rather than database tables. These are append-only, tamper-evident, and often signed:

```
chain_hash = sha256(prev_hash . body_sha . signature)
```

The genesis hash is 64 zeros. Verification is fail-closed. Examples: `AtlasLoopCycleReceiptLedger`, `AtlasLoopGoodhartReceiptLedger`, `AtlasBrainHeartbeatLedger`.

## Service organization

Services live under `app/Services/`. The vast majority are under `app/Services/Ai/` (112 subdirectories). Each subsystem tends to have:

- A **Canon** class with constants (statuses, artifact types, gates, forbidden actions)
- A **Runtime/Contract** pair defining flows and compliance gates
- A **ControlPlaneProjection** for read-model snapshots
- A **ReadinessService** for health checks
- **Orchestrator** classes that plug into the AI Gateway

Large services (100KB+) are common. The largest is `AtlasSelfConstructionReadinessService.php` at ~52K lines. File size is not a refactoring priority in this codebase; the autonomous loop handles structural evolution.

## Testing

- **PHPUnit 12** with Unit and Feature suites
- **SQLite `:memory:`** for test isolation (configured in `phpunit.xml`)
- **`paratest`** for parallel test execution
- **`infection`** for mutation testing (the Loop's certify phase also uses mutation testing)
- Feature tests are organized by subsystem: `tests/Feature/Ai/`, `tests/Feature/Loop/`, `tests/Feature/Engineering/`, `tests/Feature/Marketing/`, etc.
- Frozen acceptance contracts have dedicated tests that the loop's frozen judge re-runs out-of-process

Run tests with:

```bash
php artisan test
# or parallel:
vendor/bin/paratest
```

## Code quality

- **Laravel Pint** for formatting (PSR-12 + Laravel preset). Run `./vendor/bin/pint --test`.
- **Larastan / PHPStan** for static analysis. Run `./vendor/bin/phpstan analyse`.
- **Scribe** for API documentation generation.

## Config

The central switchboard is `config/atlas.php` (very large). Focused config files supplement it: `atlas_ai.php`, `atlas_dev.php`, `atlas_vault.php`, `atlas_code_signing.php`, `atlas_venture_foundry.php`, `atlas_projects.php`, etc.

Master switches (`ATLAS_LOOP_MASTER_ENABLED`, `ATLAS_BRAIN_MASTER_ENABLED`, `ATLAS_FLEET_ENABLED`) are read from `.env` directly (not from config cache) to remain robust under `config:cache`. They default to OFF and are fail-closed.

## Anti-Goodhart discipline

Before touching anything in the autonomous evolution scope, ask: "does this evolve the scope exponentially, leaving Atlas more capable?" If the change is small cleanup, proxy-metric optimization, or behavior-preserving refactor, stop. The loop's perception organs emit only facts and counts (NO-SCALAR), never a learned score the system could game.

## Provider-safety

Every byte that can cross to an external AI goes through a provider-safety guard. Sensitive/secret content is excluded by construction. Memory is redacted to projections. Evidence uses ids/hashes only, never raw content. This is enforced by `AtlasMemoryPrivacyService`, the AURG provider floor, and `AtlasOpenBrainGuardService`.

## Commit style

Commits follow a `subsystem(scope): description` pattern, often with a co-author line for the AI agent that produced the change. The autonomous loop's commits are signed with `merged_sha` evidence. The loop merges only certified, re-proven changes to main.
