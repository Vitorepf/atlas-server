---
id: atlas-patamar4-hardening-4-7
type: engineering_knowledge
title: Atlas Patamar 4 Fase 4.7 Hardening Cross-System Stress
status: source_material
category: patamar4
priority: 70
summary: Registro canonico de hardening e stress cross-system do Patamar 4, preservado como source material para evidencia historica e auditoria.
tags:
  - atlas
  - patamar4
  - hardening
  - stress-test
capabilities:
  - patamar4_cross_system_stress
  - runtime_degradation_signal
  - cognitive_scorecard
decisions:
  - Este doc preserva a composicao comprovada pelo teste cross-system do Patamar 4.
  - Como source material, ele nao compete com os docs canonicos de arquitetura-mae.
maintenance:
  - Promova para canonical_module apenas se ele voltar a governar implementacao ativa.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - tests/Feature/Patamar4/AtlasPatamar4CrossSystemStressTest.php
---

# Atlas Patamar 4 · Fase 4.7 — Hardening + Cross-System Stress (canon)

> **Status:** `building`
> **Group:** patamar4 / hardening
> **Authority:** docs canônicos governam implementação.
> **Claim policy:** provider-safe. Local-first.

## 1. Razão de existir

O canon original de Patamar 4 (§7 Roteiro) lista a fase 4.7 como **"Hardening + observability cruzada + stress tests reais"**. Esta doc canonifica o que isso significa e o que está auditável.

## 2. O que `tests/Feature/Patamar4/AtlasPatamar4CrossSystemStressTest.php` prova

10 invariantes encadeados num único processo, executados em sequência, todos verde:

1. **Scheduler heartbeat** registra com sha256.
2. **Reconciliation tick** roda e produz outcome.
3. **Auto-Rebalance plan** emite `probe_status` honesto (`ok | unwired | error`).
4. **Runtime Degradation Signal** crítico **dispara tick out-of-cron** automaticamente.
5. **Self-Divergence measurement** retorna `divergence_hash`.
6. **Cognitive Function Swarm Router** decompõe + dispatcha por função; arms carregam `cognitive_axis`/`axis_weight`.
7. **Embodiment Integration** snapshot lista 4 loci (mac, voice, stackchan, cartography).
8. **Scorecard overall** mantém `10/10`.
9. **Kernel hash** é **idêntico** em todos os envelopes da cadeia — zero drift cross-system.
10. **claim_policy** provider-safe em todo envelope (benchmark/rivals/superiority/external_rivals=false).

## 3. Composição efetiva canônica

A cadeia que os testes provam compor sem cross-talk:

```
                       launchd 24/7
                            ↓
       atlas:scheduler:heartbeat (every minute)
                            ↓
   atlas:reconciliation tick (every 15min)
            ├── ASCB.propose() when allow_autonomous
            ├── auto-rebalance sweep (4 kinds)
            ├── AURG tick (rationale_event)
            └── TEOS-I3 meta-projection
                            ↓
   Runtime Degradation Signal (any ACOP/ACMF source)
            └── severity >= high → out-of-cron tick
                            ↓
   Self-Divergence Model (target vs current)
            └── operator inspects via CLI or HTTP surface
                            ↓
   Cognitive Function Swarm Router (per turn)
            ├── decompose input → 6-axis vector
            ├── dispatch per axis via ADML
            └── arms carry cognitive_axis + weight
                            ↓
   AiWorker → AiProviderManager (or Swarm Auto-Failover when flags ON)
            └── outcomes → Live Outcome Feedback ledger
                            ↓
   Embodiment Integration snapshot (mac/voice/stackchan/cartography)
                            ↓
   atlas:cognition:scorecard --strict (overall 10/10)
```

## 4. Flags canon

| Flag | Default | Habilitada por A3 install / C5 activate |
|---|---|---|
| `atlas.patamar4.scheduler_heartbeat_enabled` | true | sempre |
| `atlas.patamar4.scheduler_ensure_launchd_enabled` | true | sempre |
| `atlas.patamar4.reconciliation_enabled` | true | sempre |
| `atlas.patamar4.nightly_counterfactuals_enabled` | true | sempre |
| `atlas.patamar4.adml_sweep_enabled` | true | sempre |
| `atlas.patamar4.runtime_degradation_auto_tick_enabled` | true | sempre |
| `atlas.patamar4.swarm_production_resolver_enabled` | **false** | C5 `--apply` ativa |
| `atlas.patamar4.swarm_parallel_enabled` | **false** | C5 `--apply` ativa |
| `atlas.patamar4.swarm_auto_failover_enabled` | **false** | C5 `--apply` ativa |

## 5. Honestidades remanescentes

Pontos onde a entrega backend está pronta mas a entrega TOTAL ao operador depende de ações fora desta camada:

1. **React mount** (desktop `AtlasAiContextPanel/AtlasAiLiveActivity/AtlasAiResponseAudit` + mobile 3 críticos) — endpoints HTTP existem e estão testados; consumo visual é trabalho frontend que exige build/test cycle Vite+Tauri+RN.
2. **AGRN-ISF + ACPS** — propostas ASCB geradas via A6; aguardam promoção operador.
3. **Stress test em PRODUÇÃO com flags ON** — este teste prova composição lógica; stress real com quota provider gasta tokens.

## 6. Cross-references

- `tests/Feature/Patamar4/AtlasPatamar4CrossSystemStressTest.php`
- `app/Services/Ai/Patamar4/AtlasSchedulerHealthService.php`
- `app/Services/Ai/Patamar4/AtlasRuntimeDegradationSignalService.php`
- `app/Services/Ai/Patamar4/AtlasEmbodimentIntegrationService.php`
- `app/Services/Ai/Patamar4/AtlasSubsystemAutoRebalanceService.php`
- `app/Services/Ai/AtlasDecide/AtlasCognitiveFunctionSwarmRouterService.php`
- `app/Services/Ai/SelfConstruction/AtlasSelfDivergenceModelService.php`
- `app/Services/Ai/Cognition/AtlasCognitionScoreCardService.php`
- `config/atlas.php` (§ `patamar4`)
- `app/Console/Commands/AtlasPatamar4ActivateFlagsCommand.php`
