---
id: atlas-teos-increment-1-plan
type: engineering_knowledge
title: Atlas TEOS Increment 1 Plan
status: active
category: programming
priority: 90
summary: Plano implementável da primeira fatia (Increment 1) do Atlas Temporal Engineering Operating System (TEOS). Aterrissa a visão TEOS em 10 missões reuse-first sobre serviços já shipados (AiCompactionService, AiSessionStateService, ProgrammingResumeService, ForgeLongHorizonStateService, AtlasLedgerEvent, AiAuditEvent, AtlasMemoryEntry, World Model, Compounding, Evidence/Certification, Control Plane, Telemetry, BenchmarkReadiness). Evita event sourcing universal, monthly review automatizado, causal graph complexo e qualquer runtime paralelo. benchmark_not_run.
tags:
  - atlas-dev
  - atlas-forge
  - teos
  - temporal
  - long-horizon
  - increment-1
  - 2026-05-18
capabilities:
  - teos_increment_1_planning
  - temporal_truth_field_rollout
  - continuation_pack_v2_design
  - compaction_receipt_design
  - replay_manifest_design
  - freshness_gate_design
  - recovery_planner_design
  - continuity_certification_design
decisions:
  - TEOS é north-star, multi-increment. Increment 1 é a primeira fatia implementável; não tenta entregar TEOS completo.
  - Reuse-first é regra. Não criar runtime paralelo a Compaction, SessionState, Resume, LongHorizonState, Ledger, Memory, Evidence, Compounding, Control Plane, Telemetry, BenchmarkReadiness.
  - Schemas novos restritos a 3 famílias canônicas (continuation_pack.v2, compaction_receipt.v1, replay_manifest.v1).
  - Temporal truth fields são adições em receipts existentes (não nova tabela timeline universal).
  - Causal graph entra como view/read model sobre tabelas existentes, não tabela própria.
  - Continuity certification reaproveita Final Certification + Pre-Benchmark Readiness; sem comando paralelo.
  - benchmark_not_run permanece invariante em I1 e em I2/I3.
  - Dev e Forge mantêm boundary; TEOS-I1 não funde runtimes.
maintenance:
  - Promover missão `proposed` → `implementing` → `delivered` com receipt + tests.
  - Sincronizar com `atlas-canonical-glossary-and-naming.md` quando termos da Mission 1 forem adicionados.
  - Atualizar `atlas-pre-benchmark-readiness-audit.md` quando uma missão fechar.
related_paths:
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-teos-increment-1-plan
graph_title: Atlas TEOS Increment 1 Plan
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-long-horizon-intelligence-layer
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md
allowed_changes:
  - Promover missão de `proposed` → `implementing` → `delivered` quando code + tests + receipt verde existirem.
  - Adicionar missão M11+ APENAS em incrementos futuros (TEOS-I2, TEOS-I3).
forbidden_changes:
  - Adicionar novo schema fora das 3 famílias canônicas declaradas sem entry de decisão na long-horizon layer.
  - Declarar missão `delivered` sem teste + docs-health verde.
  - Romper boundary Dev/Forge dentro do incremento.
  - Substituir reuse por sistema paralelo (event sourcing universal, timeline própria, tabela causal graph, monthly review automatizado).
  - Rodar benchmark externo durante I1.
depends_on:
  - atlas-long-horizon-intelligence-layer
  - atlas-programming-superiority-contracts
  - atlas-programming-superiority-roadmap
  - atlas-evidence-certification-runtime
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-pre-benchmark-readiness-audit
unlocks:
  - long_horizon_increment_1_implementation
  - continuation_pack_v2_audit_trail
  - context_freshness_enforcement
  - safe_resume_after_compaction
governs:
  - atlas_teos_increment_1
evidence:
  - docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Abrir AP para M1 (glossary update); pré-requisito de todas as demais.
  - Após M1 fechar, abrir AP para M2 sequencial (temporal truth fields).
  - Em Sprint C, abrir M3 + M4 em paralelo (áreas distintas).
  - Após M10 fechar, atualizar pre-benchmark-readiness-audit com "TEOS-I1 delivered" para habilitar promoção a TEOS-I2.
---

# Atlas TEOS Increment 1 Plan

## Resumo

