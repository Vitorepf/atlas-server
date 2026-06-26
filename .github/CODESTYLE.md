# Atlas Server Code Style & Naming Convention

This repository follows an explicit, enforced naming convention derived from the
Atlas canonical glossary. The goal is to make code predictable for humans and
for autonomous agents that read or edit the codebase.

## General PHP rules

- `declare(strict_types=1);` is required on every new PHP file.
- PSR-12 + Laravel Pint are enforced in CI.
- PHPStan level 5 (Larastan) is the current baseline; level increases are planned.
- Maximum class cyclomatic complexity: 15 average per class; 30 for a single method.

## Naming

### Classes

| Layer | Pattern | Example |
|-------|---------|---------|
| Console command | `Atlas{Domain}{Verb}Command` | `AtlasAiHealthCommand` |
| Service | `Atlas{Domain}{Noun}Service` | `AtlasOpenBrainContextService` |
| Controller | `Atlas{Domain}{Noun}Controller` | `AtlasAiHealthController` |
| Job | `Atlas{Domain}{Verb}Job` | `AtlasTranscriptionJob` |
| Exception | `Atlas{Domain}{Issue}Exception` | `AtlasProviderTimeoutException` |
| Test | `{ClassUnderTest}Test` | `AtlasAiHealthTest` |

### Methods

- Verbs in camelCase: `resolve`, `collect`, `provide`, `certify`, `reconcile`.
- Boolean predicates: `is`, `has`, `can`, `should`: `isReady()`, `hasCapability()`.
- Avoid `get`/`set` for business operations; prefer domain verbs.

### Variables

- camelCase; no abbreviations except canonical acronyms (AOBG, AURG, AUCRI, ACRI, TEOS).
- `$dto` and `$id` are allowed; `$data` and `$tmp` are discouraged.

## Namespaces

- `App\Console\Commands\{Domain}`
- `App\Services\{Domain}`
- `App\Http\Controllers\{Domain}`
- `App\Jobs\{Domain}`
- `App\Models`
- `App\Support`

## Tests

- Mirror source path under `tests/Unit` and `tests/Feature`.
- One test class per public class.
- Feature tests use real database/integrations; unit tests use fakes.

## Feature flags

- All new risky behavior is gated behind `ATLAS_*_ENABLED` env vars.
- The flag must be read through `config()`, not `env()`, after the first boot.

## Documentation

- Provider projections (`AGENTS.md`, `CLAUDE.md`) are generated from Atlas memory.
- Do not edit them manually; update Atlas memory and regenerate.
- Architecture decisions live in `docs/engineering-knowledge-base/` as canonical Markdown.
