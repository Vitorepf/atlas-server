---
id: atlas-memory-core-runbook
type: engineering_knowledge
title: Atlas Memory Core Runbook
status: active
implementation_state: runbook_no_runtime
authority_class: runbook
category: maintenance
priority: 99
summary: Compact operational runbook for validating, syncing, auditing and recovering Atlas memory, knowledge base, code intelligence and Open Brain.
tags:
  - atlas
  - memory
  - runbook
  - operations
capabilities:
  - memory_core_runbook
  - knowledge_base
  - code_intelligence_runbook
  - provider_projection_runbook
  - context_recall_runbook
  - open_brain_runbook
decisions:
  - Memory operations must be auditable, repeatable and small.
  - Dry-run precedes destructive actions, purge and provider projection apply/write.
  - Canonical docs and code index must be synced together after relevant changes.
  - Open Brain injection in code flows must be validated through CLI, trace and app contracts.
maintenance:
  - Keep this runbook compact and command-oriented.
  - Prefer /opt/homebrew/bin/php for Artisan in local Atlas operations.
  - Full historical runbook is archived in archive/source-material/memory-core-runbook-full-2026-05-08.md.
related_paths:
  - docs/engineering-knowledge-base/memory/README.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory/open-brain-mcp.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/archive/source-material/memory-core-runbook-full-2026-05-08.md
  - app/Console/Commands/AtlasMemory
  - app/Console/Commands/AtlasMemoryMaintenanceCommand.php
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Models/AtlasMemory
  - app/Services/Ai/AtlasMemoryMaintenanceService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-memory-core-runbook

graph_title: Atlas Memory Core Runbook

graph_world: atlas

graph_layer: module

graph_kind: runbook

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Memory Core Runbook
canonical_name: Atlas Memory Core Runbook
technical_name: atlas-memory-core-runbook
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/memory-core-runbook.md

owner: maintenance

repo_paths:
  - docs/engineering-knowledge-base/memory-core-runbook.md

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
  - maintenance

evidence:
  - docs/engineering-knowledge-base/memory-core-runbook.md
evidence_refs:
  - symbol: EngineeringKnowledgeBaseService
  - command: atlas:engineering:knowledge

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - runbook
  - maintenance

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
# Atlas Memory Core Runbook

This is the daily operational runbook. Load focused contracts before changing
behavior; use this file when validating, syncing or recovering memory systems.

## Operating Rules

- Use `/opt/homebrew/bin/php` for Artisan on the local Mac.
- Run dry-run before destructive writes, purge or provider projection apply.
- After docs changes, run docs sync and code index together.
- After core code changes, run code index and focused tests.
- Provider files are generated projections, not source of truth.
- Open Brain/MCP is read-only unless a future AP explicitly promotes writes.

## Fast Health Check

```bash
/opt/homebrew/bin/php artisan migrate:status
/opt/homebrew/bin/php artisan route:list --path=memory
/opt/homebrew/bin/php artisan route:list --path=open-brain
/opt/homebrew/bin/php artisan route:list --path=engineering/knowledge
/opt/homebrew/bin/php artisan atlas:engineering:knowledge status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge code-status --json
```

Focused smoke:

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
```

## Sync Docs And Code

```bash
./bin/atlas engineering knowledge sync --prune --json
./bin/atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
./bin/atlas engineering knowledge code-status --json
./bin/atlas engineering knowledge modules --docs-status=undocumented --json
```

Use `audit-code` when you need drift inspection without writing:

```bash
./bin/atlas engineering knowledge audit-code --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

## Memory Operations

```bash
./bin/atlas memory list --limit=30 --json
./bin/atlas memory recall "task context" --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
./bin/atlas memory quality --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
./bin/atlas memory quality history --workspace=/Users/vitorepf/develop/Atlas/atlas-server --days=30 --json
./bin/atlas memory maintain --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

For privacy/governance:

```bash
./bin/atlas memory privacy scan --limit=200 --json
./bin/atlas memory govern scan --dry-run --json
./bin/atlas memory review-queue --include-unreviewed --limit=100 --json
```

## Provider Projection

```bash
./bin/atlas memory projection status --target=all --workspace=/Users/vitorepf/develop/Atlas --json
./bin/atlas memory projection review --target=all --workspace=/Users/vitorepf/develop/Atlas --json
./bin/atlas memory projection apply --target=all --workspace=/Users/vitorepf/develop/Atlas --yes --json
./bin/atlas memory projection audit-summary --json
```

Projection apply is allowed only after review. If status reports empty
provider-safe memory, seed or promote reviewed memory before applying.

## Open Brain And MCP

```bash
./bin/atlas open-brain context "continue implementation" --workspace=/Users/vitorepf/develop/Atlas/atlas-server --include-prompt --json
./bin/atlas open-brain context "audit full context" --workspace=/Users/vitorepf/develop/Atlas/atlas-server --include-prompt --prompt-mode=full --json
./bin/atlas open-brain expand-context recheck:canonical_doc "continue implementation" --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
./bin/atlas open-brain mcp --describe --json
./bin/atlas open-brain mcp --once='{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Rules:

- recall/context exports are provider-safe by default;
- `include_prompt` defaults to compact mode and defers memory bodies/semantic excerpts to expansion handles;
- prompt exports persist only `summary.prompt` metrics (`atlas.open_brain.prompt_metrics.v1`), never the rendered prompt text;
- `atlas_memory_maintenance_status` exposes `open_brain_prompt_metrics` aggregates for compact/full usage, token savings and prompt-persistence regressions;
- Self-Improvement emits provider-safe findings when prompt metrics show low savings, full-mode dominance or prompt-persistence regressions;
- `atlas_context_feedback` lets external providers return provider-safe context ROI signals (`used_refs`, `noise_refs`, `missed_sources`) after execution; it is proposal-only and persists only when `record=true`;
- MCP writes are non-destructive and gated; destructive tools and automatic policy promotion require future AP;
- every export should write audit metadata when the audit table exists;
- Streamable HTTP/SSE, destructive MCP tools and multiuser sync require future AP.

## Open Brain Injection Smoke

```bash
./bin/atlas chat --mode=dev "smoke Open Brain injection" --json
./bin/atlas chat --mode=review "smoke review context" --json
./bin/atlas dev --plan-only "smoke dev plan" --json
./bin/atlas continue --json
```

Expected:

- dev/debug/review/programming modes expose `open_brain_injection` or
  `open_brain_preview`;
- `direct` chat skips automatic injection unless explicitly opted in;
- required mode fails closed when provider-safe context cannot be built;
- no compact CLI JSON should include raw `prompt_section` or raw context refs.

## AtlasVault Smoke

```bash
./bin/atlas vault status --json
./bin/atlas vault sync --dry-run --limit=20 --json
./bin/atlas vault conflicts --limit=20 --json
```

Vault commands must preserve human notes, block unsafe paths and create review
items instead of silently promoting memories.

## Phase Close Validation

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
git diff --check
```

Do not close a memory/Open Brain phase while docs-health regresses, context
exports bypass provider-safety, or projection status needs review.

## Resumo

Compact operational runbook for validating, syncing, auditing and recovering Atlas memory, knowledge base, code intelligence and Open Brain.

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
