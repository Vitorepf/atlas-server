---
id: atlas-dev-flow-map-and-product-options-v1-part-04
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 4
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Entrypoints ate Fair Claude.
tags:
  - atlas-dev
  - product-options
  - split-doc
  - cartography-readable
capabilities:
  - atlas_dev_product_flow_map
  - atlas_documentation_split
decisions:
  - Este recorte preserva uma parte do mapa de fluxo/produto sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md quando o mapa de produto mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-flow-map-and-product-options-v1-part-04
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 4
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-04.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Transformar opção de produto, diário ou hipótese em contrato runtime sem evidência.
depends_on:
  - atlas-dev-flow-map-and-product-options-v1
flows_to:
  - atlas-dev-flow-map-and-product-options-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas_dev.product_options
evidence:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Flow Map And Product Options v1 · Parte 4

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Entrypoints ate Fair Claude.

## Papel no Atlas

Mantém diário, opções, entrypoints ou matriz fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md` e deve ser lido quando a pessoa precisar do detalhe desta decisão de produto/fluxo.

## Contratos

Segue o documento dono, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → decisão de produto, execução ou revisão correspondente.

## Regras para IA

Não transformar hipótese, diário, opção futura ou comparação em runtime pronto. Não misturar patamar, versão, fonte, risco, regra ou prova.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-flow-map-and-product-options-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém confundir opção/produto futuro com contrato implementado.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
## Entrypoints

### 1. `atlas:cli:dev`

Arquivo: `app/Console/Commands/AtlasCliDevCommand.php`.

Formas principais:

```bash
php artisan atlas:cli:dev
php artisan atlas:cli:dev "corrija este bug"
php artisan atlas:cli:dev "implemente X" --plan-only --json
php artisan atlas:cli:dev "implemente X" --provider=codex_cli --model=5.5
php artisan atlas:cli:dev "implemente X" --claude-only --plan-only --json
php artisan atlas:cli:dev "implemente X" --forge
php artisan atlas:cli:dev "corrija X" --repair --auto-test
```

Responsabilidades:

- resolver workspace;
- normalizar provider e modelo;
- aplicar Fair Claude quando solicitado;
- montar preflight;
- montar `dev_execution_plan`;
- anexar `kernel_pipeline`;
- gerar preview Open Brain;
- montar comando `atlas:ai:chat`;
- se `--plan-only`, parar antes do provider;
- se `--forge`, executar Engineering Harness diretamente;
- senao, delegar para `atlas:ai:chat`.

### 2. `atlas:ai:chat --dev`

Arquivo: `app/Console/Commands/AiChatCommand.php`.

E o runtime conversacional real usado por Atlas Dev. Ele:

- abre thread nova ou continua thread existente;
- aceita REPL interativo;
- aceita `/fix`;
- aceita `/mode`, `/model`, `/handoff`, `/quality`, `/paste-image`;
- monta payload com workspace, permission, provider/model, images, skills;
- cria ou recebe `dev_execution_plan`;
- cria `programming_message_plan`;
- decide provider/harness;
- executa provider via gateway ou Engineering Harness;
- roda quality gate em modo dev quando aplicavel.

### 3. `atlas:cli:fix`

Arquivo: `app/Console/Commands/AtlasCliFixCommand.php`.

Alias canonico para repair:

```text
atlas:cli:fix ... -> atlas:cli:dev ... --repair --surface-origin=atlas_cli_fix
```

Ele preserva contrato `atlas.cli_fix.contract.v1`, flow `programming.repair`,
runtime `dev_repair_executor` e flags de auto-test/allow-write/plan-only.

### 4. `atlas:cli:continue`

Arquivo: `app/Console/Commands/AtlasCliContinueCommand.php`.

Retoma plano anterior via `AtlasCliSessionService`, reconstrui comando
`atlas:cli:dev` com `--resume=<plan_id>`, preserva provider/model/profile,
Open Brain e flags relevantes. Em `--dry-run`, mostra o comando e o contrato
sem executar.

### 5. API / Desktop / App

Atlas Dev tambem aparece fora do terminal:

- `AtlasDevRuntimeService` aplica runtime em payloads de `/ai/interactions`;
- `AtlasCliDevSurfaceAdapter` declara capacidades do CLI Dev;
- `AtlasDesktopAiSurfaceAdapter` mapeia Desktop AI para flows de programacao;
- `AtlasAppSurfaceAdapter` mapeia App/mobile para `programming.dev`,
  `programming.review` e `programming.repair`;
- `AtlasCodeDevToForgePromotionController` expoe promocao para Forge.

## Fluxo End-to-End Atual

### One-shot normal

```text
Operador
-> atlas:cli:dev "tarefa"
-> workspace()
-> provider/model selection
-> AtlasCliDevWorkflowService::preflight()
-> AtlasProgrammingOrchestrator::sessionPlan(profile=dev)
-> KernelPipelineDevPlanBuilder::attachProgrammingPlan()
-> Open Brain preview
-> atlas:ai:chat --dev --dev-plan=<json>
-> activeDevExecutionPlan()
-> programmingMessagePlan()
-> programmingDispatchContract()
-> provider gateway OU Engineering Harness
-> trace/thread metadata
-> quality gate
```

### Plan-only

```text
atlas:cli:dev "tarefa" --plan-only --json
-> nao chama provider
-> retorna workflow, dev_execution_plan, activated_skills,
   open_brain_preview e chat_command
