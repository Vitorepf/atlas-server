---
id: atlas-aaeos-loop-failure-diagnosis-and-remediation
type: engineering_knowledge
title: AAEOS Stewardship Loop — Failure Diagnosis and Remediation Plan
doc_schema: atlas_canonical_module_doc.v1
status: active
implementation_state: diagnosis_and_plan_no_runtime
authority_class: runbook
category: agentic-engineering
priority: 97
summary: Evidence-based diagnosis of why the AAEOS autonomous stewardship loop ran 24/7 for ~4 days (Codex + MiniMax) without delivering any backlog slice, while a strong-model parallel fan-out delivered ~107 specced slices plus ~85 latent-bug fixes in hours. Roots the failure in five structural defects — scan-only execution, weak-yet-blocking gates, sequential governance tax, under-powered execution provider, and infrastructure yak-shave — and gives a prioritized remediation plan. Honest about where the comparison is unfair to the loop.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - stewardship-loop
  - diagnosis
  - remediation
capabilities:
  - loop_failure_root_cause_analysis
  - loop_execution_unblock_plan
  - loop_gate_recalibration_plan
  - parallel_execution_engine_proposal
decisions:
  - The loop's bottleneck is execution (it cannot run the productive path), not planning (its backlogs are good specs).
  - The loop's quality gates are mis-calibrated: simultaneously too weak (they ship subtly-buggy code green) and too blocking (they abort cycles on good work).
  - The fastest path to value for well-specified atomic batches is a parallel strong-model fan-out plus independent adversarial verification, used either as the loop's real executor or as a governed bypass.
maintenance:
  - Update when the loop's execution tier, gate calibration, or provider routing changes.
  - Re-run docs-health after edits; keep the evidence section tied to verifiable artifacts.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
  - docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md
  - docs/engineering-knowledge-base/atlas-autonomy-ladder-promotion-runbook.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L7PromotionExecutor.php
graph_id: atlas-aaeos-loop-failure-diagnosis-and-remediation
human_name: AAEOS Loop Failure Diagnosis and Remediation
canonical_name: AAEOS Loop Failure Diagnosis and Remediation
technical_name: AutonomousEvolutionSessionService
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-aaeos-loop-failure-diagnosis-and-remediation.md
graph_title: AAEOS Loop Failure Diagnosis and Remediation
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-software-company-stewardship-stack
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-loop-failure-diagnosis-and-remediation.md
allowed_changes:
  - Refine root causes, evidence, and remediation steps as the loop is fixed.
  - Record measured before/after of each remediation as it lands.
forbidden_changes:
  - Do NOT present this diagnosis as proof the loop is fixed; it is a plan.
  - Do NOT delete the honest-caveat section; the agent-vs-loop comparison is not apples-to-apples.
depends_on:
  - atlas-aaeos-l7-l10-governed-ladder-backlog
  - atlas-autonomy-ladder-promotion-runbook
flows_to:
  - atlas-software-company-stewardship-stack
unlocks:
  - loop_execution_unblock
  - honest_delivery_throughput
governs:
  - stewardship_loop.remediation
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-loop-failure-diagnosis-and-remediation.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
next_actions:
  - Unblock the productive execution path (raise the loop above scan-only) before adding any new governance.
  - Pilot the parallel fan-out + adversarial-verify executor on one well-specified backlog batch and measure delivery rate.
---

# AAEOS Stewardship Loop — Failure Diagnosis and Remediation Plan

> Vocabulário de cluster (Forge, Atlas Dev, AtlasForge, AAEOS) segue `atlas-canonical-glossary-and-naming.md`. Este doc é diagnóstico + plano, NÃO prova de runtime.

## Resumo

Em ~4 dias rodando 24/7, com Codex e MiniMax "consertando", o loop autônomo do AAEOS **não entregou nenhuma fatia** das listas loop-ready (os backlogs `atlas-aaeos-*-leap-backlog`). No mesmo período, um fan-out de modelo forte em paralelo entregou **~107 fatias especificadas + ~85 correções de bugs latentes** em horas. Este documento diagnostica *por quê*, com base em evidência observada diretamente sobre o código e os artefatos do loop, e propõe um plano de remediação priorizado.

