---
id: atlas-cognitive-function-atlas
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas CognitiveFunctionAtlas (Patamar 4 · 4.2)
slug: atlas-cognitive-function-atlas
status: building
implementation_state: runtime_available_read_model
category: cognition
priority: 93
summary: Read-model do ACOS que projeta o scorecard cognitivo em self-model, grupos, gaps e shape sem criar registry paralelo de subsistemas.
tags: [atlas-ai, acos, cognition, read-model, patamar-4]
capabilities: [acos_self_model_projection, cognitive_group_taxonomy, cognitive_gap_read_model, subsystem_owner_lookup]
decisions:
  - Cognitive Function Atlas e lente read-only sobre AtlasCognitionScoreCardService.
  - Nenhum metodo deve hardcodar registry de subsistemas fora do scorecard.
  - Consumers autonomos podem consultar gaps e grupos, mas nao promover esse read-model a fonte autoral.
maintenance:
  - Atualizar quando o scorecard ACOS mudar schema, grupos ou readiness dimensions.
  - Manter testes provando contagem canonica, grupos, gaps e determinismo.
risk_level: medium
owner: atlas-ai
graph_id: atlas-cognitive-function-atlas
graph_title: Atlas Cognitive Function Atlas
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on:
  - atlas-cognition-operating-system
  - atlas-constitutional-kernel
authority_class: read_model
related_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-function-atlas.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - app/Services/Ai/Cognition/AtlasCognitiveFunctionAtlasService.php
  - app/Services/Ai/Cognition/AtlasCognitionScoreCardService.php
  - tests/Unit/Ai/Cognition/AtlasCognitiveFunctionAtlasServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-cognitive-function-atlas.md
  - app/Services/Ai/Cognition/AtlasCognitiveFunctionAtlasService.php
flows_to: [atlas-autonomous-reconciliation-runtime, atlas-swarm-conductor, atlas-teos-i4-counterfactual-tree]
unlocks: [acos_self_model, cognitive_gap_queries]
governs: [cognitive_self_model_projection]
evidence:
  - app/Services/Ai/Cognition/AtlasCognitiveFunctionAtlasService.php
  - tests/Unit/Ai/Cognition/AtlasCognitiveFunctionAtlasServiceTest.php
evidence_refs:
  - symbol: AtlasCognitiveFunctionAtlasService
  - command: atlas:cognitive-function
  - test: AtlasCognitiveFunctionAtlasServiceTest
required_tests:
  - "php artisan test tests/Unit/Ai/Cognition/AtlasCognitiveFunctionAtlasServiceTest.php"
  - "php artisan atlas:cognition:scorecard --strict --json"
next_actions:
  - Adicionar novas queries somente se derivadas do scorecard ACOS.
  - Provar consumidores autonomos usando esta lente sem criar registry paralelo.
allowed_changes:
  - Add query projections derived from AtlasCognitionScoreCardService only.
forbidden_changes:
  - duplicate_subsystem_registry
  - introduce_independent_source_of_truth_for_subsystems
  - mutate_scorecard_state_from_lens
requires_evidence: true
line_limit: 520
schema:
  - atlas.cognitive_function_atlas.self_model.v1
  - atlas.cognitive_function_atlas.group_summary.v1
---

# Atlas CognitiveFunctionAtlas — Patamar 4 · 4.2 (self-model read-only)

## Resumo

Read-model do ACOS que projeta scorecard, grupos e gaps para consumidores autonomos.

## Papel no Atlas

Permitir que runtimes Patamar 4 entendam a forma cognitiva atual sem criar novo registry.

## Onde Se Encaixa

Fica acima do AtlasCognitionScoreCardService e abaixo dos consumidores Reconciliation, Swarm e TEOS-I4.

## Contratos

Schemas `atlas.cognitive_function_atlas.self_model.v1` e `atlas.cognitive_function_atlas.group_summary.v1`.

## Fluxo

Scorecard ACOS -> self model -> group taxonomy/gaps/shape -> consumer read-only.

## Regras para IA

Nao hardcodar subsistemas aqui. Nao tratar esta lente como fonte de verdade.

## Escopo de Implementacao

Service read-only e testes unitarios existem; sem storage proprio.

## Dependencias

ACOS scorecard e Constitutional Kernel.

## Evidencias

Service `AtlasCognitiveFunctionAtlasService` e teste `AtlasCognitiveFunctionAtlasServiceTest`.

## Riscos

Virar registry paralelo e entrar em drift com ACOS.

