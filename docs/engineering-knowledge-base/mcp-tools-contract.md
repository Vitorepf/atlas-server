# Atlas MCP Tools Contract v1.1

Protocol: MCP 2025-06-18, STDIO local only.
Server: atlas-open-brain.

## Tool inventory (10)

### Read-only (8)
| Name | Purpose | Stable |
|---|---|---|
| atlas_memory_recall | Hybrid recall (registry + verbatim + semantic) | ✅ existing |
| atlas_open_brain_context_pack | Audited context pack para tarefas | ✅ existing |
| atlas_memory_maintenance_status | Health check | ✅ existing |
| atlas_decision_query | Recall filtrado a type=decision | 🆕 |
| atlas_code_find_relevant | Search code symbols (lexical via catalog) | 🆕 |
| atlas_docs_lookup | Search knowledge base items | 🆕 |
| atlas_recent_changes | Files mudados recentemente no workspace | 🆕 |
| atlas_workspace_info | Metadata do workspace (Atlas-tracked? scopes?) | 🆕 |
| atlas_capabilities | Lista todos tools + schemas (capability negotiation) | 🆕 |

### Write (1)
| Name | Purpose |
|---|---|
| atlas_memory_record | Engine reporta decisão tomada → fecha loop |

## Error taxonomy

Todo tool retorna `{ok: bool, tool: string, ...}`. Erros:

| Error code | Meaning | Retry safe? |
|---|---|---|
| `query_required` | Argumento obrigatório ausente | Não — input bug |
| `workspace_not_atlas_tracked` | Workspace fora dos repos conhecidos do Atlas | Não — chamada inadequada |
| `index_stale` | Code/docs index desatualizado vs. filesystem | Sim depois de re-index |
| `provider_safe_filter_empty` | Recall encontrou matches mas todos foram redacted | Não — escalar pra humano |
| `internal_error` | Exception não esperada | Sim com backoff |

## Annotations
- `readOnlyHint: true` para os 8 read tools
- `readOnlyHint: false, destructiveHint: false` para `atlas_memory_record` (write mas não destruidor)
