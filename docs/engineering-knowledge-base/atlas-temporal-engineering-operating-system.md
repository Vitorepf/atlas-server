---
id: atlas-temporal-engineering-operating-system
type: engineering_knowledge
title: Atlas Temporal Engineering Operating System
status: planned
category: programming
priority: 98
implementation_state: planned_north_star_not_current_runtime
summary: North-star canônica do Atlas Temporal Engineering Operating System (TEOS). Eleva Atlas Dev e Atlas Forge de IA com memória longa para sistema temporal de engenharia: tempo, verdade, validade, replay, recovery, evidence e continuity certification. Este índice preserva a tese e aponta para specs filhas detalhadas; benchmark_not_run.
tags:
  - atlas
  - atlas-dev
  - atlas-forge
  - temporal
  - long-horizon
  - continuity
  - replay
  - certification
  - north-star
capabilities:
  - temporal_truth_layer_contract
  - event_sourced_engineering_timeline
  - long_horizon_continuation_certification
  - superior_compaction_with_loss_accounting
  - freshness_and_drift_gate_design
  - provider_independent_replay
  - safe_resume_recovery_planner
  - causal_decision_graph
  - time_aware_world_model
  - strategic_forgetting_policy
  - operator_attention_queue
decisions:
  - TEOS é north-star: descreve estado-alvo, não estado runtime atual.
  - TEOS amplia LHIL com validade temporal, event timeline, replay e certification.
  - Atlas Dev e Atlas Forge permanecem núcleos paralelos; TEOS preserva a boundary dual-core.
  - Nenhuma execução longa continua só por resumo textual; exige Continuation Pack + Continuity Certification.
  - benchmark_not_run: TEOS prepara continuidade; benchmark externo é posterior e exige autorização.
maintenance:
  - Manter este índice abaixo do limite canônico; detalhes vivem nas specs filhas.
  - Promover componentes GREENFIELD/PARTIAL/WIRED somente com evidence verificável.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-temporal-engineering-operating-system
graph_title: Atlas Temporal Engineering Operating System
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-programming-superiority-architecture
graph_status: planned
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
allowed_changes:
  - Atualizar links, estados de componentes e ponte para increments TEOS.
forbidden_changes:
  - Apagar a distinção entre north-star e runtime atual.
  - Declarar TEOS implementado sem evidence verificável.
  - Inserir claim numérico de superioridade sem benchmark auditado.
  - Fundir Atlas Dev e Atlas Forge num runtime único.
depends_on:
  - atlas-long-horizon-intelligence-layer
  - atlas-programming-superiority-architecture
  - atlas-dual-core-engineering-system
  - atlas-evidence-certification-runtime
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-teos-increment-1-plan
  - atlas-pre-benchmark-readiness-audit
unlocks:
  - dev_multi_week_continuity_certified
  - forge_multi_month_obra_continuity_certified
  - provider_independent_resume
  - causal_engineering_audit_trail
governs:
  - atlas_temporal_engineering_operating_system
evidence:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Executar TEOS-I1 conforme atlas-teos-increment-1-plan.md.
  - Adicionar termos TEOS ao glossary canônico.
  - Manter benchmark_status=not_run até autorização humana explícita.
---

# Atlas Temporal Engineering Operating System

## Resumo

TEOS é a north-star que transforma o Atlas de IA com memória longa em sistema temporal de engenharia. A tese é: tempo + verdade + validade + recovery + evidence vencem compactação comum. Este índice é deliberadamente curto; os detalhes foram divididos em specs filhas para respeitar Documentation OS.

## Papel no Atlas

TEOS governa continuidade de semanas para Atlas Dev e Obras de meses para Atlas Forge. Ele não substitui LHIL, Dev, Forge, Evidence, Compounding ou World Model. Ele define como essas camadas carregam validade temporal, replay, recovery e certification antes de execução longa.

## Onde Se Encaixa

```text
Programming Superiority Architecture
-> Long-Horizon Intelligence Layer
-> Temporal Engineering Operating System
   -> TEOS Temporal Primitives
   -> TEOS Operational Protocols
-> TEOS Increment 1 Plan
-> Pre-Benchmark Readiness
```

## Contratos

Os contratos north-star ficam detalhados nas specs filhas:

- `atlas.long_horizon.continuation_pack.v1/v2`
- `atlas.long_horizon.context_manifest.v1`
- `atlas.long_horizon.compaction_receipt.v1`
- `atlas.long_horizon.freshness_report.v1`
- `atlas.long_horizon.replay_manifest.v1`
- `atlas.long_horizon.recovery_plan.v1`
- `atlas.long_horizon.continuity_certification.v1`

TEOS-I1 limita implementação inicial a famílias reuse-first descritas em `atlas-teos-increment-1-plan.md`.

## Fluxo

```text
Run / Dev Session / Forge Obra
-> timeline event
-> context manifest
-> compaction receipt
-> continuation pack
-> freshness report
-> recovery plan
-> continuity certification
-> safe resume mode
-> next safe action
```

Execução longa sem esses artefatos deve cair para `read_only`, `ask_human` ou `blocked`.

## Regras para IA

- Não tratar resumo textual como estado operacional.
- Não executar alteração crítica com contexto stale.
- Não promover memória sem evidence e review.
- Não fundir Atlas Dev e Atlas Forge.
- Não criar schema paralelo quando TEOS-I1 manda estender componente existente.
- Não rodar benchmark nesta camada.

## Escopo de Implementacao

Este doc é north-star. A implementação real de curto prazo é TEOS-I1:

1. glossary + contracts;
2. temporal truth fields;
3. ledger timeline extension;
4. compaction receipt;
5. continuation pack v2;
6. freshness gate;
7. recovery planner;
8. replay manifest;
9. causal graph lite;
10. continuity certification.

