---
id: atlas-teos-existing-code-map-part-02
type: engineering_knowledge
title: Atlas TEOS Existing Code Map · Parte 2
status: active
category: programming
priority: 95
summary: Recorte focado do mapa de código TEOS: Anti-Duplication Rules ate Gates de fechamento desta doc.
tags:
  - atlas-teos
  - code-map
  - anti-duplication
  - split-doc
capabilities:
  - existing_code_inventory
  - anti_duplication_rules
decisions:
  - Este recorte preserva uma parte do inventário TEOS sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando arquivo, classe ou classificação mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-teos-existing-code-map-part-02
graph_title: Atlas TEOS Existing Code Map Parte 2
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-teos-existing-code-map
graph_status: active
graph_source: repo
human_name: Atlas TEOS Existing Code Map Parte 2
canonical_name: Atlas TEOS Existing Code Map Parte 2
technical_name: atlas-teos-existing-code-map-part-02
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md
allowed_changes:
  - Atualizar somente inventário, gaps, testes e alterações recomendadas desta parte.
forbidden_changes:
  - Declarar reuse sem evidência de código ou criar componente paralelo proibido.
depends_on:
  - atlas-teos-existing-code-map
flows_to:
  - atlas-programming-superiority-roadmap
unlocks:
  - teos_i1_implementation_kickoff
governs:
  - atlas_teos_existing_code_reuse
evidence:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
evidence_refs:
  - symbol: AtlasTeosExistingCodeMapPart02Service
  - command: atlas:aaeos:teos-existing-code-map-part-02
  - test: AtlasTeosExistingCodeMapPart02Test
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice TEOS e bloquear duplicação de código.
---
# Atlas TEOS Existing Code Map · Parte 2

## Resumo

Este recorte preserva uma parte focada do inventário TEOS: Anti-Duplication Rules ate Gates de fechamento desta doc.

## Papel no Atlas

