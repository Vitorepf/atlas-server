---
id: atlas-ai
type: engineering_knowledge
title: Atlas AI
status: active
category: atlas-ai
priority: 100
summary: Raiz canonica do universo Atlas AI: Kernel, Mission Mode, Autonomous Intelligence OS, dominios, surfaces, evidence, policy e runtime governado.
tags:
  - atlas
  - atlas-ai
  - cartography-root
  - autonomous-intelligence
capabilities:
  - atlas_ai_root
  - atlas_ai_cartography_parent
decisions:
  - Atlas AI e subuniverso do Atlas, nao sinonimo de Atlas inteiro.
  - Filhos de Atlas AI devem aparecer abaixo deste node ou de um index canonico descendente.
  - Cartografia nao pode deixar docs de Atlas AI orfaos sob parent inexistente.
maintenance:
  - Atualizar quando o parent canonico de Atlas AI mudar.
  - Rodar docs-health e testes de cartografia apos alterar hierarquia.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-mission-mode.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai
graph_title: Atlas AI
graph_world: atlas
graph_layer: world
graph_kind: system
graph_parent: atlas
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai.md
allowed_changes:
  - Adicionar filhos canonicos de Atlas AI via graph_parent ou related_paths.
forbidden_changes:
  - Usar Atlas AI como nome para o Atlas inteiro.
  - Deixar novo documento ativo de Atlas AI apontar para parent inexistente.
depends_on:
  - atlas-ai-canonical-architecture-index
unlocks:
  - atlas-ai-cartography-root
flows_to:
  - atlas-autonomous-intelligence-operating-system
  - atlas-mission-mode
governs:
  - atlas_ai.semantic_root
evidence:
  - docs/engineering-knowledge-base/atlas-ai.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - npm run test:cartografia
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Use este node como raiz visual quando uma peca declara graph_parent atlas-ai.
quality_gates:
  - cartography-orphan-count-zero
failure_modes:
  - IA perde contexto porque documentos ativos de Atlas AI ficam orfaos na Cartografia.
observability_signals:
  - atlas-cartography audit orphan_count
next_actions:
  - Manter filhos ativos de Atlas AI apontando para este graph_id quando forem parte do subuniverso de inteligencia.
line_limit: 220
---
# Atlas AI

## Resumo

Atlas AI e a raiz visual do subuniverso de inteligencia do Atlas. Ele agrupa
Kernel, Mission Mode, Autonomous Intelligence OS, dominios, surfaces, evidence,
policy e runtime governado.

## Regra

Se um documento ativo declara `graph_parent: atlas-ai`, a Cartografia deve
conseguir encaixa-lo abaixo deste node. Se este node sumir, o grafo volta a
ficar orfao e a IA pode navegar contexto errado.

## Papel no Atlas

Raiz semantica do subuniverso Atlas AI dentro do Atlas maior.

## Onde Se Encaixa

Fica abaixo da raiz `atlas` e acima de Kernel, Mission Mode, Autonomous
Intelligence OS, dominios e surfaces de IA.

## Contratos

- Nao representa o Atlas inteiro.
- Todo filho ativo precisa de `graph_parent` resolvivel.
- Cartografia deve mostrar este node como raiz visual de inteligencia.

## Fluxo

Docs de IA apontam para `atlas-ai`; leitores de Cartografia usam esse parent
para montar o grafo operacional.

## Regras para IA

Nao inventar filhos ou mover documentos para este node sem atualizar docs,
evidencias e gates.

## Escopo de Implementacao

Somente documentacao canonica e navegacao de grafo; nao adiciona runtime.

## Dependencias

- `atlas-ai-canonical-architecture-index`
- Cartografia e docs-health.

## Evidencias

- `php artisan atlas:engineering:knowledge docs-health --json`
- Este arquivo com frontmatter canonico.

## Riscos

Parent ausente cria docs orfaos e degrada recuperacao de contexto.

## Exemplos

`atlas-mission-mode` e `atlas-autonomous-intelligence-operating-system` devem
apontar para `graph_parent: atlas-ai`.

## Proximas Acoes

Manter novos docs ativos de Atlas AI com parent, owner, evidencias e testes.
