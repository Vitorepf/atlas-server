---
id: atlas-engineering-company-runtime-http-promotion
type: engineering_knowledge
title: Atlas Engineering Company Runtime HTTP Promotion ADR
status: building
category: architecture
priority: 95
summary: ADR canonico que governa a promocao do `AtlasRealEngineeringCompanyRuntimeService` (676 LOC, 9 departamentos canonicos, cert interna) de entry CLI-only para entry HTTP produtivo via novo endpoint `POST /atlas-code/work/company`. Substitui chamada direta a `AtlasProgrammingOrchestrator` no `AtlasCodeWorkController::store()` em rollout faseado: feature flag `shadow` (dual-run silencioso 7d) -> `on` (cutover atras de flag por opt-in operador) -> `default` (apos paridade verde). Phase 1 desta ADR e zero codigo de producao; apenas registra decisao, contratos, riscos e gates. Implementacao de cada fase exige AP dedicado e Decision Receipt v2 do operador antes do cutover de default.
implementation_status: partial
implementation_boundary: adr_phase_0_decision_recorded_implementation_phases_1_5_require_dedicated_aps
tags:
  - atlas-ai
  - adr
  - engineering-company-runtime
  - http-promotion
  - feature-flag
  - 2026-05-26
capabilities:
  - company_runtime_http_entry
  - company_runtime_shadow_dual_run
  - company_runtime_feature_flag_rollback
  - company_runtime_latency_observability
  - atlas_code_work_controller_canonical_routing
decisions:
  - O entry HTTP produtivo de Programming sera promovido de `AtlasProgrammingOrchestrator` (path legado) para `AtlasRealEngineeringCompanyRuntimeService` (9 departamentos canonicos com certificacao interna), atraves de novo endpoint `POST /atlas-code/work/company`.
  - A promocao acontece em 5 fases controladas por feature flag `ATLAS_HTTP_COMPANY_RUNTIME` com valores `off|shadow|on|default`. Cada transicao exige Decision Receipt v2 do operador.
  - O endpoint legado continua aceitando requests durante Fase 2 (shadow) e Fase 3 (on). Remocao do legado e Fase 5, fora do escopo desta ADR.
  - Gap 1 (Kernel HTTP integration, doc `atlas-aiworker-kernel-integration-adr.md`) e PRE-REQUISITO desta ADR. Company Runtime deve emitir Decision Receipt v2 via Kernel canonico, nao inventar segundo path produtivo.
  - Latencia incremental de Company Runtime (9 receipts por request vs 1 do orchestrator legado) sera mensurada antes de qualquer cutover de default; budget canonico declarado nesta ADR e `p99 latency <=2x baseline`.
  - O constante `BENCHMARK_SCHEMA` interna ao Company Runtime (linha 33) viola vocabulario proibido do Atlas; sera renomeada em AP irmao antes desta ADR fechar (tracker emitido em 2026-05-26).
maintenance:
  - Atualize esta ADR quando uma fase entrar em `building` ou `active`.
  - Nao prossiga para Fase 3 (`on`) sem 7 dias completos em Fase 2 (`shadow`) com paridade verde de saida (>=95% bytewise) e budget de latencia respeitado.
  - Nao prossiga para Fase 4 (`default`) sem 14 dias completos em Fase 3 (`on`) com zero rollback emitido por operador.
next_actions:
  - Aguardar Gap1 Fase 4 (cutover de Kernel via flag `ATLAS_AIWORKER_KERNEL_ROUTED`) antes de abrir AP de Fase 1 desta ADR.
  - Abrir AP dedicado para Fase 1 (endpoint shadow novo + recorder de paridade), separado desta ADR.
  - Renomear `BENCHMARK_SCHEMA` no Company Runtime (tracker spawned 2026-05-26 durante esta ADR).
  - Pre-flight test plan: lista os 9 receipts emitidos por engagement e seus tamanhos medios em bytes para baseline de latencia.
