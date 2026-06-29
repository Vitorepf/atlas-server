# MCP server and tools

## Purpose

The Atlas Open Brain Gateway exposes itself as a Model Context Protocol (MCP)
server named `atlas-open-brain`. Any external AI that speaks MCP (Claude Code,
Codex, Cursor) can plug into it, in any project, and call ~60 read and write
tools. The server is read-only-by-default, provider-safe, local-DB-only (zero
provider spend), and fail-open. This page documents
`app/Services/Ai/AtlasOpenBrainMcpService.php`, the JSON-RPC lifecycle, the
tool catalogue, the self-check/fingerprint, and how the server is registered
with each provider.

## Key abstractions

| Path / constant | Role |
|---|---|
| `app/Services/Ai/AtlasOpenBrainMcpService.php` | The MCP server: `tools()`, `handleJsonRpc`, `callTool`, self-check |
| `app/Console/Commands/AtlasOpenBrainMcpCommand.php` | `atlas:open-brain:mcp` (stdio; `--describe`, `--once`, `--workspace`) |
| `app/Http/Controllers/AtlasOpenBrainMcpController.php` | HTTP transport at `/api/ai/open-brain/mcp` when `open_brain.mcp.http_enabled` |
| `app/Services/Ai/Kernel/Mcp/OpenBrainMcpInput.php` | Input normalisation for MCP tool calls |
| `.mcp.json` | In-repo Claude Code registration |
| `scripts/setup-aobg.sh` | Codex (`~/.codex/config.toml`) + Cursor (`~/.cursor/mcp.json`) registration |
| `AtlasOpenBrainMcpService::PROTOCOL_VERSION` | `2025-06-18` |
| `AtlasOpenBrainMcpService::SERVER_VERSION` | `1.2.0` |
| `AtlasOpenBrainMcpService::RUNTIME_SCHEMA` | `atlas.open_brain.mcp.runtime.v1` |

## How it works

### JSON-RPC lifecycle

`handleJsonRpc(array $request)` handles a single JSON-RPC request (or a batch
of them). The method dispatch is:

| Method | Handler | Returns |
|---|---|---|
| `initialize` | `initializeResult($request)` | protocol/server version, capabilities |
| `ping` | empty object | liveness |
| `tools/list` | `tools()` | the ~60 tool definitions (name, title, description, `inputSchema`, `annotations`) |
| `tools/call` | `callTool($id, $request)` | dispatches to a private handler by tool name |
| other | error `-32601` | method not found |

A notification (a request without an `id`) returns `null` per JSON-RPC. Batch
requests (a JSON array of request objects) are handled element-by-element and
the non-null responses are returned as an array.

### Tool annotations

Every tool carries an `annotations` block with the standard MCP hints:

- `readOnlyHint` — the tool performs no write.
- `destructiveHint` — the tool can destroy data (none of the Open Brain tools
  are destructive; writes are non-destructive proposal/record writes).
- `openWorldHint` — whether the tool interacts with an open world (false for
  all Open Brain tools; they are local-DB only).

Recall and context tools set `readOnlyHint: true`. Write tools
(`atlas_memory_record`, `atlas_record_outcome`, `atlas_propose_learning`,
`atlas_context_feedback`, `atlas_claim_task`, `atlas_task_*`) set
`readOnlyHint: false, destructiveHint: false`.

### Tool catalogue (selection of ~60)

The tools cluster into a few groups. This is a selection; the full list is in
`AtlasOpenBrainMcpService::tools()`.

| Tool | Group | Read-only | Purpose |
|---|---|---|---|
| `atlas_context_pack` | Brain | yes | The unified context pack (N1.F1 front door) |
| `atlas_open_brain_context_pack` | Brain | yes | HTTP-style audited context-pack export |
| `atlas_context_expand` | Brain | yes | Expand a pack handle (`expand:` / `recheck:`) |
| `atlas_context_feedback` | Brain | no | Record provider-safe context feedback |
| `atlas_context_for` | Brain | yes | Per-file brain-delta (N2.F1) |
| `atlas_code_neighbors` | Code | yes | Code-graph neighbors of a file/symbol |
| `atlas_code_path` | Code | yes | Code-graph path between two symbols |
| `atlas_code_explain` | Code | yes | Explain a code symbol |
| `atlas_code_find_relevant` | Code | yes | Find code relevant to a task |
| `atlas_docs_lookup` | Docs | yes | Look up canonical repo docs |
| `atlas_aurg_query` | Reality | yes | Query the AURG reality graph |
| `atlas_cross_domain_query` | Reality | yes | Cross-domain graph traversal |
| `atlas_ccr_retrieve` | Compression | yes | Retrieve a CCR (compressed context) |
| `atlas_memory_recall` | Memory | yes | Hybrid recall over redacted projections |
| `atlas_memory_record` | Memory | no | Write a canonical memory entry |
| `atlas_memory_get` | Memory | yes | Fetch one entry by id/ref |
| `atlas_memory_archive` | Memory | no | Archive an entry |
| `atlas_memory_link` | Memory | no | Link two entries |
| `atlas_memory_supersede` | Memory | no | Supersede an entry (temporal-truth) |
| `atlas_memory_maintenance_status` | Memory | yes | Memory health check |
| `atlas_record_outcome` | Write-back | no | Record what an external session did (N1.F2) |
| `atlas_propose_learning` | Write-back | no | Propose a learning (branch-only) |
| `atlas_claim_task` | Coordination | no | Claim a target on the blackboard (N2.F4) |
| `atlas_blackboard_status` | Coordination | yes | Active claims / conflicts |
| `atlas_next_task` / `atlas_task_start` / `atlas_task_progress` / `atlas_task_complete` / `atlas_task_report` | Tasks | no | Task lifecycle hooks |
| `atlas_workspace_info` / `atlas_workspace_status` / `atlas_workspace_map` / `atlas_workspace_fleet_map` / `atlas_workspace_activate` | Workspace | mixed | Workspace identity, status, fleet, activation |
| `atlas_capabilities` | Meta | yes | Server capabilities |
| `atlas_mcp_self_check` | Meta | yes | Runtime fingerprint vs expected (see below) |
| `atlas_session_bootstrap` | Kernel | yes | Session bootstrap for a task |
| `atlas_feature_placement` | Kernel | yes | Feature placement for a new flow |
| `atlas_domain_catalog` | Kernel | yes | Domain catalog |
| `atlas_architecture_validate` / `atlas_architecture_operations` / `atlas_architecture_readiness` | Kernel | yes | Architecture validation/operations/readiness |
| `atlas_runtime_boundary` | Kernel | yes | Runtime language boundary report |
| `atlas_decision_query` / `atlas_decision_receipt_report` | Evidence | yes | Decision + receipt lookup |
| `atlas_recent_changes` | Evidence | yes | Recent changes in the workspace |
| `atlas_mission_history` | Evidence | yes | Mission history |
| `atlas_obra_status` | Evidence | yes | Obras operating-system status |
| `atlas_*_report` (kernel SLO, pipeline, repair loop, inbox action, agent behavior, provider performance, dynamic compute market, ledger projection health) | Reports | yes | Kernel/evidence/report projections |

