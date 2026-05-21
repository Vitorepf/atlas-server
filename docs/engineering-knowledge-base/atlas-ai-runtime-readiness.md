---
id: atlas-ai-runtime-readiness
type: engineering_knowledge
title: Atlas AI Runtime Readiness
status: active
category: runtime
priority: 93
summary: Agregador canonico que responde se o runtime Atlas AI esta pronto para release.
tags:
  - atlas-ai
  - runtime
  - readiness
capabilities:
  - runtime_readiness
decisions:
  - Runtime readiness agrega checks existentes sem substituir suas fontes autoritativas.
maintenance:
  - Atualizar quando sub-certifications ou readiness services mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-release-gate.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-runtime-readiness
graph_title: Atlas AI Runtime Readiness
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Atlas AI Runtime Readiness
canonical_name: Atlas AI Runtime Readiness
technical_name: atlas-ai-runtime-readiness
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-runtime-readiness.md
owner: runtime-readiness
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-readiness.md
allowed_changes:
  - Atualizar checks agregados junto com codigo e testes.
forbidden_changes:
  - Declarar ready ignorando critical_failed.
depends_on:
  - atlas-ai-product-certification
flows_to:
  - atlas-code
unlocks:
  - runtime-release-readiness
governs:
  - runtime-readiness
evidence:
  - docs/engineering-knowledge-base/atlas-ai-runtime-readiness.md
required_tests:
  - "php artisan atlas:ai:runtime-readiness --json"
requires_evidence: true
risk_level: high
next_actions:
  - Manter agregacao honesta de critical e warn checks.
---
# Atlas AI Runtime Readiness & Release Gate

## Resumo

Agrega readiness/certification services em decisao unica ready/partial/blocked.

## Papel no Atlas

Servir como fonte de prontidao macro do runtime principal.

## Onde Se Encaixa

Acima de Product Certification, Router Runtime e Mission layers.

## Contratos

Critical failed bloqueia ready.

## Fluxo

Executa subchecks, normaliza status e calcula certification_hash.

## Regras para IA

Nao declarar release quando status for partial ou blocked.

## Escopo de Implementacao

Readiness aggregate e command JSON/strict.

## Dependencias

Product, control plane, router, specialist flows e mission stack.

## Evidencias

Command output e checks agregados.

## Riscos

Mascarar falhas criticas em macro ready.

## Exemplos

`php artisan atlas:ai:runtime-readiness --strict --json`.

## Proximas Acoes

Manter subchecks e severity coerentes.

**Status:** ativo · entregue 2026-05-19
**Schema raiz:** `atlas.ai.runtime_readiness.v1`
**Comando canon:** `php artisan atlas:ai:runtime-readiness --json [--strict]`

---

## O que é

Single source of truth — "o runtime Atlas AI está pronto para release?". Agrega TODOS os readiness/certification services existentes em uma decisão única `ready | partial | blocked` com `certification_hash` determinístico.

**Não substitui** nenhum dos services agregados — apenas os chama e normaliza o status. Cada sub-cert continua sendo a fonte de verdade da sua camada.

## Camadas agregadas (11 checks canon)

| id | label | severity | source service |
|---|---|---|---|
| `product_certification` | E2E plumbing (Mobile + Desktop + Server + Forge) | critical | `AtlasAiProductCertificationService::certify()` |
| `control_plane_runtime` | Runtime aggregate 24h (traces, blockers, handoffs) | warn | `AtlasAiControlPlaneService::report(24)` |
| `router_runtime_readiness` | Hyperflow V2 5-stage pipeline | critical | `RouterRuntimeReadinessService::report()` |
| `specialist_flows_readiness` | 14 specialist flows registered | critical | `AtlasHyperflowSpecialistFlowsReadinessService::report()` |
| `mission_foundation_readiness` | 6 tabelas + 7 services + lifecycle guard | critical | `MissionReadinessService::report()` |
| `mission_mode_layer` | Mission Mode service + CLI (`detect/create/certify`) | critical | `MissionModeService` + `AtlasAiMissionCommand` |
| `follow_through_loop` | Follow-Through service + CLI (`run/until-blocked`) | critical | `MissionFollowThroughService` + command flags |
| `operator_approval_gates` | Service + `ai_operator_approvals` table + CLI | critical | `OperatorApprovalGateService` + `AtlasAiApprovalCommand` |
| `memory_learning_loop` | Service + `ai_learning_signals/proposals` tables + CLI | critical | `AtlasAiLearningLoopService` + `AtlasAiLearningCommand` |
| `desktop_hyperflow_integration` | Desktop ↔ Hyperflow ↔ Rich Input integration | warn | `AtlasDesktopHyperflowIntegrationCertificationService::certify()` |
| `claim_policy_canon` | No benchmark / no rivals / no superiority | critical | `AtlasAiRuntimeReadinessService` (hardcoded) |

## Status semantics

- **`ready`** — TODOS os checks passaram. Critical_failed=0 AND warn_failed=0.
- **`partial`** — Pelo menos 1 check warn falhou. critical_failed=0 AND warn_failed>0.
- **`blocked`** — Pelo menos 1 check critical falhou. critical_failed>0.

## Como rodar

```bash
# Snapshot completo (não falha)
php artisan atlas:ai:runtime-readiness --json

# Modo CI: exit 3 quando não-ready
php artisan atlas:ai:runtime-readiness --strict --json
```

