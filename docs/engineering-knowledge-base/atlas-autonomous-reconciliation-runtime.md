---
id: atlas-autonomous-reconciliation-runtime
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Autonomous Reconciliation Runtime (Patamar 4 · 4.3)
slug: atlas-autonomous-reconciliation-runtime
status: building
implementation_state: runtime_available_integration_partial
category: autonomy
priority: 94
summary: Runtime de reconciliacao autonoma que orquestra self-model, autonomy admission e AURG-4D em ticks append-only sem duplicar gap detection, policy, kernel ou tick authority.
tags: [atlas-ai, autonomy, reconciliation, patamar-4, governance]
capabilities: [autonomous_reconciliation_tick, reconciliation_step_envelope, aurg_tick_bridge, append_only_reconciliation_log]
decisions:
  - Reconciliation Runtime e composer; nao substitui ASCB, ADML, Constitutional Kernel, Autonomy Admission ou AURG-4D.
  - Tick pode registrar auto_applied em v1 como outcome de envelope, mas execucao real de mudanca continua fora deste runtime.
  - Todo claim de loop fechado precisa apontar para tick, admission envelope e AURG-4D evidence.
maintenance:
  - Atualizar antes de mudar schemas, tick outcomes, cadence, storage ou consumidores Patamar 4.
  - Manter lista de dependencias alinhada aos services reais e testes unitarios.
risk_level: high
owner: atlas-ai
graph_id: atlas-autonomous-reconciliation-runtime
graph_title: Atlas Autonomous Reconciliation Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-constitutional-kernel
graph_status: building
graph_source: repo
depends_on:
  - atlas-cognitive-function-atlas
  - atlas-autonomy-admission
  - atlas-constitutional-kernel
  - atlas-aurg-temporal-4d
  - atlas-ai-self-construction-os
authority_class: composer
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-reconciliation-runtime.md
  - docs/engineering-knowledge-base/atlas-cognitive-function-atlas.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-aurg-temporal-4d.md
  - app/Services/Ai/Reconciliation/AtlasAutonomousReconciliationRuntimeService.php
  - tests/Unit/Ai/Reconciliation/AtlasAutonomousReconciliationRuntimeServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-reconciliation-runtime.md
  - app/Services/Ai/Reconciliation/AtlasAutonomousReconciliationRuntimeService.php
flows_to: [atlas-ai-self-construction-os, atlas-cognition-operating-system]
unlocks: [reconciliation_tick_audit, autonomous_gap_reconciliation_preflight]
governs: [reconciliation_ticks, reconciliation_step_envelopes]
evidence:
  - app/Services/Ai/Reconciliation/AtlasAutonomousReconciliationRuntimeService.php
  - tests/Unit/Ai/Reconciliation/AtlasAutonomousReconciliationRuntimeServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Reconciliation/AtlasAutonomousReconciliationRuntimeServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Adicionar CLI de tick/summary somente com testes provando Admission e AURG.
  - Integrar execucao real em fatia separada, sem mover ownership de ASCB ou ADML.
allowed_changes:
  - Add CLI or consumer integrations with tests proving Admission and AURG tick boundaries.
forbidden_changes:
  - bypass_autonomy_admission
  - apply_step_without_kernel_validation
  - mutate_subsystems_without_aurg_tick
  - silent_step_apply
requires_evidence: true
line_limit: 520
schema:
  - atlas.autonomous_reconciliation.tick.v1
  - atlas.autonomous_reconciliation.step.v1
---

# Atlas Autonomous Reconciliation Runtime — Patamar 4 · 4.3

## Resumo

Runtime de reconciliacao autonoma em ticks append-only. Ele seleciona gaps do self-model, passa pelo Autonomy Admission e registra AURG-4D sem virar owner de gap detection, policy ou execucao.

## Papel no Atlas

Compor o loop fechado Patamar 4 como orquestrador fino. Nao aplica mudanca real nesta fatia; registra outcome e evidencia para revisao.

## Onde Se Encaixa

Fica abaixo do Constitutional Kernel e do Autonomy Admission, consumindo Cognitive Function Atlas e AURG-4D.

## Contratos

Schemas `atlas.autonomous_reconciliation.tick.v1` e `atlas.autonomous_reconciliation.step.v1`.

## Fluxo

`selfModel` -> gap prioritario -> admission -> tick AURG-4D -> JSONL local append-only.

## Regras para IA

Nao criar segundo runtime de reconciliacao. Nao dizer que executa mudancas reais ate haver teste e owner doc da fatia de execucao.

## Escopo de Implementacao

Service e testes unitarios existem; CLI e execucao real permanecem proximas acoes.

## Dependencias

Cognitive Function Atlas, Autonomy Admission, Constitutional Kernel, AURG-4D e Self-Construction OS.

## Evidencias

Service `AtlasAutonomousReconciliationRuntimeService` e teste `AtlasAutonomousReconciliationRuntimeServiceTest`.

## Riscos

