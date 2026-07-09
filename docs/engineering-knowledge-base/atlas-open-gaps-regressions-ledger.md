---
id: atlas-open-gaps-regressions-ledger
type: engineering_knowledge
title: Atlas — Ledger de Gaps, Regressões e Falhas Abertas (implementation-ready)
status: active
category: strategy
priority: 99
doc_schema: atlas_canonical_module_doc.v1
graph_layer: system
summary: "LEDGER VIVO de gaps/erros/falhas abertos do Atlas, com PROVA por entrada (file:line + evidência verificada), severity, broken_now, fix_approach e esforço — para que na hora de corrigir/implementar tudo resolva liso. Foco inicial: cockpit de review terminal + regressões do drift recente + governança dormente. Cada entrada é aditiva e escopada. NÃO é backlog de features; é dívida técnica e wiring verificados. Curado por gap-hunt contínuo (workflows de census)."
tags:
  - atlas-ai
  - gap-ledger
  - regressions
  - technical-debt
  - review-cockpit
  - wiring
  - implementation-ready
capabilities:
  - open_gap_tracking
  - regression_ledger
decisions:
  - Só entra o que tem PROVA verificada (broken_now exige evidência real; dormente entra pelo valor destravável). Falso-alarme vai pra seção de refutados.
  - Fix-order = broken_now crítico → alto destrave/baixo esforço → resto. Reuse-first (ligar dormente antes de construir novo).
maintenance:
  - Apendar por sweep do gap-hunt; marcar status (ABERTO/EM-FIX/FECHADO) e o commit quando resolver; nunca remover entrada fechada (histórico), só marcar.
related_paths:
  - docs/engineering-knowledge-base/atlas-terminal-first-focus.md
  - docs/engineering-knowledge-base/atlas-autonomos-live-system.md
---
# Atlas — Ledger de Gaps, Regressões e Falhas Abertas

> **Propósito:** caçar e DOCUMENTAR com prova todo gap/erro/falha antes da hora de corrigir, pra
> implementação sair lisa. Cada entrada = prova (file:line + evidência), severity, `broken_now`, fix e esforço.
> Curado por **gap-hunt contínuo**. Companion do foco: `atlas-terminal-first-focus.md`.
> Legenda status: **ABERTO** · **EM-FIX** (sendo resolvido) · **FECHADO** (commit anotado).

## A. Regressões / quebrado-no-main (maior prioridade)

### REG-01 — proof-family fatal por namespace (cd018c6b3f) · `broken_now` · **EM-FIX** (task_52704584)
- **Onde:** `app/Services/Ai/SelfConstruction/…/ControlPlane/` — `TerminalLoopHealthDigest*` + `…OperationalProof*` movidos de namespace, refs relativas antigas ficaram.
- **Sintoma:** os comandos de health-digest e operational-proof dão fatal *Class not found* no HEAD.
- **Evidência:** census wrxgcbok3 (proof-family ~40%, "QUEBRADO no main"); task_52704584 já aberta.
- **Fix:** corrigir os namespaces/imports (mecânico, ~10min). *Sendo feito em sessão separada.*
- **Esforço:** baixo · **Destrave:** baixo (surface roda por ritual manual + alimenta fila do loop morto).

