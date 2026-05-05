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
  - Curator dedicado e um possivel refinamento futuro; hoje a curadoria operacional implementada vive em self_improvement.
maintenance:
  - Atualize este documento quando flows self_improvement, gates, scheduler, surfaces ou evidence sources mudarem.
  - Leia junto de atlas-ai-master-architecture.md e atlas-ai-kernel-architecture.md antes de alterar runtime de auto-melhoria.
related_paths:
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - app/Console/Commands/AtlasAiSelfImproveCommand.php
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

## Flows

- `self_improvement.nightly_review`: revisao recorrente de falhas, drift,
  regressao, envelopes incompletos, SLO drift e propostas pequenas.
- `self_improvement.weekly_architecture_audit`: auditoria semanal de arquitetura,
  docs, duplicacoes, domain health e coverage.
- `self_improvement.capability_gap_scan`: busca lacunas entre capabilities
  declaradas, surfaces, tools e comportamento observado.
- `self_improvement.benchmark_review`: compara resultados contra baselines,
  benchmark corpus e suites de qualidade.
- `self_improvement.memory_quality_review`: avalia recall, redundancia, stale
  memory, provider-safety e learning.
- `self_improvement.tool_runtime_review`: analisa falhas de tool runtime,
  normalizers, recipes, gates, missing evidence e recovery.
- `self_improvement.repair_loop_review`: analisa exclusivamente o Repair Loop:
  `REPAIR_INITIATED`, `REPAIR_COMPLETED`, human review recorrente,
  blocked/exhausted, estrategias repetidas, reasons agregadas e emitter stages.
- `self_improvement.domain_learning_review`: transforma feedback, traces e
  metricas em propostas para profiles, policies, gates e domain docs.
- `self_improvement.docs_drift_review`: detecta drift entre KB canonica, codigo,
  catalogo, migrations e docs historicos.
- `self_improvement.provider_performance_review`: revisa custo, latencia,
  qualidade, fallback, SLO drift e compliance de providers.
- `self_improvement.proposal_generation`: consolida findings em proposals
  provider-safe, auditaveis e revisaveis.

## Sources

Self-Improvement deve consumir fontes auditaveis:

- Evidence Ledger;
- `AtlasLedgerReplayService` (`sloReportForWindow`) para drift SLO por janela,
  com dimensoes `domain`, `flow`, `surface_id`, `provider`, `model`, `runtime`
  e `tool_id`;
- `AtlasLedgerReplayService` (`repairReportForWindow`) para padroes de Repair
  Loop por janela: human review, repairs bloqueados/exhausted, estrategia
  recorrente, reasons agregadas e envelopes recentes. O read model aceita
  filtros por `status`, `strategy`, `failure_domain` e `emitter_stage`, para
  que Curator e operador isolem recortes sem ler payload raw;
- `GET /ai/slo` e `atlas:ai:slo` para inspecao operacional filtrada do mesmo
  read model usado pelo runtime;
- `GET /ai/repair/report` e `atlas:ai:repair-report` para inspecao operacional
  direta do Repair Loop por janela, usando o mesmo read model consumido pelo
  runtime, com filtros por status, estrategia, dominio de falha e stage
  emissor;
- architecture validation;
- domain scorecards;
- KB canonica;
- Code Intelligence;
- tool evidence;
- memory quality signals;
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
- `AtlasSelfImprovementRuntime` executa os 11 flows especializados.
- O runtime consome `AtlasLedgerReplayService::sloReportForWindow()` para
  transformar `SLO_OBSERVED` em findings revisaveis de SLO drift. As dimensoes
  SLO permitem priorizar problemas por dominio, surface, provider e modelo sem
  autoaplicar mudancas de target, provider ou runtime.
- O runtime consome `AtlasLedgerReplayService::repairReportForWindow()` para
  transformar `REPAIR_INITIATED`/`REPAIR_COMPLETED` em findings revisaveis de
  Repair Loop: human review recorrente, repair bloqueado/exhausted e estrategia
  repetida. Esses findings nunca liberam auto-repair; eles criam proposal para
  melhorar contexto, policy, evidencia, playbook ou teste arquitetural.
- `atlas:ai:self-improve --flow=provider_performance_review` aceita filtros
  `--domain`, `--slo-flow`, `--surface`, `--provider`, `--model`, `--runtime`
  e `--tool`. Esses filtros passam pelo plano do orquestrador, chegam ao
  runtime, sao registrados em `runtime.filters` e entram nos metadata dos
  findings, mantendo a curadoria auditavel por recorte operacional.
