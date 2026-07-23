# Atlas Server GOD Debulk — HUB (10/10 IA · aponta cobertura 100%)

> **STATUS DO PLANEJAMENTO: COMPLETO COM PROVA Δ=0**  
> **Cobertura operacional (obrigatória):**  
> `docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-FILESYSTEM-100.md`  
> → **478 buckets · 15 260 files · LOC walk · Δ files = 0** (cada path do corpus em exatamente um bucket, com ações e lista ≥800 LOC).  
> Este hub define **10/10**, ordem do programa e leis. O FILESYSTEM-100 é o inventário acionável.  
> Modo: **somente planejamento** até o operador autorizar P0.  
> **For agentic workers (quando executar):** child plan por bucket/WAVE a partir do FILESYSTEM-100.

**Goal:** Elevar o atlas-server ao máximo operacional de **gerenciamento total por IA** — 10/10 em cada capacidade abaixo — aplicando o padrão GOD do atlas-native em **100% do código**, sem reescrever PHP→Swift.

**Architecture:** Laravel/PHP permanece o cérebro. SPLIT godfiles antes de fuse. Ownership único por capability. Vocabulário fechado. CODEMAP `Class::method` cobrindo 100% das façades públicas. Densidade rígida. Docs como índice navegável (não archive solto). Gates contínuos. Programa cobre **cada bucket de LOC** listado na matriz §4.

**Tech Stack:** PHP 8.4 · Laravel 13 · PHPUnit/ParaTest · Artisan · Postgres · `atlas` CLI · scripts `god-debulk-*`.

---

## 1. Definição pétrea de 10/10 (capacidades de IA)

Nota **10/10** aqui **não** é “repo pequeno”. É o máximo operacional: qualquer agente competente encontra, cabe no contexto do hot path, entende ownership, edita com prova, evolui sem criar camada gêmea, e navega docs sem arqueologia.

| # | Capacidade | 10/10 = critérios mensuráveis (todos obrigatórios) | Baseline (2026-07-22) | Gate de prova |
|---|---|---|---|---|
| A | **Achar** | (1) `app/Services/Ai/CODEMAP.md` + CODEMAPs por WAVE cobrem **100%** das façades públicas (`rg` prova path→símbolo). (2) **0** capability com 2+ entrypoints públicos não-alias. (3) `php artisan list` agrupado ≤ **12** famílias documentadas. (4) START_HERE + OWNERSHIP + CODEMAP formam caminho ≤3 hops para qualquer hot path mobile/desktop/CLI. | ~2/10 · 119 pastas · sem CODEMAP Ai · 910 commands | script `god-debulk-codemap-verify.php` exit 0 |
| B | **Cabe no contexto** | (1) **0** arquivo PHP em `app/` ou `tests/` **>2000** LOC. (2) **0** arquivo PHP **>800** LOC em façades públicas / Commands / Http controllers quentes (lista CODEMAP “hot”). (3) Target serviço 150–600; warn 600–800; split>800 hot / >2000 any. (4) `config/atlas.php` **≤800** LOC (split por domínio de config). | ~2/10 · 51 app files >2k · monsters 10–30k · config 5367 | `god-debulk-audit.php` + guard enforce |
| C | **Entender** | (1) OWNERSHIP.md `status: accepted` para **100%** das pastas Ai + non-Ai services com ≥500 LOC. (2) Vocabulário fechado aplicado (suffixes + method families) com linter/`rg` gate. (3) Diagrama “uma pergunta → um owner” no START_HERE. (4) Zero par SelfConstruction×AAEOS×AutonomousEvolution×Stewardship com façade duplicada para a mesma capability. | ~3/10 | OWNERSHIP coverage report 100% |
| D | **Editar com segurança** | (1) Todo módulo WAVE com characterization tests dos entrypoints. (2) Test monsters **0** files >2000 LOC. (3) Diff de refactor sempre acompanhado de teste do pacote. (4) Rollback path documentado por WAVE (façade alias ≤1 release). | ~4/10 · tests 1.2M mas monsters 31k | ParaTest filtered green + monster audit 0 |
| E | **Evoluir** | (1) Placement rule: pasta nova só com OWNERSHIP row + segundo consumidor. (2) Template de serviço/command/test canônico. (3) Proibido criar 5ª OS layer; extensão só dentro do owner. (4) Skill/Harness docs apontam CODEMAP, não pastas cruas. | ~3/10 | checklist placement no PreToolUse / prompt |
| F | **Provar** | (1) `php artisan test --parallel` verde em CI local da missão. (2) Smoke suite `<5 min` dos hot paths. (3) Guard densidades + CODEMAP + no-vanity-pass no pre-commit da missão. (4) Evidence LEDGER com comandos colados por WAVE. | ~6/10 | suite + guard OK |
| G | **Docs como mapa** | (1) ekb: índice vivo ≤ hops 2; archive isolado e **não** linkado como navegação. (2) 0 doc canônico >1200 LOC sem split índice/corpo. (3) Cada WAVE atualiza CODEMAP + OWNERSHIP na mesma PR/commit set. (4) Docs de loop/ACDE legado marcados LEGADO e fora do caminho operate. | ~4/10 · 408k docs | doc-index verify script |

