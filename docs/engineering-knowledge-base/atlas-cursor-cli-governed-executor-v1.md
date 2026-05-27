---
id: atlas-cursor-cli-governed-executor-v1
type: engineering_knowledge
title: Atlas Cursor CLI Governed Executor v1
status: active
category: programming-forge
priority: 98
implementation_state: experimental_driver_fail_closed
summary: Contrato canonico do driver `cursor_cli` no Atlas Forge, voltado a usar Cursor Agent CLI com login local/conta Cursor e pools de uso do app/CLI, mantendo Atlas como autoridade de provider, modelo, escopo, evidence e completion claim.
tags:
  - atlas
  - cursor
  - cli
  - composer
  - forge
  - provider-harness
capabilities:
  - cursor_cli_governed_executor
  - cursor_account_cli_pool_tracking
  - cursor_composer_pool_candidate
  - provider_scope_verification
decisions:
  - `cursor_cli` e separado de `cursor_sdk`; CLI mira login local e uso por conta, SDK mira API/Cloud Agent.
  - Para estrategia atual de subsidio por assinatura Cursor, `cursor_cli` e o caminho padrao; `cursor_sdk` fica reservado para um futuro com API/Cloud Agent.
  - `cursor_cli` provavelmente cobre a mesma funcao operacional que o Atlas precisa agora, com melhor eficiencia de uso da conta Cursor.
  - `cursor_cli` fica fail-closed: exige config habilitada, binary `cursor-agent`, workspace, model, Decision Receipt e `allowed_files`.
  - Auth local e tratada como `local_login_unverified`; o Atlas nao chama `cursor-agent status` em `configured()` para evitar side effect externo.
  - Em `auth_mode=local_login`, o driver remove `CURSOR_API_KEY` do ambiente do processo filho para evitar cair acidentalmente no caminho API.
  - O driver usa `cursor-agent --print --output-format stream-json --model <model>` e envia o prompt governado por stdin.
  - A execucao real e verificada depois por snapshots de allowed/forbidden files e delta de `git status`.
  - Output do Cursor CLI nunca promove completion claim; so Evidence/Performance Signal alimentam avaliacao posterior.
maintenance:
  - Atualizar quando Cursor CLI, parametros, output-format, modelos Composer, auth, billing ou pools mudarem.
  - Atualizar antes de alterar driver, allowlist, router, config ou testes `cursor_cli`.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-cursor-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php
  - tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php
external_references:
  - https://docs.cursor.com/en/cli/reference/parameters
  - https://cursor.com/blog/composer-2
  - https://cursor.com/changelog
  - https://cursor.com/docs/models-and-pricing
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cursor-cli-governed-executor-v1
graph_title: Atlas Cursor CLI Governed Executor v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
human_name: Atlas Cursor CLI Governed Executor v1
canonical_name: Atlas Cursor CLI Governed Executor v1
technical_name: atlas-cursor-cli-governed-executor-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php
  - app/Services/Ai/Programming/AtlasForgeProviderInvocationDriverRouter.php
  - app/Services/Ai/Programming/AtlasForgeProviderCommandAllowlistService.php
  - tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php
allowed_changes:
  - Refinar parametros CLI, parsing de output e scope verification mantendo fail-closed.
  - Adicionar smoke real depois de `cursor-agent` instalado e login concluido.
forbidden_changes:
  - Tratar Cursor CLI como provider/model authority.
  - Habilitar auto-routing por default.
  - Executar sem Decision Receipt, workspace e allowed_files.
  - Assumir Composer ilimitado; registrar como pool observado/generoso ate evidence real.
depends_on:
  - atlas-forge-governed-provider-invocation-v1
  - atlas-forge-real-provider-drivers-v1
  - atlas-cursor-antigravity-meta-provider-dossier-v1
flows_to:
  - atlas-forge-rivals-provider-arena-v2
  - programming-professional-completion-audit
unlocks:
  - cursor-cli-experimental-driver
  - cursor-composer-account-pool-experiment
governs:
  - cursor-cli-provider-harness
  - cursor-account-subsidy-routing-candidate
evidence:
  - app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php
  - tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php
required_tests:
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php
  - php artisan atlas:engineering:knowledge docs-health --json
  - git diff --check
requires_evidence: true
risk_level: critical
next_actions:
  - Instalar/autenticar Cursor CLI quando necessario e confirmar `driver-status` sem blockers.
  - Rodar smoke real isolado antes de qualquer routing automatico.
  - Medir dashboard Cursor para separar pool Composer/Auto/API usage.
visual_tags:
  - cursor
  - cli
  - composer
  - governed
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Riscos e Proximas Acoes antes de alterar Cursor CLI.
ai_usage_notes:
  - Este driver e experimental; login local e validado em runtime, nao em status.
