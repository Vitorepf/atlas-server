---
id: atlas-programming-governance-system
type: engineering_knowledge
title: Atlas Programming Governance System
status: active
category: programming-governance
priority: 100
summary: Indice canonico dos gates que transformam programacao por IA em fluxo governado por placement, spec antes do codigo, contratos de tarefa, Code Intelligence, evidence, AHCL, learning e cartografia.
tags:
  - atlas
  - programming
  - governance
  - spec-before-code
  - code-intelligence
  - forge
capabilities:
  - programming_governance_system
  - feature_placement_gate
  - spec_before_code
  - task_contracts
  - evidence_required
  - code_intelligence_links
  - programming_learning_loop
  - programming_cartography
  - hierarchical_control_loop
  - governed_completion
decisions:
  - Atlas Programming Governance System e o nome canonico do conjunto de gates que governa programacao feita por IA.
  - Programar no Atlas nao e escrever codigo direto; e passar por placement, contexto, spec, contrato, execucao, evidence, learning e cartografia.
  - Spec antes do codigo e lei para qualquer alteracao estrutural, arriscada, multiarquivo, multiagente ou de arquitetura.
  - Code Intelligence e parte obrigatoria do fluxo; ele informa onde mexer, o que existe, quais simbolos/docs/testes se relacionam e onde ha risco.
  - Evidence obrigatorio separa implementacao real de opiniao do agente.
  - Atlas Hierarchical Control Loop e o controlador H/L antes de completion; ele decide continue, repair, replan, escalate ou submit.
  - Completion so pode fechar quando AHCL converge para `submit`.
  - Cartografia da programacao deve mostrar onde cada engrenagem de software fica, o que faz, quais docs a governam e qual evidence prova seu estado.
  - Atlas Dev e a fast lane governada deste sistema; seus gates sao projecoes compactas dos gates universais, nao um sistema paralelo.
maintenance:
  - Atualize este indice quando os gates de programacao, SDD, Engineering Blueprint, Code Intelligence, Forge Workspace, Self-Construction OS ou cartografia de codigo mudarem.
  - Mantenha este arquivo como indice curto; detalhes vivem nos child docs de contratos e runbook.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/code-intelligence/README.md
  - docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - ../../../dissecar/spec/ATLAS-SPEC-KIT-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-OPENSPEC-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-BMAD-METHOD-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-GOOSE-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-AIDER-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-TESSL-SDD-TILE-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-REQNROLL-DISSECTION.md
  - ../../../dissecar/spec/ATLAS-GAUGE-DISSECTION.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-governance-system

graph_title: Atlas Programming Governance System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-programming-domain

graph_status: active

graph_source: repo
human_name: Atlas Programming Governance System
canonical_name: Atlas Programming Governance System
technical_name: atlas-programming-governance-system
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-programming-governance-system.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md

allowed_changes:
  - Atualizar este indice quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.
  - Atualizar child docs quando contratos ou runbook de programacao mudarem.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.
  - Expandir este indice com detalhes que pertencem aos child docs.

depends_on:
  - atlas-ai-programming-domain
  - atlas-ai-spec-operating-system
  - code-intelligence
  - atlas-ai-knowledge-governance-system

flows_to:
  - atlas-forge-operating-system
  - atlas-ai-self-construction-os
  - atlas-cartographic-knowledge-os

unlocks:
  - ai-safe-programming-flow
  - forge-operating-system
  - programming-cartography

governs:
  - programming
  - programming.forge
  - code-generation
  - code-review
  - repair
  - refactor

evidence:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
evidence_refs:
  - symbol: AtlasProgrammingGovernanceSystemService
  - command: atlas:aaeos:programming-governance-system
  - test: AtlasProgrammingGovernanceSystemTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"
  - "php artisan atlas:engineering:knowledge index-code --prune --json --summary-only"

requires_evidence: true

risk_level: high

visual_tags:
  - programming
  - governance
  - forge
  - code-intelligence

ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Regras para IA e Evidencias antes de implementar qualquer fluxo de programacao.
  - Para detalhes, leia atlas-programming-governance-system-contracts.md e atlas-programming-governance-system-runbook.md.

