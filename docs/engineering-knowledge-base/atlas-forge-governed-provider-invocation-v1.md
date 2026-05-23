---
id: atlas-forge-governed-provider-invocation-v1
type: engineering_knowledge
title: Atlas Forge Governed Provider Invocation v1
status: active
category: programming-forge
priority: 99
summary: Camada governada de invocacao de provider do Atlas Forge Continuum OS. Plan-only por padrao; execute exige aprovacao explicita do operador, budget aprovado, runtime dispatch confirmado e driver runtime configurado. Nunca chama provider externo por acidente.
tags:
  - atlas
  - forge
  - continuum
  - provider-invocation
  - governed
capabilities:
  - forge_provider_invocation
  - provider_invocation_dry_run
  - provider_invocation_execute_governed
  - atlas_local_executor
decisions:
  - Provider real nunca pode ser invocado sem `confirm_provider_call`, `confirm_budget` (quando externo) e `confirm_runtime_dispatch`.
  - Static-policy topologies nunca podem invocar provider.
  - Runtime dispatch plan precisa estar `dispatch_planned` + `runtime_dispatch_allowed=true` antes da invocacao.
  - Decision receipt id + hash sao obrigatorios.
  - `provider_driver_missing` bloqueia honestamente; nenhum stdout fake.
  - `atlas-local` tem executor local seguro deterministico que NAO chama provider externo nem gasta token.
  - Completion claim nao e promovido pelo invocation service.
  - Review/completion gate preservado.
maintenance:
  - Atualizar este doc antes de alterar Service/Driver Router/Prompt Builder/Command/Controller ou tabela `atlas_ledger_events`.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php
  - app/Console/Commands/AtlasForgeProviderInvokeCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-governed-provider-invocation-v1
graph_title: Atlas Forge Governed Provider Invocation v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-continuum-os
graph_status: active
graph_source: repo
human_name: Atlas Forge Governed Provider Invocation v1
canonical_name: Atlas Forge Governed Provider Invocation v1
technical_name: atlas-forge-governed-provider-invocation-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php
  - app/Console/Commands/AtlasForgeProviderInvokeCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php
evidence:
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationPromptBuilder.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderInvocationTest.php
allowed_changes:
  - Adicionar runtime drivers governados apenas com policy/receipt/UI/teste cobrindo.
  - Adicionar invariantes de invocation quando ledger ou hash forem expandidos.
forbidden_changes:
  - Permitir invocation real sem `confirm_provider_call` + `confirm_budget` + `confirm_runtime_dispatch`.
  - Permitir invocation a partir de topology static.
  - Permitir invocation sem decision receipt id + hash.
  - Promover completion claim a partir do invocation service.
  - Inventar stdout fake como provider real.
depends_on:
  - atlas-forge-continuum-os
  - atlas-forge-provider-topology-and-fallback-v1
  - atlas-decide
flows_to:
  - atlas-code-forge-review-completion-gate-v1
  - programming-professional-completion-audit
unlocks:
  - one-shot-software-construction
  - governed-provider-execution
governs:
  - forge-provider-invocation
  - atlas-local-executor
required_tests:
  - "php artisan test --filter=AtlasForgeProviderInvocationTest"
  - "php artisan atlas:forge:provider-invoke --json --strict"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - provider-invocation
  - governed
  - dry-run
ai_entrypoints:
  - Leia este doc antes de alterar invocation, driver router, prompt builder, command CLI ou endpoint REST.
ai_usage_notes:
  - Dry-run e padrao seguro. Execute exige flags explicitas + driver configurado + receipt + dispatch.
quality_gates:
  - obra-bound
  - atlas-decide-receipt-present
  - runtime-dispatch-allowed
  - operator-provider-approval
  - budget-approval-when-external
  - provider-driver-configured
  - review-completion-gate-preserved
  - completion-claim-not-promoted
failure_modes:
  - Invocation acidental sem aprovacao.
  - Provider externo invocado por static policy.
  - Completion claim promovido por output do provider.
  - Driver fake retornando stdout sintetico.
  - Tokens gastos sem budget aprovado.
observability_signals:
  - invocation_id
  - dispatch_id
  - decision_receipt_id
  - provider
  - model
  - provider_called
  - external_provider_call
  - stdout_hash
  - stderr_hash
  - ledger_event_ids
