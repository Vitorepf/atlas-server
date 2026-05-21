---
id: atlas-ai-runtime-release-gate
type: engineering_knowledge
title: Atlas AI Runtime Release Gate
status: active
category: runtime
priority: 93
summary: Gate macro final para release do Atlas AI Hyperflow Runtime Principal.
tags:
  - atlas-ai
  - release
  - runtime
capabilities:
  - runtime_release_gate
decisions:
  - Release gate herda Runtime Readiness e nao inclui Rivals externos ou TEOS-I2.
maintenance:
  - Atualizar quando macro release ou runtime readiness mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-readiness.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-runtime-release-gate
graph_title: Atlas AI Runtime Release Gate
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-ai-runtime-readiness
graph_status: active
graph_source: repo
human_name: Atlas AI Runtime Release Gate
canonical_name: Atlas AI Runtime Release Gate
technical_name: atlas-ai-runtime-release-gate
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-ai-runtime-release-gate.md
owner: runtime-readiness
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-release-gate.md
allowed_changes:
  - Atualizar gate macro quando readiness upstream mudar.
forbidden_changes:
  - Incluir benchmark externo ou claim de superioridade neste macro.
depends_on:
  - atlas-ai-runtime-readiness
flows_to:
  - atlas-code
unlocks:
  - runtime-release-gate
governs:
  - runtime-release
evidence:
  - docs/engineering-knowledge-base/atlas-ai-runtime-release-gate.md
required_tests:
  - "php artisan atlas:ai:runtime-release-gate --json"
requires_evidence: true
risk_level: high
next_actions:
  - Manter precondicoes explicitas antes de TEOS-I2.
---
# Atlas AI Runtime Release Gate

## Resumo

Gate macro que decide se o runtime principal pode ser tratado como release ready.

## Papel no Atlas

Envelopar runtime readiness sem refazer subchecks.

## Onde Se Encaixa

Acima de Runtime Readiness e antes de macros futuros.

## Contratos

Nao inclui TEOS-I2, Rivals externos ou claims comparativas.

## Fluxo

Consome readiness upstream e emite ready/partial/blocked.

## Regras para IA

Nao abrir macro seguinte com gate partial ou blocked.

## Escopo de Implementacao

Release gate macro e hard fences.

## Dependencias

Runtime Readiness e Product Certification.

## Evidencias

Command output e upstream readiness.

## Riscos

Confundir macro principal com benchmark externo.

## Exemplos

`php artisan atlas:ai:runtime-release-gate --strict --json`.

## Proximas Acoes

Manter next_macro_recommendation honesto.

**Status:** ativo · entregue 2026-05-19
**Schema raiz:** `atlas.ai.runtime_release_gate.v1`
**Macro:** `atlas_ai_hyperflow_runtime_principal`
**Camada:** agregador macro acima dos readiness/certification services existentes.

---

## O que é

Gate final agregador que responde uma pergunta única — **o macro Atlas AI Hyperflow / Runtime Principal está pronto para release?** — emitindo `ready | partial | blocked`. Reúne evidências dos sub-systems sem refazer nenhum check.

**Não substitui:** Product Certification, Control Plane runtime, Router Runtime readiness, Mission Foundation, Mission Mode, Follow-Through, Approval Gates, Learning Loop, Desktop/Mobile UX cert. Cada um continua sendo a fonte autoritativa do seu domínio.

**Adiciona apenas:** moldura macro, `next_macro_recommendation` honesto, hash determinístico próprio.

## Como rodar

```
php artisan atlas:ai:runtime-release-gate --json
php artisan atlas:ai:runtime-release-gate --strict --json    # CI gate
```

Exit codes:

| Code | Quando |
|---|---|
| `0` | execução normal; status pode ser ready/partial/blocked. Em `--strict`, só `0` quando `ready`. |
| `1` | exceção runtime. |
| `3` | `--strict` + status ≠ ready (release bloqueado). |

## Estados

| Estado | Quando | O que significa |
|---|---|---|
| `ready`    | upstream readiness=`ready` (crítico=0, warn=0)                                      | Macro fechado com evidência. Safe abrir TEOS-I2 em macro separado. |
| `partial`  | upstream=`partial` (algum check em warn — UX cert ausente, Control Plane degradado) | Macro funcional mas com gaps; **não** abrir TEOS-I2 antes de fechar warnings. |
| `blocked`  | upstream=`blocked` (algum crítico falhou — Product Cert / Hyperflow / Mission)      | Macro com bloqueio crítico; resolver antes de qualquer próximo passo. |

