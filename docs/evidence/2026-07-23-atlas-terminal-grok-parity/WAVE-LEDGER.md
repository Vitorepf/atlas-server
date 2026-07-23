# Wave ledger — FINAL

| Wave | Status | Notes |
|------|--------|-------|
| W0 | **done** | product + AAP + evidence + terminal-first L2a + daily-map |
| W1 | **done** | session runtime, tools, Open Brain, hermes never default, AAP stdio/json |
| W2 | **done** | skills multi-tier + slash + plan guard + approve |
| W3 | **source done** | `Atlas/atlas-terminal` ratatui client; cargo blocked by Xcode CLT on this Mac |
| W4 | **done** | resume/sessions/fork/rewind/compact/context/rename/export/copy/approve |
| W5 | **done** | subagents explore/plan/general + worktree, hooks, MCP client, plugins catalog |
| W6 | **done** | AAP stdio full methods; Desktop bridge contract for Tauri/Mobile |
| W7 | **done** | /review + evidence receipts, /doctor, /forge /dev /autonomos /promote-forge |
| W8 | **done** | auto-compact, copy backup, scorecard **10.0/10**, help polish |

## Residual (honest)

1. **Rust binary** not linked until `xcode-select --install` / SDK clang works.
2. **Provider muscle** still local tool planner (no live Claude/Codex tool-call loop yet) — next leap after dogfood.
3. **atlas:review:deep** needs `--task-id` (Obra path); in-session review uses git status/diff + evidence.
4. MCP client is thin stdio (tools/list + tools/call); not full streaming MCP.

## Verify

```bash
php artisan atlas:terminal:scorecard          # 19/19 · 10.0/10
php artisan test --filter=AtlasTerminalSessionTest
bin/atlas terminal "/help"
bin/atlas terminal "leia README.md" --json
```
