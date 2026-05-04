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
  - open_brain_context_injection
  - obsidian_atlas_vault
decisions:
  - Context packs devem carregar referencias pequenas e rastreaveis, nao dumps completos.
  - APIs e CLIs devem expor status e dry-run para operacao segura.
  - Contratos de refs sao parte da interface publica interna do Atlas.
  - Injecao automatica de Open Brain deve ser provider-safe, auditada e centralizada no backend.
  - Obsidian/AtlasVault e camada humana bidirecional, nao fonte operacional primaria.
maintenance:
  - Atualize este documento quando rotas, payloads, tabelas ou comandos mudarem.
  - Mantenha exemplos curtos e provider-safe.
related_paths:
  - routes/api.php
  - app/Http/Controllers/AtlasMemoryController.php
  - app/Console/Commands/AtlasVaultCommand.php
  - app/Services/Semantic/AtlasVaultManagedNoteService.php
  - app/Services/Semantic/AtlasVaultFrontmatterService.php
  - app/Services/Semantic/AtlasVaultLinkService.php
  - app/Http/Controllers/EngineeringKnowledgeController.php
  - app/Services/Ai/AiContextPackBuilder.php
  - app/Services/Ai/AiPromptBuilder.php
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/AtlasOpenBrainService.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
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
| `atlas_open_brain_access_logs` | Open Brain | Auditoria de exports de context pack por API/CLI/ferramenta |
| `atlas_vault_sync_items` | AtlasVault Sync | Fila persistente de import/export/sync, conflitos e resolucao explicita |

## Contrato Obsidian / AtlasVault

O contrato canonico esta em `obsidian-atlas-vault.md`.

Resumo obrigatorio:

- Obsidian/AtlasVault pode importar notas para `semantic_notes` e candidatos de memoria;
- o Atlas pode exportar notas humanas gerenciadas com frontmatter e links `atlas://`;
- notas do vault nao viram `atlas_memory_entries` sem classificacao, privacy, redaction e review;
- exports do Atlas para o vault sao projections humanas, nao fonte primaria;
- conflitos devem ser marcados, nunca sobrescritos silenciosamente;
- Open Brain deve consumir dados indexados/promovidos, nao ler Obsidian direto.

Fase 1 implementada:

- `AtlasVaultFrontmatterService` monta, renderiza, parseia e valida o
  frontmatter minimo de nota gerenciada;
- `AtlasVaultLinkService` gera e parseia `atlas://memory/{id}`,
  `atlas://verbatim-memory/{id}`, `atlas://semantic-note/{id}`,
  `atlas://task/{id}`, `atlas://project/{id}`,
  `atlas://engineering-run/{id}`, `atlas://open-brain/audit/{id}` e
  `atlas://trace/{id}`;
- `AtlasVaultManagedNoteService` compoe dry-run, cria nota gerenciada, atualiza
  somente quando seguro, preserva `## Manual Notes` e bloqueia overwrite em
  arquivo nao gerenciado, com frontmatter invalido, sem markers gerenciados,
  com markers duplicados/desordenados ou com drift fora do bloco permitido;
- flags `atlas_managed`, `provider_safe` e `canonical` em frontmatter
  gerenciado precisam parsear como booleanos reais; strings como `maybe` sao
  erro de input ou conflito de nota existente, nao estado valido;
- `privacy_class=secret` implica `provider_safe=false`; a combinacao
  `secret/provider_safe=true` e bloqueada em input e marcada como invalida em
  nota existente;
- `redaction_status=blocked` ou `redaction_status=needs_review` tambem implica
  `provider_safe=false`; essas combinacoes sao bloqueadas em input e marcadas
  como invalidas em nota existente;
- `updated_at` precisa ser timestamp ISO-8601 com timezone explicito; timestamps
  malformados sao rejeitados em input e marcados como `invalid_updated_at` em
  nota existente.
- `AtlasVaultManagedNoteService` tambem bloqueia `--write` quando uma nota
  existente ja esta em `sync_status=conflict` ou quando campos estaveis de
  frontmatter foram alterados manualmente;