**Tese:** o loop é um **planejador competente sem mãos**, rodando com **músculo barato**, **afogado na própria burocracia**, **confiando em gates que não pegam os próprios erros**. O gargalo é **execução e calibração**, não cognição. A maior parte dos 4 dias foi gasta **consertando o loop**, não **fazendo a lista**.

## Papel no Atlas

Servir de runbook canônico para quem for arrumar o loop: o que está quebrado, com que evidência, qual o impacto, e qual a ordem de conserto. Substitui a impressão ("o loop é uma bosta") por um diagnóstico acionável.

## Onde Se Encaixa

```text
atlas-software-company-stewardship-stack
  +-- AAEOS stewardship loop (AP-790 runner, AP-786 owner-flow, AP-805 governor)
      +-- ESTE doc: diagnóstico de falha + plano de remediação
```

## Contratos

- Este doc é diagnóstico + plano; NÃO prova runtime.
- Cada causa raiz cita evidência verificável (grep, teste, ou memória code-verified).
- A remediação é ordenada: destravar execução (D1) ANTES de adicionar qualquer governança.

## Fluxo

Quem for arrumar o loop lê D1→D5, aplica os consertos P0 (execução/provider) antes dos P1/P2 (gates/limpeza), e mede pelas Métricas de Saída antes de declarar conserto. Nenhum P1/P2 deve ser feito antes do P0-1 (destravar execução).

## Regras para IA

- Não adicionar governança nova antes de destravar execução (D1) — pioraria D3/D5.
- Não recalibrar gate para fail-open ao tentar reduzir bloqueio — re-introduz D2.
- Não tratar "kernels implementados" como "nível alcançado" (kernels ≠ runtime wired).
- Usar `atlas-canonical-glossary-and-naming.md` para termos de cluster (Forge, Atlas Dev, AtlasForge, AAEOS).

## Escopo de Implementacao

Este arquivo é runbook/diagnóstico. A implementação dos consertos vive no runtime do loop (AP-790 runner, AP-786 owner-flow, executor) e nos kernels já entregues, não neste doc.

## Dependencias

- AP-790 Reliable 24h Loop Runner; AP-786 owner-flow; AP-805 Ten-Cycle Readiness Governor.
- Backlogs loop-ready (`atlas-aaeos-*-leap-backlog`) como fonte das fatias.
- `atlas-aaeos-leap-kernels-numeric-safety-tail` para a cauda de bugs latentes.

## Exemplos

Um ciclo "bom" pós-conserto: o executor de fan-out pega 10 slices independentes, gera classe+teste por slice, um verificador adversarial refuta ou aprova cada um, a re-prova independente roda a suíte real, e só conta merge quando `main_before != main_after` — entregando 10 itens reais onde o loop atual de hoje entregaria 0.

## Diagnóstico raiz (5 defeitos estruturais)

| # | Defeito | Natureza | Impacto |
| --- | --- | --- | --- |
| D1 | **Execução scan-only** | arquitetura | O loop varre/planeja mas não executa o caminho produtivo; nada é entregue. |
| D2 | **Gates fracos E bloqueadores** | calibração | Deixa código com bug passar verde *e* aborta trabalho bom. |
| D3 | **Pedágio de governança sequencial** | arquitetura | Imposto enorme por ciclo, um de cada vez; qualquer gate que dispara aborta sem entregar. |
| D4 | **Provider de execução sub-dimensionado** | recurso | A tarefa mais difícil rodando no modelo mais fraco; o multiplicador N×M colapsa. |
| D5 | **Yak-shave de infraestrutura** | processo | Os ciclos vão para *manter a máquina*, não para *entregar a lista*. |

---

## Os problemas em detalhe

### D1 — Execução scan-only (o gargalo central)

