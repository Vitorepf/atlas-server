---
id: atlas-teos-existing-code-map
type: engineering_knowledge
title: Atlas TEOS Existing Code Map
status: active
category: programming
priority: 95
summary: Mapa técnico do código já implementado que TEOS-I1 deve reutilizar/estender em vez de duplicar. Por componente cita arquivo:classe, função atual, relação com TEOS, classificação (reuse|extend|adapter|deprecate_later|do_not_touch|greenfield), gaps, riscos, teste existente e alteração recomendada. Inclui regras anti-duplicação, ordem recomendada de implementação e mapa de risco. Não implementa código, não roda benchmark.
tags:
  - atlas-teos
  - long-horizon
  - code-map
  - anti-duplication
  - 2026-05-18
capabilities:
  - existing_code_inventory
  - anti_duplication_rules
  - implementation_order
  - test_reuse_matrix
  - risk_map
decisions:
  - TEOS-I1 reutiliza 11 componentes existentes; só 4-5 são realmente greenfield.
  - Compactação Atlas Dev evolui `AiCompactionService` em vez de criar `LongHorizonCompactionEngine` paralelo.
  - Continuation pack unificado é abstração nova ACIMA de `atlas.programming.continuation_packet.v1` e `atlas.forge.long_horizon_state.v1`, sem deprecar nenhum.
  - Decision ledger reutiliza `AtlasLedgerEvent` (append-only enforced) em vez de criar tabela paralela.
  - Memory store reutiliza `AtlasMemoryEntry` com scopes novos `obra`/`long_horizon` em vez de novo store.
  - Benchmark runner é o `BenchmarkReadinessHarness` existente; TEOS nunca cria runner próprio.
maintenance:
  - Atualizar quando um componente promover classificação (greenfield → extend → reuse).
  - Sincronizar com `atlas-long-horizon-intelligence-layer.md` após cada slice TEOS.
  - Re-verificar arquivo:linha quando refactor mover símbolos.
related_paths:
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-teos-existing-code-map
graph_title: Atlas TEOS Existing Code Map
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-long-horizon-intelligence-layer
graph_status: active
graph_source: repo
human_name: Atlas TEOS Existing Code Map
canonical_name: Atlas TEOS Existing Code Map
technical_name: atlas-teos-existing-code-map
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
allowed_changes:
  - Promover componente: greenfield → extend → reuse.
  - Adicionar novo componente quando shippado em código.
  - Ajustar arquivo:linha quando refactor move símbolos.
forbidden_changes:
  - Declarar `reuse` sem grep que prove uso em produção.
  - Esconder gap (downgrade fake de greenfield para extend).
  - Aprovar duplicação porque "é mais simples".
depends_on:
  - atlas-long-horizon-intelligence-layer
  - atlas-programming-superiority-architecture
  - atlas-programming-superiority-contracts
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-programming-superiority-roadmap
unlocks:
  - teos_i1_implementation_kickoff
governs:
  - atlas_teos_existing_code_reuse
evidence:
  - app/Services/Ai/AiCompactionService.php
  - app/Services/Ai/AiSessionStateService.php
  - app/Services/Ai/Programming/ProgrammingResumeService.php
  - app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
  - app/Models/AtlasLedgerEvent.php
  - app/Models/AiAuditEvent.php
  - app/Models/AtlasMemoryEntry.php
  - app/Models/AiCodebaseWorldModel.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentMemoryIngestionService.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - app/Services/Ai/ProgrammingRuntime/ControlPlane/ProgrammingRuntimeControlPlaneService.php
  - app/Services/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarness.php
  - app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Cravar L1 (`long_horizon` glossary entry) antes de qualquer commit TEOS.
  - Materializar L2 (continuation_pack + compaction_receipt tables + models) como primeiro slice greenfield.
  - Migrar `AiCompactionService` para suportar `scope_type ∈ {thread, dev_workstream, forge_obra, work_packet, milestone}` antes de qualquer escrita paralela.
  - Validar que TEOS commands sempre carregam `claim_policy.benchmark_not_run=true` (regra herdada de `ProgrammingConsoleService`).
line_limit: 520
---
# Atlas TEOS Existing Code Map

## Resumo

**Pergunta canônica:** *onde encaixar TEOS-I1 sem criar sistema paralelo?*

**Resposta operacional:** 15 componentes inventariados; **11 são reuse/extend**,
**4 são greenfield real** (LongHorizonContextFreshnessGate,
LongHorizonRecoveryPlannerService, ReplayManifest builder/reader,
ContinuityCertification service) e **1 é greenfield opcional**
(CausalDecisionGraph lite, depende de Decision Ledger ampliado). Compactação,
continuation, long-horizon state, ledger append-only, memory governance,
world model, certification, telemetry, control plane e benchmark readiness
já estão **shipados em produção** com testes próprios.

