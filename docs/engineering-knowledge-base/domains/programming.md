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
  - Atlas Programming Governance System governa placement, spec antes do codigo, task contracts, Code Intelligence, evidence, learning e cartografia dentro dos fluxos de programacao.
  - Atlas Programming Forge Flow e a pagina-mae de taxonomia e fluxo para programacao pesada.
  - Atlas Forge Operating System e o patamar acima para trabalho pesado, multiagente ou multiprovider sobre `programming.forge`.
  - O standard operacional de RAG/Agentic RAG profissional vive em programming-professional-rag-operating-standard.md.
  - Os oito saltos enterprise de programacao vivem em programming-enterprise-implementation-plan.md.
  - RAG/Agentic RAG profissional de programacao vive em programming-agentic-rag-professional-spec.md; MVP de RAG nao e criterio de conclusao aceitavel.
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
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/domains/programming-surfaces.md
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/archive/source-material/domains-programming-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-domain

graph_title: Atlas AI Programming Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/programming.md

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
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/programming.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

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
# Atlas AI Programming Domain

Programming e o dominio Atlas AI para trabalho de codigo: implementar,
reparar, revisar, refatorar, validar, testar, investigar banco, validar UI e
rodar forge/harness pesado. Ele e implemented/ready e deve ser a entrada unica
para qualquer fluxo operacional de codigo.

Antes de criar feature, remover codigo ou declarar algo legado/morto, o dominio
deve obedecer `atlas-code-reality-usage-intelligence.md`. ACRUI e o contrato
que separa runtime ativo, read-only, headless, scaffold, legacy adapter,
duplicacao, unused candidate e dead code confirmado.

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

## Heavy Programming Canon

Para trabalho pesado, longo, multiagente, multiprovider, com repair loop forte,
Agentic RAG, graphs, tools ou harness, a primeira leitura e
`atlas-programming-forge-flow.md`.

Taxonomia curta:

```text
Programming Domain = setor de programacao.
programming.forge = flow pesado.
Atlas Forge Operating System = fabrica operacional.
Forge Workspace = ambiente/escritorio compartilhado.
Engineering Harness Runner = motor executor.
Atlas Code = surface desktop.
Atlas Code SCOR-1 = versao da surface.
```

`programming.forge` nao e produto paralelo e nao substitui o Programming Domain.
Forge Workspace nao e o fluxo inteiro. Engineering Harness Runner nao e o Forge
OS inteiro. Agentic RAG, Semantic Code Graph, Tool Runtime, Repair Loop,
Evidence Ledger, Learning e Cartography sao camadas estruturais do fluxo pesado.

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

## Forge Workspace

`programming.forge` must use Forge Workspace for heavy or multi-provider work.
Forge Workspace is the Programming specialization of Obras Shared Workspace.

It owns the shared programming office:

- mother contract and spec;
- provider-specific context packs;
- work packets and allowed/forbidden files;
- artifact bus for Gemini, Claude, Codex, local agents and future providers;
- scope/collision map;
- integration queue and evidence normalization.

Providers may specialize, but they must not relay loose context to each other as
the source of truth. Codex implements, Claude plans/reviews, Gemini scouts and
local agents validate through workspace artifacts governed by Atlas.

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

## Resumo

Spec canonica do dominio implemented/ready Programming para dev, repair, review, refactor, QA, security, database, visual, forge e specialist profiles internos.

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
