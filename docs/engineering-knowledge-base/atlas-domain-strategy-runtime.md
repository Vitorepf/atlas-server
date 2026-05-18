---
id: atlas-domain-strategy-runtime
type: engineering_knowledge
title: Atlas Corporate Strategy / Venture Studio Runtime
status: active
category: atlas-ai
priority: 100
summary: Runtime canônico do dominio Corporate Strategy / Venture Studio. Implementa o ciclo Opportunity -> Venture Blueprint -> Market Model -> Unit Economics -> GTM -> Experiment Plan -> Strategy Memo, com integracao opcional ao Evidence Runtime (Meta 4) para certification e claim auditavel. Meta 8B backend entregue 2026-05-18.
tags:
  - atlas-ai
  - strategy
  - venture-studio
  - domain-runtime
  - experimentation
  - evidence
capabilities:
  - opportunity_radar
  - venture_blueprint
  - market_model
  - unit_economics
  - gtm_plan
  - experiment_plan
  - strategy_memo
  - strategy_evidence_integration
decisions:
  - Strategy / Venture Studio e dominio plugavel sob Domain Runtime Contract (Meta 2). Nao reescreve Mission Foundation, Domain Runtime, Policy ou Evidence Runtime.
  - Toda estrategia vira hipotese -> experimento -> decisao -> memo. Sem experimento decidido nao ha memo decided.
  - Oportunidade exige problem, ICP, pain, urgency, market, competitors e risks. Venture blueprint exige product, GTM, unit economics, hiring plan e operations.
  - Decisao de experimento e enum fechado: continue | pivot | scale | stop. Resultado pode ser inconclusivo, mas resultado inconclusivo NUNCA vira `decided`.
  - Integracao com Evidence Runtime e opcional e tolerante: se Meta 4 nao estiver presente, runtime ainda persiste artifacts e marca evidence.attached=false.
maintenance:
  - Atualize este doc antes de adicionar novo tipo de hypothesis_kind, decision kind ou memo kind.
  - Mantenha required-fields guards (OpportunityRadarService, VentureBlueprintService, ExperimentPlanService, StrategyMemoService) sincronizados com testes e StrategyReadinessService.
  - Nunca permitir memo `decided` sem rationale + next_actions; nunca permitir certificacao passada sem evidence_pack nao vazio.
related_paths:
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-experimentation-engine.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - database/migrations/2026_05_18_040000_create_ai_strategy_runtime_tables.php
  - app/Models/AiStrategyRun.php
  - app/Models/AiOpportunity.php
  - app/Models/AiVentureBlueprint.php
  - app/Models/AiMarketModel.php
  - app/Models/AiUnitEconomics.php
  - app/Models/AiExperimentPlan.php
  - app/Models/AiStrategyMemo.php
  - app/Services/Ai/Strategy/StrategyCanonicalHash.php
  - app/Services/Ai/Strategy/StrategyDomainException.php
  - app/Services/Ai/Strategy/StrategyDomainManifestSeeder.php
  - app/Services/Ai/Strategy/OpportunityRadarService.php
  - app/Services/Ai/Strategy/VentureBlueprintService.php
  - app/Services/Ai/Strategy/MarketModelService.php
  - app/Services/Ai/Strategy/UnitEconomicsService.php
  - app/Services/Ai/Strategy/GTMPlanService.php
  - app/Services/Ai/Strategy/ExperimentPlanService.php
  - app/Services/Ai/Strategy/StrategyMemoService.php
  - app/Services/Ai/Strategy/StrategyRuntimeService.php
  - app/Services/Ai/Strategy/StrategyReadinessService.php
  - app/Services/Ai/Strategy/StrategyControlPlaneProjection.php
  - app/Console/Commands/AtlasAiStrategyDomainCommand.php
  - tests/Concerns/CreatesStrategyRuntimeTables.php
  - tests/Feature/Ai/Strategy/StrategyDomainReadinessTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainSmokeTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainOpportunityTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainVentureBlueprintTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainExperimentPlanTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainMemoTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainControlPlaneTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainEvidenceIntegrationTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-domain-strategy-runtime
