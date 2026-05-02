---
id: atlas-memory-core-contracts
type: engineering_knowledge
title: Atlas Memory Core Contracts
status: active
category: architecture
priority: 98
summary: Contratos formais de tabelas, refs, APIs, CLI e configuracao do Memory Core, Knowledge Base e Code Intelligence.
tags:
  - atlas
  - memory
  - contracts
  - api
capabilities:
  - memory_refs
  - knowledge_refs
  - code_refs
  - api_contracts
  - cli_contracts
decisions:
  - Context packs devem carregar referencias pequenas e rastreaveis, nao dumps completos.
  - APIs e CLIs devem expor status e dry-run para operacao segura.
  - Contratos de refs sao parte da interface publica interna do Atlas.
maintenance:
  - Atualize este documento quando rotas, payloads, tabelas ou comandos mudarem.
  - Mantenha exemplos curtos e provider-safe.
related_paths:
  - routes/api.php
  - app/Http/Controllers/AtlasMemoryController.php
  - app/Http/Controllers/EngineeringKnowledgeController.php
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Engineering/EngineeringContextPackService.php
---

# Atlas Memory Core Contracts

Este documento define os contratos internos que permitem ao Atlas compor
contexto de IA de forma rastreavel.

## Tabelas Principais

| Tabela | Dono logico | Papel |
|---|---|---|
| `atlas_memory_entries` | Memory Registry | Memorias canonicas tipadas, escopadas e revisaveis |
| `atlas_memory_entry_usages` | Memory Usage Audit | Registra quais memorias foram usadas em traces/context packs |
| `atlas_memory_entry_relations` | Memory Governance | Duplicatas, conflitos e relacoes entre memorias |
| `atlas_verbatim_memories` | Verbatim Store | Evidencia textual exata e redigida |
| `ai_memory_deltas` | AI Memory Delta | Sugestoes de memoria antes de promocao |
| `atlas_memory_provider_projection_audits` | Provider Projection | Auditoria de projection review/apply/write/purge |
| `atlas_engineering_knowledge_items` | Engineering Knowledge Base | Docs canonicos indexados |
| `atlas_engineering_code_modules` | Code Intelligence | Modulos operacionais do codigo |
| `atlas_engineering_code_symbols` | Code Intelligence | Simbolos, rotas, comandos, migrations e testes |
| `atlas_engineering_doc_links` | Code Intelligence | Links docs->codigo e estado de cobertura |

## Contrato `memory_refs`

Origem: `AtlasMemoryRegistryService::relevantForContext()`.

Campos esperados:

```json
{
  "type": "atlas_memory_entry",
  "id": "uuid",
  "memory_type": "decision",
  "scope_type": "task",
  "scope_id": "uuid",
  "priority": 80,
  "importance": 4,
  "source_type": "engineering_run",
  "source_id": "uuid",
  "reason": "scoped_to_engineering_context"
}
```

Regras:

- deve apontar para uma memoria ativa;
- deve ter motivo de inclusao;
- nao deve carregar corpo completo quando um resumo/provider-safe basta;
- deve respeitar privacy e redaction antes de entrar em prompt.

## Contrato `verbatim_refs`

Origem: Verbatim Store.

Campos esperados:

```json
{
  "type": "atlas_verbatim_memory",
  "id": "uuid",
  "verbatim_type": "evidence",
  "scope_type": "project",
  "scope_id": "uuid",
  "snippet": "texto exato curto e redigido",
  "reason": "recall verbatim aprovado"
}
```

Regras:

- verbatim bloqueado nao entra em recall;
- texto sensivel deve ser redigido antes de provider;
- `include-verbatim` em CLI e excecao de operador, nao padrao.

## Contrato `knowledge_refs`

Origem: `EngineeringKnowledgeBaseService::contextRefs()`.

Campos esperados:

```json
{
  "type": "atlas_engineering_knowledge_item",
  "id": "uuid",
  "slug": "engineering-code-intelligence-index",
  "title": "Atlas Engineering Code Intelligence Index",
  "category": "architecture",
  "priority": 97,
  "canonical_path": "docs/engineering-knowledge-base/code-intelligence.md",
  "content_hash": "sha256",
  "summary": "Resumo curto",
  "reason": "matched_engineering_context"
}
```

Regras:

- docs canonicos orientam decisao, arquitetura e playbook;
- context pack seleciona path e resumo, nao o documento inteiro por padrao;
- `content_hash` permite detectar stale/drift.

## Contrato `code_refs`

Origem: `EngineeringCodeIntelligenceService::contextRefs()`.

Campos esperados:

