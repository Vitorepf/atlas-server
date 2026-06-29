# Tooling

## Code quality

### Pint (formatting)

Laravel Pint (`laravel/pint ^1.27`) enforces PSR-12 + Laravel preset formatting:

```bash
./vendor/bin/pint --test       # check without modifying
./vendor/bin/pint              # fix formatting
```

### Larastan (static analysis)

Larastan (`larastan/larastan ^3.10`) wraps PHPStan for Laravel-aware static analysis:

```bash
./vendor/bin/phpstan analyse
```

### Scribe (API docs)

Scribe (`knuckleswtf/scribe ^5.0`) generates API documentation from controller annotations and route definitions:

```bash
php artisan scribe:generate
```

### Infection (mutation testing)

Infection (`infection/infection ^0.33.2`) measures test quality by mutating code and checking whether tests catch it:

```bash
vendor/bin/infection
```

See [testing](testing.md) for how the Loop's certify phase also uses mutation testing.

### composer-unused

`composer-unused/composer-unused ^0.8` detects unused Composer dependencies:

```bash
vendor/bin/composer-unused
```

## CI workflows

CI runs on GitHub Actions with workflows in `.github/workflows/`:

| Workflow | File | Purpose |
|----------|------|---------|
| Atlas CLI | `atlas-cli.yml` | Tests, `git diff --check`, `atlas final --strict`, structural release gate with stubs in `scripts/ci` |
| Quality | `quality.yml` | Pint, Larastan, tests |
| Release | `release.yml` | Release pipeline |
| Security | `security.yml` | Security scanning |
| Labels | `labels.yml` | Issue/PR label management |

The `atlas-cli.yml` workflow is the mandatory CI for the CLI product. It runs `atlas final --strict` and the structural release gate using versioned stubs in `scripts/ci/`.

## Git hooks

```bash
composer atlas:install-hooks
# or
bash scripts/install-git-hooks.sh
```

This installs git hooks from `scripts/hooks/`. The hooks enforce quality checks before commits and pushes.

## The atlas CLI bootstrap

The `atlas` CLI (`bin/atlas`) is a bash launcher that resolves Homebrew PHP 8.4+ and dispatches to artisan. The bootstrap command sets up the full local environment:

```bash
./bin/atlas bootstrap --refresh-providers --strict
```

Bootstrap diagnoses provider binaries, writes `.env` with backup, installs the launcher symlink to `~/.local/bin/atlas`, optionally writes the shell profile, optionally installs the scheduler cron, and runs the final doctor check.

For operator mode:

```bash
./bin/atlas bootstrap --operator-mode --operator-root=/Users/<user> --refresh-providers --strict
```

See [getting started](../overview/getting-started.md) for the full setup flow.

## Composer scripts

The `composer.json` defines helper scripts:

| Script | Command | Purpose |
|--------|---------|---------|
| `composer test` | `config:clear` + `artisan test` | Run tests with clean config |
| `composer dev` | `artisan serve --host=0.0.0.0 --port=3737` | Start the dev server |
| `composer queue` | `queue:work --queue=transcription,default` | Start the transcription worker |
| `composer atlas:docs-gate` | `docs-health` + `documentation:enforce` | Canonical documentation gate |
| `composer atlas:install-hooks` | `bash scripts/install-git-hooks.sh` | Install git hooks |

## Related pages

- [Testing](testing.md) — PHPUnit, paratest, infection, frozen contracts
- [Development workflow](development-workflow.md) — the work cycle
- [Getting started](../overview/getting-started.md) — install and bootstrap
- [CLI and operator surface](../systems/cli-operator/index.md) — the atlas CLI product
- [Patterns and conventions](patterns-and-conventions.md) — code quality conventions