**Evidência.** Memória code-verified (2026-05-31): o loop é **Tier-0 scan-only — não executa autonomamente**; o **S49** é o bloqueador raiz e o caminho Forge de produção é **fixture**. Verificação nesta sessão (2026-06-02): `L7PromotionExecutor` — a única classe que poderia mudar um nível de autonomia — **não é invocada em nenhum arquivo de produção** (0 referências fora do próprio arquivo e testes). O único runtime que toca a escada L7-L10 é um comando read-only de exibição (`atlas:autonomy:ladder` → `evaluatePromotion`).

**Impacto.** O loop completa a parte cognitiva (selecionar finding, decompor em slice, montar packet) e **trava exatamente na ação de entregar** (executar provider → escrever arquivos → validar → merge com `main_before != main_after`). Por isso roda dias sem mover a lista. A ação que para um agente interativo é trivial (escrever os dois arquivos e rodar o teste) é a que a arquitetura do loop não realiza de fato.

**Conserto (P0).** Tirar o loop do scan-only: substituir o caminho de execução fixture por um **executor real**. Ver D3/“padrão que funcionou”: o executor deve ser um **fan-out de agentes em paralelo** (um agente por slice → impl → verificação adversarial), não um único ciclo sequencial. Sem D1 resolvido, **nenhuma evidência de L7 pode existir** e a escada inteira fica em 0%.

### D2 — Gates fracos E bloqueadores ao mesmo tempo (inversão de calibração)

**Evidência (fraco demais).** Uma varredura adversarial sobre **107 fatias já "verdes"** achou **~85 bugs latentes** que os testes pareados (e portanto o judge/validation do loop) **não pegaram**. Exemplos que o próprio loop commitou verde:
- `ScaffoldDensityScorer` (S58): `scaffold_density` podia **passar de 1.0** (contava linhas em branco só no numerador) — viola o bound 0..1 declarado.
- `ForgeMigrationIndexCollisionClassifierService` (S206): quebrava o contrato `list<string>` (`array_keys()` coagindo chave numérica-string para int; `sort()` sem `SORT_STRING`).
- **Gates fail-OPEN de governança/soberania**: `L8LocalEnginePortfolioAdmissionGate` admitia dados sensitive/secret/cyber **off-device** quando `privacy_class` tinha espaço/caixa; `L7PromotionExecutor` concedia promoção apesar de invariante quebrada (contagem chegando como numeric-string/float); `L10RecursiveDepthLimitGate` deixava recursão 10¹⁹ passar.

**Evidência (bloqueador demais).** Ao mesmo tempo, os gates de pré-gasto (preflight firewall, admission, weak-finding, escopo amplo, diff destrutivo) **abortam ciclos** com frequência. O Bloco 1 (Loop Self-Protection) existe justamente para *bloquear antes de gastar provider* — necessário, mas hoje calibrado para travar muito.

**Impacto.** O pior dos dois mundos: **o ruim passa** (teste fraco carimbado) e **o bom não termina** (gate dispara). O loop "trabalha" sem convergir para entrega correta.

**Conserto (P1).**
- Adotar a **verificação adversarial como gate de merge real** (não o auto-judge atual): rodar a suíte de fato + checagem de schema-vs-doc + scan de tautologia/scaffold + um verificador adversarial fresco que tenta *refutar* o sucesso. Isso teria pego os 85 bugs.
- **Fail-CLOSED** nos gates de governança/soberania (os fail-open achados), mas **reduzir falso-bloqueio** nos finders bons (recalibrar admission/scope para não abortar trabalho legítimo).

### D3 — Pedágio de governança sequencial

**Evidência.** O diretório `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/` tem **centenas de classes** de serviço (preflight firewall, owner-flow, merge governors, quarantine, recorders, gate evaluators, etc.). Cada ciclo paga pedágio em série: preflight → owner-flow → judge → validation → merge-truth → evidence. Se **qualquer** etapa falha, o ciclo aborta **sem entregar**, e o loop processa **um ciclo por vez**.

