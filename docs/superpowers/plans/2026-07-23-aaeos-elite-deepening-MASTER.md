# AAEOS Elite Deepening — MASTER Implementation Plan (MT)

> **Tipo:** Plano-mestre de implementação (MT = Master / Markdown plan)
> **Data:** 2026-07-23
> **Programa:** Atlas Engineer OS / AAEOS — deepen Operate → muscle, fuse shallow blocks, measured scorecards
> **Branch:** local `main` only · commits escopados · nunca `git add -A`
> **Status do predecessor:** GOD/SOTA + OPERATE + HYGIENE = **DONE no escopo declarado** (archive físico concluído; certify estrutural ok; os composites ~9.5 ainda contêm constantes/hints e **não** certificam este Elite Deepening)
> **Este programa:** **RSS (Razor Sovereign Spine)** — Operate→muscle honesty + lei soberania/engenharia; reusa `ExecutionOrder`/`EliteExecutorKernel`/Courts/Ledger; **zero** Mission OS, WorkGraph product, Foundry no DONE P4
> **Estado desta rodada:** **PLAN-ONLY** · P0–P4 não executados · P0 exige autorização literal do operador

**Para workers agenticos:** REQUIRED SUB-SKILL: use `superpowers:executing-plans` fase-a-fase; só use `superpowers:subagent-driven-development` quando o operador autorizar paralelismo e os claims de arquivo forem disjuntos. Checkboxes `- [ ]`. Evidence em:

```text
docs/evidence/2026-07-23-aaeos-elite-deepening/
  LEDGER.md
  SCOREBOARD.md
  PHASE-*-RECEIPT.md
```

**Versão:** v5 **RSS** (Razor Sovereign Spine) · anti-duplicação · v4 = base forense · Partes I–III (§0–§68) + IV (§69–§76) · **P0–P4 only**

### Índice rápido

| Parte | Seções | Conteúdo |
|---|---|---|
| I | §0–§8 | Porquê, leis, baseline, residuals, arquitetura, DONE, fusões, fórmulas |
| I | §9–§16 | Fases **P0–P4** (autoridade de execução), scoreboard, file map, commits, riscos, playbook |
| I | §17–§20 | Traceabilidade, ready, contatos, changelog |
| II | §21–§28 | Inventário 27 PHP, schemas, CLI/flags, ModeExecutor, receipt, spine S1–S8, kernel ports, testes |
| II | §29–§36 | Tasks A–G, aceitação R/C, APs HTTP, CODEMAP, densidade, counters, DAG, RACI |
| II | §37–§44 | Ops, rollback, anti-padrões, glossário, evidence, commits, DONE binário, checklist |
| III | §45–§48 | Auditoria disco, R16–R37, proof taxonomy, ownership |
| III | §49–§52 | Scorecard measured, receipt, CLI parity, **contratos de fase P0–P4** |
| III | §53–§58 | Testes, dirty-main, evidence, archive, autocrítica, handoff |
| III | §59–§68 | CLI disk-truth, brain R33, dimensions, CODEMAP, DI, schema, P0 freeze |
| IV | §69–§76 | **RSS:** anti-mapa, lei, H1–H7, R38–R46, horizonte R47–R50, emendas, ordem |

---

## 0. Por que este plano existe

O control plane fino (`app/Services/Ai/Aaeos/{Control,Spine}`) está **STABLE/OPERATE**.
O músculo elite (`EliteExecutorKernel` + `atlas:brain:*`/`atlas:task:*` + RealExecution + Evidence) **já existe**.

O gap de elite mundial **não** é mais “inventar AAEOS”. É:

1. **Operate ainda emite packs de CLI** em vez de atravessar o chokepoint até o músculo (sob caps).
2. **Scorecards mentem com constantes** (9.2 hardcoded) em vez de medir receipts.
3. **AEOS observe** tem 4 hops shallow (evaluator → delegates → sections → parts).
4. **Adapters + Dispatchers** duplicam forma (~40 LOC × 3 + twin dispatchers).
5. **Ladders L0–L\*** usam as mesmas letras para eixos diferentes (dificuldade ≠ autonomia ≠ maturity ≠ trust).
6. **CLI `run` ≈ `cycle`** — carga cognitiva do operador.

Este MT transforma essa auditoria em operação **dividida · computável · automática · otimizada**.

---

## 1. Lei pétrea (não negociável)

