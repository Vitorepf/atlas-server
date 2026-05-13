---
id: atlas-desktop-backend-contract
type: engineering_knowledge
title: Atlas Desktop Backend Contract
status: active
category: surface
priority: 99
summary: Contrato backend para as duas telas do Atlas Desktop: Cartografia da verdade canônica e Atlas Code Operating Room. Define fontes de verdade, endpoints, payloads, lacunas e regras anti-mock.
tags:
  - atlas-desktop
  - atlas-code
  - cartography
  - mcp
  - backend-contract
  - engineering-operations-system
capabilities:
  - atlas_desktop_backend
  - atlas_truth_cartography
  - atlas_code_operating_room
  - atlas_open_brain_mcp
decisions:
  - Atlas Desktop e uma cabine operacional; atlas-server continua sendo Kernel.
  - Cartografia e read-only e le diretamente repo docs + AtlasVault, sem projecao manual paralela.
  - Atlas Code nunca recebe dados inventados; ausencia de dado vira estado vazio explicito.
  - O MCP authority pertence ao atlas-server via atlas-open-brain.
  - Backend deve preservar wrappers dedicados para Atlas Desktop quando endpoints legados tiverem shapes diferentes.
maintenance:
  - Atualizar antes de mudar rotas /atlas-cartography, /atlas-code, /ai/open-brain/mcp, /projects, /ai/decisions ou contratos do atlas-desktop bridge.
  - Qualquer mock HTML e apenas referencia visual; este arquivo governa o backend real.
related_paths:
  - docs/atlas-vault-cartografia.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-code-category-evolution.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - routes/api.php
  - app/Http/Controllers/AtlasCartographyController.php
  - app/Http/Controllers/AtlasCodeSessionController.php
  - app/Http/Controllers/AtlasCodeEvidenceController.php
  - app/Http/Controllers/AtlasCodeReceiptController.php
  - app/Http/Controllers/AtlasCodeDiffController.php
  - ../atlas-desktop/docs/architecture/0001-atlas-desktop-boundaries.md
  - ../atlas-desktop/packages/atlas-domain/src/cartography.ts
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-desktop-backend-contract
graph_title: Atlas Desktop Backend Contract
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-desktop
graph_status: active
graph_source: repo
owner: atlas-ai
layer: 1-surfaces
line_limit: 360
repo_paths:
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
  - routes/api.php
  - app/Http/Controllers/AtlasCartographyController.php
  - ../atlas-desktop/docs/architecture/0001-atlas-desktop-boundaries.md
  - ../atlas-desktop/packages/atlas-domain/src/cartography.ts
  - ../atlas-desktop/apps/desktop/src/lib/bridge.ts
allowed_changes:
  - Ajustar contratos de endpoints consumidos pelo Atlas Desktop.
  - Corrigir shape de payload para remover mock e expor estado real.
  - Adicionar wrappers dedicados quando rotas historicas tiverem shape incompatível com o Desktop.
forbidden_changes:
  - Transformar Atlas Desktop em Kernel ou fonte primaria de decisao.
  - Inventar dados de Obra, receipt, evidencia, MCP, terminal ou provider.
  - Fazer Cartografia escrever em repo ou AtlasVault.
depends_on:
  - atlas-ai-documentation-operating-system
  - atlas-canonical-module-doc-v1
  - atlas-ai-memory-context-core-open-brain
flows_to:
  - atlas-desktop-code-surface
  - atlas-cartography
unlocks:
  - atlas-code-operating-room
  - atlas-semantic-graph
governs:
  - atlas-desktop
  - atlas-code
  - atlas-cartography
evidence:
  - routes/api.php
  - app/Http/Controllers/AtlasCartographyController.php
  - docs/engineering-knowledge-base/atlas-desktop-backend-contract.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Conectar Atlas Desktop apenas em endpoints reais e remover qualquer fallback mockado.
visual_tags:
  - module
  - contract
  - surface

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
---
# Atlas Desktop Backend Contract

Este contrato define o que o `atlas-server` precisa entregar para duas telas
do Atlas Desktop:

1. **Cartografia**: mapa navegavel da verdade canonica.
2. **Atlas Code**: cabine operacional do Engineering Operations System.