**Impacto.** Latência de wall-clock por entrega = soma de todos os gates, e a probabilidade de abortar cresce com o número de gates. 107 fatias em série, com pedágio por ciclo e aborto frequente, não terminam em dias. As mesmas 107 em **lotes paralelos** (14-54 de cada vez), sem cerimônia, terminam em minutos.

**Conserto (P1).**
- **Paralelizar a execução** para batches de slices independentes (raízes sem dependência — que é exatamente o que os backlogs loop-ready são).
- **Cortar a razão governança/entrega.** Medir **taxa de entrega real** (não volume de commit). O `LoopUtilizationQualityGovernor` (S85) é literalmente sobre isso (96% useful-cycle) — mas é um kernel **não-wired**; precisa virar gate de runtime.

### D4 — Provider de execução sub-dimensionado

**Evidência.** Configuração do swarm (memória `hermes-agent-multiagent-setup`): só **Codex (gpt-5.5) e MiniMax-M3** funcionam para o loop, **sem tokens Claude pagos**, MiniMax nunca abaixo de M3. A tese antifrágil do Atlas diz "provider é só motor; o cérebro multiplica" — mas aqui o motor é fraco **e** o cérebro (a orquestração do loop) não multiplica.

**Impacto.** A tarefa mais difícil (autonomia governada de ponta a ponta) está rodando no músculo mais barato. 4 dias de modelo fraco se debatendo ≠ horas de fan-out de modelo forte. O N×M colapsa para N pequeno × M≈1.

**Conserto (P0/P1).** Rotear a **lane de execução crítica** para um provider capaz (ou aceitar throughput menor conscientemente). Não rodar a tarefa mais difícil no provider mais fraco. Quando provider forte estiver disponível, o fan-out paralelo o usa diretamente.

### D5 — Yak-shave de infraestrutura

**Evidência.** Os 4 dias foram gastos com Claude+Codex **consertando o loop** (a meta-máquina), não **fazendo a lista**. A complexidade do loop (D3) torna a própria manutenção um sumidouro de ciclos.

**Impacto.** Progresso aparente (commits no loop) sem progresso real (zero itens da lista). É o anti-padrão clássico: investir na máquina que deveria produzir, em vez de produzir.

**Conserto (P2).** Para batches bem-especificados (slices atômicos, arquivo-novo, lógica pura), **bypass do loop** via fan-out governado; reservar o loop para trabalho genuinamente sequencial/stateful. Congelar mudanças no loop que não sejam os consertos P0/P1 deste plano.

---

## O padrão que funcionou (referência para o executor do loop)

O que entregou 107 slices + 85 correções em horas, e deve virar o motor do loop:

1. **Scout + inventário honesto** — ler os docs canônicos, inventariar o que existe vs. falta (o loop já tinha feito ~metade de alguns blocos, com bugs).
2. **Fan-out paralelo** — um agente por slice; cada agente lê **a linha exata do doc** (fonte da verdade), implementa classe pura + teste pareado, e **auto-verifica verde**.
3. **Verificação adversarial por slice** — um auditor fresco e cético por slice exige cada assert enumerado, pureza, bounds, anti-scaffold; **repara até verde**.
4. **Re-prova independente no main-loop** — rodar a suíte de fato + schema-vs-doc + tautologia + pureza + `git status` (nada existente editado) + hand-audit de amostra. **Nunca confiar no "done" do agente/loop.**
5. **Hardening adversarial em rounds** — varrer o código já verde atrás de bugs latentes (bounds, NaN/INF, overflow, int-coercion, fail-open) até a taxa cair; **fail-closed** nos gates de governança.
6. **Honestidade de status** — "blocked" honesto > "green" falso; reportar exatamente o que foi e não foi provado.

## Ressalvas honestas (a comparação não é apples-to-apples)

