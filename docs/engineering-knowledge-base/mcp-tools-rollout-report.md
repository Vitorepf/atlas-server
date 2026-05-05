---
id: atlas-mcp-tools-rollout-report-legacy
type: engineering_knowledge
title: MCP Tools Expansion Rollout Report Legacy
status: archived
category: mcp_legacy
priority: 20
summary: Relatorio historico do rollout MCP de 2026-05-03; preservado para auditoria, nao como contrato atual.
tags:
  - mcp
  - rollout
  - legacy
decisions:
  - Este arquivo e evidencia historica de entrega, nao fonte primaria de arquitetura.
  - O estado atual do Open Brain MCP deve ser validado por testes, comando describe e docs canonicos de Memory/Open Brain.
maintenance:
  - Nao expandir este relatorio; criar novo relatorio datado se houver outro rollout.
superseded_by:
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/memory-core-contracts.md
---

# MCP Tools Expansion Rollout Report

> Status: archived. Relatorio historico de rollout, nao contrato operacional.

Date: 2026-05-03
Phases complete: 1, 2, 3, 4, 5, 6 (lifecycle), 7 (drill-down/analysis/composition)
Server version: 1.1.0 (was 1.0.0)
Plan: docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md

## Inventory delivered

**21 tools total** (3 existing + 18 new across two waves).

### Wave 1 — Plan Phases 1-3 (10 tools)

#### Existing
- `atlas_memory_recall` — hybrid recall (registry + verbatim + semantic)
- `atlas_open_brain_context_pack` — audited context pack
- `atlas_memory_maintenance_status` — health check

#### New
- `atlas_memory_record` — write-back, fecha o loop inbound→outbound
- `atlas_code_find_relevant` — busca símbolos no índice (8.7k+)
- `atlas_docs_lookup` — KB queryable
- `atlas_capabilities` — capability negotiation
- `atlas_workspace_info` — classificação rápida de workspace
- `atlas_recent_changes` — git log + drift do índice
- `atlas_decision_query` — recall filtrado a `memory_type=decision`

### Wave 2 — Phase 6 (lifecycle, 6 tools)

#### Orchestration lifecycle
- `atlas_task_start` — cria AtlasTask, retorna task_id
- `atlas_task_progress` — registra AtlasTaskEvent (milestone)
- `atlas_task_complete` — fecha task com summary, files_changed, links a memórias

#### Memory lifecycle
- `atlas_memory_archive` — status=archived, archived_at=now (preserva histórico)
- `atlas_memory_link` — cria AtlasMemoryEntryRelation (duplicate/conflict)
- `atlas_memory_supersede` — old→new com superseded_by_id (migration idempotente)

### Wave 3 — Phase 7 (drill-down + analysis + composition, 5 tools)

- `atlas_memory_get` — corpo completo + relações de uma entry específica (provider-safe filter aplicado)
- `atlas_module_info` — módulo + símbolos + doc_links
- `atlas_route_info` — rotas HTTP do projeto (filtrando símbolos `symbol_type=route`)
- `atlas_test_for` — testes (`symbol_type=test_method`) que matcham um target
- `atlas_context_for` — meta-tool: monta context pack ad-hoc combinando recall + code_find + docs_lookup numa só chamada

## Tests

- Feature tests em `tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php`: **28 tests passando** (84 assertions)
- Benchmark tests em `tests/Feature/Ai/AtlasOpenBrainMcpBenchmarkTest.php`: 2 tests passando
- Test concerns criados/expandidos:
  - `CreatesAtlasMemoryEntryTable` (atualizado com `superseded_by_id`)
  - `CreatesAtlasEngineeringCodeTables`
  - `CreatesAtlasEngineeringKnowledgeTables`
  - `CreatesAtlasTaskTables` (novo Phase 6.1)
  - `CreatesAtlasMemoryEntryRelationsTable` (novo Phase 6.2)

## Performance

- Cold start (raw PHP boot + handshake + tools/list): **0.376s real** — daemon mode dispensável pra uso on-demand
- Warm recall: 0.12s (budget 0.5s) — asserted in benchmark
- Warm capabilities: 0.01s (budget 0.1s) — asserted in benchmark

## Migrations

Phase 6.3 introduziu **1 migration idempotente** seguindo a regra mestre:

`database/migrations/2026_05_04_010000_add_superseded_by_to_atlas_memory_entries.php`

```php
if (! Schema::hasColumn('atlas_memory_entries', 'superseded_by_id')) {
    $table->uuid('superseded_by_id')->nullable()->after('archived_at');
    $table->foreign('superseded_by_id')->references('id')->on('atlas_memory_entries')->nullOnDelete();
}
```

Rodou clean contra DB real (40.64ms). Sem `INSERT INTO migrations` manual.

## Integration

- Claude Code (CLI): MCP loads from arbitrary cwd — `atlas-open-brain: ✓ Connected` em `/tmp`
- Tools/list: 21 tools enumerated
- Smoke tests: todos os tools novos retornaram `isError: false`

## Memory curation (Phase 4)

Atlas memory antes: 5 entries genéricas sobre o próprio Atlas.
Atlas memory depois: 8 entries (5 originais + 3 canônicas do master CLAUDE.md):

