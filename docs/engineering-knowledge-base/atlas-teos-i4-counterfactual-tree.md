---
id: atlas-teos-i4-counterfactual-tree
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas TEOS-I4 Counterfactual Tree (Patamar 4 · 4.4)
slug: atlas-teos-i4-counterfactual-tree
status: building
implementation_state: runtime_available_i3_composer
category: teos
priority: 92
summary: Composer TEOS-I4 que expande uma arvore contrafactual usando TEOS-I3, Kernel e Admission sem reimplementar scoring, alternatives ou execucao.
tags: [atlas-ai, teos, counterfactual, tree, patamar-4]
capabilities: [teos_i4_counterfactual_tree, i3_branch_composition, counterfactual_best_path, admission_gated_tree_expansion]
decisions:
  - TEOS-I4 compoe chamadas de TEOS-I3; branch scoring e alternative kinds continuam em I3.
  - Arvore contrafactual nunca executa alternativa; apenas projeta e recomenda.
  - Kernel e Admission precisam existir antes de qualquer recomendacao operacional.
maintenance:
  - Atualizar antes de mudar breadth/depth caps, node schema, I3 integration ou admission behavior.
  - Manter testes cobrindo caps, kernel block, best path e referencias a branch_id I3.
risk_level: medium
owner: atlas-ai
graph_id: atlas-teos-i4-counterfactual-tree
graph_title: Atlas TEOS-I4 Counterfactual Tree
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-teos-i3-counterfactual
graph_status: building
graph_source: repo
depends_on:
  - atlas-teos-i3-counterfactual
  - atlas-constitutional-kernel
  - atlas-autonomy-admission
authority_class: composer
related_paths:
  - docs/engineering-knowledge-base/atlas-teos-i4-counterfactual-tree.md
  - docs/engineering-knowledge-base/atlas-teos-i3-counterfactual.md
  - docs/engineering-knowledge-base/atlas-constitutional-kernel.md
  - docs/engineering-knowledge-base/atlas-autonomy-admission.md
  - app/Services/Ai/Teos/AtlasTeosI4CounterfactualTreeService.php
  - app/Services/Ai/Teos/AtlasTeosI3CounterfactualService.php
  - tests/Unit/Ai/Teos/AtlasTeosI4CounterfactualTreeServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-i4-counterfactual-tree.md
  - app/Services/Ai/Teos/AtlasTeosI4CounterfactualTreeService.php
flows_to: [atlas-cognition-operating-system]
unlocks: [counterfactual_tree_expansion, teos_i4_best_path_projection]
governs: [teos_i4_tree_envelopes, teos_i4_nodes]
evidence:
  - app/Services/Ai/Teos/AtlasTeosI4CounterfactualTreeService.php
  - tests/Unit/Ai/Teos/AtlasTeosI4CounterfactualTreeServiceTest.php
evidence_refs:
  - symbol: AtlasTeosI4CounterfactualTreeService
  - command: atlas:teos-i4
required_tests:
  - "php artisan test tests/Unit/Ai/Teos/AtlasTeosI4CounterfactualTreeServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
next_actions:
  - Integrar recomendacoes TEOS-I4 ao self-improvement sem executar alternativas.
  - Provar replay tree + branches I3 em teste de integracao antes de claim operacional.
allowed_changes:
  - Add tree policies only when delegated to TEOS-I3 or explicitly documented as I4 composition.
forbidden_changes:
  - duplicate_branch_scoring_logic
  - bypass_teos_i3_engine
  - silent_tree_expansion
  - autonomous_apply_without_kernel
requires_evidence: true
line_limit: 520
schema:
  - atlas.teos_i4.tree_envelope.v1
  - atlas.teos_i4.node.v1
---

# Atlas TEOS-I4 Counterfactual Tree — Patamar 4 · 4.4

## Resumo

Composer de arvore contrafactual que usa TEOS-I3 como owner de branch e scoring.

## Papel no Atlas

Explorar lookahead em arvore para recomendacao, sem executar alternativas e sem duplicar I3.

## Onde Se Encaixa

Fica acima de TEOS-I3 e abaixo de self-improvement/admission gates.

## Contratos

Schemas `atlas.teos_i4.tree_envelope.v1` e `atlas.teos_i4.node.v1`.

## Fluxo

Kernel -> I3 branch por node -> best path -> Admission -> JSONL tree.

## Regras para IA

Nao redefinir scoring, alternative kinds ou factual/counterfactual boundary.

## Escopo de Implementacao

Service e testes unitarios existem; execucao de alternativa fica fora de escopo.

## Dependencias

TEOS-I3, Constitutional Kernel e Autonomy Admission.

## Evidencias

Service `AtlasTeosI4CounterfactualTreeService` e teste `AtlasTeosI4CounterfactualTreeServiceTest`.

## Riscos

Promover branch contrafactual a fato ou usar best path como execucao automatica.

## Exemplos

