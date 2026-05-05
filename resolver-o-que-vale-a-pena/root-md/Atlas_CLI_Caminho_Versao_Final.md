> Cleanup status: archived.
> Canonical replacement: docs/atlas-cli-final-product.md; docs/atlas-cli-release-checklist.md.
> Cleanup note: Historical CLI final-version plan. Product/release docs have authority.

# Atlas CLI — Documentação técnica para Versão Final

**Data:** 2026-04-30
**Status:** Corrigido para implementação por blocos
**Audiência:** Codex (executor), Vitor (operador)

> **Como usar:** 9 blocos sequenciais (B0–B8), cada um entregável independente com critério de pronto verificável. Cada classe Laravel citada existe e foi inspecionada no código real (path indicado). Cada comando ausente está marcado com ❌. Cada tradeoff é declarado explicitamente. Implementar em ordem (B0 destrava o resto); B2/B3, B4/B5/B6, B7/B8 podem paralelizar com cuidado. Este documento é plano operacional; o ADR de nomenclatura continua sendo a autoridade para termos canônicos.

---

## 1. Princípios constitucionais (do [Vault](AtlasVault/00-constituicao/atlas-cli-tui-estado-da-arte-plano-implementacao.md))

- Atlas é **superfície**; provider é **motor** intercambiável.
- CLI/TUI é o produto principal no Mac (não App visual).
- Runtime próprio **antes** de autonomia.
- Permissão **explícita** para risco real.
- Sessão longa preserva raciocínio; compaction e handoff são parte do produto.
- Qualidade é verificável (gates concretos, não vibe).
- Resposta clara por default; código bruto só sob demanda.
- Skills são capacidade versionada, não decoração.
- Tudo importante deixa rastro (trace persistido).

---

## 2. Estado atual (verificado no código)

### 2.1 Console Commands prontos (`atlas-server/app/Console/Commands/`)

| Classe | Signature relevante | O que faz | Status |
|---|---|---|---|
| `AiChatCommand` | `atlas:ai:chat {input?} {--provider=} {--mode=direct} {--stream} {--permission=auto} {--allow-write} {--auto-test} {--list-threads} ...` | Coração do CLI. One-shot ou REPL com 20+ slash-commands (`/objective`, `/phase`, `/compact`, `/handoff`, `/quality`, `/checkpoint`, `/threads`, `/state`, `/note`, `/next`, `/decision`, `/open-loop`, `/topic`, `/help`) | ✅ |
| `AtlasCliDevCommand` | `atlas:cli:dev {task?*} {--provider=} {--critical} {--permission=write} {--allow-write} {--auto-test} {--plan-only} {--force-offline-provider} {--no-stream} {--timeout=900} {--json}` | Preflight (provider strategy + quality) → gera `chatCommand()` → executa em subprocess `atlas:ai:chat` → quality gate final | ✅ base |
| `AtlasCliStateCommand` | `atlas:cli:state {action=show} {value?*} {--objective=} {--phase=} {--note=*} {--decision=*} {--open-loop=*} {--next-step=*} {--artifact=*} {--constraint=*} ...` | 12 ações: `show`, `set`, `objective`, `phase`, `topic`, `position`, `note`, `next`, `decision`, `open-loop`, `compact`, `handoff` | ✅ |
| `AtlasCliCheckpointCommand` | `atlas:cli:checkpoint {action=list} {checkpoint?} {--limit=20} {--yes}` | 3 ações: `list`, `show`, `restore` | ✅ |
| `AtlasCliQualityCommand` | `atlas:cli:quality {--run-tests} {--command=} {--yes}` | 4 gates: `git_status`, `git_diff`, `workspace_changes`, `tests` → `completion_packet` | ✅ |
| `AtlasCliDashboardCommand` | `atlas:cli:dashboard {--watch=0} {--limit=8} {--refresh-index} {--json}` | Painel: `workspace`, `atlas_ai`, `providers`, `runtime`, `recommended_commands` | ✅ |
| `AtlasCliTuiCommand` | `atlas:cli:tui {--once} {--classic} {--json}` | TUI PHP/ANSI com painel operacional sem depender de Go/Bubble Tea | ✅ |
| `AtlasCliFinalCommand` | `atlas:cli:final {--strict} {--refresh-providers} {--run-tests} {--json}` | Verifica B0-B8, hardening de produto, release preflight e doctor final | ✅ |
| `AtlasCliDogfoodCommand` | `atlas:cli:dogfood {action=report} {--scenario=} {--result=} {--days=3} {--real} {--strict}` | Registra uso real e executa `dogfood run` para smoke seguro, marcado e limpo dos cenários principais | ✅ |
| `AtlasCliReleaseCommand` | `atlas:cli:release {--release-version=} {--create-tag} {--preflight} {--skip-dogfood} {--no-final}` | Gate de release versionado com docs, CI, dogfood real e readiness. Skips só passam em `--preflight`. O launcher aceita `atlas release --version=vX.Y.Z`. | ✅ |
| `AtlasCliBootstrapCommand` | `atlas:cli:bootstrap {--target=} {--claude-bin=} {--codex-bin=} {--force} {--dry-run} {--strict} {--refresh-providers} {--write-shell-profile} ...` | Setup completo: diagnose binários → escreve `.env` → instala launcher → shell profile → `atlas doctor --strict` | ✅ |
| `AtlasCliDoctorCommand` | `atlas:cli:doctor {--refresh-providers} {--run-tests} {--strict} {--json}` | 5 gates de readiness: provider_binaries, provider_health, council_capacity, permission_scope, workspace_quality | ✅ |
| `AtlasCliProvidersCommand` | `atlas:cli:providers {--mode=direct} {--critical} {--refresh}` | Recomenda provider por modo (`direct`/`plan`/`review`/`dev`/`debug`/`research`) com fallback | ✅ |
| `AtlasRuntimeCommand` | `atlas:runtime {tool=workspace.profile} {arguments?*} {--permission=auto} {--path=} {--query=} {--command=} {--content=} {--patch=} {--checkpoint=} {--stdin} {--all} {--dry-run} {--yes}` | Despacha 12 tools com Permission Engine + checkpoint pré-write | ✅ |

### 2.2 Services prontos (`atlas-server/app/Services/Ai/Cli/`)