- `decision[global]` Migrations: nunca INSERT INTO migrations manualmente (priority 89)
- `decision[global]` Database em dev: fresh > reparo (priority 88)
- `technical_context[global]` Invariantes de schema vivem no DB, não no ORM (priority 87)

`atlas-server/CLAUDE.md` e `atlas-server/AGENTS.md` reprojetados via `atlas:memory:projection apply --target=all`.
Master `/Users/vitorepf/Develop/atlas/CLAUDE.md` também recebeu as entries via projection.

## Manual follow-ups (require human)

- [ ] Open Claude Code em `/tmp/atlas-mcp-test`, prompt: "como o projeto Atlas lida com migrations que conflitam com schema vivo?". Verificar se `atlas_decision_query` ou `atlas_memory_recall` é chamado e se a resposta cita "nunca INSERT INTO migrations" e "fresh > reparo".
- [ ] Mesmo teste com Codex CLI.
- [ ] Se não chamar Atlas: regra global em `~/.claude/CLAUDE.md` precisa ser endurecida (mover pra topo).

## Known limitations / out-of-scope follow-ups

- **Auto-injection da regra imperativa**: Atlas não injeta a regra de consulta no system prompt quando spawna engines. Workaround atual: global `~/.claude/CLAUDE.md` + `~/.codex/AGENTS.md`. Long-term: orchestrator deve injetar.
- **Daemon mode**: cold start de 0.376s aceitável; daemon mode eliminaria pra cenários de alta frequência. Out of scope desta phase.
- **`atlas_audit_event`** não implementado. `atlas_task_complete` cobre parcialmente o caso (registra event_type=completed em AtlasTaskEvent).
- **Policy contextual** ainda binária via `external_ai_allowed`. Não há filtro por tópico/sensibilidade.
- **`atlas_workspace_info`** infere Atlas-tracked contando memory entries — não checa registro explícito de projeto. Melhoria: lookup dedicado em `AtlasProject` model.
- **`atlas_code_find_relevant`, `atlas_route_info`, `atlas_test_for`** são lexicais (substring em name/path/signature). Semantic vector search não exposta.
- **`atlas_memory_link`** limitado a TYPES `[duplicate, conflict]` (hardcoded em `AtlasMemoryEntryRelation::TYPES`). Expandir requer migration na enum.
- **`atlas_test_for`** é heurística (substring match em nome do test). Não há análise real de coverage entre tests e símbolos cobertos.

## Score evolution

| Dimensão | Pré-rollout | Pós-Phase 5 | Pós-Phase 7 |
|---|---|---|---|
| Surface de tools | 3/10 | 7/10 (10 tools) | **10/10** (21 tools) |
| Bidirecionalidade | 1/10 | 5/10 (write tool) | **9/10** (write + lifecycle + relations) |
| Conteúdo da memória | 1/10 | 6/10 (3 regras seedadas) | 6/10 |
| Estabilidade | 7/10 | 8/10 (cold 0.376s, 14 testes) | **9/10** (28 testes feature + 2 benchmark) |
| Auto-injeção de regras | 0/10 | 0/10 | 0/10 |
| Setup/installer | 2/10 | 3/10 | 3/10 |
| Arquitetura | 8/10 | 8/10 | 8/10 |
| DX/Discovery | 3/10 | 6/10 | **8/10** (memory_get + module_info drill-down + context_for composition) |

**Média:** ~3 → ~5.5 → **~7/10**. Saiu de embrião funcional pra MVP defensável pra **MVP completo + features avançadas**.

Próximo nível (7 → 8.5) precisa do orquestrador injetar regras + auto-installer + content de memória de outros projetos (blackink, atlas-app).

## Commits desta jornada (cronologicamente)

```
2a0d11c docs(mcp): add tools contract v1.1 with 10-tool inventory and error taxonomy
ec5c1b4 feat(mcp): add atlas_memory_record tool to close inbound→outbound loop
3c8e49a fix(mcp): update instructions for write tool; align test trait softDeletesTz with prod schema
5e529e7 feat(mcp): add atlas_code_find_relevant tool exposing 8.7k symbol index
1217329 fix(mcp): remove layer param from atlas_code_find_relevant — backend has no layer filter on symbols
73979c6 feat(mcp): add atlas_docs_lookup tool exposing knowledge base
3b848a5 feat(mcp): add atlas_capabilities for capability negotiation, bump to 1.1.0
4c2040b feat(mcp): add atlas_workspace_info for fast workspace discovery
d2b200e feat(mcp): add atlas_recent_changes for git+index drift awareness
c65677b feat(mcp): add atlas_decision_query for type=decision filtered recall
d25f3a8 feat(memory): seed 3 canonical decisions from master CLAUDE.md, reproject
391be2a docs(mcp): rollout report v1.1.0 + benchmark tests
c2cca3b feat(mcp): add task lifecycle tools (start/progress/complete)
42c2dad feat(mcp): add memory archive + link tools for lifecycle management
b212cf1 feat(mcp): add atlas_memory_supersede with idempotent superseded_by_id migration
cbc9936 feat(mcp): add drill-down + analysis + composition tools (21 total)
```