O Desktop nunca vira Kernel. Ele mostra, comanda, assina e observa. O Kernel
continua no `atlas-server`.

## Resumo

Este doc governa o backend real que Atlas Desktop consome. Ele existe para
impedir que Cartografia e Atlas Code virem telas bonitas com dados falsos.

## Papel no Atlas

Ele define a fronteira entre a cabine operacional desktop e o Kernel Laravel.
O Desktop navega, mostra, observa e assina; o `atlas-server` decide, executa,
serve MCP, registra evidencia e preserva a fonte de verdade.

## Onde Se Encaixa

Pai: `atlas-desktop`. Irmaos: `atlas-desktop-code-surface` e a Cartografia.
Filhos operacionais: endpoints `/atlas-cartography/*`, `/atlas-code/*`,
`/ai/*`, `/projects` e MCP do Open Brain.

## Contratos

Contrato central: nenhuma tela pode inventar estado. Se uma fonte nao existe,
o backend deve retornar vazio, offline, missing_source ou erro explicito.

## Fluxo

Cartografia le repo docs + AtlasVault. Atlas Code le Obras, sessoes, receipts,
gates, evidence, MCP e terminal nativo via bridge. Ambos exibem fonte real.

## Regras para IA

Antes de alterar este contrato, a IA deve checar rotas reais, controllers,
bridge do Desktop e docs de Cartografia/Atlas Code. Nao pode declarar endpoint
implementado sem evidencia verificavel.

## Escopo de Implementacao

Permitido: docs, wrappers de API, shape de payload, health, evidence e bridge.
Proibido: duplicar Kernel no Desktop ou introduzir mock como fallback normal.

## Dependencias

- `atlas-ai-documentation-operating-system`
- `atlas-canonical-module-doc-v1`
- `atlas-desktop-code-surface`
- `atlas-ai-memory-context-core-open-brain`

## Evidencias

- `routes/api.php`
- `app/Http/Controllers/AtlasCartographyController.php`
- `../atlas-desktop/apps/desktop/src/lib/bridge.ts`

## Riscos

- UI parecer pronta enquanto backend retorna dados falsos.
- Desktop assumir responsabilidade do Kernel.
- Rotas antigas com shape diferente quebrarem o contrato do bridge.

## Exemplos

Exemplo correto: `missing_source=true` quando uma peca da Cartografia nao tem
arquivo fonte. Exemplo proibido: gerar uma Obra fake para preencher rail vazio.

## Proximas Acoes

Remover mocks do Desktop, conectar wrappers reais e fazer cada painel exibir
estado vazio honesto quando nao houver dado.

## 1. Regras absolutas

- **Sem mock**: se o backend nao tem dado real, responde vazio, `null`,
  `missing_source=true`, `offline` ou erro explicito. Nunca inventa OBRA,
  receipt, evidencia, terminal output, MCP call ou provider.
- **Fonte real visivel**: cada item tecnico deve apontar para docs, codigo,
  receipt, ledger, run, gate ou path real.
- **Cartografia e read-only**: nenhuma rota de cartografia escreve em repo ou
  Vault.
- **Atlas Code executa por contrato**: execucao exige Decision Receipt v2,
  politica, escopo, rollback e evidencia.
- **MCP pertence ao Kernel**: o Desktop pode exibir MCP health e chamadas, mas
  quem serve MCP e `atlas-server`.

## 2. Tela 1: Cartografia

### 2.1 Objetivo

Renderizar a verdade canonica como mapa navegavel. A tela deve responder:

- onde uma peca fica;
- o que ela faz;
- de quem depende;
- quem ela alimenta;
- qual evidencia existe;
- qual risco/gargalo existe;
- qual proxima acao concreta existe.

### 2.2 Fontes

| Conteudo | Fonte primaria |
|---|---|
| Arquitetura tecnica do Atlas | `docs/engineering-knowledge-base/` |
| Grafo tecnico / pipeline / Kernel / Forge / Evidence | repo docs |
| Memoria humana, filosofia, livros, historias | `AtlasVault/` |
| Gaps de fonte | resposta com `missing_source=true` |

### 2.3 Endpoints obrigatorios

| Endpoint | Metodo | Status |
|---|---:|---|
| `/atlas-cartography/graph` | GET | implementado |
| `/atlas-cartography/note/{graph_id}` | GET | implementado |
| `/atlas-cartography/recent-changes` | GET | implementado |

