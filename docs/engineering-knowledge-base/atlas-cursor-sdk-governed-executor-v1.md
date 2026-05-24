---
id: atlas-cursor-sdk-governed-executor-v1
type: engineering_knowledge
title: Atlas Cursor SDK Governed Executor v1
status: active
category: programming-forge
priority: 98
implementation_state: experimental_driver_fail_closed
summary: Contrato canonico e implementacao experimental fail-closed do Cursor SDK como provider-harness governado no Atlas Forge, usando @cursor/sdk via adapter Node Atlas-owned sem permitir provider/model/scope/completion authority ao Cursor.
tags:
  - atlas
  - cursor
  - sdk
  - forge
  - provider-harness
  - governed-executor
capabilities:
  - cursor_sdk_governed_executor
  - cursor_sdk_provider_harness_absorption
  - cursor_sdk_local_agent_adapter
  - cursor_account_usage_bucket_tracking
decisions:
  - Cursor SDK e provider-harness, nao modelo bruto, dominio, surface ou substituto do Atlas.
  - A integracao SDK continua oficial para API/Cloud Agent, mas nao e o caminho operacional atual enquanto a politica ativa for nao pagar API/on-demand.
  - `cursor_cli` cobre o caminho por login local/conta Cursor e deve ser preferido no presente porque usa a conta de forma mais eficiente para o subsidio.
  - A implementacao inicial e fail-closed: disabled por config, exige Node, @cursor/sdk, CURSOR_API_KEY, adapter local, Decision Receipt, model, workspace e allowed_files.
  - Cursor SDK nao pode escolher provider/model, escopo, arquivos, sucesso, memory write, policy ou completion claim.
  - O driver registra billing/quota como bucket de conta Cursor, mas nao declara uso gratis nem ilimitado.
  - Promocao para auto-routing depende de smoke real, AP-99/Rivals, evidence ledger e review humano.
maintenance:
  - Atualizar quando Cursor SDK, auth, modelos, pricing, MCP, subagents, local/cloud runtime ou output schema mudarem.
  - Atualizar antes de alterar driver, runtime executor, adapter Node, config, router ou tests Cursor SDK.
related_paths:
  - docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasCursorSdkRuntimeExecutor.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - runtimes/node/cursor_sdk/adapter.mjs
  - tests/Feature/Ai/Programming/AtlasForgeCursorSdkDriverTest.php
external_references:
  - https://cursor.com/docs/sdk/typescript
  - https://cursor.com/changelog/sdk-release
  - https://docs.cursor.com/en/cli/reference/output-format
  - https://docs.cursor.com/models/
  - https://docs.cursor.com/account/plans
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cursor-sdk-governed-executor-v1
graph_title: Atlas Cursor SDK Governed Executor v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
human_name: Atlas Cursor SDK Governed Executor v1
canonical_name: Atlas Cursor SDK Governed Executor v1
technical_name: atlas-cursor-sdk-governed-executor-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-cursor-sdk-governed-executor-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-cursor-sdk-governed-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasCursorSdkRuntimeExecutor.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - runtimes/node/cursor_sdk/adapter.mjs
  - tests/Feature/Ai/Programming/AtlasForgeCursorSdkDriverTest.php
allowed_changes:
  - Refinar contrato, adapter, schemas, blockers e metricas mantendo fail-closed.
  - Evoluir local/cloud mode depois de smoke real e tests dedicados.
forbidden_changes:
  - Habilitar auto-routing ou allow_manual como default.
  - Permitir provider execution sem Decision Receipt, approvals, workspace, model e allowed_files.
  - Tratar output do Cursor como completion claim.
  - Assumir subsidio ilimitado ou mascarar token/quota usage.
depends_on:
  - atlas-forge-governed-provider-invocation-v1
  - atlas-forge-real-provider-drivers-v1
  - atlas-cursor-antigravity-meta-provider-dossier-v1
flows_to:
  - atlas-forge-rivals-provider-arena-v2
  - programming-professional-completion-audit
unlocks:
  - cursor-sdk-experimental-driver
  - cursor-sdk-rivals-arm
governs:
  - cursor-sdk-provider-harness
  - forge-provider-harness-candidates
evidence:
  - docs/engineering-knowledge-base/atlas-cursor-sdk-governed-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasCursorSdkRuntimeExecutor.php
  - runtimes/node/cursor_sdk/adapter.mjs
  - tests/Feature/Ai/Programming/AtlasForgeCursorSdkDriverTest.php
  - https://cursor.com/docs/sdk/typescript
required_tests:
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeCursorSdkDriverTest.php
  - php artisan atlas:engineering:knowledge docs-health --json
  - git diff --check
requires_evidence: true
risk_level: critical
visual_tags:
  - cursor
  - sdk
  - provider-harness
  - governed
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Riscos e Proximas Acoes antes de alterar Cursor SDK.
ai_usage_notes:
  - Esta peca implementa um driver experimental fail-closed; nao autoriza uso automatico.
quality_gates:
  - cursor-sdk-disabled-by-default
  - cursor-sdk-auth-required
  - decision-receipt-required
  - model-required
  - allowed-files-required
  - scope-verification
  - completion-claim-blocked
failure_modes:
  - Cursor Agent altera arquivo fora de allowed_files.
  - SDK consome quota de conta sem budget approval.
  - Modelo observado diverge do modelo pedido.
  - Output parcial ou tool event vira claim de conclusao.