related_paths:
  - docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/domains/programming.md
  - app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php
  - app/Http/Controllers/AtlasCodeWorkController.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-engineering-company-runtime-http-promotion
graph_title: Atlas Engineering Company Runtime HTTP Promotion ADR
human_name: Atlas Engineering Company Runtime HTTP Promotion ADR
canonical_name: Atlas Engineering Company Runtime HTTP Promotion ADR
technical_name: AtlasEngineeringCompanyRuntimeHttpPromotionAdr
cartography_type: adr
canonical_source: docs/engineering-knowledge-base/atlas-engineering-company-runtime-http-promotion.md
graph_world: atlas
graph_layer: system
graph_kind: adr
graph_parent: atlas-autonomous-software-company-runtime
graph_status: building
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-engineering-company-runtime-http-promotion.md
allowed_changes:
  - Atualizar status de fase, feature flag values, paridade observada, latencia medida, gates verdes/vermelhos.
  - Estender lista de evidencias quando AP de fase ship.
  - Refinar nomes de schema, endpoints e flags conforme implementacao real.
forbidden_changes:
  - Declarar esta ADR `implemented_ready` sem teste Feature E2E que cubra `POST /atlas-code/work/company` real e termine com Certification passed.
  - Promover Company Runtime para `default` sem Decision Receipt v2 do operador.
  - Remover o path legado (`AtlasProgrammingOrchestrator` em `AtlasCodeWorkController::store()`) antes de Fase 5 (fora do escopo desta ADR).
  - Reduzir o budget de latencia `p99 <=2x baseline` sem evidencia empirica que justifique.
  - Reverter a decisao Gap1-precede-Gap3 (Kernel HTTP integration e pre-requisito desta ADR).
depends_on:
  - atlas-aiworker-kernel-integration-adr
  - atlas-autonomous-software-company-runtime
  - atlas-evidence-certification-runtime
  - atlas-kernel-mission-foundation
  - atlas-real-engineering-execution-kernel
  - atlas-permission-budget-safety-layer
flows_to:
  - atlas-autonomous-control-plane
  - atlas-domain-company-runtimes
unlocks:
  - canonical-http-company-runtime-pipeline
  - 9-department-execution-from-http
  - operator-grade-rollback-via-feature-flag
governs:
  - atlas_ai.engineering_company.http_entry
  - atlas_ai.engineering_company.feature_flag
  - atlas_ai.engineering_company.latency_budget
evidence:
  - docs/engineering-knowledge-base/atlas-engineering-company-runtime-http-promotion.md
  - app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php
  - app/Http/Controllers/AtlasCodeWorkController.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
required_tests:
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:architecture-validate --json"
  - "/opt/homebrew/bin/php artisan atlas:cognition:scorecard --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Onde Se Encaixa, Contratos, Fluxo, Regras para IA, Escopo de Implementacao e Riscos antes de abrir AP de fase.
ai_usage_notes:
  - Esta ADR e autoridade de decisao, nao prova de implementacao.
  - Fase 0 (decisao registrada) e o unico estado entregue por esta ADR; Fases 1-5 exigem AP dedicado.
  - Cutover de `default` exige 14 dias de Fase `on` verde + Decision Receipt v2 do operador.
quality_gates:
  - gap1-kernel-http-shipped
  - company-runtime-benchmark-schema-renamed
  - shadow-parity-7d-green
  - latency-p99-within-2x-budget
  - operator-cutover-receipt-issued
claim_policy:
  benchmark: false
  rivals: false
  superiority: false
  external_rivals: false
  passive: false
  compression: false
  auto_apply: false
  deletes_files: false
---

# Atlas Engineering Company Runtime HTTP Promotion ADR

## Resumo

O `AtlasRealEngineeringCompanyRuntimeService` (676 LOC, 9 departamentos canonicos, certificacao interna via `atlas.ai.engineering_company.certification.v1`) existe e funciona via CLI/smoke desde 2026-05 mas **nao recebe trafego HTTP produtivo**. O entry HTTP atual em `AtlasCodeWorkController::store()` ainda passa diretamente por `AtlasProgrammingOrchestrator` (path legado, single-track).