graph_title: Atlas Corporate Strategy / Venture Studio Runtime
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-domain-company-runtimes
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-domain-strategy-runtime.md
allowed_changes:
  - Adicionar novos hypothesis_kind, decision kind ou memo kind com testes sincronizados.
  - Estender StrategyControlPlaneProjection com novos read models sem remover chaves existentes.
forbidden_changes:
  - Permitir oportunidade sem market, competitors e risks.
  - Permitir venture blueprint sem product, GTM, unit economics, hiring plan e operations.
  - Permitir memo decided sem rationale e next_actions.
  - Certificar delivery sem evidence pack ou sem experimento decidido + memo decidido.
  - Acoplar Strategy Runtime a Atlas Strategic Decision (review-only) ou substitui-lo.
depends_on:
  - atlas-domain-runtime-contract
  - atlas-autonomous-intelligence-operating-system
  - atlas-experimentation-engine
flows_to:
  - atlas-evidence-certification-runtime
  - atlas-domain-company-runtimes
unlocks:
  - opportunity-to-decision-loop
  - strategy-claims-with-evidence
governs:
  - atlas_ai.strategy.opportunity
  - atlas_ai.strategy.venture_blueprint
  - atlas_ai.strategy.experiment_plan
  - atlas_ai.strategy.strategy_memo
evidence:
  - tests/Feature/Ai/Strategy/StrategyDomainSmokeTest.php
  - tests/Feature/Ai/Strategy/StrategyDomainEvidenceIntegrationTest.php
required_tests:
  - "/opt/homebrew/bin/php artisan test --filter=StrategyDomain"
  - "/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=smoke --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=control-plane --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Estado Atual, Fluxo Canonico, Contratos, Regras para IA e Definition of Done antes de implementar.
ai_usage_notes:
  - Use repo_paths e required_tests como limites operacionais.
  - Nao crie domain runtime paralelo; Strategy plugga em Domain Runtime Contract (Meta 2).
quality_gates:
  - opportunity-complete
  - venture-blueprint-complete
  - experiment-planned
  - experiment-decided
  - memo-decided
  - evidence-attached
failure_modes:
  - Estrategia que nunca vira hipotese.
  - Hipotese que nunca vira experimento.
  - Experimento sem decisao.
  - Memo sem next actions.
  - Certificacao automatica sem evidencia.
observability_signals:
  - strategy_run_id
  - opportunity_id
  - experiment_id
  - memo_kind
  - certification_status
next_actions:
  - Coordenar com Meta 2 (Domain Runtime) para registrar manifest do dominio strategy via StrategyDomainManifestSeeder.
  - Coordenar com Meta 3 (Policy) para policy_profile 'strategy.default' antes de actions de spend/publish.
  - Coordenar com Meta 9 (Control Plane) para projetar StrategyControlPlaneProjection no agregador global.
line_limit: 520
---
# Atlas Corporate Strategy / Venture Studio Runtime

## Estado Atual

Meta 8B backend entregue 2026-05-18 como runtime plugavel sob Domain Runtime
Contract (Meta 2). Implementa o ciclo canonico:

```text
Opportunity (problem/ICP/pain/market/competitors/risks)
  -> Venture Blueprint (product/GTM/unit economics/hiring/ops)
  -> Market Model + Unit Economics
  -> Experiment Plan (hypothesis/metric/design)
  -> Experiment Result + Decision (continue|pivot|scale|stop)
  -> Strategy Memo (decision + rationale + next actions)
  -> Evidence Pack + Certification (via Meta 4)
```

- **7 tabelas**: `ai_strategy_runs`, `ai_opportunities`, `ai_venture_blueprints`,
  `ai_market_models`, `ai_unit_economics`, `ai_experiment_plans`,
  `ai_strategy_memos`.
- **7 models** equivalentes em `app/Models/AiStrategy*` + `AiOpportunity`,
  `AiVentureBlueprint`, `AiMarketModel`, `AiUnitEconomics`,
  `AiExperimentPlan`, `AiStrategyMemo`.
- **12 services** em `app/Services/Ai/Strategy/`: `StrategyCanonicalHash`
  (helper), `StrategyDomainException`, `StrategyDomainManifestSeeder` (Meta 2
  manifest registrar, idempotente), `OpportunityRadarService`,
  `VentureBlueprintService`, `MarketModelService`, `UnitEconomicsService`,
  `GTMPlanService`, `ExperimentPlanService`, `StrategyMemoService`,
  `StrategyRuntimeService` (orquestrador chain + Evidence adapter),
  `StrategyReadinessService`, `StrategyControlPlaneProjection`.
