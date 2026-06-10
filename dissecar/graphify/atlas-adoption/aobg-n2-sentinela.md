# AOBG N2 — Atlas Open Brain Guardian: a sentinela, em produto

Estado: **PRODUTO, LIGADO NO DEV** (2026-06-10). N1 deu ao cérebro uma porta da frente (pack provider-bound + write-back governado + multi-project + hook full-brain no `UserPromptSubmit`). **N2 faz o cérebro INTERVIR DURANTE a sessão** em vez de esperar ser perguntado. Tudo provado LIVE contra o pgsql de dev, custo zero (read tools = DB local, zero provider). Não commitado (decisão do operador).

> Doc de operador. A árvore canônica (`docs/engineering-knowledge-base`) exige frontmatter de cartografia; este doc vive em `dissecar/` de propósito, fora do contrato de grafo.

## O salto de N1 para N2

- **N1 = a biblioteca.** A IA externa só recebia o cérebro se *pedisse* (`atlas_context_pack`) ou no início do prompt (hook `UserPromptSubmit`). Passivo.
- **N2 = a sentinela.** O cérebro segue a tarefa e **age sozinho** nos eventos do ciclo de vida da ferramenta: antes de um write (PreToolUse), depois de tocar um arquivo (PostToolUse), no fim da sessão (Stop). Ativo.

A regra-mãe de N1 continua: **não se constrói um segundo motor.** As 4 superfícies de N2 são camadas de **montagem** sobre os cérebros já provados (AURG reality graph provider-bound + memória semântica redigida + code-graph workspace-scoped + write-back governado de N1). Nada novo de retrieval, escrita ou enforcement foi inventado — só **quando** o cérebro fala mudou.

## As 4 superfícies da sentinela

| # | Superfície | Evento Claude Code | Comando | O que faz |
|---|---|---|---|---|
| **F1** | Cérebro ativo (segue a tarefa) | `PostToolUse` (Read/Edit/Write/MultiEdit/NotebookEdit) | `atlas:aobg:file-context` | Depois que a IA toca um arquivo, injeta **o que o cérebro sabe sobre ele**: decisões/missões (AURG), memória redigida, vizinhos do code-graph, símbolos definidos. |
| **F2** | Sentinela (guardrail antes do write) | `PreToolUse` (Edit/Write/MultiEdit/NotebookEdit) | `atlas:aobg:guard` | **Antes** do edit pousar, avalia a mudança contra o cérebro e devolve `allow\|warn\|block`. A superfície de segurança-killer. |
| **F3** | Captura estrutural | `Stop` / `SessionEnd` | `atlas:aobg:capture-session` | No fim da sessão, **distila deterministicamente** (sem LLM) o que a sessão fez → write-back governado: nó de missão/evidência (nunca merge) + learnings `pending_review`. |
| **F4** | Blackboard (coordenação multi-engine) | lido por F2 | tools MCP `atlas_claim_task` / `atlas_blackboard_status` | Tabela de **claims** de trabalho que expira por TTL ("codex está editando fileX"). F2 lê e avisa quando OUTRO engine já segura o arquivo. |

Os transportes (MCP / CLI / hook) chamam os MESMOS serviços:

- `app/Services/Ai/AtlasOpenBrainFileContextService.php` — F1 (o delta por-arquivo).
- `app/Services/Ai/AtlasOpenBrainGuardService.php` — F2 (a sentinela).
- `app/Services/Ai/AtlasOpenBrainSessionCaptureService.php` — F3 (o distiller) → `AtlasOpenBrainWriteBackService.php` (a porta governada de N1).
- `app/Services/Ai/AtlasAobgBlackboardService.php` — F4 (o blackboard).

Hooks: `.claude/hooks/atlas-postedit-context.sh` (F1), `atlas-pretooluse-guard.sh` (F2), `atlas-session-capture.sh` (F3). Registrados em `.claude/settings.json`.

## F2, a sentinela — os 4 checks (cite-or-omit, cada um fail-safe)

`AtlasOpenBrainGuardService::evaluate($path, $opts)` roda 4 checks independentes, cada um degradando a "sem achado" se falhar:

