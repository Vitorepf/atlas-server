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
