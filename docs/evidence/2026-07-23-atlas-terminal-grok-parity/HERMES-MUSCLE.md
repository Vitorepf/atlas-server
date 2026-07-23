# Hermes muscle on Terminal Dev (2026-07-23)

## Operator decision
Terminal Dev uses **only Hermes** (`hermes_cli` local binary), not Claude/Codex API keys.

## Config
- `config/atlas_terminal.php`
  - `provider=hermes_cli`
  - `default_provider_order=[hermes_cli]`
  - `hermes_allowed=true`
  - `hermes_cli_oneshot=true` (non-interactive `hermes -z`, avoids chat TTY hang)
  - `hermes_dry_run` for tests/offline

## Code
- `TerminalHermesBridge` → `HermesCliProvider` + `HermesWorkspaceDefaults`
- Runtime: natural language → Hermes; `tool:…` DSL → local tool host

## Verify
```bash
php artisan atlas:terminal:scorecard   # hermes_is_default
php artisan test --filter=AtlasTerminalSessionTest
bin/atlas terminal "resuma o README" --timeout=120
# offline:
bin/atlas terminal "x" --hermes-dry --json
```

## Residual
- Live hermes hang class P3: mitigated by oneshot + job timeout_seconds; not root-cause fixed.
- Rust TUI binary still needs Xcode CLT.