1. **SENSITIVE-CLASS** (path-only, nunca toca conteúdo) — o path é uma área soberana (cyber/secret/sensitive)? Classifica por **segmento de diretório inteiro** (`secrets/`, `app/Finance/`, `cyber/`) + shapes de arquivo de credencial no basename (`.env`, `.pem`, `.key`, `id_rsa`, `credentials`). Uma classe que só *contém* a palavra (ex.: `PaymentLedgerWriter` numa pasta normal) **não** dispara — falso positivo aqui brica a sessão. É a **maior confiança**: o único sinal só-de-path que pode hard-block.
2. **DECISION VIOLATION** — o arquivo/módulo é governado por uma decisão **registrada** que o diff parece **contradizer**? Decisões vêm de dois cérebros provider-safe: recall semântico (`memory_type=decision`, projeção redigida) + nós de decisão do AURG cujo caminho cross-layer toca o módulo. Conservador: `exact` (o único sinal de memória que pode hard-block) **só** quando a decisão declara uma restrição ("must use X / never use Y") **E** o diff carrega um token que a nega. Sem diff → sempre `advisory` (não dá pra provar contradição).
3. **DUPLICATION** (sempre advisory, **nunca** bloqueia) — o edit recria um símbolo que o code-graph já tem? O stem proposto + qualquer `class X` / `function X` no diff é casado contra o índice de símbolos workspace-scoped; match exato de nome curto num arquivo DIFERENTE vira "você pode estar reconstruindo Z".
4. **CROSS-ENGINE-CLAIM** (F4 blackboard, sempre advisory) — OUTRO engine já tem um claim ativo neste arquivo? Surfa "codex está editando este arquivo". O engine que pergunta é **excluído** (um engine nunca avisa sobre o próprio claim).

## A segurança: advisory por padrão, hard-block opt-in, fail-OPEN sempre

Esta é a regra não-negociável de N2 — um guardrail que BLOQUEIA é high-stakes, porque um falso positivo brica a sessão do operador.

- **PADRÃO = `warn` (advisory).** Qualquer achado vira `warn`: o hook PreToolUse devolve `permissionDecision: "allow"` + o aviso como `additionalContext`. **O modelo vê o heads-up, o edit prossegue.** Nunca um portão.
- **`block` é OPT-IN** via `config atlas.aobg.guard.block_enabled` (default **FALSE**; env `ATLAS_AOBG_GUARD_BLOCK_ENABLED`) **E** limitado às violações de maior confiança: um toque em path sensitive/secret/cyber **OU** uma contradição `exact` a uma decisão registrada. Duplicação e cross-engine-claim **nunca** bloqueiam.
- **Edit limpo nunca bloqueia** — mesmo com o flag ON. Só os dois sinais de maior confiança disparam `block`.
- **FAIL-OPEN sempre.** Qualquer erro/timeout/cérebro-fora → `allow`. O serviço **nunca lança**; o comando **sempre sai 0** (a decisão vai no payload, não no exit code) — um hook nunca pode ser quebrado por um exit não-zero.

### Como o hook PreToolUse mapeia a decisão (`.claude/hooks/atlas-pretooluse-guard.sh`)

| Decisão do guard | O que o hook emite ao Claude Code |
|---|---|
| `warn` | `permissionDecision: "allow"` + `additionalContext` (o aviso). Edit prossegue. |
| `block` | `permissionDecision: "deny"` + `permissionDecisionReason`. O ÚNICO caminho de bloqueio. |
| `allow` (edit limpo) | **Nada** — o fluxo normal de permissão aplica. O hook nunca auto-concede. |
| erro / timeout / tool faltando | **Nada** → fail-open (o edit prossegue). |

O hook tem teto de wall-clock (`timeout 8s`, configurável) — um cérebro lento NUNCA estala a sessão.

## Como LIGAR o hard-block (quando o operador quiser)

