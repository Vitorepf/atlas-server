# Plano — Refatoração Completa do Atlas Server (Full-Pass · 30 áreas)

> **For agentic workers (Autônomos / Dev / Forge):** este é um **PROGRAM PLAN**.  
> Execute na ordem das fases. Cada fase percorre um conjunto das **30 áreas**.  
> **Done do programa** = scoreboard 1→30 preenchido com prova + gates verdes — não “refatorei bastante”.

| | |
|---|---|
| **Status** | PLANEJADO — execução sob mandate do operador / standing Autônomos |
| **Repo** | `atlas-server` **somente** (PHP/Laravel cérebro) |
| **Canon de áreas** | `docs/engineering-knowledge-base/atlas-full-pass-hygiene-areas.md` |
| **Branch** | local `main` only · commits escopados · zero merge de obra |
| **Tipo** | hygiene / structure · **zero feature de produto** |
| **Executor default** | Autônomos (`atlas:brain` → `atlas:task`) · mesma barra elite L0–L5 |
| **Evidence** | `docs/evidence/2026-07-24-atlas-server-full-pass/` (criar na Fase 0) |

---

## 0. Compreensão do objetivo (pétreo)

**O que é este plano**

Rodar a **lista curta inteira (30 áreas)** no **Atlas Server** como uma limpeza/estruturação máxima:

refatorar · defatorar · otimizar · reaproveitar · padronizar · estruturar arquitetura · eliminar · quarentenar · densificar · fundir · rehome · desacoplar · honestidade · contratos · simplificar lógica · invariantes · golden · gates · reliability · anti-Goodhart · navigability · DX · docs-mapa · governança · pipes · world-model · superfícies · migrate-n/a · operate-vs-legado · agent QoS.

**O que não é**

- Não é rewrite PHP→Swift.  
- Não é feature nova (Rivals, Marketing, etc.).  
- Não é “só delete LOC” (anti-Goodhart).  
- Não é cherry-pick de 2–3 áreas e declarar vitória.

**Done do programa**

```text
SCOREBOARD 30/30 visitado
cada area_id ∈ {done, partial, n/a, blocked} com prova
partial|blocked → DEBT acionável
package gates green
anti_goodhart: pass
LEDGER commitado
```

---

## 1. Baseline (medir na Fase 0 — atualizar números ao start)

Medição de partida (snapshot ~2026-07-24; **re-medir** no dia 0 real):

| Métrica | Valor aproximado |
|---|---:|
| PHP em `app/` | ~6 496 arquivos |
| LOC `app/` (ordem) | ~1.28M (wc agregado) |
| Pastas top-level `app/Services/Ai` | ~171 |
| Commands | ~953 |
| Godfiles / monólitos quentes | `AutonomousEvolutionSessionService` ~3.6k · `AtlasLedgerReplayService` ~2.2k · `AiWorker` ~2.0k · OpenBrain MCP ~2.0k · Readiness residual · Holding fixtures |
| Operate path vivo | `atlas:brain:*` / `atlas:task:*` |
| ACDE | morto operate · keep-list `AtlasLoop*` viva |
| Programas já corridos (herança) | GOD-DEBULK · Núcleo Essencial · AAEOS-MT P0–P3b · RootSingles · Quarantine ACDE · dedup traits |

**Alvos de programa (falsificáveis — não proxies):**

| Alvo | Critério |
|---|---|
| Achar | hot path ≤3 hops · CODEMAP Ai cobre façades públicas da zona |
| Densidade | **0** PHP novo >2000 sem floor documentado; teto section ≤1500 |
| Ownership | 0 capability com 2+ entrypoints públicos não-alias na zona operate |
| Morto | 0 dangling live refs; operate-path sem entrypoint legado |
| Prova | characterization/golden nos monstruos tocados; gates do pacote verdes |
| Agent-optimal | Autônomo encontra, edita, prova sem arqueologia |

---

## 2. Leis globais (todas as fases)