**Top-3 riscos de duplicação:** (1) criar tabela `ai_long_horizon_compactions`
paralela a `ai_compactions`; (2) criar `LongHorizonDecisionLedger` paralelo a
`AtlasLedgerEvent`; (3) criar `LongHorizonMemoryStore` paralelo a
`AtlasMemoryEntry`. Os três são proibidos por `Anti-Duplication Rules` (§14).

**benchmark_not_run: true** — TEOS é substrate de benchmark, não benchmark
em si. O harness existente já garante esse invariante; TEOS nunca toca o
runner.

## Papel no Atlas

Doc é **mapa técnico de reuse**, não roadmap nem contrato. Existe para
3 audiências:

1. **Implementador TEOS-I1** — saber onde colocar cada feature antes de criar
   classe nova. Cada componente carrega `Alteração recomendada` específica.
2. **Reviewer** — bloquear PR que duplique `AtlasLedgerEvent`,
   `AtlasMemoryEntry`, `AiCompactionService` ou `ForgeLongHorizonStateService`.
3. **Operador** — entender que TEOS não é um produto novo; é uma *extensão
   coordenada* do que já roda.

Complementa `atlas-long-horizon-intelligence-layer.md` (que define schemas e
roadmap). Esta doc cita arquivo:classe.

### Classification Legend

| Tag | Significado | Quando usar |
|-----|-------------|-------------|
| `reuse` | Componente serve TEOS as-is; usar sem modificar. | Schema/serviço estável com testes próprios. |
| `extend` | Adicionar campo/método sem quebrar callers existentes. | Coluna nova, scope novo, parâmetro opcional. |
| `adapter` | TEOS escreve um wrapper read-only acima. | Não modificar o original; expor via projection. |
| `deprecate_later` | Funciona, mas TEOS-I2/I3 absorverá; manter compat. | Surface legacy ainda em uso. |
| `do_not_touch` | Estável e fora do escopo TEOS. | Camadas Kernel/runtime crítico. |
| `greenfield` | Não existe; precisa nascer dentro do escopo TEOS. | Sem código matching, sem AP equivalente. |

## Onde Se Encaixa

Layer 0.72 Programming, filho de `atlas-long-horizon-intelligence-layer`.
TEOS-I1 vive na intersecção de 4 camadas existentes:

```text
                +-----------------------------+
                |  Atlas TEOS / Long-Horizon  |   <- esta camada
                +-----------------------------+
                  |        |        |        |
       +----------+ +------+ +------+ +------+----------+
       |            |        |        |                 |
   Compaction   Resume    Forge    Evidence/         Memory /
   (Dev/Forge)  /Stage    LH State Ledger            World Model
       |            |        |        |                 |
   AiCompaction ProgrammingResume Forge*  AtlasLedger  AtlasMemoryEntry
   AiSessionState                                       AiCodebaseWorldModel*
```

TEOS não substitui essas camadas — sequência-as via `continuation_pack.v1`
unificado e `compaction_receipt.v1` cross-scope. Boundary dual-core
preservado: Dev e Forge mantêm runtimes próprios.

## Contratos

### Schemas existentes a reutilizar (não duplicar)

| Schema | Fonte | Classificação |
|--------|-------|---------------|
| `atlas.programming.continuation_packet.v1` | `ProgrammingResumeService:69` | `reuse` (Dev run-level) |
| `atlas.forge.long_horizon_state.v1` | `ForgeLongHorizonStateCanon:21` | `reuse` (Obra-scoped) |
| `atlas.forge.work_packet_execution_cycle.v1` | `ForgeWorkPacketExecutionCycleCanon:29` | `reuse` (cycle history) |
| `atlas.ledger_event.v1` | `AtlasEvidenceLedger::SCHEMA_VERSION` | `reuse` (append-only) |
| `atlas.programming.stage_receipt.v1` | `AtlasProgrammingStageReceipt` | `reuse` (Dev stage timeline) |
| `atlas.ai.local_agent_ingestion.{run,source,candidate,receipt}.v1` | `LocalAgentMemoryIngestionCanon:21-27` | `reuse` (memory ingestion) |
| `atlas.programming.codebase_world_model.ranking.v1` | `WorldModelGraphRanker::SCHEMA` | `reuse` (graph ranking) |
| `atlas.programming.benchmark_suite.readiness.v1` | `BenchmarkReadinessCanon::SUITE_SCHEMA_VERSION` | `reuse` (suite manifest) |
| `atlas.programming.runtime_control_plane.v1` | `ProgrammingRuntimeControlPlaneCanon` | `reuse` (read model) |
| `atlas.programming.runtime_telemetry.aggregate.v1` | `ProgrammingRuntimeTelemetryCanon` | `reuse` (telemetry) |
| `atlas.programming.console.v1` | `ProgrammingConsoleCanon::SCHEMA_VERSION` | `reuse` (canonical envelope) |
| `atlas.ai.compounding.{outcome,memory,heuristic_update}.v1` | `app/Services/Ai/Compounding/` (11 services) | `reuse` (compounding) |

