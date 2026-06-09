---
id: atlas-code-graph-consumption
type: engineering_knowledge
title: Atlas Code Graph Consumption (MCP + UserPromptSubmit hook)
status: building
category: code-intelligence
priority: 88
implementation_state: implemented_mcp_and_hook_default_off_seam
summary: Como providers externos (Claude Code, Codex) consomem o code-graph context pack do Atlas — via MCP atlas-open-brain e via hook UserPromptSubmit que injeta o pack do atlas:ctx.
tags:
  - atlas
  - code-intelligence
  - code-graph
  - mcp
  - hooks
  - consumption
capabilities:
  - code_intelligence_index
  - external_graph_candidate
  - code_graph_context_pack
decisions:
  - O code-graph context pack chega ao provider por uma seam compartilhada flag-gated (atlas.code_graph.auto_context), default-OFF.
  - A recuperacao e sempre a mesma (atlas:ctx -> CodeGraphContextRetriever -> E-3 budgeted pack); MCP e hook sao apenas transportes.
  - O hook UserPromptSubmit e best-effort recall, nunca um gate; falha/zero-match = exit 0 silencioso.
  - Sem --workspace, a MCP resolve o workspace primario real (base_path -> 'atlas-server' via CodeGraphWorkspaceIdentity).
maintenance:
  - Atualize quando o signature/shape do pack, o config flag, ou o describe da MCP mudarem.
  - Rode docs-health, sync e index-code depois de promover qualquer parte para codigo.
  - Mantenha este doc abaixo de 260 linhas; detalhes longos ficam no AP-815 e na STATUS ledger.
related_paths:
  - docs/engineering-knowledge-base/code-intelligence/README.md
  - docs/ap/AP-815-cross-project-context-engine.md
  - .claude/hooks/atlas-ctx.sh
  - app/Console/Commands/AtlasCodeGraphContextCommand.php
  - app/Console/Commands/AtlasOpenBrainMcpCommand.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Engineering/CodeGraph/CodeGraphContextRetriever.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-code-graph-consumption

graph_title: Atlas Code Graph Consumption

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Code Graph Consumption
canonical_name: Atlas Code Graph Consumption
technical_name: atlas-code-graph-consumption
cartography_type: module
canonical_source: docs/engineering-knowledge-base/code-graph-consumption.md

owner: code-intelligence

repo_paths:
  - docs/engineering-knowledge-base/code-graph-consumption.md
  - .claude/hooks/atlas-ctx.sh

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.
---

# Atlas Code Graph Consumption

The Atlas code graph (AP-815) is consumed in three ways. All three resolve to the SAME
proven retrieval — `atlas:ctx` → `CodeGraphContextRetriever::packFor()` (keyword terms →
A3 BM25 ranked candidates → E-3 budgeted pack). MCP and the hook are only transports.

| Consumer | How the pack reaches the model | Gate |
|---|---|---|
| Atlas in-process (Dev/Forge/loop) | `AtlasOpenBrainContextInjectionService` renders a `## Code Graph Context` block into the provider prompt | `config('atlas.code_graph.auto_context')`, default **OFF** |
| Claude Code / Codex (MCP) | `atlas-open-brain` MCP server exposes `atlas_code_find_relevant` | always available (read-only) |
| Claude Code (hook) | `UserPromptSubmit` hook runs `atlas:ctx` and injects the pack as `additionalContext` | per-prompt; silent no-op when empty |

The in-process seam is flag-gated and default-OFF: with the flag off the injection is
byte-identical to the pre-wiring behaviour (no DB touch, no extra hash key, no `## Code
Graph Context` block). Flip it on with `ATLAS_CODE_GRAPH_AUTO_CONTEXT=true`
(budget tunable via `ATLAS_CODE_GRAPH_AUTO_CONTEXT_BUDGET`, default `4000`).

## 1. Claude Code / Codex MCP config

The `atlas-open-brain` MCP server serves Atlas Open Brain tools over local stdio,
including `atlas_code_find_relevant` (code-graph symbol lookup). Generate the exact local
config with:

```bash
php artisan atlas:open-brain:mcp --describe --json
```

Add this block to your Claude Code / Codex MCP config (`command` is the repo's
`bin/atlas`; `--describe` prints the absolute path for your machine):

```json
{
  "mcpServers": {
    "atlas-open-brain": {
      "command": "bin/atlas",
      "args": ["open-brain", "mcp"]
    }
  }
}
```

`bin/atlas` is the project launcher; use the absolute path that `--describe` emits if your
MCP client does not resolve project-relative commands. Exposed tools include
`atlas_memory_recall`, `atlas_open_brain_context_pack`, `atlas_memory_maintenance_status`
and `atlas_code_find_relevant`.

### Default workspace

Called **without** `--workspace`, the server now anchors to the real indexed primary —
`base_path()`, which `CodeGraphWorkspaceIdentity` maps to the stable `atlas-server`
workspace id — instead of leaking a stale `atlas.ai.workdir` value. Verify:

```bash
php artisan atlas:open-brain:mcp --describe --json   # → "workspace_id": "atlas-server"
```

Pass `--workspace <path|id>` to target a different indexed project; a non-primary path
gets its own resolved id.

## 2. UserPromptSubmit hook (Claude Code)

`.claude/hooks/atlas-ctx.sh` is a `UserPromptSubmit` hook that, on every prompt, runs the
proven retrieval and injects the resulting pack as additional context — **best-effort
recall, never a gate**. It reads the event JSON on stdin, extracts `.prompt`, runs
`php artisan atlas:ctx "$PROMPT" --budget=4000 --json` from `$CLAUDE_PROJECT_DIR`, and:

- when `included_count > 0` → emits a `UserPromptSubmit` envelope whose `additionalContext`
  carries the rendered symbols;
- otherwise (no match, empty prompt, missing `jq`/`php`, any error) → exits `0` silently.

Wire it in your `settings.json` (project `.claude/settings.json` or user-level):

```json
{
  "hooks": {
    "UserPromptSubmit": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "$CLAUDE_PROJECT_DIR/.claude/hooks/atlas-ctx.sh"
          }
        ]
      }
    ]
  }
}
```

The hook is committed at `755`. The emitted shape is:

```json
{
  "hookSpecificOutput": {
    "hookEventName": "UserPromptSubmit",
    "additionalContext": "# Atlas Code Graph Context (atlas:ctx)\nworkspace=atlas-server  query=...  included=1  ~42/4000 tokens\n\n- sym:... [path] type=class; tokens=42; sig=class ..."
  }
}
```

Requires `jq` and `php` on `PATH`; if either is missing the hook is a silent no-op so it
can never block a prompt.

## Notes

- `atlas:ctx` is itself fail-safe: an empty/too-short query, no match, a missing read-model
  table, or a transient DB fault all resolve to an empty pack at exit `0`.
- The MCP `atlas_code_find_relevant` read path returns symbols from the indexed read-model;
  per-workspace query scoping at the read path is tracked separately (see AP-815 / the
  STATUS ledger, W-3). The default-workspace fix here corrects the resolved/reported
  identity to the primary.
- Detailed contract: `docs/ap/AP-815-cross-project-context-engine.md`.
