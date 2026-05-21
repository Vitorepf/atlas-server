---
id: atlas-ai-flow-visual-map
type: engineering_knowledge
title: Atlas AI Flow Visual Map
status: active
category: architecture
priority: 100
summary: Especificacao canonica para diagramas visuais do fluxo Atlas AI, alinhando pipeline, dominios, business contexts, runtime, evidence, AtlasVault e learning.
tags:
  - atlas-ai
  - visual-flow
  - pipeline
  - architecture
capabilities:
  - visual_flow_governance
  - pipeline_onboarding
decisions:
  - Diagramas do Atlas AI devem seguir a ordem canonica do pipeline.
  - Atlas Decide vem depois de Domain/Profile/Flow, Context e Policy.
  - Business Contexts como Blackink ficam fora do Domain Plane.
  - Capability, harness, runtime e linguagem nao sao Atlas AI Domains.
  - AtlasVault/Obsidian e Human Knowledge Surface, nao fonte operacional crua.
  - Obras Shared Workspace e o escritorio compartilhado canonico para trabalho longo ou multi-provider.
  - Evidence, AP-99, calibration e Learning retornam por policy/proposal/Decide.
maintenance:
  - Manter abaixo de 280 linhas.
  - Atualizar antes de redesenhar imagem, slide, onboarding visual ou diagrama de arquitetura.
related_paths:
  - docs/engineering-knowledge-base/assets/fluxoatlasaiv3.png
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-business-contexts.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/domains/README.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-flow-visual-map

graph_title: Atlas AI Flow Visual Map

graph_world: atlas

graph_layer: flow

graph_kind: flow

graph_parent: atlas-ai-pipeline

graph_status: active

graph_source: repo
human_name: Atlas AI Flow Visual Map
canonical_name: Atlas AI Flow Visual Map
technical_name: atlas-ai-flow-visual-map
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md

owner: architecture

gear_flow:
  - graph_id: atlas-ai-flow-visual-map:surface
    target_graph_id: surface-plane
    name: Surface
    kind: input
    summary: usuario, app, mobile, CLI, API ou MCP coleta entrada sem decidir
  - graph_id: atlas-ai-flow-visual-map:atlas-input
    target_graph_id: atlas-input
    name: Atlas Input
    kind: input
    summary: normaliza texto, imagem, audio, arquivo, paste e contexto
  - graph_id: atlas-ai-flow-visual-map:operation-envelope
    target_graph_id: operation-envelope
    name: Operation Envelope
    kind: context
    summary: cria unidade canonica com trace, tenant e origem
  - graph_id: atlas-ai-flow-visual-map:intent-routing
    target_graph_id: intent-routing
    name: Intent / Routing
    kind: context
    summary: classifica pedido, risco e tipo de tarefa
  - graph_id: atlas-ai-flow-visual-map:business-context
    target_graph_id: business-context
    name: Business Context
    kind: context
    summary: separa projeto, ambiente, empresa e produto do dominio cognitivo
  - graph_id: atlas-ai-flow-visual-map:domain-plane
    target_graph_id: domain-plane
    name: Domain Plane
    kind: context
    summary: seleciona capacidade cognitiva ou operacional
  - graph_id: atlas-ai-flow-visual-map:domain-profile
    target_graph_id: domain-profile-flow
    name: Domain Profile
    kind: context
    summary: resolve sistema operacional vertical
  - graph_id: atlas-ai-flow-visual-map:flow-profile
    target_graph_id: domain-profile-flow
    name: Flow Profile
    kind: context
    summary: resolve processo operacional dentro do dominio
  - graph_id: atlas-ai-flow-visual-map:context-builder
    target_graph_id: context-builder
    name: Context Builder
    kind: context
    summary: monta Open Brain, Memory, KB, Code Intelligence e AtlasVault sync
  - graph_id: atlas-ai-flow-visual-map:policy-profile
    target_graph_id: policy-profile
    name: Policy / Profile
    kind: policy
    summary: define permissao, privacidade, autonomia, custo e gates
  - graph_id: atlas-ai-flow-visual-map:atlas-decide
    target_graph_id: atlas-decide
    name: Atlas Decide
    kind: decision
    summary: compila modelo, provider, fallback e evidence contract
  - graph_id: atlas-ai-flow-visual-map:decision-receipt
    target_graph_id: decision-receipt
    name: Decision Receipt
    kind: gate
    summary: assina contrato, dry-run, limites e auditoria
  - graph_id: atlas-ai-flow-visual-map:runtime-executor
    target_graph_id: runtime-executor
    name: Runtime / Executor
    kind: output
    summary: executa providers, harnesses, workers e tools dentro do receipt
  - graph_id: atlas-ai-flow-visual-map:quality-gates
    target_graph_id: quality-gates
    name: Quality Gates
    kind: gate
    summary: valida seguranca, testes, SLO, compliance e visual QA
  - graph_id: atlas-ai-flow-visual-map:repair-escalation
    target_graph_id: repair-escalation
    name: Repair / Escalation
    kind: failure
    summary: corrige, reexecuta, pede review ou bloqueia
  - graph_id: atlas-ai-flow-visual-map:evidence-ledger
    target_graph_id: evidence-ledger
    name: Evidence Ledger
    kind: gate
    summary: persiste eventos append-only para replay e auditoria
  - graph_id: atlas-ai-flow-visual-map:learning-proposals
    target_graph_id: learning-proposals
    name: Learning / Proposals
    kind: context
    summary: gera memoria, metricas, proposals e human review sem autoalterar criticamente
  - graph_id: atlas-ai-flow-visual-map:output-renderer
    target_graph_id: output-renderer
    name: Output Renderer
    kind: output
    summary: surface apresenta resposta, patch, plano, proposta ou briefing

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-flow-visual-map.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - flow
  - flow
  - architecture

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
# Atlas AI Flow Visual Map

