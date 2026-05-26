---
id: atlas-gateway-preflight
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Gateway Preflight (TEOS-I4 wiring)
slug: atlas-gateway-preflight
status: building
implementation_state: runtime_available
category: gateway
priority: 96
summary: Ensaio mental antes de gastar token. Wire TEOS-I4 counterfactual tree no AiGatewayService.enqueueInteraction antes de criar trace+job em decisões majores.
tags: [atlas-ai, gateway, preflight, teos, patamar-4]
capabilities: [pre_token_projection, major_decision_classification, counterfactual_tree_advisory]
decisions:
  - Preflight é advisory; nunca bloqueia job sozinho.
  - "Major" = privacy sensitive/secret/cyber OU autonomous OU force_preflight OU input >= 8 palavras.
  - Envelope sempre persistido (verdict NOT_PROJECTED em caso de skip ou erro).
maintenance:
  - Atualizar quando major decision heuristic mudar.
  - Manter advisory; jamais virar block path.
risk_level: medium
owner: atlas-ai
graph_id: atlas-gateway-preflight
graph_title: Atlas Gateway Preflight
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-teos-i4-counterfactual-tree
graph_status: building
graph_source: repo
depends_on: [atlas-teos-i4-counterfactual-tree, atlas-constitutional-kernel, atlas-ai-gateway-service]
flows_to: [atlas-ai-gateway-service]
unlocks: [pre_token_counterfactual_projection]
governs: [major_decision_preflight_advisory]
authority_class: advisory
related_paths:
  - app/Services/Ai/Gateway/AtlasGatewayPreflightService.php
  - app/Services/Ai/AiGatewayService.php
  - app/Console/Commands/AtlasGatewayPreflightCommand.php
  - tests/Unit/Ai/Gateway/AtlasGatewayPreflightServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-gateway-preflight.md
  - app/Services/Ai/Gateway/AtlasGatewayPreflightService.php
evidence:
  - app/Services/Ai/Gateway/AtlasGatewayPreflightService.php
  - tests/Unit/Ai/Gateway/AtlasGatewayPreflightServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/Gateway/AtlasGatewayPreflightServiceTest.php"
next_actions:
  - Wirar surface mobile para exibir preflight_envelope no inbox.
allowed_changes:
  - Adicionar verdicts novos preservando schema retrocompatível.
  - Calibrar major thresholds via config.
forbidden_changes:
  - turn_preflight_into_blocking_gate
  - skip_envelope_persistence
  - benchmark_or_rivals_claim
requires_evidence: true
line_limit: 480
schema:
  - atlas.gateway.preflight_envelope.v1
---

# Atlas Gateway Preflight

## Resumo

"Ensaio mental antes de gastar token". Antes do AiGateway criar trace+job para decisões majores, este service expande uma TEOS-I4 counterfactual tree e anexa o envelope ao trace metadata. Operator decide com base no envelope.

## Papel no Atlas

Decisões majores (sensitive/secret/cyber, autonomous, force_preflight, prompts longos) merecem ensaio antes de gastar token. Preflight roda greedy BFS tree TEOS-I4 (breadth=3, depth=2), calcula best improvement, persiste envelope.

## Onde Se Encaixa

`AiGatewayService.enqueueInteraction` chama `preflight($input, $provider, $options)` opcionalmente (setter pattern). Wirado via `AppServiceProvider->resolving(AiGatewayService::class)`.

## Contratos

Schema `atlas.gateway.preflight_envelope.v1` com verdict canon:
- `not_projected` — não major OU erro defensivo
- `projected_ok` — tree expandida, ganho ≥ LOW_GAIN_THRESHOLD (0.05)
- `projected_low_gain` — tree expandida, ganho < 0.05
- `projected_kernel_block` — Kernel bloqueou expansão

## Fluxo

1. enqueueInteraction chega → resolve provider/privacy/autonomy
2. `preflight->preflight(input, provider, options)` → envelope
3. Envelope anexado a trace.metadata.preflight
4. Trace + job criados normalmente (advisory only)
5. Surface (Atlas AI Desktop) consome preflight do AiTraceResource

## Regras para IA

- NUNCA bloquear job pelo verdict.
- NUNCA pular persistência do envelope.
- Major heuristic é declarado canon — não mudar runtime.

## Escopo de Implementacao

Service + opt-in setter no AiGatewayService + AppServiceProvider resolving + CLI + tests.

## Dependencias

- AtlasTeosI4CounterfactualTreeService (expansão tree)
- AtlasConstitutionalKernelService (kernel decision no tree)
- AiGatewayService (caller)

## Evidencias

Service file + test file + opt-in setter no gateway + JSONL append-only.

## Riscos

- TEOS-I4 lento → adiciona latency. Mitigação: só roda em majores; breadth/depth pequenos.
- Envelope grande no trace metadata. Mitigação: best_path tem ≤ 4 nodes.

## Exemplos

```bash
php artisan atlas:gateway:preflight \
  --action=preflight \
  --input="constrói o ecommerce dos sapatos" \
  --provider=claude_cli \
  --json
```

## Proximas Acoes

Surface mobile inbox mostrar preflight envelopes.

## Safety

- Advisory only.
- Append-only JSONL.
- claim_policy provider-safe enforced via TEOS-I4 chain.
- Sweep hash sha256 para audit.
