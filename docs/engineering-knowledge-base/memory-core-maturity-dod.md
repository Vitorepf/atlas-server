---
id: atlas-memory-core-maturity-dod
type: engineering_knowledge
title: Atlas Memory Core Maturity And Definition Of Done
status: active
category: capability_matrix
priority: 96
summary: Modelo de maturidade, metricas e Definition of Done para evoluir o Memory Core sem perder confiabilidade.
tags:
  - atlas
  - memory
  - maturity
  - definition-of-done
capabilities:
  - maturity_model
  - release_gates
  - documentation_hardening
  - validation_policy
  - open_brain_context_injection
decisions:
  - Cada fase deve declarar o que foi entregue, validado e deixado para depois.
  - ChromaDB, Streamable HTTP completo/SSE, sync multiusuario e embedding externo so entram com fase propria e DoD explicito.
  - Open Brain automatico em CLI/app so pode ser marcado implementado com trace metadata, audit log e testes.
maintenance:
  - Atualize status de maturidade apos cada fase relevante.
  - Nao promova camada para madura sem teste e runbook.
related_paths:
  - tests/Feature/AtlasMemoryRegistryTest.php
  - tests/Feature/AtlasEngineeringKnowledgeBaseTest.php
  - app/Services/Ai/AtlasMemoryContextComposer.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - docs/engineering-knowledge-base/open-brain-context-injection.md
---

# Atlas Memory Core Maturity And Definition Of Done

Este documento define como o Memory Core evolui com qualidade. Ele evita que uma
capacidade vire "pronta" apenas porque existe codigo.

## Maturity Model

| Nivel | Nome | Criterio de pronto | Status |
|---|---|---|---|
| L1 | Memory Registry | CRUD, API, CLI, testes, scopes e tipos | Implementado |
| L2 | Context Pack deterministic | `memory_refs`, motivos e budget | Implementado |
| L3 | Usage audit/feedback | Auditoria por trace e feedback util/inutil/errado | Implementado |
| L4 | Governance | dedupe, conflito, review queue e arquivamento | Implementado |
| L5 | Privacy/redaction | classes, scan/apply/review, provider-safe | Implementado |
| L6 | Verbatim recall | evidencia exata, review e recall controlado | Implementado |
| L7 | Knowledge Base | docs canonicos, sync, API, CLI, app e context refs | Implementado |
| L8 | Code Intelligence | modulos, simbolos, rotas, comandos, migrations, testes, audit-code e code refs | Implementado backend + app |
| L9 | Operational UI | app cobre memoria, Open Brain, knowledge, projection, maintain e code intelligence | Implementado |
| L10 | Hybrid retrieval | semantic/vector/hybrid search com policy | Implementado local/provider-safe |
| L11 | Open Brain multi-tool | context export e MCP auditavel | Implementado como API/CLI/MCP local e HTTP JSON-RPC autenticado; SSE/sessoes futuras |
| L12 | Open Brain automatic injection | `atlas dev`, `atlas continue`, `atlas chat` e Atlas AI App usam Open Brain automaticamente em codigo/review/debug | Implementado |

## Definition Of Done Global

Toda fase que altera Memory Core deve entregar:

- escopo pequeno e explicitamente faseado;
- migration/model quando houver estado novo;
- service como ponto de regra de negocio;
- controller/API quando houver operacao remota;
- command CLI quando houver operacao de manutencao;
- teste focado para service/API/CLI conforme aplicavel;
- validacao real registrada no documento mestre;
- docs atualizados com status real;
- lista do que ficou para depois;
- garantia explicita de que infraestrutura externa de memoria nao foi ativada fora de fase propria.

## Definition Of Done Por Camada

| Camada | DoD minimo |
|---|---|
| Memory Registry | migration, model, service, API, CLI, tests, privacy fields |
| Context Pack | refs pequenas, motivo, budget, auditabilidade e teste de prompt |
| Verbatim | estado de review, redaction, bloqueio provider e teste de recall |
| Governance | scan dry-run, relacoes revisaveis, arquivamento seguro |
| Provider Projection | preview/review/apply/status/audit, drift guard e purge seguro |
| Knowledge Base | docs canonicos, frontmatter, sync, context refs, app status |
| Code Intelligence | modules, symbols, doc links, code refs, routes/API/CLI e teste fixture |
| App UI | estados loading/error/empty, sync seguro, typecheck e teste front |
| Open Brain Injection | service central, policy auto/off/required, trace metadata, audit log, dedupe de prompt e testes CLI/app |