Este documento governa a imagem "Arquitetura de Fluxo do Atlas" para humano e
IA entenderem o mesmo fluxo antes de implementar.

## Asset Canonico

Imagem aprovada: `assets/fluxoatlasaiv3.png`.
![Arquitetura de Fluxo do Atlas AI v3](assets/fluxoatlasaiv3.png)

Essa imagem representa o estado final enterprise do Atlas AI, nao apenas o
estado atual da implementacao. Se houver conflito entre imagem e texto, este
documento + `atlas-ai-pipeline.md` vencem.

## Fluxo Canonico V3

```text
Surface
-> Atlas Input
-> Operation Envelope Created
-> Intent / Routing
-> Business Context
-> Domain Plane
-> Domain Profile
-> Flow Profile
-> Context Builder
-> Policy / Profile
-> Atlas Decide
-> Decision Receipt
-> Runtime / Executor
-> Quality Gates
-> Repair / Escalation
-> Evidence Ledger
-> Learning / Proposals
-> Output Renderer
```

## Caixas Principais

| Zona | Texto recomendado | Regra |
|---|---|---|
| Surface Plane | Usuario / App / Mobile / CLI / API / MCP | Surface coleta input e mostra output; nao decide |
| Atlas Input | texto, imagem, audio, arquivo, paste, contexto | Preserva origem e anexos |
| Operation Envelope | unidade canonica, trace, tenant, origem | Tudo relevante vira envelope |
| Intent / Routing | pedido, risco, tipo de tarefa | Classifica sem executar |
| Business Context | Blackink, Atlas, empresa, projeto | Contexto de negocio, nao dominio |
| Domain Plane | Programming, Finance, Personal Development, Self-Improvement | Capacidade cognitiva/operacional |
| Domain/Profile/Flow | dominio + sistema operacional vertical + processo | Resolve antes do Decide |
| Context Builder | Open Brain, Memory, KB, Code Intelligence, AtlasVault sync | Contexto com hash, budget e privacy |
| Obras Shared Workspace | contratos, packets, artifact bus, status, integracao | Escritorio compartilhado; nao decide nem substitui Forge |
| Policy / Profile | permissao, privacidade, autonomia, custo, gates | Regras concretas da chamada |
| Atlas Decide | modelo, provider, fallback, evidence contract | Compila decisao; nao executa |
| Decision Receipt | contrato assinado, dry-run, limites, auditoria | Autoriza runtime |
| Runtime / Executor | providers, harness, workers, tools | Executa dentro do receipt |
| Quality Gates | seguranca, testes, SLO, compliance | Decide se passou com evidence |
| Repair / Escalation | corrigir, reexecutar, pedir review, bloquear | Retorna via policy/receipt/Decide |
| Evidence / Learning | ledger, AP-99, read models, proposals | Aprendizado sem auto-mutacao critica |
| Output Renderer | resposta, patch, plano, proposta | Surface apresenta ao operador |

## Domain Plane

Desenhe como dominios ready/current:

```text
Programming; Finance; Personal Development; Self-Improvement
```

Desenhe como scaffold/future, com etiqueta diferente:

```text
Marketing; Research; Operations / Strategic Decision; Health; Writing / Learning
```

Nao desenhar como dominio:

```text
Blackink; MiroFish; Frontend; Swift; Python; Go; AtlasVault; Super Tool Runtime; Scenario Simulation
```

Eles sao business context, source material/capability, specialist profile,
runtimes, human surface, tool runtime e harness.

## Runtime Plane

Divida `Runtime / Executor` em:

```text
Laravel Kernel / Maestro; Provider Drivers: Claude, Codex, Gemini, OpenAI, local; Super Tool Runtime; Engineering Harness / Forge Runner; Python AI/Data Runtime; Go Edge/Concurrency Runtime; Swift Native Mac Runtime
```

Regra: Python, Go e Swift executam capacidades especializadas; nao decidem
domain, modelo, policy, gate, repair ou learning.

## Capability / Harness Shelf

Se houver espaco, adicione prateleira abaixo de Runtime:

```text
Programming Harness
Frontend Design Harness
Scenario Simulation Harness
Content Intelligence
Tool Synthesis Sandbox
Dynamic Compute Market
```

Use status visual: `active`, `scaffold` ou `future`.

## Obras Shared Workspace

Para trabalho longo, pesado ou multi-provider, adicione uma caixa lateral:

```text
Obras Shared Workspace
contracts, packets, artifact bus, status, integration
```

Esse e o nome canonico do escritorio compartilhado. Em Programming, sua
especializacao e `Forge Workspace`. Ele alimenta Context Builder e Runtime com
artefatos governados, mas Kernel/Decide continuam sendo autoridade.

Quando o diagrama representar programacao pesada, use
`atlas-programming-forge-flow.md` como mapa da hierarquia completa. Forge
Workspace e o ambiente; nao e o fluxo inteiro nem o executor.

## Evidence And Learning

Lado direito laranja:

```text
Evidence Ledger
append-only, replay, audit
        |
Read Models / Telemetry
AP-99, SLO, cost, repair, provider performance, calibration
        |
Learning / Memory Signals
memoria, metricas, quality score, outcome calibration
        |
Self-Improvement / Curator
proposal, review, AP, gaps, bugs, drift
        |
Proposal Inbox / Human Review
nao autoaltera comportamento critico
```

Outcome calibration deve aparecer quando houver simulacao, marketing, produto,
programacao em producao ou decisao com resultado real.

## Human Knowledge Surface

Lado direito superior:

```text
Human Knowledge Surface
AtlasVault / Obsidian
Personal Knowledge Workspace
managed sync, review, escrita humana
nao fonte operacional crua
```

AtlasVault pode alimentar Context Builder por adapter governado e pode receber
notas gerenciadas. Eventos relevantes continuam indo para Evidence Ledger.

## Documentation OS

Inclua caixa pequena de governanca:

```text
Documentation Operating System
START_HERE + Canonical Index + KB + Code Intelligence
```

Ela orienta humanos/IAs; nao e etapa obrigatoria de toda request.

## Mermaid Canonico

```mermaid
flowchart TD
    U["Usuario / App / Mobile / CLI / API / MCP"] --> S["Surface Adapter"]
    S --> I["Atlas Input"]
    I --> E["Operation Envelope"]
    E --> R["Intent / Routing"]
    R --> BC["Business Context"]
    R --> D["Domain Plane"]
    BC --> Ctx
    D --> PF["Domain / Profile / Flow"]
    PF --> Ctx["Context Builder"]
    Ctx --> P["Policy / Profile"]
    OSW["Obras Shared Workspace"] <--> Ctx
    OSW -. packets/artifacts .-> X
    P --> AD["Atlas Decide"]
    AD --> DR["Decision Receipt"]
    DR --> X["Runtime / Executor"]
    X --> G["Quality Gates"]
    G --> Q{"Passou?"}
    Q -- "sim" --> O["Output Renderer"]
    Q -- "nao" --> RP["Repair / Escalation"]
    RP --> AD
    X -. eventos .-> EL["Evidence Ledger"]
    G -. eventos .-> EL
    RP -. eventos .-> EL
    O -. eventos .-> EL
    EL --> RM["Read Models / AP-99 / Calibration"]
    RM --> L["Learning / Memory Signals"]
    L --> SI["Self-Improvement / Curator"]
    SI --> PI["Proposal Inbox / Human Review"]
    PI -. proposal/policy .-> AD
    V["AtlasVault / Obsidian"] -. managed sync .-> Ctx
    CDoc["Documentation OS"] -. orienta .-> S
```

## Principios De Rodape

1. Surface nao decide.
2. Provider nao decide.
3. Tool nao decide.
4. Domain nao burla policy.
5. Business Context nao e Domain.
6. Capability/Harness nao e Domain.
7. Runtime nao executa sem Decision Receipt.
8. Modelo manual e override auditado, nao bypass.
9. Repair retorna via policy/receipt/Decide.
10. Todos os eventos relevantes viram Evidence.
11. Learning nao altera comportamento critico sem proposal/review.
12. AtlasVault e surface humana, nao fonte operacional crua.
13. Simulacao nao e profecia; vira hipotese calibravel.
14. Tudo repetido vira Core.

## Checklist Da Imagem V3

1. Atlas Decide vem depois de Context Builder e Policy/Profile.
2. Business Context fica fora do Domain Plane; Domain/Profile/Flow e obrigatorio.
3. Runtime separa Laravel/Python/Go/Swift, providers e Forge sem tornar harness em dominio.
4. Evidence mostra AP-99, read models, calibration, learning e human review.
5. AtlasVault e Human Knowledge Surface; Documentation OS orienta; Obras Shared Workspace aparece como escritorio compartilhado quando ha trabalho longo ou multi-provider.

## Resumo

Especificacao canonica para diagramas visuais do fluxo Atlas AI, alinhando pipeline, dominios, business contexts, runtime, evidence, AtlasVault e learning.

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
