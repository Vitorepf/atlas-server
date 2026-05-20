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
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
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
visual_tags:
  - mapa-visual
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA e Evidencias antes de alterar codigo.
ai_usage_notes:
  - Use allowed_changes e forbidden_changes como limite inicial de escopo.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
failure_modes:
  - Declarar implementado sem evidencia real.
observability_signals:
  - docs-health status ok
patamar_current:
patamar_next_of:
patamar_next:
patamar_after: []
version_family:
versions: []
version_note:
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

Se houver patamar, declare em `patamar_current`, `patamar_next_of`,
`patamar_next` ou `patamar_after`. Nao use `flows_to`, `unlocks`, camada visual
ou versao como substituto de patamar.

## Contratos

Liste entradas, saidas, invariantes, limites e responsabilidades.

## Fluxo

Mostre a sequencia operacional ou conexoes principais.

Se este modulo tiver subfluxo interno visual, declare `gear_flow` no
frontmatter. Se ainda nao tiver subfluxo, deixe claro aqui e em `next_actions`
qual lacuna documental/operacional impede a Cartografia de abrir um fluxo rico.

## Regras para IA

Declare como uma IA deve usar este doc antes de implementar.

## Escopo de Implementacao

Liste arquivos, comandos, areas permitidas e areas proibidas.

## Dependencias

Liste dependencias reais com `graph_id`, paths ou docs canonicos.

## Evidencias

Liste docs, comandos, testes, receipts, logs ou paths reais.

Todo doc que alimenta Cartografia deve manter pelo menos um teste ou gate em
`required_tests`/`quality_gates`. Se nao houver teste automatizado especifico,
declare o gate de docs-health e explique a lacuna em `failure_modes`.

## Riscos

Liste riscos, anti-patterns e confusoes provaveis.

## Exemplos

Inclua exemplos concretos quando ajudarem humanos e IAs.

Exemplo de nomenclatura:

- Patamar: salto de maturidade/capacidade. Ex.: Self-Construction OS ->
  Self-Programming OS.
- Versao: revisao, release ou degrau da mesma peca. Ex.: Atlas Vox V0/V3/V4/V6.
- Documentacao relacionada: leitura auxiliar em `related_paths`; nao e fonte
  principal, prova, patamar ou versao.
- Fluxo visual: `gear_flow`/`target_graph_id` abre outra visualizacao; nao e
  patamar e nao e versao.

## Proximas Acoes

Declare a proxima acao concreta.
