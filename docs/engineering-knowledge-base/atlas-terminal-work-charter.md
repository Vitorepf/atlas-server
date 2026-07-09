---
id: atlas-terminal-work-charter
type: engineering_knowledge
title: Atlas no Terminal — CHARTER da Sessão Focada (estado + gaps + roadmap + arquivos)
status: active
category: strategy
priority: 100
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "CHARTER auto-suficiente pra uma sessão focada SÓ em 'Atlas no Terminal'. Contém: as decisões pétreas (não construir casca própria; moat=VERIFICAÇÃO/review; 3 camadas), o ESTADO ATUAL VERIFICADO (o cockpit de review já está ~65% construído e wirado — atlas:cli:inbox + InboxActionRegistry approve/reject + forge-review fail-closed; falta 1 PRODUTOR: ligar as landings do autônomo vivo ao inbox), os gaps específicos do terminal, as 3 frentes, a 1a fatia recomendada com arquivos, e o mapa de classes/comandos. Companion do north-star atlas-terminal-first-focus.md. Reuse-first: quase tudo é ligar dormente, não construir."
tags: [atlas-ai, terminal, casca, review-cockpit, verification, cli, work-charter, focused-session]
capabilities: [terminal_review_cockpit, cli_situational_awareness, verification_surface]
decisions:
  - Atlas NÃO constrói casca própria; roda em host de terminal neutro (Maestri/iTerm). Moat = verificação/review, não gerar/editar código.
  - O cockpit de review NÃO é greenfield (~65% construído+wirado); o gap é 1 produtor (landings do autônomo → item de review). Reuse-first, não reconstruir.
  - Emitir o item de review de FORA do AtlasLoopMergeActuator (pétreo/Constitution) — nível atlas:task / pós-commit.
maintenance:
  - Atualizar o estado (vivo/dormente/quebrado) e o mapa de arquivos quando o cockpit evoluir; manter alinhado ao ledger de gaps.
related_paths:
  - docs/engineering-knowledge-base/atlas-terminal-first-focus.md
  - docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
  - app/Console/Commands/AtlasCliInboxCommand.php
  - app/Services/Ai/Mobile/InboxActionRegistry.php
  - app/Console/Commands/AtlasReviewDeepCommand.php
graph_id: atlas-terminal-work-charter
graph_title: Atlas Terminal Work Charter
graph_world: atlas
graph_kind: runbook
graph_parent: atlas-terminal-first-focus
graph_status: active
graph_source: repo
owner: atlas-ai
human_name: Atlas Terminal Work Charter
canonical_name: Atlas Terminal Work Charter
technical_name: atlas-terminal-work-charter
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-terminal-work-charter.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-terminal-work-charter.md
  - docs/engineering-knowledge-base/atlas-terminal-first-focus.md
  - docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Console/Commands/AtlasCliInboxCommand.php
  - app/Services/Ai/Mobile/InboxActionRegistry.php
  - app/Console/Commands/AtlasReviewDeepCommand.php
allowed_changes:
  - Atualizar estado/gaps/mapa de arquivos quando o cockpit de review evoluir.
forbidden_changes:
  - Reconstruir cockpit greenfield quando o gap e ligar produtor dormente.
  - Editar AtlasLoopMergeActuator para emitir review item.
depends_on:
  - atlas-terminal-first-focus
  - atlas-open-gaps-regressions-ledger
flows_to:
  - atlas-autonomos-live-system
unlocks:
  - focused-terminal-session
governs:
  - terminal-session-charter
evidence:
  - app/Console/Commands/AtlasCliInboxCommand.php
  - app/Services/Ai/Mobile/InboxActionRegistry.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
next_actions:
  - Ligar landings do autonomo vivo ao inbox de review (1 produtor).
---

# Atlas no Terminal — CHARTER da Sessão Focada

> **Como usar:** abra a sessão focada, leia (1) o north-star `atlas-terminal-first-focus.md` (a estratégia/decisões)
> e (2) este charter (estado + gaps + roadmap + arquivos). Trabalhe SÓ neste escopo. Reuse-first: quase tudo é
> **ligar dormente**, não construir. Antes de construir qualquer coisa, faça o census read-only do que já existe (§3).

## Resumo

