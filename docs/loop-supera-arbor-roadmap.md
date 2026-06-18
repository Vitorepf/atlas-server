---
title: Loop supera Arbor — roadmap canônico (features complexas + medição de qualidade extrema, Arbor como motor)
status: superseded-wrong-framing
owner: operator (Vitor)
created: 2026-06-18
layer: 1
graph_parent: acde-compounding-delivery-engine
---

> 🛑 **DOC SUPERADO — FRAMING ERRADO (registro do erro, não seguir).** Este roadmap (criado por uma IA em 18/06) enquadra o Loop como "bater o Arbor em landing-rate" e medir com **proxies** (landing-rate, ciclomática) — exatamente o **erro de rumo** que o operador corrigiu em seguida. Esses números NÃO deixam o Atlas mais capaz. **Fonte de verdade: [`docs/loop-canonical-definition.md`](loop-canonical-definition.md) + memórias `loop-*`** (objetivo/pipeline/teto/guardrail). O Loop = evolução autônoma exponencial de features REAIS, nunca proxy/faxina/one-shot. Mantido apenas como registro histórico do erro.

# Loop supera Arbor — roadmap canônico

**Meta (operador, 18/06):** o Atlas Loop chega ao nível de **implementar features complexas medindo com
qualidade extrema, usando o Arbor como motor** — 100% implementado, conectado e funcionando ponta-a-ponta.

## North star
O loop **escolhe sozinho** uma feature complexa → **decompõe** → **implementa multi-arquivo** → e **PROVA**
a qualidade com medição held-out anti-gaming — usando o **Arbor (iterate-to-metric) como músculo**, sob a
governança/cert do loop.

## Teto honesto (model-bound — não dá pra fingir)
"Desenhar uma feature complexa correta do zero" depende da inteligência do modelo. A arquitetura **não
substitui** isso — ela torna PROVÁVEL, SEGURO e COMPOUNDING. O modelo nunca destrava sozinho; o loop mede,
gateia, compõe e aprende → multiplica (a equação antifrágil N×M).

## Definição de vitória sobre o Arbor
- **(A) mesmo problema, métrica na mão, babá, número bruto maior** → model-bound, sem vantagem estrutural. NÃO é a meta.
- **(B) entregar ≥ o delta do Arbor, mas AUTÔNOMO + SEGURO + sem game** → estritamente mais valor. **Esta é a meta.**

---

## A lista completa (Blocos 1–7) — estado real

Legenda: ✅ tem · 🟡 parcial · ❌ falta

### BLOCO 1 — Originação de feature complexa (o teto real)
- 1.1 Intent → spec medível (teste RED que falha hoje) — 🟡 `generateBestForTarget` RED-verified existe; frágil p/ feature
- 1.2 Decomposição de feature complexa → antichain de slices + DAG de dependência — ❌ (model-bound + falta orquestrador)
- 1.3 Originação NET-NEW (RED→GREEN diff-earned), não refactor — 🟡 `originateFeature` gated/estreito
- 1.4 Contratos de interface entre slices (AST-interface-contract) — 🟡
- 1.5 Spec compiler (intent → frozen verifier packet) — ✅ `compile-verifier`

### BLOCO 2 — Arbor como MOTOR de otimização (capturar sob governança)
- 2.1 Grind iterate-to-metric (edit→mede-delta→guarda-se-melhor→repete) — ❌ loop é one-shot — **maior alavanca**
- 2.2 Harness de métrica por objetivo (eval + split dev/test) — ❌ só existe no bench feito à mão
- 2.3 Portfolio de estratégias como move-set do otimizador — 🟡 scenarios surgical/clean/root-cause existem
- 2.4 Medição worktree-isolada + reset entre tentativas — ✅
- 2.5 Motor trocável (codex-5.5/Hermes/MiniMax) atrás do otimizador — ✅

