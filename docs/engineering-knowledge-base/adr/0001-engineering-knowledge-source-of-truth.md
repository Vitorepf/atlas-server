---
id: adr-0001-engineering-knowledge-source-of-truth
type: engineering_adr
title: ADR 0001 - Fonte De Verdade Do Conhecimento De Engenharia
status: active
category: architecture_decision
priority: 98
summary: O Atlas usa docs versionados como fonte de verdade e Postgres como registry operacional para conhecimento de engenharia.
tags:
  - adr
  - source-of-truth
  - postgres
capabilities:
  - canonical_docs
  - operational_registry
  - automatic_recall
decisions:
  - Repo docs sao canonicos.
  - Postgres e indice operacional.
  - Obsidian e opcional, nao autoritativo.
maintenance:
  - Reavaliar apenas se o Atlas ganhar um CMS versionado com review e API nativa.
related_paths:
  - docs/engineering-knowledge-base/README.md
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: adr-0001-engineering-knowledge-source-of-truth

graph_title: ADR 0001 - Fonte De Verdade Do Conhecimento De Engenharia

graph_world: atlas

graph_layer: system

graph_kind: adr

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: ADR 0001 - Fonte De Verdade Do Conhecimento De Engenharia
canonical_name: ADR 0001 - Fonte De Verdade Do Conhecimento De Engenharia
technical_name: adr-0001-engineering-knowledge-source-of-truth
cartography_type: adr
canonical_source: docs/engineering-knowledge-base/adr/0001-engineering-knowledge-source-of-truth.md

owner: adr

repo_paths:
  - docs/engineering-knowledge-base/adr/0001-engineering-knowledge-source-of-truth.md

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
  - adr

evidence:
  - docs/engineering-knowledge-base/adr/0001-engineering-knowledge-source-of-truth.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - adr
  - adr

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
# ADR 0001 - Fonte De Verdade

## Contexto

O Atlas Engineering Harness Runner virou uma parte central do produto. As
decisoes de arquitetura, manutencao, release gate, quality scan, visual harness
e benchmark nao podem ficar presas em chats ou memoria temporaria.

Tres opcoes foram consideradas:

- guardar tudo no Postgres;
- guardar tudo em Obsidian;
- guardar docs canonicos no repo e indexar no Postgres.

## Decisao

Usar docs canonicos no repositorio `atlas-server` como fonte de verdade e
sincronizar um indice estruturado para Postgres.

## Consequencias

Beneficios:

- docs mudam junto com codigo;
- diffs e reviews mostram mudancas de decisao;
- Postgres permite API, app, CLI e context packs;
- providers diferentes recebem o mesmo contexto;
- Obsidian continua possivel como camada humana.

Custos:

- exige manter frontmatter;
- exige rodar sync apos alteracoes;
- exige disciplina para nao colocar estado operacional dentro dos docs.

## Status

Aceito.

## Resumo

O Atlas usa docs versionados como fonte de verdade e Postgres como registry operacional para conhecimento de engenharia.

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