1. **main only** · `git add -- <paths>` · 1 lote = 1 commit revertível.  
2. **Behavior-preserving** por default; change de comportamento = mandate de change (fora deste plano).  
3. **SPLIT before fuse** · fuse same-concern only · nunca godfile novo.  
4. **Golden antes** de split estrutural em monstro opaco.  
5. **Delete** só com 0 refs **vivas** (teste-espelho ≠ vida) + restore-fixpoint.  
6. **Keep-list** `AtlasLoop*` / RSI-core / Evidence intocável / floors sagrados = `blocked` se redesenho, não force.  
7. **Não** rodar suíte full destrutiva (risco wipe DB); goldens standalone / testes do pacote.  
8. **Commits:** `refactor(full-pass): A##-<area_id> <foco>` · `test(full-pass): …` · `docs(full-pass): …` — nunca `feat`.  
9. **Anti-Goodhart:** LOC↓ / file-count↓ / pass++ **não** fecham área.  
10. **Scoreboard:** ao fim de cada fase, atualizar as 30 áreas no LEDGER (mesmo que `n/a` com razão).  
11. **Blackboard:** claim de path antes de editar em multi-sessão.  
12. **Pint** no pacote tocado se escreveu PHP.

---

## 3. Mapa fase × áreas (cobertura 100% das 30)

| Fase | Nome | area_ids cobertos (primário) | Secundários |
|---|---|---|---|
| **F0** | Fundação / baseline / tooling | `governance` `dx` `anti_goodhart` `gates` | `docs_map` |
| **F1** | Prova e morto | `golden` `eliminate` `quarantine` `operate_vs_legacy` | `reliability` |
| **F2** | Densidade / godfiles | `density` `refactor` | `golden` `gates` |
| **F3** | Ownership / rehome / arquitetura | `rehome` `architecture` `defactor` `navigability` | `docs_map` |
| **F4** | Fundição / reuso / pipes | `fuse` `reuse` `unify_pipes` `world_model` | `decouple` |
| **F5** | Padronização / semântica / lógica | `standardize` `surface_std` `honesty` `simplify` `invariants` `contracts` `decouple` | `refactor` |
| **F6** | Otimização medida + QoS | `optimize` `agent_qos` | `anti_goodhart` |
| **F7** | Reliability / superfícies / migrate | `reliability` `platform_migrate` `surface_std` | `operate_vs_legacy` |
| **F8** | Docs mapa · DX · fechamento | `docs_map` `dx` `governance` `navigability` `anti_goodhart` `gates` | **todas** (scoreboard final) |

`platform_migrate` no server = **n/a** se não houver migração de stack; registrar explicitamente.

---

## 4. Fases executáveis

### F0 — Fundação (bloqueia o resto)

**Áreas:** `governance` · `dx` · `anti_goodhart` · `gates` · `docs_map`

- [ ] Criar evidence dir:
  - `docs/evidence/2026-07-24-atlas-server-full-pass/LEDGER.md`
  - `docs/evidence/2026-07-24-atlas-server-full-pass/DEBTS.md`
  - `docs/evidence/2026-07-24-atlas-server-full-pass/SCOREBOARD.md` (template 30 áreas = pending)
- [ ] Re-medir baseline (files, LOC, top-20 monólitos, commands, Ai folders).
- [ ] Confirmar branch = `main`.
- [ ] Confirmar keep-list Autônomos vivo (doc canônico).
- [ ] Linkar canon: `atlas-full-pass-hygiene-areas.md`.
- [ ] Definir **zona inicial** (default recomendado):
  1. `app/Services/Ai/SelfConstruction/`
  2. `app/Services/Ai/Aaeos/`
  3. `app/Services/Ai/Kernel/`
  4. Root pipes: `AiWorker` · `AiGatewayService` · `AtlasDecideService`
  5. Expandir WAVE-a-WAVE até 100% `app/Services/Ai` + Commands quentes + `config/atlas*.php`
- [ ] Script/comandos de prova do pacote (lista no LEDGER):
  - `php -l` nos paths
  - `composer dump-autoload -o`
  - boot smoke / command count delta
  - testes unit do pacote (não suíte full cega)
  - `vendor/bin/pint --dirty` ou paths

**Exit F0:** SCOREBOARD criado · baseline colada · mandate texto no LEDGER.