## Dependencias

- `atlas-long-horizon-intelligence-layer.md`
- `atlas-teos-increment-1-plan.md`
- `atlas-teos-existing-code-map.md`
- `atlas-evidence-certification-runtime.md`
- `atlas-canonical-glossary-and-naming.md`

## Evidencias

Estado atual conhecido:

- Event timeline: PARTIAL via Evidence Ledger/Audit events.
- Compaction: PARTIAL via `AiCompactionService`.
- Forge long horizon: PARTIAL via `ForgeLongHorizonStateService`.
- Freshness, recovery, replay, continuity certification: GREENFIELD/TEOS-I1.

## Riscos

- Schema proliferation.
- Event-sourcing universal caro.
- Compactação silenciosa.
- Dev virando Forge.
- Forge sem timeline real.
- False resume.
- Replay não determinístico.
- Benchmark prematuro.

Mitigação: TEOS-I1 é reuse-first, incremental e mantém `benchmark_not_run`.

## Exemplos

Exemplo Dev: uma sessão de 30 dias só pode continuar se o continuation pack reconstruir objetivo, decisões, blockers, tests, patch history, stale refs e next safe action.

Exemplo Forge: uma Obra de 90 dias usa milestone ledger, SDD revisions, work packet lifecycle, evidence timeline e continuity certification antes de avançar marco.

## Proximas Acoes

- Corrigir glossary e contract alignment.
- Implementar TEOS-I1 Sprint 1.
- Rodar docs-health em toda mudança.
- Não rodar benchmark até autorização explícita posterior.

## Local Runtime Status TEOS-I1

Estado do canon TEOS-I1 local (`atlas.teos.readiness_certification.v1`
emitido por `AtlasTeosReadinessCertificationService` + complementado por
`AtlasTeosRuntimeWiringTest` para invariantes de runtime).

### Comandos canon

| Comando | Função | Output |
|---|---|---|
| `atlas:teos:readiness` | TEOS-I1 readiness static audit | `atlas.teos.readiness_certification.v1` |
| `atlas:teos:readiness --json` | Mesmo, JSON puro | idem |
| `atlas:teos:readiness --strict` | Exit ≠0 unless `status=ready` | CI gate |

### Serviços canon wired

- `App\Services\Ai\LongHorizon\AtlasLongHorizonCanon` (3 schemas).
- `App\Services\Ai\LongHorizon\LongHorizonRecoveryPlannerService::plan()`.
- `App\Services\Ai\LongHorizon\Gate\LongHorizonContextFreshnessGate::evaluate()`.
- `App\Services\Ai\LongHorizon\LongHorizonMemoryPromotionGuard` injetado em
  `AtlasMemoryDeltaPromotionService` (constructor refletido pelo runtime
  wiring test).
- `App\Services\Ai\Programming\Forge\ForgeLongHorizonStateService::emitContinuationPack()`
  delegando a `ForgeContinuationPackBuilder`.
- `App\Support\TemporalTruth\HasTemporalTruth` em `AtlasDecisionReceipt`,
  `AtlasMemoryEntry`, `AiCodebaseWorldModelEdge` (3 modelos canon).
- `AtlasMemoryEntry::SCOPES` carrega `obra` + `long_horizon` + const
  `LONG_HORIZON_SCOPES`.
- `AtlasLedgerEvent` enforce append-only via `LogicException` em
  `save()` e `delete()` — invariante exercitada em runtime.

### Persistência canon wired

- `atlas_long_horizon_continuation_packs` (schema `continuation_pack.v2`).
- `atlas_long_horizon_compaction_receipts` (schema `compaction_receipt.v1`).
- Colunas temporal-truth (`valid_from..authority_level`) presentes em
  `atlas_decision_receipts`, `atlas_memory_entries`, `ai_codebase_world_model_edges`.

### Honesty invariants enforcados na readiness

1. `status=ready` recusado enquanto qualquer P0/P1 check estiver `fail`.
2. `external_claim_status` sempre `not_claimed`.
3. `provider_calls_made` sempre `false`.
4. `atlas_decide_topology_modified` sempre `false`.
5. Atlas Decide / Provider Topology contratos preservados (boundary check).
6. Provider invocation drivers (Claude/Codex/Gemini) detectados via
   use-statements/instanciações reais, NÃO menções textuais.

### Limitações declaradas (não escondidas)

- **TEOS-I2+ não implementado**: Causal Decision Graph, Strategic
  Forgetting, ReplayManifest builder/reader, Continuity Certification
  full surface continuam em design.
- **Benchmark contra Claude Code/Codex permanece bloqueado** —
  `BenchmarkReadinessHarness` é o único harness e nunca executa.
- **`atlas:teos:readiness` é audit puro**: detecta presença de código e
  shape de classes, mas não exercita execução end-to-end. A complementação
  vem dos testes focados (`*LongHorizon*Test`, `*TemporalTruth*Test`,
  `*ForgeContinuationPack*Test`, `*MemoryPromotion*Test`, runtime wiring
  test) que cobrem 103+ assertions.

## North-Star Components

Detalhes completos estão nas specs filhas:

- [TEOS Temporal Primitives](atlas-temporal-engineering-operating-system-primitives.md)
- [TEOS Operational Protocols](atlas-temporal-engineering-operating-system-operations.md)

Top princípios preservados:

1. Tempo é primeira classe.
2. Estado é projeção sobre eventos.
3. Compactação exige receipt e loss accounting.
4. Retomada exige Continuity Certification.
5. Decisão superseded aponta sucessor.
6. Provider output não é canônico.
7. Continuidade é provider-independent.
8. Dev e Forge permanecem separados.
9. Memória durável nasce via promotion pipeline.
10. Benchmark vem depois da continuidade.
