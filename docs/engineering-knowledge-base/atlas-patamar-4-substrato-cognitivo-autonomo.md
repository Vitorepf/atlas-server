---
title: Atlas Patamar 4 — Substrato Cognitivo Autônomo (mestre)
slug: atlas-patamar-4-substrato-cognitivo-autonomo
status: building
risk_level: critical
graph_parent: atlas-cognition-operating-system
depends_on:
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
  - atlas-cognitive-function-atlas
  - atlas-autonomous-reconciliation-runtime
  - atlas-teos-i4-counterfactual-tree
  - atlas-swarm-conductor
  - atlas-temporary-domain-composition
authority_class: substrate
forbidden_changes:
  - claim_patamar4_finished_without_real_runtime
  - bypass_constitutional_kernel
  - allow_autonomous_action_without_admission
  - silent_loop_iteration
schema:
  - atlas.patamar4.state.v1
  - atlas.patamar4.smoke_envelope.v1
---

# Atlas Patamar 4 — Substrato Cognitivo Autônomo (mestre)

Doc-mãe Patamar 4. Conecta os 14 services + closures P1/P2/P3 num substrato coerente onde Atlas detecta gaps, propõe ações, valida pétreos, projeta outcomes e dispara ação real — sob trilho constitucional invariante.

## Loop fechado (atual, rodando)

```
Reconciliation tick (cron */15min ou CLI)
  ↓
CognitiveFunctionAtlas.gapsByGroup()              [self-model]
  ↓
deriva change envelope { change_kind, scope, autonomy }
  ↓
Constitutional Kernel.validateChange()            [9 pétreos]
  ↓
Autonomy Admission.admit()                        [risco × autonomia × kernel]
  ↓
AURG-4D.recordTick()                              [hash chain imutável]
  ↓
(quando allow_autonomous)
TEOS-I3.branch()                                  [meta-projeção do outcome]
  ↓
if (projection.improvement ≥ 0.05)
  ASCB.propose()                                  [scaffold + receipt]
else
  recorda 'projection_below_threshold'            [honesto, não polui ASCB]
  ↓
(opcionalmente, após operator approve via CLI)
Scaffold Staging Executor                         [arquivos em staging/]
```

Cada flecha = chamada real entre services. Cada artifact = JSONL append-only no disco.

## Mapa dos 14 services + closures

### Patamar 4 core (7)

| # | Acrônimo | Service | Função |
|---|----------|---------|--------|
| 4.0 | ACK | AtlasConstitutionalKernelService | Pétreos imutáveis (claim_policy, sovereignty, cognitive_immune_law…) |
| 4.1 | AAA | AtlasAutonomyAdmissionService | Composer Kernel × PolicyCanon × risk → decision |
| 4.2 | ACFA | AtlasCognitiveFunctionAtlasService | Self-model: groups, gaps, shape derivados do ScoreCard |
| 4.3 | AARR | AtlasAutonomousReconciliationRuntimeService | Motor do loop: tick → admit → AURG-4D → ASCB |
| 4.4 | TEOS-I4 | AtlasTeosI4CounterfactualTreeService | Tree lookahead sobre TEOS-I3 |
| 4.5 | ASWC | AtlasSwarmConductorService | Multi-arm dispatch envelope composer |
| 4.6 | ATDC | AtlasTemporaryDomainCompositionService | Cápsulas cross-domain com TTL |

### Patamar 4 integration layer (4)

| # | Acrônimo | Service | Função |
|---|----------|---------|--------|
| 4.7 | ADGW | AtlasDecideGatewayConsultationService | Hook ADML que `AiGatewayService` consulta antes de provider resolution |
| 4.8 | AACM | AtlasAntifragilityCompositionMetricService | Mede multiplicador M (wrapper-only, NUNCA estima N do provider) |
| 4.9 | ACMF-SE | AtlasCognitiveMemoryFabricSchemaEvolutionService | Propõe v+1 de schemas existentes |
| 4.10 | ASCB-EX | AtlasSelfConstructionScaffoldStagingExecutorService | APPROVED proposal → scaffold em staging/ |

### Closures P1/P2/P3 (3)

| Acrônimo | Service | Função |
|----------|---------|--------|
| ACOP-ACRS | AtlasContextObservabilityToRankingReflexiveBridgeService | Sinais ACOP streaming para ACRS adaptar weights |
| AKIF-OCR | AtlasKnowledgeIngestionFabricOcrConfidenceService | Wrapper de confidence pra OCR/transcription antes da promoção |
| ACL8 | AtlasCompoundingLevel8DistillationService | L7 → L8 → L9 distilação a partir de evidence runtime |

## API operável (não-envelope-only)

