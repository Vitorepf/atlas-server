---
id: atlas-kernel-mission-foundation
type: engineering_knowledge
title: Atlas Kernel Mission Foundation
status: active
category: atlas-ai
priority: 100
summary: Fundacao universal Mission / Objective / WorkOrder do Atlas AI multi-dominio. Persistencia, lifecycle guard, evidence refs, certification e control-plane antes de qualquer dominio especifico.
tags:
  - atlas-ai
  - kernel
  - mission
  - objective
  - work_order
  - certification
  - evidence
capabilities:
  - mission_record
  - objective_decomposition
  - work_order_planning
  - mission_lifecycle_guard
  - evidence_attachment
  - mission_certification
  - control_plane_snapshot
  - mission_readiness_audit
decisions:
  - Toda mission nao trivial vira registro persistente com objectives, work_orders, evidence refs e certification.
  - Status completed exige evidence ref e certification passed; lifecycle guard rejeita atalho.
  - Cada work_order carrega receipt_hash deterministico; cada certification gera certification_hash.
  - Foundation NAO implementa Policy/Budget completo nem Tool Runtime nem Domain Company Runtimes; e a base que todos consomem.
maintenance:
  - Atualize este doc antes de mudar contratos Mission / Objective / WorkOrder.
  - Mantenha enums (status, mission_type, autonomy_level, risk_level, evidence_type) sincronizados com codigo e canon.
  - Adicione novas regras de certification no MissionCertificationService E nos testes simultaneamente.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
  - docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-kernel-mission-foundation
graph_title: Atlas Kernel Mission Foundation
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - database/migrations/2026_05_17_900000_create_ai_mission_foundation_tables.php
  - app/Models/AiMission.php
  - app/Models/AiObjective.php
  - app/Models/AiWorkOrder.php
  - app/Models/AiMissionEvent.php
  - app/Models/AiMissionEvidenceRef.php
  - app/Models/AiMissionCertification.php
  - app/Services/Ai/Mission/MissionFactoryService.php
  - app/Services/Ai/Mission/ObjectiveDecomposerService.php
  - app/Services/Ai/Mission/WorkOrderFactoryService.php
  - app/Services/Ai/Mission/MissionLifecycleService.php
  - app/Services/Ai/Mission/MissionEvidenceService.php
  - app/Services/Ai/Mission/MissionCertificationService.php
  - app/Services/Ai/Mission/MissionControlPlaneService.php
  - app/Services/Ai/Mission/MissionReadinessService.php
  - app/Services/Ai/Mission/MissionLifecycleException.php
  - app/Services/Ai/Mission/MissionCanonicalHash.php
  - app/Console/Commands/AtlasAiMissionFoundationCommand.php
  - tests/Concerns/CreatesMissionFoundationTables.php
  - tests/Feature/Ai/Mission/MissionFoundationReadinessTest.php
  - tests/Feature/Ai/Mission/MissionFoundationSmokeTest.php
  - tests/Feature/Ai/Mission/MissionFoundationLifecycleGuardTest.php
  - tests/Feature/Ai/Mission/MissionFoundationObjectiveDecomposerTest.php
  - tests/Feature/Ai/Mission/MissionFoundationWorkOrderFactoryTest.php
  - tests/Feature/Ai/Mission/MissionFoundationEvidenceServiceTest.php
  - tests/Feature/Ai/Mission/MissionFoundationCertificationServiceTest.php
  - tests/Feature/Ai/Mission/MissionFoundationControlPlaneServiceTest.php
allowed_changes:
  - Adicionar campos opcionais em definition_of_done desde que mantenha version atlas.ai.mission.definition_of_done.v1.
  - Adicionar novos evidence_type apenas via constante MissionEvidenceService::ALLOWED_TYPES + teste.
  - Estender control plane snapshot sem remover chaves existentes.
  - Adicionar checks novos em MissionCertificationService desde que cubra com teste.
forbidden_changes:
  - Permitir completed sem evidence ref ou sem certification passed.
  - Remover eventos auditaveis (mission.created, objective.created, work_order.created, evidence.attached, certification.recorded, mission.transition).
  - Persistir mission sem definition_of_done.
  - Acoplar foundation a um domain runtime especifico (Software, Cyber, etc.).
depends_on:
  - atlas-autonomous-intelligence-operating-system
  - atlas-ai-kernel-architecture
  - atlas-mission-mode
  - atlas-objective-intelligence
  - atlas-evidence-truth-layer
  - atlas-autonomous-control-plane
