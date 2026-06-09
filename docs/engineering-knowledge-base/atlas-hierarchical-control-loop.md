---
id: atlas-hierarchical-control-loop
type: engineering_knowledge
title: Atlas Hierarchical Control Loop
status: active
category: programming-governance
priority: 99
summary: Controlador H/L que governa sessoes longas de programacao por IA e decide continue, repair, replan, escalate ou submit antes do completion.
tags:
  - atlas
  - programming
  - governance
  - hierarchical-control
  - long-session
capabilities:
  - hierarchical_control_loop
  - halt_decision
  - h_level_strategy
  - l_level_execution
  - completion_guard
decisions:
  - Atlas Hierarchical Control Loop e o controlador canonico de sessoes longas e dificeis de programacao por IA.
  - AHCL vive dentro de Programming Governance; nao cria runtime paralelo a Atlas Dev, Forge OS ou Programming Domain.
  - Completion so pode fechar quando H-level e L-level convergem para `submit`.
  - H-level governa spec, plan, risco, scope, gates obrigatorios, review e limites canonicos.
  - L-level governa execution truth: evidence, gate runs, task contracts, falhas e estado operacional real.
  - A saida canonica e `atlas.programming.halt_decision.v1` com action `continue`, `repair`, `replan`, `escalate` ou `submit`.
maintenance:
  - Atualize este doc quando `ProgrammingHierarchicalControlLoopService`, gates obrigatorios, completion gate ou comandos `atlas:programming:*` mudarem.
related_paths:
  - app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php
  - app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php
  - app/Services/Ai/Programming/Governance/Gates/ProgrammingHierarchicalControlGate.php
  - app/Services/Ai/Programming/Governance/ProgrammingScopeMode.php
  - app/Services/Ai/Programming/Governance/ProgrammingGovernanceService.php
  - app/Services/Ai/Programming/Governance/Gates/ProgrammingCompletionGate.php
  - app/Console/Commands/AtlasProgrammingHierarchicalControlCommand.php
  - app/Console/Commands/AtlasProgrammingAdaptiveControlPlaneCommand.php
  - tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php
  - tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php
  - docs/engineering-knowledge-base/atlas-adaptive-hierarchical-control-plane.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-hierarchical-control-loop

graph_title: Atlas Hierarchical Control Loop

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-programming-governance-system

graph_status: active

graph_source: repo
human_name: Atlas Hierarchical Control Loop
canonical_name: Atlas Hierarchical Control Loop
technical_name: atlas-hierarchical-control-loop
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
  - app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php
  - app/Services/Ai/Programming/Governance/Gates/ProgrammingHierarchicalControlGate.php
  - app/Console/Commands/AtlasProgrammingHierarchicalControlCommand.php

allowed_changes:
  - Evoluir a decisao H/L quando novas evidencias, gates ou flows de programacao forem adicionados.
  - Ajustar thresholds de readiness sem enfraquecer spec, evidence, review ou completion.

forbidden_changes:
  - Criar outro completion controller concorrente.
  - Permitir `submit` sem evidence, review aprovado e prior blocking gates verdes.
  - Tratar AHCL como executor de codigo; ele decide direcao, nao aplica patch.

depends_on:
  - atlas-programming-governance-system
  - atlas-programming-governance-system-runbook
  - code-intelligence
  - atlas-engineering-evidence-ledger

flows_to:
  - atlas-adaptive-hierarchical-control-plane
  - atlas-code
  - atlas-forge-operating-system
  - atlas-cartographic-knowledge-os

unlocks:
  - long-session-programming-control
  - governed-completion
  - forge-submit-readiness

governs:
  - programming.hierarchical-control
  - programming.completion

evidence:
  - app/Services/Ai/Programming/Governance/ProgrammingHierarchicalControlLoopService.php
  - app/Services/Ai/Programming/Governance/Gates/ProgrammingHierarchicalControlGate.php
  - tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php

evidence_refs:
  - command: php artisan test tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php
  - command: php artisan test tests/Feature/ProgrammingGovernance
  - command: php artisan atlas:programming:hierarchical-control <work_item> --json

required_tests:
  - "php artisan test tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php"
  - "php artisan test tests/Feature/ProgrammingGovernance"

requires_evidence: true

risk_level: high

visual_tags:
  - programming
  - control-loop
  - evidence

ai_entrypoints:
  - Leia este doc antes de alterar completion, verify, Atlas Code long session, Forge submit ou gates de programacao.

ai_usage_notes:
  - Use AHCL para perguntar "o que fazer agora?" em sessoes longas; nao use para pular spec, evidence ou review.

quality_gates:
  - hierarchical-control
  - completion
  - evidence-required
  - spec-before-code