```

Uso: auditar roteamento, modelo, Open Brain, skills, permission e pipeline
antes de gastar tokens.

### Interativo

```text
atlas:cli:dev
-> atlas:ai:chat --dev --new-thread --cockpit --dev-plan=<json>
-> REPL
-> mensagens sucessivas preservam thread/workspace/status
```

Comandos relevantes dentro do REPL:

- `/fix [texto]`: converte input em repair;
- `/quality`: roda/mostra quality gate;
- `/model`: troca ou mostra modelo;
- `/handoff codex|claude`: troca provider;
- `/paste-image`: anexa imagem;
- `/status`: mostra contexto da sessao.

### Prompted cockpit

Mesmo com tarefa one-shot, o comando usa a mesma rota do cockpit quando nao
esta em `--plan-only` e nao esta em `--forge`:

```text
atlas:cli:dev "tarefa"
-> interactiveChatCommand(... task=tarefa ...)
-> atlas:ai:chat "tarefa" --dev --cockpit
```

Isso evita que o one-shot tenha contrato mais fraco que o interativo.

### Repair

```text
atlas:cli:fix "corrija teste X"
-> atlas:cli:dev "Corrija: ..." --repair
-> programming.repair
-> dev_repair_executor
-> repair_execution_contract
-> repair prompt/capsule quando gate falha
```

O chat tambem detecta repair automaticamente por sinais como `corrija`, `fix`,
`bug`, `erro`, `teste falhando`, `quality gate`.

### Forge profile dentro do comando Dev

```text
atlas:cli:dev "tarefa critica" --forge
-> programming_profile=forge
-> flow=programming.forge
-> executor=engineering_harness
-> Open Brain required
-> auto_test true
-> evidence_required
```

Esse caminho e Forge, nao Dev Light. Deve ser medido separadamente no Rivals.

## Runtime De Payload: AtlasDevRuntimeService

Arquivo: `app/Services/Ai/Programming/AtlasDevRuntimeService.php`.

Aplica em payloads de surfaces Atlas AI quando:

- surface e `atlas_app`, `atlas_desktop_ai`, `atlas_api_interaction` ou
  `atlas_cli_dev`;
- modo normalizado e `programming`;
- workspace existe.

Nao aplica quando:

- surface e `atlas_code` (Atlas Code/Forge tem propria fronteira);
- modo nao e programming;
- surface desconhecida.

Mapeamento de task:

| task | flow |
| --- | --- |
| `dev` | `programming.dev` |
| `plan` | `programming.dev` |
| `direct` | `programming.dev` |
| `review` | `programming.review` |
| `debug` | `programming.repair` |
| `repair` | `programming.repair` |

Artefatos esperados:

```text
plan
diff_or_reason
tests_or_reason
risks
```

Slice emitido:

```text
atlas_dev_runtime.schema_version = atlas.dev_runtime.v1
enabled = true
flow_id = programming.dev|review|repair
mode = programming
workspace = ...
decision_mode = atlas_decide|manual_override
provider = null|manual provider
requires_obra = false
open_brain_policy = auto
```

## Provider E Modelo

### Provedores aceitos

Atlas Dev normaliza aliases para:

| input | provider |
| --- | --- |
| `claude`, `claude-cli` | `claude_cli` |
| `codex`, `codex-cli` | `codex_cli` |
| `gemini`, `gemini-cli` | `gemini_cli` |
| `conselho`, `council`, `ambos`, `claude-codex` | `claude_codex` |

No modo `dev`, `gemini_cli` e bloqueado para execucao write: Gemini e restrito
a analise read-only nesse caminho.

### Decisao automatica

`AtlasCliProviderStrategyService` recomenda provider por modo:

- `dev` / `debug`: default, Codex, Claude;
- `review` / `plan` / `research`: default, Claude, Gemini, Codex;
- `critical`: se Claude e Codex online, recomenda `claude_codex`;
- respeita `allow_auto`, `allow_manual`, health snapshots e budget block;
- inclui performance empirica via `ProviderPerformanceProjection`.

### Override manual

Quando o operador passa `--provider` ou `--model`, Atlas Dev:

- marca `decision_mode=manual_override`;
- cria `model_selection_contract`;
- cria `ai_policy_override`;
- limita fallback/council no provider selecionado;
- valida se modelo combina com provider;
- respeita bloqueio `allow_manual=false`.

### Fair Claude

Flags:

```text
--claude-only
--single-provider
--no-decide
--fallback-disabled
```

Efeito:

- provider lock `claude_cli`;
- model lock `opus`/premium model configurado;
- Atlas Decide desabilitado;
- fallback/council proibidos;
- Codex/Gemini proibidos;
- quality gate precisa `passed`;
- unverified nao conta como pass;
- repair capsule preserva mesmo provider/modelo.

Uso: benchmark justo contra Claude Code puro, nao modo de produto diario.