Esta ADR registra a decisao canonica de **promover o Company Runtime a entry HTTP produtivo**, via novo endpoint `POST /atlas-code/work/company`, em 5 fases controladas por feature flag `ATLAS_HTTP_COMPANY_RUNTIME` (`off|shadow|on|default`), com gates duros entre cada transicao. O cutover de `default` exige 14 dias de paridade verde em `on` + Decision Receipt v2 do operador.

Esta ADR Phase 0 entrega **zero codigo de producao** — apenas registra a decisao, contratos, fases, riscos, gates e pre-requisitos. Implementacao real de cada fase exige AP dedicado.

**Pre-requisito explicito:** Gap1 (Kernel HTTP integration, doc `atlas-aiworker-kernel-integration-adr.md`) deve completar Fase 4 (cutover de `ATLAS_AIWORKER_KERNEL_ROUTED`) antes desta ADR abrir Fase 1. Razao: Company Runtime tem que emitir Decision Receipt v2 via Kernel canonico, nao via segundo path produtivo paralelo.

## Papel no Atlas

Esta ADR cumpre quatro funcoes:

1. **Registro canonico** da decisao operacional de unificar entry HTTP em Company Runtime.
2. **Sequenciamento explicito** com Gap1: Kernel HTTP integration tem que vir primeiro.
3. **Budget de latencia** publicado e auditavel (`p99 <=2x baseline`), evitando degradacao silenciosa.
4. **Estrategia de rollback** declarada (feature flag controlada por operador via Decision Receipt v2), sem qual o cutover seria irrecuperavel.

Nao e implementacao runtime; e contrato de decisao que ARs de fase consomem.

## Onde Se Encaixa

Esta ADR e filha de `atlas-autonomous-software-company-runtime.md` (parent canonico do Company Runtime no Atlas). Consome:

- `atlas-aiworker-kernel-integration-adr.md` (PRE-REQUISITO Gap1 — Kernel HTTP)
- `atlas-evidence-certification-runtime.md` (esquema de receipts emitidos)
- `atlas-kernel-mission-foundation.md` (Mission lifecycle por HTTP)
- `atlas-real-engineering-execution-kernel.md` (Kernel canonico que recebera dispatch)
- `atlas-permission-budget-safety-layer.md` (Permission gate por engagement)

Alimenta:

- `atlas-autonomous-control-plane.md` — control plane HTTP gainz visibilidade de 9 departamentos.
- `atlas-domain-company-runtimes.md` — registra Company Runtime como entry path canonico.

## Contratos

### Contrato 1: Novo endpoint HTTP

```
POST /atlas-code/work/company
Content-Type: application/json

Request body schema: atlas.ai.engineering_company.intake.v1
{
  "goal_text": string (required, max 8000 chars),
  "options": {
    "shadow_mode": bool (default false, controlled by feature flag),
    "trace_id": string (optional, propagated to receipts),
    "operator_authority": string (optional, used by Permission Gate)
  }
}

Response schema: atlas.ai.engineering_company.engagement_response.v1
{
  "engagement_id": uuid,
  "certification_status": "passed" | "pending" | "failed",
  "receipts_emitted": int (9 esperados),
  "shadow_parity": null | { "diverged_fields": [], "byte_diff_pct": float },
  "trace": [...]
}
```

### Contrato 2: Feature flag canonica

```
ENV: ATLAS_HTTP_COMPANY_RUNTIME = off | shadow | on | default
```

| Valor | Comportamento de `AtlasCodeWorkController::store()` |
|---|---|
| `off` (default ate cutover) | Encaminha somente para `AtlasProgrammingOrchestrator` (legado). |
| `shadow` | Encaminha para legado E dispara Company Runtime em paralelo (fire-and-compare); resposta ao cliente vem do legado. Persiste paridade em `evidence/company_runtime_shadow_run/`. |
| `on` | Encaminha para Company Runtime; legado vira fallback automatico se Company Runtime falhar (timeout, exception, cert failed). |
| `default` | Encaminha para Company Runtime exclusivamente; legado removido (Fase 5, fora desta ADR). |