Atlas TEOS é o north-star que dá ao Atlas continuidade de semanas (Dev) e
meses (Forge) com verdade temporal auditável. Increment 1 (TEOS-I1) é a
**primeira fatia implementável**: entrega a camada temporal mínima sem criar
runtime paralelo, sem multiplicar schemas e sem rodar benchmark externo.

Tese operacional: o Atlas já tem ~80% da musculatura long-horizon
(Compaction, SessionState, ProgrammingResume, ForgeLongHorizonState, Ledger,
Audit, Memory, World Model, Compounding, Evidence/Certification, Control
Plane, Telemetry, BenchmarkReadiness). TEOS-I1 acresce campos de verdade
temporal, formaliza continuation pack v2, ata compaction receipt, gera
replay manifest, e introduz freshness gate + recovery planner como serviços
enxutos. Continuity Certification reaproveita
`AtlasProgrammingFinalCertificationService` + Pre-Benchmark Readiness; sem
comando paralelo. `benchmark_status` permanece `not_run` durante toda I1.

## Papel no Atlas

TEOS-I1 fica entre Atlas Long-Horizon Intelligence Layer (mãe) e
Pre-Benchmark Readiness Audit (filho). Não substitui nenhum runtime — só
acresce a "linha do tempo auditável" sobre quem já existe.

- Para Atlas Dev: garante que um run pode ser interrompido por dias e
  retomado sem perder verdade decisional.
- Para Atlas Forge: garante que uma Obra de meses consegue prová-lo via
  ledger + replay manifest + compaction receipts.
- Para Evidence/Certification: adiciona freshness, supersede e provenance
  temporal a receipts já normalizados.

## Onde Se Encaixa

```text
Atlas Programming Superiority Architecture
└── Atlas Long-Horizon Intelligence Layer (canon spec)
    └── Atlas TEOS Increment 1 Plan (este doc — runbook)
        ├── 10 missões reuse-first sobre 14 serviços existentes
        ├── 3 famílias de schema long_horizon.* novas
        └── Continuity Certification integrada ao Final Certify
            └── Pre-Benchmark Readiness Audit (gate de promoção)
```

Layer 0.72 do Programming Governance; **abaixo** da camada de continuidade
(long-horizon), **acima** da camada de readiness (pre-benchmark).

## Contratos

### Schemas novos (3 famílias canônicas long_horizon)

| Schema | Substitui? | Emitido por |
|---|---|---|
| `atlas.long_horizon.continuation_pack.v2` | Não substitui v1 Dev; é generalização back-compat. | `ProgrammingResumeService` |
| `atlas.long_horizon.compaction_receipt.v1` | N/A (não existia). | `AiCompactionService` + `CompactionReceiptComposer` |
| `atlas.long_horizon.replay_manifest.v1` | N/A (novo). | `ReplayManifestService` |

### Extensões em modelos/receipts existentes (sem schema novo)

| Local | Campos acrescidos (todos nullable) |
|---|---|
| `AtlasLedgerEvent` | `scope_type`, `scope_id`, `event_type`, `event_hash`, `causation_id`, `correlation_id`. |
| `AiAuditEvent` / decision receipts | `valid_from`, `valid_until`, `observed_at`, `verified_at`, `stale_after`, `source_hash`, `superseded_by`, `authority_level`. |
| `AtlasMemoryEntry` | mesmos campos temporais que decision receipts. |
| `AiCodebaseWorldModelEdge` | `valid_until`, `superseded_by`. |

### Schemas existentes mantidos

`atlas.programming.continuation_packet.v1`, `atlas.forge.long_horizon_state.v1`,
`atlas.ai.evidence.*.v1`, `atlas.programming.repair_attempt.plan.v1`,
`atlas.programming.runtime_final_certification.v1`, todos canônicos e
preservados.

### Continuity Certification (sem comando novo)

Embutida no `AtlasProgrammingFinalCertificationService` como 5 checks
adicionais: `long_horizon_continuation_pack_v2_present`,
`long_horizon_compaction_receipt_present`,
`long_horizon_freshness_gate_present`, `long_horizon_recovery_planner_present`,
`long_horizon_replay_manifest_present`.

## Fluxo

### Sequência de missões e dependências

```text
M1 (glossary)
   └─ M2 (temporal truth fields)
        ├─ M3 (ledger timeline)
        └─ M4 (compaction receipt)
             ├─ M5 (continuation pack v2)
             │    ├─ M6 (freshness gate)
             │    └─ M7 (recovery planner)
             └─ M8 (replay manifest)
                  └─ M9 (causal graph lite)
                       └─ M10 (continuity certification)
```