- payloads de nota gerenciada incluem `operation`, `existing_content_hash`,
  `proposed_content_hash`, `write_blocked_reason` quando aplicavel e conflicts
  em formato auditavel;
- IDs usados em nota gerenciada e URI `atlas://` sao segmentos seguros
  (`A-Za-z0-9._:-`) e nao podem conter `/`, `\`, `..` ou espacos;
- paths canonicos em blocos de links sao relativos e nao aceitam traversal;
- labels de links em blocos `## Links Atlas` sao normalizados para uma linha,
  precisam ser nao vazios e nao podem conter caracteres de markdown que tornem o
  alvo ambiguo;
- `--path` customizado para nota gerenciada precisa ser relativo ao vault; paths
  absolutos sao recusados antes da normalizacao/confinamento;
- `VaultFileStore::absolutePath()` bloqueia traversal, diretorio symlinkado para
  fora do vault e arquivo symlinkado diretamente para fora do vault antes de
  permitir leitura ou escrita;
- `AtlasVaultCommand` expoe `atlas:vault status --json` e
  `atlas:vault note ... --dry-run|--write --json`, com `--content` opcional para
  atualizar apenas o bloco gerenciado;
- `atlas:vault note --dry-run` nao cria arquivo nem diretorio de vault ausente;
  criacao de diretorio raiz acontece apenas no caminho explicito de `--write`
  quando nao ha conflito;
- booleanos recebidos por `atlas:vault note`, como `--provider-safe`, sao
  estritos e recusam valores ambiguos.
- `AtlasVaultCommand` aceita `--canonical-doc=<path>` e
  `--link=Label=atlas://type/id` para incluir backlinks humanos extras, mantendo
  validacao de path relativo e URI `atlas://`;
- links extras programaticos malformados sao recusados, nao ignorados
  silenciosamente;
- `atlas:vault status --json` reporta tipos de nota suportados e tipos de link
  suportados para descoberta operacional.
- `atlas:vault status` e read-only: reporta vault ausente sem criar diretorio ou
  arquivo.

Tipos de nota aceitos na fundacao atual:

| `atlas_type` | URI primaria | Path deterministico |
|---|---|---|
| `memory_entry` | `atlas://memory/{id}` | `Atlas/Memory/memory_entry/{slug}-{id}.md` |
| `verbatim_memory` | `atlas://verbatim-memory/{id}` | `Atlas/Memory/verbatim_memory/{slug}-{id}.md` |
| `semantic_note` | `atlas://semantic-note/{id}` | `Atlas/SemanticNotes/{slug}-{id}.md` |
| `task` | `atlas://task/{id}` | `Atlas/Tasks/managed/{slug}-{id}.md` |
| `project` | `atlas://project/{id}` | `Atlas/Projects/{slug}-{id}.md` |
| `engineering_run` | `atlas://engineering-run/{id}` | `Atlas/Engineering/Runs/{slug}-{id}.md` |
| `open_brain_audit` | `atlas://open-brain/audit/{id}` | `Atlas/OpenBrain/Audits/{slug}-{id}.md` |
| `trace` | `atlas://trace/{id}` | `Atlas/Traces/{slug}-{id}.md` |

Fase 2 implementada:

- `atlas_vault_sync_items` registra operacoes locais de import, export,
  sync, conflito e resolucao explicita;
- `AtlasVaultSyncService` importa nota individual do vault em dry-run ou write;
- import de nota gerenciada (`atlas_managed=true`) e bloqueado para evitar loop
  Atlas -> Vault -> Atlas;
- import write indexa a nota em `semantic_notes` e cria
  `semantic_curation_proposals` com `promotion_allowed=false`, sem promover para
  `atlas_memory_entries`;
- notas com privacy/redaction nao provider-safe entram como item bloqueado para
  review, nao como contexto externo;