### Contrato 3: Budget de latencia

`p99 latency <=2x baseline_legado` mensurada nos primeiros 7 dias de Fase `shadow`. Baseline e o p99 atual de `AtlasCodeWorkController::store()` antes desta ADR. Se Company Runtime exceder o budget, cutover para `on` e bloqueado ate otimizacao.

### Contrato 4: Decision Receipt v2 obrigatorio

Cada transicao de feature flag exige Decision Receipt v2 do operador:

- `off -> shadow`: receipt confirma que Gap1 Fase 4 ja completou.
- `shadow -> on`: receipt confirma 7 dias de paridade verde + budget de latencia respeitado.
- `on -> default`: receipt confirma 14 dias zero rollback + Mission Foundation E2E verde.

Cada receipt fica gravado em `evidence/company_runtime_http_promotion/decision_receipts/`.

## Fluxo

### Fluxo 1: Fase `shadow` (primeira fase com codigo)

```
[cliente HTTP]
  -> POST /atlas-code/work
  -> AtlasCodeWorkController::store()
  -> AtlasProgrammingOrchestrator (legado) [responde ao cliente]
                |
                +-> fork assincrono
                     -> AtlasRealEngineeringCompanyRuntimeService::run(goalText, options)
                     -> 9 departamentos rodam, emitem 9 receipts
                     -> ParityRecorder compara saidas
                     -> persiste em evidence/company_runtime_shadow_run/<engagement_id>.json
                     -> nao bloqueia resposta
```

### Fluxo 2: Fase `on`

```
[cliente HTTP]
  -> POST /atlas-code/work/company   [endpoint novo]
  -> AtlasCodeWorkController::storeCompany()
  -> AtlasRealEngineeringCompanyRuntimeService::run(goalText, options)
  -> 9 departamentos, 9 receipts, 1 certification
  -> se Certification passed -> retorna ao cliente
  -> se Certification failed OU exception OU timeout:
       -> fallback automatico para AtlasProgrammingOrchestrator
       -> emite alerta em atlas_code.receipt.signed event_type
```

### Fluxo 3: Rollback emergencial

```
[operador detecta degradacao]
  -> atlas config set ATLAS_HTTP_COMPANY_RUNTIME=off
  -> Decision Receipt v2 emitido com reason
  -> trafego HTTP volta inteiramente para legado
  -> investigacao abre AP de hardening
  -> nao retornar para `on` sem AP fechado + nova receipt
```

## Regras para IA

1. **NUNCA** chame `AtlasRealEngineeringCompanyRuntimeService::run()` direto de um controller novo sem ler esta ADR e o feature flag.
2. **NUNCA** declare esta ADR `implemented_ready` sem teste Feature E2E que cubra `POST /atlas-code/work/company` real e termine com Certification passed.
3. **NUNCA** promova flag para `default` sem 14 dias de `on` verde + Decision Receipt v2.
4. **NUNCA** remova o path legado nesta ADR; isso e escopo de Fase 5 fora deste documento.
5. **NUNCA** comece Fase 1 desta ADR enquanto Gap1 Fase 4 (`ATLAS_AIWORKER_KERNEL_ROUTED=true`) nao tiver shipped.
6. Quando observar latencia p99 >2x baseline em `shadow`, registre na secao "Evidencias" e bloqueie cutover para `on`.
7. Ao escrever AP de fase, herde o `claim_policy` desta ADR (todos os flags `false`).

## Escopo de Implementacao

| Fase | Entrega | Status | Bloqueador |
|---|---|---|---|
| 0 | ADR registrada (este doc) | done | n/a |
| 1 | Endpoint novo + recorder de paridade + flag `shadow` | **pending** | Gap1 Fase 4 |
| 2 | Operacao em `shadow` por 7d, coletando paridade e latencia | pending | Fase 1 ship |
| 3 | Cutover `on` com fallback automatico | pending | Fase 2 metricas verdes |
| 4 | Cutover `default` apos 14d em `on` | pending | Fase 3 metricas verdes + Decision Receipt v2 |
| 5 | Remocao do path legado | **fora desta ADR** | Fase 4 estavel + nova ADR |