### 2.4 Shape canonico de `/atlas-cartography/graph`

O backend retorna snake_case; o Desktop adapta para camelCase.

Campos obrigatorios:

```json
{
  "schema_version": 1,
  "generated_at": "2026-05-13T00:00:00Z",
  "sources": {
    "repo_docs_path": "docs/engineering-knowledge-base",
    "obsidian_vault_path": "/Users/.../AtlasVault",
    "repo_indexed_count": 0,
    "vault_indexed_count": 0
  },
  "audit": {
    "pieces_found": 0,
    "pieces_missing": 0
  },
  "universe": [],
  "views": {
    "atlas-ai-kernel": {
      "ribbon": "Atlas Kernel Pipeline",
      "pipeline": [],
      "lanes": [],
      "connections": []
    }
  },
  "semantic_graph": {
    "worlds": [],
    "nodes": [],
    "hierarchy": {},
    "relations": []
  }
}
```

Cada peca deve carregar:

- `graph_id`
- `name`
- `graph_source`: `repo | vault | mixed | missing`
- `source_path`
- `missing_source`
- `role` ou `summary`
- `depends_on`
- `unlocks`
- `evidence`
- `risks`
- `next_actions`

`semantic_graph` carrega o mapa fonte-real:

- `nodes`: docs/notas reais indexados por `graph_id`.
- `hierarchy`: `graph_parent -> filhos`, base para mundo -> sistema -> fluxo -> modulo -> engrenagem.
- `relations`: conexoes derivadas de `depends_on`, `flows_to`, `unlocks` e `governs`.
- `worlds`: mundos encontrados nos frontmatters, hoje `atlas` e `vault`.

### 2.5 Gaps conhecidos da Cartografia

| Gap | Impacto | Proxima implementacao |
|---|---|---|
| Recent changes usa `git log`, nao filesystem live | A timeline nao mostra arquivo salvo ha segundos fora de commit | adicionar watcher/SSE depois do L1 |
| Canon visual ainda depende de `CartographyCanon` | Pecas sem frontmatter podem aparecer como expected/missing | migrar gradualmente para frontmatter canonico completo |
| Nao existe endpoint de busca dedicado | Search no client usa indice em memoria | criar `/atlas-cartography/search?q=` se necessario |

## 3. Tela 2: Atlas Code

### 3.1 Objetivo

Atlas Code e a cabine operacional do **Engineering Operations System**. Ele
materializa o Software Construction Operating Room:

```text
Vitor dirige intencao, contrato e evidencia.
Atlas compila spec, planeja, escolhe agentes, executa, verifica, repara e aprende.
```

### 3.2 Zonas da tela v3/v4

| Zona | Backend necessario |
|---|---|
| Topbar / Core / MCP pill | Kernel health + MCP health |
| Forge workspace bar | Obra ativa + objective + status board |
| Atlas Decide routing | Decision Receipt + provider decision + AP-99 metrics |
| Sessions rail | Obras, threads, session snapshots |
| Conversation | AiThread + AiMessage + streaming de AiTrace |
| Spec OS pipeline | eventos de pipeline / run state |
| Operational panel | receipt, gates, evidence, repair, learning proposals |
| Terminal PTY | desktop native, mas status/evidence volta ao Kernel |

### 3.3 Endpoints reutilizados

| Necessidade | Endpoint atual |
|---|---|
| Health | `GET /health` |
| Provider status | `GET /ai/providers/status` |
| Listar obras | `GET /projects` |
| Criar obra | `POST /projects` |
| Obter thread | `GET /ai/threads/{thread}` |
| Enviar intent | `POST /ai/interactions` |
| Stream de trace | `GET /ai/interactions/{trace}/stream` |
| Decision receipt | `GET /ai/decisions/{decision}` |
| Gates | `GET /tools/gate` |
| Rodar gate | `POST /tools/{tool}/run` |
| MCP HTTP | `GET|POST /ai/open-brain/mcp` |

### 3.4 Endpoints dedicados Atlas Code

