---
id: atlas-cursor-antigravity-meta-provider-dossier-v1
type: engineering_knowledge
title: Atlas Cursor and Antigravity Meta Provider Dossier v1
status: active
category: programming-forge
priority: 97
implementation_state: cursor_sdk_cursor_cli_and_antigravity_sdk_experimental_drivers_fail_closed
summary: Dossie canonico para dissecar Cursor SDK/Agent CLI e Antigravity SDK/CLI como provider-harnesses programaveis no Atlas, preservando Atlas Decide, Memory, SDD, gates, evidence, custo por conta e soberania de runtime.
tags:
  - atlas
  - cursor
  - antigravity
  - sdk
  - cli
  - provider-harness
  - meta-provider
  - subsidy-first
capabilities:
  - cursor_sdk_provider_harness_assessment
  - cursor_cli_provider_harness_assessment
  - antigravity_sdk_provider_harness_assessment
  - meta_provider_subsidy_first_routing
  - governed_agentic_runtime_absorption
decisions:
  - Cursor SDK e Antigravity SDK devem ser tratados como provider-harnesses, nao como modelos brutos e nao como substitutos do Atlas.
  - Cursor SDK possui implementacao experimental fail-closed de primeira classe para API/Cloud Agent, mas fica reservado para um futuro em que API/on-demand seja uma decisao explicita.
  - Cursor CLI possui implementacao experimental fail-closed separada para login local/conta Cursor e pools de uso por assinatura; e o caminho atual preferencial do Atlas.
  - Antigravity SDK ja possui executor experimental fail-closed no Atlas; esta doc nao promove maturidade nem libera auto-routing.
  - O Atlas deve maximizar subsidio por conta/plano dentro dos termos de cada provider antes de usar API direta, mas deve registrar quando um SDK muda para billing por API key/tokens.
  - Nenhum provider-harness pode decidir provider/model final, escopo, arquivos, policy critica, sucesso, maturidade, memory write ou completion claim sem Decision Receipt e review humano.
  - Cursor SDK, Cursor CLI e Antigravity SDK so podem virar drivers promovidos/automaticos depois de smoke real, evidence ledger, metricas AP-99 e comparacao em Rivals.
maintenance:
  - Atualizar quando Cursor SDK, Cursor Agent CLI, Cursor models/pricing, Antigravity SDK, Antigravity CLI, modelos ou planos mudarem.
  - Revalidar fontes oficiais antes de alterar driver, routing, provider policy, UI, budget policy ou fallback.
  - Manter abaixo de 520 linhas; mover runbooks de codigo para docs filhos.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-cursor-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
  - docs/engineering-knowledge-base/atlas-forge-real-provider-drivers-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - app/Services/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasAntigravitySdkRuntimeExecutor.php
  - runtimes/python/antigravity_sdk/adapter.py
  - app/Services/Ai/Programming/AtlasForgeCursorSdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasCursorSdkRuntimeExecutor.php
  - runtimes/node/cursor_sdk/adapter.mjs
  - app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php
external_references:
  - https://cursor.com/changelog/sdk-release
  - https://cursor.com/blog/typescript-sdk
  - https://cursor.com/docs/sdk/typescript
  - https://docs.cursor.com/en/cli/reference/output-format
  - https://docs.cursor.com/en/cli/reference/parameters
  - https://docs.cursor.com/background-agents
  - https://docs.cursor.com/models/
  - https://docs.cursor.com/account/plans
  - https://cursor.com/changelog/composer-2-5
  - https://antigravity.google/product/antigravity-sdk
  - https://antigravity.google/blog/introducing-google-antigravity-sdk
  - https://pypi.org/project/google-antigravity/
  - https://www.antigravity.google/product/antigravity-cli
  - https://antigravity.google/docs/cli-features
  - https://antigravity.google/docs/models
  - https://antigravity.google/docs/plans
  - https://antigravity.google/pricing
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-cursor-antigravity-meta-provider-dossier-v1
graph_title: Atlas Cursor and Antigravity Meta Provider Dossier v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-governed-provider-invocation-v1
graph_status: active
graph_source: repo
human_name: Atlas Cursor and Antigravity Meta Provider Dossier v1
canonical_name: Atlas Cursor and Antigravity Meta Provider Dossier v1
technical_name: atlas-cursor-antigravity-meta-provider-dossier-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
allowed_changes:
  - Atualizar fatos oficiais, riscos, gates, metricas e recomendacoes de experimento para Cursor e Antigravity.
  - Refinar contratos de provider-harness sem criar rotas paralelas a Atlas Decide.
