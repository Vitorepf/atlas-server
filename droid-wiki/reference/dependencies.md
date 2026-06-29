# Dependencies

## Composer dependencies

From `composer.json`:

### Runtime

| Package | Version | Purpose |
|---------|---------|---------|
| `php` | ^8.4 | Language requirement (Homebrew on macOS) |
| `laravel/framework` | ^13.0 | The application framework |
| `laravel/tinker` | ^3.0 | Interactive REPL for Artisan |
| `smalot/pdfparser` | ^2.12 | PDF parsing for attachment ingestion |

The runtime dependency list is deliberately small. Atlas is a monolith that builds most of its intelligence in-house under `app/Services/Ai/`.

### Development

| Package | Version | Purpose |
|---------|---------|---------|
| `phpunit/phpunit` | ^12.5.12 | Test framework |
| `brianium/paratest` | ^7.20 | Parallel test execution |
| `infection/infection` | ^0.33.2 | Mutation testing |
| `larastan/larastan` | ^3.10 | Static analysis (PHPStan for Laravel) |
| `laravel/pint` | ^1.27 | Code formatting (PSR-12 + Laravel preset) |
| `knuckleswtf/scribe` | ^5.0 | API documentation generation |
| `fakerphp/faker` | ^1.23 | Test data generation |
| `mockery/mockery` | ^1.6 | Test doubles |
| `nunomaduro/collision` | ^8.6 | Beautiful error reporting |
| `laravel/pail` | ^1.2.5 | Log file tailing |
| `composer-unused/composer-unused` | ^0.8 | Unused dependency detection |

## External dependencies

### PostgreSQL 16 + pgvector

The database is PostgreSQL 16 with the `pgvector` extension. pgvector provides vector similarity search for semantic memory embeddings. The Docker image is `pgvector/pgvector:pg16`. The database stores all application state: captures, AI traces and jobs, canonical memory, loop campaigns, engineering evidence, agent governance state.

### whisper.cpp + ffmpeg

Audio transcription runs locally with whisper.cpp, assisted by ffmpeg for audio processing. The transcription worker (`php artisan queue:work --queue=transcription,default`) processes audio captures. This keeps audio processing on the local machine, never sending raw audio to an external service.

### Provider CLIs

The AI Gateway calls frontier models through already-authenticated provider CLIs running as child processes on the Mac:

| Provider | CLI | Notes |
|----------|-----|-------|
| Claude | `claude` | Anthropic Claude CLI |
| Codex | `codex` | OpenAI Codex CLI |
| Gemini | `gemini` | Google Gemini CLI |
| Hermes | (internal) | Atlas's executive provider/runtime (default) |
| Minimax | (internal) | minimax_m27_cli |
| Jarvis | (internal) | jarvis_mlx (local MLX) |

The app never calls model APIs directly. An interaction creates a trace and a job; the local worker (`atlas:ai:work`) runs the provider CLI and streams the result back. See [AI Gateway](../systems/ai-gateway/index.md).

### Docker

Docker Compose is used for local development and optional production deployment. The `docker-compose.yml` defines `db`, `backend`, `app`, and `queue` services. Docker is also used by the engineering harness for sandboxed execution. See [deployment](../deployment.md).

### Tailscale

The server runs behind Tailscale on a private network. The operator's iPhone and Mac connect over the Tailscale mesh. There is no public internet exposure. See [security](../security.md).

## Related pages

- [Configuration](configuration.md) — config files and .env variables
- [Data models](data-models.md) — migrations, models, and DB conventions
- [Getting started](../overview/getting-started.md) — install and first run
- [AI Gateway](../systems/ai-gateway/index.md) — the provider CLI lifecycle
- [Deployment](../deployment.md) — Docker Compose and external services
- [Testing](../how-to-contribute/testing.md) — PHPUnit, paratest, infection
