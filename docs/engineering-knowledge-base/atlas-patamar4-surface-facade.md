---
id: atlas-patamar4-surface-facade
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Patamar 4 Surface Facade
slug: atlas-patamar4-surface-facade
status: building
implementation_state: runtime_available_backend_surface_ui_mount_pending
category: ai-runtime
priority: 93
summary: Facade HTTP provider-safe com endpoints granulares para surfaces Patamar 4, compondo services existentes sem criar logica de dominio paralela.
tags: [atlas-ai, patamar-4, surface, ui-facade, provider-safe]
capabilities: [patamar4_surface_facade, scheduler_surface_endpoint, swarm_surface_endpoint, governance_surface_endpoint, cognitive_function_surface_endpoint]
decisions:
  - A facade compoe services existentes; nao vira owner de logica de scheduler, swarm, governance, cognition ou inbox.
  - Backend endpoints e testes existem; montagem React/mobile e trabalho subsequente.
  - Decompose e o unico endpoint que pode escrever JSONL, seguindo contrato do decompositor.
maintenance:
  - Atualizar doc quando rotas, schemas ou consumers UI mudarem.
  - Manter cada endpoint com teste HTTP e schema_version explicito.
risk_level: medium
owner: atlas-ai
graph_id: atlas-patamar4-surface-facade
graph_title: Atlas Patamar 4 Surface Facade
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: building
graph_source: repo
depends_on:
  - atlas-cognitive-function-decomposer
  - atlas-cognitive-function-atlas
  - atlas-scheduler-os
  - atlas-swarm-production-resolver
authority_class: implementation_contract
related_paths:
  - docs/engineering-knowledge-base/atlas-patamar4-surface-facade.md
  - app/Http/Controllers/AtlasPatamar4SurfaceController.php
  - routes/api.php
  - tests/Feature/Http/AtlasPatamar4SurfaceControllerTest.php
repo_paths:
  - docs/engineering-knowledge-base/atlas-patamar4-surface-facade.md
  - app/Http/Controllers/AtlasPatamar4SurfaceController.php
  - routes/api.php
flows_to: [atlas-ui-context-panel, atlas-ai-live-activity, atlas-ai-response-audit]
unlocks: [patamar4_granular_ui_reads, cognitive_function_pre_routing_surface]
governs: [patamar4_http_surface_facade]
evidence:
  - app/Http/Controllers/AtlasPatamar4SurfaceController.php
  - tests/Feature/Http/AtlasPatamar4SurfaceControllerTest.php
required_tests:
  - "php artisan test tests/Feature/Http/AtlasPatamar4SurfaceControllerTest.php"
next_actions:
  - Montar consumers React/mobile com build e teste de UI antes de declarar UX pronta.
  - Adicionar testes para novos endpoints antes de expor schemas publicos.
allowed_changes:
  - Add read-only endpoint composition with explicit schema_version.
  - Add UI consumers after backend contract is tested.
forbidden_changes:
  - duplicate_domain_logic_inside_facade
  - claim_ui_mounted_without_frontend_evidence
  - return_external_benchmark_or_rivals_claims
requires_evidence: true
line_limit: 520
schema:
  - atlas.patamar4.surface.scheduler.v1
  - atlas.patamar4.surface.swarm.v1
  - atlas.patamar4.surface.rebalance.v1
  - atlas.patamar4.surface.cognitive_function.v1
  - atlas.patamar4.surface.governance.v1
  - atlas.patamar4.surface.madrugada_inbox.v1
  - atlas.cognitive_function.decomposition.v1
---

# Atlas Patamar 4 Surface Facade — F3 (canon)

> **Status:** `building`
> **Group:** patamar4 / surface
> **ACOS subsystem:** `APSF — Patamar 4 Surface Facade`
> **Authority:** docs canônicos governam implementação.
> **Claim policy:** provider-safe. Local-first. Read-only (exceção `decompose` que escreve JSONL canon).

## Resumo

Facade HTTP para surfaces Patamar 4 que entrega envelopes pequenos e provider-safe para UI desktop/mobile.

## Papel no Atlas

A UI Atlas (desktop + mobile) precisa de **endpoints surgicos** para renderizar Madrugada inbox, LiveActivity chips (preflight + swarm), ResponseAudit footer e Truth/Trust pins **sem** carregar o envelope completo `/atlas/patamar4/state`. F3 entrega 7 endpoints especializados que cada componente UI consome direto.

## Onde Se Encaixa

Fica acima dos services Patamar 4, ACOS, governance e decide, e abaixo das surfaces UI. A facade nao substitui os services; apenas compoe envelopes HTTP.

## Contratos

Os endpoints abaixo retornam `schema_version` explicito e mantem claim policy provider-safe.

| # | Método | Rota | Schema retorno | Consumer UI principal |
|---|---|---|---|---|
| 1 | GET | `/atlas/patamar4/scheduler` | `atlas.patamar4.surface.scheduler.v1` | LiveActivity (cron 24/7 dot) |
| 2 | GET | `/atlas/patamar4/swarm` | `atlas.patamar4.surface.swarm.v1` | LiveActivity (swarm chip) + ResponseAudit |
| 3 | GET | `/atlas/patamar4/rebalance` | `atlas.patamar4.surface.rebalance.v1` | Diagnostics drawer |
| 4 | GET | `/atlas/patamar4/cognitive-function` | `atlas.patamar4.surface.cognitive_function.v1` | Self-model panel + decompositions feed |
| 5 | GET | `/atlas/patamar4/governance` | `atlas.patamar4.surface.governance.v1` | ResponseAudit footer (kernel/trust/vault) |
| 6 | POST | `/atlas/patamar4/decompose` | `atlas.cognitive_function.decomposition.v1` | Composer pre-routing (real-time tupla) |
| 7 | GET | `/atlas/patamar4/inbox/madrugada` | `atlas.patamar4.surface.madrugada_inbox.v1` | ContextPanel Madrugada block |

