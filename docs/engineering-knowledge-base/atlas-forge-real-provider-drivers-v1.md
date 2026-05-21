---
id: atlas-forge-real-provider-drivers-v1
type: engineering_knowledge
title: Atlas Forge Governed Real Provider Drivers v1
status: active
category: programming-forge
priority: 98
summary: Drivers reais governados para claude_cli, codex_cli e gemini_cli no Atlas Forge Governed Provider Invocation. Plan-only por padrao; execute real exige 3 confirmacoes + budget + dispatch + capacity + driver configurado. Atlas-local continua executor seguro.
tags:
  - atlas
  - forge
  - continuum
  - provider-drivers
  - governed
  - cli
capabilities:
  - forge_real_provider_drivers
  - provider_driver_router_v2
  - safe_process_runner
  - provider_command_allowlist
  - real_provider_failure_classifier
decisions:
  - Provider real so pode ser chamado quando os 13 gates de invocation + driver configurado + allowlist + capacity estiverem todos verdes.
  - atlas-local continua executor seguro deterministico; nunca chama provider externo.
  - claude_cli/codex_cli/gemini_cli sao drivers governados; bloqueiam honestamente se runtime ou auth ausente.
  - Comandos sao argv array; nunca shell raw.
  - Output e capturado com sha256; secrets sao redacted antes do excerpt.
  - Failure classifier mapeia exit/stdout/stderr para canonical failure types.
  - Completion claim nunca promovido pela camada de drivers.
maintenance:
  - Atualizar este doc antes de alterar AtlasForgeProviderInvocationDriver, allowlist, safe runner, drivers concretos ou router v2.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php
  - app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php
  - app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeClaudeCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationFailureClassifier.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php
  - app/Console/Commands/AtlasForgeProviderInvokeCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderInvocationController.php
  - tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-real-provider-drivers-v1
graph_title: Atlas Forge Governed Real Provider Drivers v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Governed Real Provider Drivers v1
canonical_name: Atlas Forge Governed Real Provider Drivers v1
technical_name: atlas-forge-real-provider-drivers-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php
  - app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php
  - app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeClaudeCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationFailureClassifier.php
  - tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php
evidence:
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderProcessRunner.php
  - tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php
allowed_changes:
  - Adicionar novos drivers governados apenas com allowlist + safe runner + tests + audit invariants.
  - Estender failure classifier para sinais novos quando provider mudar.
forbidden_changes:
  - Spawnar provider via shell string crua.
  - Tratar provider_driver_missing como sucesso.
  - Permitir execute real sem 3 confirmations + budget + dispatch + capacity verde.
  - Promover completion claim via driver result.
  - Mentir provider_tokens_spent=false quando driver real foi invocado.
depends_on:
  - atlas-forge-governed-provider-invocation-v1
  - atlas-forge-provider-topology-and-fallback-v1
flows_to:
  - atlas-code-forge-review-completion-gate-v1
  - programming-professional-completion-audit
unlocks:
  - one-shot-software-construction-with-external-providers
governs:
  - atlas-forge-real-provider-drivers
required_tests:
  - "php artisan test --filter=AtlasForgeRealProviderDriversTest"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - provider-drivers
  - governed
ai_entrypoints:
  - Leia este doc antes de alterar drivers reais, allowlist, safe runner, failure classifier ou router v2.
ai_usage_notes:
  - atlas-local continua executor seguro; drivers reais bloqueiam honestamente sem configuracao local.
quality_gates:
  - obra-bound
  - atlas-decide-receipt-present
  - runtime-dispatch-allowed
  - operator-provider-approval
  - budget-approval-when-external
  - provider-driver-configured
  - command-allowlist-passed
  - capacity-not-exhausted
  - timeout-bounded
  - output-hashed
  - failure-classified
  - failure-memory-recorded-on-error
  - review-completion-gate-preserved
failure_modes:
  - Driver real executa sem allowlist permitindo shell injection.
  - Provider externo invocado sem 3 confirmations.
  - Receipt sem hash de output.
  - Failure classifier classifica erro real como provider_error generico mascarando rate_limit/quota/auth.
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
  - duration_ms
  - exit_code
  - failure_type
  - failure_classification_confidence