### Schemas novos canônicos TEOS-I1 (greenfield)

| Schema | Aterrissa em | Classificação |
|--------|--------------|---------------|
| `atlas.long_horizon.continuation_pack.v1` | tabela nova `ai_long_horizon_continuation_packs` | `greenfield` |
| `atlas.long_horizon.compaction_receipt.v1` | tabela nova `ai_long_horizon_compaction_receipts` | `greenfield` |
| `atlas.long_horizon.context_freshness_gate.v1` | output do `LongHorizonContextFreshnessGate` | `greenfield` |
| `atlas.long_horizon.recovery_plan.v1` | output do `LongHorizonRecoveryPlannerService` | `greenfield` |
| `atlas.long_horizon.replay_manifest.v1` | output do ReplayManifest builder/reader | `greenfield` |
| `atlas.long_horizon.continuity_certification.v1` | output do ContinuityCertification service | `greenfield` |

## Fluxo

1. Ler este index.
2. Abrir os recortes filhos indicados em `Detalhes Extraidos`.
3. Classificar o componente alvo como `reuse`, `extend`, `adapter`,
   `deprecate_later`, `do_not_touch` ou `greenfield`.
4. Implementar somente no ponto autorizado.
5. Rodar docs-health e testes do componente afetado.

## Regras Para IA

- Consultar este mapa antes de criar classe, migration ou schema TEOS.
- Reutilizar componentes marcados como `reuse`.
- Estender componentes marcados como `extend` sem quebrar callers.
- Não duplicar ledger, memory store, compaction engine ou benchmark runner.
- Não declarar benchmark, superioridade externa ou TEOS completo a partir desta doc.

## Escopo De Implementacao

Dentro do escopo: inventário, classificação, anti-duplicação, ordem de reuse e
gates mínimos para TEOS-I1.

Fora do escopo: execução de benchmark, provider real, UI, criação de runtime
novo ou mudança de boundary Dev/Forge.

## Dependencias

- `atlas-long-horizon-intelligence-layer.md`
- `atlas-programming-superiority-architecture.md`
- `atlas-programming-superiority-contracts.md`
- `atlas-canonical-glossary-and-naming.md`

## Evidencias

Evidência aceitável é grep/read direto em arquivos de código, testes verdes e
docs-health. Evidência fraca inclui memória de conversa, intenção de roadmap ou
nomes parecidos sem caller real.

## Riscos

- Duplicar sistema já existente por não consultar recorte filho.
- Promover componente `greenfield` para `reuse` sem evidência.
- Misturar north-star TEOS com implementação incremental.
- Criar nova nomenclatura fora do glossário canônico.

## Exemplos

Exemplo correto: estender `AiCompactionService` para escopo long-horizon.

Exemplo incorreto: criar `LongHorizonCompactionEngine` paralelo sem deprecação,
migração e ADR.

## Proximas Acoes

1. Manter os recortes filhos sincronizados com mudanças de código.
2. Atualizar classificação quando componente sair de `greenfield`.
3. Rodar `php artisan atlas:engineering:knowledge docs-health --json` após cada
   alteração.

## Detalhes Extraidos

O inventário técnico detalhado foi movido para recortes filhos para manter este mapa navegável na cartografia. O pai continua sendo a porta de entrada canônica: ele explica pergunta, resposta, posição, contratos e schemas.

- `docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md` — Existing Components Map ate Não-greenfield (não criar serviço novo!).
- `docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md` — Anti-Duplication Rules ate Gates de fechamento desta doc.

### Regra De Manutencao

- Não criar componente novo antes de consultar o recorte correspondente.
- Não misturar inventário de código com roadmap ou contrato runtime.
- Ao atualizar arquivo/classe, revisar o recorte filho e rodar `php artisan atlas:engineering:knowledge docs-health --json`.
