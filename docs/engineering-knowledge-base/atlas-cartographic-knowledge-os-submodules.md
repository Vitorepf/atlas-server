---
id: atlas-cartographic-knowledge-os-submodules
type: engineering_knowledge
title: Atlas Cartographic Knowledge OS Submodules
status: future
category: cartography
priority: 90
implementation_state: future_target_not_current_runtime
summary: Detalhes extraidos dos submodulos da Cartografia visual: graph, semantic zoom, LOD, gear flow, links, inspector, overlays, shell humana, API de navegacao, auditor e replay.
tags:
  - atlas
  - cartography
  - visual-knowledge
  - semantic-zoom
capabilities:
  - cartographic_submodules
  - semantic_zoom
  - gear_flow_visualization
  - visual_completeness_audit
decisions:
  - Submodulos detalham a execucao visual, mas nao substituem o contrato pai.
  - Gear view deve explicar visualmente a peca tocada e long press deve abrir a doc humana estruturada.
maintenance:
  - Atualizar quando submodulos de Cartografia mudarem.
  - Manter abaixo de 520 linhas e dividir se crescer.
related_paths:
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cartographic-knowledge-os-submodules
graph_title: Atlas Cartographic Knowledge OS Submodules
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-cartographic-knowledge-os
graph_status: future
graph_source: repo
owner: atlas-cartography
repo_paths:
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os-submodules.md
allowed_changes:
  - Refinar submodulos visuais conforme implementacao evoluir.
forbidden_changes:
  - Contradizer o contrato pai da Cartografia.
  - Misturar patamar, versao, camada, fonte, regra, risco ou teste.
depends_on:
  - atlas-cartographic-knowledge-os
flows_to:
  - atlas-cartographic-knowledge-os
unlocks:
  - visual-ai-navigation
  - zoomable-operational-truth
governs:
  - atlas-cartography.submodules
evidence:
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os-submodules.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - npm run test:cartografia
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia esta doc quando for implementar submodulos visuais da Cartografia.
ai_usage_notes:
  - Esta doc e detalhe tecnico; o contrato pai decide a semantica.
quality_gates:
  - docs-health-pass
failure_modes:
  - Submodulo virar visual bonito sem fonte real.
observability_signals:
  - docs-health status ok
next_actions:
  - Manter submodulos sincronizados com a implementacao mobile/desktop.
line_limit: 520
---
# Atlas Cartographic Knowledge OS Submodules

## Resumo

Esta doc filha guarda os detalhes dos submodulos da Cartografia para manter o contrato pai limpo. Ela explica quais pecas existem para transformar documentacao canonica em visual navegavel, sem esconder fonte real.

## Papel no Atlas

Os submodulos sao a caixa de ferramentas visual da Cartografia: grafo, zoom semantico, detalhe por escala, fluxo de engrenagem, links, inspector, overlay epistemico, shell humana, API de IA, auditor e replay.

## Onde Se Encaixa

Contrato pai da Cartografia -> submodulos visuais -> implementacao mobile/desktop -> auditoria de docs e runtime.

## Contratos

- Contrato pai: `atlas-cartographic-knowledge-os`.
- Nomenclatura: `atlas-cartography-nomenclature-contract`.
- Glossario: `atlas-canonical-glossary-and-naming`.

## Fluxo

1. Semantic Graph entrega nodes e edges.
2. Submodulos transformam nodes em cenas visuais.
3. Tap troca a cena para o fluxo do node.
4. Long press abre modal humano estruturado.
5. Auditor aponta lacunas reais.

## Regras para IA

- Nao invente fluxo quando a doc nao declara.
- Quando faltar fluxo, marque lacuna documental.
- Patamar nao e versao, camada, fonte, dependencia ou proxima etapa.
- Todo submodulo precisa preservar source_path e graph_id.

## Escopo de Implementacao

Implementar submodulos por fatias: graph visual, semantic zoom, LOD, gear flow, link semantics, source inspector, overlay, shell, API de IA, auditor e replay.

## Detalhes Dos Submodulos

## Submodulos

### Visual Knowledge Graph

Cada node deve conter:

- graph_id;
- title;
- layer;
- kind;
- parent;
- source;
- source_path;
- owner;
- status;
- maturity;
- confidence;
- risk;
- inputs;
- outputs;
- dependencies;
- unlocks;
- evidence;
- next_actions.

Cada edge deve ter tipo:

- contains;
- flows_to;
- depends_on;
- unlocks;
- governs;
- implements;
- proves;
- contradicts;
- supersedes;
- renders;
- consumes;
- emits.

### Semantic Zoom Engine

Zoom nao e apenas aproximar pixels. Zoom e trocar pergunta.