Todos suportam `?tail=N` (clamp 1..50).

## Fluxo

Request HTTP -> controller facade -> service owner existente -> envelope pequeno -> UI consumer.

## Regras para IA

- Nao duplicar logica de dominio dentro do controller.
- Nao declarar UI pronta sem evidencia de build/test frontend.
- Nao retornar claims de benchmark, rivals ou superiority.
- Tratar `decompose` como excecao controlada de escrita JSONL.
- Ao adicionar endpoint, atualizar doc, rota e teste feature.

## Escopo de Implementacao

Backend facade com 7 endpoints e teste HTTP existe. Montagem React/mobile ainda e trabalho subsequente.

## Dependencias

- Scheduler, Swarm, Rebalance, Cognitive Function, Governance, Vault e Madrugada services.
- `routes/api.php` para exposicao HTTP.

## Evidencias

- `app/Http/Controllers/AtlasPatamar4SurfaceController.php`
- `tests/Feature/Http/AtlasPatamar4SurfaceControllerTest.php`

## Riscos

- Virar controller com logica paralela de dominio.
- Confundir endpoint backend pronto com UX desktop/mobile pronta.
- Expor estado sensivel sem claim policy provider-safe.

## Exemplos

```bash
php artisan test tests/Feature/Http/AtlasPatamar4SurfaceControllerTest.php
```

## Proximas Acoes

- Montar consumers UI e provar com build/teste visual.
- Adicionar teste de contrato para qualquer novo schema de surface.

## Composição (não duplicação)

O controller faz **composição read-only** dos services já existentes — zero lógica nova:

- `AtlasSchedulerHealthService::status()` + `listHeartbeats()`
- `AtlasSwarmConductorService::listDispatches()` + `AtlasSwarmExecutorService::listExecutions()` + `AtlasSwarmProductionResolverService::circuitState()`
- `AtlasSubsystemAutoRebalanceService::plan()` (read-only) + `listReceipts()`
- `AtlasCognitiveFunctionAtlasService::selfModel()` + `gapsByGroup()` + `AtlasCognitiveFunctionDecomposerService::listDecompositions()`
- `AtlasConstitutionalKernelService::kernelHash()` + `AtlasTrustBudgetService::canonicalBudget()` + `AtlasConstitutionalVaultService::verify()`
- `AtlasNightlyCounterfactualsService::inbox()` + `AtlasDecideLiveOutcomeFeedbackService::listOutcomes()`

## Consumer UI mapping (próximo passo)

| UI surface | Endpoint | Bloco visual |
|---|---|---|
| Desktop `AtlasAiContextPanel` | `/inbox/madrugada` | Bloco "Madrugada" (counterfactuals + ADML outcomes) |
| Desktop `AtlasAiLiveActivity` | `/scheduler`, `/swarm` | Chips: cron 24/7 (verde/vermelho silent_alarm), swarm flag (off/on + circuit aberto?) |
| Desktop `AtlasAiResponseAudit` | `/governance` | Footer: kernel_hash short + trust budget + vault status |
| Mobile inbox sheet | `/inbox/madrugada` | Card Madrugada |
| Mobile trust pin | `/governance` | Pin Trust Budget tier |
| Mobile truth pin | `/governance` | Pin Vault verify status |
| Composer pre-routing | POST `/decompose` | Tupla 6-axis on-the-fly |

**Note honesto:** F3 backend (7 rotas + tests + doc canon) entregue. React mount nos componentes acima é trabalho subsequente — não enganaria o operador dizendo que está pronto sem build/test cycle do desktop/mobile. As rotas existem e são certificadas.

## Invariants

1. Read-only exceto `decompose` (que grava JSONL canon do decomposer).
2. `tail` clamped 1..50.
3. Vault verify try/catch — nunca propaga exceção pro UI.
4. claim_policy implícito: nenhuma rota retorna `benchmark/rivals/superiority`.
5. Composição pura, zero duplicação de lógica de service.

## Filtro 5 perguntas

1. **Wrapper composto?** Sim — UI consume endpoints granulares, não toca lógica de domínio.
2. **Antifrágil?** Sim — vault verify, scheduler status, circuit state todos retornam estado honesto, inclusive falhas.
3. **Linguagem natural?** Sim — `decompose` POST é o bridge frase humana → vetor cognitivo.
4. **Destrava função?** Sim — sem facade UI não tem onde puxar dados granulares.
5. **Local-first?** Sim — todos endpoints lêem JSONL local.

## Testes canon

- `tests/Feature/Http/AtlasPatamar4SurfaceControllerTest.php` — 8 tests cobrindo cada endpoint + tail clamp.

## Cross-references

- `AtlasPatamar4StateController` (envelope full, F0-F4 antigo).
- Cada service listado em §3.
