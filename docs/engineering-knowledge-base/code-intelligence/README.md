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
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-code-intelligence-specs-index

graph_title: Atlas Code Intelligence Specs Index

graph_world: atlas

graph_layer: module

graph_kind: index

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: code-intelligence

repo_paths:
  - docs/engineering-knowledge-base/code-intelligence/README.md

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
  - code-intelligence

evidence:
  - docs/engineering-knowledge-base/code-intelligence/README.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - index
  - code-intelligence

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

## Resumo

Indice local dos contratos de Code Intelligence, incluindo o harness governado para grafos externos como Graphify.

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