## Exemplos

Use `gapsByGroup()` para priorizar reconciliacao; use `whichGroupOwns()` para lookup de ownership.

## Proximas Acoes

Conectar consumidores com testes que provem leitura derivada do scorecard.

## Por que existe (e por que NÃO é registry novo)

`AtlasCognitionScoreCardService::SUBSYSTEMS` já é o registry canônico dos 36 subsistemas ACOS. Qualquer "self-model" novo que reimplemente esse registry vira drift garantido na primeira PR.

A função real do CognitiveFunctionAtlas em Patamar 4 não é "saber quais subsistemas existem" (ScoreCard já sabe). É **projetar essa informação em queries que consumidores autônomos precisam fazer**:

- "Qual o estado geral da minha cognição agora?" (Reconciliation Runtime)
- "Que grupo possui o subsystem X?" (ASCB antes de propor novo)
- "Onde estão as maiores lacunas?" (ADML escolhendo onde aprender)
- "Esse grupo está sobrecarregado?" (Swarm Conductor distribuindo trabalho)

**É lente / read-model**, não fonte de verdade.

## Princípio de não-duplicação (canon)

| Conceito                | Fonte canon (NÃO duplicar)                                          |
|-------------------------|---------------------------------------------------------------------|
| Subsystem registry      | `AtlasCognitionScoreCardService::SUBSYSTEMS` / `build()`            |
| Code probe              | `AtlasCognitionScoreCardService::probeCodeStatus()`                 |
| Doc/pipeline status     | Tuple do registry                                                   |
| Aggregated score        | `AtlasCognitionScoreCardService::aggregateScore()`                  |
| Pétreo invariants       | `AtlasConstitutionalKernelService::listInvariants()`                |

Regra absoluta: **nenhum método deste serviço pode listar subsistemas hardcoded.** Tudo deriva de `ScoreCardService->build()`.

## API

```php
selfModel(): array               // atlas.cognitive_function_atlas.self_model.v1
groupTaxonomy(): array           // lista de grupos distintos
subsystemsByGroup(string $group): array
gapsByGroup(): array             // grupos × count(non-ready)
whichGroupOwns(string $acronym): ?string
cognitiveShape(): array          // contagem por maturity bucket por grupo
isGroupOverloaded(string $group, int $threshold = 8): bool
```

### `selfModel()` envelope

```json
{
  "schema_version": "atlas.cognitive_function_atlas.self_model.v1",
  "generated_at": "...",
  "subsystem_count": 36,
  "group_count": N,
  "groups": ["cognitive_immune", "memory_core", "aucri", "self_improvement", "atlas_decide", "self_construction", "reality", "cross_domain", "teos"],
  "shape": [
    { "group": "cognitive_immune", "total": 9, "ready": 7, "partial": 2, "building": 5, "service_present": 9 },
    ...
  ],
  "gaps": [
    { "group": "aucri", "non_ready_pipeline": 6 },
    ...
  ],
  "overall_score": { ... },          // delegate ao ScoreCard
  "kernel_hash": "sha256:..."        // delegate ao Constitutional Kernel
}
```

## Como consumidores Patamar 4 vão usar

| Consumidor                                | Query principal                                                    |
|-------------------------------------------|--------------------------------------------------------------------|
| `AtlasSelfConstructionSubsystemBuilderService` (ASCB) | `whichGroupOwns()` + `isGroupOverloaded()` antes de propor.   |
| `AtlasDecideMetaLearningService` (ADML)              | `gapsByGroup()` para alocar attention de routing.             |
| Autonomous Reconciliation Runtime (4.3)              | `selfModel()` para diff vs estado anterior.                   |
| Swarm Conductor (4.5)                                | `cognitiveShape()` para balancear arms.                       |
| TEOS-I4 (4.4)                                        | `selfModel()` para snapshot de baseline antes de simulações.  |

## Storage

**Nenhum.** Read-only. Estado é derivado em runtime de `ScoreCardService`. Cache opcional pode ser adicionado depois (in-memory, escopo-de-request), mas não é canon.

## Não-objetivos

- Não persiste estado.
- Não mantém registry próprio.
- Não muta scorecard.
- Não substitui Constitutional Kernel (pétreos) nem ARPTL (privacy).
- Não emite tickets — não é gate, é introspecção.

## Replay / audit

Cada chamada de `selfModel()` é determinística para o mesmo estado do ScoreCard. `kernel_hash` no envelope permite cross-reference com Constitutional Kernel.