**Score composto 10/10:** só quando A–G estão todos 10/10 simultaneamente.  
**Proibido** declarar vitória por file-count↓ ou LOC↓ isolado.

---

## 2. Analogia native (obrigatória)

O native não virou “menor por obsessão”; virou **agent-optimal**: vocabulário fechado, peels mortos, hosts coesos, densidade, CODEMAP, gates, zero produto na missão. Fracassos a **não** repetir: fuse→godfile, Goal Done cedo, `residual pass` docs.

Server = mesmo padrão em PHP no **repo inteiro**.

---

## 3. Baseline exata do repo (100% do corpus medido)

### 3.1 Totais

| Corpus | Files | LOC |
|---|---:|---:|
| **Selecionado (app+tests+docs+database+config+routes+scripts+bin+resources)** | **15 244** | **3 495 137** |
| `app/` | 7 012 | 1 807 099 |
| `tests/` | 6 209 | 1 215 297 |
| `docs/` | 1 527 | 419 941 |
| `database/` | 375 | 29 789 |
| `config/` | 30 | 9 891 |
| `scripts/` | 78 | 10 043 |
| `routes/` | 2 | 1 632 |
| `bin/` | 7 | 1 175 |
| `bootstrap/` + `public/` + `resources/` | ~18 | ~12 752 |

`app/` PHP: **7 011** files · **1 807 082** LOC · mediana **142** · p99 **1643** · max **29744**  
`app/Services/Ai`: **5 056** files · **1 489 335** LOC (**82,4%** do app) · **120** buckets (119 pastas + root files)

### 3.2 Godfiles

- ≥2000 LOC (corpus): **79**  
- ≥5000: **25**  
- ≥10000: **11**

### 3.3 Superfícies

- Commands ~**910** · Models **407** · Migrations **371** · Http **331** · Providers **3** · ekb md **1057**

---

## 4. Matriz de cobertura 100% (todo LOC tem WAVE)

Cada linha **deve** ter child plan na execução. Soma Ai + non-Ai + tests + docs + infra = 100% do corpus selecionado.

### 4.1 Programa P0 — Fundações (bloqueia o resto)

| ID | Entrega | Cobertura |
|---|---|---|
| P0.1 | Evidence `LEDGER` + `DEBTS` + `OWNERSHIP` + audit/guard/codemap-verify scripts | repo-wide tooling |
| P0.2 | Canon + START 24h (até cancelar · SPLIT first · no vanity pass) | prompts |
| P0.3 | `app/Services/Ai/CODEMAP.md` esqueleto + índice global `docs/.../START_HERE` link | navegação |
| P0.4 | Scoreboard A–G baseline colado no LEDGER | métricas |

