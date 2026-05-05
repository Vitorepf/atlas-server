---
id: atlas-ai-programming-domain
type: engineering_knowledge
title: Atlas AI Programming Domain
status: active
category: architecture
priority: 98
summary: Spec canonica do dominio implemented/ready Programming para dev, repair, review, refactor, QA, security, database, visual e forge.
tags:
  - atlas-ai
  - domains
  - programming
  - forge
  - engineering-harness
capabilities:
  - programming_domain
  - programming_orchestrator
  - dev_repair_executor
  - engineering_harness
  - programming_forge
decisions:
  - Programming e dominio implemented/ready e autoridade unica para fluxos de codigo.
  - `atlas dev`, `atlas forge`, `atlas fix`, `atlas continue`, chat dev/review/debug, API, app e MCP devem entrar por flows `programming.*`, nao por produtos paralelos.
  - Flows de harness, QA, security, database, visual e forge devem produzir evidence suficiente para gates e replay.
  - O dominio consome Core, Super Tool Runtime, Memory/Open Brain, Code Intelligence e Evidence Ledger; nao deve duplicar essas capacidades.
maintenance:
  - Atualize este documento quando flows programming, gates, executor preference, surfaces ou orchestrator mudarem.
  - Leia junto de atlas-ai-master-architecture.md, atlas-ai-operating-system.md, engineering-blueprint.md e programming-power-tools-catalog.md antes de alterar Programming runtime.
related_paths:
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php
  - app/Services/Ai/Programming/ProgrammingExecutionRequest.php
  - app/Services/Ai/Programming/ProgrammingExecutionResult.php
  - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AiChatCommand.php
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-operating-system.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
---

# Atlas AI Programming Domain

Programming e o dominio Atlas AI para trabalho de codigo: implementar,
reparar, revisar, refatorar, validar, testar, investigar banco, validar UI e
rodar forge/harness pesado. Ele e implemented/ready e deve ser a entrada unica
para qualquer fluxo operacional de codigo.

O dominio nao substitui o Core. Ele especializa criterio, plano, gates,
evidencia e execucao para engenharia, consumindo as capacidades horizontais do
Atlas: OperationEnvelope, DecisionReceipt, Open Brain, Code Intelligence,
Policy, Super Tool Runtime, Evidence Ledger e Learning.

## Flows

- `programming.dev`: implementacao geral com plano, contexto, policy, evidence e
  gates proporcionais ao risco.
- `programming.repair`: diagnostico e correcao de falhas usando
  `dev_repair_executor`.
- `programming.review`: revisao de codigo, riscos, regressao e achados
  acionaveis.
- `programming.refactor`: mudanca estrutural com controle de escopo,
  evidencia de qualidade e rollback mental claro.
- `programming.qa`: validacao, testes, regressao e evidence packet.
- `programming.security`: revisao de seguranca, permissoes, secrets,
  superficie de ataque e risk acceptance.
- `programming.database`: migrations, queries, rollback, integridade e safety de
  dados.
- `programming.visual`: smoke visual, responsividade, screenshots e evidence de
  UI.
- `programming.forge`: modo pesado com Engineering Harness, gates mais fortes,
  tool runtime e pacote final de evidencia.

## Surfaces

Surfaces finas devem escolher domain/flow e chamar o orchestrator:

- `atlas dev` -> `programming.dev`;
- `atlas forge` -> `programming.forge`;
- `atlas fix` -> `programming.repair` quando a intencao for repair;
- `atlas continue` -> retoma `dev_execution_plan` ou
  `programming_session_plan`;
- `atlas:ai:chat --dev` -> `programming.*` conforme mode/task;
- app/API/MCP -> `domain_id=programming` e `flow_id=programming.*`.

Nenhuma surface deve virar produto paralelo de programacao. A surface coleta
input, exibe output e preserva metadata; a autoridade operacional fica no
dominio.

## Context And Memory

Programming deve usar:

- Open Brain context injection;
- Engineering Knowledge Base;
- Code Intelligence;
- workspace inventory;
- docs/test links;
- Memory projection `programming`;
- relevant tool evidence and prior repair outcomes.

Contexto deve ser provider-safe e rastreavel. Diffs, logs, traces e findings
devem ser referenciados como evidencia, nao despejados sem criterio.

## Gates And Evidence

Programming flows devem declarar gates proporcionais ao risco:

- qualidade e regressao;
- test evidence;
- repair evidence;
- security evidence;
- migration safety;
- visual evidence;
- responsive check;
- risk summary;
- rollback/containment plan;
- tool runtime evidence quando o flow exigir harness.

`programming.qa`, `programming.security`, `programming.database`,
`programming.visual` e `programming.forge` devem preferir executor/harness
capaz de produzir evidence auditavel. `programming.review` pode usar executor
simples quando o output for somente findings.

## Repair Loop Contract

`programming.repair`, `programming.forge`, `atlas dev`, `atlas complete` e os
repairs nativos do worker devem convergir para o Repair Loop unico do kernel em
uma sessao futura. A fundacao atual vive em
`app/Services/Ai/Kernel/Repair/*` e define `RepairRequest`, `RepairPolicy`,
`RepairAttempt`, `RepairDecision`, `RepairResult` e
`AtlasRepairOrchestrator`.

Uso alvo para Programming:

- classificar qualquer falha operacional como `FailureClassification`;
- preservar `envelope_id` e `receipt_id` do `DecisionReceipt`;
- preencher `evidence_refs` com ledger events, harness runs, diffs, logs,
  screenshots ou test output relevantes;
- chamar `AtlasRepairOrchestrator::plan()` antes de qualquer tentativa;
- chamar `attempt()` apenas para obter contrato/evidence payload ate existir um
  executor real aprovado;
- inspecionar o contrato por `atlas:ai:repair --json` ou `POST /ai/repair`
  quando uma surface precisar mostrar o plano de reparo sem sair do fluxo
  Atlas Dev/Forge;
- bloquear repairs pesados sem evidencia;
- exigir revisao humana para dominios de policy, privacy, security,
  compliance, unknown e estados terminais.

Status atual: o repair nativo do `AiWorker` ja chama
`AtlasRepairOrchestrator::plan()` antes de enfileirar novo job de reparo. A
decisao do kernel fica em `programming_repair.kernel_decision` e nos payloads
de ledger do gate/repair. Se o kernel bloquear, o worker para com
`reason_if_stopped=kernel_repair_contract_blocks`.

`AtlasProgrammingOrchestrator::sessionPlan()` tambem publica
`repair_execution_contract` no plano de programacao. Assim, `atlas dev`,
`atlas fix` e `atlas chat --dev` carregam a mesma policy do Kernel Repair antes
de chegar ao worker: `kernel_repair_contract`, `allowed_strategies`,
`heavy_strategies` e `requires_evidence_for_heavy_repair`.

`EngineeringHarnessExecutionService` tambem ja passa resultados bloqueados,
parciais ou falhos do Forge/Harness pelo Kernel Repair. O resultado de
programacao carrega `kernel_repair_decision` e `repair_contract` quando existe
falha: contrato read-only vira `tool.policy_denied` com `human_review`, e falha
de harness com evidence refs pode planejar `rerun_harness` respeitando limites
de tentativa e evidencia obrigatoria para repair pesado. Cada decisao anexada
pelo harness tambem vira evento `REPAIR_INITIATED` no Evidence Ledger por
`AtlasEvidenceLedger::recordRepairDecision()`, com stage
`engineering_harness.repair`.

Os pontos `atlas:ai:repair` e `POST /ai/repair` continuam sendo surfaces seguras
de planejamento/tentativa scaffold, sempre sem execucao real de patch, provider,
tool ou harness. Planejamento grava `REPAIR_INITIATED`; tentativa scaffold grava
tambem `REPAIR_COMPLETED` com `causation_id` ligado ao hash da decisao.
`atlas:ai:ledger <envelope> --repair --json` e
`GET /ai/ledger/{envelope}?repair=1` projetam esses eventos em um resumo de
repair por envelope, incluindo status, estrategia, reasons, latest decision e
necessidade de revisao humana.

## Integration Status

Programming esta centrally registered como dominio Atlas AI implemented/ready.

- `AtlasProgrammingOrchestrator` implementa `AtlasDomainOrchestrator`.
- `config/atlas_ai.php` mapeia o orchestrator e seus flows suportados.
- `AtlasDomainProfileRegistry` expoe domain profile `programming` e flows
  `programming.*`.
- `AtlasAiPolicyService` aplica guard rails para repair e flows de harness.
- `atlas:ai:domains --json` reporta Programming como `ready 9/9`.
- `atlas:ai:architecture-validate --json` inclui Programming no ready domain
  count.

Validation:

- `php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php`
- `php artisan test tests/Feature/Ai/AtlasAiDomainsCommandTest.php`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`