- **Comando** `atlas:ai:strategy-domain` com actions
  `readiness | smoke | control-plane | seed-manifest`.
- **8 feature tests** em `tests/Feature/Ai/Strategy/` (24 tests / 57 assertions
  verdes) cobrindo readiness, smoke end-to-end, opportunity required fields,
  venture blueprint required fields, experiment hypothesis/metric/decision,
  memo required fields, control-plane projection, evidence integration
  (Meta 4 presente + Meta 4 ausente / tolerancia).

### Comandos canonicos

```bash
/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=readiness --json
/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=smoke --json
/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=control-plane --json
/opt/homebrew/bin/php artisan atlas:ai:strategy-domain --action=seed-manifest --json
```

## Resumo

Corporate Strategy / Venture Studio Runtime transforma intencao estrategica
em decisao auditavel. Ele NAO substitui o dominio `strategic_decision`
(review-only co-estrategista). Este runtime e gerativo: produz oportunidades,
blueprints, modelos de mercado, unit economics, planos de GTM, experimentos e
memos de decisao com evidencia.

## Papel no Atlas

Plugga sob Domain Runtime Contract (Meta 2). Consome Mission Foundation
(Meta 1) para mission/objective context, Policy (Meta 3) para autonomia e
budget, e Evidence Runtime (Meta 4) para receipts/claims/certifications.
Expoe seu proprio control-plane que e agregado pelo Atlas Control Plane
(Meta 9) quando este o consultar.

## Onde Se Encaixa

```text
Mission Foundation (Meta 1)
 -> Domain Runtime Contract (Meta 2)
    -> Strategy Domain Manifest (`strategy`)
       -> StrategyRuntimeService.driveOpportunityToDecision()
          -> OpportunityRadarService
          -> VentureBlueprintService + MarketModelService + UnitEconomicsService + GTMPlanService
          -> ExperimentPlanService.create() + .recordResult()
          -> StrategyMemoService
          -> Evidence Runtime adapter (Meta 4)
             -> EvidencePackService
             -> ClaimVerificationService
             -> CertificationRuntimeService
       -> StrategyControlPlaneProjection
```

## Contratos

- `atlas.ai.strategy.run.v1`
- `atlas.ai.strategy.opportunity.v1`
- `atlas.ai.strategy.venture_blueprint.v1`
- `atlas.ai.strategy.market_model.v1`
- `atlas.ai.strategy.unit_economics.v1`
- `atlas.ai.strategy.experiment_plan.v1`
- `atlas.ai.strategy.memo.v1`
- `atlas.ai.strategy.control_plane.v1`
- `atlas.ai.strategy.readiness.v1`

Hash determinstico via `StrategyCanonicalHash::sha256(...)` em cada artifact
(`opportunity_hash`, `blueprint_hash`, `model_hash`, `unit_economics_hash`,
`experiment_hash`, `memo_hash`, `receipt_hash`).

## Fluxo

1. `StrategyRuntimeService::startRun()` cria `ai_strategy_runs` record.
2. `OpportunityRadarService::create()` valida problem/ICP/pain/market/
   competitors/risks e persiste com `opportunity_hash`.
3. `VentureBlueprintService::create()` valida product/GTM/unit economics/
   hiring/operations e persiste.
4. `MarketModelService::create()` opcional: TAM/SAM/SOM com asserts
   `SAM<=TAM`, `SOM<=SAM`.
5. `UnitEconomicsService::create()` opcional: CAC/LTV/ARPU/gross margin/
   payback/churn.
6. `ExperimentPlanService::create()` exige hypothesis + success_metric
   (`name`+`target`) + design.
7. `ExperimentPlanService::recordResult($plan, $result, $decision)`
   transiciona para `decided` (ou `inconclusive` quando `outcome=inconclusive`)
   com decision enum `continue|pivot|scale|stop`.
8. `StrategyMemoService::create()` exige decision + rationale + next_actions;
   status default `draft`, pode ser passado como `decided`.