Confundir outcome `auto_applied` do envelope com execucao mutativa real.

## Exemplos

Use `tick()` para registrar um ciclo; use `summary()` para tally por outcome.

## Proximas Acoes

Implementar CLI e provar a fatia de execucao real com gates antes de qualquer claim de autonomia plena.

## Por que existe

Patamar 4 prometeu **loop fechado**: Atlas detecta gap → propõe ação → valida pétreo → admite autonomia → aplica (ou pede aprovação) → registra tick imutável → aprende. Hoje cada peça existe; falta o **runtime que orquestra**.

Este serviço **não** reinventa ASCB, ADML, Kernel, Admission, AURG-4D ou Approval. É o "motor da batida" que chama esses serviços na ordem certa, com timing, threshold e gates honestos.

## Princípio de não-duplicação (canon)

| Conceito                                  | Fonte canon (NÃO duplicar)                                                |
|-------------------------------------------|---------------------------------------------------------------------------|
| Self-model / gaps                         | `AtlasCognitiveFunctionAtlasService::selfModel()` / `gapsByGroup()`        |
| Propose change                            | `AtlasSelfConstructionSubsystemBuilderService::propose()` (existing ASCB) |
| Routing recommendation                    | `AtlasDecideMetaLearningService::recommend()` (existing ADML)             |
| Pétreo validation                         | `AtlasConstitutionalKernelService::validateChange()`                      |
| Autonomy admission                        | `AtlasAutonomyAdmissionService::admit()`                                  |
| Tick imutável                             | `AtlasUnifiedRealityGraphTemporalService::recordTick()`                   |
| Approval workflow                         | `App\Services\Ai\Policy\ApprovalRequestService` (futura integração)       |

Regra: nenhum método deste runtime contém lógica de gap-detection, propose, validate, admit ou tick. Ele **chama** as fontes canon.

## API

```php
tick(?array $context = null): array         // executa 1 ciclo de reconciliação
listTicks(): array                           // ticks de reconciliação (append-only)
lastTick(): ?array
summary(): array                             // digest de saúde do runtime
```

### `tick()` semântica

1. Lê `selfModel()` do CognitiveFunctionAtlas.
2. Lê `gapsByGroup()` — escolhe pior grupo (maior `non_ready_pipeline`).
3. Constrói **change proposal**: change_kind=`reconciliation_step`, scope, proposed_effect descrevendo "estabilizar pipeline do grupo X".
4. Passa por `AtlasAutonomyAdmissionService::admit()` (que já chama Constitutional Kernel internamente).
5. Decide outcome:
   - `allow_autonomous` → registra step como `auto_applied` (v1: marca; execução real é hook out-of-scope).
   - `allow_with_approval` → registra como `pending_approval`.
   - `deny` → registra como `blocked_by_kernel`.
6. Grava AURG-4D tick (kind=`rationale_event`) para audit imutável.
7. Persiste tick local (`storage/atlas/reconciliation/ticks.jsonl`) — append-only.

### Tick envelope

```json
{
  "schema_version": "atlas.autonomous_reconciliation.tick.v1",
  "tick_id": "rcn_...",
  "at": "ISO",
  "self_model_hash": "sha256:...",
  "selected_group": "aucri",
  "selected_gap_size": 12,
  "step": {
    "schema_version": "atlas.autonomous_reconciliation.step.v1",
    "step_kind": "stabilize_pipeline",
    "scope": { "group": "aucri", "privacy_class": "normal" },
    "admission_envelope": {...},   // delegate AutonomyAdmission
    "aurg_tick_id": "tick_..."     // delegate AURG-4D
  },
  "outcome": "auto_applied|pending_approval|blocked_by_kernel|noop_no_gap",
  "tick_hash": "sha256:..."
}
```

### `noop_no_gap`

Quando `gapsByGroup()` retorna vazio (todos os subsistemas pipeline=ready), tick registra `outcome=noop_no_gap`. **Não força ação inventada.**

## Storage

- `storage/atlas/reconciliation/ticks.jsonl` — append-only.
- Cada tick chama `AtlasUnifiedRealityGraphTemporalService::recordTick()` que mantém hash chain própria em `storage/atlas/aurg/temporal_ticks.jsonl`.

## CLI (próxima fatia)

```bash
php artisan atlas:reconciliation:tick [--json]
php artisan atlas:reconciliation:summary [--json]
```

## Não-objetivos

- v1 **não executa** o subsystem builder de verdade — marca step como aplicado. Execução real fica pra fatia 4.3.b após validar o loop end-to-end com runs reais.
- Não substitui ASCB / ADML / Kernel / Admission — orquestra.
- Não grava no DB — JSONL local-first.
- Não decide pétreos — delega ao Kernel via Admission.

## Replay / audit

- Cada tick é hash-encadeado em AURG-4D.
- `listTicks()` é fonte canon de auditoria.
- `summary()` calcula tally por outcome — sem julgamento, só contagem.
