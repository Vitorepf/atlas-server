---
id: code-intelligence-source-material-index
type: engineering_knowledge
title: Code Intelligence Source Material Index
status: source_material
category: code-intelligence
priority: 35
summary: Indice de source material preservado para evolucoes futuras de Code Intelligence, incluindo Graphify.
tags:
  - atlas
  - code-intelligence
  - source-material
capabilities:
  - code_intelligence_index
  - external_graph_candidate
decisions:
  - Source material ajuda a evoluir o Atlas, mas nao governa runtime.
  - Toda promocao precisa virar doc ativo, AP ou teste antes de orientar implementacao.
maintenance:
  - Atualize quando novo material externo de Code Intelligence for auditado.
  - Nao coloque dumps brutos ou outputs sensiveis neste diretorio.
related_paths:
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/ap/AP-684-graphify-external-graph-harness.md
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md
---

# Code Intelligence Source Material Index

Este diretorio guarda material-fonte para evolucao de Code Intelligence.

Ele nao e autoridade operacional. A autoridade ativa fica em:

- `docs/engineering-knowledge-base/code-intelligence.md`;
- `docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md`;
- APs ligados, como AP-684.

## Materiais

| Material | Status | Uso Permitido |
|---|---|---|
| `graphify/README.md` | pacote enterprise auditado | Fonte detalhada para pipeline, modulos, schema, comandos, Claude/Atlas, benchmark, riscos, DoD e mapa de colheita AP-684. |
| `graphify-v0-7-11-dissection-2026-05-09.md` | source material auditado | Inspirar AP-684, schema candidate, gates e melhorias nativas. |

## Regra De Leitura

Ao ler source material:

1. extrair decisao estavel;
2. checar se ja existe doc ativo dono;
3. criar/atualizar AP curto;
4. validar contra Documentation OS, Kernel, Code Intelligence e Cognitive
   Immune Learning Kernel;
5. nunca copiar output bruto para memoria, contexto ou Constelacao.