| # | Lei | Fonte |
|---|---|---|
| L1 | Local `main` only; commits escopados | `.cursor/rules/local-main-only.mdc` |
| L2 | `bar(Dev)=bar(Forge)=bar(Autônomos)` — nunca fundir os 3 modos num runtime sem modos | `atlas-elite-executors-dev-forge-autonomos.md` |
| L3 | AAEOS **governa**; músculo = Brain/Task/SelfConstruction/RealExecution/EliteExecutorKernel | vocabulary + README Aaeos |
| L4 | Disk law: `app/Services/Ai/Aaeos/` = `Control/` + `Spine/` only | `atlas-aaeos-vocabulary.md` |
| L5 | Quarantine FROZEN em `archive/.../Quarantine` — **não reanimar** | Quarantine README |
| L6 | ACDE / `atlas:loop:*` morto; vivo = `atlas:brain:*` / `atlas:task:*` | Autônomos live |
| L7 | Keep-list `AtlasLoop*` — nunca deletar por prefixo | Autônomos live |
| L8 | Anti-Goodhart: fusão/LOC/faxina sem elevar capacidade dos executores = **PARE** | Manual Notes |
| L9 | Provider burn **opt-in** (`--execute-provider`); dry-run default honesto | OPERATE |
| L10 | Learning = `pending_review` — nunca auto-promote | PHASE-9 |
| L11 | Terminal-first: não construir casca própria nesta obra | `atlas-terminal-first-focus.md` |
| L12 | Policy pure (0 I/O) / Runtime sole mutator / Projector 0 write | GOD/SOTA plan |
| L13 | LOOP_ENGINEERING = agentes only nos três modos; proibido humano como revisor técnico de rotina | RSS §70 |
| L14 | LOOP_SOVEREIGNTY = humano only (H1–H7); SURFACE_AUDIT opcional nunca é voto de eng default | RSS §70–§71 |
| L15 | Review técnico agentic: `author ≠ judge ≠ governor`; learning never auto-promote | §70 + government live |
| L16 | Modos diferem por tempo/delegação/origem/canal soberano/**Autonomy Tax** — nunca quality bar | RSS §70 |
| L17 | n=1 default; `agent_count` nunca KPI; Foundry/ablações = programa **irmão** (R47–R50) | RSS §73 |
| L18 | Seam = `ExecutionOrder`→Kernel/`EngineeringOutcome` + brain/task; **zero** Mission OS, WorkGraph product, segundo ledger | RSS §69–§70 |
| L19 | Anti-duplicação: Part IV não reescreve §9/§47–§66; P0–P4 authority = §52 | RSS §69 |

---

## 2. Baseline computado (revalidar; não reinterpretar)

Medido novamente em 2026-07-23T20:33:50Z no `main` local. Este snapshot é prova do estado **pré-P0**, não resultado deste programa:

| Árvore | PHP | LOC | Papel |
|---|---:|---:|---|
| `app/Services/Ai/Aaeos/` | 27 | ~2 114 | Control + Spine + Dispatch (STABLE) |
| `app/Services/Ai/AgenticEngineeringOs/` | 86 | ~43 263 | Gates / Maturity / Scoring / Observe |
| `app/Services/Ai/EngineeringKernel/` | — | ~14 292 | EliteExecutorKernel (músculo unificado) |
| `archive/.../Quarantine/` | 306 | ~131 664 | FROZEN cemetery |

| Check | Valor |
|---|---|
| `atlas:aaeos:certify --json` | `ok=true` |
| Composite scorecard standalone | `9.52` — consts (`control_plane=9.2`, thesis/elite=9.5) + defaults (`operate/spine/antifragile=9.0`); `counters.cycles_total=0` |
| Composite certify | `9.56` — **injeta** hints `operate_path_wiring=9.2`, `spine_enforced=9.2`, `antifragile_loop=9.0` (diferente do standalone) |
| `aaeos_tree.pure` | `true` |
| `quarantine_production_imports` | `0` |
| Quarantine archive físico | `306 PHP / 131,664 LOC` em `archive/app/Services/Ai/Aaeos/Quarantine` — **DONE; guard-only** |
| OPERATE scoreboard anterior | `~8.7` — avaliação documental, não série runtime medida |

**Predecessor plans (DONE — ler, não reabrir como obra):**

- `docs/superpowers/plans/2026-07-23-aaeos-god-sota-complete.md`
- Evidence: `docs/evidence/2026-07-23-aaeos-god-sota/`, `…-aaeos-operate/`, `…-aaeos-hygiene/`

---

## 3. Inventário canônico de residuals (entrada deste programa)

### 3.1 Conceituais (treino do operador / IAs)

| ID | Engano | Correção operacional |
|---|---|---|
| C1 | Quarantine (~132k) = AAEOS | Cemitério; AAEOS live = Control/Spine |
| C2 | AAEOS = fábrica de código | AAEOS governa; músculo é Brain/Task/Kernel |
| C3 | `cycle` sozinho = Hermes/provider | Por design despacha/observa; provider opt-in |
| C4 | Scorecard alto = zero residual | Composite ≥9 com residuals explícitos |

### 3.2 Operate path — residuals abertos e fatos já fechados

| ID | Residual | Severidade | Fase MT |
|---|---|---|---|
| R1 | Spine N9/N11 só em intakes críticos | Alta | P1 / P2 |
| R2 | Spine daily paths partial (enqueue / senior-loop) | Média | P2 |
| R3 | Full rewrite Dev/Forge → RealExecution only | Alta / obra maior | P2–P3 (strangler) |
| R4 | `AutonomosLiveDispatcher` **invoca** `Artisan::call('atlas:brain:next', …)` quando `--live` (default `run_brain_next=true`) | **CLOSED_SOURCE** (wire existe) | P1 **não** “preservar bug de args”; P4 prova efeito real **após** R33 |
| R5 | Só há receipts dry no evidence pack; falta receipt real do efeito `brain_next` com payload de sucesso | Média | P4 obrigatório; overnight é extra ops |
| R33 | **Args de `brain:next` quebrados:** dispatcher passa só `--json` e, se scope, **`--scope` flag** — mas o comando exige argumento posicional `{scope}` e **não tem** opção `--scope` (prova: `The "--scope" option does not exist` / `missing: "scope"`). Live Autônomos **sempre falha** o call hoje | **CRÍTICA** | **P1 fix obrigatório**; red test em P0/P1; P4 bloqueado sem isto |
| R34 | Sucesso de `brain_next` medido só por `exit_code===0` — mas `atlas:brain:next` emite `disabled`/`dry` com **SUCCESS default**; falso positivo de `mutated` | Alta | P1: classificar por `result.payload.status` + exit; P0 honesty surface |
| R35 | `atlas:brain:seed` **não tem** `--max`; dispatcher tenta `--max` e faz retry bare — `max_seeds` é intent, não contrato real de batch size | Média | P1 documentar + honest effect; seed-gate real é do brain seed, não inventar flag |
| R36 | P4 live tem pré-condições ops: memory (OOM observado em comprehension 128MB), brain master switch, scope default `autonomous`, fleet Autônomos opcional | Ops | §60 + P4 preflight; `blocked_ops` ≠ DONE |
| R37 | `run` e `cycle` divergem em **exit codes** (`dispatch_failed` só no run), defaults de intent, e flags (`--mode/--execute-provider/--run-worker-once/--scope` só no run) | Alta | P0 shared application; matrix §51+§59 |
| R6 | DualCore record best-effort | Média | P1 (honest status) |
| R7 | Ledger `skipped_*` fail-open | Média | P1 (surface status) |
| R8 | World snapshot fail-open | Baixa/média | P1 (world_source metrics) |

### 3.3 Higiene / dívida estrutural

| ID | Residual | Fase MT |
|---|---|---|
| R9 | `AaeosHygieneLegacyAliases` Phase E deferred | P3 |
| R10 | Dois mapas mentais Aaeos vs AgenticEngineeringOs em docs antigos | P3 |
| R11 | Cobertura docs-as-PHP reduzida (intencional) | — aceitar |
| R12 | Archive físico Quarantine concluído (306 PHP / 131,664 LOC); risco restante é só regressão/import | **DONE/HOLD** — guard contínuo, zero trabalho de archive |

### 3.4 Superfícies

| ID | Residual | Fase MT |
|---|---|---|
| R13 | HTTP/desktop Mission Control ≠ day-to-day OPERATE | P4 (não priorizar casca) |
| R14 | `--execute-provider` opt-in — dry-run parece “não faz nada” | P0 UX honesty |
| R15 | Cockpit `aaeos` + review_inbox não fecha loop sozinho | P1 receipt → cockpit |

### 3.5 Scoreboard predecessor — por que seus números não certificam o MT

| Dimensão exposta hoje | Origem real | Classe de evidência | Tratamento v3 |
|---|---|---|---|
| Control plane `9.2` | constante/projector | assessment estático | `assessment_only`; medir por hard gates + ledger |
| Operate wiring `9.2` | hint injetado pelo Certify | hint estático | remover em P0; `unknown` até existir amostra |
| Spine N9/N11 `9.2` | hint injetado pelo Certify | hint estático | remover em P0; medir S1–S8 |
| Antifragile `9.0` | default/projector | assessment estático | remover da composição medida; medir eventos sem auto-promoção |
| Density live `10` | scan estrutural | boolean hold | manter como gate, não como compensador numérico |

O composite observado `9.52` e o certify `9.56` descrevem o predecessor estrutural/avaliativo; não são baseline medido nem alvo de aceitação. O projector também contém outras notas estáticas (`thesis_clarity`, `elite_same_bar`, `control_plane`, `antifragile_loop`). **P0 remove score fantasia de toda dimensão que se declara medida, não apenas as linhas 9.2.**

**Correção R4 (v3):** o wiring fonte de `--live → atlas:brain:next` existe — **não** reimplementar o muscle Brain.

**Correção R4+R33 (v4 adversarial):** “chama o comando” ≠ “chama com contrato válido”. Disco prova:
- `AutonomosLiveDispatcher::brainNextArgs` → `['--json'=>true]` e opcionalmente `['--scope'=>$scope]`
- `AtlasBrainNextCommand` signature → `{scope}` **posicional obrigatório**; opções = `--repo|--docs|--m|--max-prior|--actor|--scope-signals|--json` — **sem** `--scope`
- `Artisan::call` sem scope → exception capturada → `exit_code=1` → `brain_next_failed`
- `Artisan::call` com `--scope=autonomous` → `The "--scope" option does not exist`

Portanto: R4 = CLOSED_SOURCE; **R33 = OPEN crítico** (args). P1 deve **corrigir** o shape para `['scope' => $scope ?: 'autonomous', '--json' => true]` (e redigir output), não copiar o bug sob o slogan “byte-for-behavior”. P4 só fecha R5 **depois** de R33+R34.

---

## 4. Arquitetura-alvo (deep module)

**Normativo v5 = RSS §70.2.** Diagrama curto (sem WorkGraph/Mission/SovereigntyPort product):

```text
Operador (soberania H1–H7 + intenção)
    ├─ Dev / Forge / Autônomos surfaces ──┐
    └─ atlas:aaeos:run (Crown opcional) ──┼──► AAEOS Control (admit/mode/caps)
                                          ▼
              ExecutionOrder ──► EliteExecutorKernel   [Dev/Forge]
              brain→seed→task ──► SelfConstruction      [Autônomos]
                                          │
              Courts existentes + MergeGovernor + Evidence Ledger
                                          │
              effect/proof taxonomy (§47/§50) · scorecard · cockpit audit
```

**Owners existentes — reusar, não renomear para OS novo:** Kernel `execute`/`EngineeringOutcome`; VerificationCourt; MergeGovernor; `AtlasEvidenceLedger`; brain/task.  
**Proibido neste MT:** `EngineeringMission*`, WorkGraph package, SovereigntyPort package, Evaluation Foundry sob Aaeos, segundo ledger.

---

## 5. Bloco revolucionário (definição de DONE do programa)

### DONE quando TODOS forem verdadeiros

1. **Entradas simples e equivalentes:** Dev, Forge e Autônomos permanecem diretos; `atlas:aaeos:run` é Crown/roteador opcional; `cycle` é alias deprecated.
2. **Um chokepoint deep:** todas as entradas produzem `ExecutionOrder` e atravessam `EliteExecutorKernel::execute`; `AaeosCycleRuntime` não vira um quarto runtime nem monopoliza as superfícies diretas.
3. **Músculo atravessado sob caps e claim-level explícito:**
   - Autônomos `--live`: preserva `brain → seed → task`, registra `effect_level=mutated` somente com exit code zero **e** payload semântico de sucesso; seed/task continuam caps separados.
   - Dev `--live`: atravessa porta Kernel/Dev adapter; se só montar pack, registra `effect_level=prepared` e nunca “músculo executado”.
   - Forge `--live`: spine stamp + porta Forge/Kernel; se só montar intake, registra `effect_level=prepared`.
   - Cap ausente/negado: `blocked_cap` tipado, `failure_reason` preciso e zero promoção semântica por status genérico.
4. **Scorecard medido:** dimensões operate/spine/antifragile/control derivadas de receipts/ledger/scans com source, janela, numerador, denominador e sample; notas humanas ficam `assessment_only`; zero hardcode pontuável.
5. **Honesty surface:** dry-run / plan_only / skipped_ledger / dualcore_fail_open visíveis no CLI e cockpit.
6. **GateObserve decidido por evidência:** refactor com parity e ownership melhores, ou `not_applicable_evidence` provado; debt não vale DONE.
7. **Ladders com eixo explícito:** enum de eixo + value object de referência; owners das escalas permanecem separados.
8. **Gauntlet:** ≥1 receipt real Autônomos com `brain_next.exit_code=0` + dry suites verdes; `blocked_ops_sustained` é gap honesto, não substituto de DONE.
9. **Certify:** structural certify `ok=true` **e** elite-deepening certification `done=true`, com required dimensions measured e sample suficiente; composite é diagnóstico, nunca override.
10. **Docs:** vocabulary + elite executors + mother AAEOS + este MT + SCOREBOARD alinhados.
11. **Quarantine imports = 0** (guard contínuo).
12. **Nenhum crescimento** de foreign paths sob `Aaeos/` fora Control/Spine.
13. **Zero waiver semântico:** item bloqueante falso/unknown mantém `PARTIAL` ou `BLOCKED`; DEBT aceito não converte programa em DONE.
14. **RSS lei:** LOOP_ENGINEERING agentic nos três; LOOP_SOVEREIGNTY = H1–H7; SURFACE_AUDIT ≠ eng gate (§70–§71).
15. **Regimes de modo** no receipt/order correlation (§70.1); **sem** quality tier por modo; Autonomy Tax em Autônomos.
16. **R38–R46** fechados ou DEBT nomeado; **R47–R50 não bloqueiam** DONE P4 (§72–§74).
17. **R33/R34** green antes de gauntlet P4; spine R44 prova efeito, não class-name.
18. **Zero** WorkGraph OS / Mission Runtime / Foundry campaign como critério de DONE deste MT.

---

## 6. Divisão operacional (funcionários = executores)

| Executor | `duration` / `delegation` / `origin` | `sovereignty_channel` | Porta | Músculo | MT entrega |
|---|---|---|---|---|---|
| **Dev** | session / interactive / live_prompt | `live_intent` | `aaeos:run` mode=dev | Kernel Dev adapter | prepared→port P2; eng judgment agentic |
| **Forge** | obra / planned / obra_spec | `plan_seal` | `--mode=forge` | Forge+Kernel | intake prepared + stamp; eng agentic |
| **Autônomos** | continuous / queued / brain_seed | `none` (+ tax máxima) | `--autonomos --live` | brain→seed→task | R33 fix + live effect P4 |

**Automação 24/7 (fora deste MT, mas consumida):**
`atlas:agents:on autonomos` + launchd — heartbeat stale é **ops sustentada**, não feature AAEOS; registrar no LEDGER sem substituir a prova one-shot P4.

---

## 7. Mapa de fusões (computado)

| Rank | Fusar | Em | Leverage | Risco | Fase |
|---:|---|---|---|---|---|
| 1 | Operate → Kernel/Brain ports | `AaeosCycleRuntime` + ModeExecutors | Máximo | Alto | P0–P2 |
| 2 | Main scorecard + orphan Operate projector | `AaeosScorecardProjector` views; delete orphan; TriHygiene fica separado | Honestidade | Baixo–médio | P0 |
| 3 | ModeAdapter + LiveDispatcher ×3 | 3× `AaeosModeExecutor` | Superfície | Médio | P1 |
| 4 | `run` + `cycle` CLIs | 1 CLI + alias | UX | Baixo | P0 |
| 5 | GateObserve 4-hop | `AaeosObserveRegistry` | −shallow LOC | Médio | P3 |
| 6 | L0–L* duplicados | `AtlasEliteBarLadder` | Anti-confusão | Médio–alto | P2 |
| 7 | QualityBar static table | engine + métricas ledger | Maturity real | Médio | P3 |
| 8 | Namespace dual (seam-first) | aliases / CODEMAP | Mapa mental | Alto se big-bang | P3 |

### Não fundir / não deletar

- `EliteExecutorKernel` + honesty/proof floors
- `atlas:brain:*` / `atlas:task:*`
- `AtlasImplementationTruthService`
- `AaeosEngineeringSpine` / `AaeosSpineGate`
- `atlas:cli:cockpit`
- archive Quarantine + import guard
- Três modos Dev/Forge/Autônomos

---

## 8. Hipóteses históricas de fórmula — §49 é normativo

Este bloco preserva a intenção do v2, mas **não deve ser implementado literalmente**: pesos arbitrários e trends sem denominador repetiriam Goodhart. §49 substitui estas hipóteses por dimension results com provenance, hard gates e `unknown=null`.

O v2 propunha o rascunho abaixo; ele fica somente como rationale histórico e **não** é instrução de implementação:

```text
operate_path_wiring =
  0.40 * clamp(spine_stamp_coverage_critical_intakes, 0..1)
+ 0.30 * clamp(live_dispatch_success_rate_7d, 0..1)
+ 0.20 * (dualcore_recorded_rate_7d)
+ 0.10 * (1 if daily_port_is_run_only else 0.5)
→ scale to 0..10

spine_enforced =
  0.50 * critical_intakes_stamped
+ 0.30 * forge_live_stamped
+ 0.20 * (1 - parallel_ledger_violations_norm)
→ 0..10

antifragile_loop =
  0.40 * learning_candidates_emitted_on_halt_rate
+ 0.30 * (1 - auto_promote_violations)   # must be 1.0
+ 0.30 * reincident_failure_trend_improving_or_neutral
→ 0..10

control_plane =
  0.25 * certify_structural_ok
+ 0.25 * world_source_not_defaults_only_rate
+ 0.25 * evidence_status_recorded_rate_non_dry
+ 0.25 * admission_halt_sovereign_tests_green
→ 0..10

composite = mean(all dimensions)  # preserve quarantine/density/purity dims as scans
```

**Regra anti-mentira:** se amostra < mínimo declarado, dimensão = `unknown`, `value=null`, `measured_composite=null` e Elite Deepening não certifica. O certify estrutural pode passar em scope próprio; nunca converter unknown em 9.0/9.2.

Views:

| View | CLI | Inclui |
|---|---|---|
| `structural` | `aaeos:scorecard --view=structural` | invariants + purity + quarantine |
| `operate` | `--view=operate` | wiring + live + world + honesty |
| `hygiene` | `atlas:tri-hygiene:scorecard` separado | CLI/Gates/AE naming; não fundir no MT |

---

## 9. Fases de implementação (checkbox)

> **v5 RSS:** sequência executável = **P0–P4 abaixo + §52**. Part IV (§69–§76) emenda **lei/ownership**; **não** substitui esta seção por P5/P6/Foundry.

### P0 — Honesty + porta única + scorecard medido (fundação)

**Objetivo:** operador e certify param de mentir; uma porta diária.

#### P0.1 Evidence harness

- [ ] Criar/atualizar `docs/evidence/2026-07-23-aaeos-elite-deepening/{LEDGER,SCOREBOARD}.md`
- [ ] Colar baseline certify + scorecard JSON no LEDGER
- [ ] Linkar este MT no LEDGER

#### P0.2 CLI unificação

- [ ] `AtlasAaeosRunCommand` = porta canônica (flags já existentes)
- [ ] `AtlasAaeosCycleCommand` → thin deprecated alias → `run` (mesmo receipt schema)
- [ ] Router `atlas:aaeos` help aponta só para `run|scorecard|certify|cockpit`
- [ ] Atualizar `DAY-IN-THE-LIFE.md` + `atlas-cli-daily-map.md` + `Aaeos/README.md`
- [ ] Test: cycle alias preserva JSON schema `atlas.aaeos.cycle_receipt.v1`

#### P0.3 Honesty no receipt

- [ ] Receipt sempre expõe: `live_dispatch`, `plan_only`, `evidence_status`, `dualcore.recorded`, `provider_calls`, `caps`
- [ ] CLI human mode imprime aviso se `dry-run` ou `plan_only`: “não executou músculo”
- [ ] Se `--execute-provider` sem `--live`: fail fast com mensagem clara
- [ ] Testes unitários de honesty flags

#### P0.4 Measured scorecard (v1)

- [ ] Evoluir a façade única `AaeosScorecardProjector`; criar reader read-only em `Control/Measurement/`, sem um quarto projector concorrente
- [ ] Remover os hints `operate_path_wiring=9.2`, `spine_enforced=9.2`, `antifragile_loop=9.0` do Certify e remover todas as constantes numéricas que ainda se apresentam como `measured`
- [ ] Dimensões scan-based (purity/quarantine/orphan) permanecem
- [ ] Dimensões runtime: ler `AAEOS_CYCLE_RECORDED` do `AtlasEvidenceLedger`; nenhum `cycle_counters.json` paralelo
- [ ] Todo dimension result inclui `status`, `value`, `source`, `window`, `numerator`, `denominator`, `sample_size`, `failure_reason`
- [ ] Sample insuficiente = `value:null`, `status:unknown`, blocker explícito; não preencher com 9.0 conservador
- [ ] Remover `AaeosOperateScorecardProjector` no slice: busca em `app|tests|routes|config|bootstrap` confirmou zero consumidor; sua intenção vira `--view=operate` na façade principal
- [ ] `atlas:aaeos:scorecard --view=structural|operate`; default `structural`; TriHygiene permanece no comando próprio
- [ ] Compatibilidade: structural certify pode continuar `ok=true` com sample=0, mas deve declarar `certification_scope=structural` e `elite_deepening_done=false`
- [ ] Testes: nenhuma constante/hint pontuável; sample=0 nunca produz `measured_composite` nem Elite Deepening DONE

#### P0.5 Gates P0

```bash
php artisan atlas:aaeos:certify --json
php artisan atlas:aaeos:scorecard --json
php artisan test tests/Unit/Ai/Aaeos/Control --no-coverage
php artisan test tests/Feature/Ai/Aaeos --no-coverage
```

**Commit slice P0:** `feat(core): AAEOS-MT P0 honesty port+measured scorecard`

**Exit P0:** certify ok · scorecard sem hints mágicos · `cycle` alias · honesty flags verdes.

---

### P1 — ModeExecutors + Autônomos deep + cockpit delta

**Objetivo:** Adapter+Dispatcher → ModeExecutor; Autônomos é deep no chokepoint; cockpit lê residual.

#### P1.1 Introduzir interface `AaeosModeExecutor`

- [ ] Interface: `mode(): string` + `execute(cyclePlan, options): array` (policy meta + effects)
- [ ] Migrar Autonomos: fundir `AutonomosModeAdapter` + `AutonomosLiveDispatcher`, preservando a **intenção** `--live → atlas:brain:next` e **corrigindo R33** (args posicionais + default `autonomous`); proibido copiar `brainNextArgs` quebrado como “parity”
- [ ] Migrar Dev: fundir Dev adapter + dispatcher; pack recebe `effect_level=prepared`, nunca `mutated`
- [ ] Migrar Forge: fundir Forge adapter + dispatcher + spine stamp
- [ ] Gateway chama ModeExecutor apenas
- [ ] Remover exatamente `Control/Adapters/{AaeosExecutorModeAdapter,DevModeAdapter,ForgeModeAdapter,AutonomosModeAdapter}.php` e `Control/Dispatch/{AaeosModeLiveDispatcher,DevLiveDispatcher,ForgeLiveDispatcher,AutonomosLiveDispatcher}.php` depois do gateway switch e dos characterization tests verdes; não manter dual stack
- [ ] Testes operate dispatch existentes verdes

#### P1.2 Autônomos ModeExecutor (deepening)

- [ ] `--live` → chamar `brain:next` com args **corretos** (R33) + classificar efeito por payload status (R34) + effects tipados; refactor do path R4, **não** reimplementar Brain muscle
- [ ] `--max-seeds` → seed com gate-required flags no receipt
- [ ] `--run-worker-once` → `atlas:task next` uma vez
- [ ] Nunca reimplementar seed-gate / scoped commit — só exigir no receipt
- [ ] Falhas → `AaeosCycleOutcomeRecorder` learning `pending_review`
- [ ] Test: live path mocked Artisan

#### P1.3 DualCore + ledger honesty

- [ ] DualCore fail-open permanece, mas scorecard usa `recorded` rate
- [ ] Evidence `skipped_*` aparece no scorecard operate view
- [ ] World `world_source` metric (defaults-only penalty)

#### P1.4 Cockpit

- [ ] Seção `aaeos` mostra: last cycle status, evidence_status, dualcore.recorded, next_commands
- [ ] Review inbox permanece separado (R15 honesto)
- [ ] Markdown render atualizado

#### P1.5 Gates P1

```bash
php artisan test tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php --no-coverage
php artisan atlas:cli:cockpit
php artisan atlas:aaeos:certify --json
```

**Commit slice P1:** `refactor(core): AAEOS-MT P1 ModeExecutors+autonomos deep`

**Exit P1:** 3 ModeExecutors · gateway único · cockpit residual · learning on fail.

---

### P2 — Dev/Forge → Kernel ports (strangler) + ladder canônica

**Objetivo:** fechar buraco Operate→EliteExecutorKernel sem segundo músculo; ladder multi-eixo.

#### P2.1 Portas Kernel (caps)

- [ ] Mapear adapters existentes:
  - `EliteExecutorKernelDevAdapter`
  - `ForgeEliteKernelExecutionAdapter`
  - `EliteKernelWorkcellExecutorAdapter` / Autonomos gate adapters
- [ ] DevModeExecutor `--live`: chama porta Kernel **read-only / prepare** por default
- [ ] Mutative / provider só com `--execute-provider` (+ admission allow)
- [ ] ForgeModeExecutor `--live`: spine stamp + forge port; obra multi-packet **não** vira one-shot mágico
- [ ] Receipt inclui `kernel_port`, `execution_order_hash?`, `blocked_cap?`
- [ ] Testes Feature Kernel read-only já existentes — reusar, não duplicar suíte enorme

#### P2.2 Spine strangler (R1/R2)

- [ ] Inventariar call-sites Dev/Forge críticos (factory chatDev/forge, forge work intake, senior-loop)
- [ ] Stamp `AaeosSpineGate` onde faltar no critical path
- [ ] Task enqueue: primeiro decidir applicability com owner `SelfConstruction`; medir coverage no boundary existente e só adicionar stamp se não introduzir dependência AAEOS invertida
- [ ] Scorecard `spine_enforced` usa coverage real
- [ ] **Não** full rewrite RealExecution (R3) — strangler contínuo; debt no LEDGER se sobrar

#### P2.3 Eixos nomeados sem “god ladder”

- [ ] Criar `app/Services/Ai/EngineeringKernel/EliteExecutionAxis.php` enum: `difficulty|autonomy|department_maturity|trust`
- [ ] Criar `app/Services/Ai/EngineeringKernel/EliteLevelReference.php` value object com `axis`, `level`, `canonical_label`; serialização aditiva `atlas.elite.level_reference.v1`
- [ ] Manter os owners existentes das escalas: `AaeosDifficultyLevel` (difficulty), SelfConstruction/Autonomy (autonomy), AgenticEngineeringOs/Maturity (department), `EngineeringKernel/TrustLevel` (trust); não copiar valores para tabela central
- [ ] `AaeosDifficultyClassifier` adiciona `level_ref` qualificado e mantém `level`/`label` v1 para compatibilidade
- [ ] Docs: elite executors + vocabulary apontam eixos e proíbem “L3” sem eixo no código novo
- [ ] Tests: eixos não se convertem implicitamente; trust troca witness-set e nunca a barra

#### P2.4 Gates P2

```bash
php artisan test tests/Feature/Ai/EngineeringKernel/EliteExecutorKernelReadOnlyVerticalTest.php --no-coverage
php artisan test tests/Unit/Ai/Aaeos/Control --no-coverage
php artisan atlas:aaeos:certify --json
```

**Commit slice P2:** `feat(core): AAEOS-MT P2 kernel ports+spine coverage+ladder`

**Exit P2:** Dev/Forge live tocam Kernel ports sob caps · gap R1 reduzido medido · ladder eixos.

---

### P3 — AEOS observe compact + hygiene burn + quality bar real

**Objetivo:** matar shallow pass-through; Phase E aliases; maturity deixa de ser tabela estática onde possível.

#### P3.1 Observe compaction — evidence gate antes de registry

- [ ] Congelar baseline de métodos públicos e outputs do `AtlasUniversalGatesEvaluatorGoldenTest`
- [ ] Medir reachability, hop count por call e ownership dos `UniversalGatesObserveDelegates` + `GateObserveSection01–09`; registrar tabela no PHASE-3 receipt
- [ ] Só introduzir `AaeosObserveRegistry` se a medição provar redução de hops/contexto sem recriar godfile nem colidir com GOD-DEBULK; caso contrário, registrar `not_applicable_evidence` e não refatorar
- [ ] Se aprovado, registry mapeia IDs existentes para callables tipados, preserva todos os métodos públicos da façade e mantém bodies nas sections/parts donas
- [ ] README AEOS documenta a topologia efetivamente escolhida
- [ ] Aceite é paridade golden + ownership melhor; LOC menor sozinho vale zero

#### P3.2 Phase E — burn aliases

- [ ] Extrair os 40 pares de `AaeosHygieneLegacyAliases::CLASS_MAP` e varrer `app|bootstrap|config|routes|database|tests|docs` + Composer classmap, sem contar o próprio map
- [ ] Migrar refs restantes → canônicos
- [ ] Remover `AaeosHygieneLegacyAliases.php` + bootstrap register
- [ ] Teste que cada legacy FQCN deixa de resolver e cada canônico resolve; zero remoção apenas por `rg app=0`

#### P3.3 Quality bar engine (parcial)

- [ ] Identificar métricas reais disponíveis (landings, gate pass, certify)
- [ ] Substituir **subset** de `DEPARTMENT_DATA` static por feeds
- [ ] Onde feed faltar: `status=unknown`, `value=null`, `failure_reason=missing_feed`; dado sintético não entra em certificação
- [ ] Choreography continua observe-only

#### P3.4 Docs dual-map cleanup (R10)

- [ ] Atualizar docs antigos que ainda dizem Quarantine live / AAEOS=maturity
- [ ] CODEMAP AAEOS + AEOS split claríssimo
- [ ] Mother doc `atlas-agentic-engineering-os.md` aponta este MT como operate deepening

#### P3.5 Gates P3

```bash
php artisan test tests/Unit/Ai/AgenticEngineeringOs --no-coverage
php artisan atlas:aeos:observe runbook --json
php artisan atlas:aaeos:certify --json
```

**Commit slice P3:** `refactor(core): AAEOS-MT P3 observe registry+alias burn`

**Exit P3:** aliases burned · observe menos shallow · docs dual-map limpos · quality bar parcial honesta.

---

### P4 — Gauntlet live + freeze STABLE v2

**Objetivo:** prova no env do operador; freeze “use, don’t grow Control tree”.

#### P4.1 Gauntlet

- [ ] Dry receipts (já existem) regenerados
- [ ] Live Autônomos mínimo sem provider/worker: `atlas:aaeos:run "AAEOS P4 live brain-next proof" --autonomos --live --max-seeds=0 --json` no env operador
- [ ] Receipt obrigatório: `status=dispatched_live`, `effect_level=mutated`, effect `brain_next.command=atlas:brain:next`, `exit_code=0`, `payload.status` ∈ {served, already_done, success-equivalent documentado}, **não** `disabled|dry|error`, `args.scope` presente, `provider_calls=0`
- [ ] Colar receipt em `REAL-RUN-RECEIPTS/R-autonomos-live.json`
- [ ] Seed/worker são provas separadas e exigem autorização: `--max-seeds=1` e/ou `--run-worker-once`; não misturar com a prova mínima R4
- [ ] Overnight/heartbeat é operação sustentada pós-MT; se stale, registrar `blocked_ops_sustained`, sem apagar o resultado one-shot e sem chamar 24/7 de provado
- [ ] Dev e Forge: capturar ao menos um receipt de `prepared` e, quando a porta Kernel estiver habilitada, um de `mutated` ou `blocked_cap` honesto

#### P4.2 Freeze

- [ ] Atualizar `Aaeos/README.md`: STABLE / OPERATE **v2 — deep chokepoint**
- [ ] Proibir novos arquivos sob Aaeos fora Control/Spine/Dispatch
- [ ] SCOREBOARD satisfaz todos os hard gates §49; composite diagnóstico não substitui nenhum gate
- [ ] PHASE-COMPLETE.md

#### P4.3 Automação contínua (hooks)

- [ ] Adicionar step explícito `php artisan atlas:aaeos:certify --json` ao job PostgreSQL `test-coverage` de `.github/workflows/quality.yml`, depois de migrate e antes da suíte
- [ ] Scorecard measured no cockpit diário
- [ ] Import-guard Quarantine no certify (já existe — manter)

**Commit slice P4:** `docs(core): AAEOS-MT P4 gauntlet+freeze v2`

**Exit P4:** programa DONE somente se §5 + §43 + hard gates §49 forem todos verdadeiros; `blocked_ops_sustained`, `unknown` ou waiver mantêm o claim sustentado PARTIAL/BLOCKED.

---

## 10. Planning priority board — este programa ainda não tem score medido

O v2 atribuía notas de intenção a gaps sem denominador. O v3 remove essa média paralela: até P0, `measured_composite=null` e `elite_deepening_done=false`. Prioridade organiza trabalho; não simula telemetria.

| Dimensão | Estado verificável agora | Prioridade | Gate de saída |
|---|---|---|---|
| Port clarity | run/cycle divergem | P0 critical | alias parity §51 |
| Receipt honesty | effect/write truth incompleta | P0 critical | toda linha §50 verde |
| Score measurement | constants + zero samples | P0 critical | provenance; unknown abaixo de N |
| Operate→muscle | R4 source-only; Dev/Forge prepared | P1–P2 critical | receipts tipados por modo |
| Spine critical | S1–S8 incompletos | P2 high | 100% applicable ou N/A evidenciado |
| Antifragile | sem série runtime | P0/P4 high | learning safety + amostra suficiente |
| Observe compaction | benefício não medido | P3 conditional | decision receipt + golden parity |
| Alias hygiene | 40 pares ativos | P3 high | 40/40 canonical + legacy nonresolution |
| Live proof | dry receipts apenas | P4 critical | real brain-next success + readback |
| Quarantine | archive/import guard verde | continuous hold | zero import e zero diff archive |

Atualizar `docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md` a cada fase. DONE vem dos hard gates binários e da suficiência de amostra §49, nunca de média, target aspiracional ou score ≥9.

---

## 11. File map (tocar / não tocar)

### Primário (P0–P2)

```text
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/Dispatch/*
app/Services/Ai/Aaeos/Control/Adapters/*          # fundir → ModeExecutors
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosTriHygieneScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosRunApplication.php
app/Services/Ai/Aaeos/Control/AaeosRunRequest.php
app/Services/Ai/Aaeos/Control/Measurement/AaeosLedgerMeasurementReader.php
app/Services/Ai/Aaeos/Control/Measurement/AaeosMeasurementSnapshot.php
app/Services/Ai/Aaeos/Spine/*
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasAaeosCycleCommand.php
app/Console/Commands/AtlasAaeosCertifyCommand.php
app/Console/Commands/AtlasAaeosScorecardCommand.php
app/Console/Commands/AtlasCliCockpitCommand.php
tests/Unit/Ai/Aaeos/Control/**
tests/Feature/Ai/Aaeos/**
```

### Secundário (P2–P3)

```text
app/Services/Ai/EngineeringKernel/EliteExecutorKernel.php  # PORTAR, não reescrever
app/Services/Ai/Programming/*Elite*Adapter*.php
app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php
app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php
app/Services/Ai/AgenticEngineeringOs/UniversalGatesObserve*.php
app/Services/Ai/AgenticEngineeringOs/Gates/GateObserveSection*.php
app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php
docs/engineering-knowledge-base/atlas-aaeos-vocabulary.md
docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md
docs/engineering-knowledge-base/atlas-cli-daily-map.md
```

### Proibido

```text
archive/app/Services/Ai/Aaeos/Quarantine/**     # não reanimar
app/Services/Ai/AutonomousEvolution/* (ACDE morto raiz)  # não “consertar loop”
deletar AtlasLoop* por prefixo
atlas-desktop / mobile casca nesta obra
git checkout -b / merge / pull-merge
```

---

## 12. Automação & otimização (o que roda sozinho)

| Automação | Trigger | Comando / hook | Saída |
|---|---|---|---|
| Certify estrutural | PR/lane / fim de fase | `atlas:aaeos:certify --json` | ok + composite measured |
| Scorecard views | diário / cockpit | `atlas:aaeos:scorecard --view=structural\|operate` | dims |
| Import guard Quarantine | certify | scan projector | count=0 |
| Learning capture | halt/dispatch_fail | `AaeosCycleOutcomeRecorder` | pending_review |
| DualCore record | todo dispatch | gateway | recorded bool |
| Ledger cycle | non-dry runCycle | EvidenceLedger | evidence_status |
| Autônomos 24/7 | launchd + agents master | brain/task (ops) | landings — **fora** se heartbeat stale |
| Gauntlet dry | CI | receipts R1–R3 | JSON |
| Tri-hygiene | sob demanda | `atlas:tri-hygiene:scorecard` | 10/10 hold |

**Otimizações explícitas (não vanity):**

1. Menos hops observe → menos tokens/contexto IA por gate.
2. Uma porta CLI → menos erro operador.
3. Score medido → menos over-claim → menos rework.
4. ModeExecutor único por modo → locality (codebase-design deep module).
5. Caps provider → custo controlado.

---

## 13. Ordem de commits (disciplina)

```text
1. docs: evidence harness + SCOREBOARD baseline
2. feat/refactor P0: run/cycle alias + honesty + measured scorecard
3. test P0
4. refactor P1: ModeExecutors + autonomos deep + cockpit
5. test P1
6. feat P2: kernel ports + spine coverage + ladder
7. test P2
8. refactor P3: observe registry + alias burn + quality bar partial
9. test P3
10. docs P4: gauntlet receipts + freeze + PHASE-COMPLETE
```

Mensagens exatas por slice estão em §42; não improvisar mensagem genérica que esconda a fase.

---

## 14. Riscos & mitigações

| Risco | Mitigação |
|---|---|
| Provider burn acidental | caps; default plan_only; `--execute-provider` gated |
| Segundo músculo paralelo | ModeExecutors **só** delegam Brain/Kernel; proibido copiar seed-gate |
| Certify sem sample | runtime dims unknown/null; structural scope separado; Elite Deepening não certifica |
| Big-bang namespace rename | proibido; seam-first aliases |
| GateObserve golden break | registry atrás da mesma façade evaluator |
| Heartbeat Autônomos stale | `SUSTAINED` fica `blocked_ops_sustained`; one-shot P4 continua exigido |
| Escopo creep Quarantine | certify guard; README FROZEN |
| Fundir 3 modos | **proibido** por elite executors canon |

---

## 15. Possibilidades (o que este MT permite / não permite)

### Pode fazer (in-scope)

- Deepen Operate chokepoint
- Measured scorecards + honesty UX
- ModeExecutors
- Wire Kernel ports sob caps
- Spine coverage expansion (strangler)
- Ladder multi-eixo
- Observe registry compaction
- Alias burn Phase E
- Gauntlet + freeze v2
- Docs/CODEMAP alignment

### Não pode / não deve (out-of-scope)

- Reanimar Quarantine como runtime
- “Consertar” ACDE/`atlas:loop:*`
- Deletar `AtlasLoop*` por prefixo
- Fundir Dev+Forge+Autônomos sem modos
- Construir app Mac/Mobile como “AAEOS UI”
- Full rewrite Dev/Forge → só RealExecution numa tacada (R3 = obra contínua)
- Auto-promote learning
- `git stash` para esconder WIP; merge de obra

### Possibilidade máxima (horizonte pós-MT)

Se P0–P4 passarem, o próximo salto natural **não** é mais AAEOS tree — é:

1. Overnight Autônomos **medido** alimentando antifragile dims em prod.
2. Quality bar 100% telemetria.
3. Mission Control HTTP como **irmã** do mesmo receipt (não OS novo).
4. Nucleus organs N2–N12 strangler contínuo (GOD-DEBULK / ARCH), consumindo este chokepoint.

---

## 16. Playbook do implementador (sessão típica)

```bash
# 0) branch
git branch --show-current   # must be main

# 1) contexto P0
php artisan atlas:context-pack "AAEOS MT P0 honesty measurement and run-cycle alias" --json

# 2) baseline
php artisan atlas:aaeos:certify --json | tee /tmp/aaeos-certify.json
php artisan atlas:aaeos:scorecard --json | tee /tmp/aaeos-score.json

# 3) executar somente §52.1 depois de EXECUTE P0
# 4) rodar testes §53
# 5) atualizar LEDGER + SCOREBOARD + PHASE-0-RECEIPT
# 6) stage escopado dos paths realmente tocados, todos dentro de §52.1
git diff --name-only
git add -- app/Console/Commands/AtlasAaeosRunCommand.php app/Console/Commands/AtlasAaeosCycleCommand.php app/Console/Commands/AtlasAaeosRouterCommand.php app/Console/Commands/AtlasAaeosCertifyCommand.php app/Console/Commands/AtlasAaeosScorecardCommand.php app/Services/Ai/Aaeos/Control/AaeosRunRequest.php app/Services/Ai/Aaeos/Control/AaeosRunApplication.php app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php app/Services/Ai/Aaeos/Control/Measurement/AaeosLedgerMeasurementReader.php app/Services/Ai/Aaeos/Control/Measurement/AaeosMeasurementSnapshot.php tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php tests/Feature/Ai/Aaeos/AaeosLedgerMeasurementReaderTest.php tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-0-RECEIPT.md
git diff --cached --name-only
git commit -m "feat(core): AAEOS-MT P0 honest receipts measured evidence and run alias"

# 7) NÃO push sem OK do operador
```

**Exceção GOD-DEBULK:** se a sessão for GOD-DEBULK EXECUTE, este MT **não** obriga `--codex-start-packet`; autoridade = prompt EXECUTE + LAYOUT. Ainda assim: main only + commits escopados.

---

## 17. Traceabilidade com auditoria anterior

| Achado da verificação | Onde o MT resolve |
|---|---|
| Quarantine ≠ AAEOS | L5 + guard contínuo |
| Cycle ≠ provider sozinho | P0 honesty + caps |
| Scorecard ≠ zero residual | §3 + measured formulas §8 |
| R1–R3 spine/strangler | P2 |
| R4 `--live → atlas:brain:next` | source wiring fechado; P1 preserva paridade; R5/P4 exige receipt real |
| R5–R8 fail-open | P1 metrics + P4 live |
| R9 aliases | P3.2 |
| R10 dual map | P3.4 |
| R13–R15 surfaces | P1 cockpit + P4 (HTTP secondary) |
| EliteExecutorKernel paralelo | P2 ports |
| GateObserve shallow | P3.1 |
| Bloco revolucionário | §4–§5 |

---

## 18. Definition of READY TO START

- [x] GOD/SOTA certify ok
- [x] OPERATE port `atlas:aaeos:run` existe
- [x] Quarantine archived + imports 0
- [x] Elite executors canon ativo
- [x] Auditoria residuals inventariada
- [ ] Operador OK para executar P0 neste worktree
- [ ] Heartbeat/Autônomos ops checado se P4 live for meta imediata

---

## 19. Contato canônico (ler antes de cada fase)

1. Este arquivo (MT)
2. `docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md`
3. `docs/engineering-knowledge-base/atlas-aaeos-vocabulary.md`
4. `app/Services/Ai/Aaeos/README.md`
5. `docs/evidence/2026-07-23-aaeos-operate/DAY-IN-THE-LIFE.md`
6. `docs/engineering-knowledge-base/atlas-autonomos-live-system.md`
7. `docs/engineering-knowledge-base/atlas-agentic-engineering-os.md`
8. `docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md`
9. `docs/engineering-knowledge-base/atlas-sovereign-operating-system.md`

---

## 20. Changelog do plano

| Data | Nota |
|---|---|
| 2026-07-23 | MT v1 criado a partir da verificação rigorosa AAEOS (residuals R1–R15, fusões rank 1–8, EliteExecutorKernel gap, scorecard honesty) |
| 2026-07-23 | MT v2 — completeza absoluta: inventário PHP, schemas, CLI flags, testes, critical spine list, contratos ModeExecutor, receipts, rollback, DAG, RACI, ops heartbeat, APs HTTP, densidade, anti-padrões, passos atomizados P0–P4 |
| 2026-07-23 | Prompt Agenda iOS (copiar/melhorar MT): `docs/prompts/atlas-aaeos-mt-improve-AGENDA-COPY.md` — v2 ≠ teto; dois agentes continuam absolute no MASTER |
| 2026-07-23 | MT v3 absolute audit — §45–§58; R4 source-wired corrigido; archive DONE/HOLD; certify 9.2/9.2/9.0 e demais constantes classificados; ledger único; proof taxonomy; hard gates sem waiver-DONE; C1–C4/R1–R32 ligados a fase+prova+receipt; S6/S7 resolvidos em símbolos reais; média arbitrária de planning removida; P0 não executado |
| 2026-07-23 | **MT v4 adversarial absolute** — R33 brain:next args quebrados (posicional vs `--scope`); R34 false SUCCESS disabled/dry; R35 seed `--max` inexistente; R36 P4 preflight; R37 exit/flag matrix disk-truth; §42 “counter store” contradizia §34 (corrigido); §59–§68; baseline revalidado; v3 não é teto; P0 ainda não executado |
| 2026-07-23 | (superseded) rascunho mega Part IV com WorkGraph/P5–P6 — **rejeitado por duplicação** |
| 2026-07-23 | **MT v5 RSS** — Razor Sovereign Spine §69–§76; anti-mapa reusa §47–§66; H1–H7; R38–R46; R47–R50 horizonte irmão; P0–P4 only; P0 não executado |

---

# PARTE II — COMPLETEZA ABSOLUTA (nada omitido)

> Tudo abaixo é **vinculante** para o programa. Se um item não for feito nesta obra, deve constar como **DEBT** nomeado no LEDGER com owner + fase de follow-up — nunca silêncio.

---

## 21. Inventário completo live `Aaeos/` (27 PHP)

| Path | Papel | Fate no MT |
|---|---|---|
| `Control/AaeosAdmissionPolicy.php` | Fail-closed admission | KEEP (policy pure) |
| `Control/AaeosAdmissionVerdict.php` | Verdict constants | KEEP |
| `Control/AaeosCycleOutcomeRecorder.php` | Learning pending_review | KEEP; feed scorecard antifragile |
| `Control/AaeosCycleRuntime.php` | **Sole mutator** | DEEPEN (chokepoint) |
| `Control/AaeosDifficultyClassifier.php` | L0–L5 classify | KEEP; delegate ladder axis P2 |
| `Control/AaeosDifficultyLevel.php` | Ladder constants | WRAP → EliteBarLadder difficulty axis |
| `Control/AaeosExecutorMode.php` | dev\|forge\|autonomos | KEEP |
| `Control/AaeosIntentCompiler.php` | Free-text → objective | KEEP |
| `Control/AaeosModeSelector.php` | Mode select | KEEP |
| `Control/AaeosModeToDualCoreRoute.php` | Mode → DualCore route | KEEP |
| `Control/AaeosOperateScorecardProjector.php` | Operate view sem consumidor no disco | DELETE em P0 após red test de view principal |
| `Control/AaeosOrgStateProjector.php` | Org capability projection | KEEP |
| `Control/AaeosScorecardProjector.php` | GOD/SOTA scorecard | EVOLVE → measured |
| `Control/AaeosTriHygieneScorecardProjector.php` | TRI-HYGIENE | KEEP separado; fora da fusão P0 |
| `Control/AaeosWorldSnapshot.php` | World DTO | KEEP |
| `Control/AaeosWorldSnapshotBuilder.php` | World I/O fail-open | KEEP; expose metrics |
| `Control/Adapters/AaeosExecutorModeAdapter.php` | Adapter interface | REPLACE → ModeExecutor iface P1 |
| `Control/Adapters/DevModeAdapter.php` | Dev metadata | FUSE → DevModeExecutor |
| `Control/Adapters/ForgeModeAdapter.php` | Forge metadata | FUSE → ForgeModeExecutor |
| `Control/Adapters/AutonomosModeAdapter.php` | Autonomos metadata | FUSE → AutonomosModeExecutor |
| `Control/Dispatch/AaeosLiveDispatchGateway.php` | Live I/O chokepoint | KEEP; call ModeExecutors |
| `Control/Dispatch/AaeosModeLiveDispatcher.php` | Dispatcher iface | MERGE into ModeExecutor |
| `Control/Dispatch/DevLiveDispatcher.php` | Dev packs | FUSE |
| `Control/Dispatch/ForgeLiveDispatcher.php` | Forge intake stamp | FUSE |
| `Control/Dispatch/AutonomosLiveDispatcher.php` | brain:next live | FUSE |
| `Spine/AaeosEngineeringSpine.php` | N9/N11 contract | KEEP load-bearing |
| `Spine/AaeosSpineGate.php` | Stamp/evaluate | KEEP; expand call-sites |

**Disk law check after every commit touching Aaeos:** `aaeos_tree.pure=true` and zero foreign dirs.

---

## 22. Catálogo de schemas (não quebrar sem version bump)

| Schema | Owner class | Mutável? |
|---|---|---|
| `atlas.aaeos.cycle_receipt.v1` | `AaeosCycleRuntime` | Additive fields OK; never remove keys used by tests/cockpit |
| `atlas.aaeos.live_dispatch.v1` | `AaeosLiveDispatchGateway` | Additive |
| `atlas.aaeos.objective.v1` | `AaeosIntentCompiler` | Stable |
| `atlas.aaeos.difficulty.v1` | `AaeosDifficultyClassifier` | Stable |
| `atlas.aaeos.mode_selection.v1` | `AaeosModeSelector` | Stable |
| `atlas.aaeos.admission.v1` | `AaeosAdmissionPolicy` | Stable |
| `atlas.aaeos.world_snapshot.v1` | `AaeosWorldSnapshot` | Additive probes OK |
| `atlas.aaeos.org_state.v1` | `AaeosOrgStateProjector` | Stable |
| `atlas.aaeos.scorecard.v1` | `AaeosScorecardProjector` | May add `view`, `measured`, `insufficient_runtime_sample` |
| `atlas.aaeos.operate_scorecard.v1` | Operate projector orphan | Retire com a classe; nova view é aditiva em `atlas.aaeos.scorecard.v1` |
| `atlas.tri_hygiene.scorecard.v1` | TriHygiene projector | Stable |
| `atlas.aaeos.learning_candidate.v1` | OutcomeRecorder | Stable; status must stay `pending_review` default |
| `atlas.aaeos.engineering_spine.v1` | EngineeringSpine | Stable |
| `atlas.aaeos.spine_gate.v1` | SpineGate | Stable |
| `atlas.aaeos.dev_session_pack.v1` | Dev dispatcher/executor | May add `kernel_port` |
| `atlas.aaeos.forge_intake_envelope.v1` | Forge dispatcher/executor | May add `kernel_port` |
| `atlas.aaeos.god_sota_certify.v1` | CertifyCommand | Must drop reliance on hardcoded wiring dims |
| `atlas.aaeos.mission_control_cockpit.v1` | MissionControl (AEOS) | Out of daily Operate; don't break |
| `atlas.elite_executor_kernel.v1` | EliteExecutorKernel | **Do not change** in MT unless adapter needs; prefer consume |

**Rule:** new fields = optional with defaults; breaking change = `.v2` + dual-read window.

---

## 23. Matriz CLI completa (flags · daily · advanced · deprecated)

### 23.1 Daily Operate (`atlas:aaeos:*`)

| Command | Flags | Daily? | MT action |
|---|---|---|---|
| `atlas:aaeos:run` | `intent?` `--autonomos` `--mode=` `--live` `--dry-run` `--max-seeds=` `--execute-provider` `--run-worker-once` `--scope=` `--json` | **YES primary** | KEEP + honesty |
| `atlas:aaeos:cycle` | `intent?` `--autonomos` `--live` `--max-seeds=` `--dry-run` `--json` | twin | → **deprecated alias** of run |
| `atlas:aaeos:scorecard` | `--json` (+ MT: `--view=structural\|operate`) | YES health | EVOLVE measured |
| `atlas:aaeos:certify` | `--json` | YES gate | REMOVE hardcode hints |
| `atlas:aaeos` | `{action?}` | router | help → run/scorecard/certify/cockpit |

### 23.2 Advanced AEOS (`atlas:aeos:*`)

| Command | Daily? | MT action |
|---|---|---|
| `atlas:aeos:observe` | NO (god floors) | KEEP; P3 may compact internals |
| `atlas:aeos:maturity` | periodic/CI | KEEP |
| `atlas:aeos:department-status` | periodic | KEEP |
| `atlas:aeos:department-registry` | periodic | KEEP |
| `atlas:aeos:choreography-status` | periodic | KEEP |
| `atlas:aeos:verify-tests` | CI | KEEP |
| `atlas:aeos:deferred-worker` | background | KEEP |

### 23.3 Meta hygiene

| Command | MT action |
|---|---|
| `atlas:tri-hygiene:scorecard` | KEEP separado; não virar view do scorecard AAEOS neste MT |

### 23.4 Deprecated aliases (forwarders — do not revive logic)

```
atlas:aaeos:maturity → atlas:aeos:maturity
atlas:aaeos:department-status → atlas:aeos:department-status
atlas:aaeos:department-registry → atlas:aeos:department-registry
atlas:aaeos:choreography-status → atlas:aeos:choreography-status
atlas:aaeos:verify-tests → atlas:aeos:verify-tests
atlas:aaeos:deferred-worker → atlas:aeos:deferred-worker
atlas:aaeos:learning-proposals → atlas:learning:proposals-decision
atlas:aaeos:memory-cognitive-immune-learning-kernel → atlas:memory:cognitive-immune-kernel
atlas:aaeos:codex-review-chain-contract → atlas:review:codex-chain-contract
atlas:aaeos-acos:simplify-cycle → atlas:acos:simplify-cycle
```

### 23.5 Caps matrix (obrigatória no receipt)

| Flag combo | Expected behavior |
|---|---|
| (default) | `plan_only` / no live muscle; dualcore may still attempt |
| `--dry-run` | wins over `--live`; no ledger write attempt; no Artisan muscle |
| `--live` | ModeExecutor may call brain/kernel ports within caps |
| `--live --max-seeds=N` | Autônomos seed up to N |
| `--live --run-worker-once` | one `atlas:task next` |
| `--execute-provider` without `--live` | **FAIL FAST** (P0) |
| `--execute-provider --live` | note opt-in; still may not auto-burn (Dev/Forge note effect) |
| irreversible intent | `halt_sovereign` regardless of flags |

---

## 24. Contrato ModeExecutor (substitui Adapter + LiveDispatcher)

```php
namespace App\Services\Ai\Aaeos\Control\Executors;

interface AaeosModeExecutor
{
    public function mode(): string; // AaeosExecutorMode::*

    /**
     * @param  array<string,mixed>  $cyclePlan  objective/difficulty/mode/admission/world/live_dispatch/adapter_accept?
     * @param  array<string,mixed>  $options    live, plan_only, max_seeds, execute_provider, run_worker_once, scope, run_brain_next
     * @return array{
     *   status: string,                 // ready|plan_only|dispatched_live|dispatch_failed|blocked_cap
     *   effect_level: string,           // none|prepared|mutated|provider_executed|blocked
     *   proof_level: string,            // enum §47, conforme a evidência realmente obtida
     *   failure_reason: string|null,     // obrigatório quando failed/blocked/unknown
     *   operate_path: list<string>,
     *   effects: list<array<string,mixed>>,
     *   next_commands: list<string>,
     *   spine: array<string,mixed>,
     *   spine_contract?: array<string,mixed>,
     *   elite_same_bar: true,
     *   duration_regime: string,        // interactive|durable_task|obra|continuous
     *   delegation_regime: string,      // synchronous|planned|continuous
     *   work_origin: string,            // operator_intent|obra_mandate|self_originated
     *   sovereignty: array{status:string,decision_receipt_ref?:string|null,failure_reason?:string|null},
     *   seed_gate_required?: bool,
     *   scoped_commit_required?: bool,
     *   commit_policy?: string,
     *   provider_calls: int,
     *   kernel_port?: string|null,
     *   blocked_cap?: string|null,
     *   dualcore?: array<string,mixed>  // usually filled by gateway
     * }
     */
    public function execute(array $cyclePlan, array $options = []): array;
}
```

**Classes alvo:**

| Class | Path exato | Substitui |
|---|---|---|
| `DevModeExecutor` | `app/Services/Ai/Aaeos/Control/Executors/DevModeExecutor.php` | DevModeAdapter + DevLiveDispatcher |
| `ForgeModeExecutor` | `app/Services/Ai/Aaeos/Control/Executors/ForgeModeExecutor.php` | ForgeModeAdapter + ForgeLiveDispatcher |
| `AutonomosModeExecutor` | `app/Services/Ai/Aaeos/Control/Executors/AutonomosModeExecutor.php` | AutonomosModeAdapter + AutonomosLiveDispatcher |

**Gateway rule:** `AaeosLiveDispatchGateway` only resolves ModeExecutor by mode; DualCore record stays in gateway.

**Deletion rule:** after green tests, delete old Adapter/Dispatcher files in same commit series (no zombie dual stacks).

---

## 25. Shape mínimo do cycle receipt (pós-P0)

Todo `atlas:aaeos:run|cycle --json` DEVE incluir o envelope abaixo. O contrato normativo e aditivo de §50 acrescenta `cycle_id`, inventário de side effects, `effect_level`, `proof_level` e `failure_reason`; em conflito, §50 prevalece.

```json
{
  "schema": "atlas.aaeos.cycle_receipt.v1",
  "status": "dispatched|dispatched_live|dispatch_failed|halted|plan_only-implied",
  "dry_run": true,
  "live_dispatch": false,
  "plan_only": true,
  "caps": {
    "execute_provider": false,
    "max_seeds": 0,
    "run_worker_once": false
  },
  "honesty": {
    "muscle_invoked": false,
    "message": "plan_only: no muscle side effects"
  },
  "elite_same_bar": true,
  "execution_context": {
    "duration_regime": "interactive",
    "delegation_regime": "synchronous",
    "work_origin": "operator_intent",
    "sovereignty_status": "within_mandate"
  },
  "evidence_status": "skipped|recorded|skipped_no_ledger|skipped_table_missing|skipped_error|skipped_no_container",
  "objective": {},
  "difficulty": {},
  "mode": {},
  "admission": {},
  "world": { "world_source": "config_defaults|task_queue|..." },
  "dispatch": {
    "operate_path": [],
    "live": { "status": "plan_only|dispatched_live|...", "effects": [], "provider_calls": 0 },
    "dualcore": { "recorded": false }
  },
  "spine": { "delivery": "N9", "evidence": "N11", "assert": {} },
  "learning": { "status": "pending_review|none|..." },
  "next_commands": []
}
```

---

## 26. Critical Path List — Spine N9/N11 (P2 honesty)

Máximo 8 sites. P2 DONE = **100% desta lista stamped**, não “todo o monólito”.

| # | Site | Path | Mode | Status baseline |
|---|---|---|---|---|
| S1 | Programming surface Dev contract | `ProgrammingSurfaceContractFactory` | DEV | stamped |
| S2 | Programming surface Forge contract | same factory forge path | FORGE | stamped |
| S3 | Forge work intake | `AtlasCodeForgeWorkIntakeService` | FORGE | stamped |
| S4 | Forge live executor path | `ForgeModeExecutor` / current ForgeLiveDispatcher | FORGE | stamped |
| S5 | Dev live executor / session pack | `DevModeExecutor` | DEV | must stamp or explicit residual |
| S6 | Dev execution boundaries | `app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php::run` + `app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php::run` | DEV | no stamp observed; choose shared boundary or owner-backed `not_applicable_evidence` |
| S7 | Task enqueue boundary | `app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php::prepareAndEnqueue` | AUTONOMOS/DEV | no stamp observed; measure applicability before adding dependency |
| S8 | Dev/Forge Kernel port boundary | `EliteExecutorKernelDevAdapter` + `ForgeEliteKernelExecutionAdapter` | DEV/FORGE | P2 must wire; Autônomos permanece Brain-first |

Document completion in `PHASE-2-RECEIPT.md` with `rg` proof per site.

---

## 27. Kernel port map (P2 — consumir, não reimplementar)

| Mode | Port class | Safe default under `--live` | Requires `--execute-provider` |
|---|---|---|---|
| DEV | `EliteExecutorKernelDevAdapter` / `EliteExecutorKernel` read-only/prepare | prepare / plan vertical | mutative execute |
| FORGE | `ForgeEliteKernelExecutionAdapter` + intake stamp | intake + gate observe | live forge execute |
| AUTONOMOS | **not** Kernel-first: `atlas:brain:next` → seed → task | brain next + optional seed | N/A (provider inside task worker) |

**Proibido:** AutonomosModeExecutor chamar HermeticSandbox/provider direto.

---

## 28. Matriz de testes (o que rodar / tocar / não mexer)

### 28.1 Suite obrigatória a cada fase (gate)

```bash
php artisan test \
  tests/Unit/Ai/Aaeos/Control \
  tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php \
  tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php \
  --no-coverage