next_actions:
  - Conectar evidence pack hash a provider invocation receipt quando bateria real rodar.
  - Adicionar telemetry de duration/exit_code por provider no cockpit.
---
# Atlas Forge Governed Real Provider Drivers v1

## Resumo

Substitui o blocker `provider_driver_missing` por drivers reais governados para `claude_cli`, `codex_cli` e `gemini_cli`. Sem reduzir nenhum gate: execute real continua exigindo `--confirm-provider-call` + `--confirm-budget` (externo) + `--confirm-runtime-dispatch` + driver configurado + dispatch live + capacity OK.

Mantem `atlas-local` como executor seguro deterministico. Quando nenhum CLI estiver instalado/auth configurada, drivers bloqueiam honestamente com `provider_driver_not_configured`.

## Papel no Atlas

Camada runtime entre o `AtlasForgeProviderInvocationService` e os binarios CLI dos provedores. Mantem:

- contrato unico `AtlasForgeProviderInvocationDriver`;
- argv array sempre validado pela `AtlasForgeProviderCommandAllowlistService`;
- spawn via `AtlasForgeProviderProcessRunner` (Symfony Process com timeout, sem shell);
- captura sha256 stdout/stderr + excerpt redacted;
- classificacao deterministica `AtlasForgeProviderInvocationFailureClassifier`.

## Onde Se Encaixa

```text
Atlas Code Surface (SCOR-1)
└─ Atlas Forge Continuum OS
   ├─ Obra + Forge Workspace
   ├─ Atlas Decide → Provider Topology → Runtime Dispatch v1
   ├─ Governed Provider Invocation v1
   │  ├─ AtlasForgeProviderInvocationService (13 gates)
   │  ├─ Prompt Builder
   │  └─ Driver Router v2 (este modulo)
   │     ├─ atlas-local (preservado)
   │     ├─ claude_cli driver
   │     ├─ codex_cli driver
   │     └─ gemini_cli driver
   ├─ Allowlist + Safe Process Runner + Failure Classifier
   ├─ Invocation Receipt + Evidence Ledger
   ├─ Review/Completion Gate
   └─ Rivals (separado de claim externo)
```

## Contratos

| Schema | Producer | Consumer |
|---|---|---|
| `atlas.forge.provider_driver_config_status.v1` | drivers / router | CLI/API/state |
| `atlas.forge.provider_driver_plan.v1` | drivers / router | dry-run + tests |
| `atlas.forge.provider_driver_result.v1` | drivers | Invocation Service |
| `atlas.forge.provider_process_result.v1` | SafeProcessRunner | drivers |
| `atlas.forge.provider_invocation_failure_classification.v1` | classifier | service + ledger |
| `atlas.forge.provider_command_allowlist.v1` | allowlist | drivers |
| `atlas.forge.provider_driver_router_status.v1` | router | CLI/API/state |
| `atlas.forge.provider_driver_plan_packet.v1` | controller plan-driver | API |
| `atlas.forge_real_provider_drivers_certification.v1` | audit | completion |

## Fluxo

```text
1. Service decides dry_run/execute and assembles request (role, prompt, dispatch).
2. Router resolves provider → driver instance.
3. Driver.configured() inspects local env (binary on PATH + auth env vars).
4. Driver.plan(request) returns argv preview + allowlist verdict (no spawn).
5. On execute: allowlist validates argv → SafeProcessRunner spawns binary
   with explicit argv array, stdin prompt, timeout. Output captured.
6. Failure classifier maps result to canonical failure_type.
7. Service finalises invocation + receipt + ledger event.
8. UI shows driver_status, plan_safe, blockers, output hashes.
```

## Regras para IA

- Nunca usar shell string para spawn.
- Nunca skipar allowlist.
- Nunca classificar erro real como provider_error generico se houver match para rate_limit/quota/auth/context/timeout.
- Nunca promover completion claim a partir de driver result.

## Escopo de Implementacao

Backend:

- `AtlasForgeProviderInvocationDriver` (interface)
- `AtlasForgeProviderCommandAllowlistService`
- `AtlasForgeProviderProcessRunner` (Symfony Process array, timeout, hashes, redact)
- `AtlasForgeBaseCliInvocationDriver` (template metodos compartilhados)
- `AtlasForgeClaudeCliInvocationDriver`
- `AtlasForgeCodexCliInvocationDriver`
- `AtlasForgeGeminiCliInvocationDriver`
- `AtlasForgeProviderInvocationFailureClassifier`
- `AtlasForgeProviderInvocationDriverRouter` v2 (registro + driverStatus + driverPlan + driverInvoke)
- CLI: `atlas:forge:provider-invoke --driver-status` + `--plan-driver` + `--provider-timeout`
- API: `GET /forge/provider-invocations/drivers` + `POST /forge/provider-invocations/plan-driver`
- State projection: `forge_provider_driver_status`
- Audit: `atlas_forge_real_provider_drivers_certification`

Desktop:

- bridge + useBridge + RightRail receive `forgeProviderDriverStatus` ja preparado pelos passes anteriores.
- Painel topology mostra driver status quando exposto.

Testes (27 + integration with capacity/runtime/audit):

- driver router lista atlas-local/claude/codex/gemini
- driver status nunca chama provider externo
- missing CLI retorna provider_driver_not_configured
- allowlist bloqueia shell injection e binarios proibidos
- safe runner timeouts + hashes
- claude/codex/gemini drivers bloqueiam quando nao configurados
- execute exige 3 confirmations
- capacity exhausted bloqueia antes do driver
- atlas-local driver executa safe
- failure classifier detecta rate_limit/quota/auth/context/timeout
- invocation receipt inclui hashes
- driver status endpoint / plan-driver endpoint canonicos
- state endpoint expoe driver_status
- completion audit expoe drivers cert
- provider_driver_missing preservado para provider desconhecido
- completion claim nunca promove
- external_rivals continua separado

## Dependencias

- Atlas Forge Governed Provider Invocation v1
- Provider Topology + Capacity + Failure Memory
- AtlasEvidenceLedger (best-effort)
- Symfony Process

## Evidencias

- `php artisan atlas:forge:provider-invoke --driver-status --json`
- `php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --plan-driver --json`
- `php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --json --strict` → blocked sem flags
- `php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute --confirm-provider-call --confirm-budget --confirm-runtime-dispatch --json --strict` → executa quando driver real configurado; bloqueia honestamente quando nao
- `GET /atlas-code/works/{project}/forge/provider-invocations/drivers`
- `POST /atlas-code/works/{project}/forge/provider-invocations/plan-driver`
- `GET /atlas-code/works/{project}/state` → `forge_provider_driver_status`

## Riscos

- Operador instala CLI externo sem configurar capacity policy. Mitigacao: capacity exhausted continua blocker terminal antes do driver.
- Token gasto inesperadamente. Mitigacao: `provider_tokens_spent='unknown'` quando driver real e invocado; UI mostra warning forte.
- Stdout enorme. Mitigacao: redact + excerpt + hashes; receipt nao guarda raw content.
- Driver real spawnando comando errado. Mitigacao: allowlist canonica + argv array.

## Exemplos

### Exemplo 1 — Driver status sem provider call

```bash
php artisan atlas:forge:provider-invoke --driver-status --json
```

Retorna `atlas.forge.provider_driver_router_status.v1` com 4 drivers; nenhum CLI foi spawnado.

### Exemplo 2 — Plan driver com obra real

```bash
php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --plan-driver --json
```

Retorna invocation dry-run + driver plan packet com argv preview; `provider_called=false`.

### Exemplo 3 — Execute real bloqueado por driver missing

Quando claude_cli nao esta instalado na maquina, com flags habilitadas:

```bash
php artisan atlas:forge:provider-invoke --obra=<uuid> --role=primary_builder --mode=execute \
  --confirm-provider-call --confirm-budget --confirm-runtime-dispatch --json --strict
```

Status `blocked`, blocker `provider_driver_missing` ou `provider_driver_not_configured`. Honest.

### Exemplo 4 — Execute atlas-local (sem provider externo)

Dispatch precisa apontar `atlas-local`/`atlas-runtime`. atlas-local executor produz stdout deterministico; `provider_called=false`, `external_provider_call=false`, `provider_tokens_spent=false`.

## Proximas Acoes

- Conectar evidence pack hash ao invocation receipt.
- Adicionar dashboard com duration/exit_code por driver no cockpit.
- Promover drivers reais por padrao quando bateria provider real rodar sob aprovacao operador.