### REG-02 — bind órfão `WorkspaceProviderLoopExecutionDriver` (deletado em cd018c6b3f) · **ABERTO**
- **Onde:** `app/Providers/…` bind `LoopExecutionDriver → WorkspaceProviderLoopExecutionDriver` (classe deletada).
- **Sintoma:** fatal se o bind for resolvido (caminho-músculo #3, hoje morto em main).
- **Evidência:** census wrxgcbok3 (contract-hardening): "Bind órfão … fatal se resolvido". A confirmar: o bind ainda existe no provider?
- **Fix:** remover o bind órfão (ou repontar), aditivo. **Sweep 1 está verificando isto agora.**
- **Esforço:** baixo · **Severity:** alto (fatal latente).

## B. Cockpit de review no terminal (frente #1 — ~65% pronto; falta 1 produtor)

### GAP-COCKPIT-01 — landings do autônomo vivo não emitem item de review · **ABERTO** · destrave MÁXIMO
- **Onde:** caminho `atlas:task` / `AtlasLoopMergeActuator` (commit escopado) — nenhum `InboxEmitter`.
- **Sintoma:** ~4.841 landings passam 100% fora do cockpit; operador não vê nem veta.
- **Evidência:** census; publisher feito pra isso (`AtlasLoopOperatorReviewMobilePublisher`, self-titled "the MISSING SPINE") tem **0 callers** e aponta pro loop morto.
- **Fix:** repontar o publisher pros task-workers vivos + emitir de **fora** do `MergeActuator` (pétreo Constitution/ — não editar o arquivo congelado); nível `atlas:task`/pós-commit.
- **Esforço:** médio · **Severity:** alto. Destrava o cockpit inteiro (leitura+veto já prontos).

### GAP-COCKPIT-02 — itens job_result/completion sem approve/reject · **ABERTO**
- **Onde:** `app/Services/Ai/Mobile/AtlasInboxService.php:614` (só `view_trace`+`dismiss`).
- **Fix:** adicionar approve/reject reusando o gate fail-closed do `forge-review` (exige `runtime_status=passed` + evidence-pack). Aditivo.
- **Esforço:** médio · **Severity:** médio.

### GAP-COCKPIT-03 — sem agregação cross-surface (Dev+Forge+autônomo num feed) · **ABERTO**
- **Fix:** um feed sobre `atlas:cli:inbox` (spine já vivo) juntando as 3 superfícies. **Esforço:** médio.

### GAP-COCKPIT-04 — `atlas:review:deep` é recorder, não gerador · **ABERTO**
- **Onde:** `AtlasReviewDeepCommand` + `EngineeringReviewService.deepReview` (recebe `--finding` pronto).
- **Fix:** wirar "rode deep sobre esta landing e mostre os findings". **Esforço:** médio.

## C. Contract hardening / governança (frente #3 — ~78%; dormente por flag)

### GAP-GOV-01 — governança MEDE mas não GOVERNA (enforce=OFF) · **ABERTO** · baixo esforço
- **Onde:** `config/atlas.php:1168` `enforce=OFF` + `cost_guard.hard_units=0` → `should_block` nunca dispara.
- **Fix:** provar que músculo roda instrumentado (ver GAP-GOV-02) e então virar `enforce=ON` + `hard_units>0`. Hook já wirado nos 2 músculos.
- **Esforço:** baixo · **Severity:** médio.

### GAP-GOV-02 — ledger sem records de músculo (bypass-rate não-provado) · **ABERTO**
- **Evidência:** 66/66 records são `ai_provider_manager`; 0 de músculo. Bypass caindo = não-provado.
- **Fix:** instrumentar o spawn dos músculos vivos antes de ligar enforce. **Esforço:** médio.

### GAP-GOV-03 — rota ADML advisory-only, não aplicada ao spawn · **ABERTO** · construção real (deferir)
- **Fix:** aplicar a rota recomendada no spawn do músculo (único item não-reuse). **Esforço:** alto. Só depois de A/B provados vivos.

## D. Ergonomia CLI / situational awareness (frente #2 — ~55%; falta agregador)

### GAP-CLI-01 — sem cockpit único do motor vivo · **ABERTO**
- **Sintoma:** "o que o Atlas faz AGORA" = encadear brain:summary + task:health + autonomy:status na mão, 4 formatos.
- **Fix:** UM comando agregando os read-models (já existem) via `TerminalMarkdownRenderer`. **Esforço:** médio.

### GAP-CLI-02 — `atlas status`/dashboard cego pro motor autônomo · **ABERTO**
- **Evidência:** `cli:dashboard` puxa AiJob/traces/providers/chat; zero refs a brain/serving/landing.
- **Fix:** adicionar seção do motor vivo. **Esforço:** médio.

### GAP-CLI-03 — `TerminalMarkdownRenderer` subusado (2 de 874 comandos) · **ABERTO**
- **Fix:** adotar nos comandos-núcleo (output rico dormente). **Esforço:** baixo-médio · **Severity:** baixo.

## Z. Refutados / falso-alarme (não re-abrir)
- _(vazio — sweeps preencherão com o que pareceu bug e foi refutado na verificação)_

---
*Sweep 1 (broken-on-main) em andamento — apendará A/B com órfãos e comandos quebrados verificados.*
