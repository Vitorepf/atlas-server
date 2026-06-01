---
id: atlas-programming-self-construction-forge-map-v1
type: engineering_knowledge
title: Atlas Programming Self-Construction Forge Map v1
status: active
category: programming-forge
priority: 102
summary: Mapa canonico curto para qualquer IA entender a hierarquia entre Self-Construction OS, Self-Programming OS, Forge Continuum OS, Atlas Code, Obras, Agent Control Plane e multi-provider sem procurar em dezenas de docs.
tags:
  - atlas
  - atlas-code
  - programming
  - forge
  - self-construction
  - self-programming
  - ai-entrypoint
capabilities:
  - ai_orientation
  - programming_architecture_map
  - forge_continuum_orientation
  - self_construction_orientation
decisions:
  - Esta e a doc curta de orientacao para IAs antes de explicar Atlas Code, Forge Continuum, Self-Construction OS ou Self-Programming OS.
  - Atlas Agentic Engineering OS e o nome da camada-mae de engenharia quando a pergunta for substituir uma area tech inteira; Forge Continuum continua sendo a especializacao de programacao pesada.
  - Atlas Forge Continuum OS ja e a especializacao operacional de programacao pesada; nao crie outro OS paralelo para isso.
  - Atlas Dual-Core Engineering System governa a fronteira entre Atlas Dev e Atlas Forge; nao fundir os dois e nao subordinar Forge ao Dev.
  - Atlas Code e surface humana; nao e o sistema inteiro, nao e provider e nao e Obra.
  - Self-Construction OS e a lei-mae para Atlas construir Atlas.
  - Self-Programming OS e o patamar/conjunto de safety contracts para auto-modificacao governada; ainda nao deve ser tratado como runtime livre.
  - Self-Directed Evolution Layer e camada de composicao/curadoria acima de Self-Construction, Self-Improvement, AAEL e Spec OS; nao e OS novo nem substitui qualquer owner.
  - Agent Control Plane, Work Splitter, Packet Contract, Scope Validator e Multi-Provider Orchestration sao contratos operacionais abaixo de Self-Construction OS e Forge.
maintenance:
  - Atualize este mapa antes de renomear qualquer doc-mae de Atlas Code, Forge Continuum, Self-Construction OS, Self-Programming OS, Agent Control Plane ou multi-provider.
  - Mantenha este arquivo curto; ele existe para orientar IA rapidamente, nao para substituir os docs-mae.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
  - docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-desktop-code-surface.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-programming-self-construction-forge-map-v1
graph_title: Atlas Programming Self-Construction Forge Map v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
human_name: Atlas Programming Self-Construction Forge Map v1
canonical_name: Atlas Programming Self-Construction Forge Map v1
technical_name: atlas-programming-self-construction-forge-map-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
owner: programming
patamar_current: Self-Construction OS
patamar_next: Self-Programming OS
patamar_after:
  - Self-Programming OS so pode avancar para runtime mais autonomo depois de safety contracts, receipts, gates e evidencia real.
version_note: Este mapa pode ter versoes do documento, mas a relacao Self-Construction OS -> Self-Programming OS e patamar, nao versao.
repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
allowed_changes:
  - Clarificar hierarquia, nomes canonicos, ordem de leitura e fronteiras entre OS, surface, Obra, provider e contracts.
forbidden_changes:
  - Declarar que Atlas Code e o sistema inteiro.
  - Declarar que Forge Continuum substitui Self-Construction OS.
  - Declarar que Self-Programming OS esta liberado para autoexecucao sem safety contracts, receipts e gates.
  - Criar Self-Directed Evolution como runtime paralelo quando Subsystem Builder, Self-Improvement, AAEL, Spec OS ou Forge ja cobrem o owner.
  - Criar doc-mae paralela para programacao pesada quando Forge Continuum OS ja cobre esse papel.
depends_on:
  - atlas-forge-continuum-os
  - atlas-programming-forge-flow
  - atlas-ai-self-construction-os
  - atlas-code-programming-obras-operating-system
flows_to:
  - atlas-code
  - forge-continuum
  - self-construction
  - multi-provider-orchestration
unlocks:
  - fast-ai-orientation
  - no-duplicate-programming-os
  - canonical-forge-hierarchy
governs:
  - ai_read_first.programming_forge_hierarchy
evidence:
  - docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md
evidence_refs:
  - symbol: AtlasProgrammingSelfConstructionForgeMapService
  - command: atlas:aaeos:programming-self-construction-forge-map
  - test: AtlasProgrammingSelfConstructionForgeMapTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc primeiro quando a pergunta envolver Atlas Code, Forge Continuum, Self-Construction OS, Self-Programming OS, Obras de Programacao ou multi-provider.
