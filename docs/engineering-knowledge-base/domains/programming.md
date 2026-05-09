---
id: atlas-ai-programming-domain
type: engineering_knowledge
title: Atlas AI Programming Domain
status: active
category: architecture
priority: 98
summary: Spec canonica do dominio implemented/ready Programming para dev, repair, review, refactor, QA, security, database, visual, forge e specialist profiles internos.
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
  - Especialistas tecnicos como frontend, backend-api, mobile e performance vivem como specialist profiles dentro de Programming, nao como dominios paralelos.
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
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/domains/programming-surfaces.md
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/archive/source-material/domains-programming-full-2026-05-08.md
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

Mapa detalhado de surfaces, contracts e override de modelo vive em
`programming-surfaces.md`. Regra curta: surface coleta input/exibe output;
Programming e Atlas Decide mantem autoridade operacional.

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

## Specialist Profiles

`programming.visual` existe hoje, mas nao substitui um especialista frontend
completo. A evolucao correta e manter Programming como dominio unico e adicionar
specialist profiles internos, começando por `programming.frontend`.

Perfis alvo: `programming.frontend`, `programming.backend_api`,
`programming.mobile`, `programming.architecture`, `programming.performance`,
`programming.accessibility`, `programming.testing`, `programming.api_contract`
e `programming.devops_sre`.

A regra completa vive em `programming-specialist-profiles.md`. Ate existir subflow
formal, surfaces devem enviar `specialist_profile=programming.frontend` junto de
`flow_id=programming.visual` ou `programming.dev`, nunca criar `domain_id=frontend`.

## Repair Loop Contract

Contrato detalhado vive em `programming-repair-contract.md`. Regra curta:
Programming classifica falha, preserva receipt/evidence, planeja pelo Kernel
Repair e nunca faz repair pesado sem evidencia/review apropriado.

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