next_actions:
  - Configurar runtime drivers governados para claude_cli/codex_cli/gemini_cli quando custo e aprovacao operador estiverem prontos.
  - Adicionar telemetry de duration por role no cockpit.
---
# Atlas Forge Governed Provider Invocation v1

## Resumo

Transforma um `runtime_dispatch_plan` em uma invocacao governada de provider.

Em modo `dry_run` (padrao), prepara plan + prompt + receipt sem chamar provider externo. Em modo `execute`, ainda assim so chama provider real quando:

- `confirm_provider_call=true`;
- `confirm_runtime_dispatch=true`;
- `confirm_budget=true` (apenas quando o driver chama provider externo; `atlas-local` nao exige);
- driver runtime configurado (hoje apenas `atlas-local`);
- decision receipt id + hash presentes;
- runtime_dispatch_allowed=true;
- role canonica;
- capacity nao exaurida.

Se qualquer condicao falhar, retorna `blocked` com blocker honesto. Nunca finge sucesso.

## Papel no Atlas

```text
Atlas Code
 → Obra
 → Fast Path
 → Atlas Decide
 → Provider Topology
 → Runtime Dispatch Plan
 → Governed Provider Invocation (este modulo)
 → Invocation Receipt + Evidence Pack
 → Review Gate
 → Completion Claim
```

## Onde Se Encaixa

```text
Programming Domain
└─ programming.forge
   └─ Atlas Forge Continuum OS
      ├─ Atlas Code Surface (SCOR-1)
      ├─ Obra + Forge Workspace
      ├─ Atlas Decide → Provider Topology v1
      ├─ Runtime Dispatch v1 (dispatch plan + child receipt)
      ├─ Governed Provider Invocation v1 (este modulo)
      │  ├─ Driver Router (atlas-local + provider_driver_missing para externos)
      │  ├─ Prompt Builder (atlas.forge.provider_invocation_prompt.v1)
      │  ├─ Invocation Service (13 gates)
      │  ├─ Invocation Receipt (sha256 receipt_hash)
      │  ├─ Evidence Ledger (PROVIDER_INVOCATION_* subtypes)
      │  └─ State Projection + Cockpit UI
      ├─ Review/Completion Gate (humano)
      └─ Rivals (separado de claim externo)
```

Este eixo fica entre Runtime Dispatch e Review Gate: ele e o ponto onde o
Forge poderia, em tese, invocar provider externo — e onde o Atlas obriga
flags explicitas + driver runtime configurado antes de qualquer chamada
real.

## Contratos

| Schema | Producer | Consumer |
|---|---|---|
| `atlas.forge.provider_invocation.v1` | `AtlasForgeProviderInvocationService` | Cockpit, state projection, audit |
| `atlas.forge.provider_invocation_receipt.v1` | mesmo | Evidence ledger, replay, audit |
| `atlas.forge.provider_invocation_prompt.v1` | `AtlasForgeProviderInvocationPromptBuilder` | Driver router |
| `atlas.forge.provider_invocation_plan.v1` | `AtlasForgeProviderInvocationDriverRouter::plan` | Read-model do plan-only |
| `atlas.forge.provider_invocation_driver_result.v1` | Driver runtime | Service finalize |

## Fluxo

```text
1. Operator triggers atlas:forge:provider-invoke (CLI) or POST /forge/provider-invocations (API).
2. Service loads Obra + latest runtime dispatch + topology + decision receipt.
3. 13 gates validate Obra/dispatch/receipt/role/budget/operator/driver.
4. Prompt Builder compiles atlas.forge.provider_invocation_prompt.v1.
5. Driver Router plans (`dry_run`) or invokes (`execute`) the runtime driver.
6. Service captures stdout_hash, stderr_hash, exit_code, duration_ms.
7. Receipt is computed (sha256 over canonical payload) and persisted with the projection.
8. Ledger events recorded with subtypes PROVIDER_INVOCATION_PLANNED/BLOCKED/STARTED/COMPLETED/FAILED/TIMED_OUT.
9. State projection exposes forge_provider_invocation + forge_provider_invocation_receipt.
10. Cockpit renders status + provider + model + completion gate + blockers.
```

## Regras para IA

- Nunca invocar provider sem flags explicitas.
- Nunca promover completion claim.
- Nunca bypassar review/completion gate.
- Nunca esconder blockers para deixar audit verde.
- Nunca inventar stdout fake como provider real.