forbidden_changes:
  - Declarar Cursor ou Antigravity como melhor geral sem evidence real do Atlas.
  - Promover auto-routing, permissao ampla, budget override ou completion claim baseado apenas em marketing/benchmark externo.
  - Usar conta, CLI ou SDK para contornar limites, termos, permissoes, sandbox ou auditoria.
depends_on:
  - atlas-antigravity-sdk-governed-executor-v1
  - atlas-antigravity-cli-governed-terminal-executor-v1
  - atlas-forge-governed-provider-invocation-v1
flows_to:
  - atlas-forge-rivals-provider-arena-v2
  - atlas-forge-provider-capacity-continuity-v1
  - atlas-code-provider-arena-ui-v1
unlocks:
  - cursor-sdk-experimental-driver
  - cursor-cli-experimental-driver
  - antigravity-sdk-revalidation-smoke
governs:
  - cursor-sdk-provider-harness-assessment
  - cursor-cli-provider-harness-assessment
  - antigravity-sdk-provider-harness-assessment
  - subsidy-first-provider-harness-routing
evidence:
  - docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeAntigravitySdkInvocationDriver.php
  - app/Services/Ai/Programming/AtlasAntigravitySdkRuntimeExecutor.php
  - runtimes/python/antigravity_sdk/adapter.py
  - docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
  - app/Services/Ai/Programming/AtlasForgeCursorCliInvocationDriver.php
  - tests/Feature/Ai/Programming/AtlasForgeCursorCliDriverTest.php
  - https://cursor.com/changelog/sdk-release
  - https://cursor.com/docs/sdk/typescript
  - https://docs.cursor.com/en/cli/reference/output-format
  - https://antigravity.google/product/antigravity-sdk
  - https://pypi.org/project/google-antigravity/
  - https://antigravity.google/docs/plans
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - git diff --check
requires_evidence: true
risk_level: high
next_actions:
  - Rodar smoke real controlado de cursor_sdk quando plano/API permitirem Cloud Agent.
  - Rodar smoke real controlado de cursor_cli com stream-json em repo fixture apos instalar/autenticar cursor-agent.
  - Revalidar antigravity_sdk existente com auth/custo/modelo observado.
visual_tags:
  - provider-harness
  - cursor
  - antigravity
  - subsidy-first
ai_entrypoints:
  - Leia Resumo, Papel no Atlas, Contratos, Regras para IA, Riscos e Proximas Acoes antes de alterar drivers.
ai_usage_notes:
  - Este doc e avaliacao/contrato; ele nao autoriza provider execution real nem promocao de driver.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
  - git diff --check
failure_modes:
  - Confundir SDK provider-harness com modelo bruto.
  - Assumir subsidio por conta quando o SDK usa API key/token billing.
  - Permitir agent loop externo escolher escopo, provider/model ou completion claim.
observability_signals:
  - provider_harness
  - billing_mode
  - quota_bucket
  - model_requested
  - model_observed
  - tool_events
  - changed_files
  - token_usage_observed
  - provider_status
---

# Atlas Cursor and Antigravity Meta Provider Dossier v1

## Resumo

Este dossie responde a uma pergunta operacional: como o Atlas deve entender Cursor SDK/Agent CLI e Antigravity SDK/CLI antes de decidir qualquer integracao governada?

O resultado nao e implementacao. E um mapa de evidencias, capacidades, riscos e proximos experimentos.

## Papel no Atlas

Antigravity ja existe no Atlas como driver experimental fail-closed:

- `AtlasForgeAntigravitySdkInvocationDriver`
- `AtlasAntigravitySdkRuntimeExecutor`
- `runtimes/python/antigravity_sdk/adapter.py`
- teste feature dedicado

Cursor agora possui dois drivers runtime experimentais fail-closed no Atlas:

- `cursor_sdk`: `AtlasForgeCursorSdkInvocationDriver`, `AtlasCursorSdkRuntimeExecutor` e `runtimes/node/cursor_sdk/adapter.mjs`. E o caminho oficial/API/Cloud Agent via `@cursor/sdk`.
- `cursor_cli`: `AtlasForgeCursorCliInvocationDriver`. E o caminho alinhado a login local/conta Cursor, usando `cursor-agent --print --output-format stream-json --model <model>` quando o binario estiver instalado e autenticado.

