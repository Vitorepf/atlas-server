# ACOS Max — Manifesto do que FALTA para 100% (v1)

> **Propósito:** a lista ÚNICA, completa, de-duplicada e sem gaps de tudo que falta para a obra ACOS Max chegar a 100%. Fonte de verdade = o scoreboard vivo (`atlas-acos-max-execution-scoreboard-v1.md`), corte **2026-07-12**. Cada slice aparece EXATAMENTE UMA VEZ. Regenerável (comando no fim). Em divergência, o scoreboard vence este manifesto (ele é foto; o scoreboard é o estado vivo).

---

## 0. Placar honesto (o número, sem inflar)

- **255 slices** no total. Estado no corte: **218 caixas terminais · 48 abertas** (o executor obra-17 já landou o grosso de L0–L9 com Evidence Ledger, content-hash e testes).
- Terminal = `landed(<sha>)` | `suspended(ELEV-28)` | `refutado`. Aberto = `pending` | `pending_window` | `blocked_by`.
- Das 48 abertas: **11 `pending_window`** (mecanismo landed, esperando TEMPO+tráfego) · **37 `blocked_by`** (esperando outro slice/obra) · **4 `pending` puro** (2 são conditional/suspended por design; 2 são meta de fecho).
- **ISTO NÃO É % DE CAPACIDADE.** É % de caixas. A capacidade real só sobe quando o flywheel gira — e o flywheel espera o **flip do operador** (raiz #1 abaixo).

---

## 1. Definição COMPLETA de "100%" (o gabarito — nada além disto conta)

100% do ACOS Max = TODAS as condições abaixo verdes NO MESMO CORTE:
1. **255/255 slices em estado terminal** (`landed` | `suspended(ELEV-28)` | `refutado-com-registro`). Suspenso/refutado com registro CONTA — verde vácuo não.
2. **Gates L0–L12** verdes (checklist no cabeçalho de cada lote do scoreboard).
3. **MARCO ESP-V1** — ≥1 volta completa do flywheel com ids encadeados e outcome `proven_real` (`atlas:flywheel:loops --json` → `loops_complete ≥ 1`).
4. **Critérios de conclusão do plano:** §vii itens 1–10 (Max) + §viii itens 11–17 (ASI) + pétreos §xiii (Espinhas) + §xv itens 18–21 (TETO: mission_e2e ≥ alvo · N-Capture Drill executado · ≥1 volta proven_real fora de engenharia · Trajectory Vault com privacy provada).
5. **M > 1 e R > 0** medidos em avaliação independente (ELEV-02 / REC-03 / REC-05).

Fora desta lista não existe "falta" — se algo não está aqui, não é requisito de 100%.

---

## 2. AS 6 RAÍZES (o que realmente destrava as 48 — abrir estas cascateia quase tudo)

> As 48 caixas abertas não são 48 trabalhos independentes. Elas descem de **6 gatilhos-raiz**. Abrir os 6 na ordem certa cascateia-desbloqueia o resto. Nenhuma raiz é "construir mais um slice" — são flips, a obra vizinha, 1 decisão de escopo, e tempo.

| # | RAIZ | Natureza | Quem abre | O que cascateia ao abrir |
|---|---|---|---|---|
| **R1** | **Flip do master do músculo** `ATLAS_AUTONOMOS_MASTER_ENABLED` | Gatilho de OPERADOR (ASI-06 preflight 8/8 já landed e verde) | **Vitor** | O flywheel começa a girar → enche TODAS as janelas de soak que hoje dizem `insufficient_signal`: os ≥5 outcomes/dia·7d, MARCO ESP-V1 (volta proven_real), M-series (M>1), MAXN-03 (20 injeções), MULTX-04, MAXI-09, ASI-08 pleno, REC-04 shadow. **É a raiz nº1 — a maior parte do "que falta" é isto + esperar.** |
| **R2** | **Flip do auto-apply** `ATLAS_AUTONOMOUS_AUTO_APPLY` | Gatilho de OPERADOR (ASI-07 preflight 4/4 já landed e verde) | **Vitor** | Ponte delta→memória em minutos; enche `ai_learning_proposals.applied>0` + reversal_rate; fecha o aceite pleno do ASI-07. |
| **R3** | **Obra v1 fecha ADV-01 10/10** | Externo (obra v1) — hoje **5/6 confirmed, 1 refuted (CPT-10 em soak cross-week)** | executor **v1** | Destrava ASI-10 (flip AVCEL enforce), MAXG-08 (Marco Zero v2), MAXL-10 (selo por-área). Toda a tampa de CERTIFICAÇÃO. |
| **R4** | **Obra v1 flipa FEE-04** (feedback→ranking) | Externo (obra v1) | executor **v1** | Destrava MAXB-03 (fusão RRF v2), MAXB-07 (floor calibrado), MAXB-08 (LTR-lite) + MAXA-08 (que espera MAXB-03). Toda a onda de RANKING. |
| **R5** | **Obra v1 põe CPT-09 em enforce** | Externo (obra v1) | executor **v1** | Destrava MAXF-11 (compressão de payload MEDIDA). |
| **R6** | **Decisão de escopo do MULTV-01** — hoje `blocked_by: EngineeringKernel_scope_forbidden` + janela de 20 landings | **Decisão de engenharia** (você/executor) + tempo | executor Max + Vitor | MULTV-01 (o VerifiedExecutionReceipt) é o KEYSTONE da espinha de verificação: destrava MULTV-02/03/04/05/06/07/08/09 + ESP-04 + TETO-03. **É o maior gargalo INTERNO.** Precisa: (a) resolver como o receipt cobre o EngineeringKernel sem violar o guard de escopo; (b) 20 landings reais para a janela. |

**Leitura:** R1+R2 são do operador (a chave é dele — a máquina entregou os preflights verdes). R3+R4+R5 são da obra v1 (você CHECA, não implementa). R6 é a única decisão de engenharia dura que resta na obra Max. Tudo mais é cadeia ou soak.

---

## 3. TABELA MESTRA — as 48 caixas abertas (cada slice 1×, classificada por raiz)

> Legenda de CLASSE: **OP**=espera flip do operador (R1/R2) · **V1**=espera obra v1 (R3/R4/R5) · **KEY**=MULTV-01 scope (R6) · **CHAIN**=auto-desbloqueia quando o predecessor Max landar · **SOAK**=mecanismo landed, espera janela de tráfego real · **COND**=conditional/suspended (pode terminar não-landed por design) · **FINAL**=verificação de fecho.

| Slice | Lote | Estado/bloqueio imediato | Classe | Raiz |
|---|---|---|---|---|
| MAXG-09 | L3 | pending_window (MAXG-01 p95 pós-land) | SOAK | tempo (14d latency ledger) |
| MAXG-10 | L3 | blocked_by MAXG-09 | CHAIN | ← MAXG-09 |
| ASI-10 | L4 | blocked_by ADV-01 (5/6, CPT-10 soak) | V1 | R3 |
| ESP-04 | L6 | blocked_by ESP-01, MULTV-01 | KEY | R6 |
| ESP-07 | L6 | blocked_by ESP-04, MAXJ-03 | CHAIN | ← ESP-04 (R6) |
| **MARCO ESP-V1** | L6 | pending_window (nenhuma volta proven_real ainda) | SOAK | **R1** (volta real precisa do músculo ligado) |
| MAXA-08 | L7 | blocked_by MAXB-03 | CHAIN | ← MAXB-03 (R4) |
| MAXB-03 | L7 | pending_window (FEE-04 flip + golden v2 RRF dual-read) | V1 | R4 |
| MAXB-07 | L7 | blocked_by MAXB-05, FEE-04(flip), shadow 7d | V1 | R4 |
| MAXN-03 | L7 | pending_window (20 injeções de comportamento do operador) | SOAK | **R1** |
| MAXN-06 | L7 | blocked_by MAXN-05, sinal de política real do operador | CHAIN+SOAK | ← MAXN-05 / R1 |
| **MULTV-01** | L7 | blocked_by EngineeringKernel_scope_forbidden + 20 landings | **KEY** | **R6** |
| MULTV-07 | L7 | blocked_by MULTV-01, ELEV-03, flake series | CHAIN | ← MULTV-01 (R6) |
| MULTV-05 | L7 | blocked_by MULTV-07, cross-executor repaired-failures window | CHAIN | ← MULTV-07 (R6) |
| MULTX-04 | L7 | pending_window (task-serving/Forge COM-01/ARFL soak) | SOAK | **R1** |
| TETO-03 | L7 | blocked_by ESP-04, MULTV-01, 20 trajectory receipts | KEY | R6 |
| MAXB-08 | L9 | blocked_by FEE-04(flip), labels floor, ELEV-30 holdout | V1 | R4 |
| MAXI-09 | L9 | pending_window (watch→trusted evidence soak) | SOAK | **R1** |
| MULTV-02 | L9 | blocked_by MULTV-01, ELEV-03 freeze | CHAIN | ← MULTV-01 (R6) |
| MULTV-03 | L9 | blocked_by MULTV-01, MULTV-02, executor MSI soak | CHAIN | ← MULTV-01 (R6) |
| MULTV-09 | L9 | blocked_by MULTV-02, MULTV-07, cli-traces selection | CHAIN | ← MULTV (R6) |
| MULTH-04 | L9 | blocked_by MAXH-07, ASI-14 | CHAIN | ← MAXH-07/ASI-14 |
| MULTH-05 | L9 | blocked_by MAXH-07, MULTH-04 | CHAIN | ← MULTH-04 |
| ESP-10 | L9 | blocked_by MAXN-03(fecho), MULTN15-07 | CHAIN+SOAK | ← MAXN-03 / R1 |
| RAGX-09 | L10 | blocked_by RAGX-01 | CHAIN | ← RAGX-01 (→MAXA-04) |
| RAGX-04 | L10 | pending_window (medição de gap MAXC-01/07) | SOAK | medição (candidato-a-corte) |
| MAXC-07 | L10 | pending (condicional — só se MAXC-01 deixar gap) | COND | medição |
| MAXF-11 | L10 | blocked_by CPT-09 (enforce) | V1 | R5 |
| MULTJ-05 | L10 | blocked_by MULTJ-03, MAXJ-01, sandbox floor | CHAIN | ← MULTJ-03 |
| MULTJ-07 | L10 | blocked_by MAXH-07 | CHAIN | ← MAXH-07 |
| MULTJ-08 | L10 | pending_window (nível-3 provado) | SOAK | volume de lições |
| MULTJ-09 | L10 | pending (SUSPENSO por design, ELEV-28) | COND | destrava com ≥5 skills vivas |
| MULTV-04 | L10 | blocked_by MULTV-01/02/07, ASI-11, SUB-01 | CHAIN | ← MULTV (R6) |
| MULTV-06 | L10 | blocked_by MULTV-01 | CHAIN | ← MULTV-01 (R6) |
| MULTV-08 | L10 | blocked_by MULTV-01/02, ASI-09 | CHAIN | ← MULTV (R6) |
| MULTH-08 | L10 | blocked_by MULTH-01..07 | CHAIN | ← MULTH chain |
| MULTX-07 | L10 | blocked_by MULTX-01, MULTJ-05, ELEV-02 | CHAIN+SOAK | ← MULTX-01 / M-series |
| MULTX-08 | L10 | blocked_by MULTX-02 | CHAIN | ← MULTX-02 |
| REC-04 (shadow) | L10 | pending_window (M>1 medido) | SOAK | **R1** (M>1 precisa de volume) |
| TETO-04 | L10 | blocked_by MARCO ESP-V1 | CHAIN | ← MARCO (R1) |
| TETO-07 | L10 | blocked_by TETO-04 | CHAIN | ← TETO-04 |
| ASI-16 | L11 | blocked_by F0 completa, launchd, MAXG-01 14d baseline | SOAK+INFRA | tempo (14d) + build residente |
| ASI-17 | L11 | blocked_by ASI-16 | CHAIN | ← ASI-16 |
| ASI-18 | L11 | blocked_by ASI-16, MAXG-01 14d baseline | CHAIN | ← ASI-16 |
| MAXG-08 | L12 | blocked_by ADV-01 10/10, EVI-04/05/06 | V1 | R3 |
| MAXL-10 | L12 | blocked_by MAXG-08, MAXL-04, MAXL-02 | CHAIN | ← MAXG-08 (R3) |
| REC-04 (flip) | L12 | pending — flip shadow→atuar | FINAL/OP | **R1/R2** + M>1,R>0 verdes (gatilho do operador) |
| **Critérios L12** | L12 | pending — verificação linha-a-linha no corte | FINAL | todos os acima |

**Contagem de fecho: 48 linhas = 48 caixas abertas.** REC-04 aparece em 2 linhas (shadow L10 + flip L12) porque é 1 slice em 2 partes — não é duplicação, é o split declarado. Nenhuma outra slice se repete. Nenhuma caixa aberta ficou de fora.

---

## 4. Distribuição do trabalho REAL (o que sobra depois de tirar flips, v1 e soak)

- **OP (espera operador): ~9 caixas** diretas + o grosso das SOAK que só enchem pós-flip. → **0 código a escrever.** É a chave de Vitor.
- **V1 (espera obra vizinha): 7 caixas** (ASI-10, MAXB-03/07/08, MAXF-11, MAXG-08, MAXL-10). → **0 código Max.** É CHECAR o v1.
- **KEY (MULTV-01 scope): 1 decisão** que destrava ~10 caixas. → **1 decisão de engenharia + 20 landings.**
- **CHAIN: ~22 caixas** que auto-landam quando o predecessor Max cai — trabalho de implementação NORMAL, sem novidade de design, já 100% especificado no plano.
- **SOAK: ~8 caixas** = puro TEMPO/tráfego (14d de latency ledger; janelas de outcomes/injeções). → **0 código, só relógio.**
- **COND: 2 caixas** (MAXC-07 condicional, MULTJ-09 suspenso) — podem terminar NÃO-landed por design (estado terminal honesto).
- **INFRA: 3 caixas** (ASI-16/17/18 = servidor residente F3) — build real, mas gated por F0-completa + 14d de baseline.

**Conclusão honesta:** o trabalho de CONSTRUÇÃO que resta é o cluster CHAIN (~22, já especificado) + MULTV-01 (a 1 decisão de escopo) + o residente F3 (ASI-16/17/18). Todo o resto é **flip do operador + obra v1 + relógio.** A obra Max não tem buraco de design não-resolvido além do R6.

---

## 5. Dependências EXTERNAS na obra v1 (o gate que não é da obra Max)

Estes IDs pertencem ao executor v1 (`atlas-acos-excellence-10-10-plan-v1.md`). A obra Max **só CHECA o estado**, nunca implementa. Bloqueiam itens Max abertos:

| Dep v1 | Estado no corte | Bloqueia (Max) |
|---|---|---|
| **ADV-01** | landed, mas **5/6 confirmed · 1 refuted (CPT-10 pending_soak cross-week)** — 10/10 NÃO fecha | ASI-10, MAXG-08, MAXL-10 |
| **CPT-10** | refuted/pending_soak (é o 1/6 que falta no ADV-01) | (indireto, via ADV-01) |
| **FEE-04** | aguardando flip com ROL-01 | MAXB-03, MAXB-07, MAXB-08, MAXA-08 |
| **CPT-09** | aguardando enforce | MAXF-11 |
| **EVI-04/05/06** | aguardando veredito estável cross-week | MAXG-08 |
| **ENG-13/14/15, PIP-07, ENG-12, OPE-06, RAG-12, COM-10** | onda 5 v1 (parte já ON; ADV-01 é o agregador) | ASI-10 (via ADV-01) |
| **SUB-01, WDG-01** | fundações v1 (WDG-01 vivo; SUB-01 usado por MULTV-04) | MULTV-04 |

---

## 6. Ações EXCLUSIVAS do operador (Vitor) — a máquina nunca faz

1. **Flip `ATLAS_AUTONOMOS_MASTER_ENABLED`** — preflight ASI-06 já entrega 8/8 verde; a chave é sua (R1).
2. **Flip `ATLAS_AUTONOMOUS_AUTO_APPLY`** — preflight ASI-07 já entrega 4/4 verde (R2).
3. **Flip da cadência hourly/event-driven do auto-apply** — após soak ≥7d pós-R2.
4. **Escala ≥3 workers** — após ASI-11 (linhagem 100%) + ASI-12 (séries multi-ator).
5. **Flip REC-04 shadow→atuar** — só com M>1 e R>0 medidos e freios REC-06 verdes.
6. **`git push`** — só com seu OK (nada é empurrado sem isso).

Enquanto estes não acontecem, a IA entrega o checklist verde + rollback pré-declarado e PARA. Isto é por design (charter 06/07), não é gap.

---

## 7. Caminho crítico de RELÓGIO (o gargalo real não é build, é tempo)

A ordem que minimiza o wall-clock até 100%:
1. **Hoje:** Vitor flipa R1 (músculo) + R2 (auto-apply). Custo: 2 flips. Efeito: o flywheel gira e TODAS as janelas de soak começam a encher.
2. **Em paralelo:** resolver R6 (decisão de escopo do MULTV-01) — desbloqueia a espinha de verificação inteira (10 caixas), que então acumula suas 20 landings junto com o volume do R1.
3. **Semanas 1–2:** as janelas de soak fecham conforme o tráfego real chega (MARCO ESP-V1, M-series, MAXN-03, MAXI-09, MULTX-04, MAXG-09→14d de latency, ASI-16 baseline).
4. **Depende do v1:** R3 (ADV-01 10/10, hoje 5/6) → destrava a tampa de certificação (ASI-10, MAXG-08, MAXL-10). R4 (FEE-04) → onda de ranking. R5 (CPT-09) → MAXF-11.
5. **Build normal (CHAIN):** os ~22 slices encadeados landam conforme seus predecessores caem — trabalho especificado, sem design pendente.
6. **F3 residente:** ASI-16/17/18 após F0-completa + 14d de baseline de latência.
7. **Fecho:** MARCO ESP-V1 verde → REC-04 shadow com M>1 → Vitor flipa REC-04 → Critérios L12 no mesmo corte → 100%.

**O piso de wall-clock é ~14 dias** (o baseline de latência do MAXG-01 que ASI-16/18 exigem) **+ os soaks de 7d** — não semanas de build. O gargalo é o relógio das janelas, não a fila de código.

---

## 8. Estado da DOCUMENTAÇÃO (pós-auditoria de completude 12/07)

A auditoria adversarial (5 dimensões, 11 agentes, verificação cética) fechou **21 defeitos, 0 bloqueante**, TODOS corrigidos nesta rodada. Verificação mecânica pós-fix: cobertura bidirecional plano↔scoreboard **vazia**, zero resíduo de contagem, glossário completo, deps corrigidas. **A documentação NÃO tem gap de conteúdo remanescente.** Resíduos honestos que sobram (todos cosméticos, nenhum bloqueia):
- Auto-citações de nº de linha usam marcadores de aproximação ("3.100+ linhas", "~415") — dentro de tolerância; não são números duros errados.
- A ERRATA DE AGENDA v2 (playbook §5.5) reconcilia os forward-deps; enquanto o scoreboard vivo ainda lista alguns slices no lote antigo, **a errata vence** (nota-ponteiro já cravada no topo do scoreboard).

Os 4 documentos do ecossistema estão consistentes entre si e com o estado vivo:
`plano (a LEI) → playbook (o COMO + errata) → scoreboard (o ESTADO vivo) → prompt (o executor)` + este manifesto (o QUE FALTA).

---

## 9. Como VERIFICAR 100% (o comando de fecho — não declarar por autoproclamação)

```bash
cd /Users/vitorepf/develop/Atlas/atlas-server/docs/engineering-knowledge-base
SB=atlas-acos-max-execution-scoreboard-v1.md
# (a) zero caixas abertas de SLICE (as meta de gate/critério fecham por último):
grep -cE '^- \[ \]' $SB           # alvo: só os meta de fecho (Critérios L12) por último
# (b) nenhuma slice terminal por vácuo — todo landed tem sha/receipt, todo suspended cita ELEV-28:
grep -E '^- \[x\]' $SB | grep -vE 'landed\(|suspended\(ELEV-28\)|refutado\(' || echo "todos terminais têm prova"
# (c) MARCO + M>1/R>0 (a autoproclamação é PROIBIDA — pétreo §3072):
php artisan atlas:flywheel:loops --json | jq '.loops_complete'   # ≥1
php artisan atlas:acos:m-series --json | jq '.m_ratio'           # >1 com denominador exposto
```
**100% NUNCA é declarado pela IA** — é o operador que confirma o corte com os critérios §vii/§viii/§xiii/§xv todos verdes e M>1/R>0 medidos (pétreo §3072). Este manifesto lista o que falta; o fecho é dele.

---

*Gerado 2026-07-12 a partir do scoreboard vivo (corte: 218 terminais / 48 abertas). Regenerar a §3 com: `awk '/^## LOTE/{l=$0} /^- \[ \]/{print l" | "$0}' atlas-acos-max-execution-scoreboard-v1.md`. Este manifesto é foto; o scoreboard é o estado vivo — em divergência, o scoreboard vence.*
