---
id: atlas-agent-qos-excellence-ceiling
type: engineering_knowledge
title: Atlas Agent QoS — Excellence Ceiling (operator out of eng loop)
status: active
category: programming
priority: 95
summary: "Agent QoS com alvo falsificável M_excellence ≥ 50× (mesmo modelo cru vs Atlas, ex. Kimi). Multi-loop Court+Floor+Repair; operador fora do eng; sem dial vanity; Rivals-only claim. Residuals R106 path-law + R107 measured 50×."
tags:
  - agent-qos
  - excellence
  - multiplier-50x
  - aaeos
  - verification-moat
  - rivals
  - elite-executors
---

# Atlas Agent QoS — Excellence Ceiling + Multiplicador ≥ 50×

> **Programa:** AAEOS residual **R106** (path law) · **R107** (measured M ≥ 50×) · fatia **`EXECUTE P2g-QOS`** + prova Rivals  
> **Premissa:** operador **sempre fora do loop de engenharia**; só soberania.  
> **Tempo:** secundário. **Qualidade:** primária.  
> **Alvo de produto (operador):** mesmo modelo (ex. **Kimi K2.7 / K7-class**) **sem Atlas** vs **com Atlas** → Atlas entrega **≥ 50×** no instrumento de excelência abaixo.  
> **Proibido:** órgão novo; dial vanity; claim “50×” sem suite preregistrada e braços simétricos.

---

## 0. Multiplicador ≥ 50× — lei do instrumento (pétreo)

### 0.1 O que “50×” **não** é

| Proibido como “50×” | Por quê |
|---|---|
| 50× mais tokens / mais loops / mais tempo | Custo, não qualidade |
| Score interno 50× ou GOD_SOTA | Vanity (P0) |
| “Parece 50× melhor” | Não falsificável |
| Atlas sozinho sem braço cru | Sem N de controle |
| Braços assimétricos (cru com FC, Atlas com JSON³) | Falsa derrota/vitória (P1 JSON³) |

### 0.2 Definição formal — `M_excellence`

Para um **mesmo modelo** `μ` (ex. Kimi K2.7-FC / K7-class) e uma **suite preregistrada** `S` de tarefas de engenharia:

```text
N_raw(μ, S)   = taxa de EXCELLENCE_PASS do braço CRU   (harness mínimo, sem AAEOS/Kernel courts)
N_atlas(μ, S) = taxa de EXCELLENCE_PASS do braço ATLAS (path QoS depth=max, R104 packaging, courts)

M_excellence(μ, S) = N_atlas(μ, S) / max(N_raw(μ, S), ε)
```

- **ε** = piso estatístico (default `0.02` = 2% se raw ≈ 0 em amostra pequena; documentado no preregistro).  
- **Alvo operador:** `M_excellence ≥ 50` em suite preregistrada com n suficiente.  
- **Claim público “≥50×”** só via **Rivals** (comparative SOTA) + PHASE residual **R107** GREEN — nunca certify inject.

### 0.3 O que é `EXCELLENCE_PASS` (conjunctive — anti-proxy)

Uma unidade passa **só se todos** forem verdade (fail-closed):

| # | Predicado | Cru | Atlas |
|---|---|---|---|
| E1 | Solução **aplica** no workspace (não só texto) | apply harness | hermetic apply |
| E2 | **Critérios de aceitação** da unidade passam (testes/oracles da suite) | sim | sim |
| E3 | **Anti-fake-green** (não smoke fixo; suite real da unidade) | harness | FalseClaim + Court |
| E4 | **Sem authority laundering** / sem write fora do allowed set | harness scope | write-set observer |
| E5 | (Atlas-only, não pune cru) Court+Floor promote quando path mutativo | n/a | obrigatório |
| E6 | (Atlas-only) R104: não classificar encoding fail como model_failure | n/a | obrigatório |

**Cru não precisa de Court** — senão o Atlas “vence” por burocracia.  
**Atlas precisa de Court** — senão não há M de qualidade, só N.