| Endpoint | Metodo | Responsabilidade |
|---|---:|---|
| `/atlas-code/works/{project}/sessions` | GET | sessoes/threads de uma obra |
| `/atlas-code/works/{project}/evidence` | GET | timeline de runs/evidencias por obra |
| `/atlas-code/decisions/{decision}/sign` | POST | registrar assinatura ed25519 no Ledger |
| `/atlas-code/diffs/{patch}/apply` | POST | registrar pedido de aplicar diff e iniciar gates |

### 3.5 Regra de shape para Desktop

Para rotas historicas, preservar shape existente para o app atual. Para o
Desktop, preferir wrappers dedicados em `/atlas-code/*` quando o shape legado
for diferente do contrato do bridge.

Exemplos de divergencia que precisam de cuidado:

| Rota | Shape legado | Desktop espera |
|---|---|---|
| `GET /projects` | `{ "projects": [...] }` | lista de Obras ou adapter dedicado |
| `POST /projects` | `{ "project": ..., "active_next_task": ..., "receipt_id": ... }` | Obra normalizada + `receipt_id` |
| `GET /ai/decisions/{id}` | `{ "decision": ... }` | Receipt v2 direto ou adapter dedicado |
| `GET /atlas-code/works/{id}/sessions` | `{ "data": [...], "meta": ... }` | client TS ja aceita; Rust bridge precisa wrapper ou endpoint direto |
| `GET /atlas-code/works/{id}/evidence` | `{ "data": [...], "meta": ... }` | client/bridge deve adaptar antes de tipar como lista |

Regra canonica:

```text
Nao quebrar rotas existentes para encaixar o Desktop.
Criar adapter/wrapper Atlas Code quando a tela precisar de shape proprio.
```

### 3.6 MCP na tela Atlas Code

A pill MCP e o painel v4 devem ler do Open Brain real:

| UI | Fonte backend |
|---|---|
| server name | `GET /ai/open-brain/mcp` |
| protocol version | `MCP-Protocol-Version` + body |
| tools | JSON-RPC `tools/list` em `/ai/open-brain/mcp` |
| active/inactive | endpoint responde + config `atlas.open_brain.mcp.http_enabled` |
| docs indexed | Knowledge Base index / future health summary |
| symbols indexed | Code Intelligence index / future health summary |
| last call | `AtlasOpenBrainAccessLog` ou event log futuro |
| freshness/drift | Memory maintenance / architecture validation |

Backend recomendado para v1 da pill:

```text
GET /atlas-code/mcp/status
```

Resposta proposta:

```json
{
  "server": "atlas-open-brain",
  "status": "active",
  "protocol_version": "2025-06-18",
  "transport": "http_json_rpc",
  "tools_count": 0,
  "tools": [],
  "docs_indexed": 0,
  "symbols_indexed": 0,
  "last_call": null,
  "freshness": {
    "indexed_at": null,
    "drift": "unknown"
  }
}
```

## 4. Backlog backend obrigatorio

| Prioridade | Item | Por que |
|---:|---|---|
| P0 | Endpoint `GET /atlas-code/boot` | Desktop precisa declarar Kernel, providers, MCP, roots da Cartografia, queue, DB e workspace em um contrato unico |
| P0 | Wrappers normalizados para Obras | Evita bridge Rust/TS depender do shape legado `{ projects: [...] }` |
| P0 | Wrapper normalizado para thread/messages | Evita Desktop esperar lista crua quando `/ai/threads/{thread}` retorna envelope |
| P0 | Payload real de `POST /ai/interactions` documentado para Desktop | Impede envio de payload local (`session_id/body/channel`) que o Kernel nao entende |
| P0 | Testes de contrato para `/atlas-cartography/*` | Cartografia e fonte visual da verdade |
| P0 | Testes de contrato para `/atlas-code/*` | Desktop nao pode quebrar por shape divergente |
| P0 | Adapter MCP status `/atlas-code/mcp/status` | v4 mostra MCP como cidadao de primeira classe |
| P1 | Wrapper direto de receipt em `/atlas-code/decisions/{id}/receipt` | Evita depender de `{ decision: ... }` legado |
| P1 | Wrapper direto de projects/obras para Desktop | Evita Rust bridge quebrar com `{ projects: [...] }` |
| P1 | SSE ou polling contract para recent changes | Live-doc sem depender de commit |
| P1 | Hash/checksum no graph | Desktop detecta mudanca sem comparar payload inteiro |
| P2 | Busca backend `/atlas-cartography/search` | Search em grandes grafos |
| P2 | MCP call timeline | Painel v4 mostra last call real |