```

### 28.2 Control / Operate (tocar)

| Test | Fase |
|---|---|
| `tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php` | P0–P2 |
| `tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php` | P1–P2 |
| `tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php` | P0 alias |
| `tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php` | P0 certify honesty |
| **NEW** `tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php` | P0 |
| **NEW** `tests/Unit/Ai/Aaeos/Control/Executors/DevModeExecutorTest.php` | P1 |
| **NEW** `tests/Unit/Ai/Aaeos/Control/Executors/ForgeModeExecutorTest.php` | P1 |
| **NEW** `tests/Unit/Ai/Aaeos/Control/Executors/AutonomosModeExecutorTest.php` | P1 |
| **NEW** `tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php` | P0 |

### 28.3 Kernel (consumir em P2 — não reescrever suíte)

| Test | Uso |
|---|---|
| `tests/Feature/Ai/EngineeringKernel/EliteExecutorKernelReadOnlyVerticalTest.php` | smoke após wire Dev port |

### 28.4 Maturity/AEOS unitários (61 files sob *Aaeos*)

Muitos testes ainda sob `tests/Unit/Ai/Aaeos/*` apontam para classes **re-homed** em `AgenticEngineeringOs` (aliases).

| Ação | Quando |
|---|---|
| Não deletar em massa | nunca neste MT |
| Rodar subset wiring wired | P3 alias burn |
| Golden gates | P3 ObserveRegistry |

### 28.5 Proibido “verde falso”

- Não skippar testes falhando com `@group` sem LEDGER debt.
- Não baixar asserts de `elite_same_bar` / halt_sovereign.

---

## 29. Passos atomizados extras (nível GOD/SOTA plan)

### Task A — P0.0 Pré-voo (obrigatório antes de código)

- [ ] `git branch --show-current` == `main`
- [ ] Sem `MERGE_IN_PROGRESS`
- [ ] `php artisan atlas:aaeos:certify --json` paste → LEDGER
- [ ] `php artisan atlas:aaeos:scorecard --json` paste → LEDGER
- [ ] `rg -n "Aaeos\\\\Quarantine" app --glob '*.php' | rg -v '/Quarantine/' || true` → 0
- [ ] Operador OK explícito para P0

### Task B — P0 scorecard measured (detalhe)

- [ ] Add fields to scorecard JSON: `certification_scope`, `elite_deepening_done`, `measurement_health`, `measured_composite`, `hard_blockers`, `view`
- [ ] Implement probes:
  - filesystem: S1–S4 stamp presence (static analysis or fixture flags)
  - runtime: query bounded `AAEOS_CYCLE_RECORDED` events from the canonical ledger, deduped by `cycle_id`
- [ ] Enriquecer o evento do CycleRuntime com `cycle_id`, `effect_level`, `dispatch_status`, `dualcore_recorded`, `world_source`, `provider_calls`, sem nova tabela/store
- [ ] CertifyCommand: remover o array de hints inteiro; chamar projector apenas com `view/window`
- [ ] Se sample=0: runtime dimensions `unknown`, `measured_composite=null`, structural certify pode passar com scope explícito, Elite Deepening não

### Task C — P0 cycle alias (detalhe)

- [ ] Criar `AaeosRunRequest` + `AaeosRunApplication`; `run` e `cycle` convertem CLI para a mesma request e chamam a mesma application service
- [ ] `AtlasAaeosCycleCommand` mantém o nome legado, aceita o superset de flags de `run`, aplica somente o default intent legado e emite deprecation fora de JSON

```php
final readonly class AaeosRunRequest
{
    public function __construct(
        public string $intent,
        public ?string $forcedMode,
        public bool $live,
        public bool $dryRun,
        public int $maxSeeds,
        public bool $executeProvider,
        public bool $runWorkerOnce,
        public ?string $scope,
    ) {}
}
```

- [ ] Proibido delegar via `Artisan::call('atlas:aaeos:run')`: isso mistura stdout/exit e dificulta DI
- [ ] Feature test ambos os comandos com a matriz §51: mesmos receipt keys/exit codes para input equivalente; JSON sem warning

### Task D — P1 migration order (zero dual-stack)

1. Create `Executors/` + interface
2. Implement AutonomosModeExecutor (copy+merge behavior)
3. Point gateway to Autonomos executor only; keep Dev/Forge dispatchers temporarily
4. Tests green
5. Migrate Dev, then Forge
6. Delete Adapters + old Dispatchers + `AaeosModeLiveDispatcher`
7. Update CODEMAP

### Task E — P2 spine residual protocol

For each of S1–S8:

```text
- [ ] S1/S2 ProgrammingSurfaceContractFactory: assert existing stamp and tests
- [ ] S3 AtlasCodeForgeWorkIntakeService: assert existing stamp and tests
- [ ] S4 ForgeModeExecutor: assert stamp after P1 migration
- [ ] S5 DevModeExecutor: add/evidence stamp before any Kernel port
- [ ] S6 `AtlasDevExecutionService::run` + `SeniorEngineerLoopExecutor::run`: stamp at the shared invocation owner, or record owner-backed `not_applicable_evidence`; never stamp every caller
- [ ] S7 `AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue`: measure applicability only; do not add AAEOS dependency unless the SelfConstruction owner contract approves
- [ ] S8 EliteExecutorKernel Dev/Forge adapter boundary: require receipt link or name exact blocker
- [ ] paste path + symbol + test + receipt status for S1–S8 in PHASE-2-RECEIPT.md
```

### Task F — P3 ObserveRegistry acceptance

- [ ] `AtlasUniversalGatesEvaluator` public methods unchanged
- [ ] Golden test green
- [ ] Decision receipt escolhe `refactor` somente com hop/ownership benefit; `not_applicable_evidence` encerra a proposta sem fingir redução
- [ ] Se `refactor`, hop count cai e nenhum owner body volta a godfile; LOC isolado não é gate
- [ ] No new business logic in pass-through layers

### Task G — P4 freeze checklist

- [ ] `PHASE-4-COMPLETE.md` with DONE §5 all true/false table
- [ ] SCOREBOARD hard gates §49 all true; diagnostic composite labeled
- [ ] README STABLE v2
- [ ] REAL-RUN-RECEIPTS contém live `brain_next.exit_code=0`; heartbeat stale fica `blocked_ops_sustained` separado

---

## 30. Aceitação por residual (C1–C4 / R1–R15 → fase + prova + receipt)

| ID | Fase | Prova/teste obrigatório | Receipt | Done quando |
|---|---|---|---|---|
| C1 | P3–P4 | `AaeosArchiveHoldTest` + docs scan | `PHASE-4-COMPLETE.md` | Vocabulary/README dizem live Control+Spine e archive cemetery |
| C2 | P2–P3 | `AaeosSpineCriticalCoverageTest` + CODEMAP diff | `PHASE-2-RECEIPT.md` | Docs e ports mostram governo AAEOS separado de Brain/Task/Kernel muscle |
| C3 | P0 | `AtlasAaeosRunCommandTest` / `AtlasAaeosCycleCommandTest` | `PHASE-0-RECEIPT.md` | Help/JSON explicam plan, live, provider cap e efeitos reais |
| C4 | P0 | `AaeosMeasuredScorecardTest` | `PHASE-0-RECEIPT.md` | Score alto nunca esconde hard blocker/unknown |
| R1 | P2 | `AaeosSpineCriticalCoverageTest` | `PHASE-2-RECEIPT.md` | 100% dos sites aplicáveis S1–S8 stamped; N/A só com owner evidence |
| R2 | P2 | same coverage test + ledger reader aggregation | `PHASE-2-RECEIPT.md` | Spine daily coverage publicada; enqueue path medido |
| R3 | P2 | `EliteExecutorKernelReadOnlyVerticalTest` + per-mode receipts | `PHASE-2-RECEIPT.md` | Full rewrite fica fora; strangler progress explícito, sem false DONE |
| R4 | P1 | `AutonomosModeExecutorTest` | `PHASE-1-RECEIPT.md` | Intenção `--live → atlas:brain:next` preservada; **R33/R34** fecham contrato real |
| R5 | P4 | real command + artifact readback | `REAL-RUN-RECEIPTS/R-autonomos-live.json` | ≥1 `brain_next.exit_code=0`; sustained blocker não substitui one-shot |
| R6 | P0–P1 | `AaeosReceiptHonestyTest` + measured aggregation | `PHASE-1-RECEIPT.md` | `dualcore.recorded` visível e rate reproduzível |
| R7 | P0–P1 | receipt honesty + DB-unavailable reader case | `PHASE-1-RECEIPT.md` | `evidence_status` nunca silencioso; non-dry skipped aparece no operate view |
| R8 | P0–P1 | `AaeosMeasuredScorecardTest` | `PHASE-1-RECEIPT.md` | `world_source` e defaults-only rate expostos |
| R9 | P3 | `AaeosLegacyAliasRetirementTest` + Composer dump-autoload | `PHASE-3-RECEIPT.md` | 40/40 canônicos resolvem; 40/40 legacy não; compat file removido |
| R10 | P3 | alias retirement test inclui docs/CODEMAP canonical path | `PHASE-3-RECEIPT.md` | Vocabulary, mother doc e CODEMAP sem dual-map mentiroso |
| R11 | P3 | evidence census + no-new-generated diff | `PHASE-3-RECEIPT.md` | Redução intencional aceita; nenhum test órfão restaurado por contagem |
| R12 | Todas/P4 | `AaeosArchiveHoldTest` + certify | todo phase receipt + `PHASE-4-COMPLETE.md` | Archive presente/intocado, imports zero, README FROZEN |
| R13 | P3 docs | route/docs scan dos AP-696–702 | `PHASE-3-RECEIPT.md` | HTTP listado como irmã non-daily; nenhuma casca entra no DONE |
| R14 | P0 | `AtlasAaeosRunCommandTest` caps rows | `PHASE-0-RECEIPT.md` | provider sem live falha; dry/plan-only mostra honest banner |
| R15 | P1 | `AtlasCliCockpitCommandTest` | `PHASE-1-RECEIPT.md` | Cockpit mostra AAEOS residual e mantém review inbox separado |
| R16–R32 | ver §46 | testes/receipts da coluna §46 | phase receipt da coluna | Aceitação canônica de residuals novos = §46 (não duplicar aqui) |
| R33 | P1 (+red P0/P1) | `AutonomosModeExecutorTest` brain args | `PHASE-1-RECEIPT.md` | `Artisan::call` usa posicional `scope` (default `autonomous`); zero flag `--scope` em brain:next |
| R34 | P1 | same + payload status cases | `PHASE-1-RECEIPT.md` | `disabled|dry|error` ⇒ não `effect_level=mutated` mesmo com exit 0 |
| R35 | P1 | seed effect honesty | `PHASE-1-RECEIPT.md` | `max_seeds` não inventa `--max`; effect registra o que o seed realmente aceitou |
| R36 | P4 | preflight checklist §60 | `PHASE-4-COMPLETE.md` | Preflight documentado; OOM/master-off = blocked_ops, não DONE |
| R37 | P0 | `AtlasAaeosRunCommandTest` + Cycle parity | `PHASE-0-RECEIPT.md` | Matrix §59 verde: flags, defaults, exit codes idênticos no path shared |

---

## 31. APs HTTP / Mission Control (explícito: não bloqueiam MT)

| AP | Título | Relação com MT |
|---|---|---|
| AP-696…699 | HTTP path façade phases | AEOS observe/contracts — **não** daily Operate |
| AP-700 | Dev plan visible HTTP readmodel | irmã; out of P0–P3 |
| AP-701 | Dev scope guard contract | irmã |
| AP-702 | Mission control cockpit HTTP/desktop | route exists; **not** day-to-day; P4 optional note only |

MT DONE **não** exige desktop wiring 100%.

---

## 32. CODEMAP entries (obrigatório atualizar)

File: `app/Services/Ai/CODEMAP.md` (e/or local Aaeos README table)

| Capability | Symbol |
|---|---|
| Daily operate | `atlas:aaeos:run` → `AaeosCycleRuntime::runCycle` |
| Mode execution | `AaeosModeExecutor::execute` |
| Live gateway | `AaeosLiveDispatchGateway::dispatch` |
| Spine stamp | `AaeosSpineGate::stamp` |
| Measured scorecard | `AaeosScorecardProjector::project` / Measured |
| Certify | `AtlasAaeosCertifyCommand` |
| Elite muscle | `EliteExecutorKernel::execute` (consume) |
| Autônomos muscle | `atlas:brain:next` / `atlas:task` |

---

## 33. Densidade & orçamento LOC

| Superfície | Orçamento | Nota |
|---|---|---|
| Hot façade/command | ≤800 LOC | Run/Certify/Scorecard commands |
| Qualquer novo PHP Aaeos | ≤2000 | prefer deepen existing |
| `Aaeos/` total live | hold ~2–4k | não reimportar maturity |
| ModeExecutor each | ≤200 LOC | deep via delegation |
| Measured scorecard | ≤400 LOC | formulas + probes |
| ObserveRegistry (P3) | net LOC down | deletion > addition |

Anti-vanity: commits que só movem arquivos sem behavior = só se Phase E burn.

---

## 34. Measurement read model — Evidence Ledger único (P0.4 detalhe)

**Problema factual:** `AaeosScorecardProjector::counters` hoje apenas ecoa hints/defaults e sai com `cycles_total=0`; o v2 propunha `storage/atlas/aaeos/cycle_counters.json`, que viraria segunda verdade, perderia idempotência e teria risco de lost update concorrente.

**Decisão v3:** nenhum counter store novo. O source de runtime é `atlas_ledger_events` via `AtlasEvidenceLedger`; scans de disco continuam source estrutural.

1. Gerar `cycle_id` ULID no início de todo run e devolvê-lo no receipt.
2. Em non-dry, registrar um `AAEOS_CYCLE_RECORDED` com `cycle_id`, mode, admission, `effect_level`, dispatch status, effect kinds, `dualcore_recorded`, world source, provider calls, spine verdict e hashes provider-safe.
3. `AaeosLedgerMeasurementReader` consulta por `event_type` + janela UTC, limita volume, deduplica por `cycle_id` e produz `AaeosMeasurementSnapshot`; não escreve.
4. Projector recebe o snapshot por DI e calcula apenas a partir dele; DB/table indisponível → `measurement_health=unavailable`, dimensões runtime unknown, composite measured null.
5. Uma falha de ledger não pode registrar a própria ausência. O receipt imediato mantém `evidence_status=skipped_*`; o scorecard declara blind spot `unobservable_failed_writes` em vez de inventar `evidence_recorded_rate`.
6. Dry-run nunca entra em denominador runtime; seu receipt marca `effect_level=none`, `runtime_write_performed=false`, `evidence_status=skipped_dry_run`.
7. Nenhuma query depende de JSON-path específico do SQLite para alegar produção: integration proof roda contra a conexão PostgreSQL configurada ou fica `not_proven_postgres`, sem converter teste SQLite em prova operacional.

---

## 35. DAG de dependências (paralelismo seguro)

```text
P0.0 pré-voo
  └─ P0.2 CLI alias ──┐
  └─ P0.3 honesty ────┼─► P0.4 measured scorecard ─► P0.5 gates ─► P1
  └─ P0.1 evidence ───┘

P1.1 ModeExecutor iface
  └─ P1.2 Autonomos executor ─► P1.3 Dev/Forge executors ─► delete old ─► P1.4 cockpit ─► P2

P2.3 ladder (docs+type) can parallel P2.1 kernel ports
P2.1 kernel ports ─► P2.2 spine critical list ─► P2.4 gates ─► P3

P3.1 observe registry ║ P3.2 alias burn ║ P3.3 quality bar partial
  └─► P3.4 docs ─► P4 gauntlet/freeze
```

**Agentes paralelos:** só nós sem aresta comum de arquivo. Nunca dois agents em `AaeosCycleRuntime.php`.

---

## 36. RACI (operador + IAs + executores)

| Trabalho | Operador (Vitor) | IA implementadora | Autônomos 24/7 | Dev session | Forge obra |
|---|---|---|---|---|---|
| Autorizar fase | A | C | I | I | I |
| Código P0–P3 | I | R | I | C | C |
| Live gauntlet P4 | A/R (env) | C | R (se master on) | C | I |
| Scoreboard honesty | A | R | I | I | I |
| Heartbeat/launchd | A/R | C | — | I | I |
| Push force-with-lease | A only | proibido sozinho | — | — | — |

R=Responsible A=Accountable C=Consulted I=Informed

---

## 37. Ops Autônomos (heartbeat stale) — protocolo

Sintoma: `storage/atlas/scheduler/heartbeat.jsonl` STALE ~10d+.

Checklist (não é bug AAEOS Control):

```bash
# 1) master
php artisan atlas:agents:status
php artisan atlas:agents:on autonomos    # only if operator wants

# 2) launchd / print-disabled (macOS) — operator
# 3) re-check heartbeat age
# 4) if still stale → sustained/24h = blocked_ops_sustained; one-shot P4 live continua obrigatório
```

MT não “conserta launchd” como feature de Control plane.

---

## 38. Rollback / fail strategy

| Falha | Rollback |
|---|---|
| Testes Control vermelhos | `git revert` commit escopado; não stash |
| Certify quebrado | hotfix honesty/projector; composite floor |
| ModeExecutor regrede brain:next | restore AutonomosLiveDispatcher from commit pai (scoped) |
| Alias burn quebra FQCN | temporarily restore `AaeosHygieneLegacyAliases` ONE commit; fix refs; re-burn |
| Kernel port burn provider | feature flag / remove call; keep caps |

**Nunca:** `git reset --hard` sem OK; `git stash` de obra; merge de branch.

---

## 39. Anti-padrões (checklist de review)

- [ ] Reanimar Quarantine
- [ ] Segundo seed-gate / segundo evidence ledger
- [ ] Fundir 3 modos sem `AaeosExecutorMode`
- [ ] Hardcode 9.2 no certify
- [ ] Declarar R3 done sem strangler
- [ ] Chamar Dev de fast-patch
- [ ] Chamar Autônomos de qualidade inferior
- [ ] Tocar Terminal/Hermes/TUI como “AAEOS fix”
- [ ] Big-bang rename AgenticEngineeringOs → Aaeos
- [ ] `git add -A`
- [ ] Auto-promote learning
- [ ] Pass-through novo em GateObserve
- [ ] Crescer `Aaeos/` com Maturity/Generated

---

## 40. Glossário rápido (para IAs)

| Termo | Significado neste MT |
|---|---|
| MT | Master Implementation Plan (este arquivo) |
| AAEOS | Org control plane Operate |
| AEOS | AgenticEngineeringOs observe/maturity |
| ModeExecutor | fusão adapter+dispatcher |
| Muscle | Brain/Task/Kernel/RealExecution |
| Caps | live / execute-provider / max-seeds / worker-once |
| Measured | score derivado de probes/counters |
| Critical Path List | ≤8 spine sites |
| DEBT | residual nomeado no LEDGER |
| blocked_ops_sustained | Claim 24/7 bloqueado por launchd/heartbeat; one-shot é status separado |
| pending_review | learning never auto-promote |
| STABLE v2 | freeze após P4 |

---

## 41. Evidence pack template (criar por fase)

O template v2 com campos vazios foi substituído pelo schema preenchível e sem silêncio de §55. Cada fase cria exatamente `docs/evidence/2026-07-23-aaeos-elite-deepening/PHASE-N-RECEIPT.md`, onde `N` é `0`, `1`, `2`, `3` ou `4`; nenhum receipt é criado nesta rodada plan-only.

---

## 42. Sequência de mensagens de commit (canônica)

```
docs(core): AAEOS-MT P0 evidence harness
feat(core): AAEOS-MT P0 run-cycle alias + honesty caps
feat(core): AAEOS-MT P0 measured scorecard + ledger read model
test(core): AAEOS-MT P0 control/certify honesty
refactor(core): AAEOS-MT P1 AutonomosModeExecutor + brain args R33
refactor(core): AAEOS-MT P1 Dev+Forge ModeExecutors + delete adapters
feat(core): AAEOS-MT P1 cockpit aaeos residual
test(core): AAEOS-MT P1 mode executors
feat(core): AAEOS-MT P2 kernel ports under caps
feat(core): AAEOS-MT P2 spine critical path + ladder axes
test(core): AAEOS-MT P2 spine+kernel smoke
refactor(core): AAEOS-MT P3 observe registry
refactor(core): AAEOS-MT P3 hygiene alias burn
docs(core): AAEOS-MT P3 vocabulary+CODEMAP
docs(core): AAEOS-MT P4 gauntlet+freeze v2
```

**Anti-contradição:** a mensagem P0 **não** pode dizer “counter store”. §34 é normativo: read model do Evidence Ledger apenas.

---

## 43. Definition of DONE — tabela binária final (P4)

Copiar para `PHASE-4-COMPLETE.md` e marcar:

| # | Critério §5 | true/false |
|---|---|---|
| 1 | Porta diária única `run` | |
| 2 | CycleRuntime deep chokepoint + ModeExecutors | |
| 3 | Muscle sob caps (Autônomos/Dev/Forge) | |
| 4 | Scorecard medido sem hardcode wiring | |
| 5 | Honesty surface CLI+cockpit | |
| 6 | Observe decision receipt completo; refactor parity green ou not_applicable_evidence provado | |
| 7 | Ladder multi-eixo | |
| 8 | Gauntlet live com `brain_next.exit_code=0` | |
| 9 | Structural certify ok + elite-deepening done + sample suficiente | |
| 10 | Docs alinhados | |
| 11 | Quarantine imports 0 | |
| 12 | Sem foreign paths sob Aaeos/ | |

Programa DONE iff **todos** os itens forem true e todos os hard gates §49 estiverem verdes. Item false/unknown, `blocked_ops_sustained`, DEBT ou waiver mantém `PARTIAL`/`BLOCKED`; aprovação humana pode aceitar risco para continuar, mas não reescreve a verdade do status.

---

## 44. Índice de cobertura do v2 (não é prova de completeza)

Os `[x]` abaixo significam apenas “o assunto aparece em algum lugar do arquivo”. Eles **não** significam que o fato está correto, o contrato está executável ou a prova existe. A auditoria real é §45–§85 (v5).

- [x] Conceituais C1–C4
- [x] Residuals R1–R15
- [x] Scoreboard dims GOD/SOTA + OPERATE + MT
- [x] Quarantine stats + archive path
- [x] EliteExecutorKernel gap
- [x] Fusion rank 1–8 + do-not-fuse
- [x] Formulas scorecard
- [x] Fases P0–P4 checkboxes/contratos (§9 + §52)
- [x] File map primary/secondary/forbidden
- [x] Automação tabela
- [x] Commit discipline
- [x] Riscos
- [x] In/out scope + horizonte pós-MT
- [x] Playbook sessão
- [x] Traceabilidade auditoria
- [x] Ready to start
- [x] Contatos canônicos
- [x] Inventário 27 PHP fate
- [x] Schemas catalog
- [x] CLI flags + deprecated aliases
- [x] Caps matrix
- [x] ModeExecutor contract
- [x] Receipt JSON shape
- [x] Spine critical path S1–S8
- [x] Kernel port map
- [x] Test matrix
- [x] Atomized tasks A–G
- [x] Residual acceptance criteria
- [x] APs 696–702
- [x] CODEMAP
- [x] Density budgets
- [x] Counter persistence
- [x] DAG parallelism
- [x] RACI
- [x] Heartbeat ops protocol
- [x] Rollback
- [x] Anti-patterns review
- [x] Glossário
- [x] Evidence templates
- [x] Commit message list
- [x] Final binary DONE table
- [x] v4: R33–R37 + §59–§68 (CLI matrix, brain contract, dimensions live, CODEMAP, DI, dry writes, schema, P0 freeze, predecessor census, anti-already-done)
- [x] v5 RSS: §69–§76 anti-duplicação; R38–R46; horizonte R47–R50; mega Part IV removida

Se aparecer residual novo: R## em §46 ou §72; nunca plano paralelo.

---

---

# PARTE III — ABSOLUTE AUDIT (prova, não volume)

## 45. Auditoria forense do disco vs. §44

Snapshot read-only executado em 2026-07-23 no `main` local. Nenhum P0–P4 foi implementado e nenhum comando live foi disparado nesta rodada.

| Claim do v2 | Evidência direta atual | Veredito | Correção vinculante |
|---|---|---|---|
| AAEOS live é Control+Spine | `find app/Services/Ai/Aaeos -name '*.php'` → 27 PHP / 2,114 LOC; dirs live `Control/`, `Spine/` | **VERIFIED_DISK** | Hold; tamanho não prova capacidade |
| Quarantine ainda precisa ser arquivada | `archive/app/Services/Ai/Aaeos/Quarantine` → 306 PHP / 131,664 LOC | **FALSE** | Archive está DONE; só guard R12 |
| Imports Quarantine = 0 | scorecard/certify → `quarantine_production_imports=0`; busca FQCN em `app/` sem import produtivo | **VERIFIED_SCAN** | Revalidar em cada fase; nunca mover de volta |
| R4 `--live → brain:next` falta | `AutonomosLiveDispatcher::liveDispatch` chama `Artisan::call('atlas:brain:next', ...)`; `AaeosCycleRuntime` defaulta `run_brain_next=true` | **FALSE como gap de source wiring** | R4 = CLOSED_SOURCE |
| R4 call é semanticamente válido | `brainNextArgs` omite posicional `scope` e usa flag `--scope` inexistente no comando | **FALSE / BROKEN_CONTRACT (R33)** | P1 corrige args; tests must fail until fix |
| exit_code=0 implica brain serviu | `AtlasBrainNextCommand::emit` default SUCCESS para `disabled`; dry probe também | **FALSE (R34)** | Classificar payload.status |
| `max_seeds` limita batch seed | `atlas:brain:seed` **sem** `--max`; dispatcher tenta e faz retry bare | **FALSE (R35)** | Honest effect; não fingir cap de batch |
| Há receipt live real de R4 | evidence predecessor: só `REAL-RUN-RECEIPTS/R{1,2,3}-*-dry.json` com `dry_run=true`, `rwp=true`, effects `[]` | **NOT_PROVEN_REAL** | P4 após R33/R34 |
| Certify e scorecard usam os mesmos 9.2 | standalone: operate/spine/antifragile **default 9.0**; certify **injeta 9.2/9.2/9.0** | **DISTINÇÃO obrigatória** | P0 remove ambos os tipos de fantasia |
| Dev `--live` executa músculo | `DevLiveDispatcher` monta `dev_session_pack`; `provider_calls=0` | **OVERCLAIM** | Classificar `prepared`, não `mutated` |
| Forge `--live` executa músculo | `ForgeLiveDispatcher` monta/stampa `forge_intake_envelope`; `provider_calls=0` | **OVERCLAIM** | Classificar `prepared`, não `mutated` |
| Certify 9.56 é medido | `AtlasAaeosCertifyCommand` injeta 9.2/9.2/9.0 | **FALSE** | Structural proof válido; score não-medido |
| Scorecard 9.52 é runtime | projector usa constantes e defaults; `cycles_total=0` | **FALSE** | `legacy_assessment`; `measured_composite=null` |
| Só duas notas são fantasiosas | `thesis_clarity`, `elite_same_bar`, `control_plane` e Operate dims também são constantes | **FALSE** | Remover toda constante pontuável ou marcar `assessment_only` |
| `run` e `cycle` já são aliases | signatures, flags, defaults e exit handling diferem | **FALSE** | P0 shared application/request + parity matrix |
| Dry-run é write-free | CycleRuntime pula cycle ledger, mas command ainda chama OutcomeRecorder; receipt fixa `runtime_write_performed=true` | **FALSE/UNSAFE CLAIM** | P0 torna dry-run realmente write-free e o prova |
| Counters têm persistência | projector só ecoa hints/defaults | **FALSE** | Read model do ledger §34; zero JSON paralelo |
| Phase E alias burn é simples | compat map tem 40 aliases; busca só em `app` não prova consumidores externos/tests/classmap | **UNDER-SPECIFIED** | Reachability + classmap + negative resolution tests |
| Observe registry reduz debt por definição | sections/parts foram sub-split com goldens; novo registry pode recriar godfile | **UNPROVEN** | P3 condicional a medição e parity |
| §44 `[x]` prova completeza | marca apenas presença textual | **FALSE** | §46–§58 são o gate de executabilidade |

**Comandos de auditoria reexecutáveis:**

```bash
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json
/opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json
find app/Services/Ai/Aaeos -type f -name '*.php' -print0 | xargs -0 wc -l
find archive/app/Services/Ai/Aaeos/Quarantine -type f -name '*.php' -print0 | xargs -0 wc -l
rg -n "operate_path_wiring|spine_enforced|antifragile_loop|9\.2" app/Console/Commands/AtlasAaeosCertifyCommand.php app/Services/Ai/Aaeos/Control
rg -n "atlas:brain:next|run_brain_next" app/Services/Ai/Aaeos tests/Unit/Ai/Aaeos
```

## 46. Gaps novos descobertos (R16–R32)

| ID | Gap falsificável | Owner/Fase | Prova/teste | Receipt | Done quando |
|---|---|---|---|---|---|
| R16 | Scorecard mistura scans, opinião estática e hints | P0 | `AaeosMeasuredScorecardTest` | `PHASE-0-RECEIPT.md` | Cada dimensão tem status/source/sample; constantes assessment-only |
| R17 | `runtime_write_performed=true` fixo; dry pode chamar recorder | P0 | `AaeosReceiptHonestyTest` | `PHASE-0-RECEIPT.md` | Inventário deriva de resultados; dry zero writes |
| R18 | `dispatched_live` confunde pack/intake com mutação | P0–P1 | three executor tests | `PHASE-1-RECEIPT.md` | Effect enum diferencia none/prepared/mutated/provider/blocked |
| R19 | Nota sem window/denominator/provenance não é reprodutível | P0 | scorecard + reader tests | `PHASE-0-RECEIPT.md` | Dimension contract §49 completo |
| R20 | Counter JSON duplicaria Evidence Ledger | P0 | reader test + no-new-store diff | `PHASE-0-RECEIPT.md` | Ledger read model único; zero file/table paralelos |
| R21 | `run`/`cycle` divergem em flags/defaults/JSON/exit | P0 | two command parity tests | `PHASE-0-RECEIPT.md` | Matrix §51 verde |
| R22 | Falta feature test dedicado do RunCommand | P0 | `AtlasAaeosRunCommandTest` | `PHASE-0-RECEIPT.md` | Matriz §50 coberta |
| R23 | Source/mock/dry/real estavam misturados | Todas | receipt contract assertions | todo phase receipt | Toda claim usa proof level §47 suportado |
| R24 | Targets numéricos e waiver-DONE contraditórios | Plano/P0 | scorecard hard-veto tests | `PHASE-0-RECEIPT.md` | Hard gates governam; média não certifica |
| R25 | Blocked ops podia substituir live proof | P4 | required live artifact assertion | `PHASE-4-COMPLETE.md` | Blocked mantém PARTIAL/BLOCKED |
| R26 | Alias burn usava `rg app=0` como prova suficiente | P3 | alias retirement + classmap | `PHASE-3-RECEIPT.md` | 40/40 migration map verde |
| R27 | ObserveRegistry preselecionado sem medir | P3 | evaluator golden + hop census | `PHASE-3-DECISION.md` | `refactor|not_applicable_evidence` justificado |
| R28 | S6/S7/S8 sem owner/call-site/prova precisa | P2 | spine critical coverage test | `PHASE-2-RECEIPT.md` | S1–S8 inclui symbol/owner/applicability/test/result |
| R29 | One-shot e 24/7 sustained fundidos | P4/Ops | one-shot artifact + heartbeat series separados | `PHASE-4-COMPLETE.md` | Claims/status nunca se promovem entre níveis |
| R30 | Dirty main sem failure-set protocol | Todas | before/after failure list + cached-path audit | todo phase receipt | §54 preenchido e nenhuma falha nova da lane |
| R31 | Archive DONE aparecia como trabalho potencial | Plano/P4 | archive hold test + empty archive diff | `PHASE-4-COMPLETE.md` | Archive read/guard-only, zero touched file |
| R32 | CODEMAP linha “Source connector governance” aponta FQCN legado `App\Services\Ai\Aaeos\Support\AtlasSourceConnectorsAndCaptureService` (só vivo via alias map pair #40) | P3 docs | alias retirement doc assertion | `PHASE-3-RECEIPT.md` | Canonical `…\AutonomousEvolution\Brain\AtlasSourceConnectorsAndCaptureService` documentada; legacy só sai após proof |
| R33 | `brainNextArgs` shape inválido vs `AtlasBrainNextCommand` | P1 | unit test Artisan params | `PHASE-1-RECEIPT.md` | call usa `scope` posicional + default `autonomous` + `--json`; zero `--scope` flag |
| R34 | exit_code-only success classification | P1 | payload status matrix | `PHASE-1-RECEIPT.md` | mutated só se status de sucesso real documentado |
| R35 | `max_seeds` / `--max` inventado | P1 | seed call inspection | `PHASE-1-RECEIPT.md` | flags = subset do signature real de `atlas:brain:seed` |
| R36 | P4 env (memória, master switch, scope) | P4 | preflight §60 | `PHASE-4-COMPLETE.md` | preflight pass ou blocked_ops nomeado |
| R37 | run≠cycle em flags/exit/defaults | P0 | §59 parity tests | `PHASE-0-RECEIPT.md` | path único `AaeosRunApplication` |

Nenhum R16+ cria segundo plano. O owner continua este MASTER; execução registra o fechamento em §30 + LEDGER + phase receipt.

## 47. Taxonomia obrigatória de prova e linguagem

| Nível | Significado | Evidência mínima | Pode dizer |
|---|---|---|---|
| `PLANNED` | Só existe contrato no MASTER | path + task + teste esperado | “planejado” |
| `SOURCE_WIRED` | Código contém o caminho | symbol/path + source inspection | “wire existe em fonte” |
| `AUTOMATED_CHARACTERIZED` | Teste automatizado prova comportamento isolado | test name + green receipt | “caracterizado automaticamente” |
| `DRY_RECEIPT` | Command/runtime compôs receipt sem efeito live | receipt com `dry_run=true` | “simulado/dry” |
| `LIVE_EFFECT` | Processo local executou efeito e retornou sucesso | receipt effect + exit code + timestamp | “efeito live local provado” |
| `REAL_OPERATION` | Efeito produziu artefato/ledger downstream verificável em fresh read | effect receipt + durable artifact + replay/readback | “operação real provada” |
| `SUSTAINED` | Repetição temporal/SLA sem intervenção | série com janela, sample e failure rate | “operação sustentada” |

Regras:

1. Um nível nunca implica automaticamente o próximo.
2. `certify ok` atual = `AUTOMATED_CHARACTERIZED` de invariantes estruturais; não é `REAL_OPERATION`.
3. R4 atual = `SOURCE_WIRED`; R33 = call **não** sobe a `LIVE_EFFECT` até args válidos; evidence predecessor = `DRY_RECEIPT` com `runtime_write_performed=true` mentiroso; P4 busca `LIVE_EFFECT` + readback **após** R33/R34.
4. Archive = `VERIFIED_DISK` + scan de import; é DONE como movimentação, mas o guard é contínuo.
5. O programa nesta rodada = `PLANNED` (docs); v3/v4/v5 **não** autorizam `EXECUTE` implícito.

## 48. Arquitetura e ownership v3

```text
CLI run ─┐
         ├─ AaeosRunRequest (pure, typed caps)
CLI cycle┘
           └─ AaeosRunApplication (same orchestration for both names)
                ├─ AaeosCycleRuntime (sole control-plane mutator)
                ├─ AaeosModeExecutor[dev|forge|autonomos]
                │    └─ existing Kernel/Forge/Brain ports (no copied muscle)
                ├─ AtlasEvidenceLedger (single durable event truth)
                └─ cycle receipt with side-effect inventory

AtlasEvidenceLedger (read-only bounded query)
  └─ AaeosLedgerMeasurementReader
       └─ AaeosMeasurementSnapshot (pure DTO)
            └─ AaeosScorecardProjector (views; zero writes)
                 ├─ scorecard CLI
                 ├─ structural certify
                 └─ cockpit
```

| Unit | Owns | Must not own |
|---|---|---|
| `AaeosRunRequest` | validated intent/caps | I/O, Artisan, scoring |
| `AaeosRunApplication` | one shared command use-case | mode muscle, DB queries |
| `AaeosCycleRuntime` | cycle ordering + receipt assembly + ledger attempt | provider implementation, score projection |
| `AaeosModeExecutor` | delegate one mode and classify effects | second Brain/Task/Kernel/evidence pipeline |
| `AaeosLedgerMeasurementReader` | bounded read/dedupe/provenance | writes, business scoring |
| `AaeosScorecardProjector` | pure formulas + hard gate aggregation | hints from certify, persistence |
| `AtlasAaeosCertifyCommand` | present certification scopes/checks | invent numbers |

Dependency direction is one-way: Commands → Application → Control/Executors → existing ports. Measurement reads Evidence; execution never depends on the scorecard.

## 49. Scorecard measured e hard gates (normativo)

### 49.1 Dimension result

```json
{
  "id": "live_autonomos_brain_next",
  "status": "measured",
  "value": 1.0,
  "unit": "ratio",
  "numerator": 1,
  "denominator": 1,
  "sample_size": 1,
  "min_sample": 1,
  "window": {"from": "2026-07-23T00:00:00Z", "to": "2026-07-24T00:00:00Z"},
  "source": "atlas_ledger_events:AAEOS_CYCLE_RECORDED",
  "proof_level": "LIVE_EFFECT",
  "failure_reason": null
}
```

Allowed `status`: `measured|unknown|not_applicable|assessment_only|failed`. `unknown` always uses `value:null`; it never receives a floor number.

### 49.2 Dimensions and denominators

| Dimension | Source | Denominator / minimum | Hard gate |
|---|---|---|---|
| `aaeos_tree_pure` | filesystem scan | all live PHP paths | true |
| `quarantine_imports_zero` | production import scan | all `app/**/*.php` | true |
| `archive_hold` | archive census | physical archive exists; no touched paths | true |
| `daily_port_single` | Artisan registry + router/alias test | run canonical; cycle deprecated alias | true |
| `caps_honesty` | behavior matrix tests | every row §50 | true |
| `mode_smoke_coverage` | phase receipts | one non-dry receipt per mode | 3/3; each honest effect level |
| `live_autonomos_brain_next` | ledger + captured receipt | ≥1 live effect com args+payload válidos (R33/R34) | success ratio 1.0; disabled/dry/error contam como fail |
| `dev_kernel_port` | receipt + kernel adapter test | ≥1 P2 effect | mutated success or exact blocked capability keeps program not-DONE |
| `forge_kernel_port` | receipt + kernel adapter test | ≥1 P2 effect | same |
| `spine_critical_coverage` | S1–S8 evidence matrix | all applicable sites | 100%; N/A requires owner evidence, not waiver |
| `dualcore_recorded_rate_7d` | ledger events | publish rate only at N≥5 | unknown below N; no hard threshold until sustained claim |
| `live_dispatch_success_rate_7d` | ledger events by mode | publish per-mode rate only at N≥5 | diagnostic for MT; hard gate for SUSTAINED only |
| `learning_safety` | halt test + learning events | ≥1 halt case; all events | auto-promote violations = 0 |
| `evidence_health` | DB/table/readback | current configured runtime | available for REAL_OPERATION claim |
| `operator_cognitive_load` | no objective feed today | none | assessment-only; excluded |
| `thesis_clarity` | human review | none | assessment-only; excluded |

### 49.3 Composite and certification scopes

- `legacy_composite`: compatibility only; clearly labeled `assessment_only`.
- `measured_composite`: mean only of required numeric dimensions with sufficient sample; if any required dimension unknown/failed, `null`.
- `structural_certified`: structural checks only; preserves predecessor semantics.
- `elite_deepening_done`: conjunction of every §5/§43 hard gate; never `composite >= x`.
- `sustained_operate_certified`: separate future status; requires per-mode N≥5/7d plus heartbeat/SLA evidence.

Hard vetoes regardless of any score: Quarantine import >0, foreign live path, `elite_same_bar=false`, provider call without cap, auto-promote learning, parallel evidence truth, dry-run write, missing failure_reason on failure, live effect claimed from pack/intake, or required proof unknown.

## 50. Receipt truth contract e caps matrix

### 50.1 Additive fields

```json
{
  "cycle_id": "01...ULID",
  "effect_level": "none|prepared|mutated|provider_executed|blocked",
  "plan_only": true,
  "caps": {
    "live": false,
    "execute_provider": false,
    "max_seeds": 0,
    "run_worker_once": false,
    "scope": null
  },
  "side_effects": {
    "muscle_invoked": false,
    "control_plane_writes": [],
    "muscle_writes": [],
    "provider_calls": 0,
    "runtime_write_performed": false
  },
  "proof_level": "DRY_RECEIPT",
  "failure_reason": null
}
```

Legacy keys remain during v1 additive window, but presenters and scorecards read the new truth fields first.

### 50.2 Behavior matrix

| Input | Muscle effect | Control writes | Required result |
|---|---|---|---|
| `--dry-run` | none | none, including learning | `plan_only=true`, `runtime_write_performed=false`, `skipped_dry_run` |
| default, no `--live` | none | DualCore/cycle ledger attempts allowed and enumerated | `effect_level=none`; never “músculo executado” |
| `--live --mode=dev` before P2 port | pack only | receipt/ledger | `effect_level=prepared` |
| `--live --mode=forge` before P2 port | intake+stamp only | receipt/ledger | `effect_level=prepared` |
| `--live --autonomos --max-seeds=0` | `brain:next` com `scope` posicional | receipt/ledger | mutated only se exit 0 **e** payload.status sucesso real; R33 fix pré-requisito |
| `--live --autonomos --max-seeds=1` | brain next + seed (flags reais do seed) | receipt/ledger | each effect independent; R35: sem `--max` inventado |
| `--live --autonomos --run-worker-once` | brain next + `atlas:task next` | receipt/ledger | worker effect separate; no hidden provider count |
| `--live --autonomos` **hoje (pré-R33)** | call falha args | receipt/ledger | `dispatch_failed` / `brain_next_failed` esperado; **não** mutated |
| `--execute-provider` without `--live` | none | none | exit 2, `invalid_cap_combination` |
| `--max-seeds>0` outside Autônomos | none | none | exit 2, `max_seeds_requires_autonomos` |
| `--run-worker-once` outside live Autônomos | none | none | exit 2, `worker_once_requires_live_autonomos` |
| irreversible intent under any flags | none | halt/learning only when not dry | `halt_sovereign`; dry version remains write-free |

Effect outputs cross a provider-safe boundary: command excerpts/errors are redacted and bounded before entering receipt/evidence; raw secrets, prompts or provider internals never land in MASTER/LEDGER.

## 51. Compatibilidade `run` / `cycle` e UX terminal-first

| Contract | `run` | `cycle` alias |
|---|---|---|
| Application service | `AaeosRunApplication` | same instance/path |
| Supported flags | full §23.1 set | same set during deprecation window |
| Default intent | `aaeos_daily_cycle` | legacy `aaeos_default_cycle` allowed only when omitted |
| JSON keys | canonical v1 additive | identical set |
| JSON stderr/stdout | receipt only | receipt only; no warning in JSON |
| Human output | canonical presenter | deprecation warning + same presenter |
| Exit 0 | plan/prepared/success | same |
| Exit 1 | halted/dispatch_failed | same (**hoje** cycle só trata `halted` — bug R37) |
| Exit 2 | invalid flag/cap combination | same |

Router help lists `run|scorecard|certify|cockpit`; `cycle` appears only under deprecated aliases. No TUI, desktop shell or inline editor entra nesta obra.

**Disk truth atual (pré-P0) — ver tabela completa §59:** `cycle` **não** expõe `--mode`, `--execute-provider`, `--run-worker-once`, `--scope`; default intent `aaeos_default_cycle` vs `aaeos_daily_cycle`; source hint `cli` vs `atlas_aaeos_run`; `--autonomos` no cycle usa `runAutonomosCycle`, no run força `world.force_mode`.

## 52. Contratos exatos de fase (override de ambiguidades anteriores)

**v5 RSS:** §52.1–§52.5 **permanecem** a autoridade de paths/tests por fase. P0 também remove/neutraliza `human_in_engineering_loop` como identidade (R38) e tipa admission (R40) **sem** expandir file freeze além do necessário. P1 inclui R33–R35 + brain application services. **Sem** P5/P6.

### 52.1 P0 — honesty + measurement + alias

**Allowed production files (paths exatos):**

```text
app/Console/Commands/AtlasAaeosRunCommand.php
app/Console/Commands/AtlasAaeosCycleCommand.php
app/Console/Commands/AtlasAaeosRouterCommand.php
app/Console/Commands/AtlasAaeosCertifyCommand.php
app/Console/Commands/AtlasAaeosScorecardCommand.php
app/Services/Ai/Aaeos/Control/AaeosRunRequest.php
app/Services/Ai/Aaeos/Control/AaeosRunApplication.php
app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php
app/Services/Ai/Aaeos/Control/AaeosCycleOutcomeRecorder.php
app/Services/Ai/Aaeos/Control/AaeosScorecardProjector.php
app/Services/Ai/Aaeos/Control/AaeosOperateScorecardProjector.php
app/Services/Ai/Aaeos/Control/Measurement/AaeosLedgerMeasurementReader.php
app/Services/Ai/Aaeos/Control/Measurement/AaeosMeasurementSnapshot.php
```

**Red first:** new tests §53 must fail on fake constants, dry-run write truth, invalid caps, sample zero and alias parity.
**Green exit:** §50 matrix green; certify emits both scopes; measured composite null with zero sample; no counter file/table/migration.
**Commit:** `feat(core): AAEOS-MT P0 honest receipts measured evidence and run alias`.

### 52.2 P1 — ModeExecutor parity

**Allowed production paths:** `app/Services/Ai/Aaeos/Control/AaeosCycleRuntime.php`, `app/Services/Ai/Aaeos/Control/Dispatch/AaeosLiveDispatchGateway.php`, the three new executor paths in §24, the four files in `Control/Adapters/` and four interface/implementation files in `Control/Dispatch/` listed in P1.1 only for same-slice deletion, and `app/Console/Commands/AtlasCliCockpitCommand.php`.
**Red first:** characterization snapshots for Dev pack, Forge stamped intake; Autônomos tests that **fail** on current `brainNextArgs` (R33) and on exit-only success (R34).
**Green exit:** one executor per mode; eight old adapter/dispatcher/interface files removed; R4 path + **R33/R34/R35 closed**; Dev/Forge prepared; no provider burn.
**Commit:** `refactor(core): AAEOS-MT P1 fuse mode executors with effect parity`.

### 52.3 P2 — existing ports + qualified axes

**Allowed production paths:** the Dev/Forge executor paths in §24; `app/Services/Ai/Programming/AtlasDev/Execution/EliteExecutorKernelDevAdapter.php`; `app/Services/Ai/Programming/Forge/ForgeEliteKernelExecutionAdapter.php`; `app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php`; `app/Services/Ai/Programming/AtlasCodeForgeWorkIntakeService.php`; `app/Services/Ai/Programming/AtlasDev/Execution/AtlasDevExecutionService.php`; `app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopExecutor.php`; `app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php`; `app/Services/Ai/EngineeringKernel/EliteExecutionAxis.php`; `app/Services/Ai/EngineeringKernel/EliteLevelReference.php`; `app/Services/Ai/Aaeos/Control/AaeosDifficultyClassifier.php`; `app/Services/Ai/Aaeos/Control/AaeosDifficultyLevel.php`.
**Red first:** Kernel port caps, S1–S8 coverage, axis cross-conversion refusal.
**Green exit:** Dev/Forge receipt points to real port effect or exact blocker; all applicable spine sites evidenced; no copied Kernel; axis-qualified level refs additive.
**Commit:** `feat(core): AAEOS-MT P2 wire kernel ports spine evidence and qualified axes`.

### 52.4 P3 — evidence-led compaction + alias retirement

**Allowed production files:** AEOS evaluator/delegate/sections only after decision receipt; `app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php` (40 pairs em `CLASS_MAP` — contagem disco revalidada); exact consumers from that map; `app/Services/Ai/CODEMAP.md` + docs vocabulary.
**Red first:** golden façade parity, 40 canonical-resolution/legacy-nonresolution cases, CODEMAP path test.
**Green exit:** Observe decision has evidence; no godfile recreation; alias map removed only at 40/40; current canonical SourceConnectors path documented.
**Commit:** `refactor(core): AAEOS-MT P3 evidence-led observe and legacy alias retirement`.

### 52.5 P4 — real proof + freeze

**Allowed changes:** evidence/docs + `.github/workflows/quality.yml` para o certify explícito. Falha descoberta pelo gauntlet exige fix slice separado e reentrada em P0–P3; não hotfixar silenciosamente dentro de P4.
**Entry:** P0–P3 phase receipts green **e** R33/R34 closed (sem isto o gauntlet só reproduz `brain_next_failed`).
**Exit:** one-shot live receipt com payload sucesso real + downstream readback, three-mode smoke, hard gates true, archive untouched, preflight §60 preenchido.
**Commit:** `docs(core): AAEOS-MT P4 real gauntlet and stable v2 freeze`.

## 53. Test catalog — nomes e intenção exatos

| New/updated test | Required cases |
|---|---|
| `tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php` | all §50 rows; JSON cleanliness; exit codes |
| `tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php` | parity with run; deprecation only human mode |
| `tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php` | side-effect inventory; dry zero-write; precise failure_reason |
| `tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php` | no numeric hints/constants; sample zero unknown; provenance and hard vetoes |
| `tests/Feature/Ai/Aaeos/AaeosLedgerMeasurementReaderTest.php` | bounded window, cycle_id dedupe, mode/effect aggregation, DB unavailable |
| `tests/Unit/Ai/Aaeos/Control/Executors/AutonomosModeExecutorTest.php` | brain next once; **R33** posicional `scope`+default; **R34** disabled/dry not mutated; **R35** seed flags realistas; redacted effects |
| `tests/Unit/Ai/Aaeos/Control/Executors/DevModeExecutorTest.php` | pack=prepared; Kernel effect=mutated only after P2 |
| `tests/Unit/Ai/Aaeos/Control/Executors/ForgeModeExecutorTest.php` | intake stamp=prepared; Kernel effect classification |
| `tests/Unit/Ai/EngineeringKernel/EliteLevelReferenceTest.php` | axis qualification; invalid/cross-axis rejection |
| `tests/Feature/Ai/Aaeos/AaeosSpineCriticalCoverageTest.php` | S1–S8 applicable owners and no parallel ledger |
| `tests/Unit/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorGoldenTest.php` | public method/output parity before/after P3 |
| `tests/Feature/Ai/Aaeos/AaeosLegacyAliasRetirementTest.php` | 40 canonical classes resolve; 40 legacy names do not |
| `tests/Feature/Ai/Aaeos/AaeosArchiveHoldTest.php` | archive exists, imports zero, live tree pure |

Phase command baseline:

```bash
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Aaeos/Control tests/Feature/Ai/Aaeos --no-coverage
/opt/homebrew/bin/php artisan test tests/Feature/Ai/EngineeringKernel/EliteExecutorKernelReadOnlyVerticalTest.php --no-coverage
/opt/homebrew/bin/php artisan test tests/Unit/Ai/AgenticEngineeringOs/AtlasUniversalGatesEvaluatorGoldenTest.php --no-coverage
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json
/opt/homebrew/bin/php artisan atlas:aaeos:scorecard --json
```

Expected before each implementation slice: new red test fails for the named missing behavior, not for bootstrap/DB noise. Expected after: lane tests green and no new failures relative to recorded baseline.

## 54. Dirty-main e baseline-relative verification

1. Confirmar `git branch --show-current` = `main`; abortar `MERGE_IN_PROGRESS`.
2. Registrar `git status --short` no phase receipt e separar WIP preexistente de arquivos do slice.
3. Claim no blackboard para cada arquivo compartilhado; conflict = coordenar, nunca roubar.
4. Rodar lane tests antes do primeiro edit e salvar conjunto exato de failures.
5. Depois do edit, comparar conjuntos: toda falha nova da lane bloqueia o slice; falha concorrente/preexistente fica nomeada e não é apagada.
6. Nunca reverter, formatar ou stagear WIP alheio.
7. Stage só paths do slice: `git add -- path1 path2`; conferir `git diff --cached --name-only`.
8. `git add -A`, stash, branch, merge e pull-merge continuam proibidos.
9. Commit não equivale a phase DONE: gates + receipt + ledger + scoreboard precisam concordar.

## 55. Evidence receipt e failure contract

Cada `PHASE-N-RECEIPT.md` deve preencher, sem campos vazios:

```yaml
phase: P0
status: passed|failed|blocked|partial
branch: main
head_before: git_sha
commit: git_sha_or_none_with_reason
proof_level: AUTOMATED_CHARACTERIZED
files_touched: [exact/paths]
baseline_failures: [exact_test_or_none]
final_failures: [exact_test_or_none]
commands:
  - command: exact command
    exit_code: 0
    result_hash: sha256
