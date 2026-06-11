---
id: atlas-open-brain-context-injection
type: engineering_knowledge
title: Atlas Open Brain Context Injection
status: active
category: architecture
priority: 99
summary: Compact canonical runtime profile for automatic Open Brain context injection in Atlas dev, continue, chat, app programming, review and debug flows.
tags:
  - atlas
  - open-brain
  - context-pack
  - cli
  - app-ai
capabilities:
  - open_brain_context_injection
  - app_ai_context_injection
  - provider_safe_recall
  - audited_context_pack
  - context_delivery_policy_projection
  - context_expansion_handle_resolution
decisions:
  - Open Brain context injection is Core runtime behavior, not a manual workbench step.
  - Surfaces declare intent and policy; atlas-server composes, audits and injects context.
  - Automatic context must be provider-safe, budgeted, traceable, deduplicated and reversible.
  - When ATER/ACRS provides a context delivery policy, Open Brain must project the minimal initial packet and expansion handles instead of dumping full docs, tests or graph output.
  - Expansion handles are resolved on demand by a read-only provider-safe command; the initial pack stays small and the provider asks for a specific source type when needed.
  - Projection apply, MCP write tools, remote sync, ChromaDB and external embeddings stay outside this flow until a dedicated AP promotes them.
maintenance:
  - Read this file before changing atlas dev, atlas continue, atlas chat, AiPromptBuilder, AiGatewayService or AtlasAiSheet.
  - Keep this file compact; move transport details to memory/open-brain-mcp.md and retrieval details to memory/retrieval-and-context.md.
  - Full historical source is archived in archive/source-material/open-brain-context-injection-full-2026-05-08.md.
related_paths:
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/archive/source-material/open-brain-context-injection-full-2026-05-08.md
  - app/Services/Ai/AtlasOpenBrainService.php
  - app/Services/Ai/AtlasOpenBrainContextExpansionService.php
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Ai/ValueObjects/AiContextPack.php
  - app/Services/Ai/AiPromptBuilder.php
  - app/Services/Ai/AiGatewayService.php
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AtlasCliContinueCommand.php
  - app/Console/Commands/AiChatCommand.php
  - app/Console/Commands/AtlasOpenBrainContextCommand.php
  - app/Console/Commands/AtlasOpenBrainExpandContextCommand.php
  - app/Models/AtlasOpenBrainAccessLog.php
  - routes/api.php
  - atlas-app/components/sheets/AtlasAiSheet.tsx
  - atlas-app/lib/api/client.ts
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-open-brain-context-injection

graph_title: Atlas Open Brain Context Injection

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Open Brain Context Injection
canonical_name: Atlas Open Brain Context Injection
technical_name: atlas-open-brain-context-injection
cartography_type: module
canonical_source: docs/engineering-knowledge-base/open-brain-context-injection.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/open-brain-context-injection.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture

evidence:
  - docs/engineering-knowledge-base/open-brain-context-injection.md
evidence_refs:
  - symbol: AtlasOpenBrainService
  - symbol: AtlasOpenBrainContextInjectionService
  - symbol: AtlasOpenBrainContextExpansionService
  - symbol: AiContextPack
  - command: atlas:open-brain:context
  - command: atlas:open-brain:expand-context
  - mcp_tool: atlas_context_expand
  - test: AtlasOpenBrainContextInjectionServiceTest
  - test: AtlasOpenBrainContextExpansionServiceTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php"
  - "php artisan test tests/Unit/Ai/AtlasOpenBrainContextExpansionServiceTest.php"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Open Brain Context Injection

This is the active runtime profile for automatic Open Brain injection. It is
kept compact on purpose so a fresh AI session can understand the rule in one
pass. The full 2026-05-03 implementation narrative is preserved at
`archive/source-material/open-brain-context-injection-full-2026-05-08.md`.

## Authority