Ambos continuam sem auto-routing, sem completion claim e sem promocao antes de smoke real/Rivals.

## Onde Se Encaixa

Este modulo fica sob `atlas-forge-governed-provider-invocation-v1`, ao lado dos contratos de drivers reais e dos docs Antigravity existentes. Ele orienta como Cursor e Antigravity entram na arena de providers sem criar uma rota paralela ao Atlas Decide.

```text
Atlas Decide
└─ Governed Provider Invocation
   ├─ existing CLI drivers: claude_cli, codex_cli, gemini_cli
   ├─ existing experimental SDK driver: antigravity_sdk
   ├─ experimental SDK driver: cursor_sdk
   ├─ experimental CLI driver: cursor_cli
   └─ Rivals / AP-99 evidence before promotion
```

## Official Cursor Facts

Cursor anunciou o SDK em 2026-04-29 como beta publico. O SDK permite criar agents com o mesmo runtime, harness e modelos usados no Cursor desktop, CLI e web app. O exemplo oficial usa `npm install @cursor/sdk`, `Agent.create`, `agent.send` e `run.stream`.

O blog oficial afirma tres modos relevantes:

- local na maquina do usuario
- Cursor Cloud em VM dedicada com repo clonado
- self-hosted workers para manter codigo e tool execution dentro da rede do cliente

O SDK oficial/artefato npm inspecionado (`@cursor/sdk` 1.0.13) expoe:

- `Agent.create(options)`
- `agent.send(message, options)`
- `run.stream()`
- `run.wait()`
- `run.conversation()`
- `run.cancel()`
- `Agent.resume`, `Agent.list`, `Agent.getRun`, `Agent.cancelRun`
- `Cursor.me`, `Cursor.models.list`, `Cursor.repositories.list`
- `mcpServers` inline
- `agents` para subagents
- `local.cwd`, `local.settingSources`, `local.sandboxOptions`
- `cloud.repos`, `cloud.autoCreatePR`, `cloud.workOnCurrentBranch`, `cloud.envVars`
- `listArtifacts` e `downloadArtifact` para cloud

Os eventos de stream do SDK incluem `system`, `user`, `assistant`, `tool_call`, `thinking`, `status`, `request` e `task`. Isso e suficiente para um driver Atlas observar tool lifecycle, modelo observado, arquivos tocados, artefatos, status e pedidos de aprovacao.

Cursor Agent CLI tambem e programavel. A documentacao oficial define `--print` com `--output-format text|json|stream-json`, sendo `stream-json` o padrao em modo print/inferido. O stream NDJSON emite `system`, `user`, `assistant`, `tool_call` e `result`; falhas saem com codigo nao-zero e podem nao emitir evento terminal. A referencia CLI expoe `--api-key`, `--print`, `--output-format`, `--background`, `--resume`, `--model` e `--force`.

Cursor Background Agents rodam em maquina Ubuntu isolada, com internet, repo clonado do GitHub, branch separada, environment setup via `.cursor/environment.json`, e podem auto-rodar terminal commands. A propria doc de seguranca alerta risco de prompt injection/exfiltracao porque comandos sao auto-executados.

Pricing/plans oficiais do Cursor: planos individuais incluem tab completions, limites estendidos de agent usage, Bugbot e Background Agents. O uso de agente e consumido conforme precos de inferencia do modelo: Pro inclui US$20 de API agent usage + bonus, Pro Plus US$70 + bonus, Ultra US$400 + bonus. Isso e uma camada de subsidio/credito por conta, mas nao e "ilimitado"; modelo escolhido consome o bucket em velocidades diferentes.

## Official Antigravity Facts

Antigravity SDK e Python. A pagina oficial diz que o Agent SDK fornece as mesmas ferramentas, agent loop e context management do Google Antigravity. Ele e instalado via `pip install google-antigravity` e depende de runtime binario compilado dentro das wheels oficiais.

O blog oficial de 2026-05-19 descreve o SDK como preview e acesso programatico ao Antigravity coding agent. Ele herda o runtime usado por Antigravity 2.0 e Antigravity CLI: toolset embutido, safety-policy engine, lifecycle hooks e sessoes multi-turn stateful.

O quickstart publico usa:

- `from google.antigravity import Agent, LocalAgentConfig`
- `async with Agent(config) as agent`
- `await agent.chat(...)`
- `await response.text()`
- stream via `async for token in response`

Recursos oficiais do SDK:

- file I/O
- code editing
- shell execution
- directory search
- image generation
- sub-agent delegation
- custom Python tools
- MCP via stdio/SSE/Streamable HTTP
- skills paths
- policies declarativas
- hooks de inspect, decide e transform
- multimodal input com imagens, PDFs, audio e video
- structured output via schema/Pydantic
- session persistence por conversation id
- token usage metadata
- triggers para background tasks

Ha uma nuance importante de custo/autenticacao: o quickstart do PyPI usa `GEMINI_API_KEY`. Isso sugere que o SDK pode cair em billing/API-key path, enquanto Antigravity 2.0/CLI/plans sao o caminho mais forte para subsidio por conta Google AI. O Atlas nao deve assumir que "Antigravity SDK" consome o mesmo bucket gratis/conta do app sem smoke real.

Antigravity CLI e terminal-first e compartilha o harness com Antigravity 2.0. A doc oficial lista plugins, MCP, skills, hooks, terminal sandbox, slash commands, subagents, `/permissions`, `/model`, `/tasks`, `/skills`, `/mcp`, `/agents` e configuracao por `~/.gemini/antigravity-cli/settings.json`. O sandbox usa recursos nativos do SO (`sandbox-exec` no macOS) e pode isolar comandos locais.

Modelos oficiais do Antigravity docs/plans:

- Gemini 3.1 Pro high/low
- Gemini 3 Flash
- Claude Sonnet 4.6 thinking
- Claude Opus 4.6 thinking
- GPT-OSS-120b

Modelos auxiliares nao customizaveis incluem Nano Banana Pro 2 para imagem, Gemini 2.5 Pro UI Checkpoint para browser subagent, Gemini 2.5 Flash para checkpoint/context summarization e Gemini 2.5 Flash Lite para semantic search.

Plans oficiais: Individual US$0 com agent model access, tab completions e command requests; Google AI Pro/Ultra dao quotas mais generosas e pool de creditos; Organization vem via Google Cloud. A doc de plans declara que nao ha suporte atual para bring-your-own-key ou bring-your-own-endpoint para mais rate limits. Overage em Pro/Ultra usa AI credits em Vertex API pricing.

## Capability Matrix

| Area | Cursor SDK | Cursor CLI | Antigravity SDK | Antigravity CLI |
| --- | --- | --- | --- | --- |
| Runtime programavel | Forte, TypeScript | Medio, processo CLI | Forte, Python | Medio, terminal UI |
| Conta/subsidio | Via Cursor API key e plano/usage bucket | Via login/API key | Incerto, quickstart usa Gemini API key | Forte via conta Google/AI plans |
| Agent loop | Cursor harness | Cursor harness | Antigravity runtime | Antigravity harness |
| Streaming estruturado | `run.stream()` | NDJSON `stream-json` | `async for` e hooks | TUI/status, menos ideal para machine parse |
| Tool observability | Bom: `tool_call`, deltas, status | Bom: `tool_call`, result | Forte via hooks/policies | Bom para humano, menos SDK-grade |
| MCP | Inline e `.cursor/mcp.json` | Cursor config | MCP servers em config | `/mcp` e plugins |
| Subagents | `agents` definitions | Agent behavior/CLI | sub-agent delegation | `/agents` async panel |
| Artifacts | Cloud only | Indireto via files/events | Structured output, artifacts dependem runtime | Artifacts na plataforma |
| Local sandbox | `local.sandboxOptions` | permission/force flags | policies/capabilities | terminal sandbox |
| Cloud | Cursor Cloud VM, PRs | background mode | roadmap remote harness | local platform/Google Cloud org |
| Best Atlas fit | Official/API/Cloud Agent driver | Subscription/login local driver | Existing fail-closed driver revalidation | Subsidy-first/manual harness candidate |

## Atlas Interpretation

Cursor SDK deve continuar como o driver mais direto para controle programatico/API/Cloud Agent (`cursor_sdk`):

- API surface clara
- eventos estruturados
- artifacts/cloud PRs
- model catalog via `Cursor.models.list`
- local/cloud split explicito
- TypeScript combina com runtimes Node do Atlas Desktop/Forge sidecars

Cursor CLI deve ser o driver mais alinhado para uso por assinatura/login local (`cursor_cli`):

- facil de instalar e testar
- `stream-json` parseavel
- funciona com login/API key
- menos controle fino que SDK, mas melhor para a estrategia subsidy-first quando API/on-demand nao deve ser gasto