### 4.2 WAVE-OS — Ownership das camadas (antes de mover em massa)

| ID | Owner canônico | LOC Afetado (pastas) | Ação de plano |
|---|---|---:|---|
| OS.1 | SelfConstruction | 398 540 | façade única readiness/build; sections viram classes <800 |
| OS.2 | Aaeos | 142 212 | orquestração AAEOS; aliases de AutonomousEngineering |
| OS.3 | Programming (+ ProgrammingRuntime) | 131 893 + 3 657 | um Programming façade |
| OS.4 | SoftwareCompanyStewardship | 116 401 | stewardship ≠ evolution |
| OS.5 | AutonomousEvolution | 109 596 | evolution sessions; sem gêmeo em Stewardship |
| OS.6 | Kernel + EngineeringKernel + AgenticEngineeringOs | 41 474 + 14 293 + 26 649 | gates/scanner ownership |
| OS.7 | Holding | 44 389 | mandate/buildout/fixture split |
| OS.8 | Cognition + Cognitive + CognitiveMemory + AcosMax + Compounding | 13 420+8 848+466+14 223+7 321 | mapa ACOS vs Cognition |
| OS.9 | Memory* + OpenBrain* root services | Memory 7 238 + root OpenBrain gods | Memory Core vs AOBG surfaces |
| OS.10 | AtlasDecide + Router* + DualCore + Provider* | Decide/Router/Provider | decide path único |
| OS.11 | Autônomos live (`brain`/`task`) vs Loop keep-list | transversal | operate paths; keep-list intact |

### 4.3 WAVE-A — Ai folders por tamanho (100% dos 1 489 335 LOC Ai)

#### A1 — W1 (>40k) — 8 buckets · **1 045 877** LOC

| Bucket | Files | LOC | Child plan na execução | Ordem |
|---|---:|---:|---|---|
| SelfConstruction | 1210 | 398 540 | `...-wave-a1-selfconstruction.md` | 1 |
| Aaeos | 346 | 142 212 | `...-wave-a1-aaeos.md` | 2 |
| Programming | 543 | 131 893 | `...-wave-a1-programming.md` | 3 |
| SoftwareCompanyStewardship | 280 | 116 401 | `...-wave-a1-stewardship.md` | 4 |
| AutonomousEvolution | 639 | 109 596 | `...-wave-a1-autonomous-evolution.md` | 5 |
| (Ai-root-files) | 95 | 61 372 | `...-wave-a1-ai-root.md` | 6 |
| Holding | 6 | 44 389 | `...-wave-a1-holding.md` | 7 |
| Kernel | 159 | 41 474 | `...-wave-a1-kernel.md` | 8 |

#### A2 — W2 (10–40k) — 13 buckets · **216 526** LOC

MarketingDomain · AgenticEngineeringOs · Rivals · Context · Finance · Vox · EngineeringKernel · AcosMax · Hermes · Cognition · SelfImprovement · Foundry · Product  

Child plan: `...-wave-a2-<bucket>.md` (um por bucket, ordem por LOC desc).

#### A3 — W3 (2–10k) — 33 buckets · **170 515** LOC

Cli · AtlasDecide · Mobile · Cognitive · LongHorizon · Telemetry · VentureFoundry · WorkspaceIntelligence · Compounding · Memory · RealExecution · Publishing · Reality · Voice · Mission · ControlPlane · Obra · Domain · Governance · RouterRuntime · OperatorIntelligence · ProgrammingRuntime · Runtime · Autonomy · Router · Organism · Aemor · Compression · RuntimeEfficiency · Arena · ToolRuntime · Surface · Evidence  

Child plan batchável: grupos de 3–5 buckets por child plan se same OS owner; senão 1:1.

#### A4 — W4 (<2k) — 66 buckets · **56 417** LOC