---

### F1 — Prova + eliminação + operate-path

**Áreas:** `golden` · `eliminate` · `quarantine` · `operate_vs_legacy` · `reliability` (mínimo)

- [ ] **Golden:** capturar characterization das façades dos monstruos da zona (standalone/`php -r` ou unit já existente — **sem** Feature suite destrutiva).
- [ ] **Eliminate:** scan 0-caller **vivo** (não confiar só em teste); lotes pequenos; restore-fixpoint.
- [ ] **Quarantine:** órfãos ACDE / paper-machinery / theater já catalogados → archive se ainda no live tree.
- [ ] **Operate vs legacy:** garantir que `atlas:loop:*` não é operate path; entrypoints vivos = brain/task + façades AAEOS/SelfConstruction documentadas.
- [ ] **Reliability:** remover secrets em paths; failure modes tipados em edits novos; sem swallow de Throwable em gates de prova.

**Alvos prioritários herdados (verificar se ainda existem):**

| Alvo | Motivo |
|---|---|
| ExternalBrain advisory residual | prune já feito; re-scan unwired |
| ACDE órfãos residuais | quarantine CSV / keep-list |
| Wiring ASP dangling | bind-to-missing |
| tests/Archive residual | delete se reaparecer |
| Commands mortos | signature sem Schedule/call/config |

**Exit F1:** morto da zona 0 refs vivas · operate-path honesto · goldens dos monstruos existem.

---

### F2 — Densidade / godfiles (SPLIT)

**Áreas:** `density` · `refactor` · (+ re-`golden` · `gates`)

**Lei:** section ≤1500 · any file ≤2000 **ou** floor documentado no LEDGER.

**Monstruos a tratar (lista viva — re-rank no start):**

| Prioridade | Arquivo / agregado | Estratégia |
|---|---|---|
| P0 | `AutonomousEvolutionSessionService` (~3.6k+) | extract families; RSI-core na façade; golden-gated |
| P0 | `AtlasLedgerReplayService` (~2.2k) | family split **sem** quebrar Evidence API; floor se blueprint intocável |
| P0 | `AiWorker` (~2.0k) | leaf sections; hot-path side-effect order preservado |
| P1 | OpenBrain MCP / ContextInjection / ContextPack | split + scanner pins coordenados |
| P1 | Readiness residual (hub + sections >1500) | trait/section ≤1500; sem redesign probe sem operador |
| P1 | Holding / EnterpriseFlowFixture residual | data-table / sections; floor se run() monólito |
| P2 | Commands >800 LOC quentes | thin command → façade |
| P2 | Controllers HTTP >800 | thin controller |
| P2 | Qualquer `app/` PHP >2000 novo | split imediato |

Protocolo por monstro:

```text
1. golden APIs públicas
2. extract leaf families verbatim / byte-identical preferido
3. façade pins sagrados
4. php -l + pacote tests + pint
5. commit refactor(full-pass): A09-density <nome>
6. se floor: LEDGER floor_reason + operator_present?
```

**Exit F2:** top monstruos da zona no teto ou floor honesto · 0 godfile **novo**.

---

### F3 — Rehome · ownership · defatoração · arquitetura

**Áreas:** `rehome` · `architecture` · `defactor` · `navigability` · `docs_map`

- [ ] Completar / verificar **RootSingles** (6 pipes canônicos na raiz; resto em owners).
- [ ] **OWNERSHIP** por pasta Ai da zona (1 owner / capability).
- [ ] **Defatoração:** colapsar entrypoints gêmeos (SelfConstruction × AAEOS × AutonomousEvolution × Stewardship × Programming) — façade única por capability; alias documentado se transição.
- [ ] **AAEOS purify residual:** Control+Spine operate; resto support/quarantine.
- [ ] **CODEMAP:** `app/Services/Ai/CODEMAP.md` (e CODEMAPs por WAVE se necessário) — path → `Class::method` hot.
- [ ] **Nucleus alignment:** mapear pasta → órgão N1–N12 ou Coroa/Quarentena (doc Nucleus).
- [ ] Remover indirection “OS gêmeo” sem segundo consumidor real.

