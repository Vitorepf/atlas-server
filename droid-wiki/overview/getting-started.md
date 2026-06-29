# Getting started

## Prerequisites

- **PHP 8.4+** (Homebrew on macOS: `/opt/homebrew/bin/php`). The `bin/atlas` launcher resolves this automatically.
- **PostgreSQL 16** with the `pgvector` extension. The Docker Compose setup includes this.
- **Composer** for PHP dependencies.
- **whisper.cpp** (optional, for audio transcription) with ffmpeg.
- **Provider CLIs** (optional, for the AI Gateway): `claude`, `codex`, `gemini` installed and authenticated on the Mac.

## Install

```bash
git clone https://github.com/Vitorepf/atlas-server.git
cd atlas-server
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set `ATLAS_TOKEN` to a secure random string. This static token authenticates all API requests via the `X-Atlas-Token` header.

## Database

Start PostgreSQL (Docker or local):

```bash
docker compose up -d db
```

Run migrations:

```bash
php artisan migrate
```

The database uses UUID primary keys (`gen_random_uuid`), `client_id` UNIQUE constraints for idempotent upserts, JSONB `metadata` columns, soft-deletes, and DB-level CHECK constraints for enums. A `set_updated_at()` trigger handles timestamp updates. Migrations are idempotent; never manually insert rows into the `migrations` table.

If the local database diverges, rebuild from scratch rather than writing repair migrations:

```bash
php artisan migrate:fresh
```

## Run

Start the API server:

```bash
php artisan serve --host=0.0.0.0 --port=3737
```

Start the transcription worker (if audio captures are used):

```bash
php artisan queue:work --queue=transcription,default --tries=3 --timeout=5400
```

Start the AI worker (if the AI Gateway is used):

```bash
php artisan atlas:ai:work
```

Start the scheduler (for recurring Rize sync and other scheduled tasks):

```bash
php artisan schedule:work
```

## Atlas CLI setup

The recommended setup flow is the bootstrap command, which diagnoses providers, writes `.env`, installs the launcher, and runs the final doctor check:

```bash
./bin/atlas bootstrap --refresh-providers --strict
```

For operator mode (governed control over the user's home directory):

```bash
./bin/atlas bootstrap --operator-mode --operator-root=/Users/<user> --refresh-providers --strict
```

Verify readiness:

```bash
atlas doctor --strict
atlas final --strict
```

## Test

```bash
php artisan test
```

For parallel execution:

```bash
vendor/bin/paratest
```

Tests use SQLite `:memory:` (configured in `phpunit.xml`). The test suite has Unit and Feature suites. Feature tests are organized by subsystem (Ai, Loop, Engineering, Marketing, CodeGraph, etc.).

## Code quality

```bash
./vendor/bin/pint --test          # formatting (PSR-12 + Laravel preset)
./vendor/bin/phpstan analyse      # static analysis (larastan)
./vendor/bin/infection            # mutation testing
```

## Docker

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d --build
```

| Service | Port | Purpose |
|---------|------|---------|
| `db` | 5433 -> 5432 | PostgreSQL + pgvector |
| `backend` | 3737 | Laravel API |
| `queue` | - | transcription worker |
| `scheduler` | - | recurring tasks (Rize sync, etc.) |

## Key environment variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `ATLAS_TOKEN` | (required) | Static auth token for all API requests |
| `ATLAS_STORAGE_PATH` | (required) | Local hash-based file storage path |
| `ATLAS_AI_ENABLED` | false | Enable the AI Gateway |
| `ATLAS_AI_DEFAULT_PROVIDER` | hermes_cli | Default provider for AI interactions |
| `ATLAS_LOOP_MASTER_ENABLED` | false | Master switch for the autonomous loop (fail-closed, operator-only) |
| `ATLAS_BRAIN_MASTER_ENABLED` | false | Master switch for the brain (fail-closed, operator-only) |
| `ATLAS_FLEET_ENABLED` | false | Master switch for the fleet control plane |
| `RIZE_API_KEY` | (optional) | Rize GraphQL API key (server-only, never sent to the app) |
| `RIZE_SYNC_ENABLED` | false | Enable scheduled Rize pull sync |

All master switches default to OFF and are fail-closed: the loop/brain/fleet can never re-enable themselves. Only the operator can turn them on by editing `.env`.