Charter auto-suficiente para sessao focada em Atlas no Terminal: decisoes, estado, gaps, frentes e mapa de arquivos. Companion de `atlas-terminal-first-focus.md`. Glossary: `docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md`.

## Papel no Atlas

Runbook de sessao — operacionaliza o north-star terminal-first sem expandir escopo.

## Onde Se Encaixa

North-star → este charter → ledger de gaps → patches reuse-first no cockpit/CLI.

## Contratos

| Item | Regra |
|---|---|
| Escopo | so terminal/review/CLI |
| Postura | reuse-first |
| Emissao review | fora do MergeActuator |

## Fluxo

1. Bootstrap da sessao (§9 no corpo).
2. Census do estado atual.
3. Fechar primeira fatia (produtor inbox).
4. Atualizar charter/ledger.

## Regras para IA

- Nao construir casca.
- Nao greenfield do cockpit.
- Nao tocar MergeActuator petreo para emitir review.

## Escopo de Implementacao

Inclui ligar surfaces de review/CLI. Nao inclui shell propria nem rebuild do inbox.

## Dependencias

- `atlas-terminal-first-focus.md`
- Inbox/review commands
- Ledger de gaps

## Evidencias

Census e mapa de arquivos no corpo; comandos inbox/review em repo_paths.

## Riscos

Reconstruir o que ja existe; expandir escopo para app shell.

## Exemplos

Primeira fatia: emitir item de review a partir do caminho atlas:task / pos-commit, consumido por `atlas:cli:inbox`.

## Proximas Acoes

- Executar primeira fatia do corpo do charter.
- Manter % pronto honesto nas 3 frentes.

## 1. A DECISÃO (resumo — detalhe no north-star)
- **Atlas NÃO constrói casca própria.** Roda com poder cheio em host de terminal NEUTRO (Maestri/iTerm/tmux) porque é dirigível por CLI. Veredito 6-agentes: `must_build_own_shell=false`. A alavanca é o CONTRATO, não UI.
- **O moat = VERIFICAÇÃO/review** — o gargalo real de 2026 é *confiar na saída do agente*, não gerar código. É onde vive o M do Atlas (gates/evidence/proof). O recurso-matador (terminal agora, app Mac depois) mira **review**, não edição inline.
- **3 camadas:** L1 harness (Claude Code/Codex — alugar), L2 superfície CLI (849 cmds/600 rotas — investir, é a API do produto), L3 seams brain-behind (memória/gates/evidence — o M).
- App Mac/Mobile depois = superfície IRMÃ do MESMO backend (não pai-filho).

## 2. O MOAT EM UMA FRASE
Um cockpit no terminal onde o operador **vê o que o autônomo/Dev/Forge fez + a evidência + aprova/rejeita**. Isso torna o Maestri um cockpit de verdade (operador observa e decide, não edita) e é o M puro.

## 3. ESTADO ATUAL VERIFICADO (census wrxgcbok3 — reuse-first)

### ✅ JÁ EXISTE, VIVO e WIRADO (construir EM CIMA, não paralelo)
| Peça | Arquivo | O que faz |
|---|---|---|
| **Inbox spine** | `app/Console/Commands/AtlasCliInboxCommand.php` (`atlas:cli:inbox`) | inbox operacional real: list/show/review-critical/respond/dismiss/discuss; lê `ai_inbox_items` via `AtlasInboxService`; review-critical imprime "somente operador decide" |
| **Produtores do inbox (wired)** | `JobResultInboxEmitter` (chamado em `AiWorker.php:2548`), `ProposalInboxEmitter` (11 callers), `RecommendationInboxEmitter`, `InsightInboxEmitter`, `SelfDiagnosticEmitter` | todo job Dev/autônomo succeeded/failed vira item |
| **Veto real (approve/reject)** | `app/Services/Ai/Mobile/InboxActionRegistry.php` | `loop_operator_review_approve/reject` → merge/reject REAL; `review_patch`/`view_trace` → diff_refs/trace_refs/file_refs |
| **Forge review fail-closed** | `AtlasCodeForgeReviewCommand.php` + `AtlasCodeForgeReviewCompletionService.php` (`atlas:code:forge-review`) | approve/reject/rollback REAIS + review packet (evidence_pack_digest, diff_scope, changed_files); `approve()` exige runtime_status=passed + evidence_pack |
| **Cockpit por-obra** | `AtlasCodeObraCommandCenterCommand.php` (`atlas:code:obra-command-center`) | resumo humano: lifecycle, decision inbox, trust, primary_action, next_safe_action, readiness_progress |
| **Recorder de review** | `AtlasReviewDeepCommand.php` + `EngineeringReviewService::deepReview` (`atlas:review:deep`) | grava findings (severity/confidence/category) que VOCÊ passa via `--finding`; computa gate; grava evidence pack. **É recorder, não gerador.** |
| **Padrão de fila de review** | `AtlasMemoryReviewQueueCommand` (`atlas:memory:review-queue`) | fila unificada priority/kind/scope/target/reason/action_hint (mas de MEMÓRIA, não código — prova que o padrão existe) |
| **Render rico no terminal** | `app/Support/TerminalMarkdownRenderer.php` | usado por `AtlasCliStartCommand:178` + `AiChatCommand` (só 2 de 874 comandos — subusado) |