ai_usage_notes:
  - Se uma tarefa pedir codigo estrutural, use este doc como checklist de governanca antes de escrever.
  - Se algum gate nao existir ainda, registre como gap; nao finja que o Atlas ja possui automacao completa.

quality_gates:
  - docs-health
  - spec-before-code
  - code-intelligence-context
  - evidence-required
  - cartography-update

failure_modes:
  - IA escreve codigo sem saber onde a feature pertence.
  - Spec e plano aparecem depois do codigo como justificativa retroativa.
  - Task contract nao limita arquivos, risco, testes e rollback.
  - Code Intelligence fica desatualizado e a IA altera simbolos errados.
  - Evidence vira resumo textual sem comando, log, diff, teste ou receipt.
  - Learning nao volta para docs, specs, prompts, gates ou cartografia.

observability_signals:
  - docs-health status ok
  - code intelligence index atualizado
  - receipts com evidence verificavel
  - links doc-codigo presentes

next_actions:
  - Implementar gates obrigatorios em CLI/API/UI para impedir programacao estrutural sem placement, spec, Code Intelligence e evidence.
---
# Atlas Programming Governance System

## Resumo

Atlas Programming Governance System e o sistema que governa programacao por IA
no Atlas. Ele nao e um editor, prompt ou ferramenta isolada. Ele define como uma
IA transforma intencao em codigo seguro: placement, contexto, spec, contrato,
execucao, evidence, learning e cartografia.

Para programacao pesada em `programming.forge`, leia tambem
`atlas-programming-forge-flow.md`. Governance define as regras; o Forge Flow
amarra essas regras ao Forge OS, Forge Workspace, Agentic RAG, Tool Runtime,
Engineering Harness Runner, Repair Loop, Evidence Ledger e Cartography.

Este arquivo e o indice canonico. Os detalhes foram separados para reduzir
drift documental:

| Documento | Papel |
|---|---|
| `atlas-programming-governance-system-contracts.md` | Contratos de placement, spec, task, Code Intelligence, evidence, learning e cartografia. |
| `atlas-programming-governance-system-runbook.md` | Fluxo operacional, arquitetura alvo, DoD, gaps e evidence de programacao governada. |
| `atlas-hierarchical-control-loop.md` | Controlador H/L que decide continue, repair, replan, escalate ou submit antes do completion. |

## Papel no Atlas

Transformar programacao em uma linha de producao governada:

- impedir implementacao solta;
- impedir duplicacao de arquitetura;
- obrigar spec antes do codigo quando o risco justificar;
- ligar mudancas a docs canonicos, simbolos, testes e evidence;
- permitir que Forge OS use varios agentes sem perder controle;
- alimentar Cartografia para humanos e IAs navegarem pelo software real.

## Onde Se Encaixa

```text
Sovereign OS
-> Epistemic OS
-> Knowledge Governance System
-> Programming Governance System
-> Forge Operating System
-> Programming Domain / Self-Construction OS
-> Runtime / Code / Tests / Evidence
-> Cartographic Knowledge OS
```

Programming Governance nao substitui Programming Domain. O dominio executa
fluxos `programming.*`; este sistema define os gates que todo fluxo de
programacao deve obedecer. Forge OS e a fabrica que aplica estes gates em
trabalho pesado, longo, multiagente ou multiprovider.

## Contratos

Contratos detalhados vivem em
`docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md`.

Invariantes:

- feature estrutural passa por placement;
- spec vem antes do codigo quando ha risco estrutural;
- task contract limita arquivos, risco, testes, rollback e evidence;
- Code Intelligence orienta contexto real;
- evidence separa implementacao real de narrativa;
- learning volta para docs, specs, prompts, gates ou cartografia.

### Atlas Dev Fast Lane

Atlas Dev aplica uma versao compacta e proporcional destes invariantes para trabalho diario. Ele nao substitui Programming Governance e nao possui gates concorrentes. Alguns gates sao projecoes diretas; outros sao Dev-only para operacionalizar o fast path, mas nao podem contradizer Governance.

Mapeamento canonico:

| Programming Governance | Atlas Dev Efficient Flow |
| --- | --- |
| placement | `intake_risk_gate` |
| code intelligence | `context_budget_gate` + `CodeDiscoveryManifest` |
| spec-before-code | `mini_spec_before_code_gate` |
| scope guard | `scope_guard_light` |
| evidence | `receipt_gate` |
| hierarchical-control | `completion_state_gate` + halt decision |
| completion | `completion_state_gate` |