```bash
# Opção A — flag global (todos os hard-blocks de maior confiança):
echo 'ATLAS_AOBG_GUARD_BLOCK_ENABLED=true' >> .env && php artisan config:clear

# Opção B — armar só um dry-run do hook, sem mexer no .env:
echo '{"tool_name":"Write","tool_input":{"file_path":"secrets/keys.env"}}' \
  | ATLAS_AOBG_GUARD_FORCE_BLOCK=1 .claude/hooks/atlas-pretooluse-guard.sh
```

Com o flag ON, **só** um toque em path soberano ou uma contradição `exact` de decisão devolve `deny`. Todo o resto continua `warn` (advisory). Mesmo com ON, um cérebro-fora ainda fail-OPEN para `allow`.

## A assimetria Claude Code (rico) × Codex/Cursor (subset) — honesta, nunca "paridade"

Esta é a verdade load-bearing, escrita em cada hook:

- **Claude Code tem hooks ricos** (`PreToolUse` pode `deny` uma tool call; `PostToolUse` injeta contexto; `Stop` dispara captura). Então a **intervenção AUTOMÁTICA antes/depois/no-fim** é uma capacidade **só do Claude Code**.
- **Codex / Cursor NÃO têm esses hooks.** Eles alcançam o MESMO cérebro só pelo **subset de tools MCP** (`atlas_claim_task`, `atlas_blackboard_status`, e o guard via CLI/MCP) — **chamável sob demanda**, NÃO auto-disparado antes de uma tool rodar, e **incapaz de negar** uma tool call.
- Nenhum hook reivindica que esses engines recebem a mesma intervenção automática. O blackboard (F4) é a ponte: como ambos falam MCP, ambos podem reivindicar + ver claims — a coordenação cross-engine funciona, mas o *enforcement* antes-do-write é Claude-Code-only.

## Comandos

```bash
php artisan atlas:aobg:file-context <path> [--workspace=] [--json]        # F1
php artisan atlas:aobg:guard <path> [--diff=] [--engine=] [--block] [--json]   # F2
php artisan atlas:aobg:capture-session --transcript=<jsonl> [--workspace=] [--json]  # F3
# F4 blackboard via MCP: atlas_claim_task / atlas_blackboard_status (ambos engines)

# dry-runs dos hooks (custo zero):
echo '{"tool_name":"Edit","tool_input":{"file_path":"app/Foo.php","new_string":"x"}}' | .claude/hooks/atlas-pretooluse-guard.sh
echo '{"tool_name":"Read","tool_input":{"file_path":"app/Foo.php"}}' | .claude/hooks/atlas-postedit-context.sh
echo '{"transcript_path":"/tmp/session.jsonl"}' | .claude/hooks/atlas-session-capture.sh
```

## Config (`config/atlas.php` → `aobg`)

```
guard.block_enabled=false       # OPT-IN hard block (env ATLAS_AOBG_GUARD_BLOCK_ENABLED). default OFF.
guard.max_decisions=8  guard.max_duplicates=8  guard.max_reasons=12  guard.budget_chars=2500
guard.soft_budget_ms=1500       # o hook impõe um timeout HARD; isto é só self-report
file_context.budget_chars=3500  file_context.max_neighbors=12  max_memory=6  max_paths=8
session_capture.max_files=50  max_learnings=10  max_learning_chars=600  (+ caps de transcript)
blackboard.default_ttl_seconds=3600  max_ttl_seconds=86400  max_active=200  max_target_chars=500
```

As garantias duras (provider-safety, never-merge, never-auto-promote, fail-open) são **estruturais** nos serviços, **não config-tunable**. Os knobs acima são só pisos de tamanho/perf/TTL.

## Limites honestos (anti-over-claim)

- O guard é **cite-or-omit + curated top-K** (`HONESTY_LABEL = "curated top-K (not exhaustive); advisory-by-default, fail-open"`), não um analisador completo. Ele pega contradições conservadoras de decisão e duplicações de nome exato — **não** raciocínio semântico profundo sobre a mudança.
- `block` é deliberadamente **raro**: só 2 sinais de maior confiança, e só com o flag ON. O design prefere deixar passar um edit ruim (warn) a brickar a sessão (falso block).
- O blackboard é **coordenação, não lock**: um claim não rouba nem trava; é metadata advisory. Dois engines querendo o mesmo arquivo é um sinal de hand-off, não uma violação.
- F3 é **distill determinístico** (sem LLM/provider) — pega só learnings EXPLÍCITOS e file-cited; o que ele NÃO destila o operador ainda registra à mão via N1.
- A intervenção automática (hooks) é **Claude-Code-only**; Codex/Cursor têm o subset MCP chamável, não o enforcement. (ver assimetria acima)
- Custo: tudo read-only / DB local, **zero provider/HTTP**. F3 não chama LLM.