observability_signals:
  - provider
  - model_requested
  - model_observed
  - runtime_mode
  - billing_mode
  - quota_bucket
  - changed_files
  - tool_events
  - provider_tokens_spent
next_actions:
  - Rodar smoke real com @cursor/sdk instalado e CURSOR_API_KEY configurado.
  - Criar arm Rivals/AP-99 para Composer 2.5 vs Codex/Claude/Gemini.
---
# Atlas Cursor SDK Governed Executor v1

## Resumo

Implementa `cursor_sdk` como provider-harness governado e experimental no Atlas Forge. O driver usa Node para chamar `@cursor/sdk` por um adapter Atlas-owned, mas fica desabilitado por padrao e bloqueia honestamente quando runtime, auth, model, scope ou receipt faltam.

Postura operacional atual: `cursor_sdk` fica reservado para um futuro em que o operador decidir usar API/Cloud Agent. Enquanto a decisao for assinar Cursor e evitar API/on-demand, `cursor_cli` e o trilho preferencial porque entrega praticamente o mesmo papel de executor agentico usando login local/conta Cursor.

## Papel no Atlas

Cursor SDK executa trabalho agentico como subordinado. Atlas Decide, Memory, SDD, gates, Evidence Ledger, budget e completion review continuam no Atlas. O Cursor nao decide autoridade; ele apenas roda um work packet ja autorizado.

## Onde Se Encaixa

```text
Atlas Decide
 → Runtime Dispatch
 → Governed Provider Invocation
 → Driver Router
 → cursor_sdk driver
 → Node adapter
 → @cursor/sdk Agent
 → Evidence + Review Gate
```

## Contratos

| Schema | Producer | Consumer |
|---|---|---|
| `atlas.provider.cursor_sdk.status.v1` | Runtime executor | router/status UI |
| `atlas.provider.cursor_sdk.invocation_request.v1` | Cursor driver | Node adapter |
| `atlas.provider.cursor_sdk.invocation_result.v1` | Node adapter/runtime | driver/service |
| `atlas.provider.cursor_sdk.performance_signal.v1` | adapter/runtime | Provider Performance/Rivals |

## Fluxo

1. Invocation Service valida Obra, dispatch, Decision Receipt, budget e approvals.
2. Router resolve `cursor_sdk`.
3. Driver monta manifest com prompt, workspace, allowed/forbidden files, model, billing e metadata.
4. Runtime faz plan/config sem chamar provider.
5. Execute escreve manifest temporario e spawna `node runtimes/node/cursor_sdk/adapter.mjs`.
6. Adapter cria `Agent.create`, roda `agent.send`, observa stream/tool events e compara arquivos permitidos.
7. Resultado volta com hashes, changed files, artifacts, blockers e performance signal advisory-only.

## Regras para IA

- Nunca habilitar `ATLAS_CURSOR_SDK_ENABLED` como default.
- Nunca rodar sem `CURSOR_API_KEY`.
- Nunca permitir run sem `decision_receipt_id`, `decision_receipt_hash`, `model`, `workspace` e `allowed_files`.
- Nunca aceitar arquivos alterados fora de `allowed_files` como sucesso.
- Nunca promover completion claim a partir do output Cursor.
- Nunca usar `cursor_sdk` para tarefas normais enquanto a politica ativa for assinatura Cursor por conta; usar `cursor_cli`.

## Escopo de Implementacao

Inclui:

- `AtlasForgeCursorSdkInvocationDriver`
- `AtlasCursorSdkRuntimeExecutor`
- `runtimes/node/cursor_sdk/adapter.mjs`
- wiring em `AtlasForgeProviderInvocationDriverRouter`
- config `atlas.ai.providers.cursor_sdk`
- testes focados do driver

Nao inclui:

- auto-routing;
- Cursor CLI;
- smoke real com conta do operador;
- promocao para melhor provider;
- Rivals/AP-99 real.

## Dependencias

- `@cursor/sdk` disponivel para Node import.
- `CURSOR_API_KEY` configurado.
- Workspace local com arquivos permitidos.
- Forge Provider Invocation gates verdes.

## Evidencias

- Tests cobrem fail-closed, status, plan sem provider call, blockers de receipt/model/scope e invoke fake via process factory.
- Adapter Node verifica scope por snapshots de allowed/forbidden files e `git status`.
- Config registra `billing_mode` e `quota_bucket`.

## Riscos

- O SDK pode consumir bucket de conta Cursor rapidamente.
- O agent pode tentar alterar arquivos fora de allowed scope.
- `@cursor/sdk` ainda e beta e pode mudar schema/API.
- Cloud/self-hosted modes exigem smoke especifico antes de promocao.

## Exemplos

Config minima local:

```env
ATLAS_CURSOR_SDK_ENABLED=true
CURSOR_API_KEY=...
ATLAS_CURSOR_SDK_MODEL=composer-latest
ATLAS_CURSOR_SDK_RUNTIME_MODE=local
```

## Proximas Acoes

1. Instalar `@cursor/sdk` no runtime controlado ou apontar `ATLAS_CURSOR_SDK_MODULE`.
2. Rodar smoke com fixture pequena e `composer-latest`.
3. Registrar result evidence e quota behavior.
4. Criar arm Rivals/AP-99 antes de qualquer routing automatico.
