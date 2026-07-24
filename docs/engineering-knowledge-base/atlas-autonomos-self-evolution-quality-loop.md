---
id: atlas-autonomos-self-evolution-quality-loop
type: engineering_knowledge
title: Atlas Autônomos — Self-Evolution Quality Loop (night → forever)
status: active
category: programming
priority: 100
summary: "Visão pétrea do operador: Atlas deve melhorar a própria qualidade SEM pedido humano (night→loop→Autônomos/self-construction). Mede frontier; se 80–100% satura nível, sobe a escola e enfileira trabalho para o Atlas alcançar o novo patamar. Operador só sente o produto 10× melhor. ACDE morto; vivo = brain/task + Rivals + QoS + AAEOS."
tags:
  - autonomos
  - self-construction
  - self-evolution
  - curriculum-ladder
  - agent-qos
  - rivals
  - night-loop
  - petreo
capabilities:
  - autonomos_self_evolution_loop
  - unattended_quality_compounding
  - curriculum_auto_promotion_to_work
decisions:
  - "Desejo canônico: Atlas evolui qualidade sozinho; operador chega e sente diferença (ex. 10×) sem ter pedido."
  - "Linha histórica: night → loop (MVP morto) → Autônomos vivo (brain/seed/task) + self-construction — não reanimar ACDE."
  - "Self-evolution = medir no frontier → se saturou, promover currículo → originar tasks reais de melhoria → land com QoS → re-medir; nunca Goodhart de proxy."
  - "80–100% em capacidade = aprovar nível e subir a escola; inútil como meta final (anti-ceiling fallacy)."
  - "Operador fora do eng loop; soberania só (mandate/H1–H7). Autônomos zero task-causal operator actions no path qualificado."
  - "Multiplicador negativo (Atlas pior que cru) = stop-the-line; prioridade máxima de self-repair."
maintenance:
  - Manter alinhado com atlas-autonomos-live-system, curriculum-ladder, agent-qos-excellence-ceiling, thesis multiplier.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomos-live-system.md
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - docs/engineering-knowledge-base/atlas-agent-qos-excellence-ceiling.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/self-construction/quality-bar-and-metrics.md
  - docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomos-self-evolution-quality-loop
graph_title: Autônomos Self-Evolution Quality Loop
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-autonomos-live-system
graph_status: active
graph_source: repo
owner: programming
canonical_source: docs/engineering-knowledge-base/atlas-autonomos-self-evolution-quality-loop.md
allowed_changes:
  - Refinar owners e receipts do loop com evidência de runtime.
forbidden_changes:
  - Reviver ACDE/atlas:loop:* como sistema vivo.
  - Self-evolution por proxy (LOC, test-count, landing-rate) sem frontier measure.
  - Exigir operador técnico no loop de melhoria.
depends_on:
  - atlas-autonomos-live-system
  - atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy
  - atlas-agent-qos-excellence-ceiling
  - atlas-ai-thesis-multiplier-channel
governs:
  - unattended_atlas_self_improvement
  - curriculum_driven_ambition
requires_evidence: true
risk_level: high
---

# Atlas Autônomos — Self-Evolution Quality Loop

> **Desejo do operador (canônico):** o Atlas **melhora sozinho** — qualidade, fluidez, inteligência sentida — **sem pedido**.  
> Você usa o app e **sente** a diferença (ex. ordem de grandeza 10×), não “abre um ticket de melhoria”.  
> **História:** night (evolução enquanto dorme) → loop (MVP) → **Autônomos + self-construction** (vivo).  
> **Futuro:** auto-aprendizado fechado com **escola que sobe** e **músculo que implementa o próximo degrau**.

---

## 1. Sim — compreendemos

| O que você quer | O que **não** é |
|---|---|
| Atlas cuida de si 24/7 | Você como revisor técnico de cada patch |
| Acordar / abrir app e sentir 10× | Dashboard diz 9.2 e o produto igual |
| 80–100% num nível → **próximo livro** | “Chegamos ao teto, parar” |
| Autônomos + self-construction no máximo | Reanimar o loop/ACDE morto |
| Self-evolution de **qualidade real** | Faxina proxy (LOC, contagem de testes) |

**Até hoje não está acontecendo de forma confiável** porque faltam pedaços fechados em série: canal (R104), path QoS (R106), medição frontier (Rivals + R107), e o **elo** “saturou → originar tarefa de evolução → land → re-medir” rodando **sem você**.

---

## 2. Linha histórica (não confundir cadáver com vivo)

```text
night     → evolução noturna (intenção)
   ↓
loop/ACDE → MVP de evolução autônoma (MORTO — não é o alvo)
   ↓
Autônomos → cérebro atlas:brain:* + músculo atlas:task:* + commit escopado main
self-construction → runtime daemon, land, serving (vivo)
```