```json
{
  "type": "atlas_engineering_code_module",
  "id": "uuid",
  "slug": "engineering_harness_services",
  "name": "Engineering Harness Services",
  "layer": "service",
  "root_path": "app/Services/Engineering",
  "docs_status": "documented",
  "file_count": 12,
  "symbol_count": 160,
  "route_count": 0,
  "command_count": 0,
  "migration_count": 0,
  "test_count": 8,
  "related_docs": ["docs/engineering-knowledge-base/code-intelligence.md"],
  "related_tests": ["tests/Feature/AtlasEngineeringKnowledgeBaseTest.php"],
  "reason": "important_code_module"
}
```

Regras:

- `root_path` e `related_tests` podem entrar em `selected_files`;
- `docs_status=undocumented` e lacuna de manutencao, nao ausencia de codigo;
- refs devem apontar para modulos, nao substituir leitura de codigo.

## Rotas De Memoria

| Metodo | Rota | Papel |
|---|---|---|
| `GET` | `/ai/memory` | Listar memorias |
| `POST` | `/ai/memory` | Criar memoria |
| `GET` | `/ai/memory/{memoryEntry}` | Mostrar memoria |
| `PATCH` | `/ai/memory/{memoryEntry}` | Atualizar/arquivar memoria |
| `GET` | `/tasks/{task}/memory` | Memorias de task |
| `GET` | `/projects/{project}/memory` | Memorias de projeto |
| `GET` | `/engineering/runs/{run}/memory` | Memorias de run |
| `GET` | `/ai/memory/audit/traces/{trace}` | Auditoria por trace |
| `POST` | `/ai/memory/privacy/scan` | Scan de privacy |
| `POST` | `/ai/memory/governance/scan` | Scan de governance |
| `GET` | `/ai/memory/review-queue` | Fila de revisao |
| `GET/POST/PATCH` | `/ai/memory/verbatim*` | Verbatim Store |
| `GET/POST` | `/ai/memory/provider-projection*` | Provider projections e auditoria |

## Rotas De Knowledge E Code Intelligence

| Metodo | Rota | Papel |
|---|---|---|
| `GET` | `/engineering/knowledge` | Catalogo de knowledge items |
| `GET` | `/engineering/knowledge/items/{item}` | Detalhe de knowledge item |
| `GET` | `/engineering/knowledge/context` | Preview de `knowledge_refs` |
| `POST` | `/engineering/knowledge/sync` | Sync docs canonicos -> Postgres |
| `POST` | `/engineering/knowledge/code/index` | Indexar codigo |
| `GET` | `/engineering/knowledge/code/audit` | Comparar scan dry-run com indice persistido sem escrita |
| `GET` | `/engineering/knowledge/code/modules` | Listar modulos |
| `GET` | `/engineering/knowledge/code/modules/{module}` | Detalhe de modulo |
| `GET` | `/engineering/knowledge/code/symbols` | Listar simbolos |

## CLI

| Comando | Papel |
|---|---|
| `atlas:memory:list` | Listar registry |
| `atlas:memory:add` | Criar memoria |
| `atlas:memory:audit` | Auditar uso em trace |
| `atlas:memory:privacy` | Scan/apply/review privacy |
| `atlas:memory:review-queue` | Fila unificada |
| `atlas:memory:govern` | Duplicatas/conflitos |
| `atlas:memory:relations` | Revisao de relacoes |
| `atlas:memory:verbatim` | Verbatim Store |
| `atlas:memory:projection` | Provider projections |
| `atlas:engineering:knowledge` | Knowledge Base, Code Intelligence e auditoria de drift |

## Configuracao Relevante

| Config/env | Papel |
|---|---|
| `ATLAS_AI_MEMORY_REGISTRY_LIMIT` | Limite de memorias do registry no contexto |
| `ATLAS_AI_MEMORY_REGISTRY_EXCERPT_CHARS` | Tamanho de excerpt do registry |
| `ATLAS_AI_VERBATIM_RECALL_LIMIT` | Limite de verbatim recall |
| `ATLAS_AI_VERBATIM_RECALL_BUDGET_CHARS` | Budget total de verbatim |
| `ATLAS_AI_MEMORY_RECALL_LIMIT` | Limite do recall composto |
| `ATLAS_AI_MEMORY_RECALL_BUDGET_CHARS` | Budget total do recall composto |
| `ATLAS_AI_MEMORY_RECALL_ITEM_CHARS` | Budget por item de recall |
| `ATLAS_AI_PROVIDER_PROJECTION_MAX_LINES` | Tamanho maximo da projection |
| `ATLAS_AI_PROVIDER_PROJECTION_MEMORY_LIMIT` | Quantidade de memorias em projection |
| `ATLAS_AI_PROVIDER_PROJECTION_MEMORY_CHARS` | Tamanho por memoria em projection |
| `ATLAS_PRIVACY_BLOCK_EXTERNAL_AI_FOR` | Classes bloqueadas para provider |