- `atlas:ai:self-improve --flow=repair_loop_review` e, de forma agregada,
  `--flow=tool_runtime_review` aceitam filtros de
  Repair Loop: `--repair-status`, `--repair-strategy`, `--failure-domain` e
  `--repair-emitter-stage`. Esses filtros chegam a
  `AtlasLedgerReplayService::repairReportForWindow()` e entram em
  `runtime.filters`/`finding.metadata.filters`, mantendo a mesma fronteira
  auditavel usada por `GET /ai/repair/report`.
- `atlas:ai:self-improve --list-flows --json` inspeciona flows sem executar.
- `atlas:ai:self-improve --schedule-plan --json` inspeciona o plano recorrente
  efetivo sem criar `AtlasInitiativeRun` nem executar runtime.
- `atlas:ai:self-improve --schedule-plan --fail-on-schedule-warning --json`
  transforma `health.status` diferente de `healthy` em exit code nao-zero,
  permitindo CI/cron health gate sem executar auto-melhoria.
- `atlas:ai:self-improve --schedule-health --json` expoe o mesmo resumo
  compacto de `GET /ai/self-improvement/schedule/health` para shell, CI e
  cron. Tambem aceita `--fail-on-schedule-warning`.
- `GET /ai/self-improvement/schedule` expoe o mesmo plano recorrente efetivo
  para App, dashboard, mobile ou automacoes autenticadas por `atlas.token`.
- `GET /ai/self-improvement/schedule/health` expoe um resumo compacto do mesmo
  plano (`health`, `flow_count`, `invalid_flow_count`, `defaulted`, `emit`) sem
  comandos ou configuracao completa, ideal para monitoramento leve.
- `GET /ai/observability` inclui `self_improvement_schedule` junto de
  `kernel_slo` e `kernel_repair`, para dashboard operacional enxergar o ciclo
  recorrente sem chamar uma rota separada.
- `atlas:ai:self-improve --flow=... --plan-only --json` renderiza plano,
  gates, runtime e executor sem criar run operacional.
- `atlas:ai:domains --json` reporta `self_improvement` como ready, com o flow
  dedicado `self_improvement.repair_loop_review` no catalogo.
- `AtlasSelfImprovementScheduleService` normaliza o agendamento recorrente por
  `ATLAS_AI_SELF_IMPROVEMENT_FLOWS`. O default seguro executa
  `nightly_review,repair_loop_review`, deduplica flows, reporta
  `configured_flows`, `invalid_flows`, `defaulted`, `timezone`, `next_run_at`
  e `health`, e preserva `emit=false` salvo configuracao explicita. O plano
  tambem publica `plan_hash` com `plan_hash_algorithm=sha256`, calculado sobre
  a configuracao efetiva e health issues, mas sem depender de `next_run_at`,
  para detectar drift entre CLI/API/observability/cron. Valores desconhecidos
  nunca disparam execucao silenciosa: eles aparecem no plano para CLI, API e
  observability com `health.status=warning`. Horario invalido
  (`atlas_ai.self_improvement.time` fora de `HH:MM`) tambem vira warning e
  deixa `next_run_at=null`. Timezone invalida (`app.timezone` fora da lista
  IANA) vira `invalid_self_improvement_schedule_timezone`, tambem com
  `next_run_at=null` e `schedulable=false`. Quando o ciclo recorrente esta
  desligado, `health.status=disabled` aparece explicitamente.
- O scheduler real em `bootstrap/app.php` consome
  `AtlasSelfImprovementScheduleService::scheduledCommands()`, aplica
  `dailyAt(time)` e `timezone(timezone)` do mesmo contrato, e carrega
  `plan_hash` em cada item agendavel para diagnostico de drift.
  `scheduledCommands()` so retorna itens quando `schedulable=true`; schedule
  desligado, horario invalido ou timezone invalida continua visivel no
  plano/health, mas nao vira registro real no cron. O bloco
  `scheduler_registration` reporta
  `registered_command_count` e `skipped_reason`, deixando claro se o cron
  receberia comandos ou se o plano foi apenas mantido como diagnostico.
- `atlas:ai:architecture-validate --json` inclui Self-Improvement no ready
  domain count.

Validation:

- `php artisan test tests/Unit/Ai/SelfImprovement tests/Feature/Ai/SelfImprovement`
- `php artisan atlas:ai:self-improve --list-flows --json`
- `php artisan atlas:ai:self-improve --schedule-plan --json`
- `GET /ai/self-improvement/schedule`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`