| Nivel | Pergunta visual | Exemplo |
|---|---|---|
| L0 World | O que existe no Atlas? | sistemas principais |
| L1 Sector | Quais setores compoem esse sistema? | Kernel, Evidence, Runtime |
| L2 Flow | Como o setor opera? | pipeline com entradas e saidas |
| L3 Module | Que modulos executam cada etapa? | Atlas Decide, Policy |
| L4 Gear | Como essa engrenagem funciona por dentro? | decisao, teste, receipt |
| L5 Evidence | O que prova que isso existe? | codigo, teste, ledger |

### Level of Detail Engine

Em cada escala, detalhes irrelevantes devem sumir. Isso evita que a Cartografia
vire nuvem ilegivel.

Regras:

- L0 mostra poucos sistemas.
- L1 mostra familias e boundaries.
- L2 mostra fluxo.
- L3 mostra modulos com status.
- L4 mostra entradas, transformacoes, saidas e falhas.
- L5 mostra evidencias e comandos.

### Gear Flow Renderer

Uma engrenagem precisa mostrar:

- inputs;
- normalizacao;
- decisao ou transformacao;
- policies aplicadas;
- outputs;
- fallback;
- failure modes;
- tests;
- evidence;
- owner;
- proxima engrenagem.

Exemplo: ao abrir `Atlas Decide`, o renderer deve ocultar o restante do Atlas e
mostrar apenas o funcionamento interno: intent, policy, provider selection,
budget, autonomy, receipt, outputs e falhas.

### Link Semantics Engine

Links sao infraestrutura. Um link ruim e como uma rua errada no mapa. Para IA,
isso vira contexto errado. Para humano, vira cidade invisivel.

Cada link deve responder:

- por que existe?
- que tipo de dependencia representa?
- e direcional?
- e fluxo, autoridade, evidencia ou composicao?
- se quebrar, o que deixa de funcionar?
- aparece em qual nivel de zoom?

### Source Inspector

O inspector deve mostrar a fonte real:

- markdown do doc canonico;
- path;
- frontmatter;
- related paths;
- codigo relacionado;
- tests;
- Evidence Ledger;
- confidence;
- drift;
- contradictions;
- owner;
- next actions.

### Epistemic Overlay

Consome o Epistemic OS. Exemplos visuais:

- verde: confiavel e recente;
- amarelo: parcial ou stale;
- vermelho: contradicao ou drift;
- cinza: future/scaffold;
- tracejado: fonte ausente ou planejada;
- brilho: evidencia runtime recente;
- cadeado: escrita por IA bloqueada;
- seta: fluxo ativo;
- alerta: risco critico.

### Human Navigation Shell

O humano deve poder:

- ver a cidade inteira;
- buscar uma engrenagem;
- dar zoom progressivo;
- voltar pelo breadcrumb;
- filtrar por status, risco, owner, dominio ou maturity;
- abrir fonte;
- comparar esperado vs real;
- ver o que falta;
- entender sem depender de texto longo.

### AI Navigation API

Uma IA deve poder pedir:

- contexto de um node;
- vizinhos por profundidade;
- owner docs;
- allowed_changes e forbidden_changes;
- confidence e maturity;
- links de entrada e saida;
- shortest path entre duas engrenagens;
- impact map de uma mudanca;
- pacote de implementacao visualmente derivado.

### Visual Completeness Auditor

Audita se a cidade esta incompleta.

Findings:

- orphan_node;
- missing_source_path;
- missing_parent;
- missing_edge_type;
- stale_visual_node;
- undocumented_runtime_component;
- documented_but_unrendered_node;
- high_risk_without_evidence;
- implemented_without_visual_presence.

### Scenario Replay Layer

Mostra fluxo acontecendo. Exemplo:

```text
Input do usuario -> Surface Adapter -> Operation Envelope -> Intent Routing
-> Context Builder -> Policy -> Atlas Decide -> Decision Receipt
-> Runtime -> Quality Gates -> Evidence Ledger -> Output Renderer
```

Isso deve poder ser animado ou reconstituido a partir de Evidence Ledger.

## Dependencias

- `atlas-cartographic-knowledge-os`.
- `atlas-semantic-graph`.
- `atlas-cartography-nomenclature-contract`.

## Evidencias

- Esta doc filha.
- Doc pai de Cartographic Knowledge OS.
- Validador docs-health.

## Riscos

- Esconder informacao por layout cortado.
- Confundir submodulo tecnico com patamar.
- Mostrar fluxo visual sem fonte real.

## Exemplos

Ao tocar em `Atlas Decide`, Gear Flow Renderer troca a cena para o fluxo interno. Ao pressionar, Human Navigation Shell abre o modal textual estruturado.

## Proximas Acoes

- Garantir que cada submodulo tenha implementacao e teste proprio quando sair do estado futuro.
- Sincronizar a Cartografia mobile e desktop com este contrato.
