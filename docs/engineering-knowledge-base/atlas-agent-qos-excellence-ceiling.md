---
id: atlas-agent-qos-excellence-ceiling
type: engineering_knowledge
title: Atlas Agent QoS — Excellence Ceiling (operator out of eng loop)
status: active
category: programming
priority: 95
summary: "Lei de qualidade agentic máxima: M multiplica N com multi-loop spec→author→judge→verify→repair→land→settle; operador só soberania; tempo secundário; sem dial vanity quality_ceiling; owners existentes (Kernel Court/Floor/Repair). Residual MASTER R106 / EXECUTE P2g-QOS."
tags:
  - agent-qos
  - excellence
  - aaeos
  - verification-moat
  - elite-executors
---

# Atlas Agent QoS — Excellence Ceiling

> **Programa:** AAEOS Elite Deepening residual **R106** · fatia **`EXECUTE P2g-QOS`**  
> **Premissa:** operador **sempre fora do loop de engenharia**; só soberania (intent / plan_seal / standing_mandate / H1–H7).  
> **Tempo:** secundário. **Qualidade de software:** primária.  
> **Proibido:** órgão novo, score vanity, flag produtiva `quality_ceiling=max` gameável.

---

## 1. O que é Agent QoS de excelência

**Não é** latência, tokens/s, “time-to-first-commit” nem “o modelo respondeu”.

**É** a probabilidade de que um trabalho **promova com prova** — estrutura, correção, manutenibilidade, segurança e verificação no nível pedido — **sem babysitting técnico** e **sem mentir sucesso**.

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
| Residual | **R106** |
| Gate | **`EXECUTE P2g-QOS`** |
| Receipt | `PHASE-P2G-QOS.json` |
| DAG | após **P2c** (repair continuum), antes **P2d** |
| Predecessores culturais | **R104/P1-JSON** (não destruir N); **P2b CUTOVER** (authority) |
| Non-waivable | se o programa reivindica excellence ceiling / full elite DONE |

Implementação = **compor e enforçar** o spine existente no Kernel/Dev/Forge/Autônomos — não novo cérebro.

---

## 10. Acceptance tests mínimos (slice)

1. Server-resolved max raise-only  
2. Architecture path refuse mutate  
3. Implementation without FREEZE → no land  
4. Floor any-blocker sozinho impede promote  
5. Court facts missing → refuse  
6. Same-family + mechanical court rule  
7. Repair continuum mesmo root + lock  
8. Infinite loop stop (same signature / no-delta)  
9. Timeout ≠ promote  
10. review:deep non-gating  
11. Mode parity Dev/Forge/Autônomos  
12. Mutation semantic veto  
13. No new organ census  
14. R104 orthogonality  

---

## 11. Ordem de valor para o operador

```text
1. Fechar R104 (P1-JSON) — parar de perder do cru por encoding
2. P2b Decision/courts + P2c repair — estrutura de julgamento
3. P2g-QOS (R106) — tornar multi-loop excellence LEI do path
4. P3 verification surface — moat sem humano técnico
5. P4 REAL_OPERATION — provar jornada
6. Rivals — provar Atlas ≥ cru no que importa
```

---

## 12. Uma frase

**Agent QoS de excelência = multi-loop agentic com Court+Floor+Repair+Settle sob mandato soberano, tempo irrelevante, dial vanity proibido, M falsificável — Atlas como fábrica de julgamento e prova, não chat com fricção.**