failure_modes:
  - AHCL virar burocracia sem leitura de evidence real.
  - AHCL liberar submit com review ausente.
  - AHCL virar executor e duplicar Forge/Atlas Dev.
  - Completion fechar antes de H/L convergir.

observability_signals:
  - atlas.programming.hierarchical_control_state.v1
  - atlas.programming.halt_decision.v1
  - atlas_programming_gate_runs gate_name=hierarchical-control

next_actions:
  - Consumir Atlas Adaptive Hierarchical Control Plane para projetar action, reason, readiness, next_step e next_command no Atlas Code.
---
# Atlas Hierarchical Control Loop

## Resumo

Atlas Hierarchical Control Loop (AHCL) e o controlador canonico de programacao
assistida por IA em sessoes longas, dificeis ou de alto risco. Ele responde uma
pergunta simples e critica: **o Atlas deve continuar, reparar, replanejar,
escalar ou submeter para conclusao?**

Ele nao substitui Programming Governance, Atlas Dev ou Forge OS. Ele e o
controle H/L dentro do fluxo de programacao. Para v2/v3/v4/v5, leia tambem
`atlas-adaptive-hierarchical-control-plane.md`.

```text
Intent
-> Spec
-> Plan
-> Execution
-> Evidence
-> Gates
-> Review
-> AHCL halt decision
-> Completion
```

## Papel no Atlas

Sem AHCL, uma IA em sessao longa tende a continuar trabalhando mesmo quando:

- a spec esta ausente;
- o plano perdeu relacao com o objetivo;
- evidence ainda nao prova a mudanca;
- gates falharam;
- review pediu reparo;
- o trabalho parece completo, mas nao esta pronto para cert.

AHCL cria o ponto de parada governado antes do completion.

## Onde Se Encaixa

AHCL fica abaixo do Programming Governance System e acima do completion gate.
Atlas Dev, Forge OS, Atlas Code e qualquer surface de programacao devem
consumir a mesma decisao H/L quando precisam saber se uma sessao pode fechar.

```text
Programming Domain
-> Programming Governance
-> Gates + Evidence + Review
-> Atlas Hierarchical Control Loop
-> Completion
-> Evidence/Learning/Cartography
```

## H-Level

H-level e o controle estrategico. Ele olha o contrato maior:

- intent text e intent type;
- scope mode `compact` ou `structural`;
- risk level;
- current stage e status;
- spec_hash e presenca da spec;
- plan_hash e presenca do plano;
- quantidade de task contracts;
- review result;
- gates obrigatorios, blocking e advisory;
- gaps ainda abertos;
- regra anti-sprawl: nao criar runtime paralelo.

Se H-level encontra falta estrutural, ele nao manda "continuar programando".
Ele manda `replan` ou `escalate`.

## L-Level

L-level e a verdade operacional. Ele olha a execucao real:

- evidence_count;
- latest_evidence;
- task_contracts resumidos;
- latest gate run por gate;
- missing prior blocking gates;
- failed prior blocking gates;
- waived prior blocking gates;
- review payload.

Se L-level encontra falha de qualidade, evidence ausente ou gate nao rodado, ele
manda `continue` ou `repair`.

## Contratos

### Halt Decision

Schema canonico: `atlas.programming.halt_decision.v1`.

Actions:

| Action | Significado | Quando usar |
|---|---|---|
| `continue` | execucao ainda deve seguir | falta evidence, gates ainda nao rodaram, proximo passo operacional existe |
| `repair` | houve falha corrigivel | review pediu mudanca ou quality gate falhou |
| `replan` | contrato estrategico precisa mudar | falta spec/plan, spec-before-code falhou, placement/code-intelligence invalido |
| `escalate` | precisa decisao humana ou autoridade maior | review ausente, blocked/deferred, falha desconhecida |
| `submit` | pronto para completion | evidence existe, review approved, prior blocking gates verdes |

Campos obrigatorios:

```json
{
  "schema_version": "atlas.programming.halt_decision.v1",
  "action": "continue|repair|replan|escalate|submit",
  "reason": "machine_reason",
  "next_step": "human readable next step",
  "next_command": "php artisan ...",
  "required_repairs": [],
  "h_cycle_required": false,
  "l_cycle_required": false,
  "blocking": true,
  "decided_at": "ISO-8601"
}
```

### Runtime Implementado

Servicos:

```text
ProgrammingHierarchicalControlLoopService
  -> buildState()
  -> decide()
  -> readinessScore()

ProgrammingHierarchicalControlGate
  -> gate name: hierarchical-control
  -> passa somente quando halt_decision.action = submit

AtlasProgrammingHierarchicalControlCommand
  -> php artisan atlas:programming:hierarchical-control <work_item> --json
```

O gate `hierarchical-control` e required tanto em `compact` quanto em
`structural`, sempre imediatamente antes de `completion`.

## Fluxo

Compact:

```text
evidence-required
-> scope-guard
-> hierarchical-control
-> completion
```

Structural:

```text
feature-placement
-> code-intelligence-context
-> spec-before-code
-> evidence-required
-> scope-guard
-> docs-health
-> cartography-update
-> hierarchical-control
-> completion
```

## Completion Contract

`atlas:programming:complete` registra `AtlasProgrammingReview` e roda:

```text
hierarchical-control
-> completion
```

Completion so fecha quando:

- evidence existe;
- review e `approved`;
- prior blocking gates estao verdes;
- `hierarchical-control` passou;
- `completion` passou.

## Escopo de Implementacao

AHCL governa a decisao de parada e fechamento do trabalho de programacao:

- work items de Programming Governance;
- completion de Atlas Dev;
- submit/promotion de Forge;
- sessoes longas do Atlas Code;
- repair loop quando review ou gates falham;
- replan quando spec/plan/placement/code-intelligence quebram.

AHCL nao executa patch, nao roda provider, nao substitui Forge e nao altera
evidence. Ele apenas decide a proxima direcao governada.

## Readiness Score

O readiness score e uma leitura operacional de 0 a 9.5:

- spec presente;
- plan presente;
- task contracts existem;
- evidence existe;
- prior blocking gates verdes;
- review approved;
- `submit` converge para 9.5.

Ele nao substitui gate. Score alto sem `submit` nao fecha work item.

## Uso no Atlas Code

Atlas Code deve projetar AHCL como painel de controle de sessao longa:

- action;
- reason;
- readiness score;
- next_step;
- next_command;
- H-cycle required;
- L-cycle required;
- required_repairs;
- latest evidence;
- gate posture.

O operador deve conseguir ver rapidamente se a Obra esta em:

```text
CONTINUE -> executar/provar
REPAIR   -> corrigir falha
REPLAN   -> voltar para spec/plan
ESCALATE -> pedir decisao humana
SUBMIT   -> pronto para cert/completion
```

## Uso no Forge

Forge deve usar AHCL no fim de work packets e antes de promotion/release:

- se `continue`, ainda falta evidence/gate;
- se `repair`, abrir repair packet;
- se `replan`, voltar para spec/plan/placement;
- se `escalate`, parar scheduler ou pedir operador;
- se `submit`, liberar promotion para completion/release gate.

## Dependencias

- Atlas Programming Governance System;
- Programming Domain;
- Code Intelligence;
- Evidence Ledger;
- AtlasProgrammingReview;
- ProgrammingGateRunner;
- Forge OS para execucao pesada;
- Atlas Code para projection visual.

## Regras para IA

- Antes de fechar trabalho, leia ou rode AHCL.
- Nunca declare conclusao se action nao for `submit`.
- Se action for `replan`, nao continue codando; atualize spec/plan.
- Se action for `repair`, faca patch pequeno, gere evidence e rode gates.
- Se action for `escalate`, pare e apresente a decisao pendente.
- Se action for `continue`, siga o `next_command`.
- AHCL nao e justificativa para pular docs, evidence, review ou cartografia.

## Riscos

- AHCL virar formalidade e nao ler evidence real.
- AHCL liberar `submit` sem review approved.
- Completion ignorar AHCL.
- Forge criar decisao paralela de submit.
- Atlas Code mostrar score, mas esconder reason/next_step.

## Exemplos

Exemplo 1: structural sem spec.

```text
H-state: spec_present=false
decision: replan
next_command: atlas:programming:spec <work_item> ... --json
```

Exemplo 2: evidence existe, gates verdes, review ausente.

```text
decision: escalate
reason: h_level_operator_review_missing
next_command: atlas:programming:complete <work_item> --review=approved --strict --json
```

Exemplo 3: review approved, evidence existe, prior gates verdes.

```text
decision: submit
completion: allowed
```

## Definition of Done

AHCL esta concluido para o thin slice atual quando:

- existe service canonicamente testavel;
- existe gate registrado no provider;
- `ProgrammingScopeMode` exige `hierarchical-control`;
- `complete` roda AHCL antes de `completion`;
- comando CLI imprime JSON e suporta `--strict`;
- testes provam `replan`, `repair` e `submit`;
- suite de Programming Governance permanece verde.

## Evidencias

Validado por:

```text
php artisan test tests/Feature/ProgrammingGovernance/HierarchicalControlLoopTest.php
php artisan test tests/Feature/ProgrammingGovernance
```

## Proximas Acoes

1. Projetar AAHCP no Atlas Code com action, reason, readiness, next_step e next_command.
2. Fazer Forge promotion consultar v3/v4 antes de release.
3. Registrar AHCL/AAHCP decisions como eventos de cartografia de execucao.
4. Criar replay visual das transicoes continue/repair/replan/escalate/submit.