Esta ADR entrega Fase 0 exclusivamente. Fases 1-5 exigem AP dedicado.

## Dependencias

**ADRs e docs canon que esta ADR consome:**

- `atlas-aiworker-kernel-integration-adr.md` (Gap1 PRE-REQ)
- `atlas-autonomous-software-company-runtime.md` (parent)
- `atlas-evidence-certification-runtime.md` (schema de receipts)
- `atlas-kernel-mission-foundation.md` (Mission lifecycle)
- `atlas-real-engineering-execution-kernel.md` (Kernel dispatch)
- `atlas-permission-budget-safety-layer.md` (Permission gate)
- `atlas-domain-company-runtimes.md` (path canonico)

**Servicos consumidos pela implementacao futura:**

- `app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php` (676 LOC, 9 metodos publicos)
- `app/Http/Controllers/AtlasCodeWorkController.php` (a ser estendido)
- `app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php` (path legado durante Fases 1-4)

**Tasks irmas:**

- Rename `BENCHMARK_SCHEMA` (spawned 2026-05-26).
- Gap1 Fase 4 ship (Kernel HTTP cutover).

## Evidencias

### Evidencia 1: Snapshot baseline (2026-05-26)

```
AtlasRealEngineeringCompanyRuntimeService:
  arquivo: app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php
  linhas: 676
  metodos publicos: 9
  schemas emitidos: 8 (engagement, cycle, role_run, review, qa_run, release_pack, benchmark*, certification)
  *benchmark sera renomeado antes desta ADR fechar
  ROLES canonicas: 9 departamentos
  entry HTTP atual: NENHUM
  entry CLI atual: AtlasAiEngineeringCompanyCommand

AtlasCodeWorkController:
  arquivo: app/Http/Controllers/AtlasCodeWorkController.php
  metodo store(): chama AtlasProgrammingOrchestrator direto
  referencia a Company Runtime: NENHUMA (grep verificado em 2026-05-26)
```

### Evidencia 2: Sequencia de receipts emitidos por run

Quando Company Runtime executa `run(goalText)`, emite (em ordem):

1. `atlas.ai.engineering_company.engagement.v1` (1 receipt)
2. `atlas.ai.engineering_company.cycle.v1` (1 receipt)
3. `atlas.ai.engineering_company.role_run.v1` (9 receipts — um por departamento)
4. `atlas.ai.engineering_company.review.v1` (1 receipt)
5. `atlas.ai.engineering_company.qa_run.v1` (1 receipt)
6. `atlas.ai.engineering_company.release_pack.v1` (1 receipt)
7. `atlas.ai.engineering_company.benchmark.v1` (1 receipt — rename pendente)
8. `atlas.ai.engineering_company.certification.v1` (1 receipt)

Total: **16 receipts por engagement** (nao 9 como estimativa inicial do plano Gap 3). Esta evidencia atualiza o budget de latencia: cada receipt e append-only ao ledger, e o overhead acumula.

### Evidencia 3: Vocabulario proibido encontrado

Constante `BENCHMARK_SCHEMA = 'atlas.ai.engineering_company.benchmark.v1'` (linha 33 do service) viola `claim_policy.benchmark=false` do Atlas. Rename track aberto em 2026-05-26.

## Riscos