## Metricas De Saude

| Metrica | Bom sinal | Alerta |
|---|---|---|
| active memory count | cresce com uso real | cresce sem governance |
| review queue count | baixo ou esvaziado periodicamente | fila crescendo sem operador |
| privacy redacted count | existe quando ha dados sensiveis | zero em ambiente com traces reais |
| provider projection drift | zero ou explicitamente revisado | drift manual recorrente |
| knowledge active docs | acompanha docs canonicos | docs no repo sem sync |
| code modules documented | sobe com maturidade | muitos `undocumented` em core |
| code intelligence audit | `fresh` apos sync/index | `drift_detected` antes de context pack |
| code refs in context pack | presentes em tarefas de engenharia | ausentes em runs de codigo |
| open brain injection rate | presente em dev/debug/review | ausente em `atlas dev` ou app programming |
| open brain duplicate prompt | zero | mesma hash renderizada duas vezes |
| memory feedback negative | usado para arquivar/rebaixar | ignorado |

## Gates Para Futuras Fases

### UI De Code Intelligence

Status: implementada no app Engineering a partir da Fase 4Y.

Gate de manutencao:

- app lista modulos e simbolos;
- filtros por layer/docs_status/symbol_type;
- detalhe de modulo mostra docs, testes e rotas/comandos;
- painel `Code audit` executa dry-run sem escrita e mostra drift por modulos,
  simbolos e doc links;
- typecheck e testes front passam;
- docs registram fluxo operacional.

### Hybrid Retrieval

Status: implementado no `atlas-server` como recall hibrido provider-safe.

Gate de manutencao:

- policy de privacy para embeddings estiver escrita;
- fonte de verdade continuar sendo Postgres/docs;
- fallback deterministico existir;
- testes provarem que conteudo bloqueado nao entra em recall provider-safe nem em embedding externo;
- custos, storage e retention estiverem documentados.

### Open Brain MCP/Remoto

Status: implementado como API/CLI/MCP local, HTTP JSON-RPC autenticado e tela operacional no app para recall, context pack, auditorias e memory maintain. A rotina `atlas:memory:maintain` e `POST /ai/memory/maintain` centralizam sync docs, index-code, provider projection status/apply opcional e health MCP. Streamable HTTP completo com SSE/sessoes persistentes, tools destrutivas e sync multiusuario continuam fase futura.

Gate de manutencao:

- tools MCP locais/HTTP continuarem read-only ate haver human gate dedicado;
- audit log cobrir exports via API, CLI e MCP;
- exportacao provider-safe estiver provada por teste;
- app permitir ver/copy context pack e rodar maintain sem depender de terminal;
- endpoint HTTP exigir `X-Atlas-Token` e validar `Origin` quando presente;
- endpoint HTTP validar `MCP-Protocol-Version` quando enviado e recusar `GET`
  SSE com `405` ate Streamable HTTP completo existir;
- Streamable HTTP completo tiver autenticacao/autorizacao multiusuario definida antes de escrita;
- sync remoto tiver conflito/dedupe;
- rollback/desativacao estiver documentado.

### Open Brain Context Injection

Status: implementado em `open-brain-context-injection.md`.

Gate de implementacao:

- service central aplica policy por surface/mode;
- `atlas dev` injeta Open Brain por padrao;
- `atlas continue` reutiliza ou regenera contexto sem duplicar prompt;
- `atlas chat --mode=dev|debug|review` injeta por padrao;
- Atlas AI App injeta em `programming`, `review` e `debug`;
- direct chat nao injeta por padrao;
- `--no-open-brain`, `--require-open-brain` e refresh existem onde aplicavel;
- `AiTrace.metadata.open_brain_injection` registra status, hash e audit id;
- `atlas_open_brain_access_logs` registra `action=context_injection`;
- privacy/redaction continuam bloqueando memoria nao provider-safe;
- testes cobrem service, prompt builder, CLI e app payload/status;
- docs e runbook registram validacoes reais.

## Regra De Promocao De Status

Uma capacidade so pode ser marcada como implementada quando:

1. existe codigo ou doc canonico entregue;
2. existe validacao executada;
3. o documento mestre lista arquivos tocados;
4. limites e pendencias estao declarados;
5. nao ha dependencia implicita de conversa ou provider externo.