### Plano de paralelização (sprints A-G)

| Sprint | Missões paralelas | Notas |
|---|---|---|
| A | M1 | Pré-requisito; bloqueia ship. |
| B | M2 (sozinha) | Migrations + campos nos 3 receipts. |
| C | M3 ‖ M4 | Áreas distintas (ledger vs compactador). |
| D | M5 (sozinha) | Lê M4. |
| E | M6 ‖ M7 ‖ M8 | Áreas distintas; M8 depende de M3+M4 já fechados. |
| F | M9 | Leitor sobre tudo acima. |
| G | M10 | Integração final em cert existente. |

### Estimativa total

- Soma de estimativas individuais: 82–130h.
- Overhead (PR review, AP por missão, testes integrados): +25–40h.
- Total realista: **110–170h** para I1 completo.
- MVP defensável (M1 → M5 + M10, sem M6/M7/M8/M9): 50–80h.

## Regras para IA

1. Não criar comando, tabela ou serviço paralelo a Compaction, SessionState,
   Resume, LongHorizonState, Ledger, Audit, Memory, World Model,
   Compounding, Evidence/Certification, Control Plane, Telemetry,
   BenchmarkReadiness.
2. Schemas novos restritos a 3 famílias `atlas.long_horizon.{continuation_pack.v2,
   compaction_receipt.v1, replay_manifest.v1}`. Specializações Dev/Forge
   permanecem como refinamento, não substituem.
3. Campos temporais (M2) são nullable e back-compat — caller antigo não
   precisa enxergá-los.
4. Continuation pack v1 continua sendo emitido sob demanda explícita;
   `legacy_compat=true` no receipt.
5. Causal Graph é **lite**: view/read-model. Tabela própria é
   `forbidden_changes` em I1.
6. Continuity Certification é **integração** ao Final Certify, não comando
   paralelo. Alias thin opcional só com pedido explícito do operador.
7. `benchmark_status` permanece `not_run` em todas as 10 missões. Promover
   para `executed` é decisão de TEOS-I4+, não desta doc.
8. Cada missão tem AP/teste/receipt verde antes de virar `delivered`.
9. Boundary Dev↔Forge preservado.
10. Sem novo termo se um existente cobre o caso — preferir `long_horizon`,
    `continuation_pack`, `compaction_receipt`, `replay_manifest`, etc.

## Escopo de Implementacao

### Entra em I1

Temporal truth fields em 3 receipts críticos; `AtlasLedgerEvent` scope/
correlation/causation; `continuation_pack.v2`; `compaction_receipt.v1`;
`replay_manifest.v1`; `LongHorizonContextFreshnessGate`;
`LongHorizonRecoveryPlannerService`; Causal Decision Graph **Lite** (view);
Continuity Certification integrada à Final Certification.

### Não entra em I1

Event sourcing universal; monthly review automatizado; strategic forgetting
completo; benchmark real; Temporal World Model completo; operator attention
sofisticado; causal graph com tabela própria; fusão Dev↔Forge.

### Reuse-First Map

| Capacidade TEOS-I1 | Serviço reaproveitado | Extensão |
|---|---|---|
| Compactação auditável | `AiCompactionService` | Emite `compaction_receipt.v1`. |
| Estado de sessão | `AiSessionStateService` | Lê campos temporais novos. |
| Resume Dev | `ProgrammingResumeService` | Emite continuation pack v2. |
| Estado long-horizon Forge | `ForgeLongHorizonStateService` | Consome v2 quando origem Forge. |
| Timeline auditável | `AtlasLedgerEvent` | 6 colunas nullable. |
| Audit decisional | `AiAuditEvent` | Reusa taxonomia; sem novo modelo. |
| Memória canônica | `AtlasMemoryEntry` | 5 campos temporais opcionais. |
| Grafo de código | `AiCodebaseWorldModelEdge` | `valid_until` + `superseded_by`. |
| Compounding/RAG feedback | `AtlasCompoundingRuntimeService` + `AtlasRagFeedbackService` | Consomem `compaction_receipt`. |
| Evidence runtime | `EvidencePackService` + `CertificationRuntimeService` | Aceitam refs aos novos manifests. |
| Cert final | `AtlasProgrammingFinalCertificationService` | Recebe 5 checks `long_horizon_*`. |
| Control plane | `EvidenceControlPlaneService` | Surface dos receipts no snapshot. |
| Telemetry | `RepairTelemetryRecorder` + `AuditEventService` | Loga eventos das missões. |
| Benchmark readiness | `ProgrammingRuntimeReadinessService` + Pre-Benchmark Audit | Promove gaps long-horizon → green. |