**Exit F3:** hops ≤3 nos hot paths da zona · CODEMAP útil · 0 twin façade não-alias.

---

### F4 — Fundição · reaproveitamento · pipes · world-model

**Áreas:** `fuse` · `reuse` · `unify_pipes` · `world_model` · `decouple`

- [ ] **Fuse** peels same-concern (<~80 LOC, 1–2 callers) **depois** dos splits.
- [ ] **Reuse:** continuar sweep de helpers byte-identical → traits por família (Kernel, OpenBrain, SelfConstruction Support, Hermes, Marketing, …).
- [ ] **Unify pipes:**
  - Provider registries (AiProviderManager × driver registry — zerar drift de keys).
  - Runtime real × ToolRuntime mock × RealExecution × AVER — seguir blueprint RuntimeExecution (sem reanimar mock como “real”).
  - Ledgers → kernel `JsonlReceiptStore` onde ainda houver FILE_APPEND paralelo injustificado.
- [ ] **World-model:** code-graph fusion (AP-815) — **não** ativar dead-fed como vivo; honesty se edges dormants.
- [ ] **Decouple:** policy services sem I/O; projection sem side-effect de write.

**Exit F4:** duplicatas ≥3 da zona unificadas · pipe drift DEBT ou resolvido · fuse sem monstro.

---

### F5 — Padronização · honestidade · lógica · contratos

**Áreas:** `standardize` · `surface_std` · `honesty` · `simplify` · `invariants` · `contracts` · `decouple` · `refactor`

- [ ] **Vocabulário fechado** em classes novas/tocadas: famílias `decide*` `pack*Context` `rank*` `certify*` `project*` `run*`.
- [ ] Sufixos honestos — banir `Helper`/`Util`/`Manager` sem boundary em código novo.
- [ ] **Surface_std:** commands/HTTP/MCP usam mesmos nomes de capability; thin adapters.
- [ ] **Honesty rename** onde o nome mente (sem big-bang cosmético fora de zona).
- [ ] **Simplify:** reduzir god-switch / nesting nos hot paths tocados; early returns.
- [ ] **Invariants:** asserts/policies nomeadas nos gates da zona.
- [ ] **Contracts:** 1 entrypoint público por capability; dual-read só se cutover de schema já em curso (AAEOS receipts) — não inventar versão nova neste plano.
- [ ] Pint + estilo consistente no pacote.

**Exit F5:** zona lexicalmente canônica · contratos estáveis · lógica mais rasa nos hot paths.

---

### F6 — Otimização medida · Agent QoS

**Áreas:** `optimize` · `agent_qos` · `anti_goodhart`

- [ ] Medir **antes** (boot time smoke, command count, context pack budget se tocado, hot path steps).
- [ ] Otimizar só com delta medido: menos I/O no loop, menos hops, density, compaction honesta.
- [ ] **Agent QoS:** se a zona toca Autônomos/Rivals/QoS — garantir medição honesta (não falso-seguro); alinhar a curriculum/anti-ceiling docs.
- [ ] Recusar “otimização” que só reduz LOC sem ganho de hops/custo/clareza.

**Exit F6:** pelo menos 1 ganho medido na zona **ou** `n/a` com “sem hot path caro” · anti_goodhart pass.

---

### F7 — Reliability · migrate · superfícies

**Áreas:** `reliability` · `platform_migrate` · `surface_std` · `operate_vs_legacy`

- [ ] Secrets / tokens: nenhum commit de credencial; configs sensíveis fora do repo.
- [ ] Error types: não engolir Throwable em paths de prova.
- [ ] Rollback documentado por lote grande.
- [ ] **platform_migrate:** `n/a` (server permanece PHP) — registrar no SCOREBOARD.
- [ ] Superfícies CLI/MCP/HTTP: thin + ownership; signatures de command alinhadas ao CODEMAP.

**Exit F7:** reliability checklist da zona verde · platform_migrate n/a explícito.

---

### F8 — Docs · DX · fechamento full-pass

**Áreas:** `docs_map` · `dx` · `governance` · `navigability` · `anti_goodhart` · `gates` · **revisão de todas as 30**