| Service | Métodos | Persistência |
|---|---|---|
| `AtlasCliDevWorkflowService` | `preflight()`, `chatCommand()` | — (delega) |
| `AtlasCliSessionService` | `snapshot()`, `update()`, `compact()`, `handoff()` | DB: `ai_threads`/`ai_sessions`/`ai_session_states`/`ai_compactions`/`ai_provider_handoffs` |
| `AtlasCliCheckpointService` | `list()`, `show()`, `restore()`, `restoreResult()` | FS: `storage/app/ai/checkpoints/{ts-slug}/checkpoint.json` |
| `AtlasCliQualityService` | `evaluate()`, `compact()` | — |
| `AtlasCliDashboardService` | `build()` | — (lê DB) |
| `AtlasCliProviderStrategyService` | `recommend()` | — (lê health snapshots) |
| `AtlasCliDoctorService` | `diagnose()` | — |
| `AtlasCliDogfoodService` | `start()`, `record()`, `report()` | FS: `storage/app/atlas-cli/dogfood.json` |
| `AtlasCliSetupService` / `AtlasCliInstallService` | `diagnose()`/`writeEnv()`/`install()`/`plan()` | FS: `.env`, `~/.local/bin/atlas` |

### 2.3 Orquestrador AI (`app/Services/Ai/`)

| Componente | Papel | Tabelas |
|---|---|---|
| `AiGatewayService` (24 KB) | **Gateway/facade atual de entrada**. `enqueueInteraction()`: privacidade → provider → thread → sessão → compaction check → handoff → prompt → trace + job(s) + state + snapshot + audit, em transação. É parte do Task Orchestrator, não o Harness inteiro. | `ai_traces`, `ai_jobs`, `ai_messages`, `ai_context_snapshots` |
| `AiIntentRouter` | Roteia agent/skill **por keyword** (não por modo) entre ~25 agents (`comunicador-claro`, `desenvolvedor`, `code-reviewer`, `decision-advisor`, `researcher-quick`, `security-review`, `dev-quality-gate`, `test-repair-loop`, `provider-handoff`, …) | — |
| `AiPromptBuilder` | Compõe prompt: master + skill + output_governor (`comunicador-claro`) + workflow_instructions por modo (`direct`/`plan`/`review`/`dev`/`semantic_clarification`/`quality_repair`) + context_pack + execution_plan + permission_instructions + output_contract | — |
| `AiPermissionEngine` | 3 modos: `read` / `write` / `danger`. Verifica `allowed_roots`, sandbox Codex, confirmação humana | — |
| `AiSessionManager` | `ensureActive()` / `close()`. Idle = 360 min (config `ATLAS_AI_SESSION_IDLE_MINUTES`); ao retomar cria compaction `session_resume` | `ai_sessions` |
| `AiSessionStateService` | `updateForUserInput()` / `updateForAssistantResponse()`. Persiste `objective`, `current_phase`, `current_topic`, `user_position`, `decisions[]`, `open_loops[]`, `next_steps[]`, `relevant_artifacts[]`, `constraints[]`, `provider_context`, `quality_notes[]`, `version` | `ai_session_states` |
| `AiCompactionService` | Auto-compaction se `msg_count ≥ 18` E `msgs desde última ≥ 10`. Razões: `manual`/`auto`/`provider_switch`/`phase_change`/`session_resume`/`session_close` | `ai_compactions` |
| `AiQualityEvaluator` | Score 0–100 sobre 5 dimensões (`clarity`, `continuity`, `context_discipline`, `actionability`, `verification`). 6 flags com severidade (`empty_response`, `lost_continuity`, `internal_context_leak`, `provider_identity_leak`, `likely_oververbose_or_code_heavy`, `verification_missing`) | `ai_quality_evaluations` |
| `AiQualityActionService` | Cria ações de reparo: `re-run`, `handoff`, `clarity`, etc | `ai_quality_actions` |
| `ClaudeCliProvider` / `CodexCliProvider` | Spawn de binários `claude`/`codex` com streaming JSON. Codex aceita `--sandbox {read-only,workspace-write,danger-full-access}` | — |
| `AiToolRuntime` (26 KB) | Executa as 12 tools com Permission Engine + checkpoint pré-write | — (FS) |

### 2.4 Tabelas DB existentes

`ai_threads`, `ai_messages`, `ai_sessions`, `ai_session_states`, `ai_traces`, `ai_jobs`, `ai_job_attempts`, `ai_compactions`, `ai_context_snapshots`, `ai_provider_handoffs`, `ai_provider_health_snapshots`, `ai_quality_evaluations`, `ai_quality_actions`, `ai_worker_events`.

### 2.5 Lacunas verificadas

| O que falta | Onde se encaixa | Bloco |
|---|---|---|
| `atlas debug` mapeado em `bin/atlas` | shell wrapper | B1 |
| `atlas fix` (loop test→fix→test) | wrapper do DevExecutionPlan/repair loop | B2 |
| `atlas research` (modo pesquisa) | shell wrapper + modo de workflow | B1 |
| `DevExecutionPlan` formal por etapas (inspect→plan→edit→test→repair→review→finish) | `AtlasCliDevWorkflowService` está com `preflight()`/`chatCommand()` apenas | B2 |
| Repair loop com limite e justificativa | novo no Dev | B2 |
| Tool events persistidos no DB | só `AiWorkerEvent` para heartbeat | B3 |
| `atlas trace` (`last`, `show`, `replay`) | novo Command | B3 |
| `PermissionSession` com `expires_at` por workspace/path/tool | hoje aprovação é por invocação | B4 |
| `atlas permissions` (`status`, `approve`, `revoke`) | novo Command | B4 |
| `MemoryDelta` tipado pós-sessão (claim/evidence/scope/confidence/valid_from/valid_until/use_when/do_not_use_when) | hoje só `operator_notes` (24 últimas, sem evidência) | B5 |
| `atlas memory` (`review`, `accept`, `reject`) | novo Command | B5 |
| `RouterDecision` persistido com sinais (latency, success_rate, p50, fallback_reason) | `AtlasCliProviderStrategyService` decide mas não persiste | B6 |
| `atlas compare` (dual-review explícito) | hoje é flag `--critical` no Dev | B6 |
| TUI interativa real com painéis (Fase 4) | dashboard hoje é JSON + tabela texto | B7 |
| `atlas update`, `atlas rollback`, `atlas version` | bootstrap não cobre upgrade | B8 |
| Glossário canônico | spec usa Harness/Orchestrator; B0 deve mapear sem colapsar Harness em `AiGatewayService` | B0 |

---

