---
id: graphify-v0-7-11-dissection-2026-05-09
type: engineering_knowledge
title: Graphify v0.7.11 Dissection Source Material
status: source_material
category: code-intelligence
priority: 40
summary: Source material preservado da auditoria Graphify para futura implementacao do External Graph Harness do Atlas.
tags:
  - graphify
  - source-material
  - code-intelligence
  - graph-rag
capabilities:
  - external_graph_candidate
  - code_intelligence_index
  - architecture_operations
decisions:
  - Graphify pode inspirar o Atlas, mas nao governa arquitetura.
  - A primeira colheita deve ser sandboxada, read-only e sem provider call por default.
  - Componentes copiados exigem atribuicao MIT e revisao de licenca.
maintenance:
  - Atualize somente quando uma nova versao upstream for auditada.
  - Nao use este source material como autoridade ativa; use `code-intelligence/external-graph-harness.md`.
related_paths:
  - docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/ap/AP-684-graphify-external-graph-harness.md
  - docs/engineering-knowledge-base/code-intelligence.md
---

# Graphify v0.7.11 Dissection Source Material

Este documento preserva a sintese da disseccao do projeto open source
`safishamsi/graphify`.

Ele nao e doc ativo de runtime. E material-fonte para AP-684.

A disseccao enterprise detalhada vive em
`docs/engineering-knowledge-base/archive/source-material/code-intelligence/graphify/README.md`.
Este arquivo fica como capsula historica da primeira auditoria.

## Fonte Auditada

| Campo | Valor |
|---|---|
| Upstream | `https://github.com/safishamsi/graphify` |
| Pacote | `graphifyy` |
| Versao | `0.7.11` |
| Commit auditado | `adf96da` |
| Licenca | MIT |
| Data da auditoria | `2026-05-09` |
| Tarball SHA256 | `7c02e6447e0d5199280e46222548d26d8a0ae8a9b757f31892c36fdfa69f2ee2` |

## O Que O Graphify Faz

Pipeline upstream resumido:

```text
detect
-> extract
-> build
-> cluster
-> analyze
-> report/export
-> optional MCP/query/path/explain
```

Ele transforma codigo, docs, papers e imagens em um grafo consultavel com
nodes, edges, comunidades, god nodes, relacoes surpreendentes e outputs como
`graph.json`, `GRAPH_REPORT.md`, HTML e MCP read-only.

## Pontos Fortes

| Area | Valor Para Atlas |
|---|---|
| Tree-sitter/AST | Melhorar Code Intelligence estrutural. |
| Confidence labels | Separar extraido, inferido e ambiguo. |
| Graph JSON | Formato simples para candidato externo. |
| Community detection | Sugerir clusters de codigo/docs. |
| God nodes | Achar modulos centrais e risco de acoplamento. |
| Surprising connections | Alimentar proposal/Constelacao apenas apos review. |
| MCP read-only | Inspirar ferramentas Open Brain de consulta segura. |
| Incremental cache | Inspirar otimizacao de index-code. |

## Riscos

| Risco | Mitigacao Atlas |
|---|---|
| `extract.py` monolitico | Reimplementar nativo em modulos pequenos. |
| CLI instala hooks/skills | Proibido em repos Atlas por default. |
| Docs/imagens podem ir a provider | Provider call bloqueado no primeiro AP. |
| `graphify-out` pode vazar informacao | Nao commitar dumps; gerar artefatos sandboxados. |
| Grafo inferido pode parecer verdade | `INFERRED` vira proposta revisavel. |
| Sem Decision Receipt/Evidence Ledger | Atlas envolve com Kernel, gates e Curator. |
| Sem privacidade/tombstone Atlas | Filtros Atlas rodam antes de query/promocao. |

## Matriz De Colheita

| Componente | Decisao |
|---|---|
| Graph schema | adaptar como `external_graph_candidate.v1` |
| Confidence labels | adaptar |
| AST extraction | reimplementar Atlas-native |
| SQL extraction | adaptar padrao |
| Query/path/explain | adaptar read-only |
| God nodes/surprises | adaptar para reports/proposals |
| Community detection | adaptar como hint, nao verdade |
| Hooks/skills | rejeitar para core |
| HTML/Obsidian export | opcional fora do core |
| Semantic LLM extraction | fase futura com Atlas Decide |

## Regra Final

Graphify e uma lente externa de cartografia. O Atlas continua sendo o sistema
operacional:

```text
Graphify candidate
-> Atlas gates
-> review
-> optional native promotion
```

Qualquer implementacao que tente transformar output Graphify em memoria,
contexto, decisao ou Constelacao sem review viola este source material e AP-684.