Todos os restante Ai (Cyber, Strategy, Skills, Brain, Gateway, Streaming, Security, … + value objects).  

Child plan: `...-wave-a4-long-tail.md` com checklist **nomeada** de cada pasta (obrigatório listar as 66 no child plan na execução — inventário já está na medição; execução enumera e marca).

**Cobertura Ai:** W1+W2+W3+W4 = **1 489 335** LOC = **100%** de `app/Services/Ai`.

### 4.4 WAVE-B — App fora de Ai (100% do restante do `app/`)

| ID | Bucket | Files | LOC | Ação |
|---|---|---:|---:|---|
| B1 | `app/Console` | 939 | 138 624 | thin commands → façades; split AiChat/Stewardship/Holding/AAEOS gods |
| B2 | `app/Services/Engineering` | 144 | 72 135 | split Benchmark + RealityUsage gods; CODEMAP engineering |
| B3 | `app/Http` | 331 | 46 749 | controllers ≤800; PipelineRunExecutor split |
| B4 | `app/Models` | 407 | 21 138 | manter slim; rename honesty só se mentir; 0 godfiles |
| B5 | `app/Services/AtlasCode` | 33 | 11 288 | surface única AtlasCode |
| B6 | `app/Services/Semantic` | 19 | 5 385 | ownership vs Memory |
| B7 | `app/Services/Tools` | 14 | 4 309 | fuse peels same-concern |
| B8 | `app/Services` root singles (Project*, Capture*, Bitacula*, MacAgent, Whisper, Audit, DomainRegistry, …) | ~20 | ~15 000+ | cada um na long-tail B com owner |
| B9 | `app/Jobs` | 14 | 1 478 | thin jobs |
| B10 | `app/Providers` | 3 | 1 760 | bindings por owner; sem god-provider |
| B11 | `app/Support` + Enums + Observers + Logging | ~16 | ~1 760 | suporte fino |
| B12 | `app/Services/Vault` + Digital + MacAgent leftovers | — | ~4 000 | ownership surfaces |

**Regra:** soma B* + Ai = **100%** de `app/` PHP (1 807 082). Qualquer arquivo novo descobridor entra em DEBTS `unmapped` até WAVE.

### 4.5 WAVE-T — Tests (100% de 1 208 965 PHP tests)

| ID | Bucket | Files | LOC | Ação 10/10 |
|---|---|---:|---:|---|
| T1 | `tests/Unit` | 3462 | 664 745 | espelhar ownership; split >2000; 0 orphan tests de classes deletadas |
| T2 | `tests/Feature` | 2154 | 537 694 | hot path smoke; split SelfConstruction/AAEOS/Holding/Gates monsters |
| T3 | `tests/Concerns` + Fixtures + Support + TestCase | ~85 | ~6 526 | shared harness limpo |
| T4 | `tests/atlas_generated_0.php` etc. | residual | residual | delete ou regenerar com dono |

**Done T:** 0 test file >2000; characterization por façade WAVE-A/B.

### 4.6 WAVE-D — Docs (100% de ~420k)

| ID | Bucket | LOC | Ação 10/10 |
|---|---|---:|---|
| D1 | `docs/engineering-knowledge-base` vivo | ~343k (incl. archive) | índice ≤2 hops; archive **quarantine** (não navega); split docs >1200 |
| D2 | `docs/ap` | 26 920 | índice AP ou archive |
| D3 | `docs/superpowers` | 7 861 | este programa + child plans |
| D4 | restante `docs/*` (goals, contracts, loop-*, rivals-*, cli plans, …) | ~40k+ | marcar LEGADO vs VIVO; loop/ACDE fora do operate path |
| D5 | Proibir rival/benchmark claims em código/doc novo | — | constitucional |

### 4.7 WAVE-I — Infra (100% database/config/routes/scripts/bin/bootstrap/public/resources)