Use `expand()` para gerar tree e `bestPath()` para replay do caminho escolhido.

## Proximas Acoes

Adicionar teste de integracao com self-improvement antes de qualquer uso operacional.

## Por que existe

TEOS-I3 já calcula uma branch contrafactual por chamada (anchor + 1 alternativa + projeção em profundidade ≤6). O que falta no Patamar 4 é **lookahead em árvore**: explorar K alternativas por anchor × N níveis, escolher o **caminho** com melhor improvement esperado, e ainda atravessar o Constitutional Kernel antes de propor execução.

TEOS-I4 = **árvore de I-3**. Não recalcula scoring; chama I-3 para cada branch da árvore.

## Princípio de não-duplicação (canon)

| Conceito                          | Fonte canon (NÃO duplicar)                                  |
|-----------------------------------|-------------------------------------------------------------|
| Branch single-step scoring        | `AtlasTeosI3CounterfactualService::branch()`                |
| Recommendation envelope           | `AtlasTeosI3CounterfactualService::recommendReplan()`       |
| Pétreo validation                 | `AtlasConstitutionalKernelService::validateChange()`        |
| Autonomy admission                | `AtlasAutonomyAdmissionService::admit()`                    |
| Alternative kinds                 | `AtlasTeosI3CounterfactualService::VALID_ALTERNATIVE_KINDS` |

Regra: I-4 NÃO redefine alternative kinds, scoring, ou recommendation. Apenas compõe múltiplas chamadas de I-3 em uma estrutura de árvore.

## API

```php
expand(array $input): array         // atlas.teos_i4.tree_envelope.v1
bestPath(string $treeId): array     // re-leitura do tree
listTrees(): array
```

### `expand` input

```json
{
  "scope": { "mission_id":"...", "work_order_id":"...", "obra_id":"..." },
  "anchor_decision_id": "decision_xyz",
  "alternatives": [
    { "decision_kind":"policy_swap", "value":"strict" },
    { "decision_kind":"provider_swap", "value":"local_only" },
    { "decision_kind":"escalation", "value":"operator" }
  ],
  "max_breadth": 3,              // K — alternativas por nó
  "max_depth": 3,                // N — níveis (cada nível chama I-3 com depth=1)
  "factual_outcome_score": 0.5,
  "projected_outcome_score": 0.75 // estimativa inicial; cada branch recalcula
}
```

### `expand` envelope

```json
{
  "schema_version": "atlas.teos_i4.tree_envelope.v1",
  "tree_id": "cft_...",
  "generated_at": "ISO",
  "anchor_decision_id": "...",
  "max_breadth": 3,
  "max_depth": 3,
  "node_count": N,
  "nodes": [
    { "schema_version": "atlas.teos_i4.node.v1", "node_id": "...", "parent_node_id": "...", "depth": K, "branch_id": "cf_...", "improvement_delta": 0.18 }
  ],
  "best_path": ["node_root","node_d1_a2","node_d2_a1"],
  "best_path_improvement": 0.32,
  "kernel_decision": "allow|block|allow_with_human_approval",
  "admission_decision": "allow_autonomous|allow_with_approval|deny",
  "tree_hash": "sha256:..."
}
```

### Algoritmo (curto)

- Greedy breadth-first: para cada nível, expande até `max_breadth` alternativas a partir do melhor nó do nível anterior.
- Cada expansão chama `AtlasTeosI3CounterfactualService::branch()` com depth=1 (I-3 já cobre branch scoring).
- Para de expandir cedo se atinge profundidade ou se Kernel bloqueia a continuação.
- `best_path` = caminho cumulativo com maior `sum(improvement_delta)`.

### Gates obrigatórios

1. **Constitutional Kernel** chamado uma vez sobre a operação inteira ("expandir árvore de profundidade N em scope X") antes de iniciar.
2. **Autonomy Admission** chamado sobre a recomendação final — define se a árvore é executável autonomamente ou exige aprovação.
3. **`is_counterfactual=true`** sempre em todos os nós (delegate I-3).

### Limites

- `max_breadth` ∈ [1, 5] (defensive cap; árvore explode rápido).
- `max_depth` ∈ [1, 4] (defensive cap).
- `node_count` ≤ `max_breadth × max_depth` por construção (greedy, não full BFS).

## Storage

- Append-only JSONL: `storage/atlas/teos_i4/trees.jsonl`
- I-3 branches gerados ao expandir ficam em `storage/atlas/teos_i3/branches.jsonl` (canon do I-3).

## Não-objetivos

- Não reescreve I-3.
- Não executa nenhuma alternativa — apenas projeta + recomenda.
- Não persiste no DB.
- Não substitui Constitutional Kernel / Admission.

## Replay / audit

- `tree_hash` permite verificação determinística.
- Cada node aponta para `branch_id` no I-3 ledger.
- Replay = ler `trees.jsonl` + `branches.jsonl` em paralelo.