### 🟡 DORMENTE (built-but-unwired — LIGAR, não reescrever)
- **`AtlasLoopOperatorReviewMobilePublisher`** (`app/Services/Ai/AutonomousEvolution/`) — self-titled *"the MISSING SPINE"*, emite item proposal com botões Aprovar/Rejeitar. **0 callers** e aponta pro loop MORTO. → **repontar pros task-workers vivos.**
- `atlas:ai:self-construction:merge-review` — stub que só imprime `shell_ready`.
- Itens `job_result`/`completion` no inbox: só `view_trace`+`dismiss` (`AtlasInboxService.php:614`), **sem approve/reject**.

### 🔴 QUEBRADO no HEAD (não usar até consertar)
- Família **`TerminalLoopProof*`/`TerminalLoopHealthDigest*`** (`SelfConstruction/TerminalLoopProof/`, `.../ControlPlane/`): substância real (lê fila/leases, roda cenários) MAS **fatal por regressão de namespace** (`cd018c6b3f`, `task_52704584` em conserto em outra sessão) + só roda por ritual manual + lê `AgentControlPlaneTaskPacketQueue` (fila do loop-morto), não o `atlas:task`/`atlas:brain` vivo.

## 4. OS GAPS DO FOCO (subconjunto do ledger relevante ao terminal)
> Fonte completa: `atlas-open-gaps-regressions-ledger.md`.

| ID | Sev | O quê | Onde |
|---|---|---|---|
| **GAP-COCKPIT-01** | 🟠 **(o destrave)** | landings do autônomo vivo NÃO emitem item de review (~4.841 passam fora do cockpit); o publisher feito pra isso está 0-caller+aponta pro morto | caminho `atlas:task`/`MergeActuator` |
| GAP-COCKPIT-02 | 🟡 | itens job_result/completion sem approve/reject | `AtlasInboxService.php:614` |
| GAP-COCKPIT-03 | 🟡 | sem agregação cross-surface (Dev+Forge+autônomo num feed) | — |
| GAP-COCKPIT-04 | 🟡 | `atlas:review:deep` é recorder, não gerador ("rode deep sobre esta landing") | `AtlasReviewDeepCommand` |
| GAP-CLI-01 | 🟡 | sem cockpit ÚNICO do motor vivo (brain+fila+landings+saúde) — read-models já existem: `brain:summary`, `brain:metrics`, `task:health`, `autonomy:status` | — |
| GAP-CLI-02 | 🟡 | `atlas status`/`cli:dashboard` cego pro motor autônomo (só AiJob/traces/chat) | — |
| GAP-CLI-03 | 🟢 | `TerminalMarkdownRenderer` subusado (2 de 874) | `app/Support/TerminalMarkdownRenderer.php` |

## 5. AS 3 FRENTES (com % pronto honesto)
1. **[RECOMENDADO] Cockpit de review no terminal — ~65% pronto.** Leitura (inbox) + veto (InboxActionRegistry/forge-review) já vivos; falta 1 produtor.
2. **Ergonomia CLI / situational awareness — ~55%.** Read-models existem; falta o agregador "o que o Atlas faz AGORA".
3. **Endurecer o contrato (L3) — fechar o bypass do `AiProviderManager`.** Fundacional, invisível.

