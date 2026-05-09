---
id: atlas-code-intelligence-specs-index
type: engineering_knowledge
title: Atlas Code Intelligence Specs Index
status: active
category: code-intelligence
priority: 91
summary: Indice local dos contratos de Code Intelligence, incluindo o harness governado para grafos externos como Graphify.
tags:
  - atlas
  - code-intelligence
  - index
capabilities:
  - code_intelligence_index
  - external_graph_candidate
decisions:
  - O parent canonico de Code Intelligence continua sendo `code-intelligence.md`.
  - Child specs detalham extensoes governadas sem competir com o parent.
  - Graphify e outros grafos externos entram apenas pelo External Graph Harness.
maintenance:
  - Adicione novos child specs aqui antes de ligar nos indices globais.
  - Mantenha este arquivo curto; detalhes longos pertencem ao child spec ou AP.
related_paths:
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/ap/AP-684-graphify-external-graph-harness.md
---

# Atlas Code Intelligence Specs Index

Leia este diretorio antes de alterar Code Intelligence, grafos externos,
Graphify, AST graph, `graph.json`, god nodes, comunidades de codigo ou relacoes
surpreendentes.

## Arquivos

| Arquivo | Papel |
|---|---|
| `../code-intelligence.md` | Parent canonico: modulos, simbolos, rotas, comandos, migrations, testes e doc links. |
| `external-graph-harness.md` | Contrato para grafos externos como candidatos read-only de Code Intelligence. |

## Regra De Autoridade

Code Intelligence nativo continua sendo o read model operacional do codigo real
do Atlas.

Ferramentas externas podem gerar evidencia candidata, mas nao podem:

- substituir o indice nativo;
- escrever memoria;
- compor contexto automaticamente;
- criar Constelacao;
- alterar Decide/Policy/Profile;
- virar runtime paralelo.

## Fluxo De Implementacao

```text
AP-684
-> sandboxed candidate
-> schema/privacy/confidence gates
-> Architecture Operations report
-> Curator proposal
-> human review
-> optional native Code Intelligence improvement
```

Se uma sessao quiser "usar o Graphify", ela deve primeiro ler AP-684 e o
External Graph Harness.

