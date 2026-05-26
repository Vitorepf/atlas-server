---
id: atlas-teos-i3-counterfactual
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: TEOS-I3 Counterfactual Planning Runtime
status: active
implementation_state: runtime_scorecard_ready
category: teos
priority: 92
summary: Runtime TEOS-I3 para branches contrafactuais, recomendacao de replan e separacao explicita entre fato observado e simulacao alternativa.
tags: [atlas-ai, acos, teos, counterfactual, replanning]
capabilities: [counterfactual_branching, replan_recommendation, divergence_scoring, counterfactual_replan_human_approval_gate]
decisions:
  - Counterfactual sempre usa is_counterfactual=true e nao vira fato operacional.
  - Replan recommendation exige aprovacao humana antes de influenciar missao.
  - AURG Temporal e a linha factual de referencia para branches.
maintenance:
  - Atualizar antes de mudar schema de branch, recomendacao, depth cap ou comandos TEOS.
  - Manter scorecard ACOS sincronizado com service, comandos e testes reais.
related_paths:
  - docs/engineering-knowledge-base/atlas-aurg-temporal-4d.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - app/Services/Ai/Teos/AtlasTeosI3CounterfactualService.php
  - app/Console/Commands/AtlasTeosCounterfactualBranchCommand.php
  - app/Console/Commands/AtlasTeosCounterfactualRecommendCommand.php
  - tests/Unit/Ai/Teos/AtlasTeosI3CounterfactualServiceTest.php
owner: atlas-ai
graph_id: atlas-teos-i3-counterfactual
graph_title: TEOS-I3 Counterfactual Planning Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-aurg-temporal-4d
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-i3-counterfactual.md
  - app/Services/Ai/Teos/AtlasTeosI3CounterfactualService.php
depends_on: [atlas-aurg-temporal-4d, atlas-cognition-operating-system]
flows_to: [atlas-ai-self-improvement-os]
unlocks: [counterfactual_replan_recommendations, teos_i3_branch_replay]
governs: [counterfactual_branches, replan_recommendations]
evidence:
  - docs/engineering-knowledge-base/atlas-teos-i3-counterfactual.md
  - app/Services/Ai/Teos/AtlasTeosI3CounterfactualService.php
  - tests/Unit/Ai/Teos/AtlasTeosI3CounterfactualServiceTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:cognition:scorecard --strict --json"
  - "php artisan test tests/Unit/Ai/Teos/AtlasTeosI3CounterfactualServiceTest.php"
next_actions:
  - Manter recomendacoes contrafactuais em modo proposta ate aprovacao humana.
  - Integrar TEOS-I3 ao self-improvement sem misturar branch com fato.
allowed_changes:
  - Evoluir branch/recommendation schema com testes e owner decision.
  - Integrar sinais contrafactuais ao self-improvement sem promover como fato.
forbidden_changes:
  - Aplicar recomendacao contrafactual sem aprovacao humana.
  - Misturar branch contrafactual com Evidence Ledger factual.
requires_evidence: true
risk_level: high
line_limit: 520
---

# TEOS-I3 — Counterfactual Planning Runtime

> **Status**: canonical
> **Authority**: ACOS · Patamar 2/3 (Cognitive Maturity → Sovereign)
> **Schema**: `atlas.teos_i3.counterfactual_branch.v1` · `atlas.teos_i3.replan_recommendation.v1`
> **Service**: `App\Services\Ai\Teos\AtlasTeosI3CounterfactualService`
> **Owner**: this doc is the **source of truth**.

## 1. Why this exists

TEOS-I2 (already shipped as `LongHorizonCausalDecisionGraphService`) is a
read-model of *what happened*: nodes, edges, actual causal traversal.
TEOS-I3 is the next layer: "**what would have happened if decision D
had been D'**". The output is not a fact — it's a **branch** with a
divergence score and a replan recommendation.

This unlocks **automatic replan**: when an outcome is below threshold,
TEOS-I3 explores nearby counterfactuals and proposes the smallest change
that would have improved the trajectory.

## 2. Hard invariants

- **Counterfactuals are explicitly labelled `is_counterfactual=true`** —
  never mixed with factual nodes.
- **Branches are append-only** — no mutation of past branches.
- **No automatic application** — recommendation requires operator
  approval to influence next mission.
- **Provider-safe** — branches reference scope_ids and decision_ids, not
  raw content.
- **Divergence score is bounded** — `[0.0, 1.0]`.
- **No infinite branching** — depth cap `MAX_BRANCH_DEPTH = 6`.
- **AURG-temporal integration** — every branch ties back to a temporal
  tick from AURG-4D so replay can show "factual line vs branch line".

## 3. Counterfactual branch schema

`atlas.teos_i3.counterfactual_branch.v1`:

```json
{
  "schema_version": "atlas.teos_i3.counterfactual_branch.v1",
  "branch_id": "cf_<sha8>",
  "generated_at": "ISO-8601",
  "scope": {
    "mission_id": "uuid|null",
    "work_order_id": "uuid|null",
    "obra_id": "uuid|null"
  },
  "anchor_decision_id": "uuid",
  "alternative": {
    "decision_kind": "policy_swap | provider_swap | escalation | abort | replan",
    "value": "free-text or structured"
  },
  "projected_path": [
    {"node_kind": "decision|outcome|signal", "label": "...", "delta_vs_factual": "..."}
  ],
  "divergence_score": 0.42,
  "is_counterfactual": true,
  "factual_outcome_score": 0.6,
  "projected_outcome_score": 0.85,
  "branch_hash": "sha256:..."
}
```

## 4. Replan recommendation schema

`atlas.teos_i3.replan_recommendation.v1`:

```json
{
  "schema_version": "atlas.teos_i3.replan_recommendation.v1",
  "generated_at": "ISO-8601",
  "scope": {...},
  "trigger": "below_threshold_outcome | operator_request | regression_detected",
  "best_branch_id": "cf_...",
  "best_branch_summary": "...",
  "improvement_delta": 0.25,
  "confidence": "low|medium|high",
  "actionable": true,
  "requires_human_approval": true,
  "reason": ["below_threshold", "single_step_change_unlocks_25pct"],
  "recommendation_hash": "sha256:..."
}
```

## 5. Public API

```php
$svc->branch(array $input): array                  // build one counterfactual branch
$svc->branches(array $scope, int $maxDepth=6): list // explore branches from anchor
$svc->recommendReplan(array $scope): array          // best branch → recommendation envelope
```

## 6. Persistence

- `storage/atlas/teos_i3/branches.jsonl`
- `storage/atlas/teos_i3/recommendations.jsonl`

## 7. Operator workflow

```bash
# Build a single counterfactual
php artisan atlas:teos:counterfactual:branch \
  --mission-id=<uuid> --anchor-decision-id=<uuid> \
  --alternative-kind=provider_swap --alternative-value="codex_cli" \
  --mode=apply --check=teos-i3-branch --confirm

# Get the best replan recommendation for a scope
php artisan atlas:teos:counterfactual:recommend \
  --mission-id=<uuid> --json
```

## 8. Test coverage requirements

- Unit: branch envelope shape, divergence bounded, hash determinism, depth cap.
- Feature: recommend with no anchor returns insufficient evidence.
- Real-fixture: tests construct real branches; no provider mocks.

## 9. ACOS scorecard integration

```
['TEOS-I3', 'TEOS-I3 Counterfactual Runtime', 'teos',
 AtlasTeosI3CounterfactualService::class, 'ready', 'ready']
```

## 10. Evolucao governada

- **TEOS-I4** — multi-step contrafactual chains; exige doc, service e teste proprios.
- **Self-improvement integration** — counterfactual insights feed L7 ResultLedger somente com receipt e owner decision.
- **Cross-domain counterfactual** — branch de `finance` cross-bridged into `engineering` through Cross-Domain Mesh, sem promover simulacao a fato.

## Resumo

TEOS-I3 cria branches contrafactuais e recomendacoes de replan sem declarar simulacao como fato.

## Papel no Atlas

Ele ajuda o Atlas a perguntar "e se a decisao fosse outra?" mantendo a linha factual separada da linha alternativa.

## Onde Se Encaixa

Fica depois de AURG Temporal e antes de self-improvement ou replan operacional aprovado.

## Contratos

Schemas `atlas.teos_i3.counterfactual_branch.v1` e `atlas.teos_i3.replan_recommendation.v1`.

## Fluxo

Runtime recebe anchor decision, gera branch marcada como counterfactual, persiste e recomenda replan somente como proposta.

## Regras para IA

Nao aplicar recomendacao sem humano, nao misturar branch com Evidence Ledger factual e nao remover `is_counterfactual=true`.

## Escopo de Implementacao

Runtime local com branch, recommendations, depth cap e comandos Doctor 3-Tier.

## Dependencias

- `AtlasTeosI3CounterfactualService`
- `AtlasUnifiedRealityGraphTemporalService`
- `AtlasMutativeCommand`

## Evidencias

- `php artisan test tests/Unit/Ai/Teos/AtlasTeosI3CounterfactualServiceTest.php`
- `php artisan atlas:cognition:scorecard --strict --json`

## Riscos

Promover simulacao para verdade operacional ou deixar replan afetar missao sem aprovacao.

## Exemplos

Gerar branch com `provider_swap`, comparar melhoria projetada e emitir recommendation com `requires_human_approval=true`.

## Proximas Acoes

Conectar sinais TEOS-I3 ao ResultLedger somente com receipt e owner decision.