## 5. Definition of Done backend L1

- Cartografia abre com dados reais de repo docs + Vault.
- Atlas Code lista obras reais e cria obra sem dados inventados.
- Sessoes por obra retornam threads reais.
- Enviar intent cria/continua thread real.
- Decision Receipt aparece com provider, confidence, budget, fallback chain e
  evidence refs quando existirem.
- Assinar receipt sem assinatura real falha; assinar com payload real grava
  Ledger.
- Gates e Evidence sao lidos de tabelas reais.
- MCP status vem do `atlas-open-brain` real.
- Nenhum endpoint retorna exemplos hardcoded para satisfazer UI.

## 6. Auditoria de integracao do Atlas Desktop · 2026-05-13

Esta secao registra os pontos que bloqueiam a tela atual de virar produto
confiavel.

### 6.1 Gaps P0 de contrato

| Area | Problema atual | Correcao requerida |
|---|---|---|
| Boot | Desktop mostra `kernel falhou`, mas sem causa estruturada | `GET /atlas-code/boot` + Tauri `KernelStatusReport` com `failure_code`, `repair_hint`, tails de stdout/stderr, port e server path |
| Obras | `GET /projects` retorna envelope legado | Criar `/atlas-code/works` ou adaptar bridge para envelope tipado |
| Criar obra | `POST /projects` retorna `project`, `active_next_task`, `receipt_id` | Wrapper Desktop deve devolver Obra normalizada + `receipt_id` |
| Thread | `/ai/threads/{thread}` retorna envelope | Wrapper Desktop deve devolver messages normalizadas e metadados da thread |
| Intent | Bridge Rust ainda modela `session_id/body/channel` | Contrato Desktop deve usar `input_text`, `kind`, `source_type`, `source_id`, `thread_id|new_thread` |
| Sessions | `/atlas-code/works/{project}/sessions` retorna `{ data, meta }` | Bridge Rust precisa DTO wrapper ou endpoint deve retornar shape Desktop |
| Evidence | `/atlas-code/works/{project}/evidence` retorna `{ data, meta }` | Bridge Rust precisa DTO wrapper ou endpoint deve retornar shape Desktop |
| Gates | `/tools/gate` e legado operacional | Criar adapter de gates para painel Atlas Code |
| Receipt | `/ai/decisions/{decision}` retorna `{ decision }` | Criar `/atlas-code/decisions/{decision}/receipt` para Receipt v2 direto |

### 6.2 Snapshot operacional recomendado

Para reduzir round-trips e impedir a tela de montar estado inconsistente,
adicionar:

```text
GET /atlas-code/works/{project}/state
```

Resposta deve agregar:

```json
{
  "work": {},
  "sessions": [],
  "active_thread": null,
  "messages": [],
  "sdd": {
    "stage": "context|spec|plan|execute|verify|learn|idle",
    "steps": [],
    "spec": null,
    "plan": null
  },
  "receipt": null,
  "gates": [],
  "evidence": [],
  "repair": [],
  "learning_proposals": []
}
```

### 6.3 Cartografia live-doc

Cartografia L1 ja pode ler repo docs + AtlasVault. Para a experiencia "ver a documentacao nascer", o backend precisa evoluir:

- adicionar checksum no `/atlas-cartography/graph` e `source_health` com root path, readable, indexed_count e errors;
- trocar/complementar `git log` por watcher/polling de mtime e classificar eventos como `repo_doc_saved`, `vault_note_saved`, `git_commit` e `server_event`;
- garantir que `note/{graph_id}` sempre retorna body real do arquivo fonte.

### 6.4 Anti-mock reforcado

O Desktop pode renderizar estado pendente local apenas enquanto uma requisicao esta em andamento. Esse estado precisa sumir se o backend recusar. E proibido:
- criar sessao artificial chamada `thread atual`;
- retornar `diff_applied=true` sem apply real;
- marcar assinatura como valida sem verificacao criptografica real;
- mostrar contagem de MCP/gates/evidence sem fonte persistida;
- usar mock para preencher SDD, packets, receipts ou terminal.