ai_usage_notes:
  - Use este mapa para decidir quais docs-mae ler em seguida. Nao use este mapa como contrato de runtime.
quality_gates:
  - no-duplicate-os
  - correct-canonical-name
  - provider-neutrality-preserved
  - self-programming-not-overclaimed
failure_modes:
  - IA demora para achar a hierarquia e inventa uma camada nova.
  - IA chama Atlas Code de produto inteiro.
  - IA confunde Self-Construction OS com Forge Continuum OS.
  - IA trata Claude, Codex ou Gemini como papel fixo em vez de provider substituivel.
observability_signals:
  - read_first_used
  - hierarchy_explained
  - canonical_docs_selected
next_actions:
  - Manter linkado em START_HERE, README e Canonical Architecture Index.
  - Atualizar se Forge Continuum, Self-Programming safety ou Agent Control Plane mudarem de autoridade.
line_limit: 320
---
# Atlas Programming Self-Construction Forge Map v1

## Resumo

Esta e a pagina curta para IA entender a hierarquia sem vasculhar dezenas de
docs.

## Papel no Atlas

Este modulo e a porta de entrada curta para perguntas sobre Atlas Code, Forge,
Self-Construction, Self-Programming, Obras de Programacao e providers. Ele nao
substitui docs-mae; ele diz qual doc-mae vence e qual deve ser lida depois.

## Onde Se Encaixa

```text
Documentation / Spec / Kernel law
-> Self-Construction OS
-> Programming Governance
-> Programming Forge Flow
-> Forge Continuum OS
-> Atlas Code surface
```

Camada anterior a Obra:

```text
Atlas AI Conversation Surface
-> Atlas Dev
-> Intervencao Rapida / Candidato de Obra
-> Atlas Forge / Obra de Programacao
```

Use `atlas-ai-conversation-surface-and-atlas-dev-v1.md` quando a pergunta for
chat tecnico, consulta, bug rapido, pesquisa de codigo, debug, continuidade
mobile/desktop ou quando decidir se um trabalho deve virar Obra.

## Contratos

Contratos canonicos que este mapa organiza:

- `atlas-ai-self-construction-os.md`
- `self-construction/self-programming-safety-contract.md`
- `atlas-programming-forge-flow.md`
- `atlas-forge-continuum-os.md`
- `atlas-code-programming-obras-operating-system.md`
- `self-construction/multi-provider-agent-orchestration-contract.md`
- `atlas-self-directed-evolution-layer.md`

## Fluxo

```text
Self-Construction OS
  = lei-mae para Atlas construir e evoluir o proprio Atlas.

Self-Programming OS
  = patamar/safety layer para auto-modificacao governada.

Forge Continuum OS
  = especializacao operacional de programacao pesada.

Atlas Code
  = surface humana do Forge Continuum.

Programming Obra
  = unidade produtiva de software dentro de um Project/Workspace.

Providers
  = executores substituiveis, nunca donos da arquitetura.

Self-Directed Evolution Layer
  = inbox/read-model de propostas de evolucao; compoe Subsystem Builder,
    Self-Improvement, AAEL, Spec OS, TEOS/ASRE e Evidence.
```

## Regras para IA

- Leia este doc primeiro quando a pergunta envolver Atlas Code + Forge +
  Self-Construction.
- Nao crie OS paralelo se o papel ja estiver em Forge Continuum.
- Nunca trate provider como papel fixo.
- Nunca declare Self-Programming OS como runtime livre.
- Nunca implemente Self-Directed Evolution criando detector/proposal runtime
  paralelo aos owners existentes.

## Escopo de Implementacao

Permitido: orientar leitura, resolver nomenclatura, reduzir tempo de busca de IA
e prevenir duplicacao de arquitetura. Proibido: definir runtime, autorizar
dispatch, substituir safety contracts ou promover autonomia.

## Regra Principal

Nao crie um novo "Programming Self-Programming OS" separado.

Nao funda Atlas Dev e Atlas Forge. A fronteira entre os dois pertence a:

```text
atlas-dual-core-engineering-system.md
```

O papel operacional de programacao pesada ja pertence ao:

```text
atlas-forge-continuum-os.md
```

Se precisar explicar isso em uma frase:

```text
Forge Continuum OS e a encarnacao operacional de programacao pesada dentro da
familia Self-Construction / Self-Programming do Atlas.
```

## Hierarquia Canonica

```text
Atlas Sovereign / Kernel / Spec / Documentation laws
-> Self-Construction OS
   -> Self-Programming safety contracts
   -> Agent Control Plane
   -> Multi-Provider Orchestration
   -> AI Implementation Packets
   -> Work Splitter / Scope Validator / Reservation Ledger
-> Programming Governance
-> Programming Forge Flow
-> Forge Continuum OS
   -> Forge Workspace
   -> Atlas Decide provider topology
   -> governed invocation / fallback / repair
   -> review / evidence / learning
-> Atlas Code
   -> Obra Command Center
   -> Attention Control Plane
   -> Adaptive Provider Operating Room
```