9. `StrategyRuntimeService::attachEvidence()` monta `EvidencePack` (artifact
   refs do opportunity + blueprint, test refs do experiment, receipt refs do
   `domain_step`) e roda `CertificationRuntimeService::certify(...)` com
   required_requirements `[opportunity_created, venture_blueprint_created,
   experiment_planned, experiment_decided, strategy_memo_decided]`.
10. `StrategyRuntimeService::closeRun()` marca run como `decided` com
    `completed_at` setado e outputs com IDs de cada artifact.

## Regras para IA

- Nunca declare uma estrategia pronta sem `strategy_memo.status=decided`.
- Nunca crie `strategy_memo.decided` sem experimento `decided` ou `inconclusive`
  com decision_kind explicito.
- Nunca crie oportunidade com `market=[]`, `competitors=[]` ou `risks=[]`. Sao
  campos obrigatorios para impedir oportunidade-vaporware.
- Nao replique Strategic Decision domain (review-only): este runtime e gerativo
  e produz artifacts auditaveis.
- Nao publique GTM, gasto de midia ou trade real a partir deste runtime. Esses
  side-effects pertencem a Marketing, Finance e demais runtimes.

## Escopo de Implementacao

Em escopo (Meta 8B):
- 7 tabelas + 7 models + 12 services + comando + 8 feature tests.
- Integracao opcional com Evidence Runtime (tolerante: se Meta 4 ausente,
  evidence.attached=false).
- Manifest seeder para registrar `domain_id=strategy` em
  `ai_domain_manifests` (Meta 2) idempotentemente.

Fora de escopo:
- Execucao real de gasto/publish (Marketing/Finance).
- Strategic Decision review (`domains/strategic-decision.md`).
- Atlas Decide/Router selecionando este runtime automaticamente (Meta 9
  aggregator nao escolhe; orquestra).

## Dependencias

- Domain Runtime Contract (Meta 2).
- Evidence Runtime (Meta 4) - opcional, integracao tolerante.
- Experimentation Engine (`atlas-experimentation-engine.md`).
- Laravel 13 + PHP 8.4 + HasUuids.

## Evidencias

- `tests/Feature/Ai/Strategy/StrategyDomainSmokeTest.php`: smoke end-to-end
  com run.status=decided, memo.status=decided e experiment.status=decided.
- `tests/Feature/Ai/Strategy/StrategyDomainEvidenceIntegrationTest.php`:
  certification=passed quando Meta 4 ativa, attached=false quando Meta 4
  ausente.
- Comando `atlas:ai:strategy-domain --action=smoke --json` retorna
  `ok=true, certification_status=passed`.

## Riscos

- Falsos positivos: declarar `scale` sem amostra suficiente. Mitigacao: enum
  fechado de decisao + resultado inconclusivo distinto de decided.
- Memo sem proxima acao: bloqueado pelo guard required `next_actions`.
- Acoplamento a Marketing/Finance: este runtime emite plano, nao executa
  side-effects.

## Exemplos

```bash
php artisan atlas:ai:strategy-domain --action=smoke --json
# -> {"ok": true, "run_status": "decided", "memo_status": "decided",
#     "experiment_status": "decided", "evidence":{"attached":true,
#     "certification_status":"passed"}}
```

## Proximas Acoes

1. Registrar manifest do dominio via `atlas:ai:strategy-domain --action=seed-manifest`
   apos Meta 2 estabilizar.
2. Conectar `StrategyControlPlaneProjection` ao agregador global do Atlas
   Control Plane (Meta 9) como source `strategy`.
3. Coordenar com Meta 3 (Policy) para `policy_profile=strategy.default` antes
   de transitar para acoes com side-effect.

## Definition of Done

Atendido quando:
- toda estrategia segue Opportunity -> VentureBlueprint -> ExperimentPlan ->
  Decision -> StrategyMemo;
- experimento inconclusivo nao vira memo decided;
- memo decided sempre tem rationale e next_actions;
- certification passa apenas com evidence_pack nao vazio + experiment_decided
  + memo_decided;
- `php artisan test --filter=StrategyDomain` verde;
- `atlas:ai:strategy-domain readiness/smoke/control-plane` retornam JSON ok.