### Detalhes por missão (objetivo · arquivos · gates · riscos · estimativa)

**M1 — Glossary + Contract Alignment.** Registrar 7 termos em
`atlas-canonical-glossary-and-naming.md` + cross-link na long-horizon layer.
Sem código. Gate: docs-health zero violations. Risco: drift; mitigação:
cross-link explícito. 2h. Pré-requisito de todas.

**M2 — Temporal Truth Fields.** 3 migrations ALTER TABLE com campos
nullable (`valid_from`, `valid_until`, `observed_at`, `verified_at`,
`stale_after`, `source_hash`, `superseded_by`, `authority_level`) em
`AtlasLedgerEvent` (parcial), `AtlasMemoryEntry`, `AiCodebaseWorldModelEdge`.
Index em `stale_after`. Tests: roundtrip + back-compat. Gates: pint +
tests + docs-health. Risco: schema bloat; mitigação: campos opcionais. 6–10h.

**M3 — AtlasLedgerEvent Timeline.** Acresce `scope_type`, `scope_id`,
`event_type`, `event_hash`, `causation_id`, `correlation_id` ao modelo
existente. Helpers `scopeFor`, `forCorrelation`. Tests: chain
reconstruction, índice performante, hash determinístico. 8–12h. Serial
após M2.

**M4 — Compaction Receipt.** `AiCompactionService` emite
`compaction_receipt.v1` com `must_keep_coverage`, `retained_items`,
`discarded_items`, `discarded_reason`, `unresolved_loss`, `loss_risk`,
`recovery_queries`, `evidence_refs`, `summary_hash`. Novo helper
`CompactionReceiptComposer` puramente plan-only. Tests: determinismo,
`must_keep_coverage<0.85 → loss_risk=high`, propagação para v2. 10–14h.
Paralelo com M3.

**M5 — Continuation Pack V2.** `ProgrammingResumeService` emite
`continuation_pack.v2` com `superseded_decisions`, `context_manifest_inline`,
`safe_resume_mode` (`full|read_only|human_review_required`), `stale_refs`,
`missing_required_refs`, `next_safe_action`, `human_decisions_required`. V1
permanece disponível com `legacy_compat=true`. Tests: back-compat,
`missing_required_refs ≠ [] → safe_resume_mode=human_review_required`,
consumo Forge. 12–18h. Serial após M4.

**M6 — Freshness Gate.** Novo `LongHorizonContextFreshnessGate` single-
purpose. Output: `fresh|stale_advisory|stale_failed_closed`. Tests: stale
em flow strict bloqueia; flow advisory degrada; legado sem `stale_after`
permanece `fresh`. 6–10h. Paralelo com M5.

**M7 — Recovery Planner.** Novo `LongHorizonRecoveryPlannerService` puro
plan-only. Output com `recovery_steps`, `queries_to_replay` (do receipt
M4), `human_decisions_required`, `next_safe_action`, `risk_level`,
`evidence_refs`. Tests: pack `human_review_required` ⇒ pelo menos uma
decisão humana; `unresolved_loss ≠ [] ⇒ risk_level=high`; output hash
determinístico. 10–14h. Paralelo com M6.

**M8 — Replay Manifest + Reader.** Novo schema `replay_manifest.v1` +
`ReplayManifestService` (read-only) + command opcional
`atlas:long-horizon:replay --json`. Invariante: `provider_calls_required=false`.
Tests: reproducible bit-a-bit por `correlation_id`; comando retorna JSON
canônico + exit zero; nunca chama provider. 12–16h. Serial após M3+M4;
paralelo com M7 na fase E.

**M9 — Causal Decision Graph Lite.** Novo
`CausalDecisionGraphLiteService` puro reader sobre `AiAuditEvent` +
`AtlasLedgerEvent` + `AtlasMemoryEntry` + missions/work_orders.
**Sem tabela própria** (proibido em I1). Tests: parent via `causation_id`
alcançável; cadeia `superseded_by` rastreável; guard contra loop infinito.
10–14h. Serial após M3+M5; paralelo com M8.