**Self-evolution de qualidade** usa o **Autônomos vivo**, não o cadáver `atlas:loop:*`.

---

## 3. O loop fechado do “futuro” (auto-aprendizado de qualidade)

```text
                    ┌─────────────────────────────────────┐
                    │  1. MEDIR no frontier (Rivals)        │
                    │     cru vs Atlas · mesmo μ · simetria │
                    └─────────────────┬───────────────────┘
                                      │
              ┌───────────────────────┼───────────────────────┐
              │ M < 1 (Atlas pior)    │ 80–100% saturou nível │  frontier aberto, M fraco
              ▼                       ▼                       ▼
        STOP-THE-LINE           PROMOVER ESCOLA          ORIGINAR melhoria
        consertar canal/path    L_k → sanity             no path QoS
        (R104/R106 first)       L_{k+1} → frontier       (spec/court/repair/…)
              │                       │                       │
              └───────────────────────┴───────────────────────┘
                                      │
                                      ▼
                    ┌─────────────────────────────────────┐
                    │  2. CÉREBRO (atlas:brain:next/seed)   │
                    │     propõe trabalho REAL de evolução │
                    │     (não proxy cleanup)               │
                    └─────────────────┬───────────────────┘
                                      │
                                      ▼
                    ┌─────────────────────────────────────┐
                    │  3. MÚSCULO (task → worker → land)    │
                    │     sob QoS (Court+Floor+Repair)     │
                    │     self-construction / daemon       │
                    └─────────────────┬───────────────────┘
                                      │
                                      ▼
                    ┌─────────────────────────────────────┐
                    │  4. EVIDÊNCIA (ledger / outcome)      │
                    │     re-medir frontier                │
                    └─────────────────┬───────────────────┘
                                      │
                                      └──► volta ao 1  (24/7, sem operador eng)
```

**Quando 80–100% numa capacidade:**

1. **Não** parar.  
2. Marcar nível como **S_sanity**.  
3. **Dificultar** o teste / abrir **S_frontier** novo.  
4. Autônomos **enfileira** trabalho para o Atlas **alcançar** o novo patamar.  
5. Repetir — **isso** é o auto-aprendizado de qualidade.

Isso é a mesma lei da [curriculum ladder](atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md) + [Agent QoS](atlas-agent-qos-excellence-ceiling.md), **acionada pelo Autônomos** em vez de por você.

---

## 4. O que o operador sente vs o que o sistema faz

| Você sente | Sistema faz (sem você pedir) |
|---|---|
| Mais fluido | Menos fricção de contrato/canal; paths estáveis |
| Mais inteligente | Melhor context + routing + M |
| Mais qualidade | Courts, repair, settle, menos fake-green |
| “10× melhor” | Composto de muitos lands no frontier, não um score |

**Não prometemos “10×” como número vanity no certify.**  
Prometemos a **máquina** que torna essa experiência **possível e contínua**.

---

## 5. Condições para o patamar absoluto (honesto)

| # | Condição | Estado típico hoje |
|---|---|---|
| 1 | Autônomos vivo 24/7 (brain→seed→task→land) com mandato | Parcial / ops |
| 2 | Canal não destrói N (R104) | Aberto no produto |
| 3 | QoS multi-loop path law (R106) | Plano |
| 4 | Rivals dual-arm + curriculum promote automático | Parcial / manual |
| 5 | Brain origina **melhoria de qualidade real** quando frontier satura ou M fraco | Ambition faculty existe em pedaços; elo fechado frágil |
| 6 | M&lt;1 = stop-the-line com task de reparo prioritária | Doutrina sim; wiring frágil |
| 7 | Zero operador task-causal no Autônomos qualificado | Em hardening AAEOS |
| 8 | Você só usa o app | Meta de produto |

**Patamar absoluto** = 1–7 verdes em série, **sem** você no eng loop.  
Enquanto 2–5 falham, o night “não acontece” de forma sentida.

---

## 6. Anti-Goodhart (senão o night vira faxina)

Self-evolution **proibida** se a origem do trabalho for só:

- subir contagem de testes / landings / LOC  
- “parecer ocupado”  
- otimizar scorecard interno  

Self-evolution **permitida** se:

- fecha residual de frontier measure (M, R104, court gap)  
- sobe currículo porque nível saturou  
- REAL_OPERATION / excellence pass sobe no **S_frontier**  
- multiplica N (tese) em vez de degradar  

---

## 7. Uma frase

**Night eterno: medir o frontier, se o Atlas (ou o cru) “passou de ano” subir a escola, o Autônomos inventa e landa a melhoria, e você só sente o produto 10× melhor — sem pedir.**