## 3. Definition of Done (versão final, verificável)

Versão final é declarada quando, **simultaneamente**:

1. Vitor opera 3-5 dias úteis sem abrir Claude Code/Codex CLI direto. Mensurável por `atlas dogfood report --strict` e, quando houver base suficiente, `ai_traces.surface=atlas_cli` por dia (>95%).
2. `atlas dev "X"` finaliza tarefa pequena (1 arquivo, ≤200 linhas) sem intervenção manual em ≥80% dos casos. Mensurável via `ai_quality_evaluations.score ≥ 80` em traces de modo dev.
3. Todo trace persiste: prompt, contexto, tool events, decisão de provider, gates, completion packet. Mensurável: `ai_traces.metadata.tool_events` é não-vazio em ≥95% dos traces de modo dev.
4. Todo write/danger passa por Permission Engine com decisão registrada. Mensurável: zero traces com `tool_permissions.confirmed=true` sem entrada correspondente em log de auditoria.
5. Compaction não perde objetivo, fase, decisões, open loops, next steps. Mensurável: `ai_compactions.quality_gate_status='passed'` em ≥95%.
6. Provider handoff entrega brief completo (objective+state+latest tool events). Mensurável: `ai_provider_handoffs.brief_text` ≥ 200 chars em todos.
7. TUI interativa (B7) navega entre 7 painéis e suporta atalhos `p/d/t/r/a/x/c/m/q`. Aceitação manual com checklist.
8. Memory delta gera proposta tipada após sessão; Vitor aceita/rejeita em <2 min. Mensurável: `ai_memory_deltas` com `status` em (`accepted`/`rejected`/`pending`).
9. Provider router escolhe por evidência registrada. Mensurável: `ai_router_decisions.signals` populado em todas as decisões.
10. `atlas bootstrap` em máquina limpa termina em <2 min com `--strict` passando.
11. `atlas doctor --strict` passa todos os 5+ gates após bootstrap.
12. `atlas release --version=vX.Y.Z` passa docs, CI, dogfood real de 3 dias e `atlas final --strict` antes de qualquer tag.

Falha em qualquer item = não é versão final.

---

## 4. Glossário canônico

| Termo canônico | Implementação no código | Path |
|---|---|---|
| Atlas AI | (conceito — não há classe única) | — |
| Atlas AI Harness | Conjunto `App\Services\Ai\*`, `App\Services\Ai\Cli\*`, `App\Services\Ai\Runtime\*` | [app/Services/Ai/](atlas-server/app/Services/Ai/) |
| Task Orchestrator | `AiGatewayService`, `AiPromptBuilder`, `AiSessionManager`, `AiThreadResolver`, `AiQualityActionService` | [app/Services/Ai/AiGatewayService.php](atlas-server/app/Services/Ai/AiGatewayService.php) |
| Skill/Intent Router | `AiIntentRouter` (heurística por keyword) | [app/Services/Ai/AiIntentRouter.php](atlas-server/app/Services/Ai/AiIntentRouter.php) |
| Provider/Model Router | `AtlasCliProviderStrategyService` (por modo) + `AiGatewayService::resolveProvider()` | [app/Services/Ai/Cli/AtlasCliProviderStrategyService.php](atlas-server/app/Services/Ai/Cli/AtlasCliProviderStrategyService.php) |
| Context Pack Builder | `AiContextPackBuilder` + `AiConversationContextBuilder` | [app/Services/Ai/AiContextPackBuilder.php](atlas-server/app/Services/Ai/AiContextPackBuilder.php) |
| Atlas Tool Runtime | `AiToolRuntime` | [app/Services/Ai/Runtime/AiToolRuntime.php](atlas-server/app/Services/Ai/Runtime/AiToolRuntime.php) |
| Trace Store | tabela `ai_traces` (Eloquent `AiTrace`) | [database/migrations/](atlas-server/database/migrations/) |
| Permission Engine | `AiPermissionEngine` (job-level) + `AiToolPermissionEngine` (runtime) | [app/Services/Ai/AiPermissionEngine.php](atlas-server/app/Services/Ai/AiPermissionEngine.php) |
| Output Governor | skill `comunicador-claro` (sempre anexada exceto `aclarador`/`semantic_clarification`) | [app/Services/Ai/AiPromptBuilder.php](atlas-server/app/Services/Ai/AiPromptBuilder.php) |

**Regra:** documentação nova usa o termo canônico e, quando precisar executar, aponta a implementação atual. Nome de código não substitui conceito arquitetural. `AiGatewayService` é gateway/facade atual; não é sinônimo de Atlas AI Harness.

---

## 5. Roadmap em blocos (linear, sequencial)

> Ordem é proposital: B0 destrava B1–B8 (vocabulário comum); B1 fecha gaps cosméticos; B2 entrega o ganho prático maior (autonomia em `atlas dev`); B3 instrumenta tudo; B4–B6 elevam controle/inteligência; B7 entrega TUI; B8 fecha distribuição.

### B0 — Glossário canônico + cleanup (0.5 dia)

**Objetivo.** Eliminar colisões de nomes antes de qualquer escrita nova, sem reduzir conceito constitucional a classe concreta.

**Entregáveis.**
- Criar `docs/atlas-glossary.md` com a tabela §4 acima + pointer para [Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md](Atlas_AI_CLI_Nomenclatura_Comandos_TUI_ADR.md).
- Atualizar [Atlas_Documento_Mestre_v6.md](Atlas_Documento_Mestre_v6.md) apenas se houver termo legado ambíguo. A forma correta é `Atlas AI Harness`; não trocar por `AiGatewayService`.
- Atualizar docs técnicos para mapear `Atlas AI Harness` ao conjunto de serviços atuais e `AiGatewayService` à facade/gateway atual.
- Marcar comandos órfãos (`atlas code`, `atlas inspect`, `atlas council`, `atlas new`, `atlas close`, `atlas remember`, `atlas ingest` em [Atlas_AI_Sessoes_Compactacao_Continuidade.md](Atlas_AI_Sessoes_Compactacao_Continuidade.md)/[Atlas_AI_Documentacao_Final.md](Atlas_AI_Documentacao_Final.md)) com `[deprecated]` ou `[V2-spec]`.
- Preservar `atlas sessions` e `atlas switch` como aliases compatíveis: canônicos são `atlas threads` e `atlas handoff`, mas os aliases já existem no launcher.