claims:
  - id: R16
    state: closed|open|blocked
    evidence_refs: [path_or_ledger_event]
debts:
  - id: none
    failure_reason: none
```

Rules:

- `status=passed` exige `final_failures=[]` para lane required tests.
- `blocked|partial|failed` exige `failure_reason` preciso; nunca “test issue” genérico.
- Receipt live referencia artifact/hash; não cola segredo, prompt bruto ou output não redigido.
- Contradição entre receipt, scorecard e test bloqueia a promoção de fase.
- `commit=none` é válido para P4 proof sem patch; não transforma ausência de mudança em ausência de prova.

## 56. Quarantine archive hold (R12 normativo)

O archive já está concluído. P0–P4 não movem, renomeiam, editam nem “melhoram” seus 306 PHP.

```bash
test -d archive/app/Services/Ai/Aaeos/Quarantine
find archive/app/Services/Ai/Aaeos/Quarantine -type f -name '*.php' | wc -l
rg -n -F 'App\Services\Ai\Aaeos\Quarantine\' app --glob '*.php'
/opt/homebrew/bin/php artisan atlas:aaeos:certify --json
git diff --name-only -- archive/app/Services/Ai/Aaeos/Quarantine
```

Aceite: directory existe; census permanece explicável; import produtivo 0; diff archive vazio. Mudança intencional futura no archive exige obra própria e autorização explícita, nunca “faxina” incidental deste MT.

## 57. Autocrítica obrigatória antes de declarar o plano/execution slice fechado

Checklist **reexecutável a cada rodada** (v5 inclui a correção semântica e arquitetural que v4 ainda omitia):

- [x] **Spec coverage:** C1–C4 / R1–R50 apontam phase + test + receipt (§30 + §46 + §74 + §82).
- [x] **Placeholder scan:** zero `TBD`/`TODO`/“if cheap” em passos executáveis (re-scan §57).
- [x] **Type consistency:** effect levels / proof levels / score statuses uniformes.
- [x] **Fact regression:** R4 = SOURCE_WIRED; **R33 OPEN** (args); archive DONE/HOLD; certify hints 9.2/9.2/9.0 e standalone defaults 9.0/9.0/9.0 + control 9.2 classificados.
- [x] **Anti-Goodhart:** hard gates > composite; §42 sem “counter store”.
- [x] **No silent break:** failure_reason obrigatório; contradição para promoção.
- [x] **No parallel truth:** ledger único (§34); zero counter JSON.
- [x] **Terminal-first:** sem casca.
- [x] **Adversarial disk:** CLI matrix §59, brain contract §60, dimensions §61, CODEMAP §62 revalidados nesta rodada.
- [x] **Human-out-of-engineering-loop:** campo abolido no target contract; current writers/tests enumerados em R38/P0.
- [x] **No architecture sprawl:** existing ExecutionOrder/Kernel/Courts/Ledger/Rivals reutilizados; zero novo OS/Judgment Plane.
- [ ] **Execution slice WIP safety / `git diff --check`:** só quando houver código; plan-only = N/A com nota no LEDGER.

Scan reexecutável:

```bash
awk '/^## 57\./{exit} {print}' docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md | rg -n '\bT[B]D\b|\bT[O]DO\b|if cheap|se flag existir|sugestão path|ou evoluir|locate call-site|counter store'
awk '/^## 57\./{exit} {print}' docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md | rg -n 'byte-for-behavior|live receipt OR blocked_ops|composite.*>=.*DONE'
# R33 still open until P1:
rg -n "brainNextArgs|'--scope'" app/Services/Ai/Aaeos/Control/Dispatch/AutonomosLiveDispatcher.php
git diff --check -- docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md docs/evidence/2026-07-23-aaeos-elite-deepening/LEDGER.md docs/evidence/2026-07-23-aaeos-elite-deepening/SCOREBOARD.md
```

## 58. Handoff e autorização

Estado ao fechar **esta** rodada documental (**v5 RSS**):

- MASTER = v4 forense + **RSS §69–§76** (anti-duplicação; mega Part IV removida).
- R33–R37 + R38–R46 no plano; R47–R50 = horizonte irmão, **não** P5/P6.
- P0–P4 `not_started`; **zero código de produção**.
- Structural certify ~9.5 **não** certifica Elite Deepening.
- Próxima mutação de código **só** com **`EXECUTE P0`**.

Ao receber `EXECUTE P0`: **somente** §52.1 + §53–§55 + §59 + §66 + R38/R40 labels se couberem no freeze; **não** antecipar P1/R33 fix salvo red characterization. Sem essa frase: docs only.

---

## 59. CLI disk-truth matrix (`run` vs `cycle`) — revalidado

Fonte: `AtlasAaeosRunCommand` + `AtlasAaeosCycleCommand` no `main` local (pré-P0).

| Aspecto | `atlas:aaeos:run` | `atlas:aaeos:cycle` | P0 target |
|---|---|---|---|
| Default intent | `aaeos_daily_cycle` | `aaeos_default_cycle` | documentar + shared request; cycle may keep legacy default when intent omitted |
| `--mode=` | yes | **no** | cycle gains via shared app **or** documents unsupported→exit 2 |
| `--execute-provider` | yes | **no** | same set |
| `--run-worker-once` | yes | **no** | same set |
| `--scope=` | yes (→ hints.scope) | **no** | same set; feeds **positional** brain scope after R33 |
| `--autonomos` path | `runCycle` + `force_mode=autonomos` | `runAutonomosCycle` (adds `queue_default`+force) | same application semantics; prefer one |
| source hint | `atlas_aaeos_run` | `cli` | optional unify to `atlas_aaeos_run` / `atlas_aaeos_cycle_alias` |
| Exit on `dispatch_failed` | FAILURE (1) | **SUCCESS (0)** today | **must align** (both 1) |
| Exit on `halted` | 1 | 1 | keep |
| OutcomeRecorder always called | yes, even dry | yes, even dry | P0: dry must not write; recorder skip when dry |
| `runtime_write_performed` in runtime | **hardcoded `true`** (`AaeosCycleRuntime` receipt) | same | derive truth (§50) |

## 60. Contrato canônico Brain / Seed / Task (Autônomos muscle boundary)

### 60.1 `atlas:brain:next` (R33/R34)

| Campo | Valor no disco |
|---|---|
| Signature | `{scope}` **required positional** + `--repo --docs* --m --max-prior --actor --scope-signals --json` |
| **Não existe** | opção `--scope` |
| Handle fallback | `trim(argument('scope')) ?: 'autonomous'` — só se o arg for string vazia; **não** dispensa o arg |
| Master switch off | `emit(['status'=>'disabled',...])` com **SUCCESS** default |
| Dry probe | `status=dry`, SUCCESS |
| Served | `status` served-equivalent no payload (journal append) |
| **Args corretos (P1 target)** | `Artisan::call('atlas:brain:next', ['scope' => $scope ?: 'autonomous', '--json' => true])` |
| **Args errados (hoje)** | `['--json'=>true]` ± `['--scope'=>$scope]` → exception / missing scope |

**Classificação de efeito (normativo R34):**

| exit_code | payload.status (JSON) | effect_level | note |
|---:|---|---|---|
| ≠0 | any / parse fail | `blocked` or none + failure | `brain_next_failed` |
| 0 | `disabled` | `blocked` | master off — **não** mutated |
| 0 | `dry` | `prepared` or blocked_ops | scope dry — não mutated |
| 0 | `error` | `blocked` | |
| 0 | `served` / `already_done` / success documented | `mutated` | only these count for hard gate |
| 0 | unknown | `unknown` → fail hard gate | never invent |

### 60.2 `atlas:brain:seed` (R35)

| Campo | Disco |
|---|---|
| Flags reais | `--specs --scope --actor --require-actor --cleanup-specs --dry-run --no-heartbeat --json` |
| **Não existe** | `--max` |
| Comportamento atual dispatcher | tenta `--max`, falha, retry bare `--json` |
| P1 | chamar só flags reais; se `max_seeds>0`, effect note `seed_batch_cap_not_supported_by_cli` + still one seed invoke; **não** inventar semântica |

### 60.3 `atlas:task next`

| Campo | Disco |
|---|---|
| Signature | `atlas:task {action : next\|report\|…}` |
| Call atual | `Artisan::call('atlas:task', ['action'=>'next', '--json'=>true])` — **válido** |
| Cap | só com `--run-worker-once` + live autonomos |

### 60.4 P4 preflight (R36)

```bash
# 1) branch + no merge
git branch --show-current   # main
# 2) R33 must be green in tests
# 3) memory headroom for brain comprehension (OOM 128MB observed) — raise php memory_limit for the one-shot or document blocked_ops
# 4) choose scope (default autonomous); confirm registry resolves
php artisan atlas:brain:next autonomous --json   # operator-approved one-shot; may write docs/ledger
# 5) optional: fleet autonomos on only if sustained claim desired (not required for one-shot DONE)
php artisan atlas:agents:status
```

One-shot P4 **não** exige fleet Autônomos 24/7 ON; exige um receipt AAEOS `--live --autonomos` com effect classificado `mutated` de verdade.

## 61. Dimension provenance table (live revalidation)

Revalidado via `atlas:aaeos:scorecard --json` e `atlas:aaeos:certify --json` nesta rodada plan:

| Dimension | Standalone scorecard | Certify inject/hint | Scan-backed? | P0 fate |
|---|---:|---:|---|---|
| `thesis_clarity` | 9.5 const | (via projector) | no | `assessment_only` / exclude measured |
| `elite_same_bar` | 9.5 const | | structural intent | hard boolean gate, not 9.5 |
| `control_plane` | **9.2 const** | | no | remove numeric fantasy |
| `operate_path_wiring` | **9.0 default** | **9.2 inject** | no | measured or unknown |
| `spine_enforced` | **9.0 default** | **9.2 inject** | no | measured S1–S8 coverage |
| `antifragile_loop` | **9.0 default** | **9.0 inject** | no | learning safety events |
| `quarantine_clean` | 10 if imports=0 | | **yes** | keep as gate |
| `density_live` / `aaeos_tree_pure` | 10 if pure | | **yes** | keep as gate |
| `orphan_generated_tests_clean` | 10 if 0 | | **yes** | keep as gate |
| `counters.cycles_total` | **0** | | echo only | ledger reader |
| composite | **9.52** | **9.56** | mix | `legacy_assessment` only |
| `god_sota` | **bool true** | | composite≥9 + purity | structural scope only |

**Prova de inject (source):** `AtlasAaeosCertifyCommand` keys `operate_path_wiring=>9.2`, `spine_enforced=>9.2`, `antifragile_loop=>9.0`.

## 62. CODEMAP + alias map disk anchors (R32 / R9)

| Item | Path / fact |
|---|---|
| CODEMAP file | `app/Services/Ai/CODEMAP.md` (banner: intentionally incomplete) |
| Daily cycle entry | `AaeosCycleRuntime::runCycle` — OK |
| Source connectors row | legado `App\Services\Ai\Aaeos\Support\AtlasSourceConnectorsAndCaptureService` |
| Canonical via CLASS_MAP | `App\Services\Ai\AutonomousEvolution\Brain\AtlasSourceConnectorsAndCaptureService` |
| Alias registrar | `app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php` — **40** pairs; `register()` at file bottom |
| P3 burn proof | 40 canonical resolve + 40 legacy non-resolve **after** map removal; Composer dump-autoload |

## 63. DI / registration (sem AppServiceProvider magic)

| Component | Binding atual | P0/P1 rule |
|---|---|---|
| `AaeosCycleRuntime` | constructor DI (adapters map + live gateway) | deepen; no service-locator new |
| Live dispatchers | concrete classes resolved by gateway/runtime | P1: ModeExecutors map mode→executor |
| Scorecard projector | `new` defaults in ctor for org/spine | measured reader injected; null ledger → unknown |
| OutcomeRecorder | optional ledger; `app()` fallback | dry-run **must not** call ledger write |
| New classes | `AaeosRunRequest`, `AaeosRunApplication`, `Measurement/*`, `Executors/*` | pure PSR-4; Laravel auto-wire; **no** new Provider unless interface binding required |
| Forbidden | second container binding that forks muscle Brain/Task/Kernel | |

## 64. Dry-run write surface inventory (P0 honesty target)

| Call site | Today | P0 required |
|---|---|---|
| `AaeosCycleRuntime` sets `runtime_write_performed=true` always | lie | derive from actual writes |
| `recordEvidence` skipped when `$dryRun` | OK path | keep; surface `skipped_dry_run` |
| `AtlasAaeosRunCommand` → `$outcomes->record($receipt)` always | may write learning on halt even dry | if dry: force `learning.status=skipped_dry_run` and **zero** ledger calls |
| `AtlasAaeosCycleCommand` same | same | same via shared application |
| Predecessor dry JSON | `rwp=true` | prove new dry receipts flip to false |

## 65. Schema / additive contract policy

| Schema | Bump rule |
|---|---|
| `atlas.aaeos.cycle_receipt.v1` (runtime SCHEMA) | additive fields (§50) without version bump **only** if old keys preserved; document in receipt `schema_features: ['effect_level','side_effects',…]` |
| Breaking rename/remove | bump to `v2` + dual-read one phase max |
| Scorecard `atlas.aaeos.scorecard.v1` | add `measured` block; keep `dimensions` legacy labeled `assessment_only` until consumers migrate |
| Learning `atlas.aaeos.learning_candidate.v1` | no auto_promote ever; status enum stable |
| Evidence event types | reuse `AaeosCycleRecorded` / learning types; no parallel event store |

## 66. P0 allowed paths freeze (production + tests)

**v5 RSS:** freeze = §52.1 production + tests abaixo. R38/R40 em P0 **somente** se couberem nestes paths (Admission/Runtime/Certify/Adapters já listáveis em §52.1 ou adição mínima documentada no PHASE-0-RECEIPT). **Proibido** antecipar P1+ paths. Canon elite executors = docs slice separado se necessário.

**Production (exact):** §52.1 list (+ no máximo Admission/Runtime/Certify/Adapter writers se R38 exigir no mesmo slice — listar no receipt).

**Tests allowed in P0:**

```text
tests/Feature/Ai/Aaeos/AtlasAaeosRunCommandTest.php
tests/Feature/Ai/Aaeos/AtlasAaeosCycleCommandTest.php
tests/Unit/Ai/Aaeos/Control/AaeosReceiptHonestyTest.php
tests/Unit/Ai/Aaeos/Control/AaeosMeasuredScorecardTest.php
tests/Feature/Ai/Aaeos/AaeosLedgerMeasurementReaderTest.php
tests/Feature/Ai/Aaeos/AaeosGodSotaCertificationTest.php   # update asserts: no 9.2 inject
tests/Unit/Ai/Aaeos/Control/AaeosControlPlaneTest.php      # only if receipt shape forces
tests/Unit/Ai/Aaeos/Control/AaeosOperateDispatchTest.php    # only if honesty fields force
```

**Docs/evidence allowed:** `docs/evidence/2026-07-23-aaeos-elite-deepening/**`, this MASTER, vocabulary only if scorecard semantics require one paragraph.

**P0 must NOT fix R33** unless a failing honesty test forces a one-line characterization double — prefer red characterization of failure; **fix lands in P1**.

## 67. Predecessor evidence census (R5)

| Artifact | Path | Fact |
|---|---|---|
| Dev dry | `docs/evidence/2026-07-23-aaeos-operate/REAL-RUN-RECEIPTS/R1-dev-dry.json` | dry=true, live plan_only, effects=[], rwp=true |
| Forge dry | `…/R2-forge-dry.json` | same pattern |
| Autônomos dry | `…/R3-autonomos-dry.json` | same; **no** brain_next effect |
| Elite Deepening pack | `docs/evidence/2026-07-23-aaeos-elite-deepening/` | LEDGER+SCOREBOARD only; no PHASE receipts yet |

## 68. Anti-“already done” protocol (pétreo para agentes)

1. Linhas / scorecard ~9.5 / certify ok / §44 `[x]` / v2 / v3 **nunca** significam Elite Deepening DONE.
2. Se achar “o plano já está absoluto”, rode: §45 revalidation commands + R33 `rg brainNextArgs` + empty PHASE receipts → ainda `PLAN_ONLY`.
3. Novo buraco ⇒ novo `R##` neste MASTER + LEDGER; **proibido** segundo plano-mestre.
4. Código só após `EXECUTE P0` literal do operador.
5. Após cada fase: hard gates §49 + receipt §55 + SCOREBOARD; composite diagnóstico opcional.

---

# PARTE IV — RSS v5 (Razor Sovereign Spine)

> **Nome:** Razor Sovereign Spine (RSS)  
> **Status:** PLAN-ONLY · lei + ownership + residuals constitutivos  
> **Anti-duplicação (pétreo):** esta Parte **não** reescreve P0–P4, matrices de teste, CLI disk-truth, scorecard measured, proof taxonomy nem file freezes. Em conflito de *execução de fase*, **§9 + §52 + §53 + §59–§66 vencem**. Em conflito de *lei de executores / soberania / anti-órgão-novo*, **esta Parte IV vence** e emenda Partes I–III.  
> **Proibido:** segundo plano-mestre · Mission Runtime / MissionReceipt novos · WorkGraph-as-OS · Evaluation Foundry dentro do DONE P4 · reanimar Quarantine/ACDE · copiar catálogos de fase de novo.

## 69. Anti-mapa: o que RSS **não** inventa (aponta o que já existe)

| Conceito RSS / Codex | Owner / seção **já no MASTER ou disco** | Ação |
|---|---|---|
| Proof levels planned→sustained | **§47** | reusar; não redefinir enum |
| Receipt honesty / effect_level | **§50 + §64** | reusar |
| Measured scorecard / hard gates | **§49** | reusar |
| CLI run≠cycle / R37 | **§51 + §59** | reusar |
| brain:next args R33–R35 | **§60 + R33–R35** | reusar; fix em **P1** |
| P0 paths freeze | **§52.1 + §66** | reusar; P0 **não** cresce para Foundry |
| Dirty-main / phase receipt | **§54–§55** | reusar |
| Archive hold | **§56** | reusar |
| ModeExecutor contract | **§24** | reusar |
| Spine S1–S8 | **§26** | reusar |
| Kernel ports | **§27** | reusar; **não** segundo Kernel |
| `ExecutionOrder` / `EngineeringOutcome` | `EngineeringKernel/*` no disco | **seam canônico**; zero `EngineeringMission*` |
| Verification / Merge courts | `SelfConstruction/VerificationCourt`, `MergeGovernor` | **compor**; zero Court 2.0 sob Aaeos |
| Evidence | `AtlasEvidenceLedger` + §34 | única verdade; zero counter JSON |
| Autônomos muscle | `atlas:brain:*` / `atlas:task:*` + scoped committer | **não** reimplementar em Aaeos |
| R16–R37 honesty/ops | **§3 + §46** | permanecem; RSS **não** renumera |
| Multiagente / ablação / Foundry | Rivals + Quality Foundry (planos irmãos) | **horizonte §73**; fora do binary DONE P4 |
| WorkGraph product | **não existe no disco** | **não criar** neste MT; TaskGraph/planners só se P2+ provar necessidade com residual novo |
| SovereigntyPort package | **não existe** | **não criar package**; admissão pura + H1–H7 (§71) |
| 17-phase / 11 depts | ritual AEOS observe | **demote**; não operate path |

**Regra:** se a frase do plano só renomeia uma linha da tabela acima, **delete a frase** — não acrescente órgão.

## 70. Lei RSS (constitutiva — 12 linhas)

1. **LOOP_ENGINEERING** = somente agentes (spec, implement, verify, land, evidence, learn-propose).  
2. **LOOP_SOVEREIGNTY** = somente humano (propósito, identidade, autoridade, irreversibilidade, trade-off estratégico, expandir autonomia, H1–H7 §71).  
3. **SURFACE_AUDIT** = humano opcional (cockpit/terminal review) **observa/amostra/escala**; **nunca** voto de engenharia default; moat terminal-first vive aqui.  
4. **Mesma barra L0–L5** nos três modos; proibido Dev=leve / Autônomos=pior.  
5. Diferença de modo = `duration_regime` + `delegation_regime` + `work_origin` + `sovereignty_channel` + **`autonomy_tax_tier`** — **não** qualidade.  
6. **Autonomy Tax:** `sovereignty_channel → none` ⇒ **mais** prova (witness/independence), não a mesma.  
7. **Intent Clarity Rebate:** reduz só cerimônia **não-prova**; nunca mutation floor, false-green, ledger ou courts.  
8. Seam de engenharia = **`EliteExecutorKernel::execute(ExecutionOrder): EngineeringOutcome`** (ou Autônomos brain→task sob o **mesmo** efeito/proof taxonomy §47/§50). Zero Mission Runtime paralelo.  
9. AAEOS = Crown **opcional** fino (`Control`+`Spine`); Dev/Forge/Autônomos e `aaeos:run` são entradas; AAEOS **não** é pedágio diário obrigatório de todo keystroke.  
10. Provider = músculo não-confiável: nunca `verified`, promote learning, authorize release.  
11. `author ≠ judge ≠ governor` com prova (principal / capability / mechanical); booleano auto-declarado **não** basta.  
12. n=1 default; `agent_count` **nunca** KPI; fan-out só com largura real + lift esperado (§73 horizonte).

### 70.1 Campos de envelope/receipt (aditivos; não segundo schema de missão)

Preferir **campos aditivos** no cycle receipt / order correlation (sem inventar `EngineeringMissionReceipt`):

```text
engineering_judgment: agentic_only          # sempre
duration_regime: session|obra|continuous
delegation_regime: interactive|planned|queued
work_origin: live_prompt|obra_spec|brain_seed
sovereignty_channel: live_intent|plan_seal|none|halt_exception
autonomy_tax_tier: dev_proportional|forge_elevated|autonomos_maximum
audit_surface: optional_human_observe
admission: admitted|repair_required|policy_blocked|sovereign_judgment_required|…
intent_clarity_rebate_applied: bool
# REMOVER como identidade de modo:
# human_in_engineering_loop  (legado — ver R38)
```

**Dev:** `sovereignty_channel=live_intent` (intenção viva no canal de soberania) — **não** “humano revisor técnico”.  
**Forge:** `plan_seal` + soberania de risco.  
**Autônomos:** `none` + tax máxima + H1–H7.

### 70.2 Arquitetura-alvo RSS (substitui o diagrama inchado de §4 em significado)

```text
Operador (soberania H1–H7 + intenção)
    │
    ├─ Dev surface ──┐
    ├─ Forge surface ┼──► (opcional) AAEOS Control: admit/mode/caps
    └─ Autônomos ────┘         │
                               ▼
              ExecutionOrder  ──► EliteExecutorKernel   [Dev/Forge]
              brain→seed→task ──► SelfConstruction      [Autônomos]
                               │
              Courts existentes (Spec organs / VerificationCourt / MergeGovernor)
                               │
              AtlasEvidenceLedger  (N11) + effect_level/proof_level (§47/§50)
                               │
              Learning pending_review · scorecard measured/unknown · cockpit audit
```

Spine N9/N11: **passagem com refs/hashes reais** (R44); proibido `assertShared([])` auto-ok.

## 71. Soberania mínima (H1–H7) — sem SovereigntyPort package

| ID | Classe | Humano obrigatório | Agente ok se |
|---|---|---|---|
| H1 | Constituição / policy self-mod / admission laws | sim | — |
| H2 | Valores / objetivo de negócio ambíguo | sim | — |
| H3 | Efeito externo irreversível (wipe, $ live, legal publish, secret) | sim | — |
| H4 | Promote learning → memória canônica | sim | propose only |
| H5 | Expandir autonomia / auto_apply / risk window | sim | — |
| H6 | Mint tokens / disable independence gates / sovereignty_exception | sim | — |
| H7 | Domínio sensível (ex.: trading/cyber ofensivo) | sim | — |

**Admissão tipada (emenda AAEOS; alinha MergeGovernor):**  
`repair_required` | `policy_blocked` | `sovereign_judgment_required` | `admitted` | `admitted_notify` | `emergency_halt`.  
**Proibido:** `invalid_mode` ou incidente técnico → `halt_sovereign` (R40).

Classificador de soberania: **pure, sem LLM no port**; sinais mecânicos (path/effect class); unknown ⇒ não `admitted`. Implementação: estender `AaeosAdmissionPolicy` + owners Decide **existentes** — **não** criar árvore `SovereigntyPort/` sem residual de disco pós-P1.

## 72. Residuals constitutivos RSS (R38–R46) — sem reabrir R16–R37

| ID | Gap | Já coberto por? | Fase | Done quando |
|---|---|---|---|---|
| R38 | `human_in_engineering_loop` como identidade de modo / Dev=true no receipt | parcial §24 | P0–P1 | campo removido ou não-autoritativo; regimes §70.1 no receipt; elite doc alinhado |
| R39 | difficulty/autonomy/risk/soberania colados (human_review por nível) | §7 ladder | P1–P2 | eixos separados; review técnico agentic; soberania só H\* |
| R40 | falha técnica rotulada soberana | admission live | P0–P1 | taxonomia §71; golden cases |
| R41 | falta regimes tempo/delegação/origem/tax no contrato de modo | — | P1–P2 | campos §70.1 emitidos e testados |
| R42 | 17-phase/depts como operate path | §15 out-of-scope | P3 docs | demote documentado; daily = run/cycle/scorecard/certify/cockpit |
| R43 | entradas não compartilham application services; CLI→CLI | R33 + §48 | P1 | brain/seed apps; R33–R35 green; parity path |
| R44 | spine declara classes sem efeito/ledger | R1–R2 §26 | P2 | evidence refs + readback; metadata-only fail |
| R45 | atenção/fila tratada como autoridade | — | P1 | queue = read model; só DecisionReceipt soberano altera boundary |
| R46 | author/judge/governor sem prova de independência | seed-gate parcial | P2–P3 | principals distintos **ou** mechanical-only gate; same-engine refuse em tax máxima |

**R47–R50 = HORIZONTE (fora do DONE P4 deste MT):**

| ID | Tema | Onde |
|---|---|---|
| R47 | SM durável / recon authorize→land→canary em escala | pós-P4 / Kernel hardening — **não** P4 gauntlet substitute |
| R48 | ablação topologia (estrutura T0–T5, não n cru) | **irmã** Rivals/Quality Foundry |
| R49 | minutos humanos / economia de atenção | irmã + instrumentação receipt |
| R50 | prova comparativa externa sustentada | irmã; claim #1 só com campanhas |

Mint de residual **>R50** só com falha de disco ou emenda canônica — nunca por diagrama.

## 73. Horizonte multiagente / Foundry (irmã — zero fase P5/P6 neste MT)

- Default **n=1**.  
- Fan-out exige: write-set disjunto, largura real, ROI esperado, **braço pareado n=1**.  
- `agent_count` telemetria only.  
- Evaluation: reusar **Rivals / Quality Foundry** — proibido terceiro framework sob Aaeos.  
- Elite Deepening emite no máximo **campos de receipt** consumíveis pela irmã; **não** roda campanhas R48–R50 como gate de P4.  
- P4 deste MT = gauntlet one-shot + hard gates §49 + R33/R34 + archive hold (§52.5) — **inalterado em espírito**.

## 74. Emendas obrigatórias a Partes I–III (ponteiros, não cópia)

| Onde | Emenda RSS |
|---|---|
| §1 L13–L18 | manter espírito; L13 lê-se com §70 (SURFACE_AUDIT + H1–H7; não “zero humano em tudo”) |
| §4 diagrama | significado = §70.2 (sem WorkGraph product box) |
| §5 DONE 14–19 | ver §74.1 abaixo — **não** exigir Foundry/SM global para DONE |
| §6 tabela | regimes §70.1; não “presença humana eng” como qualidade |
| §9 / §52 | **autoridade de implementação** P0–P4; Part IV **não** substitui file freeze |
| §24 receipt | campos legados `human_in_engineering_loop` → migrar per R38 |
| elite doc | `atlas-elite-executors-dev-forge-autonomos.md` deve ser atualizado **na mesma obra de docs** que fechar R38 (owner identidade) |

### 74.1 DONE do programa (emenda §5 — conjunção)

DONE Elite Deepening = itens **1–13 de §5** (honesty, muscle, scorecard, gauntlet, quarantine, …)  
**mais** R38–R46 fechados **ou** DEBT nomeado com owner  
**menos** qualquer exigência de R47–R50 / P5 / P6 / WorkGraph OS / Mission Runtime.

Itens §5.14–19 da expansão mega anterior ficam **reinterpretados**:

| Item mega | RSS |
|---|---|
| “humano impossível no loop técnico” | **sim** como LOOP_ENGINEERING; SURFACE_AUDIT e H1–H7 permanecem |
| ExecutionOrder v3 completo | **strangler**: campos §70.1 aditivos; bump v3 só se breaking for inevitável |
| courts independentes | prova R46; reusar courts disco |
| topologia provada | **horizonte** R48; P4 não exige |
| governo durável completo | **horizonte** R47; P4 usa chokepoints Kernel/lease **já** no land path |
| claim #1 externo | **horizonte** R50 |

## 75. Ordem de execução (sem P5/P6 neste MASTER)

```text
PLAN_ONLY (agora)
  └─ v5 RSS docs + elite doc (R38 canon) quando autorizado em docs slice
EXECUTE P0  →  §52.1 honesty + run/cycle + measured + admission labels R40 (caracterizar R38)
P1          →  ModeExecutors + R33–R35 + brain app services + R38 writers + R45 attention read-only
P2          →  Kernel ports + spine R44 + axes + R41 fields + R46 floor
P3          →  observe/alias (existente) + R42 demote docs; topology só se residual disco
P4          →  live gauntlet + freeze; R47–R50 explicitamente NÃO bloqueiam one-shot
```

**Autorização:** `EXECUTE P0` **não** autoriza WorkGraph, Foundry, nem package SovereigntyPort.

## 76. Autocrítica RSS (esta rodada docs)

- [x] Part IV **substitui** expansão mega (WorkGraph/P5/P6/file map duplicado) — anti-Sol duplication.  
- [x] R16–R37 **não** renumerados; R38–R46 constitutivos; R47–R50 horizonte.  
- [x] P0–P4 **continuam** §9/§52.  
- [x] Zero segundo MASTER.  
- [x] Zero código produção nesta integração.  
- [ ] Elite executors doc ainda precisa de slice de alinhamento (R38) — **não** feito se só MASTER nesta rodada; LEDGER registra.

---

**Fim do MASTER Implementation Plan (MT) v5 — Razor Sovereign Spine (anti-duplicação), sem over-claim.**  
Próxima decisão humana: **EXECUTE P0** (honesty sob lei RSS) **ou** docs-slice elite executors (R38) **ou** mais absolute só se residual de disco novo.