| Concern | Canonical owner |
|---|---|
| Injection activation, prompt placement and failure semantics | This file |
| MCP/API/HTTP export boundary | `memory/open-brain-mcp.md` |
| Context refs, ranking, budget and provider-safe retrieval | `memory/retrieval-and-context.md` |
| Memory schemas, projections and privacy contracts | `memory/contracts.md` |
| Historical implementation notes | `archive/source-material/open-brain-context-injection-full-2026-05-08.md` |

## Runtime Rule

Open Brain injection is Core behavior. CLI, app, mobile, voice or MCP surfaces
must not manually paste memory into prompts. They submit task metadata and
policy hints to `atlas-server`; the backend composes a provider-safe context
packet, audits it, attaches trace metadata and injects one prompt section.

```mermaid
flowchart TD
    SURFACE["Surface\nCLI / App / Mobile / Voice / MCP"]
    GATEWAY["AiGatewayService"]
    PROMPT["AiPromptBuilder"]
    INJECTION["Open Brain Injection Profile"]
    CONTEXT["Context Builder\nmemory + KB + code refs"]
    AUDIT["Access Log + Trace Metadata"]
    PROVIDER["Provider Driver"]

    SURFACE --> GATEWAY --> PROMPT --> INJECTION
    INJECTION --> CONTEXT
    INJECTION --> AUDIT
    PROMPT --> PROVIDER
```

## Supported Surfaces

| Surface | Default policy | Notes |
|---|---|---|
| `atlas dev` | `auto` | Inject for programming tasks when context is available. |
| `atlas dev --complete` | `required` recommended | Heavy programming should fail closed when required context is missing. |
| `atlas continue` | `auto` | Reuse previous thread/task refs when safe; refresh when requested. |
| `atlas chat --mode=dev` | `auto` | Programming chat uses the same injection path as dev. |
| `atlas chat --mode=debug` | `auto` | Prioritize code refs, recent failures and relevant traces. |
| `atlas chat --mode=review` | `auto` | Prioritize contracts, changed files, gates and prior decisions. |
| Atlas App programming/review/debug | `auto` | App declares mode; backend owns context composition. |
| Mobile/voice realtime | `auto` through Surface Adapter | Never bypass Kernel or Decision Receipt. |

Programming flows `programming.dev`, `programming.debug`,
`programming.review`, `programming.repair`, `programming.refactor`,
`programming.qa`, `programming.security`, `programming.database`,
`programming.visual` and `programming.forge` activate Open Brain even when the
surface mode is generic/direct. This prevents repair or resumed work from
depending on session memory.

## Policy Modes

| Mode | Behavior |
|---|---|
| `off` | Skip injection intentionally and record the reason in trace metadata. |
| `auto` | Inject when useful and provider-safe; degrade gracefully if context is unavailable. |
| `required` | Missing/unsafe context fails closed before provider execution. |

Supported hints:

- `budget_chars`: maximum prompt budget for the Open Brain section.
- `refresh`: rebuild context instead of reusing recent safe context.
- `require`: convert unavailable context into `failed_closed`.
- `provider_safe_only`: always `true` for automatic injection.
- `context_delivery_policy`: optional provider-safe policy computed by ATER/ACRS
  with initial token budget, expansion reserve, initial/deferred source types and
  guarded required-source recheck handles.

## Injection Request

Minimum conceptual shape:

```json
{
  "surface": "cli_dev",
  "mode": "dev",
  "workspace": "/absolute/workspace",
  "objective": "short task objective",
  "task_id": "optional",
  "thread_id": "optional",
  "provider": "claude|codex|openai|gemini|local",
  "model": "optional",
  "policy": {
    "mode": "auto",
    "budget_chars": 20000,
    "refresh": false,
    "require": false,
    "provider_safe_only": true
  }
}
```

Required fields: `surface`, `mode`, `workspace`, `objective`,
`policy.mode`, `policy.provider_safe_only`.

## Injection Result

Minimum conceptual shape:

```json
{
  "enabled": true,
  "status": "injected",
  "reason": "mode_requires_open_brain",
  "context_pack_hash": "sha256",
  "audit_id": "uuid",
  "prompt_section": "# Atlas Open Brain Context\n...",
  "summary": {
    "memory_refs": 4,
    "knowledge_refs": 3,
    "code_refs": 8,
    "budget_chars": 20000,
    "provider_safe": true
  },
  "safety": {
    "schema_version": "atlas.open_brain.context_pack_safety.v1",
    "provider_safe_only": true,
    "raw_content_exposed": false,
    "raw_content_persisted": false,
    "audit_persisted": true
  },
  "warnings": []
}
```

## Prompt Placement

The prompt must contain exactly one Open Brain section:

1. system and Atlas identity;
2. execution instructions and safety/policy;
3. task contract and user objective;
4. `# Atlas Open Brain Context`;
5. user request or generated development plan.

The injection service must deduplicate existing context pack content. Repeated
context belongs in Core retrieval/cache logic, not in surface-specific prompts.

## Audit And Evidence

Every injection attempt writes or updates trace metadata with:

- surface, mode, workspace and objective hash;
- policy mode, budget and provider-safe flag;
- status, reason and warnings;
- context pack hash and prompt section hash;
- memory/knowledge/code ref counts;
- audit id from `AtlasOpenBrainAccessLog` when persisted.

Programming injections also include `summary.programming_context` and a
`## Programming Context` prompt section with flow/profile, resume state,
plan/review/patch/test/repair stage contract, selected files, prior runs,
previous traces and prior decisions. This is the automatic bridge from Memory,
Open Brain, Code Intelligence and engineering history into dev/debug/review/
repair without relying on chat-session memory.

When `context_delivery_policy.status=active`, Open Brain also emits
`summary.context_delivery_policy`, synthetic provider-safe refs for
`expand:<source_type>` and `recheck:<source_type>`, and a compact
`## Context Delivery Policy` prompt block. This block is advisory and
provider-safe: it carries source types, token budgets, expansion reserve,
quality-gate hint and policy claims, never raw docs/tests/graph dumps or raw
feedback text. Inactive or provider-unsafe policies are ignored so legacy
prompt/hash behavior remains unchanged.

When the provider needs a deferred or guarded source, it should call MCP
`atlas_context_expand` or CLI
`./bin/atlas open-brain expand-context <handle> "<objective>" --json`. The expansion
resolver is read-only, local-only and provider-safe. Ranking-backed sources
(`evidence_replay`, `code_intelligence`, `memory_signals`, `vector_retrieval`)
return filtered refs with hashes, reasons and score components, never raw source
refs. `recheck:canonical_doc` returns a compact AOBG pack excerpt with the
objective redacted plus counts, sources present, budget and a recheck warning.
Unsupported source types fail safe with `status=unsupported` and no provider
call or write.

The prompt block must also carry the operational expansion contract:
`expansion_tool: mcp=atlas_context_expand`, concrete `expansion_handles`, and an
`implementation_gate` line when guarded required sources exist. This is what
turns staged context delivery into provider behavior: use the small first packet,
then expand exact source types before code changes or before requesting broad
docs/tests/graph dumps.

After execution, external providers can close the loop with
`atlas_context_feedback`. The tool accepts only provider-safe refs and source
types (`delivered_context_refs`, `used_context_refs`, `noise_context_refs`,
`missed_required_sources`, outcome and utility score), then returns AUCRI
context ROI, ref attribution and the next context policy. It does not accept
raw failure narratives, never auto-applies learning, and persists feedback only
when `record=true`.

For external context exports, `include_prompt=true` defaults to compact prompt
mode. Compact mode keeps the ranked recall index, summaries, source counts and
expansion handles, but defers registry bodies, verbatim snippets and semantic
excerpts. Full prompt mode is still available for audit with
`prompt_mode=full` or `./bin/atlas open-brain context ... --prompt-mode=full`.
The compact prompt must remain provider-safe and expansion-first: do not paste
raw docs, tests, memory bodies or semantic excerpts into the initial provider
packet when a focused expansion handle can recover the exact source.