- [ ] Atualizar CODEMAP + OWNERSHIP + ekb pointers vivos (não archive).
- [ ] Marcar LEGADO onde ainda houver doc de loop/ACDE no caminho operate.
- [ ] DEBTS: toda área `partial`/`blocked` vira item acionável.
- [ ] SCOREBOARD final 1→30.
- [ ] Gates finais do pacote.
- [ ] Resumo executivo no LEDGER (o que mudou · o que ficou floor · próximos ciclos mensais).

**Exit F8 = Exit do programa** (ou Exit da **onda** se o corpus for multi-onda).

---

## 5. Ondas de cobertura do corpus (100% do server)

O full-pass **não cabe** em um único dia se o alvo for 100% do monólito. Use ondas:

| Onda | Escopo | Meta |
|---|---|---|
| **W0** | Tooling + SelfConstruction + Kernel + root pipes | operate path + monstruos centrais |
| **W1** | Aaeos + AutonomousEvolution (vivo) + Stewardship | defator OS gêmeos |
| **W2** | Programming + Rivals + Cognition/ACOS | façades eng |
| **W3** | Memory/OpenBrain/AOBG + Decide/Router/Provider | context/decide pipe |
| **W4** | Holding + Domains (Finance/Marketing/…) | density + thin commands |
| **W5** | Commands/Http/config split residual | superfície |
| **W6** | Tests monsters + docs mapa | T/D waves |

Cada onda **repete F1–F8** (ou subset) e **repreenche scoreboard 30**.  
Programa “completo” = todas as ondas com scoreboard fechado **ou** DEBT global priorizado com mandate contínuo Autônomos.

---

## 6. Floors operator-present (não forçar)

| Floor | Motivo | Status esperado se intocado |
|---|---|---|
| RSI-core / sacred gates EvolutionSession | side-effect ordering load-bearing | `blocked` ou floor documentado |
| Evidence / LedgerReplay API | append-only sagrado | `blocked` redesign |
| Readiness probe-mechanism hub | 170+ caps / method_exists | `blocked` redesign |
| AiWorker hot-path order | kernel offline load-bearing | floor / partial |
| Scanner pin files (MCP tokens in-file) | split exige coordenar testes | partial + coordinated |

---

## 7. Scoreboard template (copiar para SCOREBOARD.md)

```yaml
full_pass_id: atlas-server-full-pass-2026-07-24
wave: W0
scope: app/Services/Ai/SelfConstruction/**
areas:
  refactor:            { status: pending, proof: "" }
  defactor:            { status: pending, proof: "" }
  optimize:            { status: pending, proof: "" }
  reuse:               { status: pending, proof: "" }
  standardize:         { status: pending, proof: "" }
  architecture:        { status: pending, proof: "" }
  eliminate:           { status: pending, proof: "" }
  quarantine:          { status: pending, proof: "" }
  density:             { status: pending, proof: "" }
  fuse:                { status: pending, proof: "" }
  rehome:              { status: pending, proof: "" }
  decouple:            { status: pending, proof: "" }
  honesty:             { status: pending, proof: "" }
  contracts:           { status: pending, proof: "" }
  simplify:            { status: pending, proof: "" }
  invariants:          { status: pending, proof: "" }
  golden:              { status: pending, proof: "" }
  gates:               { status: pending, proof: "" }
  reliability:         { status: pending, proof: "" }
  anti_goodhart:       { status: pending, proof: "" }
  navigability:        { status: pending, proof: "" }
  dx:                  { status: pending, proof: "" }
  docs_map:            { status: pending, proof: "" }
  governance:          { status: pending, proof: "" }
  unify_pipes:         { status: pending, proof: "" }
  world_model:         { status: pending, proof: "" }
  surface_std:         { status: pending, proof: "" }
  platform_migrate:    { status: pending, proof: "" }
  operate_vs_legacy:   { status: pending, proof: "" }
  agent_qos:           { status: pending, proof: "" }
anti_goodhart: pending
gates: pending
```

Status permitidos: `pending` | `done` | `partial` | `n/a` | `blocked`.