Para **C_ARCH** (só desenho): EXCELLENCE_PASS = critérios de arquitetura da suite (multi-candidato / trade-off / adversarial) — cru emite 1 desenho; Atlas path max exige §4.4.3 (≥3 + adversarial). Suite deve **pontuar** isso de forma comparável (ver §0.5).

### 0.4 Por que 50× é ambicioso e ainda assim o alvo

```text
Se N_raw ≈ 2% de EXCELLENCE_PASS em suite dura (obra-prima),
então M=50 ⇒ N_atlas ≥ 100% na mesma suite.

Se N_raw = 30% (modelo forte em tasks fáceis),
M=50 seria impossível por cima de 100% — por isso a suite S
DEVE ser calibrada no regime em que o cru COLAPSA
(tarefas onde excelência conjuntiva é rara sem multi-loop).
```

**Lei de calibragem da suite `S_50`:**

1. Preregistro **antes** de rodar (Rivals preregistration).  
2. Mix: ≥40% unidades onde raw historical EXCELLENCE_PASS ≤ 5% (hard).  
3. ≥30% unidades medium; ≤30% easy (sanity que Atlas não regride).  
4. Mesmo `μ`, mesma seeds/prompts de tarefa, **só o path** muda (cru vs Atlas).  
5. Simetria de canal: se cru usa FC nativo, Atlas **também** (R104) — senão medição é inválida.  
6. n mínimo: **≥ 50 unidades** ou power analysis no preregistro; reportar CI.  
7. Se `N_atlas < N_raw` → `M_excellence < 1` e claim 50× é **proibido** (estado atual em bfcl JSON³ era esse).

### 0.5 Como o QoS **gera** multiplicação (stack de M)

Cada camada multiplica **taxa de excelência**, não tokens:

```text
M_total ≳ M_channel × M_spec × M_multi × M_judge × M_verify × M_repair × M_settle
```

| Fator | O que faz | Se ausente |
|---|---|---|
| **M_channel (R104)** | Não destrói N resolvido; FC/package | M_total **< 1** (Atlas pior que cru) |
| **M_spec** | FREEZE discriminante; mata green-on-noop | lixo estruturado passa |
| **M_multi (C_ARCH)** | ≥3 candidatos + adversarial | “1 chute do Kimi” = cru |
| **M_judge** | author≠judge; Court mecânico | self-approve = cru |
| **M_verify** | testes reais + mutation em predicados | fake-green |
| **M_repair** | 0..N mesmo root até critérios | 1-shot fail = cru |
| **M_settle** | land só com write-set + canary | mentira de “shipped” |

**Meta de desenho:** depth=max + R104 GREEN ⇒ a máquina de M deve **visar** `M_excellence ≥ 50` no **S_frontier** atual.  
**Garantia de claim:** só medição no frontier — o QoS **estrutura** a escola; **não** imprime “50×” no certify.  
**Quando o cru “passa de ano”:** promover currículo; o 50× migra para o **próximo livro**, não morre.

### 0.6 Gates de claim (quando pode dizer “≥50×”)

| Estado | Pode claim 50×? |
|---|---|
| R104 aberto | **NÃO** |
| S_frontier não preregistrada | **NÃO** |
| Braços assimétricos | **NÃO** |
| S_frontier saturada pelo cru (domínio estável) sem promoção de nível | **NÃO** — teste **inapropriado** para 50×; evoluir escola |
| `M_excellence` ≥ 50 no S_frontier válido | **SIM** (R107 GREEN) |
| Só path law R106 GREEN | **NÃO** (lei sem prova) |
| PHPUnit only | **NÃO** (R84) |
| S_sanity falhando no Atlas | **NÃO** — regressão; consertar antes de brag |

### 0.7 Exemplo operador (Kimi) — escola, não teto

```text
Arm A: Kimi CRU
Arm B: Kimi + Atlas depth=max

S_sanity:   níveis já dominados (1–10) → ambos devem passar (~paridade / sem regressão)
S_frontier: nível atual (ex. equações de excelência eng) → medir M_excellence ≥ 50
S_horizon:  próximo livro (2030-class tasks) → preregistrado quando frontier saturar

Se Kimi cru faz 80–100% em S_frontier:
  → NÃO dizer “50× impossível”
  → dizer “aprovado neste nível; S_frontier vira S_sanity; abrir L_{k+1}”
```