### CLI
```bash
# Pétreos
php artisan atlas:constitutional:kernel --action=list-invariants --json
php artisan atlas:constitutional:kernel --action=validate --change-json='{...}'

# Admission gate
php artisan atlas:autonomy:admit --change-json='{...}' --json
php artisan atlas:autonomy:admit --list --limit=20

# Self-model
php artisan atlas:cognitive-function --action=self-model --json
php artisan atlas:cognitive-function --action=gaps

# Loop
php artisan atlas:reconciliation --action=tick --force-group=cognitive_immune --autonomy=autonomous
php artisan atlas:reconciliation --action=summary

# Counterfactual
php artisan atlas:teos-i4 --action=expand --input-json='{...}'

# Multi-arm
php artisan atlas:swarm --action=dispatch --work-json='{...}'

# Cross-domain TTL
php artisan atlas:temporary-domain --action=compose --input-json='{...}'

# ADML × Gateway
php artisan atlas:atlas-decide:gateway-consult --task-category=code_generation --json

# Smoke end-to-end
php artisan atlas:patamar4:run-loop-once --json
```

### HTTP
```
GET /atlas/patamar4/state?tail=N      # aggregator vivo de todos os 14 services
GET /atlas/ai/runtime-readiness        # Atlas AI runtime gate (canon anterior)
```

### Cron
`bootstrap/app.php` schedule:
```
*/15 * * * * php artisan atlas:reconciliation --action=tick --privacy=normal --autonomy=execute_with_approval --json
```

Configurável via `config('atlas.patamar4.reconciliation_cadence', 'fifteen')`.

## Persistência (append-only JSONL local-first)

| Camada | Path |
|--------|------|
| Kernel violations | `storage/atlas/governance/violations.jsonl` |
| Admission tickets | `storage/atlas/governance/autonomy_admissions.jsonl` |
| Reconciliation ticks | `storage/atlas/reconciliation/ticks.jsonl` |
| AURG-4D temporal | `storage/atlas/aurg/temporal_ticks.jsonl` |
| ASCB proposals/approvals | `storage/atlas/self_construction/proposals.jsonl` + `approvals.jsonl` |
| Scaffold staging receipts | `storage/atlas/self_construction/staging_receipts.jsonl` |
| TEOS-I3 branches | `storage/atlas/teos_i3/branches.jsonl` |
| TEOS-I4 trees | `storage/atlas/teos_i4/trees.jsonl` |
| Swarm dispatches | `storage/atlas/swarm/dispatches.jsonl` |
| TDC capsules | `storage/atlas/cross_domain/capsules.jsonl` |
| Gateway consultations | `storage/atlas/atlas_decide/gateway_consultations.jsonl` |
| Compounding L8 distillations | `storage/atlas/compounding/level8_distillations.jsonl` |
| ACOP→ACRS signals | `storage/atlas/acop_to_acrs/signals.jsonl` |
| AKIF OCR artifacts | `storage/atlas/akif/ocr_artifacts.jsonl` |
| ACMF schema proposals | `storage/atlas/acmf/schema_proposals.jsonl` |

## DI / wiring real (AppServiceProvider)

- `AtlasTeosI3CounterfactualService` resolvido → recebe `setAurgForChaining()` para emitir AURG-4D tick a cada branch.
- `AtlasAutonomousReconciliationRuntimeService` resolvido → recebe `setTeosI3ForMetaProjection()` para projetar antes de propor.
- `AtlasSelfConstructionSubsystemBuilderService` injetado obrigatório em Reconciliation Runtime (não-nullable).

## Pendências honestas (não-objetivos desta entrega)

- **ADML wired DENTRO do `AiGatewayService` real** — hook `AtlasDecideGatewayConsultationService` existe e tem CLI próprio; integração no critical path do gateway é um PR cirúrgico próprio (gateway tem 2600+ LoC).
- **Surface mobile/desktop consumindo `/atlas/patamar4/state`** — endpoint pronto, surface não. UI work próxima fatia.
- **Constitutional Kernel `elastic` e `runtime` classes** — declaradas no doc, source-coded é só pétreo. Próxima fatia define invariantes elastic concretos.
- **Trust Ledger integration no Admission** — declarado "futura integração".
- **Reconciliation gap probe via JSONL evidence** — hoje gap detection olha só `pipeline_status` declarado; probing real exigiria opt-in com cuidado pra não derrubar o scorecard.

## claim_policy (zero tolerância)

- `benchmark_claim_allowed=false` em todos os envelopes
- `rivals_claim_allowed=false`
- `superiority_claim_allowed=false`
- `external_rivals_certification_touched=false`
- `cognitive_immune_law_enforced=true`
- `provider_capability_estimated=false` (Antifragility mede só M, NUNCA N)
- `provider_safe_only_enforced=true`

## Replay / audit

`GET /atlas/patamar4/state` agrega tudo. Cada JSONL é fonte canônica daquela camada. `kernel_hash` carregado em todos os envelopes permite cross-reference. AURG-4D timeline tem hash chain (prev_tick_hash → tick_hash) verificável por `verifyChain()`.