## Escopo de Implementacao

Backend:

- `AtlasForgeProviderInvocationService` (schema `atlas.forge.provider_invocation.v1`)
- `AtlasForgeProviderInvocationDriverRouter` (atlas-local seguro; outros providers retornam `provider_driver_missing`)
- `AtlasForgeProviderInvocationPromptBuilder` (schema `atlas.forge.provider_invocation_prompt.v1`)
- `AtlasForgeProviderInvokeCommand` (`atlas:forge:provider-invoke`)
- `AtlasCodeForgeProviderInvocationController` (`POST /forge/provider-invocations`, `GET /forge/provider-invocations/latest`)
- `AtlasCodeWorkController::state()` expoe `forge_provider_invocation` + `forge_provider_invocation_receipt`
- `ProgrammingProfessionalCompletionAuditService` bloco `atlas_forge_provider_invocation_certification`

Desktop:

- Tipos `AtlasForgeProviderInvocationPlan`/`AtlasForgeProviderInvocationReceipt` em `@atlas/domain`.
- Bridge `runForgeProviderInvocation`/`getForgeProviderInvocationLatest`.
- Cockpit panel com 3 checkboxes (confirm provider call, confirm budget, confirm runtime dispatch) + role select + mode select.

Testes (19):

- fails closed sem Obra
- blocks sem runtime dispatch
- static policy block
- dry_run plan com live dispatch
- execute requer operator approval
- execute requer budget approval (provider externo)
- requer decision receipt hash
- provider driver missing
- atlas-local safe
- receipt persistido
- output hashes recorded
- ledger events available
- timeout invalido
- state expoe invocation
- API POST + GET latest
- completion audit cert
- completion claim nao promovido
- external rivals separado
- CLI strict sem obra exit 1

## Dependencias

- Decision Receipt v2 (Atlas Decide)
- Provider Topology v1
- Runtime Dispatch v1
- AtlasEvidenceLedger (best-effort)

## Evidencias

- Comando CLI: `php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=dry_run --json --strict`
- Endpoint: `POST /atlas-code/works/{project}/forge/provider-invocations`
- State endpoint: `GET /atlas-code/works/{project}/state` → `forge_provider_invocation` + `forge_provider_invocation_receipt`
- Audit block: `atlas_forge_provider_invocation_certification` em `php artisan atlas:programming:completion-audit --json`

## Riscos

- Operador habilita execute com driver real configurado e budget insuficiente. Mitigacao: `BLOCKER_BUDGET_APPROVAL_REQUIRED` so deixa passar com `confirm_budget=true`.
- Ledger table ausente. Mitigacao: service reporta `ledger_available=false` e nao falha.
- Stdout enorme. Mitigacao: `max_output_chars` corta excerpt; hashes ainda capturam diff.
- Atlas-local executor sintetico. Mitigacao: marca `provider_called=false` e `external_provider_call=false`; tests asseguram.

## Exemplos

### Exemplo 1 — Dry-run com dispatch live

`atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=dry_run --json --strict`
→ status `planned`, `provider_called=false`, `external_provider_call=false`, receipt persistido.

### Exemplo 2 — Execute sem confirmations

`atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --json --strict`
→ status `blocked`, blockers `operator_provider_approval_required`, `runtime_dispatch_confirmation_required`, `budget_approval_required` (quando provider externo).

### Exemplo 3 — Execute atlas-local com confirmations minimas

`atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --confirm-provider-call --confirm-runtime-dispatch --json --strict`
→ atlas-local executor produz stdout deterministico, `provider_called=false`, `external_provider_call=false`, receipt persistido com `stdout_hash`/`stderr_hash`.

### Exemplo 4 — Execute provider externo sem driver configurado

`atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --confirm-provider-call --confirm-budget --confirm-runtime-dispatch --json --strict`
→ status `blocked`, blocker `provider_driver_missing`, `provider_called=false`. Honesto: o driver ainda nao foi configurado.

## Proximas Acoes

- Configurar driver runtime governado para claude_cli/codex_cli/gemini_cli (apenas com aprovacao operador + budget gate + UI confirmacao).
- Adicionar persistencia explicita de prompts hash em tabela dedicada quando volume justificar.
- Conectar `evidence_pack_hash` a um Evidence Pack real quando bateria provider rodar.