---

## 1. O que é Agent QoS de excelência

**Não é** latência, tokens/s, “time-to-first-commit” nem “o modelo respondeu”.

**É** a máquina que faz `M_excellence ≥ 50` ser **possível e mensurável**: probabilidade de **EXCELLENCE_PASS** com prova — estrutura, correção, manutenibilidade, segurança e verificação — **sem babysitting técnico** e **sem mentir sucesso**.

| Dimensão (conjunta) | Conta | Não conta |
|---|---|---|
| Spec fidelity | FREEZE com critérios discriminantes; witness independente | “spec bonita”; freeze self-composed |
| author ≠ judge ≠ governor | SoD real + Court mecânico se mesma família de modelo | auto-aprovação LLM |
| Anti-fake-green | execução real; sem smoke fixo | lint-as-suite; exit 0 sozinho |
| Court + HonestyFloor | promote só se floor **e** court passarem | média de scores |
| Mutation / invalidadores | veto em predicados de autoridade/efeito/terminal | MSI cosmético |
| Repair honesty | 0..N sob **mesmo root/budget** | repair = nova journey |
| Settlement | LAND+canary+SETTLE com write-set observado | declare-and-trust |
| Provider honesty | R104: não destruir N resolvido | JSON³ como “modelo fraco” |
| M governado | COVERED/total spawns; refuse/repair rate | GOD_SOTA / 9.2 inject |

**Done = evidence/certification.** Narrativa de agente não é Done.

**bar(Dev) = bar(Forge) = bar(Autônomos).** Excellence ceiling **aumenta profundidade e loops**, nunca cria “modo premium” só para um executor.

---

## 2. Por que multi-loop é necessário (e quando não é)

### Necessário
“Melhor arquitetura de fato” e “obra-prima de código” **não cabem em um pass** do provider. Excelência exige:

candidatos → ataque adversarial → escolha com critérios → spec → impl → verify → recusa → repair → re-verify → land só com seal.

Isso é **LOOP_ENGINEERING** multi-estágio, não burocracia.

### Não é “quanto mais loop, melhor”
| Anti-padrão | Lei |
|---|---|
| Loop no canal quebrado (JSON³) | **Primeiro R104** — M não pode destruir N |
| Retry idêntico sem delta | **parar antes do provider** (R86) |
| Otimizar contagem de loops/reviews | **proxy proibido** (Goodhart) |
| Timeout → promote “best effort” | **proibido** — terminal preciso, nunca promote |

---

## 3. Policy de excellence (server-derived — não dial do operador)

**Não existe flag produtiva CLI `quality_ceiling=max`.**

A profundidade **max** é **resolvida no servidor** a partir de:

- mandato / plan_seal / intent (soberania)  
- risco / superfície mutativa / difficulty L4–L5  
- sealed caps no Decision / ExecutionOrder  

| Regra | Significado |
|---|---|
| Caller pode **apertar** (pedir mais rigor) | raise-only |
| Caller **não baixa** o teto server-resolved | fail-closed |
| Label “max” nunca entra em certify como número | anti-vanity |
| Default diário já é alta barra; max = **perfil de profundidade R4/R5** (`EngineeringRoleRoster`) + loops obrigatórios | reuse |

---

## 4. Pipeline obrigatório

### 4.1 Pedido de **arquitetura** (estrutura / placement)

```text
[0] Ingress soberano (intent | plan_seal | mandate)
[1] Context pack (AOBG / builders existentes)
[2] Spec adversary 0..N → SovereignSpecFloor FREEZE (+ independence)
[3] Architecture disposition — EngineeringQualityCourt (software_architecture + NFR se tocar)
[4] Decision v3 binding (sem provider mutativo sem authority)
[5] Verification read-only / characterization (sem MergeActuator)
[6] Evidence; learning só propose-only
```

**Fail-closed:** arquitetura **não landa código de produto**. Reclassificar para implementação = **nova Decision revision** (mesmo root ok se budget monotônico).

### 4.2 Pedido de **implementação**