flows_to:
  - atlas-domain-company-runtimes
unlocks:
  - atlas-domain-company-runtimes
  - atlas-mission-mode
governs:
  - mission_persistence
  - mission_lifecycle
  - mission_certification_gate
  - mission_control_plane_snapshot
evidence:
  - tests/Feature/Ai/Mission/MissionFoundationSmokeTest.php
  - tests/Feature/Ai/Mission/MissionFoundationLifecycleGuardTest.php
  - tests/Feature/Ai/Mission/MissionFoundationCertificationServiceTest.php
required_tests:
  - "/opt/homebrew/bin/php artisan test --filter=MissionFoundation"
  - "/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=smoke --json"
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Meta 2: ligar mission classifier ao Router/Intent layer para criar mission automaticamente em prompts nao triviais.
  - Meta 2: integrar Policy/Budget como gate antes de transition para running.
  - Meta 2: ligar Tool Runtime para anexar evidence pack automatico.
  - Meta 2: primeiro Domain Company Runtime (Software) consumir Mission via WorkOrder.
---

# Atlas Kernel Mission Foundation

## Resumo

Atlas AI e o Autonomous Intelligence Operating System multi-dominio do Atlas. Antes de qualquer dominio especifico (Software, Cyber, Finance, Marketing, Research, Personal Development, Operations, Automation, Strategy) operar, existe uma fundacao universal de **Mission / Objective / WorkOrder** com lifecycle guard, evidence refs e certification.

Esta foundation entrega Meta 1 do Atlas AI: persistencia, models, services, lifecycle, evidence, certification, control-plane snapshot e readiness audit. Nenhum dominio nasce sem essa base.

## Papel no Atlas

A foundation expoe contratos canonicos para qualquer surface (chat, CLI, MCP, IDE) ou dominio:

- transformar prompt bruto em **Mission** classificada (trivial / task / mission / obra).
- decompor mission em **Objectives** com `success_criteria` mensuraveis.
- planejar **WorkOrders** com `instructions`, `expected_artifacts`, `expected_tests`, `risk_notes`, `rollback_plan` e `receipt_hash`.
- guardar todas as transicoes em `ai_mission_events` com `status_before` e `status_after`.
- anexar **Evidence Refs** tipadas (`doc`, `command`, `test`, `artifact`, `receipt`, `source`, `screenshot`, `diff`, `blocker`, `certification`).
- emitir **Certification** com `certification_hash`, requisitos checados e ausentes.
- expor **Control Plane Snapshot** que qualquer IA consegue ler para responder "o que esta acontecendo".
- expor **Readiness Audit** que prova que a base esta viva.

O Kernel impede mission ir para `completed` sem evidencia + certification `passed`. Esse e o guard que torna o Atlas AI auditavel.

## Onde Se Encaixa

```
Atlas AI Surface
 -> Mission Mode (classificacao trivial/task/mission/obra)
 -> Objective Intelligence (metricas, DoD, restricoes)
 -> WorkOrder Planning
 -> Atlas Kernel Mission Foundation [ESTE DOC]
     -> Policy / Permission / Budget   (futuro Meta 2)
     -> Tool Runtime                   (futuro Meta 2)
     -> Evidence Ledger                (consumido aqui via evidence refs)
     -> Memory / Knowledge             (futuro Meta 2)
     -> Control Plane                  (snapshot exposto aqui)
     -> Certification                  (recorded aqui, gate em transition)
 -> Domain Company Runtimes
     -> Software / Cyber / Finance / Marketing / Strategy / Research / etc.
```

Foundation e o ponto de encontro: Mission Mode classifica, Objective Intelligence detalha, e a Foundation persiste, audita e certifica. Domain Runtimes consomem WorkOrder + DoD + Evidence pack.

## Contratos

Contratos versionados (`schema_version` em cada tabela):

- `atlas.ai.mission.v1` — registro principal: prompt, classificacao, status, autonomy, risk, DoD.
- `atlas.ai.objective.v1` — sub-meta com `success_criteria`, `constraints`, `assumptions`.
- `atlas.ai.work_order.v1` — unidade executavel com `expected_artifacts/tests`, `risk_notes`, `rollback_plan`, `receipt_hash`.
- `atlas.ai.mission_event.v1` — evento auditavel append-only com `status_before/after` e `payload`.
- `atlas.ai.evidence_ref.v1` — referencia tipada com `evidence_hash`.
- `atlas.ai.certification.v1` — receipt de auditoria com `checked_requirements`, `missing_requirements`, `certification_hash`.
- `atlas.ai.mission.control_plane.v1` — snapshot JSON read-only agregando estado completo.
- `atlas.ai.mission.readiness.v1` — relatorio binario `ok=true/false` + lista de checks.
- `atlas.ai.mission.definition_of_done.v1` — estrutura DoD com `criteria[]`, `primary_metric`, `constraints`, `assumptions`.

