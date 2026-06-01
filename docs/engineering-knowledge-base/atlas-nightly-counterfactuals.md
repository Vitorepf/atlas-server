---
id: atlas-nightly-counterfactuals
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Nightly Counterfactuals
slug: atlas-nightly-counterfactuals
status: building
implementation_state: runtime_available
category: patamar_4
priority: 94
summary: Cron noturno que lê preflight envelopes do dia, reexpande TEOS-I4 sobre alternativas canônicas, gera recomendações ordenadas por projected_improvement no inbox.
tags: [atlas-ai, teos, background, nightly, patamar-4]
capabilities: [nightly_batch_counterfactual, decision_selector, recommendation_inbox]
decisions:
  - Cron daily 03:00 UTC.
  - Max 20 decisions per sweep (cost cap).
  - Tree breadth=4 depth=2 fixed.
  - READ-ONLY: nunca executa alternativa.
maintenance:
  - Recalibrar cap após observação real de runtime.
  - Verificar TEOS-I4 budget consumido com Trust Budget service futuramente.
risk_level: medium
owner: atlas-ai
graph_id: atlas-nightly-counterfactuals
graph_title: Atlas Nightly Counterfactuals
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-teos-i4-counterfactual-tree
graph_status: building
graph_source: repo
depends_on: [atlas-gateway-preflight, atlas-teos-i4-counterfactual-tree, atlas-constitutional-kernel]
flows_to: [atlas-ai-context-panel]
unlocks: [background_counterfactual_inbox]
governs: [nightly_decision_reprojection]
authority_class: advisor
related_paths:
  - app/Services/Ai/Patamar4/AtlasNightlyCounterfactualsService.php
  - app/Console/Commands/AtlasNightlyCounterfactualsCommand.php
  - tests/Unit/Ai/Patamar4/AtlasNightlyCounterfactualsServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-nightly-counterfactuals.md
  - app/Services/Ai/Patamar4/AtlasNightlyCounterfactualsService.php
evidence:
  - app/Services/Ai/Patamar4/AtlasNightlyCounterfactualsService.php
  - tests/Unit/Ai/Patamar4/AtlasNightlyCounterfactualsServiceTest.php
evidence_refs:
  - symbol: AtlasNightlyCounterfactualsService
  - command: atlas:nightly:counterfactuals
required_tests:
  - "php artisan test tests/Unit/Ai/Patamar4/AtlasNightlyCounterfactualsServiceTest.php"
next_actions:
  - Wirar cron daily 03:00 UTC em bootstrap/app.php.
  - Surface inbox no AtlasAiContextPanel mostrar top 3 recomendações.
allowed_changes:
  - Calibrar MAX_DECISIONS_PER_SWEEP.
forbidden_changes:
  - execute_alternative_automatically
  - skip_envelope_persistence
  - benchmark_or_rivals_claim
requires_evidence: true
line_limit: 480
schema:
  - atlas.patamar4.nightly_counterfactuals_sweep.v1
  - atlas.patamar4.nightly_recommendation.v1
---

# Atlas Nightly Counterfactuals

## Resumo

Cron noturno reroda TEOS-I4 sobre preflight envelopes que foram emitidos durante o dia. Produz recomendações priorizadas (best projected_improvement) no inbox para operador ler na manhã seguinte.

## Papel no Atlas

Cenário "Madrugada (você dormindo) → TEOS-I3 roda contrafactuais em loop sobre as decisões maiores" da spec original Patamar 4. Operator acorda com inbox montado.

## Onde Se Encaixa

- `selectMajorDecisions()` — lê preflight log, filtra last 24h + verdict ≠ not_projected
- `projectDecision(decision)` — TEOS-I4 expand sobre 4 alternativas canônicas (provider_swap, escalation, replan, abort)
- `inbox(limit)` — ordena por projected_improvement desc
- Append-only JSONL sweep + sweep_hash sha256

## Contratos

- `atlas.patamar4.nightly_counterfactuals_sweep.v1` — sweep envelope
- `atlas.patamar4.nightly_recommendation.v1` — recomendação individual

## Fluxo

1. Cron 03:00 UTC dispara `php artisan atlas:nightly:counterfactuals --action=run`
2. Service lê preflight envelopes últimas 24h
3. Filtra verdict ≠ not_projected → até 20 decisões
4. Para cada: TEOS-I4 expand 4 alternativas, breadth=4 depth=2
5. Recomendação JSONL com projected_improvement
6. Operator inspeciona com `--action=inbox --limit=10`

## Regras para IA

- NÃO executar alternativa sozinho.
- NÃO mutar preflight envelopes.
- NÃO ultrapassar MAX_DECISIONS_PER_SWEEP=20.

## Escopo de Implementacao

Service + CLI 4 actions + tests + cron registration (bootstrap/app.php pending wire).

## Dependencias

- AtlasGatewayPreflightService (source de decisões)
- AtlasTeosI4CounterfactualTreeService (engine)
- AtlasConstitutionalKernelService (kernel_hash anchor)

## Evidencias

Service + tests + JSONL append-only.

## Riscos

- TEOS-I4 caro por decisão. Mitigação: cap 20 + breadth=4 + depth=2.
- Recomendações inflacionadas. Mitigação: filter por projected_improvement > 0 no inbox.

## Exemplos

```bash
php artisan atlas:nightly:counterfactuals --action=run --json
php artisan atlas:nightly:counterfactuals --action=inbox --limit=5 --json
```

## Proximas Acoes

Cron 03:00 UTC. Surface inbox no Atlas AI Context Panel.

## Safety

- READ-ONLY.
- Append-only JSONL.
- kernel_hash anchor.
- claim_policy provider-safe via TEOS chain.