**Critério de pronto.** Fora do ADR de nomenclatura e de tabelas explicitamente marcadas como legado, não há uso ativo de "Atlas AI Orchestrator" nem "Context Compiler". Nenhum documento pode tratar `AiGatewayService` como sinônimo de Atlas AI Harness. Ocorrências de `Atlas Harness` devem estar normalizadas para `Atlas AI Harness` ou marcadas como alias legado com pointer para o ADR.

**Tradeoff.** Custo zero. Falta = qualquer dev (humano ou agente) novo perde 30 min para mapear nomes.

---

### B1 — Comandos faltantes leves: `debug` e `research` (1 dia)

**Objetivo.** Fechar gap leve entre spec do CLI e `bin/atlas` sem antecipar o repair loop de B2.

**Entregáveis.**

**`atlas debug`** — alias para `atlas:ai:chat --mode=debug --stream`. Adicionar mapeamento em [bin/atlas](atlas-server/bin/atlas). Adicionar `debug` à lista aceita por `AiChatCommand::workflowMode()` e atualizar a descrição de `--mode`. Adicionar workflow_instruction `debug` em `AiPromptBuilder`: "Investigue erro: reproduza, isole, diagnostique, proponha 1 correção mínima. Não edite código sem confirmação." `AtlasCliProviderStrategyService` já prefere Codex em `debug`.

**`atlas research`** — alias para `atlas:ai:chat --mode=research --stream`. Adicionar `research` à lista aceita por `AiChatCommand::workflowMode()` e atualizar a descrição de `--mode`. Provider preferido: Claude já existe em `AtlasCliProviderStrategyService`. Workflow_instruction V1: "Pesquise dentro do contexto disponível: repo, Vault, documentos enviados e traces. Cite somente fontes efetivamente fornecidas ou recuperadas pelo Atlas. Se faltar ferramenta de web/search, declare a limitação." Pesquisa web com citações externas fica para bloco próprio de tool/runtime, não para B1.

**`atlas fix`** — não entra em B1. O comando depende de `DevExecutionPlan`, repair loop, checkpoint e trace filho. Implementar em B2 para evitar um loop frágil e duplicado.

**Mapping em `bin/atlas`:**
```bash
debug)         exec php artisan atlas:ai:chat "$@" --mode=debug --stream ;;
research)      exec php artisan atlas:ai:chat "$@" --mode=research --stream ;;
```

**Critério de pronto.** `atlas debug "..."` e `atlas research "..."` rodam end-to-end. Smoke test em `tests/Feature/Cli/` cobrindo mapping no launcher e `workflowMode()`.

**Tradeoff.** `research` sem tool de web/search não deve fingir profundidade externa. Mitigação: V1 deixa claro quando está usando apenas repo/Vault/documentos enviados; web real vira capacidade explícita do runtime.

---

### B2 — DevExecutionPlan + repair loop em `atlas dev` (4–6 dias)

**Objetivo.** Transformar `atlas:cli:dev` de preflight + delegação em **executor formal por etapas**, com persistência e retomada.

**Estado atual.** [AtlasCliDevWorkflowService](atlas-server/app/Services/Ai/Cli/AtlasCliDevWorkflowService.php) tem só `preflight()` e `chatCommand()`; o "plano" hoje é o que o provider escolhe em runtime.

**Entregáveis.**

**Schema `dev_execution_plan`** persistido em `ai_traces.metadata.execution_plan` (já há campo, hoje vazio):
```json
{
  "plan_id": "uuid",
  "objective": "...",
  "phases": ["inspect","plan","edit","test","repair","review","finish"],
  "current_phase": "edit",
  "steps": [
    {"id":"s1","phase":"inspect","status":"done","tool":"workspace.profile","duration_ms":120},
    {"id":"s2","phase":"plan","status":"done","output":"..."},
    {"id":"s3","phase":"edit","status":"running","tool":"file.patch","files":["a.ts"]}
  ],
  "iterations": {"current":1,"max":3,"reason_if_stopped":null},
  "checkpoints": ["20260430-..."]
}
```

**Mudanças.**
- Em `AtlasCliDevWorkflowService`, adicionar `executePlan(string $task, array $options): DevExecutionPlan`. Retorna VO com fases e steps.
- Novas flags em `AtlasCliDevCommand`: `--max-iterations=3`, `--complete` (só termina se todos os gates passam), `--resume <plan_id>`.
- Persistir plan_id em `ai_traces.metadata.execution_plan.plan_id`. Resume: carrega trace por plan_id, retoma do step pendente.
- Repair loop: após `test`, se `quality_gate.tests='failed'`, entra em `repair` por até `max_iterations`. Cada iteração é trace filha (`metadata.parent_trace_id`).
- Criar `AtlasCliFixCommand` como wrapper de `executePlan()` com foco em falha de teste/bug conhecido, não como loop independente:
```php
protected $signature = 'atlas:cli:fix
    {description?* : What to fix (or empty: pick last failed test)}
    {--workspace=}
    {--max-iterations=3}
    {--auto-test}
    {--allow-write}
    {--json}';
```
Mapping em `bin/atlas`: `fix) exec php artisan atlas:cli:fix "$@";;`.

**Tradeoff.** Plano formal pode rigidificar fluxos onde o provider faz a coisa certa em uma chamada só. Mitigação: para tasks pequenas (≤200 linhas previstas), usar caminho atual (`chatCommand()` direto) e marcar `execution_plan.mode='single_shot'`. `--plan-only` continua disponível.

**Tradeoff 2.** Persistir cada step custa I/O. Mitigação: 1 update em `ai_traces.metadata` por fase (não por step), agregando steps em buffer.

**Critério de pronto.**
- `atlas dev --plan-only "X"` imprime `dev_execution_plan` JSON.
- `atlas dev --complete "Y"` só termina com gates passados; sai com erro descritivo se 3 iterações sem sucesso.
- `atlas dev --resume <plan_id>` retoma na fase pendente.
- Trace persistido com `metadata.execution_plan.plan_id` e `phases[].status` corretos.

---

### B3 — Tool events ricos + `atlas trace` (3 dias)

**Objetivo.** Toda chamada de tool gera evento persistido. `atlas trace` permite auditar/reproduzir qualquer trabalho.

**Estado atual.** `AiToolRuntime` executa, mas eventos vivem em memória (classe `RuntimeEvent`). Apenas `AiWorkerEvent` é persistido (heartbeat).

**Entregáveis.**