Antigravity SDK deve continuar como `antigravity_sdk` experimental fail-closed:

- mais profundo em policies/hooks/subagents/structured output
- muito forte para research/evals/custom agents
- risco de cair em billing API-key via `GEMINI_API_KEY`
- precisa smoke real para confirmar auth, modelo observado, quotas e artifact semantics

Antigravity CLI deve continuar `reference_only` ate prova contraria:

- otimo para subsidio por conta, subagents e operacao humana
- menos ideal como driver autonomo porque a API machine-readable e menor que SDK/CLI Cursor
- util para aprender comportamento e capturar patterns, nao para ser autoridade

## Fluxo

Fluxo seguro para qualquer avaliacao:

1. Atlas Decide cria Decision Receipt com provider/model/escopo proposto.
2. Driver faz `plan()` sem contato real quando possivel.
3. Operador aprova custo, runtime dispatch e provider call.
4. Executor roda em worktree isolada com allowed/forbidden files e sandbox.
5. Stream/eventos viram evidence: tool calls, modelo observado, arquivos, artifacts e custos.
6. Completion claim fica bloqueado ate testes, diff review e receipt final do Atlas.

## Cost and Subsidy Policy

Regra do Atlas: maximizar plano/conta/subsidio dentro dos termos antes de API direta.

Aplicacao pratica:

- Cursor Pro/Ultra pode virar pool de capacidade por conta, com `billing_mode=account_subscription` e `usage_bucket=cursor_included_usage`.
- Cursor SDK usa `CURSOR_API_KEY`; mesmo com plano, o consumo deve ser tratado como bucket medido, nao gratis, e por isso nao deve ser usado no momento salvo decisao explicita de API.
- Cursor CLI deve ser preferido quando a politica local for "assinatura primeiro, sem on-demand/API extra", desde que use `auth_mode=local_login`, remova `CURSOR_API_KEY` do processo filho e o smoke com dashboard confirme consumo no bucket esperado.
- Antigravity 2.0/CLI parece melhor alinhado a subsidio por conta Google AI.
- Antigravity SDK precisa ser marcado como `billing_mode=api_key_or_unknown` ate smoke confirmar se usa quota de conta, AI credits ou Gemini API key.
- Overage e API direta so entram como fallback controlado, com approval e budget receipt.

## Contratos

Campos minimos para qualquer driver Cursor/Antigravity:

- `provider_harness`: `cursor_sdk`, `cursor_cli`, `antigravity_sdk`, `antigravity_cli`
- `billing_mode`: `account_subscription`, `included_usage`, `api_key`, `ai_credits`, `unknown`
- `quota_bucket`: nome do plano/conta observado sem expor segredo
- `model_requested`
- `model_observed`
- `runtime_mode`: `local`, `cloud`, `self_hosted`, `cli`
- `decision_receipt_id`
- `decision_receipt_hash`
- `work_packet_hash`
- `context_pack_hash`
- `allowed_files_hash`
- `forbidden_files_hash`
- `mcp_servers_hash`
- `permissions_mode`
- `sandbox_enabled`
- `tool_events`
- `changed_files`
- `artifact_refs`
- `stdout_hash` / `stderr_hash`
- `duration_ms`
- `token_usage_observed`
- `provider_status`
- `completion_claim_allowed=false` por default

## Regras para IA

Obrigatorio antes de qualquer execucao real:

- Atlas Decide escolhe provider/model; SDK/CLI apenas executa.
- Scope de arquivos e worktree isolados.
- Secrets redaction antes de mandar contexto.
- No auto-run terminal fora de sandbox/allowlist.
- No `--force` ou equivalente sem approval explicito e auditavel.
- Background/cloud agents nao podem tocar branches principais sem PR/review.
- Tool calls viram evidence, nao verdade canonica.
- Completion claim depende de testes, diff review e receipt Atlas.

## Escopo de Implementacao

Permitido nesta fase:

- pesquisa oficial;
- doc canonica;
- spike plan-only;
- smoke fixture;
- tests de parsing/receipt sem provider real.

Nao permitido nesta fase:

- auto-routing;
- escrita real por Cursor/Antigravity sem approvals;
- promocao para melhor provider;
- bypass de sandbox/allowlist;
- memory write por output externo.

## Dependencias

- `atlas-antigravity-sdk-governed-executor-v1`
- `atlas-antigravity-cli-governed-terminal-executor-v1`
- `atlas-forge-governed-provider-invocation-v1`
- `atlas-forge-real-provider-drivers-v1`
- `atlas-forge-rivals-provider-arena-v2`
- `atlas-canonical-glossary-and-naming`