Ajuda implementadores e reviewers a reutilizar código existente em vez de criar sistema paralelo.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-teos-existing-code-map.md` e complementa o contrato de Long-Horizon Intelligence.

## Contratos

Não duplica ledger, memory store, compaction engine, Forge state ou benchmark runner.

## Fluxo

Mapa TEOS → recorte de componente → decisão reuse/extend/adapter/greenfield → PR com evidência.

## Regras para IA

Sempre procurar componente existente antes de propor classe nova. Não misturar roadmap com inventário técnico.

## Escopo de Implementacao

Este recorte documenta código existente, gaps, riscos, testes e alteração recomendada.

## Dependencias

Depende do mapa TEOS, Long-Horizon Intelligence e glossário canônico.

## Evidencias

A evidência principal é o próprio caminho de código citado no conteúdo extraído.

## Riscos

Risco principal: duplicação operacional que faz docs/cartografia divergirem do runtime real.

## Exemplos

Os exemplos abaixo são o inventário extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar quando a classificação do componente mudar e rodar docs-health.

## Conteudo Extraido
### Anti-Duplication Rules

1. **Não criar tabela paralela ao `atlas_ledger_events`.** TEOS escreve
   eventos com `event_type ∈ long_horizon.*`; append-only enforcement é
   herdado de `AtlasLedgerEvent::save()`.
2. **Não criar `LongHorizonCompactionEngine`.** Estender `AiCompactionService`
   com `compactForScope()` + migration aditiva em `ai_compactions`.
   Compactação tem que passar pelo mesmo lock transaction.
3. **Não criar `LongHorizonForgeState`.** `ForgeLongHorizonStateService`
   serve. Adicionar `emitContinuationPack()` que delega ao builder novo.
4. **Não criar `AtlasLongHorizonMemoryEntry` ou store paralelo.**
   Estender `AtlasMemoryEntry::SCOPES` com `obra` e `long_horizon`.
   Promotion gate ganha invariante operator_review_required.
5. **Não criar `LongHorizonBenchmarkRunner` ou `LongHorizonRivalsHarness`.**
   `BenchmarkReadinessHarness` é a única superfície; TEOS nunca toca runner.
6. **Não criar comando paralelo a `atlas:programming:console`.** Adicionar
   actions `long-horizon:*` no Canon existente (`ProgrammingConsoleCanon`).
7. **Não criar `ProgrammingResumeServiceV2`.** Estender `state()` +
   `continuationPacket()` com chaves novas; manter backwards-compat.
8. **Não criar service paralelo a `AtlasEvidenceLedger`.** Usar `record()`
   com `LedgerEventType` novos.
9. **Não criar tabela `ai_long_horizon_certifications`.** Usar
   `ai_mission_certifications` com `kind=long_horizon_continuity`.
10. **Não introduzir naming proliferation.** "TemporalState",
    "ContinuityEngine", "WorkstreamMachine" — todos proibidos. Usar
    `long_horizon.*` ou nada.

### Quality Gates herdados

- `must_keep_coverage == 1.0` obrigatório em qualquer
  `compaction_receipt.v1` emitido (greenfield invariante).
- `claim_policy.benchmark_not_run=true` em todo envelope do console e
  control plane (já enforced).
- `completed` exige certification (já enforced em `MissionLifecycleService`).
- Promotion memory scope `obra|long_horizon` exige operator review
  (greenfield invariante).

## Escopo de Implementacao

### Recommended Implementation Order

Baseado no código real e em paralelização segura:

| # | Slice | Toca | Classificação | Paralelizável | Dep |
|---|-------|------|---------------|---------------|-----|
| S1 | Glossary entry `long_horizon` em `atlas-canonical-glossary-and-naming.md` | doc | doc-only | não | — |
| S2 | Migration `ai_long_horizon_continuation_packs` + model | DB | greenfield | não | S1 |
| S3 | Migration `ai_long_horizon_compaction_receipts` + model | DB | greenfield | paralelo S2 | S1 |
| S4 | `LongHorizonContinuationPackBuilder` (Dev side via `AtlasDevRunIndex`) | service | greenfield | depende S2 | S2 |
| S5 | Extend `AiCompactionService::compactForScope()` + migration aditiva em `ai_compactions` | service+DB | extend | paralelo S4 | S3 |
| S6 | `ForgeLongHorizonStateService::emitContinuationPack()` hook | service | extend | paralelo S5 | S2+S4 |
| S7 | Extend `ProgrammingResumeService::state()` com `pack_hash`/`stale_after` | service | extend | paralelo S6 | S2 |
| S8 | Console actions `long-horizon:{compact,continue,status}` em `ProgrammingConsoleCanon` | service | extend | depende S5+S7 | S5+S7 |
| S9 | `LongHorizonContextFreshnessGate` (greenfield) + console action | service | greenfield | depende S2 | S2 |
| S10 | Extend `AtlasMemoryEntry::SCOPES` + promotion gate operator-reviewed | model+service | extend | paralelo S9 | — |
| S11 | `LongHorizonRecoveryPlannerService` (greenfield) | service | greenfield | depende S9 | S9 |
| S12 | `ContinuityCertification` service (greenfield) + 8 invariants | service | greenfield | depende S5+S9+S11 | S5+S9+S11 |
| S13 | `ReplayManifest` builder/reader (greenfield) | service | greenfield | paralelo S12 | S2+S3 |
| S14 | Control Plane projection `long_horizon_summary` + telemetry event names | service | extend | depende S8 | S8 |

**Total greenfield slices:** S2, S3, S4, S9, S11, S12, S13 (7).
**Total extend slices:** S5, S6, S7, S8, S10, S14 (6).
**Doc-only:** S1.
**Zero deprecation, zero rewrite.**

## Dependencias

- `atlas-long-horizon-intelligence-layer.md` (lei + schemas).
- `atlas-programming-superiority-architecture.md` + `-contracts.md` +
  `-roadmap.md` (trinity).
- `atlas-canonical-glossary-and-naming.md` (entry `long_horizon` em S1).
- `atlas-evidence-certification-runtime.md` (ledger + certification).
- `atlas-compounding-engineering-intelligence.md` (memory + outcome
  feedback).
- Backend Meta 1 (Mission), Meta 4 (Evidence), Meta 6 (Router), Meta 9
  (Control Plane) — todos `live`.

## Evidencias

### Tests To Reuse (preservar)

Cada componente classificado `reuse|extend|do_not_touch` carrega teste
existente que TEOS NÃO pode quebrar. Cobertura herdada:

| Componente | Teste obrigatório (PRESERVE) |
|------------|------------------------------|
| AiCompactionService | `tests/Unit/AiSessionManagerTest.php` |
| AiSessionStateService | `tests/Unit/AiSessionStateServicePendingSteerTest.php` |
| ProgrammingResumeService | `tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php` |
| ForgeIntakeService | `tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php` |
| ForgeLongHorizonStateService | `tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php` |
| ForgeWorkPacketExecutionCycle (v1 service) | `tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php` |
| ForgeWorkPacketExecutionCycle (Execution/ new) | `tests/Feature/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycleTest.php` |
| AtlasEvidenceLedger | `tests/Unit/Ai/Kernel/EvidenceLedgerTest.php` |
| AtlasLedgerEvent (append-only) | herdado de tests/Unit/Ai/Kernel/EvidenceLedgerTest.php |
| AtlasMemoryEntry | `tests/Feature/Ai/AtlasMemoryEntry*Test.php` (vários) |
| AiCodebaseWorldModel* | `tests/Unit/Ai/AutonomousEngineering/AtlasAutonomousEngineeringServiceTest.php` |
| WorldModelGraphRanker | `tests/Unit/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRankerTest.php` |
| LocalAgentMemoryIngestion | `tests/Feature/Ai/Memory/LocalAgentIngestion/*Test.php` |
| Compounding family (11 services) | `tests/Feature/Ai/AtlasCompoundingEngineeringIntelligenceTest.php` |
| ProgrammingRuntimeControlPlane | `tests/Feature/Ai/ProgrammingRuntime/ControlPlane/*Test.php` |
| ProgrammingConsole | `tests/Feature/Ai/Programming/Console/ProgrammingConsoleCommandTest.php` |
| BenchmarkReadinessHarness | `tests/Unit/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarnessTest.php` |

### Tests novos requeridos (greenfield)

Cada slice greenfield TEM que carregar test:

1. **S2/S3** — model + table smoke; insert/read; `pack_hash`/`receipt_hash`
   determinístico.
2. **S4** — Dev workstream em 3 runs → pack agrega decisions, blockers;
   `pack_hash` estável.
3. **S5** — `compactForScope(scope_type=forge_obra)` emite
   `compaction_receipt` com `must_keep_coverage==1.0`; throw em coverage
   < 1.0.
4. **S6** — Forge milestone-end → continuation_pack emitido sem
   alterar `state_hash` do `AiForgeLongHorizonState`.
5. **S7** — `ProgrammingResumeService::state()` retorna `pack_hash`,
   `stale_after`; backwards-compat com callers atuais.
6. **S8** — 3 console actions long-horizon emitem envelope canon com
   `claim_policy.benchmark_not_run=true`.
7. **S9** — Freshness Gate: `stale_after = now-1h` → status `blocked`.
8. **S10** — Memory promotion scope `obra` sem operator review → refusal.
9. **S11** — Recovery Planner: drift trigger → recovery plan com
   `next_best_action`.
10. **S12** — Continuity Certification: 8 invariants verdes em pack canon;
    falha quando `must_keep_coverage<1.0`.
11. **S13** — ReplayManifest builder + reader: round-trip determinístico.
12. **S14** — Control plane snapshot inclui `long_horizon_summary`;
    telemetry events `long_horizon.*` reconhecidos.

## Riscos

### Risk Map (onde mudanças podem quebrar runtime)

1. **`AiCompactionService` lock contention** (S5): adicionar
   `compactForScope` precisa reusar o mesmo `DB::transaction` +
   `lockForUpdate` para não causar double-compaction. **Mitigação:** novo
   método público delega ao `compactLocked` privado existente.
2. **`AtlasLedgerEvent` append-only invariant** (S8+): qualquer attempt de
   update lança `LogicException`. Bugs de TEOS que tentem corrigir evento
   gravado vão crashar em produção (correto, mas operacionalmente
   visível). **Mitigação:** TEOS sempre emite evento novo
   `long_horizon.pack_corrected` em vez de tentar update.
3. **`ForgeLongHorizonStateService::recordCycle` lock** (S6): emitir
   continuation_pack dentro de recordCycle aumenta tempo do lock.
   **Mitigação:** emit asíncrono via event listener post-save.
4. **`AtlasMemoryEntry::SCOPES` enum bypass** (S10): callers que validem
   scope contra const SCOPES vão rejeitar `obra`/`long_horizon` até PR
   shipped em todos os lugares. **Mitigação:** grep agressivo antes do
   merge.
5. **`ProgrammingResumeService` backwards-compat** (S7): callers em
   `AtlasProgrammingResumeCommand` e `AtlasCliContinueCommand` consomem
   `continuation_packet`. Adicionar keys é seguro; remover ou renomear
   quebra. **Mitigação:** sempre aditivo.
6. **Control plane snapshot determinism** (S14): adicionar
   `long_horizon_summary` muda o `snapshot.sha256` se algum test depende
   disso. **Mitigação:** verificar
   `ProgrammingRuntimeControlPlaneServiceTest::snapshot_is_stable_for_identical_state`.
7. **Telemetry canon event names** (S14): adicionar nomes em
   `CANONICAL_EVENT_NAMES` muda o array. Tests que comparam contagem podem
   falhar. **Mitigação:** validar tests com `count(CANONICAL_EVENT_NAMES)`
   antes do merge.
8. **Freshness Gate false positives** (S9): heurística agressiva bloqueia
   resumes legítimos. **Mitigação:** primeiros 30 dias rodar em modo
   `advisory_only`; bloqueio só em modo `enforce` ativado por flag.
9. **Recovery Planner loop infinito** (S11): plan que sempre falha
   freshness gate cria loop. **Mitigação:** `max_recovery_attempts=3` +
   escalate para operator.
10. **Naming proliferation acidental** — alguém cria `WorkstreamPack` ou
    `TemporalCertification`. **Mitigação:** §14 enforce em code review;
    `atlas-canonical-glossary-and-naming.md` é gate explícito.

### Anti-pattern proibido

- **Criar `LongHorizonForgeState` em vez de estender o serviço existente.**
- **Criar `ai_long_horizon_audit_events`** quando `atlas_ledger_events` +
  `event_type=long_horizon.*` resolve.
- **Reescrever `ProgrammingResumeService` "para limpeza"** — extend, não
  rewrite.
- **Aprovar PR TEOS que adicione `migrations/2026_xx_xx_create_temporal_*`**
  fora da família `ai_long_horizon_*` declarada.
- **Declarar `benchmark_status=running` em qualquer envelope TEOS** —
  proibido por contrato do harness.

## Exemplos

### Reuse correto: Dev workstream resume

S4 + S7 combinados:

```text
operator runs `atlas:cli:continue --thread=feat-router-x` ->
  AtlasCliContinueCommand resolve thread -> ProgrammingResumeService::state()
    (extended) carrega timeline via ProgrammingStageReceiptStore +
    chama LongHorizonContextFreshnessGate::evaluate(pack_id)
  -> Freshness Gate consulta `ai_long_horizon_continuation_packs` (S2),
     `stale_after >= now` -> passed -> resume_command devolvido com
     `pack_hash`. Zero criação de classe nova além de Builder + Gate;
     `ProgrammingStageReceiptStore` intocado.
```

### Reuse correto: Forge Obra continuation

S6:

```text
ForgeLongHorizonStateService::recordCycle() (intocado) executa cycle ->
  event listener post-save dispara LongHorizonContinuationPackBuilder
  (S4 generalizado) -> pack persiste em `ai_long_horizon_continuation_packs`
  -> AtlasEvidenceLedger::record(LedgerEventType::LONG_HORIZON_PACK_BUILT)
     escreve em `atlas_ledger_events` (append-only enforced).
  Zero migration nova em `ai_forge_long_horizon_states`. Zero classe nova
  além de Builder.
```

### Anti-pattern bloqueado

```text
git PR: "feat: add LongHorizonCompactionEngine + ai_long_horizon_compactions
table"
review: REJEITADO. AntiDup §2: estender AiCompactionService::compactForScope
+ migration aditiva em ai_compactions. Re-submit como S5.
```

## Proximas Acoes

### Sequência canônica TEOS-I1 (14 slices em 4 sprints)

**Sprint 1 — Foundation (S1+S2+S3):**
- S1 (2h): glossary entry `long_horizon`.
- S2 (4-6h): tabela + model `ai_long_horizon_continuation_packs`.
- S3 (4-6h): tabela + model `ai_long_horizon_compaction_receipts`.
- Receipt: 2 schemas novos + 2 models + tests smoke.

**Sprint 2 — Builders (S4+S5+S6+S7):**
- S4 (10-14h): `LongHorizonContinuationPackBuilder` Dev side.
- S5 (12-16h): `AiCompactionService::compactForScope()` extend.
- S6 (8-12h): Forge hook `emitContinuationPack()`.
- S7 (4-6h): `ProgrammingResumeService::state()` extend.
- Receipt: 1 builder novo + 3 services estendidos + 4 tests novos.

**Sprint 3 — Gates + Recovery (S8+S9+S10+S11):**
- S8 (6-8h): Console actions `long-horizon:*`.
- S9 (6-10h): `LongHorizonContextFreshnessGate` greenfield.
- S10 (6-8h): `AtlasMemoryEntry::SCOPES` extend + promotion gate.
- S11 (16-24h): `LongHorizonRecoveryPlannerService` greenfield.
- Receipt: 1 Canon estendido + 2 services greenfield + 1 model extend +
  operator-review gate.

**Sprint 4 — Certification + Manifest (S12+S13+S14):**
- S12 (14-18h): `ContinuityCertification` service greenfield.
- S13 (10-14h): `ReplayManifest` builder/reader greenfield.
- S14 (6-8h): Control plane + telemetry projection extend.
- Receipt: 2 services greenfield + 2 services estendidos + 12 testes
  novos cumulativos.

**Total esforço I1**: ~110-160h. **Zero deprecação**, **zero rewrite**,
**zero benchmark run**, **zero rival call**.

### Definition of Done — Atlas TEOS-I1

A integração TEOS-I1 está **shipped** quando:

(1) `long_horizon` é entry no glossary canônico.
(2) Schemas `continuation_pack.v1` + `compaction_receipt.v1` shipped com
    tabelas/models/tests.
(3) `AiCompactionService::compactForScope()` enforce
    `must_keep_coverage==1.0`.
(4) `ProgrammingResumeService` retorna `pack_hash`/`stale_after`.
(5) `ForgeLongHorizonStateService` emite continuation_pack em
    milestone-end.
(6) `LongHorizonContextFreshnessGate` bloqueia resume stale.
(7) `LongHorizonRecoveryPlannerService` produz plano por drift trigger.
(8) `AtlasMemoryEntry::SCOPES` inclui `obra|long_horizon`; promotion gate
    operator-reviewed.
(9) `ContinuityCertification` enforce 8 invariants long-horizon.
(10) Console expõe `long-horizon:{compact,continue,status,freshness-gate}`.
(11) ReplayManifest builder+reader round-trip determinístico.
(12) Control plane snapshot carrega `long_horizon_summary`; telemetry
     reconhece event_names long-horizon.
(13) Tests novos (12) verdes; tests existentes (17) preservados verdes.
(14) `claim_policy.benchmark_not_run=true` em todos envelopes.

### Gates de fechamento desta doc

- `php artisan atlas:engineering:knowledge docs-health --json` → 0
  violations; sob `line_limit: 900`.
- `git diff --check` → limpo.
- **benchmark_not_run: confirmado** — esta doc é mapa de reuse, não
  execução.