**Migration nova:** `2026_05_xx_create_ai_tool_events_table.php`:
```php
Schema::create('ai_tool_events', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('trace_id')->index();
    $t->uuid('session_id')->nullable()->index();
    $t->uuid('thread_id')->nullable()->index();
    $t->string('tool', 64);              // file.write, shell.run, ...
    $t->string('risk', 16);              // low/medium/high
    $t->string('permission_status', 16); // approved/denied/auto
    $t->string('approval_source', 32)->nullable();
    $t->jsonb('input_summary');
    $t->jsonb('output_summary');
    $t->jsonb('changed_files')->nullable();
    $t->string('checkpoint_id', 64)->nullable();
    $t->integer('exit_code')->nullable();
    $t->integer('duration_ms');
    $t->text('error')->nullable();
    $t->timestamp('created_at');
    $t->index(['trace_id','created_at']);
});
```

**Mudanças.**
- `AiToolRuntime::execute()` recebe `?string $traceId`. Após cada `ToolResult`, grava `AiToolEvent` (hash truncado de input/output, nunca conteúdo sensível bruto).
- `AtlasRuntimeCommand` passa `traceId` quando rodando dentro de uma sessão (carrega trace ativo via `AiSessionStateService`).
- Sanitização: `shell.run.input.command` truncado a 200 chars; `file.write.content` substituído por `{lines: N, sha256: ..., bytes: M}`.

**Novo comando `atlas trace`** (`AtlasCliTraceCommand`):
```php
protected $signature = 'atlas:cli:trace
    {action=last : last, show, replay, list}
    {trace?  : Trace id (or empty: latest)}
    {--workspace=}
    {--limit=20}
    {--full : show full payload (default: redacted)}
    {--json}';
```
Ações:
- `last`: trace mais recente do workspace.
- `show <id>`: trace + tool events + quality + completion packet.
- `replay <id>`: imprime sequência reproduzível como script bash (read-only, sem reaplicar writes).
- `list`: últimas N traces resumidas.

Mapping em `bin/atlas`: `trace) exec php artisan atlas:cli:trace "$@";;`.

**Tradeoff.** Persistir 100% dos events é IO-pesado. Mitigação: tabela com retenção 30 dias (job `atlas:trace:prune` semanal); em modo `dev`/`debug`, eventos de leitura relevantes (`search.rg`, `git.diff`, `git.status`, `workspace.profile`, `package.detect`, testes) são gravados como resumo redigido porque fazem parte do raciocínio. Fora de `dev`/`debug`, read-only events podem ser agregados por tipo salvo `--full-trace`.

**Critério de pronto.**
- `atlas trace last` imprime trace mais recente com tool events em ordem.
- `atlas trace replay <id>` produz script bash válido.
- Em todo trace de modo dev, `ai_tool_events.count(by trace_id)` ≥ 1.

---

### B4 — Permission session + `atlas permissions` (2–3 dias)

**Objetivo.** Aprovações duradouras dentro de uma sessão (não 1 prompt por write), sem perder controle.

**Estado atual.** `AiPermissionEngine` decide por job; `AtlasRuntimeCommand` faz prompt 1× por invocação. Sem persistência de "aprovado para esta sessão".

**Entregáveis.**

**Migration:** `2026_05_xx_create_ai_permission_sessions_table.php`:
```php
Schema::create('ai_permission_sessions', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('thread_id')->nullable()->index();
    $t->uuid('session_id')->nullable()->index();
    $t->string('workspace');
    $t->string('mode', 16);  // read/write/danger
    $t->jsonb('allowed_tools')->nullable();
    $t->jsonb('allowed_paths')->nullable();
    $t->jsonb('denied_patterns')->nullable();
    $t->timestamp('expires_at')->nullable();
    $t->string('granted_by', 32)->default('operator_interactive');
    $t->text('reason')->nullable();
    $t->timestamps();
    $t->index(['workspace','expires_at']);
});
```

**Mudanças.**
- `AiPermissionEngine::authorizeJob()` consulta `AiPermissionSession` ativa antes de pedir confirmação. Se há sessão cobrindo (`mode`/`tool`/`path`/não expirada/não denied), aprova sem prompt.
- `AtlasRuntimeCommand` ao receber `--yes` com nova flag `--remember=2h` (formato Carbon: `30m`/`2h`/`1d`), cria/estende `AiPermissionSession`.

**Novo comando `atlas permissions`** (`AtlasCliPermissionsCommand`):
```php
protected $signature = 'atlas:cli:permissions
    {action=status : status, approve, revoke, list}
    {scope? : tool name or path}
    {--workspace=}
    {--mode=write}
    {--tool=*}
    {--path=*}
    {--for=2h : duration (10m, 2h, 1d)}
    {--reason=}
    {--all : revoke all}
    {--json}';
```

Mapping: `permissions) exec php artisan atlas:cli:permissions "$@";;`.

**Tradeoff.** Aprovações duradouras enfraquecem controle. Mitigação: (a) `danger` nunca tem permission session por mais de 30 min; (b) qualquer write fora de `allowed_paths` cai para prompt mesmo dentro de sessão; (c) qualquer falha de `secret-detection` (regex de chaves AWS, tokens GitHub, .env) revoga sessão imediatamente.

**Critério de pronto.**
- `atlas permissions approve write --path app/Services --for=1h` cria sessão e os próximos `file.write` em `app/Services` rodam sem prompt por 1h.
- `atlas permissions status` lista sessões ativas com expiração.
- `atlas permissions revoke --all` invalida todas.

---

### B5 — Memory delta tipado + `atlas memory` (5–7 dias)

**Objetivo.** Aprendizado pós-sessão estruturado, com evidência e validade. Substitui o "operator_notes" (24 últimas, sem schema) por delta tipado revisável.

**Estado atual.** `AiSessionState.operator_notes` existe mas é livre, manual, sem evidência nem validade.

**Entregáveis.**

**Migration:** `2026_05_xx_create_ai_memory_deltas_table.php`:
```php
Schema::create('ai_memory_deltas', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('source_trace_id')->nullable()->index();
    $t->uuid('source_session_id')->nullable()->index();
    $t->string('source_workspace');
    $t->string('type', 32); // preference|architecture|error_pattern|test_invariant|naming|process
    $t->text('claim');
    $t->jsonb('evidence');           // [{kind:'trace'|'file'|'log', ref:'...', excerpt:'...'}]
    $t->string('scope', 64);         // 'workspace:/path' | 'project:atlas' | 'global'
    $t->float('confidence');         // 0.0 - 1.0
    $t->timestamp('valid_from')->nullable();
    $t->timestamp('valid_until')->nullable();
    $t->jsonb('use_when')->nullable();
    $t->jsonb('do_not_use_when')->nullable();
    $t->boolean('requires_confirmation')->default(true);
    $t->string('status', 16)->default('pending'); // pending|accepted|rejected|superseded
    $t->uuid('superseded_by')->nullable();
    $t->timestamps();
});
```

