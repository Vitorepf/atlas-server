# Blockers resolved (2026-07-23)

| Blocker | Resolution |
|---------|------------|
| Rust TUI no binary (Xcode SDK) | Built with `DEVELOPER_DIR=/Library/Developer/CommandLineTools` + CLT clang → `atlas-terminal/target/release/atlas-term` + `~/.local/bin/atlas-term` |
| `bin/atlas terminal` always launched TUI (broke --json/oneshot) | TUI only if interactive TTY **and** no positional/--json/--stdio |
| Non-TTY atlas-term crash | Guard `is_terminal` + exit 2 with usage |
| Hermes hang P3 (orphans) | Terminal oneshot `hermes -z`; `reapCliProcess` SIGTERM/SIGKILL group + `pkill -P`; Process `create_new_process_group` when supported |
| Provider wrong (claude/codex default) | Hermes-only default |

## Still operator dogfood
- Live multi-minute hermes quality in real repos
- Full Grok UX polish (fold, @ fuzzy rich) iterative on TUI

## Verify
```bash
php artisan atlas:terminal:scorecard   # includes atlas_term_binary
php artisan test --filter=AtlasTerminalSessionTest
bin/atlas terminal "tool:file.read path=README.md" --json
# interactive TUI:
bin/atlas terminal
```