- `export-semantic` cria projection humana gerenciada a partir de
  `semantic_notes`, usando `AtlasVaultManagedNoteService`;
- `sync` executa scan local do vault em dry-run ou write com limite explicito;
- falha previsivel em arquivo individual durante `sync` vira item bloqueado
  `sync_file_error`, sem abortar o scan inteiro; em `--write`, esse bloqueio
  entra na fila persistente e em `audit_events`;
- `status` inclui resumo operacional da fila local em `vault.sync_queue`
  quando `atlas_vault_sync_items` esta migrada;
- `vault.sync_queue.conflicts` conta apenas conflitos abertos em status de
  review; `historical_conflicts` preserva a contagem auditavel incluindo itens
  resolvidos com `conflict_type`;
- `conflicts` lista itens pendentes, candidatos, bloqueados ou em conflito;
- `conflicts` aceita filtros auditaveis por `status`, `direction` e
  `operation`; `status` pode ser lista separada por virgula no CLI/API, e a
  API retorna `422` para filtros fora do allowlist;
- `item` retorna detalhe read-only de um item persistente por ID, incluindo
  metadata auditavel e a lista de acoes de resolucao permitidas;
- `resolve` aceita `adopt`, `archive`, `merge`, `regenerate`, `force` e
  `dismiss`; nesta fase essas acoes registram revisao/auditoria e nao fazem
  overwrite destrutivo silencioso;
- `resolve` aceita `reason` opcional no CLI/API; o valor e normalizado para
  uma linha, limitado a 1000 caracteres e gravado em
  `metadata.resolution_reason` e no evidence do audit event;
- `resolve --resolution=regenerate` reexecuta write seguro somente para itens
  `atlas_to_vault/export_semantic_note`; sucesso muda o item para
  `regenerated`, falha preserva `conflict` com `conflict_type` auditavel;
- `audit_events` registra import, export e resolucao quando a tabela existe.

Rotas HTTP locais autenticadas por `X-Atlas-Token`:

| Metodo | Rota | Contrato |
|---|---|---|
| `GET` | `/ai/vault/status` | Status do vault, contagens, safety policy e resumo `sync_queue` |
| `POST` | `/ai/vault/import` | `{path, dry_run|write}` para importar nota local permitida |
| `POST` | `/ai/vault/export-semantic` | `{semantic_note_id, dry_run|write}` para gerar nota gerenciada |
| `POST` | `/ai/vault/sync` | `{dry_run|write, limit}` para scan local limitado |
| `GET` | `/ai/vault/conflicts` | Lista fila local pendente/bloqueada/conflitante; filtros `status`, `direction`, `operation`, `limit` |
| `GET` | `/ai/vault/conflicts/{item}` | Detalhe read-only de item persistente da fila/conflito |
| `POST` | `/ai/vault/conflicts/{item}/resolve` | `{resolution, reason?}` para registrar decisao explicita; `regenerate` e permitido apenas para export de `semantic_note` |

As rotas usam os mesmos services da CLI e nao autorizam sync remoto, UI, MCP
write tools nem promocao automatica para `atlas_memory_entries`. Erros
previsiveis de path, vault e filesystem em import/export/sync retornam `422`
com payload JSON `{ok:false,error}`. `semantic_note_id` inexistente em
`export-semantic` retorna `404` com payload JSON `{ok:false,error}`. Item
inexistente em detalhe ou resolucao tambem retorna `404` com payload JSON
`{ok:false,error}`. IDs de item malformados sao recusados antes da query UUID
para evitar erro SQL em Postgres. Se `atlas_vault_sync_items` ainda nao estiver
migrada, detalhe e resolucao retornam `422` com payload JSON estavel. Filtros
malformados e modo
`dry_run`/`write` ambiguo retornam `422` com payload JSON `{ok:false,error}`.