quality_gates:
  - cursor-cli-disabled-by-default-in-example
  - cursor-cli-binary-required
  - decision-receipt-required
  - model-required
  - allowed-files-required
  - scope-verification
---

## Resumo

`cursor_cli` integra o Cursor Agent CLI como executor governado do Atlas Forge. Ele existe para testar e aproveitar o uso por conta/login local do Cursor, especialmente Composer/Auto/Agent CLI, sem confundir isso com a API/Cloud Agent do `cursor_sdk`.

## Papel no Atlas

O driver nao decide provider, modelo, escopo, sucesso, fallback ou completion. Atlas Decide e Forge Provider Invocation continuam sendo autoridade. Cursor CLI recebe um prompt governado e pode executar somente depois dos gates de invocation e budget.

## Onde Se Encaixa

Fluxo:

```text
Atlas Decide / Forge Dispatch
 → Provider Invocation Service
 → Driver Router
 → cursor_cli
 → Safe Process Runner
 → cursor-agent --print
 → scope verification
 → receipt/evidence/performance signal
```

## Contratos

| Contrato | Produtor | Consumidor |
|---|---|---|
| `atlas.forge.provider_driver_config_status.v1` | `cursor_cli.configured()` | router/status |
| `atlas.forge.provider_driver_plan.v1` | `cursor_cli.plan()` | dry-run/receipt |
| `atlas.forge.provider_driver_result.v1` | `cursor_cli.invoke()` | invocation service |
| `atlas.provider.cursor_cli.performance_signal.v1` | `cursor_cli.invoke()` | Provider Performance/Rivals |

## Fluxo

1. Router resolve `cursor_cli`.
2. `plan()` monta argv e valida config/allowlist sem spawnar Cursor.
3. `invoke()` exige receipt, model, workspace e allowed files.
4. Driver tira snapshots de arquivos permitidos/proibidos e status Git.
5. Safe runner executa `cursor-agent --print --output-format stream-json --model <model>`.
6. Driver compara snapshots e bloqueia promocao se houver forbidden file ou scope violation.

## Regras para IA

- Nunca sugerir `cursor_cli` como ilimitado.
- Priorizar `cursor_cli` sobre `cursor_sdk` quando a estrategia for assinatura por conta e sem on-demand/API extra.
- Nao remover `cursor_sdk`; ele continua sendo trilho API oficial futuro quando o operador decidir aceitar API/Cloud Agent e budget medido.
- Qualquer uso automatico precisa de smoke real, evidence ledger, Rivals/AP-99 e review humano.

## Escopo de Implementacao

Inclui:

- `AtlasForgeCursorCliInvocationDriver`
- allowlist de `cursor-agent`
- router canonical driver `cursor_cli`
- config `atlas.ai.providers.cursor_cli`
- testes focados
- docs canonicos

Nao inclui:

- instalar Cursor CLI no host
- chamar Cursor real automaticamente
- garantir pool ilimitado
- criar PR/Cloud Agent REST

## Dependencias

- Cursor Agent CLI instalado (`cursor-agent`)
- login local (`cursor-agent login`) ou API key se `auth_mode=api_key`
- Forge Provider Invocation gates
- Safe Process Runner

## Evidencias

- `php artisan test tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php`
- `php artisan test tests/Feature/Ai/Programming/AtlasForgeRealProviderDriversTest.php`
- `php artisan atlas:forge:provider-invoke --driver-status --json`

## Riscos

- Cursor CLI pode nao ler stdin em alguma versao; smoke real deve validar.
- Composer pode ter pool generoso, mas nao contrato ilimitado.
- Login local nao e verificado em `configured()`; erro real e classificado em runtime.
- Se `auth_mode=api_key` for ativado, o driver deixa de representar a estrategia sem API/on-demand.
- CLI agent pode editar fora do alvo; por isso scope verification e obrigatoria.

## Exemplos

Config local:

```env
ATLAS_CURSOR_CLI_ENABLED=true
ATLAS_CURSOR_CLI_BINARY=cursor-agent
ATLAS_CURSOR_CLI_AUTH_MODE=local_login
ATLAS_CURSOR_CLI_MODEL=composer-2.5-fast
ATLAS_CURSOR_CLI_COMPOSER_2_5_MODEL=composer-2.5
ATLAS_CURSOR_CLI_OUTPUT_FORMAT=stream-json
```

Instalacao/login manual:

```bash
curl https://cursor.com/install -fsS | bash
cursor-agent login
cursor-agent status
```

## Proximas Acoes

1. Instalar Cursor CLI e fazer login.
2. Rodar `driver-status` e confirmar `cursor_cli` sem blockers.
3. Fazer smoke real com arquivo permitido isolado.
4. Medir consumo no dashboard Cursor para separar Composer pool, Auto pool e API usage.