Enums canonicos:

- `mission_type`: `trivial`, `task`, `mission`, `obra`.
- `mission status`: `draft`, `planned`, `running`, `waiting_approval`, `blocked`, `repairing`, `certifying`, `completed`, `failed`, `cancelled`.
- `autonomy_level`: `suggest`, `draft`, `execute_with_approval`, `autonomous`.
- `risk_level`: `low`, `medium`, `high`, `critical`.
- `evidence_type`: `doc`, `command`, `test`, `artifact`, `receipt`, `source`, `screenshot`, `diff`, `blocker`, `certification`.
- `certification status`: `pending`, `passed`, `failed`, `blocked`.

## Fluxo

Lifecycle canonico (transicoes permitidas em `MissionLifecycleService::ALLOWED_TRANSITIONS`):

```
draft -> planned -> running ⇄ waiting_approval
                     |          |
                     v          v
                  blocked -> repairing -> running
                     |                       |
                     v                       v
                  failed                 certifying -> completed
                                                \
                                                 failed
   (qualquer nao terminal) -> cancelled
```

Guards obrigatorios:

- `-> running`: from in {planned, waiting_approval, repairing}.
- `-> completed`: from = certifying **e** `evidenceRefs().count() >= 1` **e** `latestCertification.status === passed`.

Fluxo end-to-end (validado por `MissionFoundationSmokeTest`):

1. `MissionFactoryService::create(prompt)` -> mission `draft` + evento `mission.created`.
2. `ObjectiveDecomposerService::decompose(mission)` -> N objectives + eventos `objective.created`.
3. `MissionLifecycleService::transition(mission, planned)`.
4. `WorkOrderFactoryService::plan(mission)` -> N work orders + eventos `work_order.created`, cada um com `receipt_hash`.
5. `MissionLifecycleService::transition(mission, running)`.
6. `MissionEvidenceService::attach(mission, [...])` -> 1+ evidence refs + eventos `evidence.attached`.
7. `MissionLifecycleService::transition(mission, certifying)`.
8. `MissionCertificationService::certify(mission)` -> certification + evento `certification.recorded`; preenche `mission.certification_hash` e `mission.evidence_pack_hash`.
9. Se certification `passed`: `MissionLifecycleService::transition(mission, completed)` -> `completed_at` setado.
10. `MissionControlPlaneService::snapshot(mission)` -> JSON canonico consumivel por qualquer surface/IA.

## Regras para IA

- Nunca declare mission `completed` sem chamar `MissionCertificationService::certify`. O lifecycle guard rejeita.
- Nunca pule `MissionEvidenceService::attach` antes da certification: certification falha sem evidence.
- Nunca crie evidence_type fora de `MissionEvidenceService::ALLOWED_TYPES`. Adicionar tipo novo exige editar constante + teste.
- Nunca grave `mission_type` arbitrario: respeite o enum `trivial/task/mission/obra`.
- Sempre emita eventos via `MissionLifecycleService::recordEvent` para manter `payload` e `receipt_hash` consistentes.
- Para responder "qual o status da mission X" para o operador, leia `MissionControlPlaneService::snapshot(mission)` em vez de inspecionar tabelas direto.
- Para responder "a foundation esta viva?" leia `MissionReadinessService::report()` ou rode `atlas:ai:mission-foundation --action=readiness --json`.

## Escopo de Implementacao

Em escopo (Meta 1):

- 6 tabelas, 6 models, 8 services + 1 exception + 1 hash helper.
- comando `atlas:ai:mission-foundation` com 8 actions: `readiness`, `create`, `decompose`, `plan`, `evidence`, `certify`, `control-plane`, `smoke`.
- 8 feature tests + trait de schema in-memory (19 tests / 101 assertions verdes).
- doc canonica (este arquivo) + links curtos nos indices canonicos.

Fora de escopo (Meta 2+):

- Policy / Budget engine completo (a foundation expoe pontos de gate, nao implementa policy ainda).
- Tool Runtime completo (foundation registra evidence mas nao executa tools).
- Domain Company Runtimes (Software, Cyber, Finance, ...).
- Forge multi-mission / orchestration.
- UX (web/CLI premium) alem do output JSON do comando.
- Auto-classification via LLM (Meta 1 usa heuristica deterministica simples).