| Risco | Severidade | Mitigacao |
|---|---|---|
| Latencia p99 explode (16 receipts vs 1 do legado) | Alto | Budget `<=2x baseline` declarado; medicao obrigatoria em Fase 2 antes de Fase 3 |
| Company Runtime falha silenciosa em prod | Alto | Fase `on` tem fallback automatico para legado; alerta em event_type signed |
| Operador promove `default` sem 14d em `on` | Alto | Decision Receipt v2 obrigatorio + script de gate `php artisan atlas:engineering-company-promotion --validate` (a ser shipped em Fase 4) |
| Gap1 Fase 4 nao completa, esta ADR fica bloqueada | Medio | Documentado explicitamente como pre-requisito; Phase 1 nao abre AP sem Gap1 ship |
| Paridade `shadow` mede coisas erradas | Medio | ParityRecorder com schema canonico + invariants list publicada em AP de Fase 1 |
| `BENCHMARK_SCHEMA` proibido nao renomeado a tempo | Medio | Tracker spawned 2026-05-26; bloqueador de Phase 1 ship |
| Endpoint novo `POST /atlas-code/work/company` quebra existing OpenAPI consumers | Baixo | Endpoint NOVO, nao reescreve `/atlas-code/work`; rota legada permanece |

## Exemplos

### Exemplo 1: AP de Fase 1 (template)

```yaml
ap_id: AP-XXX-company-runtime-http-shadow
title: Implementar endpoint shadow POST /atlas-code/work/company
depends_on:
  - atlas-engineering-company-runtime-http-promotion (esta ADR, Fase 0 done)
  - atlas-aiworker-kernel-integration-adr (Fase 4 done)
deliverables:
  - app/Http/Controllers/AtlasCodeWorkController.php (metodo storeCompany)
  - routes/api.php (POST /atlas-code/work/company)
  - app/Services/Ai/EngineeringCompany/ParityRecorderService.php (novo)
  - tests/Feature/AtlasCodeWorkControllerCompanyEndpointTest.php (novo)
flag_default: shadow
```

### Exemplo 2: Decision Receipt v2 para `shadow -> on`

```json
{
  "schema_version": "atlas.decision_receipt.v2",
  "issued_at": "2026-06-02T15:30:00Z",
  "actor": "operator:vitor",
  "action": "company_runtime_http_promotion.flag_transition",
  "from": "shadow",
  "to": "on",
  "evidence_refs": {
    "parity_report": "evidence/company_runtime_shadow_run/2026-05-26-to-2026-06-02-parity.json",
    "latency_report": "evidence/company_runtime_shadow_run/2026-05-26-to-2026-06-02-latency.json"
  },
  "metrics_observed": {
    "parity_byte_diff_pct_p50": 0.3,
    "parity_byte_diff_pct_p99": 4.1,
    "latency_p99_ratio_vs_baseline": 1.82,
    "shadow_runs_executed": 8421,
    "shadow_runs_failed": 12,
    "shadow_failure_rate_pct": 0.14
  },
  "gate_decisions": {
    "shadow_parity_7d_green": "passed",
    "latency_p99_within_2x_budget": "passed",
    "gap1_kernel_http_shipped": "passed"
  }
}
```

### Exemplo 3: Rollback emergencial

```
$ atlas config set ATLAS_HTTP_COMPANY_RUNTIME=off --reason "p99 latency exceeded 3x baseline"
Decision Receipt v2 emitted: storage/atlas/evidence/company_runtime_http_promotion/decision_receipts/2026-06-15T22-14-rollback.json
Traffic redirected to legacy path.
Next step: open AP-XXX-company-runtime-latency-hardening before retrying promotion.
```

## Proximas Acoes

1. **Aguardar Gap1 Fase 4 ship** (`ATLAS_AIWORKER_KERNEL_ROUTED=true` em prod, com kernel_routed=100% em 7d).
2. **Concluir rename de `BENCHMARK_SCHEMA`** (tracker spawned 2026-05-26) antes de abrir AP de Fase 1.
3. **Medir baseline de latencia** atual de `AtlasCodeWorkController::store()` legado em 24h de janela; persistir em `evidence/company_runtime_http_promotion/baseline-latency.json`.
4. **Abrir AP-XXX-company-runtime-http-shadow** com escopo de Fase 1 (endpoint shadow + ParityRecorderService + teste E2E).
5. **Definir invariants list** do ParityRecorderService (quais campos comparar bytewise, quais comparar semanticamente, quais ignorar).