Na CLI com `--json`, erros equivalentes retornam exit code `1` e payload
`{ok:false,error}` estavel, incluindo arquivo ausente, path inseguro,
`semantic_note` inexistente, item inexistente, filtro invalido e modo
`--dry-run`/`--write` ambiguo.

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
| `POST` | `/ai/memory/recall` | Recall hibrido provider-safe |
| `GET` | `/ai/memory/quality` | Scorecard read-only de qualidade da memoria |
| `GET` | `/ai/memory/quality/history` | Historico persistido de scorecards de qualidade |
| `POST` | `/ai/memory/quality/snapshots` | Registrar snapshot atual de qualidade |
| `POST` | `/ai/memory/maintain` | Rotina de manutencao para app/API: sync docs, index-code, promocao de deltas aceitos, scorecard/snapshot de memoria, projection status/apply opcional e health MCP |
| `POST` | `/ai/open-brain/context-pack` | Exportar context pack Atlas auditado |
| `GET` | `/ai/open-brain/audits` | Auditar exports Open Brain |

Contrato adicional de injecao automatica: quando `atlas dev`,
`atlas continue`, `atlas chat --mode=dev|debug|review` ou o Atlas AI App entram
em fluxo de programacao/review/debug, `AiPromptBuilder` injeta Open Brain com
`summary.memory_quality`. Esse resumo contem apenas status, score, contagens,
latest snapshot compacto, tendencia historica compacta e issues curtas; nao
carrega snapshot bruto nem texto privado. Regressoes de tendencia aparecem como
`memory_quality_trend_regressed` ou `memory_quality_trend_watch_regressed` e
degradam a injecao automatica sem falhar fechado sozinhas. Quando houver
regressao, `summary.memory_quality.trend.drivers` pode listar causas compactas
como `component_drop`, `count_increase`, `issue_increase` ou `score_drop`; esses
drivers sao diagnostico operacional e nao memoria canonica.

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
| `atlas:memory:seed-core` | Memorias core provider-safe para projection nao vazia |
| `atlas:memory:recall` | Recall hibrido provider-safe |
| `atlas:memory:quality` | Scorecard, snapshot e historico de qualidade; com `workspace`, feedback e relacoes seguem o conjunto ativo daquele contexto |
| `atlas:memory:maintain` | Rotina local de sync docs, index-code, promocao de aprendizados aceitos, scorecard/snapshot de memoria, projection status/apply opcional e health MCP |
| `atlas:vault status` | Status local do AtlasVault: path, exists, writable, notas gerenciadas, conflitos, safety e resumo da fila `atlas_vault_sync_items` |
| `atlas:vault note` | Dry-run ou escrita segura de nota humana gerenciada com frontmatter, links `atlas://`, bloco gerenciado e area manual preservada |
| `atlas:vault import` | Dry-run ou import local auditado de nota humana para `semantic_notes` e proposta de curadoria |
| `atlas:vault export-semantic` | Dry-run ou export seguro de `semantic_notes` para nota humana gerenciada |
| `atlas:vault sync` | Scan local limitado do vault em dry-run ou write |
| `atlas:vault conflicts` | Lista fila local de review/conflitos; filtros `--status`, `--direction`, `--operation`, `--limit` |
| `atlas:vault item` | Consulta detalhe read-only de item persistente da fila/conflito por `--item` |
| `atlas:vault resolve` | Registra resolucao explicita de item da fila, com `--reason` opcional, sem overwrite silencioso |
| `atlas:open-brain:context` | Exportar context pack Atlas auditado |
| `atlas:open-brain:mcp` | Servir Open Brain MCP local/read-only por stdio |
| `atlas:engineering:knowledge` | Knowledge Base, Code Intelligence e auditoria de drift |

## App Surface

| Tela | Entrada | Operacoes |
|---|---|---|
| `atlas-app/app/open-brain.tsx` | `Home > Atlas Open Brain` | Recall provider-safe, context pack preview/copy, auditorias Open Brain, memory maintain e projection apply com confirmacao |

Regras da tela:

- recall e context pack usam os mesmos contratos de API/CLI/MCP;
- preview/copy mostra conteudo provider-safe produzido pelo backend;
- `Rodar maintain` chama `POST /ai/memory/maintain` com sync docs, index-code, promocao de deltas aceitos, scorecard de memoria e health MCP;
- `Aplicar projection` exige segundo toque no app e `confirm=true` no backend;
- respostas `409` de manutencao ainda sao exibiveis quando o payload contem `memory_maintenance`.

## Open Brain Context Injection

Status: implementado em `open-brain-context-injection.md` para service central,
prompt runtime, CLI e Atlas AI App runtime.

A injecao automatica e diferente do export manual de context pack. O export
manual permite copiar/usar contexto. A injecao automatica faz o runtime do Atlas
adicionar Open Brain ao prompt quando CLI/app pedem codigo, review ou debug.

### `open_brain` Payload

Superficies que chamam `AiGatewayService` podem enviar:

```json
{
  "open_brain": {
    "mode": "auto",
    "surface": "app_ai",
    "budget_chars": 20000,
    "refresh": false,
    "provider_safe_only": true
  }
}
```

Regras:

- `mode` aceita `auto`, `off` ou `required`;
- `provider_safe_only` deve ser `true` na injecao automatica;
- backend pode inferir `open_brain.mode=auto` quando `atlas_workflow_mode` for
  `dev`, `debug`, `review` ou `programming`;
- app/CLI nao devem montar prompt manual de memoria;
- direct chat nao injeta por padrao.

### `open_brain_injection` Trace Metadata

Quando houver injecao, o trace deve conter:

```json
{
  "open_brain_injection": {
    "status": "injected",
    "surface": "cli_dev",
    "mode": "dev",
    "context_pack_hash": "sha256",
    "audit_id": "uuid",
    "summary": {
      "memory_refs": 4,
      "knowledge_refs": 3,
      "code_refs": 8
    },
    "warnings": []
  }
}
```

Statuses permitidos: `injected`, `skipped`, `degraded`, `failed_open`,
`failed_closed`.

### `open_brain_preview` Em `atlas dev --plan-only`

`atlas dev --plan-only --json` deve retornar uma previa compacta antes de
executar provider:

```json
{
  "open_brain_preview": {
    "status": "injected",
    "context_ready": true,
    "provider_execution_allowed": true,
    "surface": "cli_dev",
    "context_pack_hash": "sha256",
    "audit_id": "uuid",
    "summary": {
      "context_refs": 24,
      "memory_refs": 5,
      "knowledge_refs": 6,
      "code_refs": 8
    },
    "warnings": []
  }
}
```

Esse payload nao pode conter `prompt_section` nem `context_refs` brutos.
Previews auditam `atlas_open_brain_access_logs.action=context_injection_preview`.

### CLI Flags Implementadas

| Flag | Comandos | Papel |
|---|---|---|
| `--no-open-brain` | `atlas dev`, `atlas continue`, `atlas chat` | Desativa injecao automatica |
| `--require-open-brain` | `atlas dev`, `atlas continue`, `atlas chat` | Aborta se contexto nao puder ser injetado |
| `--open-brain-refresh` | `atlas dev`, `atlas continue` | Regera contexto em vez de reutilizar hash |
| `--open-brain-budget=<chars>` | `atlas dev`, `atlas chat` | Ajusta budget da secao Open Brain |

### PHP Runtime Para Comandos Internos

Comandos Atlas que chamam Artisan como processo filho devem resolver o binario
por `App\Support\AtlasPhpBinary`, nao por `PHP_BINARY` direto. Isso evita que
`atlas dev`, `atlas continue`, dogfood, release/final, scheduler e harness
gerenciado herdem um PHP antigo do shell.

Config:

```php
config('atlas.cli.php_binary')
config('atlas.cli.php_binary_candidates')
```

Env:

- `ATLAS_PHP_BIN`: override explicito;
- `ATLAS_PHP_BIN_CANDIDATES`: lista separada por virgula.

Default operacional no Mac local: `/opt/homebrew/bin/php`.

### Audit Log