**M10 — Continuity Certification.** **Não cria comando novo.** Acresce 5
checks long_horizon_* ao `AtlasProgrammingFinalCertificationService` +
linha equivalente em `ProgrammingRuntimeReadinessService` e Pre-Benchmark
Audit. Tests: green path, P0 fail bloqueia overall, `benchmark_status=not_run`
intacto. 6–10h. Serial — última missão.

## Dependencias

- `atlas-long-horizon-intelligence-layer.md` (mãe canon spec).
- `atlas-programming-superiority-contracts.md` (catálogo de schemas).
- `atlas-programming-superiority-roadmap.md` (sequenciamento e DoD).
- `atlas-evidence-certification-runtime.md` (Meta 4: receipts, packs, cert).
- `atlas-canonical-glossary-and-naming.md` (vocabulário, atualizado em M1).
- 14 serviços/modelos shipados (ver Reuse-First Map em §Escopo).

## Evidencias

### Readiness/Certification canônico

Status auditavel emitido por `AtlasTeosReadinessCertificationService` via
`php artisan atlas:teos:readiness --json`. Schema
`atlas.teos.readiness_certification.v1`. 12 checks cobrindo docs, services,
models, tests, freshness gate, recovery planner, memory promotion guard,
Hyperflow canon doc, boundary com Atlas Decide e invariantes de segurança
(`provider_calls_made=false`, `atlas_decide_topology_modified=false`,
`external_claim_status=not_claimed`). Status `ready` exige zero P0/P1 fail.
NÃO autoriza promoção contra rival; NÃO mexe em provider topology.

### Definition of Done

TEOS-I1 é **delivered** quando, simultaneamente:

1. Glossary canon contém os 7 termos de M1 sem violations.
2. 3 migrations de M2 aplicadas + tests de cast + back-compat verde.
3. `AtlasLedgerEvent` carrega `scope_type/scope_id/event_type/event_hash/
   causation_id/correlation_id` sem quebrar callers existentes.
4. `AiCompactionService` emite `compaction_receipt.v1` com
   `must_keep_coverage`, `loss_risk` e `unresolved_loss` honestos.
5. `ProgrammingResumeService` emite `continuation_pack.v2` com
   `safe_resume_mode` calculado a partir dos sinais.
6. `LongHorizonContextFreshnessGate` retorna `stale_failed_closed` em flow
   strict + tests back-compat.
7. `LongHorizonRecoveryPlannerService` produz plano determinístico
   (hash estável) com `risk_level` honesto.
8. `replay_manifest.v1` é gerado com `provider_calls_required=false`
   invariante asserted em teste.
9. Causal Decision Graph Lite responde queries parent/child + cadeia
   `superseded_by` sem tabela própria.
10. Continuity Certification embutida no Final Certify: 5 checks novos
    aparecem; `benchmark_status` permanece `not_run`.
11. `docs-health --json` zero violations; trinity (long-horizon + this +
    pre-benchmark) cross-link íntegro.
12. `git diff --check` limpo na PR final.

### Receipts esperados por missão

- M1: glossary entries presentes; docs-health limpo.
- M2: migrations id `2026_05_*_add_temporal_truth_*`; tests verdes.
- M3: tests `AtlasLedgerEvent::test_correlation_chain` verde.
- M4: `compaction_receipt.v1` persistido ou anexado a snapshot existente.
- M5: `continuation_pack.v2` shipado por `ProgrammingResumeService`.
- M6: gate result com `status` canônico em fixture stale.
- M7: payload do planner com `recovery_steps[]` e `risk_level`.
- M8: manifest com hash determinístico.
- M9: serviço lite retorna grafo derivado em <50ms para 1k eventos.
- M10: cert final com 5 checks long_horizon_* + `benchmark_status=not_run`.

## Riscos

1. **Overengineering.** Tentação de virar event sourcing universal.
   Mitigação: `forbidden_changes` no frontmatter + Mission 9 "lite".
2. **Schema proliferation.** Mitigação: 3 famílias canônicas declaradas;
   especializações Dev/Forge permanecem como refinamento.
3. **Breaking existing flows.** Mitigação: todos os campos novos são
   nullable; receipts v2 são back-compat (caller v1 continua recebendo v1).
4. **False green em continuity certification.** Mitigação: P0/P1 fail
   bloqueia overall_status no Final Certify (regra já vigente).