## Evidencias

Evidencias locais:

- Antigravity SDK driver e executor ja existem em `app/Services/Ai/Programming`.
- Cursor SDK e Cursor CLI drivers ja existem como experimentais fail-closed em `app/Services/Ai/Programming`.
- `@cursor/sdk` 1.0.13 foi inspecionado por `npm view`/`npm pack` fora do repo.
- `docs-health` deve ficar verde para este doc antes de merge.

Evidencias externas:

- Cursor SDK official docs/blog: SDK TypeScript, local/cloud/self-hosted, streaming, MCP e subagents.
- Cursor CLI docs: `--print`, `--output-format json|stream-json`, model/background/resume flags.
- Cursor plans/models docs: usage bucket por plano e precos por modelo.
- Antigravity SDK official docs/PyPI/blog: Python SDK, Agent, LocalAgentConfig, policies, hooks, MCP, multimodal, structured output.
- Antigravity plans/models/pricing docs: modelos disponiveis, AI credits e ausencia atual de BYOK/BYO endpoint para rate limits adicionais.

## Riscos

- Cursor SDK pode consumir bucket de uso de forma rapida se o Atlas tratar Composer/GPT/Claude como gratis.
- Antigravity SDK pode usar `GEMINI_API_KEY` e cair em billing API, enquanto o usuario espera subsidio por conta.
- Background/cloud agents podem executar comandos e exfiltrar contexto se prompt injection vencer regras locais.
- CLI TUI pode ser menos parseavel que SDK; output parcial nao deve virar claim.
- Model aliases mudam; runtime deve registrar `model_requested` e `model_observed`.
- Benchmarks externos nao substituem Rivals AP local.

## Exemplos

Envelope minimo de evidence esperado:

```yaml
provider_harness: cursor_sdk
billing_mode: cursor_account_api_or_cloud_pool
quota_bucket: cursor_plan_agent_usage_bucket
model_requested: composer-2.5
model_observed: composer-2.5
runtime_mode: local
decision_receipt_id: receipt-redacted
work_packet_hash: sha256-redacted
sandbox_enabled: true
completion_claim_allowed: false
```

Envelope minimo para o caminho por assinatura/login local:

```yaml
provider_harness: cursor_cli
billing_mode: cursor_account_cli_pool
quota_bucket: cursor_account_composer_pool
model_requested: composer-2.5-fast
runtime_mode: cli
decision_receipt_id: receipt-redacted
work_packet_hash: sha256-redacted
sandbox_enabled: true
completion_claim_allowed: false
```

## AP-99 Metrics

Comparar Cursor SDK, Cursor CLI, Antigravity SDK e Antigravity CLI por:

- wrong_file_rate
- broken_patch_rate
- hallucination_context_miss_rate
- wasted_token_rate
- real_test_execution_rate
- repair_precision
- first_pass_acceptance
- user_correction_count
- changed_file_scope_accuracy
- cost_per_accepted_patch
- quota_burn_per_task
- confidence_calibration_error

## Proximas Acoes

Sequencia tecnica:

1. Rodar smoke real controlado de `cursor_sdk` apenas quando plano/API permitirem Cloud Agent sem surpresa de custo.
2. Rodar smoke real controlado de `cursor_cli` usando `--print --output-format stream-json` em repo fixture depois de instalar e logar o `cursor-agent`.
3. Revalidar `antigravity_sdk` existente com docs atuais, mas manter fail-closed.
4. Manter Antigravity CLI como referencia/subsidy-first manual ate existir output estruturado suficiente ou SDK account-auth confirmado.
5. Rodar Rivals AP com tarefas pequenas e repetiveis antes de qualquer preferencia automatica.

Conclusao atual: para controle programatico/API/Cloud Agent futuro, Cursor SDK continua limpo e util. Para a estrategia presente do operador, assinar conta Cursor e evitar on-demand/API extra, `cursor_cli` e o caminho prioritario e provavelmente cumpre a mesma funcao pratica com melhor eficiencia de uso da conta. Antigravity SDK e mais profundo em runtime/policies/hooks, mas seu caminho de custo/autenticacao precisa ser provado. Antigravity CLI e Cursor CLI sao importantes como surfaces de subsidio e devem entrar na arena Rivals antes de qualquer promocao automatica.