## O que entra no macro

Herdado dos checks do `AtlasAiRuntimeReadinessService`:

- **Product Certification ready** (`atlas.ai.product_certification.v1`)
- **Control Plane runtime** (24h window)
- **Hyperflow V2 / Router Runtime** wired
- **Universal Composer canon** wired (validado pelo Product Cert)
- **Presentation Contract** wired em Mobile + Desktop (validado pelo Product Cert)
- **Specialist Flows** ready ou warn com gaps explícitos
- **Mission Mode** + **Follow-Through Loop** + **Operator Approval Gates** + **Memory & Learning Loop**
- **Desktop Hyperflow Integration UX Certification**
- **Forge handoff / canon payload** (validado pelo Product Cert)
- **Claim Policy** check (fence anti-superioridade/benchmark)

## O que fica fora

Por construção, este macro **não** inclui:

- TEOS-I2 (open in a separate macro certification)
- External rivals certification
- Benchmark / rivals runs
- Claim de superioridade vs Claude Code/Codex/Cursor
- Provider invocations
- External APIs reads
- Mutações de persistência

Estas fences vivem em `claim_policy.forbidden_claims` e são reforçadas pelo macro mesmo quando o upstream omite.

## Quando voltar para TEOS-I2

Só quando este macro estiver `ready`. O `next_macro_recommendation` reflete isso:

- `ready` → `next_macro = atlas_teos_i2_macro` com pré-condições explícitas
- `partial` → `next_macro = stay_in_atlas_ai_hyperflow_runtime_principal` + lista de gap actions
- `blocked` → `next_macro = stay_in_atlas_ai_hyperflow_runtime_principal` + blockers

A precondição `do_not_run_external_rivals_inside_this_macro` é hardcoded — TEOS-I2 é uma certificação separada, não um sub-passo deste macro.

## Hard rules

- **Hash determinístico.** `certification_hash = sha256(canonical_json(payload \ generated_at))`. Mesmos inputs → mesmo hash.
- **Sem leak de raw sensitive text.** O macro não compila payloads novos; herda strings já sanitizadas dos serviços upstream.
- **Fail-safe.** Status upstream desconhecido → `blocked`.
- **Claim policy é tighter-only.** Upstream pode tornar uma fence `true` (admite leak), mas não pode tornar `false` algo que o macro mantém `false`.
- **Não invoca provider, não roda rivals/benchmark.** Lê e agrega.
- **`required_commands`** sempre lidera com `php artisan atlas:ai:runtime-release-gate --json`.

## Tests

21 testes verdes em `tests/Feature/Ai/RuntimeReleaseGate/`:

- 14 service tests (status mapping, blockers/warnings propagation, claim policy fence, hash determinístico, hash muda quando status muda, upstream surfacing).
- 7 command tests (canonical shape, strict-3 quando partial/blocked, strict-0 quando ready, smoke com service real).

Regressão verde: 22 testes RuntimeReadiness + 79 Mission + 41 OperatorApproval — total 163/163.

## Pointers de código

| Arquivo | Papel |
|---|---|
| `app/Services/Ai/RuntimeReleaseGate/AtlasAiRuntimeReleaseGateService.php` | Aggregator macro. Delega para `AtlasAiRuntimeReadinessService`. |
| `app/Console/Commands/AtlasAiRuntimeReleaseGateCommand.php` | CLI `atlas:ai:runtime-release-gate [--strict] [--json]`. |
| `tests/Feature/Ai/RuntimeReleaseGate/` | Suite de testes (stub + command). |

## Limitações honestas

- **O macro não substitui o readiness.** Se você quer o snapshot detalhado com `ux_bundle`, continue usando `atlas:ai:runtime-readiness`. O release gate é a vista executiva ready/partial/blocked.
- **Não há TTL/cache.** Cada chamada é fresca. Para CI, prefira `--strict` e capture o JSON como artifact.
- **`next_macro_recommendation` é estático.** Não tenta predizer qual macro vem depois de TEOS-I2 — só sinaliza se é seguro avançar.
- **Claim policy é a única fonte de fences.** Não há cross-check com `AtlasAiPolicyService` aqui; vive no readiness check `claim_policy`.
- **Não há histórico.** Não há tabela `ai_runtime_release_gate_runs` — cada run é efêmero (read-only). Para histórico, persistir o JSON em CI artifact.