## 6. A PRIMEIRA FATIA (detalhada — maior destrave por esforço)
**Ligar o produtor de review do autônomo vivo:** a cada landing de task, emitir um item no inbox com **diff + evidence-pack + approve/reject**.
- **Reusar:** `AtlasLoopOperatorReviewMobilePublisher` (repontado do loop morto pros task-workers) + o verdict do `InboxActionRegistry` (loop_operator_review_approve/reject já merge/reject) + o gate fail-closed do `forge-review` (exige runtime_status=passed + evidence_pack) como contrato.
- **Onde emitir:** de FORA do `AtlasLoopMergeActuator` (pétreo/Constitution — NÃO editar o arquivo congelado). Nível `atlas:task` / pós-commit (ver `SelfConstruction/AtlasTaskScopedCommitter`).
- **+ adicionar approve/reject** aos itens `job_result`/`completion` em `AtlasInboxService.php:614`.
- **Por quê primeiro:** leitura + veto + gate já existem e estão wirados; ligar o produtor **destrava o cockpit inteiro de uma vez**.

**Fatia 2 (paralela):** UM comando `atlas:*:cockpit` agregando `brain:summary` + `task:health` + `autonomy:status` + landings recentes por autor, renderizado com `TerminalMarkdownRenderer`. Responde "o que o Atlas faz agora".

## 7. MAPA DE ARQUIVOS (onde mexer)
- **Inbox/cockpit:** `app/Console/Commands/AtlasCliInboxCommand.php`, `app/Services/Ai/Mobile/InboxActionRegistry.php`, `app/Services/Ai/Mobile/AtlasInboxService.php` (:614 actions).
- **Produtores:** `app/Services/Ai/**/JobResultInboxEmitter.php`, `ProposalInboxEmitter.php`; o publisher dormente `app/Services/Ai/AutonomousEvolution/AtlasLoopOperatorReviewMobilePublisher.php`.
- **Review/verdict:** `AtlasReviewDeepCommand.php`, `AtlasCodeForgeReviewCommand.php` + `AtlasCodeForgeReviewCompletionService.php`, `AtlasCodeObraCommandCenterCommand.php`.
- **Commit vivo:** `app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php`; committer pétreo `Constitution/AtlasLoopMergeActuator.php` (NÃO editar).
- **Render:** `app/Support/TerminalMarkdownRenderer.php`.
- **Read-models do motor:** comandos `atlas:brain:summary/metrics`, `atlas:task:health`, `atlas:*:autonomy:status`.
- **L3 contrato:** `app/Services/Ai/AiProviderManager.php` (fechar bypass).

## 8. GUARD-RAILS (pétreo)
- Aditivo-only; commit ESCOPADO (nunca `git add -A`, nunca push sem OK); `AutonomousEvolution/` → git escopado (`forbidden_self_target`).
- NÃO construir casca própria; NÃO edição inline como diferencial; NÃO tratar app Mac como pai da CLI.
- NÃO editar o `Constitution/AtlasLoopMergeActuator.php` (pétreo, hash-guardado) — emitir de fora.
- Reuse-first: census do que existe (§3) antes de construir. NÃO confundir loop-morto (`atlas:loop:*`) com o vivo (`atlas:brain:*`/`atlas:task:*`).
- **Vocabulário proibido:** Jarvis, Rivals, benchmark, superiority, concurrent; "Atlas concorre com Claude Code" (é: substitui como produto, usa como engine).
- Interface humana = linguagem natural (terminal = control-plane/engine, não a UX final).

## 9. BOOTSTRAP DA SESSÃO FOCADA
```
# 1. contexto canônico
php artisan atlas:ai:session-bootstrap --task="Atlas no terminal — cockpit de review" --json
# 2. ler
#   docs/engineering-knowledge-base/atlas-terminal-first-focus.md   (north-star)
#   docs/engineering-knowledge-base/atlas-terminal-work-charter.md  (este)
#   docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md (gaps COCKPIT/CLI)
# 3. census reuse-first antes de construir (§3)
# 4. começar pela 1a fatia (§6): ligar o produtor de review do autônomo
```
Memórias-âncora: `terminal-first-strategy-validated`, `atlas-shell-independence-verdict`, `maestri-cockpit-adoption`, `atlas-autonomos-live-system` (o vivo).