## Provas LIVE desta finalização (números reais, contra o pgsql de dev)

- **F1** `atlas:aobg:file-context` em `app/Services/Ai/Reality/AtlasRealityGraphQueryService.php`: 12 símbolos definidos, 1 consumidor real (`AtlasAurgQueryCommand::handle`), recall_count=6 → 0 injetados (todos redigidos como não-provider-safe, honesto), `provider_bound=true`, 2.1s.
- **F2** edit normal → `decision=allow`, `GUARD_BLOCKS_NORMAL_EDIT:NO`, todos os checks false. Path secret-class (`config/secrets/app.env`, block OFF) → `decision=warn` (NÃO block), reason SENSITIVE-CLASS. Com `--block` no path secret → `decision=block` (`OPT_IN_BLOCK_WORKS:YES`). Edit limpo com `--block` → ainda `allow` (`NORMAL_EDIT_BLOCKED_EVEN_WITH_FLAG:NO`).
- **F3** `atlas:aobg:capture-session` em fixture de transcript: 1 arquivo tocado, 1 learning extraído como `pending_review`, `merged=false`, `auto_promoted=false`, `provider_bound=true`. Memória canônica intocada.
- **F4** claim como `codex` → guard como `claude_code` no mesmo arquivo → `CROSS_ENGINE_WARN:YES` ("codex is already editing this file"); guard como `codex` (dono) → `ENGINE_WARNS_ABOUT_OWN_CLAIM:NO`; cleanup → 0 active.
- **FAIL-OPEN**: workspace-identity quebrada (brain down) + path secret + diff com `class` + flag ARMADO → `decision=allow`, `fail_open=true` (`test_fail_open_allows_when_a_check_throws`). O caso de maior confiança ainda fail-OPEN.
- **Hook PreToolUse** dry-run: secret + sem-block → `permissionDecision: allow` + additionalContext; secret + FORCE_BLOCK → `permissionDecision: deny` + reason; edit limpo → emite NADA; tool Read → no-op (é o gate de WRITE).
- **MCP**: `tools()` = **55 tools**; os 2 do blackboard presentes (`atlas_claim_task`, `atlas_blackboard_status`).
- **Hooks registrados** em `.claude/settings.json`: UserPromptSubmit (N1) + PreToolUse + PostToolUse + Stop (os 3 de N2). `shellcheck -S warning` limpo nos 4. `php -l` limpo em todos os serviços/comandos/testes.
- **DB de dev limpo** ao fim (blackboard 0 rows; nós AURG de teste do capture removidos).
- **Bateria de regressão (surface AOBG)**: **138 testes / 1076 assertions VERDES** nos 8 arquivos do surface (Blackboard 11, Guard, FileContext, SessionCapture, McpService, ContextPack, WriteBack, WorkspaceOnboarding). + `tests/Feature/Reality/` **32/344 verdes**; `tests/Unit/Ai/SelfConstruction/` **86 verdes** (1 risky, não-falha).
- **Bateria ampla `tests/Feature/Ai/` (8736 testes)**: 95 errors + 57 failures — **ZERO nas minhas superfícies** (provado: 0 ocorrências de Aobg/Guard/Blackboard/FileContext/SessionCapture na lista de falhas). O vermelho é **pré-existente/ambiental** (mid-refactor/dirty-tree, doc da memória `atlas-server-test-suite-infra`): provado reproduzindo `SurfaceDomainCatalogInteractionApiTest` (2 failed) **idêntico com minhas mudanças em `git stash`** — não é meu. Re-rodei os 8 arquivos AOBG isolados: 138/138 verdes de novo.