5. **Doc drift.** Mitigação: M1 obriga glossary; M10 marca cada artefato
   no readiness audit.
6. **Sistemas paralelos.** Mitigação: proibição explícita no frontmatter
   contra CLI/comando paralelo ao final-certify, ao ledger, ao compactador.
7. **Benchmark leak.** Mitigação: `benchmark_status` invariante `not_run`
   no Final Certify; check P2 explícito + asserted em teste.

## Exemplos

### Exemplo de continuation_pack.v2 mínimo

```json
{
  "schema_version": "atlas.long_horizon.continuation_pack.v2",
  "run_id": "dev-run-001",
  "safe_resume_mode": "human_review_required",
  "superseded_decisions": ["dec-007"],
  "context_manifest_inline": [
    {"ref": "spec:auth-flow-v2", "stale_after": "2026-06-01T00:00:00Z"}
  ],
  "stale_refs": [],
  "missing_required_refs": ["test:auth/regression-suite"],
  "next_safe_action": "request_human_decision_on_missing_ref",
  "human_decisions_required": [
    {"id": "hd-001", "question": "Aprovar plano alternativo sem suite?"}
  ],
  "legacy_compat": false
}
```

### Exemplo de compaction_receipt.v1 mínimo

```json
{
  "schema_version": "atlas.long_horizon.compaction_receipt.v1",
  "compaction_run_id": "comp-101",
  "must_keep_coverage": 0.92,
  "retained_items": 42,
  "discarded_items": 18,
  "discarded_reason": "low_signal",
  "unresolved_loss": ["ref:foo-edge-case"],
  "loss_risk": "medium",
  "recovery_queries": ["SELECT * FROM atlas_memory_entries WHERE tag = 'foo-edge-case'"],
  "evidence_refs": ["ai_audit_event:42"],
  "summary_hash": "sha256:..."
}
```

### Exemplo de Continuity Certification embedded

```json
{
  "schema_version": "atlas.programming.runtime_final_certification.v1",
  "overall_status": "green",
  "benchmark_status": "not_run",
  "checks": [
    {"check_id": "long_horizon_continuation_pack_v2_present", "status": "pass", "severity": "P0"},
    {"check_id": "long_horizon_compaction_receipt_present",  "status": "pass", "severity": "P0"},
    {"check_id": "long_horizon_freshness_gate_present",      "status": "pass", "severity": "P1"},
    {"check_id": "long_horizon_recovery_planner_present",    "status": "pass", "severity": "P1"},
    {"check_id": "long_horizon_replay_manifest_present",     "status": "pass", "severity": "P1"}
  ]
}
```

## Proximas Acoes

### Imediatas (Sprint A)

1. Abrir AP da Mission 1 (glossary update). Pré-requisito de tudo.
2. Validar com operador a aderência aos termos canônicos sugeridos.
3. Atualizar `atlas-pre-benchmark-readiness-audit.md` com row "TEOS-I1
   tracking begun" (apenas tracking, sem promoção).

### Por sprint

- Sprint B: AP de M2 (3 migrations).
- Sprint C: APs paralelas de M3 e M4.
- Sprint D: AP de M5.
- Sprint E: APs paralelas de M6, M7, M8.
- Sprint F: AP de M9.
- Sprint G: AP de M10; ao fechar, atualizar pre-benchmark-readiness-audit
  com "TEOS-I1 delivered".

### Fases posteriores (não em I1)

| Fase | Escopo |
|---|---|
| TEOS-I2 | Strategic forgetting completo (TTL graduado + memory promotion governada), monthly review automatizado advisory-only, causal graph com persistência opcional, replay CLI executável (não só read). |
| TEOS-I3 | Temporal World Model completo (edges com weight temporal), operator attention robusto, deprecar `continuation_packet.v1` legado. |
| TEOS-I4+ | Benchmark autorizado contra Claude Code/Codex/Cursor com gate explícito do operador — único momento em que `benchmark_status` é promovível para `executed`. |

Cada incremento futuro tem AP/RFC próprio. Não antecipar trabalho desta
doc para incrementos posteriores.

### Gates de fechamento deste plano

- `php artisan atlas:engineering:knowledge docs-health --json` → 0 violations.
- `git diff --check` → limpo.
- Cross-link integrity verificado com `atlas-long-horizon-intelligence-layer.md`
  e `atlas-pre-benchmark-readiness-audit.md`.