**Geração.**
- Após `AiSessionManager::close()` ou `compact(reason='session_close')`, novo serviço `AiMemoryDeltaProposer` analisa `ai_session_states.decisions[]` + `quality_evaluations.flags[]` + `ai_quality_actions[]` da sessão e propõe deltas (heurística determinística primeiro; LLM-assisted opcional V2).
- Limite: máx 5 propostas por sessão.

**Aplicação.**
- Deltas `accepted` são injetados em `AiPromptBuilder::build()` na seção `context_pack.memory` quando `scope` cobre o workspace atual e `valid_until` não expirou.
- `AiSkillStore` consulta deltas relevantes para temperar skills (low priority — pode ficar para V2).

**Novo comando `atlas memory`** (`AtlasCliMemoryCommand`):
```php
protected $signature = 'atlas:cli:memory
    {action=review : review, accept, reject, list, show}
    {delta? : delta id (or empty in review: walk pending one by one)}
    {--workspace=}
    {--type=*}
    {--scope=}
    {--all : accept/reject all pending}
    {--json}';
```

Mapping: `memory) exec php artisan atlas:cli:memory "$@";;`.

**Tradeoff 1.** Heurística vai gerar ruído. Mitigação: `requires_confirmation=true` por default; bulk-reject fácil; Vitor pode editar `claim` antes de aceitar.

**Tradeoff 2.** Deltas em `context_pack` aumentam prompt. Mitigação: limite de 8 deltas por context_pack, ordenados por `confidence × recency`.

**Tradeoff 3.** Drift: aceitar delta hoje pode não fazer sentido em 6 meses. Mitigação: `valid_until` opcional default 90 dias; job `atlas:memory:expire` semanal.

**Critério de pronto.**
- Após sessão de dev real, `atlas memory review` apresenta 1–5 propostas com claim+evidence em <2 min de leitura.
- Aceito → trace seguinte no mesmo workspace traz delta no context_pack (visível em `atlas trace show`).
- Schema cobre 6 tipos em §schema acima.

---

### B6 — Provider router por evidência + `atlas compare` (3 dias)

**Objetivo.** Decisão de provider com sinais persistidos e razão explícita, não só heurística por modo.

**Estado atual.** [AtlasCliProviderStrategyService](atlas-server/app/Services/Ai/Cli/AtlasCliProviderStrategyService.php) decide por `mode` × `critical` × `health snapshot`. Decisão não é persistida com sinais.

**Entregáveis.**

**Migration:** `2026_05_xx_create_ai_router_decisions_table.php`:
```php
Schema::create('ai_router_decisions', function (Blueprint $t) {
    $t->uuid('id')->primary();
    $t->uuid('trace_id')->index();
    $t->string('mode', 32);
    $t->string('selected_provider', 32);
    $t->string('fallback_provider', 32)->nullable();
    $t->jsonb('signals');  // {provider_online, p50_latency_ms, success_rate_7d, quality_score_7d, pain_score, cost_estimate}
    $t->text('reason');
    $t->boolean('was_overridden')->default(false); // true se Vitor passou --provider=
    $t->timestamps();
});
```

**Mudanças.**
- `AtlasCliProviderStrategyService::recommend()` retorna `RouterDecision` VO; `AiGatewayService::enqueueInteraction()` persiste em `ai_router_decisions` com trace_id.
- Adicionar sinal `success_rate_7d` (calculado de `ai_quality_evaluations.score >= 80` por provider em 7d) e `quality_score_7d` (média de score). Computado por job semanal `atlas:router:rebuild-stats`.

**Novo comando `atlas compare`** (`AtlasCliCompareCommand`):
```php
protected $signature = 'atlas:cli:compare
    {input?* : Question or task}
    {--mode=direct}
    {--workspace=}
    {--no-stream}
    {--json}';
```
Equivalente a `atlas:ai:chat --provider=claude_codex` (que já dispara dual-review). Diferencia-se ao emitir output lado-a-lado e gravar `RouterDecision` com `selected_provider='claude_codex'` e `signals` de ambos.

Mapping: `compare) exec php artisan atlas:cli:compare "$@";;`.

**Tradeoff.** Computar `success_rate_7d`/`quality_score_7d` requer histórico — funciona só após ~50 traces. Mitigação: até lá, fallback para heurística atual.

**Critério de pronto.**
- Toda `atlas dev`/`atlas plan`/`atlas review` gera linha em `ai_router_decisions` com `signals` populados.
- `atlas compare "X"` mostra resposta dos dois providers e registra decisão.
- `atlas trace show <id>` exibe `router_decision.reason`.

---

### B7 — TUI interativa Fase 4 (7–10 dias)

**Objetivo.** Substituir dashboard JSON+tabela por TUI navegável de 7 painéis com atalhos.

**Estado atual.** [AtlasCliDashboardCommand](atlas-server/app/Console/Commands/AtlasCliDashboardCommand.php) renderiza o dashboard clássico. [AtlasCliTuiCommand](atlas-server/app/Console/Commands/AtlasCliTuiCommand.php) abre uma TUI navegável no terminal com 7 painéis e atalhos operacionais. `atlas tui --classic` preserva o fallback.

**Decisão de stack — primeiro entregável.** 4 candidatos viáveis:

| Stack | Pró | Contra |
|---|---|---|
| **Laravel Prompts** + **Termwind** (PHP) | Mesmo runtime; sem subprocess | Limitado para painéis; sem layout em grid |
| **Laravel Zero** standalone (PHP) | Idem | Reescrita do binário; redobra distribuição |
| **Charm/Bubble Tea** (Go binário) invocado por `bin/atlas tui` | TUI estado da arte; ANSI rico | Linguagem nova no projeto; comunicação via JSON-RPC ou stdin |
| **Textual** (Python) | Estado da arte; widgets ricos | Linguagem nova; instalação extra |

