---
id: atlas-decide-live-outcome-feedback
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Decide Live Outcome Feedback
slug: atlas-decide-live-outcome-feedback
status: building
implementation_state: runtime_available
category: atlas_decide
priority: 95
summary: Live online feedback ledger que registra outcome de cada provider call e detecta degradação de rotas ativas para que ADML feche o loop de aprendizado.
tags: [atlas-ai, atlas-decide, feedback, closed-loop, patamar-4]
capabilities: [live_outcome_record, route_stats, degradation_signal, auto_deactivate_route]
decisions:
  - Ledger live é diferente do Forge Rivals offline benchmark ledger.
  - Degradation threshold hardcoded em 0.7 (success_rate); broken em 0.4.
  - autoDeactivateOnDegradation roteia via applyAction normal para manter audit trail idêntico.
maintenance:
  - Atualizar thresholds só via PR + redeploy.
  - Não permitir degradation_signal sintético — sample_size mínimo MIN_CALLS_FOR_SIGNAL=5.
risk_level: medium
owner: atlas-ai
graph_id: atlas-decide-live-outcome-feedback
graph_title: Atlas Decide Live Outcome Feedback
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-decide-meta-learning
graph_status: building
graph_source: repo
depends_on: [atlas-decide-meta-learning, atlas-constitutional-kernel]
flows_to: [atlas-ai-meta-provider-os]
unlocks: [closed_feedback_loop, autonomous_route_self_repair]
governs: [provider_route_health_signal]
authority_class: feedback
related_paths:
  - app/Services/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackService.php
  - app/Services/Ai/AtlasDecide/AtlasDecideMetaLearningService.php
  - tests/Unit/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackServiceTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-decide-live-outcome-feedback.md
  - app/Services/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackService.php
evidence:
  - app/Services/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackService.php
  - tests/Unit/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackServiceTest.php
required_tests:
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackServiceTest.php"
next_actions:
  - Wire record() em todos os call-sites do AiGatewayService.
  - Adicionar cron */1h para autoDeactivateOnDegradation sweep.
allowed_changes:
  - Adicionar campos opcionais ao outcome envelope mantendo schema retrocompatível.
forbidden_changes:
  - lower_min_calls_below_5
  - benchmark_or_rivals_claim
  - auto_activate_alternative_route_without_human_review
requires_evidence: true
line_limit: 480
schema:
  - atlas.atlas_decide.live_outcome.v1
  - atlas.atlas_decide.live_route_stats.v1
  - atlas.atlas_decide.degradation_signal.v1
  - atlas.atlas_decide.auto_deactivation_sweep.v1
---

# Atlas Decide Live Outcome Feedback

## Resumo

Fecha o feedback loop do Atlas Decide. Forge Rivals ledger fornece sinal **offline** (benchmark battery). Live Outcome Feedback fornece sinal **online** — cada provider call de produção registra outcome (success/failure/timeout) + latency + quality_score opcional.

## Papel no Atlas

Quando ADML ativa uma rota baseada em sinal offline, a realidade pode divergir (provider regrediu, nova versão quebra, rede degrada). Sem feedback online ADML decora rota ruim e nunca reverte. Com este service, ADML chama `autoDeactivateOnDegradation()` e devolve rota degradante ao modo shadow automaticamente.

## Onde Se Encaixa

`AiGatewayService` ou qualquer chamador de provider → `record()` ao fim de cada call → `routeStats()`/`degradationSignal()` consultados por ADML → `applyAction(deactivate)` quando degrading/broken.

## Contratos

- `atlas.atlas_decide.live_outcome.v1` — entry shape canônico
- `atlas.atlas_decide.live_route_stats.v1` — agregado por (task_category, role, framework) com array providers
- `atlas.atlas_decide.degradation_signal.v1` — verdict {healthy|insufficient_evidence|degrading|broken} + thresholds
- `atlas.atlas_decide.auto_deactivation_sweep.v1` — envelope produzido por ADML

## Fluxo

1. Provider call retorna → caller chama `record(task_category, role, framework, provider, model, result, latency_ms, quality_score?)`
2. Append JSONL local
3. ADML `autoDeactivateOnDegradation(actor)` lê routingTable → para cada rota ativa chama `degradationSignal()` → se degrading ou broken chama `applyAction(deactivate)`
4. Receipt de deactivation entra na mesma trilha de auditoria que ações manuais

## Regras para IA

- NÃO modificar thresholds sem PR.
- NÃO emitir claim de qualidade comparativa entre providers — signal é interno, não public claim.
- NÃO popular ledger com dados sintéticos para forçar deactivate.

## Escopo de Implementacao

Service + 10 testes unitários + integração via AppServiceProvider resolving + CLI próximo.

## Dependencias

- AtlasDecideMetaLearningService (consumer principal)
- AtlasConstitutionalKernelService (claim_policy provider-safe enforcement)

## Evidencias

Service file + test file + scorecard tuple ADLF.

## Riscos

- Falso positivo deactivation se transient network failure inflar failure rate. Mitigação: WINDOW_SIZE=20 + MIN_CALLS_FOR_SIGNAL=5 evita disparo prematuro.
- Race condition em sweep concorrente. Mitigação: file lock no append.

## Exemplos

```bash
php artisan atlas:atlas-decide:live-feedback --action=sweep --actor=cron --json
```

## Proximas Acoes

Wire record() em call-sites reais; adicionar cron horário para sweep.

## Safety

- claim_policy provider-safe enforced.
- Append-only JSONL.
- Hash sha256 por entry.
- Thresholds não-flipáveis runtime.