### Self-check and runtime fingerprint

`atlas_mcp_self_check` compares the loaded runtime fingerprint and feature
flags against what the current AOBG behavior expects. The server advertises
`RUNTIME_FEATURE_FLAGS` (e.g. `context_expand_tool`, `context_feedback_tool`,
`context_pack_runtime_fingerprint`, `workspace_activation`,
`blackboard_coordination`, `mcp_runtime_self_check`) via `atlas_capabilities`
and the self-check. A native MCP client that does not see these flags is
stale (typically because the server binary was updated but the client process
was not restarted). The self-check surfaces this so the client can restart or
fall back to the CLI (`bin/atlas open-brain context`).

The server also records `processStartedAt` so a long-lived stdio process can
report its age.

### Registration

Three providers plug into the same local invocation:
`bin/atlas open-brain mcp`, which auto-scopes the workspace from the caller's
CWD.

| Provider | Where it is registered | File |
|---|---|---|
| Claude Code | In-repo, picked up automatically | `.mcp.json` |
| Codex | `~/.codex/config.toml` (TOML table `[mcp_servers.atlas-open-brain]`) | `scripts/setup-aobg.sh --install` |
| Cursor | `~/.cursor/mcp.json` (JSON entry `mcpServers."atlas-open-brain"`) | `scripts/setup-aobg.sh --install` |

`scripts/setup-aobg.sh` defaults to `--print` (dry run, shows the exact stanzas
and a dry diff). With `--install` it is idempotent (skips when a registration
already exists) and writes a timestamped `.bak` before any change. The in-repo
`.mcp.json` and the Claude Code hooks are the only files the gateway build
writes; external global configs are the operator's to apply.

### Claude Code hooks (asymmetry, honestly stated)

In Claude Code, `PreToolUse` and `PostToolUse` hooks
(`atlas-pretooluse-guard.sh`, `atlas-postedit-context.sh`) read the blackboard
and fire the per-file brain-delta **automatically**. Codex and Cursor do not
have rich hooks: they reach the same brain only via the MCP tools, called on
demand. The brain is the same; the delivery differs. See
[blackboard-and-write-back.md](blackboard-and-write-back.md).

## Integration points

- **Context pack**: `atlas_context_pack` delegates to
  `AtlasOpenBrainContextPackService`. See [context-pack.md](context-pack.md).
- **Write-back**: `atlas_record_outcome` / `atlas_propose_learning` delegate to
  `AtlasOpenBrainWriteBackService`. See
  [blackboard-and-write-back.md](blackboard-and-write-back.md).
- **Blackboard**: `atlas_claim_task` / `atlas_blackboard_status` delegate to
  `AtlasAobgBlackboardService`. See
  [blackboard-and-write-back.md](blackboard-and-write-back.md).
- **Memory**: `atlas_memory_*` tools delegate to the memory services. See
  [canonical-memory.md](canonical-memory.md) and
  [memory-governance-and-lifecycle.md](memory-governance-and-lifecycle.md).

## Key source files

| File | What |
|---|---|
| `app/Services/Ai/AtlasOpenBrainMcpService.php` | The MCP server (~60 tools, JSON-RPC, self-check) |
| `app/Console/Commands/AtlasOpenBrainMcpCommand.php` | `atlas:open-brain:mcp` (stdio, `--describe`, `--once`) |
| `app/Http/Controllers/AtlasOpenBrainMcpController.php` | HTTP transport |
| `app/Services/Ai/Kernel/Mcp/OpenBrainMcpInput.php` | MCP input normalisation |
| `.mcp.json` | Claude Code registration |
| `scripts/setup-aobg.sh` | Codex + Cursor registration |
| `config/atlas.php` | `open_brain.mcp.http_enabled`, `open_brain.mcp.allowed_origins` |