Gates Dev-only justificados: `light_task_contract_gate`, `verification_gate`, `forge_escalation_gate`. Eles adicionam contrato operacional, verificacao focada e parada segura para Forge preview.

O fast path pode reduzir payload e custo por R-level, mas nao pode relaxar uma lei de governanca: write sem spec, sem contrato, sem escopo, sem verification/evidence ou sem completion state continua invalido.

## Fluxo

Runbook detalhado vive em
`docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md`.

Fluxo resumido:

```text
intake
-> placement
-> code intelligence
-> spec/delta
-> task contract
-> agent role
-> execution
-> acceptance/tests
-> evidence
-> docs/index/cartography
-> learning
-> hierarchical-control
-> completion gate
```

## Regras para IA

- Nao escrever codigo estrutural antes de placement/contexto.
- Nao criar spec retroativa para justificar diff pronto.
- Nao mexer em arquivo sem entender dono, risco e teste proporcional.
- Nao chamar evidence um resumo sem comando, diff, teste, log ou receipt.
- Nao declarar gate implementado quando ainda e roadmap.
- Nao expandir este indice; detalhes pertencem aos child docs.

## Escopo de Implementacao

Este sistema governa:

- feature placement;
- spec-before-code;
- change delta;
- task contracts;
- Code Intelligence context;
- runner/tool gateway;
- executable acceptance;
- evidence ledger;
- repair/refactor governance;
- docs/code intelligence/cartography refresh;
- hierarchical control loop;
- completion gate.

## Dependencias

- Programming Domain;
- Spec Operating System;
- Engineering Blueprint;
- Code Intelligence;
- Forge Operating System;
- Self-Construction OS;
- Knowledge Governance;
- Cartographic Knowledge OS.

## Evidencias

Evidencia minima para mudanca governada:

- placement ou justificativa de escopo pequeno;
- context pack quando estrutural;
- spec/delta quando necessario;
- task contract;
- diff dentro de escopo;
- testes/gates proporcionais;
- docs/cartografia quando afetadas;
- receipt ou log verificavel;
- risco residual.

## Riscos

- IA programar por memoria e alterar modulo errado;
- governanca virar burocracia sem prova;
- Code Intelligence stale orientar contexto falso;
- repair virar loop sem learning;
- cartografia atrasar e deixar humanos/IA cegos.

## Exemplos

Use governanca completa para mudanca multiarquivo, arquitetura, schema,
provider/runtime, security/privacy, self-construction ou cartografia.

Use governanca compacta para patch pequeno, typo, ajuste local ou teste focado,
preservando ownership, prova e limite.

## Implementacao Atual

Status `building`: thin slice runtime publicada em
`app/Services/Ai/Programming/Governance/` e sob a superficie
`atlas:programming:*`. Detalhes operacionais e gaps explicitos vivem em
`atlas-programming-governance-system-runbook.md` (secao "Implementacao Atual").

CLI canonica:

```text
atlas:programming:intake
atlas:programming:spec
atlas:programming:plan
atlas:programming:receipt
atlas:programming:verify
atlas:programming:hierarchical-control
atlas:programming:complete
atlas:programming:status
```

Tabelas vivas (Postgres read model do fluxo):
`atlas_programming_work_items`, `atlas_programming_gate_runs`,
`atlas_programming_reviews`. Evidence verificavel reutiliza
`atlas_engineering_evidence`. Stage receipts pre-existentes (`atlas_programming_stage_receipts`)
permanecem para encadeamento com `AtlasProgrammingOrchestrator` quando o fluxo
delegar execucao para o Engineering Harness.

## Proximas Acoes

1. Manter este indice abaixo do limite de documentacao ativa.
2. Evoluir contratos em `atlas-programming-governance-system-contracts.md`.
3. Evoluir fluxo em `atlas-programming-governance-system-runbook.md`.
4. Rodar docs-health, sync e index-code apos alteracoes canonicas.
5. Promover plan/tasks autogeneration, cartografia automatizada e learning
   loop quando saírem do estado de gap registrado nos work items.