**Decisão implementada nesta versão:** TUI nativa em PHP/ANSI dentro de `AtlasCliTuiCommand`, sem dependência externa de Go, Python ou Laravel Zero. Razão: o runtime local verificado não tem toolchain Go instalado, e a versão final do CLI não deve depender de um segundo build system antes de o produto estar operacional. **Bubble Tea (Go)** continua sendo upgrade opcional de V2 para refinamento visual, não requisito bloqueante da versão terminal funcional.

**Painéis (`1`–`7`):** conversa, plano, diff, testes, permissões, memória, traces.

**Atalhos:** `r` refresh · `s` conversa/state · `p` plano · `d` diff · `t` testes · `a` aprovar write session temporária · `x` revogar permissões · `c` compactar sessão · `m` mudar provider por handoff · `q` sair.

**Streaming.** Subscreve `ai_tool_events` via polling (1s) ou Postgres `LISTEN/NOTIFY` (V2). Painel "diff" usa `atlas:runtime git.diff --json`.

**Tradeoff.** PHP/ANSI não tem o mesmo acabamento visual de Bubble Tea, mas reduz risco de instalação e entrega uma TUI real agora. Mitigação: manter `atlas tui --classic`, isolar a TUI em um command próprio e deixar a fronteira pronta para substituir a renderização por Bubble Tea no futuro sem mudar os comandos do usuário.

**Critério de pronto.**
- `atlas tui` abre TUI navegável em <500ms.
- 7 painéis funcionais; 9 atalhos respondem.
- Aprovação interativa de write/danger pelo painel de permissões.
- `atlas tui --classic` continua disponível.

---

### B8 — Upgrade, rollback, distribuição (2 dias)

**Objetivo.** Atlas se atualiza e recua sem retrabalho manual.

**Estado atual.** `atlas bootstrap` instala. Não há `update`, `version`, `rollback`.

**Entregáveis.**

**Versão.** Adicionar `config/atlas.php` campo `version` lido de `composer.json` ou de `VERSION` file no repo. Comando novo `AtlasCliVersionCommand`:
```php
protected $signature = 'atlas:cli:version {--json}';
```

**Update.** `AtlasCliUpdateCommand`:
```php
protected $signature = 'atlas:cli:update
    {--channel=stable : stable, beta}
    {--allow-dirty}
    {--dry-run}
    {--strict}
    {--json}';
```
Fluxo: exige worktree limpa ou `--allow-dirty`; cria backup de DB/config relevante; grava lock de manutenção local; `git fetch origin`; compara HEAD com remote; se há `--dry-run`, lista mudanças, migrations pendentes e risco; senão aplica fast-forward (`git pull --ff-only`) + `composer install` + `php artisan migrate --force` + `atlas doctor --strict`. Se qualquer etapa falhar, mantém backup e emite instrução de rollback.

**Rollback.** `AtlasCliRollbackCommand`:
```php
protected $signature = 'atlas:cli:rollback
    {--to= : version tag or commit sha}
    {--steps=1 : number of versions back}
    {--dry-run}
    {--confirm-data-loss}
    {--json}';
```
Fluxo: salva snapshot atual em tag local `atlas-rollback-{ts}` + backup de DB/config; valida target; faz `git checkout <target>`; `composer install`; calcula migrations aplicadas depois do target. Se forem reversíveis e `--confirm-data-loss` estiver presente quando necessário, roda `migrate:rollback`; caso contrário restaura backup ou aborta antes de tocar dados. Nunca rodar `php artisan migrate --force` como "rollback".

Mapping em `bin/atlas`:
```bash
version)       exec php artisan atlas:cli:version "$@" ;;
update)        exec php artisan atlas:cli:update "$@" ;;
rollback)      exec php artisan atlas:cli:rollback "$@" ;;
final)         exec php artisan atlas:cli:final "$@" ;;
dogfood)       exec php artisan atlas:cli:dogfood "$@" ;;
release)       exec php artisan atlas:cli:release "$@" ;;
```

**Tradeoff.** Rollback de código é simples; rollback de schema/dados pode ser destrutivo. Mitigação: backup obrigatório, dry-run padrão para migrations, bloqueio de rollback destrutivo sem `--confirm-data-loss` e restauração guiada se a reversão segura não existir.

**Critério de pronto.**
- `atlas version` mostra versão + commit + branch.
- `atlas update --dry-run` lista mudanças.
- `atlas update` aplica e roda doctor.
- `atlas rollback --steps=1 --dry-run` lista código, migrations e risco.
- `atlas rollback --steps=1` recua para versão anterior apenas se houver caminho seguro; `atlas doctor --strict` passa.

---

## 6. Anexos

### A. JSON packets canônicos (definitivos para versão final)

```json
// dev_execution_plan — em ai_traces.metadata.execution_plan
{
  "plan_id":"uuid", "objective":"...", "mode":"single_shot|multi_step",
  "phases":["inspect","plan","edit","test","repair","review","finish"],
  "current_phase":"edit",
  "steps":[{"id":"s1","phase":"inspect","status":"done|running|pending|failed","tool":"...","duration_ms":120}],
  "iterations":{"current":1,"max":3,"reason_if_stopped":null},
  "checkpoints":["20260430-..."]
}

// tool_event — linha em ai_tool_events
{
  "id":"uuid","trace_id":"uuid","session_id":"uuid","thread_id":"uuid",
  "tool":"file.write","risk":"medium","permission_status":"approved",
  "approval_source":"interactive_cli_once|permission_session|--yes",
  "input_summary":{"path":"app/...","lines":50,"sha256":"..."},
  "output_summary":{"bytes":2400,"sha256":"..."},
  "changed_files":["app/..."],"checkpoint_id":"20260430-...",
  "exit_code":0,"duration_ms":42,"error":null,"created_at":"ISO8601"
}

// permission_session — linha em ai_permission_sessions
{
  "id":"uuid","thread_id":"uuid","session_id":"uuid","workspace":"/abs",
  "mode":"write","allowed_tools":["file.write","file.patch"],
  "allowed_paths":["app/Services/"],"denied_patterns":["**/*.env","**/secrets/*"],
  "expires_at":"ISO8601","granted_by":"operator_interactive","reason":"..."
}

// memory_delta — linha em ai_memory_deltas
{
  "id":"uuid","source_trace_id":"uuid","source_session_id":"uuid",
  "source_workspace":"/abs",
  "type":"preference|architecture|error_pattern|test_invariant|naming|process",
  "claim":"...","evidence":[{"kind":"trace|file|log","ref":"...","excerpt":"..."}],
  "scope":"workspace:/abs|project:atlas|global","confidence":0.85,
  "valid_from":"ISO8601","valid_until":"ISO8601",
  "use_when":["..."],"do_not_use_when":["..."],
  "requires_confirmation":true,
  "status":"pending|accepted|rejected|superseded","superseded_by":null
}

// router_decision — linha em ai_router_decisions
{
  "id":"uuid","trace_id":"uuid","mode":"dev",
  "selected_provider":"codex_cli","fallback_provider":"claude_cli",
  "signals":{"provider_online":true,"p50_latency_ms":1800,
             "success_rate_7d":0.83,"quality_score_7d":78.4,
             "pain_score":0.12,"cost_estimate":null},
  "reason":"dev mode + codex online + sandbox required",
  "was_overridden":false
}
```