## Dependencias

- Laravel 13 + PHP 8.4.
- `Illuminate\Database\Eloquent\Concerns\HasUuids` para UUID primary keys.
- `MissionCanonicalHash` (sha256 + canonical JSON com sort recursivo) para `receipt_hash`, `certification_hash`, `evidence_hash` deterministicos.
- Nenhuma dependencia externa nova (sem package novo no composer).
- Sem ServiceProvider novo: container resolve services via autowiring.

Docs canonicos pais (consumidos como contrato):

- `atlas-autonomous-intelligence-operating-system.md`
- `atlas-ai-kernel-architecture.md`
- `atlas-mission-mode.md`
- `atlas-objective-intelligence.md`
- `atlas-evidence-truth-layer.md`
- `atlas-autonomous-control-plane.md`

## Evidencias

Como provar Meta 1 esta entregue:

1. `/opt/homebrew/bin/php artisan test --filter=MissionFoundation` -> 19 testes, 101 assertions verdes.
2. `/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=readiness --json` -> `ok:true`, todos os checks `passed`.
3. `/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=smoke --json` -> mission termina `completed`, certification `passed`, eventos auditaveis presentes.
4. `/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json` -> sem violacao em `atlas-kernel-mission-foundation.md`.
5. Inspecao manual do snapshot via `--action=control-plane --mission=<uuid>` revela `schema:atlas.ai.mission.control_plane.v1` + `readiness:{has_objectives,has_work_orders,has_evidence,has_passed_certification}`.

## Riscos

- **Heuristica de classificacao** (`MissionFactoryService::classify`) e simples; sera substituida em Meta 2 por classifier ligado ao Router/Intent. Para Meta 1, operador pode forcar via `--mission-type`.
- **Idempotencia da decomposicao** garante uma decomposicao por mission; se DoD mudar, precisa criar nova mission ou estender `ObjectiveDecomposerService` (decisao Meta 2).
- **Sem soft delete**: missions canceladas ficam como `cancelled`, eventos sao append-only. Compactacao e politica de retencao sao trabalho Meta 2.
- **Sem ligacao com Tool Runtime**: foundation aceita evidence ref como string, mas nao valida runtime real. Ligar com Tool Runtime e tarefa Meta 2.

Mitigacoes:

- Lifecycle guard com excecao tipada (`MissionLifecycleException`) evita falso completed.
- Tests cobrem cada guard e cada falha de certification (`missing_requirements` real).
- `MissionReadinessService` falha alto e cedo se algum servico parar de resolver.

## Exemplos

Criar e levar uma mission ate `completed`:

```bash
cd atlas-server
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=readiness --json

/opt/homebrew/bin/php artisan atlas:ai:mission-foundation \
    --action=create \
    --prompt="Implementar exporter CSV no painel admin com testes de regressao" \
    --json
# anote o mission uuid

/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=decompose --mission=<uuid> --json
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=plan --mission=<uuid> --json
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation \
    --action=evidence --mission=<uuid> --type=test --ref="phpunit:passed" --json
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=certify --mission=<uuid> --json
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=control-plane --mission=<uuid> --json
```

Rodar o end-to-end completo (mesmo fluxo do smoke test):

```bash
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=smoke --json
```

Forcar uma falha intencional para inspecionar `missing_requirements`:

```bash
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=create --prompt="trivial: ping" --json
/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=certify --mission=<uuid> --json
# -> status:failed, missing_requirements: objectives_exist, work_orders_exist, evidence_refs_exist, work_orders_have_receipt_hash
```

## Proximas Acoes

- Meta 2: ligar `MissionFactoryService::classify` ao Atlas Router/Intent para criar mission automaticamente em todo prompt nao trivial.
- Meta 2: introduzir Policy/Budget gate antes de `STATUS_RUNNING` (autonomy_level + risk_level decidindo gates).
- Meta 2: integrar Tool Runtime para anexar evidence_ref reais (logs, diffs, command receipts) automaticamente.
- Meta 2: primeiro Domain Company Runtime (Software) consumir WorkOrder via interface canonical.
- Meta 2: REST endpoint `/atlas-ai/mission-foundation/*` para surfaces externas.
- Meta 2: extender `MissionEvidenceService` para resolver evidence packs imutaveis com hash de conteudo (Evidence Ledger).
