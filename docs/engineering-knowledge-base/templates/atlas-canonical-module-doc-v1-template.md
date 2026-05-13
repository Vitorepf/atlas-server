---
id: exemplo-modulo-canonico
type: engineering_knowledge
title: Exemplo Modulo Canonico
status: template
category: documentation
priority: 50
summary: Template para criar docs tecnicos que alimentam Cartografia, Atlas Code e IAs implementadoras.
tags:
  - atlas
  - template
capabilities:
  - canonical_docs
decisions:
  - Substitua todos os valores de exemplo antes de promover o doc para active ou building.
maintenance:
  - Copie este template para o local canonico do novo modulo.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: exemplo-modulo-canonico
graph_title: Exemplo Modulo Canonico
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas
graph_status: planned
graph_source: repo
owner: owner-area
repo_paths:
  - docs/engineering-knowledge-base/exemplo-modulo-canonico.md
allowed_changes:
  - Descreva o que uma IA pode alterar.
forbidden_changes:
  - Descreva o que uma IA nao pode alterar.
depends_on:
  - atlas
flows_to:
  - atlas
unlocks:
  - atlas
governs:
  - owner-area
evidence:
  - Evidencia real, doc, comando ou caminho verificavel.
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
next_actions:
  - Substituir este template por conteudo real.
---

# Exemplo Modulo Canonico

## Resumo

Explique em poucas linhas o que este modulo e e por que ele existe.

## Papel no Atlas

Explique o papel operacional do modulo no Atlas.

## Onde Se Encaixa

Declare pai, filhos, irmaos, camada e relacao com outros sistemas.

## Contratos

Liste entradas, saidas, invariantes, limites e responsabilidades.

## Fluxo

Mostre a sequencia operacional ou conexoes principais.

## Regras para IA

Declare como uma IA deve usar este doc antes de implementar.

## Escopo de Implementacao

Liste arquivos, comandos, areas permitidas e areas proibidas.

## Dependencias

Liste dependencias reais com `graph_id`, paths ou docs canonicos.

## Evidencias

Liste docs, comandos, testes, receipts, logs ou paths reais.

## Riscos

Liste riscos, anti-patterns e confusoes provaveis.

## Exemplos

Inclua exemplos concretos quando ajudarem humanos e IAs.

## Proximas Acoes

Declare a proxima acao concreta.
