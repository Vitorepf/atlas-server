---
id: atlas-ai-self-improvement-domain
type: engineering_knowledge
title: Atlas AI Self-Improvement Domain
status: active
category: architecture
priority: 96
summary: Spec canonica do dominio implemented/ready self_improvement para auditoria, aprendizado operacional, proposals e melhoria continua do Atlas.
tags:
  - atlas-ai
  - domains
  - self-improvement
  - evidence-ledger
  - learning
capabilities:
  - self_improvement_domain
  - docs_drift_review
  - capability_gap_scan
  - proposal_generation
decisions:
  - Self-Improvement e dominio implemented/ready, nao apenas conceito de curadoria.
  - O dominio opera sobre evidencias, metrics, ledger, KB, code intelligence, tool evidence e benchmark corpus.
  - O dominio pode propor melhorias, mas mudancas estruturais continuam exigindo gates, review e approval humano quando o risco pedir.
  - Pesquisa de alto nivel, source quality e promocao para docs sao governadas por Atlas AI Research Intelligence And Self-Improvement Runtime antes de virarem implementacao.
  - Curator dedicado e um possivel refinamento futuro; hoje a curadoria operacional implementada vive em self_improvement.
maintenance:
  - Atualize este documento quando flows self_improvement, gates, scheduler, surfaces ou evidence sources mudarem.
  - Leia junto de atlas-ai-master-architecture.md e atlas-ai-kernel-architecture.md antes de alterar runtime de auto-melhoria.
related_paths:
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - app/Console/Commands/AtlasAiSelfImproveCommand.php
  - docs/engineering-knowledge-base/domains/self-improvement-flows.md
  - docs/engineering-knowledge-base/domains/self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/research-self-improvement/research-operating-system.md
  - docs/engineering-knowledge-base/research-self-improvement/evidence-lake-and-citation-health.md
  - docs/engineering-knowledge-base/research-self-improvement/multi-agent-research-roles.md
  - docs/engineering-knowledge-base/research-self-improvement/scheduled-research-and-triggers.md
  - docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
  - docs/engineering-knowledge-base/research-self-improvement/private-connectors-security-and-stack.md
  - docs/engineering-knowledge-base/research-self-improvement/source-quality-and-trust-ladder.md
  - docs/engineering-knowledge-base/research-self-improvement/research-pipeline.md
  - docs/engineering-knowledge-base/research-self-improvement/research-to-docs-promotion.md
  - docs/engineering-knowledge-base/research-self-improvement/continuous-self-improvement-loop.md
  - docs/engineering-knowledge-base/research-self-improvement/schemas-and-packets.md
  - docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
  - docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
  - docs/ap/AP-689-research-self-improvement-runtime-contract.md
  - docs/engineering-knowledge-base/archive/source-material/domains-self-improvement-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
---

# Atlas AI Self-Improvement Domain

Self-Improvement e o dominio Atlas AI para autoavaliar, metrificar, aprender e
propor melhorias no proprio Atlas. Ele e implemented/ready e deve aparecer no
catalogo como dominio operacional de primeira classe.

O dominio nao substitui review humano, PR, migrations, docs canonicos ou gates
arquiteturais. Ele cria evidencia, findings e proposals para que o Atlas evolua
com rastreabilidade.

Pesquisa de estado-da-arte e aceleracao de evolucao usam
`atlas-ai-research-self-improvement-runtime.md` como lei superior da frente:
fonte primaria, evidencia, documentacao canonica e plano validado vêm antes de
implementacao estrutural.

## Flows

Flows detalhados vivem em `self-improvement-flows.md`.

Resumo canonico:

- nightly review;
- weekly architecture audit;
- capability gap scan;
- benchmark review;
- memory quality review;
- tool runtime review;
- repair loop review;
- kernel pipeline review;
- domain learning review;
- docs drift review;
- provider performance review;
- agent behavior review;
- proposal generation.

## Sources

Self-Improvement deve consumir fontes auditaveis:

- Evidence Ledger;
- `AtlasLedgerReplayService` (`sloReportForWindow`) para drift SLO por janela,
  com dimensoes `domain`, `flow`, `surface_id`, `provider`, `model`, `runtime`
  e `tool_id`;
- `AtlasLedgerReplayService` (`repairReportForWindow`) para padroes de Repair Loop;
- `AtlasLedgerReplayService` (`kernelPipelineReportForWindow`) para padroes de Kernel Pipeline;
- `AtlasAiDomainCatalogService` para scorecards de onboarding de dominios,
  incluindo dominios `scaffold` e `executable_incomplete`, fases faltantes,
  proximas acoes e contadores por status;
- `AtlasAiArchitectureValidationService` para architecture validation
  compartilhado por CLI, API, Observability e Open Brain/MCP. Quando algum AP
  falha, `weekly_architecture_audit` e o review default geram finding/proposal
  provider-safe com `architecture_validation_ap`, failed keys, violation count e
  health de kernel/capabilities/domains/orchestrators;
- `GET /ai/slo` e `atlas:ai:slo`;
- `GET /ai/repair/report` e `atlas:ai:repair-report`;
- `GET /ai/kernel-pipeline/report` e `atlas:ai:kernel-pipeline-report`;
- architecture validation;
- domain scorecards;
- KB canonica;
- Code Intelligence;
- tool evidence;
- memory quality signals;
- Local RAG readiness/benchmark e contrato `LOCAL_RAG_*`;
- provider performance traces;
- benchmark corpus;
- user corrections and reviewed feedback.

Notas humanas, AtlasVault e Obsidian so entram como Human Knowledge Surface /
Personal Knowledge Workspace: material de pesquisa e revisao, nunca fonte
operacional primaria.

## Safety Contract

- Default autonomy baixa.
- Background execution permitido apenas para flows configurados e observaveis.
- Proposals devem ser provider-safe, com origem e risco explicitos.
- Mudancas em runtime, policy, migrations, config ou docs mae exigem workflow
  normal de implementacao/review.
- O dominio nao deve auto-aplicar refactors, apagar documentos, promover vault
  humano direto para runtime ou alterar fonte operacional sem approval.

## Integration Status

Self-Improvement esta centrally registered como dominio Atlas AI implemented/ready.

- `AtlasSelfImprovementOrchestrator` resolve flows `self_improvement.*`.
- `AtlasSelfImprovementRuntime` executa os 13 flows especializados.
- Detalhes de runtime, filtros, schedule, AP42-AP56 e replay vivem em
  `self-improvement-runtime.md`.
- `atlas:ai:self-improve --list-flows --json` inspeciona flows sem executar.
- `atlas:ai:self-improve --schedule-plan --json` inspeciona o plano recorrente
  efetivo sem criar `AtlasInitiativeRun` nem executar runtime.
- `atlas:ai:self-improve --flow=... --plan-only --json` renderiza plano,
  gates, runtime e executor sem criar run operacional.
- `atlas:ai:domains --json` reporta `self_improvement` como ready, com o flow
  dedicado `self_improvement.repair_loop_review` no catalogo.
- `atlas:ai:architecture-validate --json` inclui Self-Improvement no ready
  domain count.
- `docs_drift_review` tambem abre proposta `proposal_only` para promocao de
  Graph RAG/Python quando `atlas:ai:local-rag-benchmark` passa e o unico
  bloqueio restante e review humano/Curator.

Validation:

- `php artisan test tests/Unit/Ai/SelfImprovement tests/Feature/Ai/SelfImprovement`
- `php artisan atlas:ai:self-improve --list-flows --json`
- `php artisan atlas:ai:self-improve --schedule-plan --json`
- `GET /ai/self-improvement/schedule`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`