```text
[0] Ingress + Decision v3 + root/budget
[1] Spec FREEZE (criteria_hash frozen)
[2] Author — provider governado (R104 packaging; R87 spawn covered)
[3] Hermetic apply / write-set canônico
[4] Judge — Court mecânico (+ LLM judge advisory se same-family)
[5] Acceptance — VerificationCourtAcceptanceGate = floor + court facts
[6] Repair 0..N (mesmo root) — RepairOrchestrator / Forge cycle / TaskServing
    · same_signature_twice / no-delta → stop
    · regression_lock + replay_proof se repair>0
[7] Governor admit → MergeActuator LAND (nonce once)
[8] Canary → SETTLE (ou revert path)
[9] EngineeringOutcome + journey terminal absorbing
[10] SURFACE_AUDIT opcional — atlas:review:deep / cockpit (NÃO vota eng)
```

### 4.3 O que “max” força além do default

| Eixo | Default | Excellence max |
|---|---|---|
| Spec | freeze mínimo | FREEZE discriminante; self-composed fail |
| Roles | depth por risk | ≥ R4; R5 se frontier/L5 |
| Judges | ≥2 famílias no floor | + Court mecânico se same-family |
| Mutation | waive se N/A | obrigatório em predicados tocados de autoridade/efeito/terminal |
| Repair | budget policy | finito, monotônico, no-delta stop |
| Land | Court+floor | + canary settle; zero human_action_required de rotina |
| Latency | n/a | timeout → HOLD/repair_exhausted — **nunca** promote |

---

## 4.4 TETO FINAL DE DESENHO (binding — não opcional)

> Versão anterior do mapa era **implementável e anti-Goodhart**, mas **incompleta como teto**: faltavam classes de pedido numeradas, multi-candidato de arquitetura e algoritmo fechado de profundidade.  
> **Esta seção fecha o teto de desenho.** Runtime ainda exige `EXECUTE P2g-QOS` + predecessores (R104, P2b, P2c).

### 4.4.1 Classes de pedido (taxonomia fechada)

O servidor classifica o pedido em **exatamente uma** classe primária (fail-unknown se ambíguo demais → `repair_required` / clarify **só** se for ambiguidade de soberania, nunca eng babysitting):

| ID | Classe | Exemplos | Path |
|---|---|---|---|
| `C_ARCH` | Arquitetura / estrutura / placement | “melhor arquitetura”, redesign de módulo, boundaries | **só** §4.1 — **zero** MergeActuator |
| `C_FEAT` | Feature / comportamento novo | user-facing capability | impl §4.2 |
| `C_FIX` | Bug / regressão | fail reprodutível | impl §4.2 (repair-first) |
| `C_HARD` | Hardening / security / privacy | authz, secrets, isolation | impl §4.2 + roles security |
| `C_REF` | Refactor sem mudança de comportamento | extract, rename estrutural | impl §4.2 + regression_lock obrigatório |
| `C_OPS` | Ops / reliability / perf | latency, capacity, incident | impl §4.2 + NFR probes |
| `C_DOCS` | Docs-only / policy text | knowledge-base | path docs; **não** promove eng land |
| `C_MEAS` | Medição / benchmark / workspace descartável | rivals unit, eval | authority **não-merge** (R105); packaging R104 |

### 4.4.2 Matriz tarefa → profundidade (server-resolved)

`depth ∈ {standard, elevated, max}` — **nunca** label de certify; só controla loops/roles/mutation.

**Algoritmo (ordem, raise-only):**

```text
depth := standard
if difficulty ≥ L4 OR mutative surface in {auth, merge, ledger, provider_gov, privacy}
    depth := max(depth, elevated)
if difficulty ≥ L5 OR class ∈ {C_ARCH, C_HARD} OR mandate.excellence_depth=max
    OR sealed Decision cap requests elevated/max
    depth := max(depth, max)
if class = C_MEAS
    depth := max(depth, elevated)   # prova de medição ainda exige honesty; não merge court
# caller/request may raise, never lower server depth
```