Não conclua "agente > loop". O fan-out fez a **fatia tratável** com a ferramenta **sob medida**:
- Os slices eram **atômicos, totalmente especificados, arquivo-novo, lógica pura, zero dependência** — formato ideal para geração paralela one-shot.
- O fan-out **não** teve que: wirar no runtime, mergear em sistema vivo sem quebrá-lo, rodar **sozinho por dias**, nem respeitar orçamento de provider barato.
- O mandato real do loop — **autonomia governada de ponta a ponta, merge-truth num repo que evolui ao vivo, ao longo de dias** — é genuinamente **mais difícil**.

A conclusão correta: **dê ao loop o motor de execução (fan-out paralelo), a verificação adversarial, o provider capaz e a desburocratização que o fan-out usou.** O cérebro do loop (planejamento/decomposição) é bom; faltam mãos, calibração e músculo.

## Plano de remediação (priorizado)

- **P0-1 — Destravar execução (D1/S49):** substituir o caminho de execução fixture por um executor real; sair de Tier-0 scan-only. É pré-requisito de tudo.
- **P0-2 — Executor de fan-out paralelo (D3):** um agente por slice (impl → verificação adversarial) para batches independentes, no lugar do ciclo único sequencial.
- **P0-3 — Provider capaz na lane de execução (D4):** rotear execução crítica para um modelo forte; parar de rodar a tarefa difícil no provider fraco.
- **P1-1 — Gate de verificação adversarial real (D2):** suíte real + schema-vs-doc + scan tautologia/scaffold + verificador que tenta refutar; substitui o auto-judge que carimba teste fraco.
- **P1-2 — Recalibrar gates (D2):** fail-closed em governança/soberania; reduzir falso-bloqueio em finders bons.
- **P1-3 — Wirar o governor de utilização (D3):** `LoopUtilizationQualityGovernor` (96% useful-cycle) como gate de runtime; medir entrega real, não volume de commit.
- **P2-1 — Convenção determinística de segurança numérica (D2):** helpers de coerção `finite`+saturating, `is_string` antes de `(string)`, `SORT_STRING`/`strcmp` para listas, aplicados aos ~57/107 kernels com a superfície de risco (ver `atlas-aaeos-leap-kernels-numeric-safety-tail`).
- **P2-2 — Consertar acoplamentos frágeis (D2):** ex. `Ap786OwnerFlowExecutor` decidindo CREATE vs HARDEN por existência de arquivo (quebra quando um slice cria o arquivo que um teste assumia ausente).
- **P2-3 — Congelar yak-shave (D5):** para batches especificados, bypass do loop; só mexer no loop pelos P0/P1.

## Métricas de saída (como saber que foi arrumado)

- `useful_cycle_rate ≥ 0.96` com **entregas reais** (merge-truth `main_before != main_after`), não fixtures.
- **0 gates fail-open** de governança/soberania (re-rodar a varredura adversarial → limpo).
- Gate adversarial verde como pré-condição de merge.
- O loop entrega **N slices especificados/dia sem supervisão**, cada um com re-prova independente verde.
- Tempo de manutenção do loop < tempo de entrega da lista (inverter a razão de D5).

## Evidencias

- Este documento.
- `atlas-aaeos-leap-kernels-numeric-safety-tail` (a cauda de ~85 bugs latentes e os 57/107 kernels).
- `L7PromotionExecutor.php` nunca invocado em produção (verificável por grep).
- Memórias code-verified do loop (scan-only / S49 / Forge fixture).

## Riscos

- Tratar este plano como prova de conserto (é plano).
- Adicionar **mais** governança antes de destravar execução (D1) — pioraria D3/D5.
- Recalibrar gates para fail-open ao tentar reduzir bloqueio — re-introduziria os bugs de D2.

## Proximas Acoes

1. Destravar o caminho produtivo (P0-1) e medir o primeiro ciclo que entrega de verdade.
2. Pilotar o executor de fan-out (P0-2) em **um** batch loop-ready e comparar entrega vs. o loop atual.
3. Plugar o gate de verificação adversarial (P1-1) e re-rodar a varredura; meta: 0 fail-open.