### B. Migrations propostas (resumo)

Em ordem: `ai_tool_events` (B3), `ai_permission_sessions` (B4), `ai_memory_deltas` (B5), `ai_router_decisions` (B6). Cada uma com índices declarados em §5.

### C. `bin/atlas` final — comandos novos

```bash
debug)         exec php artisan atlas:ai:chat "$@" --mode=debug --stream ;;
fix)           exec php artisan atlas:cli:fix "$@" ;;
research)      exec php artisan atlas:ai:chat "$@" --mode=research --stream ;;
trace)         exec php artisan atlas:cli:trace "$@" ;;
permissions)   exec php artisan atlas:cli:permissions "$@" ;;
memory)        exec php artisan atlas:cli:memory "$@" ;;
compare)       exec php artisan atlas:cli:compare "$@" ;;
version)       exec php artisan atlas:cli:version "$@" ;;
update)        exec php artisan atlas:cli:update "$@" ;;
rollback)      exec php artisan atlas:cli:rollback "$@" ;;
```

### D. Comandos finais (mapa completo)

| Surface | Existe hoje | Novo neste plano |
|---|---|---|
| `ask`, `chat` | ✅ | — |
| `dev`, `plan`, `review` | ✅ | `dev` ganha `--complete`, `--resume`, `--max-iterations` |
| `debug`, `research` | ❌ | ✅ B1 |
| `fix` | ❌ | ✅ B2 |
| `status`, `tui`, `dashboard` | ✅ | TUI ganha modo interativo B7 |
| `state`, `compact`, `handoff` | ✅ | — |
| `checkpoint(s)` | ✅ | — |
| `quality`, `finish`, `test` | ✅ | — |
| `runtime`, `tool` | ✅ | — |
| `threads`, `sessions` | ✅ | — |
| `bootstrap`, `setup`, `init`, `install`, `doctor`, `providers`, `health`, `work`, `profile`, `help` | ✅ | — |
| `final`, `readiness`, `product` | ✅ | Verificação B0-B8 + release preflight + doctor final |
| `dogfood` | ✅ | Registro de uso real + `dogfood run` smoke automatizado não-poluente |
| `release` | ✅ | Gate de release versionado + tag opcional; release final exige dogfood real; `--preflight` separado de release final |
| `trace` | ❌ | ✅ B3 |
| `permissions` | ❌ | ✅ B4 |
| `memory` | ❌ | ✅ B5 |
| `compare` | ❌ | ✅ B6 |
| `version`, `update`, `rollback` | ❌ | ✅ B8 |
| `code`, `inspect`, `council`, `new`, `close`, `remember`, `ingest` | spec órfã | **deprecated em B0** (resolver para `dev`/`review`/`compare`/`threads`/`memory`) |
| `sessions`, `switch` | aliases compatíveis | manter; canônicos são `threads` e `handoff` |

---

## 7. Estimativa total e ordem de release

| Bloco | Esforço | Pode paralelizar com | Release acumulada |
|---|---|---|---|
| B0 | 0.5 d | nenhum (gating) | v1.1 |
| B1 | 1 d | B0 | v1.2 |
| B2 | 4–6 d | B3 | v1.3 |
| B3 | 3 d | B2 | v1.3 |
| B4 | 2–3 d | B5 | v1.4 |
| B5 | 5–7 d | B4, B6 | v1.5 |
| B6 | 3 d | B5 | v1.5 |
| B7 | 7–10 d | B8 | v1.6 |
| B8 | 2 d | B7 | **v2.0 = versão final** |

Total sequencial: 27.5–35.5 dias úteis. Total com paralelização razoável (1 humano + agents): **18–25 dias úteis**.

---

## 8. Como executar

1. Aprove este plano (ou rejeite blocos individuais).
2. Para cada bloco, abrir 1 worktree, branch `atlas-cli/B<N>-<slug>`. Critério de PR: gates do bloco + testes verdes.
3. Cada bloco merged → tag `v1.<N>` → entradas em `CHANGELOG.md`.
4. Após B8, tag `v2.0`. Doc #3 ([Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md](Atlas_CLI_Produto_Final_Roadmap_7_Pontos.md)) e Doc #4 podem ser arquivados somente depois de seus packets e decisões terem sido migrados para `docs/atlas-packets-v1.md` e `docs/atlas-glossary.md`.

---

## 9. O que este plano NÃO cobre (escopo explícito)

- Revisão estética da TUI (ficará para uma rodada de design dedicada após B7 funcional).
- Sincronização Atlas-server ↔ Atlas-app (Expo) — fora do CLI.
- Memória semântica via vault/pgvector (já existe spec separada em [Atlas_Memoria_Semantica_Ativa_Compartilhada.md](Atlas_Memoria_Semantica_Ativa_Compartilhada.md)).
- Substituição da stack Laravel por outra (e.g. Go puro) — explicitamente descartado.
- App mobile/Web. Atlas-app continua como surface secundária.

---

**Verificação dialética antes de executar:**
1. Aprove ou aponte ajustes na **§3 Definition of Done** (são 11 critérios — vetar/adicionar).
2. Confirme **B7 stack = Bubble Tea (Go)** ou rejeite (e indique alternativa).
3. Confirme **ordem dos blocos** (alguma frente é mais urgente para você que merece pular a fila? Ex.: B5 memory antes de B3 trace?).
4. Aprove **deprecação** dos comandos órfãos (B0): `atlas code`, `atlas inspect`, `atlas council`, `atlas new`, `atlas close`, `atlas remember`, `atlas ingest`. `atlas sessions` e `atlas switch` permanecem como aliases compatíveis para `threads` e `handoff`.