### BLOCO 3 — Medição de qualidade EXTREMA (o moat, estendido)
- 3.1 Cert por DELTA em held-out (split congelado, não o de treino) — 🟡 frozen tests + revert_recheck; falta dimensão métrica
- 3.2 Scorecard multi-dimensional (correção+comportamento+perf+complexidade+cobertura+contrato+no-regressão) — 🟡 dossiê hoje 100% 0-criteria
- 3.3 Anti-gaming adversarial (refuters, revert-recheck, mutation, no-self-declared) — ✅ a força
- 3.4 Replay de consumidores cross-file (prova integração) — ✅ `cross-file-consumer-gate`
- 3.5 Machine-resolved, nunca auto-declarado — ✅
- 3.6 Mutation-score gate no comportamento novo — ✅ (mergeado 18/06)

### BLOCO 4 — Supply de alvo + Profundidade (parar re-tread/desistência)
- 4.1 Descoberta de alvo fresco de alto valor — 🟡 leverage scorer; supply fino
- 4.2 Injeção deliberada de alvo grande (apontar como apontei o Arbor) — ❌
- 4.3 Escada de escalada / depth budget (não abandonar em 5 scenarios) — 🟡 ~17% conversão

### BLOCO 5 — Integração multi-arquivo segura
- 5.1 Lane multi-arquivo — 🟡 Path B existe, não provado em escala
- 5.2 Merge de slices em ordem de DAG, cada um certificado — ❌
- 5.3 Obra grande governada (per-slice cert + net-diff obra cert) — 🟡
- 5.4 Never-break-main (blast-radius + boot-smoke + canary) — ✅

### BLOCO 6 — Autonomia (substituir a babá)
- 6.1 Métrica auto-suprida (o loop constrói o eval, não eu) — ❌
- 6.2 Gaming auto-pego (cert faz o que eu fiz na mão) — ✅
- 6.3 Self-merge seguro — ✅ provado (`5887a6124`)
- 6.4 Outcome ledger + compounding (aprende e melhora seleção/originação) — 🟡

### BLOCO 7 — Prova (medir que bateu o Arbor)
- 7.1 Arena head-to-head (loop-autônomo vs Arbor-babá, mesmo held-out) — ✅ bench existe (`/private/tmp/arbor-loop-bench`)
- 7.2 Scorecard honesto machine-resolved do delta — ✅ DQS

---

## Plano de entrega (3 fases)

### FASE 1 — vitória provável rápido (o motor sob governança)
**Escopo:** 2.1 + 2.2 + 3.1 → rodar no bench do Arbor **autônomo** → bater o Arbor em (B).
- 2.2 Metric harness genérico por objetivo (espelha eval.php: scalar + split dev/test + guarda frozen).
- 2.1 Grind iterate-to-metric (edit→mede→guarda-se-melhor→repete até convergir) reusando applyWithLadder/resetWorktree.
- 3.1 Cert por delta held-out (prova o ganho no split test, não no dev).
- 7.1/7.2 Arena: rodar o loop autônomo no bench, comparar delta vs Arbor (0.73→0.84), sem babá, sem game.
**DoD:** loop, sozinho, move o held-out ≥ delta do Arbor, certifica sem game, mergeia seguro. Número medido.

### FASE 2 — feature complexa
**Escopo:** 1.2 + 1.3 + 1.4 + 5.1 + 5.2 + 5.3 → originar e entregar uma feature multi-arquivo certificada slice-a-slice.

### FASE 3 — autonomia plena
**Escopo:** 4.2 + 4.3 + 6.1 + 6.4 → o loop supre a própria métrica e compõe.

---

## Princípios de execução (não violar)
- Construir SOBRE o que existe (ladder principle) — não reinventar grinder/cert/materializer.
- Arquitetura completa primeiro, **prova no final** com número medido no held-out.
- Anti-gaming é o moat: nada self-declared; held-out congelado; revert-recheck; machine-resolved.
- Never-break-main: flags default-OFF até provado; blast-radius gate; byte-identical-OFF.
- Honestidade: nunca vender potencial como fato; reportar o delta real ou onde travou.