Exit codes:
- `0` — operação executada (status pode ser ready/partial/blocked)
- `1` — runtime exception
- `3` — `--strict` + status != ready (gate falhou)

## Output canon

```json
{
  "ok": true,
  "action": "runtime-readiness",
  "strict": false,
  "report": {
    "schema_version": "atlas.ai.runtime_readiness.v1",
    "status": "ready",
    "generated_at": "2026-05-19T17:24:12+00:00",
    "summary": {
      "total": 11,
      "passed": 11,
      "partial": 0,
      "failed": 0,
      "critical_failed": 0,
      "warn_failed": 0
    },
    "checks": [
      {
        "id": "product_certification",
        "label": "Atlas AI product certification (E2E plumbing)",
        "status": "passed",
        "severity": "critical",
        "source_service": "App\\Services\\Ai\\Product\\AtlasAiProductCertificationService",
        "evidence_refs": ["php artisan atlas:ai:product-certify --json"],
        "detail": {
          "product_certification_status": "ready",
          "passed": 12,
          "critical_failed": 0,
          "warn_failed": 0,
          "certification_hash": "9f05c861...",
          "remaining_blockers": []
        }
      }
    ],
    "blockers": [],
    "warnings": [],
    "evidence_refs": ["php artisan atlas:ai:product-certify --json", "..."],
    "required_commands": [
      "php artisan atlas:ai:product-certify --json",
      "php artisan atlas:ai:control-plane runtime --hours=24 --json",
      "php artisan atlas:ai:mission detect --goal=\"...\" --json",
      "php artisan atlas:ai:mission run --mission=<uuid> --json",
      "php artisan atlas:ai:approval list --json",
      "php artisan atlas:ai:learning collect --hours=24 --json",
      "php artisan atlas:ai:runtime-readiness --json"
    ],
    "claim_policy": {
      "declares_benchmark": false,
      "declares_rivals": false,
      "declares_superiority": false,
      "declares_teos_certification": false,
      "invokes_provider": false,
      "scope": "atlas_ai_runtime_release_gate",
      "forbidden_claims": [
        "better_than_claude_code",
        "better_than_codex",
        "better_than_cursor",
        "beats_benchmark_x",
        "wins_arena_y",
        "teos_certified_unless_explicitly_proven"
      ]
    },
    "release_scope": "atlas_ai_runtime",
    "certification_hash": "f3a8...deterministic..."
  }
}
```

## O que entra no release scope

- Hyperflow V2 + Router Runtime
- Universal Composer Runtime + Rich Input canon
- Response Presentation Contract
- Forge/Obras intake + rich input preservation
- Product certification E2E
- Control Plane aggregate
- Mission Foundation
- Mission Mode
- Autonomous Follow-Through Loop
- Operator Approval Gates
- Memory & Learning Feedback Loop
- Desktop Hyperflow Integration

## O que fica fora explicitamente

- ❌ **TEOS** — certificação independente, não unlocada por este gate
- ❌ **Benchmark / arena / rivals** — declarado proibido em `claim_policy`
- ❌ **Provider invocation** — readiness é read-only
- ❌ **Superiority claims** (Claude Code, Codex, Cursor) — hardcoded em `forbidden_claims`
- ❌ **External rivals certification** — não unlocada

## Honesty rules

1. `ready` exige TODOS os 9 critical checks `passed` E zero warn. Docs sozinha NÃO contam.
2. Cada check exige evidência verificável (`service resolve`, `certify passed`, `file exists`, `command signature`).
3. Falhas silenciosas (exception) → tratadas como `failed` (severity critical → blocked).
4. `certification_hash` é determinístico SHA-256 sobre canonical payload sem `generated_at`. Mesmo estado → mesmo hash.
5. `claim_policy` é hardcoded; mudar exige decisão de produto E nova versão de schema.

## Limitações reais

- Snapshot é read-only por construção; estado dinâmico (control_plane_runtime) pode variar entre chamadas — apenas o `certification_hash` reflete a snapshot canon.
- `control_plane_runtime` é `warn` (não `critical`) porque pode reportar `watch` em janela de 24h sem traces — não bloqueia release.
- `desktop_hyperflow_integration` é `warn` porque depende de paths cross-package; falha não-crítica para o backend.
- Sem UI dedicada — backend-first; consumir via comando JSON.

## Relação com peças adjacentes

| Peça | Relação |
|---|---|
| Product Certification | Sub-cert agregada; AtlasAiRuntimeReadinessService NÃO substitui, apenas chama `certify()` |
| Control Plane | Sub-cert agregada via `report(24)` |
| Router Runtime / Hyperflow / Specialist Flows | Sub-certs agregadas via readiness services |
| Mission Foundation / Mode / Follow-Through | Sub-cert agregada (sequencial) |
| Approval Gates / Learning Loop | Sub-cert agregada via alive-check (classe + tabela + comando) |
| TEOS | Fora de escopo. claim_policy declara explicitamente `declares_teos_certification: false` |
| Benchmark / Rivals | Fora de escopo. claim_policy declara explicitamente `declares_rivals: false` + lista forbidden |

## Arquivos

- `app/Services/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessService.php`
- `app/Console/Commands/AtlasAiRuntimeReadinessCommand.php`
- `tests/Feature/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessServiceTest.php`
- `tests/Feature/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessCommandTest.php`