---

## 8. Mandate pronto para Autônomos (colar no packet)

```text
PROGRAM: Atlas Server Full-Pass Refactor Complete
PLAN: docs/superpowers/plans/2026-07-24-atlas-server-full-pass-refactor-complete.md
CANON: docs/engineering-knowledge-base/atlas-full-pass-hygiene-areas.md
WAVE: W0
SCOPE: app/Services/Ai/SelfConstruction/** + Kernel hot + root pipes
RULES:
  - visit ALL 30 area_ids; fill SCOREBOARD
  - no product features
  - behavior-preserving default
  - main only; scoped commits
  - split before fuse; golden before structural split
  - no prefix delete; keep-lists intact
  - no destructive full suite
  - floors operator-present = blocked, not force
DONE:
  - SCOREBOARD complete for wave
  - package gates green
  - anti_goodhart pass
  - LEDGER + DEBTS updated
```

---

## 9. Relação com obras anteriores (não recomeçar do zero)

| Obra | Herdar |
|---|---|
| GOD-DEBULK | leis densidade, FILESYSTEM-100, blueprints, −91% godfiles claim |
| Núcleo Essencial | proof-of-death, ExternalBrain prune, ACDE waves |
| RootSingles | rehome 89 movers |
| AAEOS-MT | receipts v3, spine, P0–P3b |
| Dedup traits | reutilizar Support traits já criados |
| Elite executors | mesma barra Dev/Forge/Autônomos |
| Full-pass hygiene areas | **menu 30 áreas** (este plano **executa** o menu no server) |

Este plano **une** herança + full-pass 30 em um programa contínuo de ondas — não apaga o que já foi feito; **fecha gaps** e **institucionaliza** a limpeza.

---

## 10. Halt conditions

Parar e DEBT se:

- keep-list / RSI / Evidence em risco  
- fuse → arquivo >2000  
- split sem golden em monstro opaco  
- behavior change sem mandate  
- multi-sessão colidindo sem claim  
- vanity scoreboard  
- suite destrutiva  

---

## 11. Ordem de kickoff (operador ou Autônomo)

1. Operador: **OK de mandate** (ou standing hygiene Autônomos).  
2. Agente: executar **F0** (criar evidence + baseline + SCOREBOARD).  
3. Agente: **W0 × F1→F8**.  
4. Scoreboard W0 fechado → W1…W6.  
5. Programa completo quando todas as ondas tiverem scoreboard fechado **ou** DEBT residual só com floors operator-present.

---

## 12. Checklist visual das 30 áreas (tracking manual)

Copiar e marcar na execução da onda atual:

- [ ] 1 refactor  
- [ ] 2 defactor  
- [ ] 3 optimize  
- [ ] 4 reuse  
- [ ] 5 standardize  
- [ ] 6 architecture  
- [ ] 7 eliminate  
- [ ] 8 quarantine  
- [ ] 9 density  
- [ ] 10 fuse  
- [ ] 11 rehome  
- [ ] 12 decouple  
- [ ] 13 honesty  
- [ ] 14 contracts  
- [ ] 15 simplify  
- [ ] 16 invariants  
- [ ] 17 golden  
- [ ] 18 gates  
- [ ] 19 reliability  
- [ ] 20 anti_goodhart  
- [ ] 21 navigability  
- [ ] 22 dx  
- [ ] 23 docs_map  
- [ ] 24 governance  
- [ ] 25 unify_pipes  
- [ ] 26 world_model  
- [ ] 27 surface_std  
- [ ] 28 platform_migrate  
- [ ] 29 operate_vs_legacy  
- [ ] 30 agent_qos  

---

## 13. Changelog

| Data | Nota |
|---|---|
| 2026-07-24 | v1 — plano completo atlas-server full-pass 30 áreas; fases F0–F8; ondas W0–W6; mandate Autônomos; scoreboard; herança GOD-DEBULK/Núcleo/AAEOS. |

---

**Frase de bolso**

> Full-pass no **Atlas Server**: as **30 áreas**, por **ondas**, com **prova**, sem feature, sem vanity — até o scoreboard fechar.
