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
decisions:
  - Cada fase deve declarar o que foi entregue, validado e deixado para depois.
  - Embeddings/vector/Open Brain so entram com fase propria e DoD explicito.
maintenance:
  - Atualize status de maturidade apos cada fase relevante.
  - Nao promova camada para madura sem teste e runbook.
related_paths:
  - tests/Feature/AtlasMemoryRegistryTest.php
  - tests/Feature/AtlasEngineeringKnowledgeBaseTest.php
  - app/Services/Ai/AtlasMemoryContextComposer.php
  - app/Services/Engineering/EngineeringContextPackService.php
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
| L8 | Code Intelligence | modulos, simbolos, rotas, comandos, migrations, testes e code refs | Implementado backend |
| L9 | Operational UI | app cobre memoria, knowledge, projection e code intelligence | Parcial |
| L10 | Hybrid retrieval | semantic/vector/hybrid search com policy | Nao implementado |
| L11 | Open Brain remoto | multi-tool remoto auditavel | Nao implementado |

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
- garantia explicita de que embeddings/vector/Open Brain nao foram ativados fora de fase propria.

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

## Metricas De Saude

| Metrica | Bom sinal | Alerta |
|---|---|---|
| active memory count | cresce com uso real | cresce sem governance |
| review queue count | baixo ou esvaziado periodicamente | fila crescendo sem operador |
| privacy redacted count | existe quando ha dados sensiveis | zero em ambiente com traces reais |
| provider projection drift | zero ou explicitamente revisado | drift manual recorrente |
| knowledge active docs | acompanha docs canonicos | docs no repo sem sync |
| code modules documented | sobe com maturidade | muitos `undocumented` em core |
| code refs in context pack | presentes em tarefas de engenharia | ausentes em runs de codigo |
| memory feedback negative | usado para arquivar/rebaixar | ignorado |

## Gates Para Futuras Fases

### UI De Code Intelligence

So considerar pronta quando:

- app lista modulos e simbolos;
- filtros por layer/docs_status/symbol_type;
- detalhe de modulo mostra docs, testes e rotas/comandos;
- typecheck e testes front passam;
- docs registram fluxo operacional.

### Hybrid Retrieval

So iniciar quando:

- policy de privacy para embeddings estiver escrita;
- fonte de verdade continuar sendo Postgres/docs;
- fallback deterministico existir;
- testes provarem que conteudo bloqueado nao entra em index vetorial;
- custos, storage e retention estiverem documentados.

### Open Brain Remoto

So iniciar quando:

- autenticao/autorizacao multiusuario estiver definida;
- audit log cobrir leitura e escrita;
- sync remoto tiver conflito/dedupe;
- exportacao provider-safe estiver provada;
- rollback/desativacao estiver documentado.

## Regra De Promocao De Status

Uma capacidade so pode ser marcada como implementada quando:

1. existe codigo ou doc canonico entregue;
2. existe validacao executada;
3. o documento mestre lista arquivos tocados;
4. limites e pendencias estao declarados;
5. nao ha dependencia implicita de conversa ou provider externo.