| Classe × depth | Spec | Candidatos / adversary | Roles (roster) | Mutation | Repair max (mesmo root) | Land |
|---|---|---|---|---|---|---|
| **C_ARCH × max** | FREEZE discriminante | **≥3** desenhos distintos; **≥1** pass adversarial que tenta **refutar** o vencedor; matriz trade-off (acoplamento, falha, escala, segurança, operabilidade) | ≥ R4 arch+appsec; R5 se L5 | N/A se zero código | N/A (sem land) | **proibido** |
| **C_ARCH × elevated** | FREEZE | **≥2** desenhos + 1 adversarial | ≥ R4 arch | N/A | N/A | **proibido** |
| **C_FEAT × max** | FREEZE + criteria_hash | author + **≥1** alternative approach se L5 | ≥ R4 | on se toca predicados críticos | finito alto (policy); no-delta stop | Court+Floor+canary |
| **C_FIX × max** | repro + acceptance | repair-first; replan se 2× same signature | ≥ R3 default; R4 se security | on se fix em authority path | bounded; lock+replay | Court+Floor |
| **C_HARD × max** | threat criteria | adversarial security disposition **obrigatória** | **R5** security roles | on | bounded | Court+Floor+canary; H1–H7 se reserved |
| **C_REF × max** | behavior freeze | blast-radius disposition | ≥ R4 | on em superfície mutada | lock+replay **sempre** se repair>0 | Court+Floor; behavior oracle |
| **C_OPS × max** | SLO/probe criteria | perf/reliability disposition | ≥ R4 | as applicable | bounded | Court+Floor + NFR probes |
| **C_DOCS** | n/a eng | n/a | n/a | n/a | n/a | sem eng promote |
| **C_MEAS** | harness contract | R104 packaging; **não** merge authority | measurement scope | n/a land main | n/a | artefato descartável + R105 |

**Fail-closed da matriz:** se a classe exigir ≥K candidatos e o path entregar 1 só “porque o modelo preferiu” → **não** FREEZE/promote; status `repair_required` / `architecture_under_sampled`.

### 4.4.3 Arquitetura “melhor de fato” — hard-done do teto

Para **C_ARCH × max**, DONE de arquitetura **só** se:

1. **≥3** candidatos com diferenças estruturais reais (não parafrase).  
2. Cada candidato com: boundaries, data/control flow, failure modes, operability, security posture (campos mínimos).  
3. **≥1** juiz/adversário **independente** (capability/principal ou model_family distinta **ou** Court mecânico) ataca o ranking.  
4. Vencedor escolhido por **matriz de critérios** selada no SpecFloor (não “gostei”).  
5. Critérios incluem **RED-on-noop** / discriminadores (R82 spirit).  
6. **Zero** mutação de workspace de produto.  
7. Recibo de arquitetura no ledger/journey (projection), não só texto de chat.  
8. Reclassificar para `C_FEAT`/`C_REF` exige **nova** Decision revision.

### 4.4.4 Implementação “obra-prima” — hard-done do teto

Para **impl × max** (C_FEAT/C_HARD/C_REF/C_OPS no depth max):

1. R104: N resolvido **não** morre em JSON³.  
2. Spec FREEZE + `criteria_hash` no AcceptanceBundle.  
3. author ≠ judge ≠ governor; same-family → Court mecânico obrigatório.  
4. VerificationCourt facts presentes (gate fail-closed se ausentes).  
5. HonestyFloor promote; qualquer invariante sozinho bloqueia.  
6. Mutation/invalidadores em predicados tocados de autoridade/efeito/terminal.  
7. Repair 0..N mesmo root; no-delta stop; lock+replay se repair>0.  
8. LAND nonce once + canary SETTLE (ou terminal preciso).  
9. Journey terminal absorbing; sem splice (R75).  
10. review:deep/cockpit **não** mintam pass.  
11. Timeout/budget → `repair_exhausted` / `held` / `blocked` — **nunca** promote.  
12. Mesma lei Dev/Forge/Autônomos (witness set pode diferir; invariantes não).

### 4.4.5 Kill-tests do instrumento (M e QoS)

Se estes não puderem **falhar** o instrumento, o teto é mentira:

| # | Kill-test | Esperado |
|---|---|---|
| K1 | Spawn provider ungoverned | coverage M **cai** |
| K2 | Modelo resolve + JSON³ tax | `provider_response_encoding`, não model_failure; **sem** claim excellence |
| K3 | C_ARCH max com 1 candidato | **não** FREEZE |
| K4 | Same-family author+judge sem Court | **não** land |
| K5 | Timeout sob max | **não** promote |
| K6 | review spam / dial label sem outcome | certify **não** sobe |
| K7 | no-delta retry | provider **não** re-chamado |
| K8 | mutante semântico em authority predicate sobrevive | hard veto |
| K9 | C_ARCH tenta MergeActuator | fail-closed zero LAND |
| K10 | human accept/reject tenta mint eng pass | ignorado / rejeitado |

### 4.4.6 O que o teto de desenho **recusa** ser

- Dial `quality_ceiling=max` no CLI  
- Score 0–100 de “obra-prima”  
- “Mais loops” como proxy  
- Excelência claim com R104 aberto  
- Operador como revisor técnico default  
- Quarto executor / ExcellenceLoop organ  
- Comparative SOTA sem Rivals  

### 4.4.7 Status do teto

| Camada | Estado |
|---|---|
| **Desenho / lei path (R106 + §4.4)** | **FECHADO** |
| **Instrumento M≥50× (§0)** | **FECHADO como lei de medição** |
| **Runtime path QoS** | ABERTO até PHASE-P2G-QOS (+ R104, P2b, P2c) GREEN |
| **Claim medido M≥50× (R107)** | ABERTO — suite S_50 + Rivals arms simétricos + report |

### 4.4.8 Residual R107 — measured excellence multiplier ≥ 50×

| ID | Gap | Owners | Hard done |
|---|---|---|---|
| **R107** | Não existe claim falsificável “Atlas multiplica modelo ≥50×”; risco de vanity ou de suite **inapropriada** (nível escolar saturado tratado como teto do modelo) | Rivals + **currículo em escada** S_sanity / S_frontier / S_horizon; dual arm; EXCELLENCE_PASS; R104; R106; **sem** score organ | (1) S_sanity + S_frontier preregistrados; (2) mesmo μ; (3) R104; (4) M≥50 **só** em S_frontier **não saturado** pelo cru; (5) se raw domina frontier → promover nível (escola), não “impossível”; (6) M&lt;50 no frontier válido proíbe claim; (7) certify sem Rivals receipt proibido; (8) Atlas falhar S_sanity = regressão bloqueante |

**R106** = máquina. **R107** = prova do 50× **no frontier escolar atual**. Ambos + **escola que sobe** com o tempo (2026→2050).

### 4.4.9 Ordem operacional para chegar a M≥50 (e manter)

```text
1. R104 GREEN              — M_channel ≥ 1
2. R106 / P2g              — multi-loop = lei
3. S_sanity verde nos dois — básico dominado; Atlas não regride
4. S_frontier preregistrado — nível onde 50× ainda é apropriado
5. Rivals dual-arm         — Kimi cru vs Kimi+Atlas
6. R107                    — claim ≥50× se medido no frontier
7. Se raw satura frontier  — promover L_{k+1} (escola); repetir 4–6
8. Nunca argumentar “raw 80% = fim da multiplicação”
```

---

## 5. Owners existentes (zero órgãos novos)

```text
Spec:     SovereignSpecFloor, SpecSourceIndependence, witnesses
Execute:  EliteExecutorKernel, mode gate adapters, AgentExecutionProviderPortAdapter
Verify:   VerificationCourt*, FalseClaimInvariant, MutationTestingAdapter / QualityFoundryMutationCoverageRunner
Accept:   SovereignHonestyFloor, VerificationCourtAcceptanceGate
Adjudicate: EngineeringQualityCourt, EngineeringFinalCertifier, EngineeringRoleRoster
Admit/Land: AtlasMergeGovernor*, AtlasTaskMergeActuator, CanarySettlementRequest
Repair:   RepairOrchestrator (Dev), Forge cycle repair, AtlasRepairOrchestrator (Kernel)
Evidence: AtlasEvidenceLedger, EngineeringOutcome
Audit:    AtlasReviewDeepCommand, EngineeringReviewService (non-gating)
M-lever:  ProviderGovernanceConsult / CoverageLedger
```