| ID | Bucket | LOC | Ação 10/10 |
|---|---|---:|---|
| I1 | `database/migrations` | ~29 263 | manter; 0 migration god; docs de schema no CODEMAP dados |
| I2 | `config/` esp. `config/atlas.php` **5367** | 9 891 | **split obrigatório** atlas.php → config/atlas/*.php ≤800 |
| I3 | `routes/` | 1 632 | rotas por domínio; thin |
| I4 | `scripts/` + `bin/` | ~11 218 | god-debulk scripts + atlas CLI wrappers documentados |
| I5 | `bootstrap/` + `public/` + `resources/` | ~12 752 | mínimo; sem lógica de domínio |

### 4.8 Prova de cobertura 100%

```
Ai W1..W4     = 1,489,335  (100% Ai)
App B1..B12   = resto app até 1,807,082
Tests T1..T4  = 1,208,965
Docs D1..D5   = ~419,941
Infra I1..I5  = database+config+routes+scripts+bin+bootstrap+public+resources
--------------------------------
= corpus selecionado 3,495,137
```

Checklist de planejamento (esta seção): **COMPLETA** — não existe bucket medido fora da matriz.  
Na execução: `god-debulk-audit.php --unmapped` deve retornar **0** paths sem WAVE id.

---

## 5. Ordem de execução (programa)

```
P0 Fundações
 → OS Ownership accepted (operador)
 → A1 godfile SPLIT (SelfConstruction → Holding → Gates/Kernel → …)
 → A1 façade collapse
 → A2 → A3 → A4
 → B1 Commands + B2 Engineering + B3 Http + B* long-tail
 → T* (em paralelo após cada A/B WAVE: testes do pacote)
 → I2 config split (cedo — bloqueia contexto)
 → D* índice/quarantine (contínuo)
 → Scoreboard A–G = 10/10
```

**Lei:** nunca fuse em massa antes dos splits A1 dos monsters.  
**Lei:** cada WAVE fecha com CODEMAP + OWNERSHIP + testes do pacote + audit.

---

## 6. Vocabulário fechado (canon — planejamento)

### Sufixos de classe (PHP)

`Facade` · `Runtime` · `Service` · `Policy` · `Projector` · `Scanner` · `Evaluator` · `Gateway` · `Command` · `Provider` · `Support` · `ValueObject`

Proibidos como desculpa de godfile: `Section`, `Batch1`, `Helper`, `Util`, `Manager` sem boundary.

### Famílias de método públicas

`decide*` · `pack*Context` · `rank*` · `certify*` · `project*` · `run*` · `scan*` · `evaluate*`

### Densidade

| Camada | Target | Warn | Fail |
|---|---|---|---|
| Facade / Command / Http hot | 150–600 | >800 | >800 |
| Service/Policy/Projector | 150–800 | >1000 | >2000 |
| Qualquer PHP app/tests | — | >2000 | >2000 (pós-P2 enforce) |
| config files | ≤400 | >600 | >800 |

---

## 7. Artefatos a criar na execução (não agora)

| Path | Função |
|---|---|
| `docs/evidence/2026-07-22-atlas-server-god-debulk/*` | LEDGER DEBTS OWNERSHIP audits |
| `docs/prompts/atlas-server-god-debulk*.md` | missão 24h |
| `docs/prompts/atlas-server-god-code-canon.md` | canon |
| `app/Services/Ai/CODEMAP.md` + CODEMAPs WAVE | navegação |
| `scripts/god-debulk-audit.php` | métricas + unmapped |
| `scripts/god-debulk-guard.sh` | enforce |
| `scripts/god-debulk-codemap-verify.php` | A=10 |
| `docs/superpowers/plans/2026-07-22-god-debulk-wave-*.md` | child plans |

Plano parcial anterior: `2026-07-22-atlas-server-god-debulk.md` — **superseded** por este COMPLETE para cobertura/10/10; manter como rascunho de tasks P0 iniciais se útil.

---

## 8. Anti-padrões (halt)

1. Declarar 10/10 sem gates A–G verdes  
2. PHP→Swift rewrite  
3. Vanity `residual pass` docs  
4. Fuse criando arquivo >800 hot / >2000 any  
5. Delete `AtlasLoop*` por prefixo  
6. Nova pasta Ai sem OWNERSHIP  
7. Fechar WAVE sem CODEMAP  
8. “Só Ai” — **viola 100%**; B/T/D/I obrigatórios  

---

## 9. Scoreboard alvo ( Declaração de Done do programa )

| Capacidade | Alvo |
|---|---|
| A Achar | 10/10 |
| B Contexto | 10/10 |
| C Entender | 10/10 |
| D Editar seguro | 10/10 |
| E Evoluir | 10/10 |
| F Provar | 10/10 |
| G Docs mapa | 10/10 |
| Cobertura LOC matriz §4 | **100%** |
| `godfiles_gt_2000` app+tests | **0** |
| `unmapped` paths | **0** |

---

## 10. Autoverificação do planejamento

| Pergunta | Resposta |
|---|---|
| Cobre 100% dos paths do corpus? | **Sim — prova Δ files=0** no FILESYSTEM-100 (15 260 paths) |
| Cada bucket tem ações + arquivos ≥800? | **Sim** — gerado do filesystem |
| Fixtures/json/scripts/docs assets? | **Sim** — inclusos após gap analysis (548 paths que faltavam) |
| 10/10 falsificável? | **Sim** — §1 |
| Implementação? | **Não** até autorizar P0 |

---

## 11. Handoff

| Artefato | Papel |
|---|---|
| `...-COMPLETE.md` (este) | Hub · 10/10 · leis · ordem |
| `...-FILESYSTEM-100.md` | **Cobertura 100% acionável** |
| `...-god-debulk.md` | Rascunho P0 tasks (superseded para cobertura) |

**Planejamento: COMPLETO.** Execução só com OK do operador em **P0**.

---

## Appendix A — Inventário nominativo 100% Ai (120 buckets)


### A1 completo

| Bucket | Files | LOC | WAVE |
|---|---:|---:|---|
| `SelfConstruction` | 1210 | 398,540 | A1 |
| `Aaeos` | 346 | 142,212 | A1 |
| `Programming` | 543 | 131,893 | A1 |
| `SoftwareCompanyStewardship` | 280 | 116,401 | A1 |
| `AutonomousEvolution` | 639 | 109,596 | A1 |
| `(Ai-root-files)` | 95 | 61,372 | A1 |
| `Holding` | 6 | 44,389 | A1 |
| `Kernel` | 159 | 41,474 | A1 |

### A2 completo

| Bucket | Files | LOC | WAVE |
|---|---:|---:|---|
| `MarketingDomain` | 180 | 26,853 | A2 |
| `AgenticEngineeringOs` | 17 | 26,649 | A2 |
| `Rivals` | 70 | 20,724 | A2 |
| `Context` | 65 | 20,248 | A2 |
| `Finance` | 100 | 17,631 | A2 |
| `Vox` | 39 | 16,103 | A2 |
| `EngineeringKernel` | 110 | 14,293 | A2 |
| `AcosMax` | 51 | 14,223 | A2 |
| `Hermes` | 53 | 13,507 | A2 |
| `Cognition` | 52 | 13,420 | A2 |
| `SelfImprovement` | 22 | 11,333 | A2 |
| `Foundry` | 47 | 11,331 | A2 |
| `Product` | 34 | 10,211 | A2 |

### A3 completo

| Bucket | Files | LOC | WAVE |
|---|---:|---:|---|
| `Cli` | 30 | 9,618 | A3 |
| `AtlasDecide` | 33 | 9,583 | A3 |
| `Mobile` | 22 | 8,925 | A3 |
| `Cognitive` | 65 | 8,848 | A3 |
| `LongHorizon` | 23 | 8,678 | A3 |
| `Telemetry` | 35 | 8,623 | A3 |
| `VentureFoundry` | 39 | 8,117 | A3 |
| `WorkspaceIntelligence` | 17 | 8,059 | A3 |
| `Compounding` | 40 | 7,321 | A3 |
| `Memory` | 34 | 7,238 | A3 |
| `RealExecution` | 8 | 6,431 | A3 |
| `Publishing` | 2 | 6,254 | A3 |
| `Reality` | 7 | 5,620 | A3 |
| `Voice` | 12 | 5,363 | A3 |
| `Mission` | 25 | 5,115 | A3 |
| `ControlPlane` | 13 | 4,904 | A3 |
| `Obra` | 19 | 4,787 | A3 |
| `Domain` | 22 | 4,695 | A3 |
| `Governance` | 16 | 4,687 | A3 |
| `RouterRuntime` | 14 | 3,778 | A3 |
| `OperatorIntelligence` | 19 | 3,665 | A3 |
| `ProgrammingRuntime` | 12 | 3,657 | A3 |
| `Runtime` | 14 | 2,879 | A3 |
| `Autonomy` | 10 | 2,775 | A3 |
| `Router` | 10 | 2,764 | A3 |
| `Organism` | 24 | 2,647 | A3 |
| `Aemor` | 5 | 2,444 | A3 |
| `Compression` | 14 | 2,327 | A3 |
| `RuntimeEfficiency` | 4 | 2,269 | A3 |
| `Arena` | 7 | 2,231 | A3 |
| `ToolRuntime` | 15 | 2,080 | A3 |
| `Surface` | 15 | 2,079 | A3 |
| `Evidence` | 17 | 2,054 | A3 |

### A4 completo

| Bucket | Files | LOC | WAVE |
|---|---:|---:|---|
| `AutonomousEngineering` | 4 | 1,897 | A4 |
| `AutomationDomain` | 16 | 1,869 | A4 |
| `Cyber` | 14 | 1,742 | A4 |
| `RuntimeBoundary` | 23 | 1,739 | A4 |
| `AgenticWorkcell` | 3 | 1,699 | A4 |
| `DomainRuntime` | 10 | 1,574 | A4 |
| `Strategy` | 13 | 1,527 | A4 |
| `Policy` | 10 | 1,474 | A4 |
| `Skills` | 8 | 1,450 | A4 |
| `Patamar4` | 6 | 1,428 | A4 |
| `Support` | 12 | 1,413 | A4 |
| `ValueObjects` | 7 | 1,370 | A4 |
| `NightShift` | 2 | 1,348 | A4 |
| `ResearchDomain` | 10 | 1,291 | A4 |
| `ContextIntelligence` | 5 | 1,205 | A4 |
| `Scheduling` | 6 | 1,190 | A4 |
| `AgentGovernance` | 12 | 1,159 | A4 |
| `AutonomousWorkExecution` | 2 | 1,152 | A4 |
| `SelfDirectedEvolution` | 3 | 1,114 | A4 |
| `StrategicReality` | 2 | 1,080 | A4 |
| `Learning` | 1 | 1,067 | A4 |
| `SpecialistFlows` | 13 | 1,052 | A4 |
| `VerifiedExecution` | 2 | 1,044 | A4 |
| `Concerns` | 3 | 1,027 | A4 |
| `OperatorApproval` | 4 | 1,017 | A4 |
| `RealitySandbox` | 2 | 1,003 | A4 |
| `IntelligenceFactory` | 2 | 982 | A4 |
| `PersistentContext` | 2 | 977 | A4 |
| `EngineeringCompany` | 2 | 909 | A4 |
| `StrategicOperatingSystem` | 1 | 884 | A4 |
| `RuntimeReadiness` | 1 | 857 | A4 |
| `Caching` | 6 | 851 | A4 |
| `Analysis` | 6 | 835 | A4 |
| `PersonalDevelopment` | 6 | 787 | A4 |
| `ConversationOps` | 2 | 736 | A4 |
| `Teos` | 2 | 723 | A4 |
| `Capture` | 2 | 719 | A4 |
| `CrossDomain` | 2 | 706 | A4 |
| `Rsi` | 6 | 701 | A4 |
| `Provider` | 10 | 675 | A4 |
| `AtlasForge` | 4 | 665 | A4 |
| `Attachments` | 2 | 649 | A4 |
| `OpenBrain` | 3 | 622 | A4 |
| `DualCore` | 5 | 620 | A4 |
| `Reconciliation` | 1 | 613 | A4 |
| `Brain` | 3 | 591 | A4 |
| `Knowledge` | 3 | 585 | A4 |
| `Compaction` | 4 | 492 | A4 |
| `CognitiveMemory` | 2 | 466 | A4 |
| `Search` | 2 | 456 | A4 |
| `VerifiedContextExecution` | 1 | 442 | A4 |
| `Cartography` | 1 | 388 | A4 |
| `SoftwareCompany` | 1 | 364 | A4 |
| `Gateway` | 2 | 357 | A4 |
| `Operator` | 4 | 323 | A4 |
| `Transcription` | 1 | 304 | A4 |
| `HumanSurface` | 1 | 290 | A4 |
| `Forge` | 2 | 287 | A4 |
| `Tasks` | 1 | 284 | A4 |
| `Mcp` | 1 | 283 | A4 |
| `Tokens` | 2 | 275 | A4 |
| `RuntimeReleaseGate` | 1 | 263 | A4 |
| `Streaming` | 1 | 167 | A4 |
| `Instrumentation` | 1 | 155 | A4 |
| `MemoryGovernance` | 3 | 148 | A4 |
| `Security` | 1 | 55 | A4 |

**Contagem:** A1=8 A2=13 A3=33 A4=66 total=120


## Appendix B — App Services fora de Ai (nominativo)


### Pastas `app/Services/<X>` non-Ai

| Bucket | Files | LOC | WAVE |
|---|---:|---:|---|
| `app/Services/Engineering` | 144 | 72,135 | B* |
| `app/Services/AtlasCode` | 33 | 11,288 | B* |
| `app/Services/Semantic` | 19 | 5,385 | B* |
| `app/Services/Tools` | 14 | 4,309 | B* |
| `app/Services/MacAgent` | 1 | 1,686 | B* |
| `app/Services/Vault` | 7 | 1,446 | B* |
| `app/Services/Digital` | 7 | 1,235 | B* |
| `app/Services/Bitacula` | 1 | 541 | B* |

### Arquivos soltos em `app/Services/*.php`

| File | LOC | WAVE |
|---|---:|---|
| `app/Services/ProjectExecutionService.php` | 2,346 | B8 |
| `app/Services/CaptureService.php` | 1,272 | B8 |
| `app/Services/TaskPlanningService.php` | 1,171 | B8 |
| `app/Services/ProjectPlanningService.php` | 615 | B8 |
| `app/Services/ProjectBlockerService.php` | 563 | B8 |
| `app/Services/CaptureDestinationService.php` | 511 | B8 |
| `app/Services/WhisperTranscriber.php` | 348 | B8 |
| `app/Services/RoutineSchedulingService.php` | 334 | B8 |
| `app/Services/BitaculaService.php` | 206 | B8 |
| `app/Services/CaptureDeletionService.php` | 201 | B8 |
| `app/Services/AtlasDomainRegistry.php` | 195 | B8 |
| `app/Services/BitaculaServiceSupport.php` | 137 | B8 |
| `app/Services/CapturePrivacyService.php` | 123 | B8 |
| `app/Services/AuditLogService.php` | 115 | B8 |
| `app/Services/CaptureFileStorage.php` | 76 | B8 |