Injeções automaticas devem registrar `atlas_open_brain_access_logs` com
`action=context_injection` e `surface` em `cli_dev`, `cli_continue`,
`cli_chat` ou `app_ai`. O log nao deve persistir prompt bruto sensivel.

## MCP Open Brain

Transportes implementados:

| Transporte | Endpoint/comando | Auth | Observacao |
|---|---|---|---|
| stdio local | `atlas open-brain mcp` | processo local | Preferido para Claude/Codex no mesmo host |
| HTTP JSON-RPC | `POST /ai/open-brain/mcp` | `X-Atlas-Token` | Read-only, valida `Origin` quando presente |
| HTTP status/SSE guard | `GET /ai/open-brain/mcp` | `X-Atlas-Token` | Status humano por JSON; `Accept: text/event-stream` retorna `405` ate existir SSE |

O servidor implementa discovery e chamadas de tools pelo protocolo MCP/JSON-RPC:

| Metodo MCP | Papel |
|---|---|
| `initialize` | Negociar versao/capabilities |
| `tools/list` | Listar tools Atlas expostas ao host |
| `tools/call` | Executar tool read-only/provider-safe |
| `ping` | Health check simples |

Tools expostas:

| Tool | Papel | Escrita |
|---|---|---|
| `atlas_memory_recall` | Busca memoria provider-safe por query/contexto | Nao |
| `atlas_open_brain_context_pack` | Exporta context pack provider-safe e auditado | Nao |
| `atlas_memory_maintenance_status` | Resume health de memoria, docs, code index e provider projection | Nao |

Contrato de seguranca:

- nenhuma tool MCP desta fase executa escrita;
- toda memoria retornada deve ser provider-safe;
- context pack via MCP grava auditoria com `surface=mcp`;
- actions de manutencao retornadas por `atlas_memory_maintenance_status` sao recomendacoes, nao execucao;
- HTTP valida `MCP-Protocol-Version` quando o header e enviado;
- Streamable HTTP completo com SSE/sessoes persistentes, multiusuario e tools destrutivas exigem fase propria.

## Configuracao Relevante

| Config/env | Papel |
|---|---|
| `ATLAS_AI_MEMORY_REGISTRY_LIMIT` | Limite de memorias do registry no contexto |
| `ATLAS_AI_MEMORY_REGISTRY_EXCERPT_CHARS` | Tamanho de excerpt do registry |
| `ATLAS_AI_VERBATIM_RECALL_LIMIT` | Limite de verbatim recall |
| `ATLAS_AI_VERBATIM_RECALL_BUDGET_CHARS` | Budget total de verbatim |
| `ATLAS_AI_MEMORY_RECALL_LIMIT` | Limite do recall composto |
| `ATLAS_AI_MEMORY_RECALL_BUDGET_CHARS` | Budget total do recall composto |
| `ATLAS_OPEN_BRAIN_MCP_HTTP_ENABLED` | Liga/desliga endpoint HTTP JSON-RPC |
| `ATLAS_OPEN_BRAIN_MCP_ALLOWED_ORIGINS` | Lista de origins permitidos quando o header `Origin` existe |
| `ATLAS_AI_MEMORY_RECALL_ITEM_CHARS` | Budget por item de recall |
| `ATLAS_AI_PROVIDER_PROJECTION_MAX_LINES` | Tamanho maximo da projection |
| `ATLAS_AI_PROVIDER_PROJECTION_MEMORY_LIMIT` | Quantidade de memorias em projection |
| `ATLAS_AI_PROVIDER_PROJECTION_MEMORY_CHARS` | Tamanho por memoria em projection |
| `ATLAS_PRIVACY_BLOCK_EXTERNAL_AI_FOR` | Classes bloqueadas para provider |
| `ATLAS_SEMANTIC_EMBEDDING_PROVIDER` | Provider de embedding; padrao conservador `local_hash` |
| `ATLAS_SEMANTIC_EMBEDDING_FALLBACK_ENABLED` | Fallback local quando provider externo falha |