## O Que Cada Nome Quer Dizer

| Nome | Significado | Nao e |
|---|---|---|
| Self-Construction OS | Como Atlas evolui Atlas com docs, SDD, packets, gates, evidence e learning | Tela de programacao |
| Self-Programming OS | Patamar de auto-modificacao governada com safety contracts | Runtime livre ou permissao para autoeditar tudo |
| Self-Directed Evolution Layer | Camada de gap/proposal/roadmap/curadoria sobre owners existentes | OS novo, builder novo ou autoaprovador |
| Forge Continuum OS | Sistema completo de programacao pesada: Obra, Forge Workspace, Atlas Decide, providers, fallback, review, repair, evidence | Apenas provider router ou prompt |
| Programming Forge Flow | Mapa/taxonomia do fluxo inteiro de programacao pesada | Executor |
| Atlas Code | Surface desktop humana para operar Programming Obras | O sistema inteiro |
| Programming Obra | Unidade produtiva governada de uma entrega de software | Chat, branch, ticket ou terminal |
| Provider | Claude, Codex, Gemini, local ou futuro executor | Dono permanente de papel |

## Ordem De Leitura Para IA

Para pergunta sobre Atlas Code ou programacao pesada:

1. Este doc.
2. `atlas-programming-forge-flow.md`.
3. `atlas-forge-continuum-os.md`.
4. `atlas-code-programming-obras-operating-system.md`.
5. Docs filhos especificos da pergunta.

Para pergunta sobre Atlas construindo Atlas:

1. Este doc.
2. `atlas-ai-self-construction-os.md`.
3. `atlas-self-directed-evolution-layer.md`, se a pergunta envolver Atlas propondo gaps/specs/roadmap.
4. `self-construction/self-programming-safety-contract.md`.
5. `self-construction/agent-control-plane-contract.md`.
6. `self-construction/multi-provider-agent-orchestration-contract.md`.

Para pergunta sobre varios providers na mesma Obra:

1. Este doc.
2. `atlas-code-adaptive-provider-operating-room-v1.md`.
3. `self-construction/multi-provider-agent-orchestration-contract.md`.
4. `self-construction/ai-implementation-packet-contract.md`.
5. `self-construction/work-splitter-contract.md`.

## Anti-Confusoes

- Atlas Code nao programa sozinho; Atlas Code mostra e governa a operacao humana.
- Forge Continuum OS nao substitui Self-Construction OS; ele especializa programacao pesada.
- Self-Programming OS nao esta liberado como autonomia irrestrita; ele exige safety, receipts, gates e evidence.
- Self-Directed Evolution nao e permissao para criar outro Self-Construction; ele normaliza propostas e passa por curadoria humana.
- Providers nao sao cargos fixos. Claude, Codex, Gemini e local podem trocar de papel conforme Atlas Decide, evidencia, capacidade, risco e decisao humana.
- Obra nao nasce de qualquer chat. Obra nasce quando ha unidade produtiva persistente com objetivo, escopo, contexto, acceptance, gates e evidence.

## Frase Canonica

```text
Atlas Code e a surface. Forge Continuum OS e o sistema operacional de
programacao pesada. Self-Construction OS e a lei-mae de evolucao do Atlas.
Self-Programming OS e o patamar de auto-modificacao governada. Providers sao
executores substituiveis, Obras sao as unidades produtivas, e Self-Directed
Evolution e a inbox governada de propostas sobre owners ja existentes.
```

## Dependencias

- `atlas-ai-self-construction-os.md`
- `atlas-self-directed-evolution-layer.md`
- `atlas-programming-forge-flow.md`
- `atlas-forge-continuum-os.md`
- `atlas-code-programming-obras-operating-system.md`

## Evidencias

- Este mapa existe em `docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md`.
- `START_HERE.md`, `README.md` e `atlas-ai-canonical-architecture-index.md`
  apontam para este mapa como orientacao curta.

## Riscos

- IA ignorar este mapa e inventar camada nova.
- IA ler apenas este mapa e nao abrir docs-mae quando for implementar.
- Mudar autoridade de Forge Continuum sem atualizar este mapa.

## Exemplos

Correto: "Forge Continuum OS e a especializacao operacional de programacao
pesada." Errado: "precisamos criar outro OS de programacao do zero."

## Proximas Acoes

Manter este arquivo curto e linkado em qualquer bootstrap de IA que explique ou
implemente Atlas Code, Forge, Obras de Programacao ou multi-provider.