**Proibido criar:** `AgentQosService`, `ExcellenceLoop`, segundo ledger, segundo AcceptanceGate, dial CLI de QoS.

**Não spine:** Quarantine excellence checklists, AgentValidationGate theater, QualityFoundry readiness-only como claim de qualidade de land.

---

## 6. Fail-closed — não pode landar

1. Sem promote Court+Floor  
2. Fake-green / smoke fixo / claim sem OutcomeProof  
3. Author self-certify / self-composed authority  
4. Same-family only sem Court mecânico (em max)  
5. Spec tautológica (green-on-noop)  
6. Repair sem regression_lock quando repair_attempts>0  
7. Mutation abaixo do piso quando aplicável  
8. Authority stale/ausente (Decision)  
9. JSON³ / encoding fail rotulado como incapacidade  
10. `human_action_required` / plan re-approve de rotina como gate  
11. review:deep / cockpit como mint de verdade de eng  
12. Timeout/budget → promote  
13. REAL_OPERATION de PHPUnit  
14. git add -A / branch de obra  

---

## 7. Operador fora do loop (pétreo)

| Loop | Quem |
|---|---|
| ENGINEERING | Atlas only (author ≠ judge ≠ governor) |
| SOVEREIGNTY | Operador: intent, plan_seal, mandate, H1–H7 reais |
| AUDIT | Operador opcional; nunca vota eng |

**OneShot** = UX (um commissioning), **não** “um pass técnico”.  
Internamente: **0..N** repairs/replans sob o mesmo root.

---

## 8. M falsificável (sem vanity)

1. `enforced_governed_coverage = COVERED / total_spawns` (unknown ≠ 100%)  
2. refuse/repair rate com taxonomia encoding vs incapacity  
3. Rivals = única comparative SOTA  
4. canary/settle/revert outcomes  

**Kill tests do instrumento:** se M não pode cair sob spawn ungoverned / JSON³ tax / review spam, o instrumento é fake.

---

## 9. Encaixe no MASTER AAEOS

| Item | Valor |
|---|---|
| Path law | **R106** · `EXECUTE P2g-QOS` · `PHASE-P2G-QOS.json` |
| Multiplicador ≥50× | **R107** · suite S_50 · Rivals dual-arm · sem vanity certify |
| DAG path | após **P2c**, antes **P2d** |
| Predecessores | **R104** (canal); **P2b** (authority) |
| Non-waivable | R106 se excellence path claimed; **R107** se claim Atlas≥50× |

---

## 10. Acceptance tests mínimos (slice + 50×)

**Path (R106):** raise-only depth; C_ARCH refuse mutate; FREEZE; Court+Floor; repair lock; timeout≠promote; review non-gating; mode parity; no new organ; R104 ortho.

**Multiplier (R107):**
15. S_50 preregistered before run  
16. Same μ both arms (e.g. Kimi)  
17. Channel symmetry (R104)  
18. EXCELLENCE_PASS conjunctive applied equally where defined  
19. Report `M_excellence`; if &lt;50 claim blocked  
20. Certify/cockpit cannot display 50× without Rivals receipt  

---

## 11. Ordem de valor para o operador (rumo a 50× e além)

```text
1. R104 (P1-JSON)     — M_channel ≥ 1
2. P2b + P2c          — authority + repair
3. P2g-QOS / R106     — máquina multi-loop = lei
4. S_sanity           — provar paridade no que já é “1 a 10”
5. S_frontier         — medir 50× no livro atual (não no livro saturado)
6. Rivals dual-arm    — Kimi cru vs Kimi+Atlas
7. R107               — claim ≥50× só medido no frontier apropriado
8. Promover escola    — quando raw domina frontier (2026→2030→…)
9. P4                 — REAL_OPERATION além da bateria
```

---

## 12. Uma frase

**Agent QoS = escola de excelência: multi-loop multiplica o modelo ≥50× no frontier atual (Kimi cru vs Atlas); níveis saturados viram sanity; o currículo sobe com o século — nunca “80% = teto do aluno”.**