Prompt exports also emit `summary.prompt` using
`atlas.open_brain.prompt_metrics.v1`. The summary records mode, chars, lines,
estimated tokens, full-mode baseline size, saved chars/lines/tokens,
compaction ratios, deferred-body status and expansion-handle count. These
metrics are persisted in `atlas_open_brain_access_logs.result_summary_json`
when auditing is available, but the rendered `prompt_section` itself is never
persisted. This lets Atlas learn provider context cost and utility without
creating parallel prompt memory or storing raw provider packets.

`atlas_memory_maintenance_status` aggregates those prompt metrics as
`open_brain_prompt_metrics` using
`atlas.open_brain.prompt_metric_aggregate.v1`. The aggregate is bounded to the
recent audit window, counts compact/full/unknown modes, averages chars and
estimated tokens, reports saved chars/tokens, and raises review signals for low
compact savings, full-mode dominance or any prompt-section persistence
violation. A running MCP client may need restart to expose newly added fields,
but `./bin/atlas open-brain mcp --once=...` always validates the current local
server code path.

Self-Improvement consumes the same audit trail. The
`domain_learning_review`/memory-quality review path emits
`atlas.self_improvement.open_brain_prompt_metrics.v1` when prompt metrics show
low compact savings, full-mode dominance, unknown prompt modes or any raw
prompt persistence. The finding references only provider-safe metric rows
(`open_brain_prompt_metric` refs with ids, hashes, modes, counts and ratios),
never the rendered prompt text.

The code intelligence slice is expected to include AST-backed PHP relations
when available (`php_use_ast`, `class_constant_ast`,
`test_symbol_reference_ast`), plus dependency edges, symbol references,
test targets and doc links.

Important context injection events are evidence. They can feed read models and
Self-Improvement proposals, but they cannot silently promote memory or mutate
critical behavior.

## Retrieval Availability

When a retrieval plan marks a source as required, Open Brain must explain
availability by source and fail closed in `required` mode if that source is
missing. `evidence_replay` is available when any provider-safe replay reference
exists in context refs or in `evidence.previous_traces`,
`evidence.replay_events` or `evidence.replay_refs`. `replay_refs` is the
preferred compact contract for attached ledger/envelope evidence.

## Failure Semantics

| Status | Meaning | Runtime behavior |
|---|---|---|
| `injected` | Context was attached successfully. | Continue provider execution. |
| `skipped` | Policy or mode intentionally disabled injection. | Continue and record reason. |
| `degraded` | Partial safe context was attached. | Continue with warning. |
| `failed_open` | Non-required context failed. | Continue without injection and record warning. |
| `failed_closed` | Required context failed or was unsafe. | Stop before provider execution. |

## Forbidden

- Surface-specific memory prompt assembly.
- Provider-owned memory merge.
- Secrets or non-provider-safe memory in automatic context.
- MCP write tools in this path.
- ChromaDB, external vector search or new embedding stores without AP approval.
- Remote multiuser sync/SSE as part of this injection profile.
- Automatic projection apply from context usage alone.
- Treating `context_delivery_policy` as permission to remove must-keep evidence;
  guarded required sources must be expanded/rechecked before implementation.

## Validation

Run after changing injection policy, prompt placement, request/result metadata
or Open Brain runtime integration:

```bash
php artisan test tests/Unit/Ai tests/Feature/Ai
php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
php artisan test tests/Unit/Ai/AtlasOpenBrainContextExpansionServiceTest.php
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
```

## Definition Of Done

- All supported surfaces enter through the same backend injection profile.
- `required` mode fails closed before provider execution.
- Automatic context remains provider-safe and budgeted.
- Prompt contains one deduplicated Open Brain section.
- Trace/audit metadata can explain why context was injected, skipped or failed.
- No surface, provider or tool decides memory, provider, policy or promotion.

## Resumo

Compact canonical runtime profile for automatic Open Brain context injection in Atlas dev, continue, chat, app programming, review and debug flows.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
