# PATAMAR SUPERIOR — Plano-Mestre de continuação (MASTER · GOD · AAEOS)

| | |
|---|---|
| **Status** | PLANEJADO — aguarda token do operador por fase |
| **Data** | 2026-07-27 |
| **Repo** | `atlas-server` **somente** |
| **Branch** | local `main` only · commits escopados · zero merge |
| **Evidence** | `docs/evidence/2026-07-27-patamar-superior/` (criar na Fase 0) |
| **Herda de** | AAEOS MASTER (vFINAL-COOKBOOK) · GOD-DEBULK HUB · Núcleo Essencial v2 · Full-Pass 30 áreas · ASDD seal |
| **Não substitui** | nenhum plano acima — este **encadeia** o que ficou aberto |

---

## 0. A tese (por que este plano não é "mais refactor")

Medi o corpus em 2026-07-26 com 11 detectores mecânicos. **O eixo mecânico secou:**

| Detector | Resultado | Veredito |
|---|---:|---|
| Arquivos duplicados (corpo normalizado) | **0 grupos** | exaurido |
| Dead code de serviço (fora de Commands) | **14 classes** de 6.672 | exaurido |
| Classes delegadoras puras | **5** | exaurido |
| Métodos duplicados | 214 grupos ≈ **4.259 linhas** | 0,27% do `app/` |
| Total removível por refactor mecânico | **≈ 12.000 linhas de 1.585.786** | **0,8%** |

Continuar nesse eixo é exatamente o Goodhart que o `CLAUDE.md` proíbe: *"Refactor que preserva comportamento = melhoria ZERO."*

**Mas dois eixos NÃO estão secos** — e são justamente os que o operador pediu ("patamar superior, mais poderoso e inteligente"):

| Eixo | O que é | Baseline medido 2026-07-26 |
|---|---|---|
| **N — Navegabilidade** | quanto esforço um **agente** gasta para achar/entender/editar (critérios A–G do GOD-DEBULK) | **~2/10** — ver §1 |
| **F — Fusão de capability** | mesma capacidade implementada em 4–8 camadas paralelas | **8 capabilities duplicadas** — ver §2 |
| **C — Capacidade** | AAEOS R106/R107/R108: qualidade multi-loop, M falsificável, auto-evolução noturna | **NOT_CLAIMED** — ver §3 |

Refactor mecânico faz o repo menor. **Estes três fazem o Atlas mais inteligente** — porque o consumidor do código é o próprio Atlas quando se auto-programa. Cada hop de arqueologia é token gasto e erro cometido pelos Autônomos.

**Lei anti-Goodhart deste plano:** LOC deletada **nunca** é critério de done. Todo done é um predicado falsificável com comando colado.

---

## 1. Baseline N — Navegabilidade (critérios A–G do GOD-DEBULK)

Medido agora, não herdado:

| Crit. | Alvo GOD-DEBULK | **Medido 2026-07-26** | Gap |
|---|---|---|---|
| **A1** | CODEMAP cobre 100% das façadas públicas | `app/Services/Ai/CODEMAP.md` = **79 linhas · ~40 rows** para **6.656 arquivos / 169 pastas**; auto-rotulado `GOD-DEBULK-CODEMAP: INCOMPLETE` | **~0,6%** |
| **A3** | `php artisan list` ≤ **12** famílias documentadas | **188 famílias** · 939 comandos `atlas:*` | **15,7×** acima |
| **B1** | 0 arquivo PHP >2000 LOC | **2** (`AutonomousEvolutionSessionService` 3.597 · `AgentControlPlaneTaskQueueOrchestrator` 2.074) | quase lá |
| **B2** | 0 arquivo >800 LOC em façada pública/Command/Controller quente | **33** arquivos >1500 LOC | aberto |
| **C1** | `OWNERSHIP.md` `status: accepted` em 100% das pastas ≥500 LOC | **0 arquivos OWNERSHIP.md** no repo · ~20 pastas ≥500 LOC | **0%** |
| **C4** | 0 par SelfConstruction×AAEOS×AutonomousEvolution×Stewardship com façada duplicada | **8 capabilities duplicadas** (§2) | **0%** |
| **F1** | gates de arquitetura verdes | **13 de 97 VERMELHOS** (regressão 24/07, §4.1) | RED |
| **F4** | medição honesta | `atlas:aaeos:certify` → `ok=false`, `dimensions: null`, `composite: null` | **cego** |

**Distribuição de massa** (onde a navegabilidade dói mais):

```
352.788 LOC  SelfConstruction/     ← 22% de todo o app/
134.288 LOC  Programming/
118.280 LOC  SoftwareCompanyStewardship/
 44.114 LOC  AgenticEngineeringOs/
 44.057 LOC  Kernel/
 43.977 LOC  Holding/
 41.148 LOC  AutonomousEvolution/
```

As 3 primeiras = **605k LOC = 38% do app/**. É onde N e F se resolvem ou não se resolvem.

---

## 2. Baseline F — as 8 capabilities com façada duplicada

Medido por stem de nome de classe cruzado com as 9 camadas OS. Critério C4 do GOD-DEBULK proíbe exatamente isto:

| Capability | Classes | Camadas | Concentração |
|---|---:|---:|---|
| `Receipt` | 95 | **7** | SelfConst=52 · Programming=13 · Kernel=9 · EngKernel=9 · AutoEvo=6 |
| `Evidence` | 85 | **8** | SelfConst=51 · Programming=10 · Stewardship=7 · Kernel=6 |
| `Ledger` | 80 | **6** | SelfConst=36 · AutoEvo=13 · Kernel=12 · Stewardship=9 |
| `Contract` | 73 | **7** | Stewardship=28 · SelfConst=15 · Kernel=10 |
| `Executor` | 66 | **7** | SelfConst=43 · Programming=10 · Stewardship=6 |
| `Policy` | 63 | **6** | SelfConst=44 · Programming=7 · Kernel=6 |
| `Merge` | 62 | **4** | SelfConst=43 · Stewardship=12 · AutoEvo=4 |
| `Readiness` | 115 | **6** | SelfConst=95 · Programming=9 · EngKernel=5 |

**Leitura:** `Receipt`+`Evidence`+`Ledger` = **260 classes em 8 camadas** para uma única espinha conceitual: *provar que algo aconteceu*. A AAEOS MASTER §R20 já proíbe ("counter store duplicates Ledger → **PROHIBITED**") e §1.4 já nomeia o dono (`AtlasEvidenceLedger` com `event_id` INSERT-hard). **A lei existe; a implementação não a seguiu.**

Esta é a "fundição de blocos em patamar superior" pedida — e não é fundir arquivos, é **eleger um dono por capability e rebaixar os outros a adapters finos**.

---

## 3. Baseline C — o que a AAEOS deixou aberto

Do `SCOREBOARD.md` do Absolute Closure (25/07), verificado:

| Residual | Estado | O que destrava |
|---|---|---|
| R104 path law + LIVE L2 | **GREEN** | FC nativo provado com Hermes real |
| CUTOVER-V3-LIVE | **GREEN** | assinatura V3 com APP_KEY |
| `absolute_master_done` | **true** | — |
| **R106-LIVE** | NOT_CLAIMED | qualidade multi-loop é lei do caminho (spec→author→judge→verify→repair→land→settle) |
| **R107-LIVE** | NOT_CLAIMED | M_excellence ≥50× **falsificável** em fronteira não-saturada |
| **R108-LIVE** | NOT_CLAIMED | loop noturno fechado: promove escola → origina → landa → remede |
| R105 | NOT_CLAIMED | autoridade de governor em workspace efêmero (o P2 dos problemas conhecidos) |
| R103-PUBLIC cold matrix | NOT_CLAIMED | selo formal opcional |
| Delete família PRE | NOT_CLAIMED | suíte de caracterização retida |

**R106/R107/R108 são o eixo "mais inteligente".** O MASTER já tem as slices escritas (`P2g-QOS`, `P2g-CURR`, `P2g-MEAS`, `P2g-EVOL`) com hard-done conditions. **Não reescrever — executar.**

---

## 4. Leis globais (herdadas — violação = HALT)

1. **`main` only** · `git add -- <paths>` · nunca `-A` · zero merge · push só com OK do operador.
2. **NUNCA rodar a suíte** (`php artisan test` / `phpunit` / `pest`) — Núcleo Essencial §1.2, causa de 2 wipes do Postgres vivo. Prova = captura standalone via `php -r`/script com clock congelado (§2.7 do Núcleo Essencial). **Exceção única:** após a Fase 0.2 (pin `$_SERVER`) o operador pode reautorizar explicitamente.
3. **Sessão paralela ativa.** Antes de cada commit: `git log --oneline -3 -- <alvo>`. Edições em `AppServiceProvider` commitam sozinhas.
4. **Nunca deletar `AtlasLoop*` por prefixo** — ~129 classes vivas sob nome legado.
5. **Não tocar** AAEL · Stewardship Loop · TerminalLoop · `Brain/` · `external-brain`.
6. **Hard bans da AAEOS §0.4** valem integralmente: nenhum organ novo · nenhuma classe da lista proibida · `GOD_SOTA`/`REAL_OPERATION` nunca a partir de PHPUnit ou score estático · implementador não emenda MASTER.
7. **Proibido re-injetar score.** As 3 dimensões `null` do certify são o P0 funcionando. Torná-las verdes por hardcode = hard-ban §0.4 (score fiction).
8. **SPLIT antes de fuse** · fuse só same-concern · nunca criar godfile novo.
9. 1 lote = 1 commit revertível.

---

# FASE 0 — DESTRAVAR A MEDIÇÃO (bloqueia todo o resto)

> Sem medir, toda fase seguinte é teatro. Esta fase não entrega capacidade — entrega o direito de afirmar qualquer coisa.

### 0.1 Consertar os 13 gates de arquitetura (regressão de 24/07)

**Causa exata, rastreada:** 8 arquivos em `app/Services/Ai/Kernel/Architecture/Scanner/*.php` fazem `base_path('routes/api.php')` hardcoded. O full-pass fatiou `routes/api.php` (762→90 linhas + 20 `require` para `routes/api/*.php`) em `df3448f2a`/`21f5865e4`/`d7bc171d7`. Scanners tocados pela última vez em **23/07** — um dia antes. As rotas funcionam (`route:list` confirma); os **guardas** cegaram.

**Fix-raiz (não 8 remendos):** já existe `ArchitecturePathHelper` — trait extraído em `095d5e7a1` justamente para essas 6 classes. Adicionar um método `routesSource(): string` que concatena `routes/api.php` + `routes/api/*.php`, e substituir os 8 call-sites.

```
DONE: os 13 testes de tests/Feature/Architecture voltam a passar
PROVA: php -r que instancia cada Audit e assere violations === []
       (NÃO rodar phpunit até 0.2)
```

### 0.2 Fechar a raiz do wipe de DB

`tests/bootstrap.php` **não existe** (`git log --all` = zero commits). `phpunit.xml` faz `bootstrap="vendor/autoload.php"`. O único guard vive em `tests/TestCase.php:29` — no `setUp()`, a camada que a memória de 15/07 provou ser teatro (PHPUnit seta `hasMetRequirements` **antes** do setUp, então o tearDown ainda dropa).

```
CRIAR: tests/bootstrap.php  → pin $_SERVER['DB_CONNECTION']='sqlite',
                               $_SERVER['DB_DATABASE']=':memory:', $_SERVER['DB_URL']=''
EDITAR: phpunit.xml bootstrap="tests/bootstrap.php" (que requer vendor/autoload)
DONE: com DB_CONNECTION=pgsql exportado no $_SERVER, um teste-canário ainda
      resolve sqlite. Prova por php -r, não por suíte.
```

### 0.3 Medir de verdade as 3 dimensões cegas

`operate_path_wiring`, `spine_enforced`, `antifragile_loop` = `null` · `measurement_sources: []` · `measurement_status: "unknown"`.

**Não inventar número.** Derivar cada uma de fato observável e registrar a fonte em `measurement_sources`:

| Dimensão | Fonte de fato proposta |
|---|---|
| `operate_path_wiring` | fração dos entrypoints operate (`atlas:brain:*`, `atlas:task:*`, Dev/Forge) que resolvem via DI Kernel — contável por reflexão do container |
| `spine_enforced` | fração das intakes Dev/Forge/Autônomos com stamp `AaeosSpineGate::stamp` no Evidence Ledger em janela N |
| `antifragile_loop` | outcomes → `pending_review` → aplicados, medidos no ledger em janela N |

```
DONE: certify devolve number|null com measurement_sources não-vazio para
      toda dimensão não-null. composite só existe se as 3 forem number.
      god_sota=false continua válido enquanto qualquer uma for null.
HALT: qualquer constante literal no caminho de score.
```

### 0.4 Congelar o baseline A–G

Gerar `docs/evidence/2026-07-27-patamar-superior/METRICS-BASELINE.json` com os números de §1 e §2, produzidos por **script versionado** (`scripts/patamar-baseline.php`), não à mão. Sem isso não há delta honesto depois.

**Gate de saída da Fase 0:** 13 gates verdes · pin de DB provado · certify com fonte declarada · baseline congelado e commitado.

---

# FASE 1 — NAVEGABILIDADE (o M do agente)

> Aqui mora o maior ganho de "inteligência" por unidade de esforço: cada hop economizado é token e erro poupado em **toda** execução futura dos Autônomos.

### 1.1 CODEMAP corpus-complete — **gerado, nunca escrito à mão**

Um CODEMAP manual apodrece em uma semana (o atual tem ~40 rows para 6.656 arquivos). Portanto:

```
CRIAR: php artisan atlas:codemap:build --write
       varre façadas públicas (classes com método público chamado de fora
       do próprio namespace), emite path → Class::method por pasta
GATE:  atlas:codemap:verify → exit 1 se alguma façada pública não estiver mapeada
WIRE:  o verify entra no pre-commit da missão
DONE:  cobertura de façadas públicas ≥ 95% em app/Services/Ai (medida pelo verify)
```

Anti-Goodhart: o verify mede **façadas públicas**, não arquivos. Split gratuito não move o número.

### 1.2 OWNERSHIP por pasta ≥500 LOC — gerado + **aceito por humano**

```
CRIAR: atlas:ownership:scaffold → gera OWNERSHIP.md draft por pasta
       (capability, dono único, entrypoints públicos, consumidores, status: draft)
OPERADOR/EXECUTOR: revisa e move para status: accepted
DONE:  100% das ~20 pastas ≥500 LOC com status accepted
HALT:  pasta sem dono claro → vira input da Fase 2, não ownership inventado
```

### 1.3 Colapsar 188 → ≤12 famílias de comando — **por alias, zero breaking**

188 famílias é o pior número do baseline. Mas 939 comandos documentados em `docs/` são a superfície L2 que o `atlas-terminal-first-focus.md` manda **investir**, não destruir.

```
LEI: nenhum comando some. Toda migração é ALIAS (nome antigo continua
     funcionando e é marcado deprecated no help, não removido).
FAZER: mapa 188 → ≤12 famílias canônicas
       (proposta inicial: brain · task · dev · forge · rivals · memory ·
        context · engineering · finance · marketing · ops · audit)
DONE:  php artisan list agrupa em ≤12 famílias documentadas
       E todo nome antigo ainda resolve (teste de alias por php -r)
```

**Gate de saída da Fase 1:** A1 ≥95% · C1 100% accepted · A3 ≤12 famílias com 100% dos aliases vivos.

---

# FASE 2 — FUSÃO DE CAPABILITY (o patamar superior)

> Não é fundir arquivos. É **eleger um dono por capability** e rebaixar os demais a adapters finos. A lei já existe na AAEOS (§R20, §1.4); falta a implementação obedecer.

### 2.1 Censo de capability (antes de mover uma linha)

```
CRIAR: docs/evidence/2026-07-27-patamar-superior/CAPABILITY-CENSUS.md
Para cada uma das 8 capabilities de §2:
  - inventário classe → camada → consumidores reais (não testes)
  - candidato a DONO ÚNICO com justificativa
  - lista de duplicatas → destino (adapter fino | delete | rehome)
HALT: censo que não distingue consumidor real de teste é inválido.
```

### 2.2 Ordem de fusão (dependência, não gosto)

| # | Capability | Dono proposto | Por quê primeiro |
|---|---|---|---|
| 1 | **Receipt + Evidence + Ledger** (260 classes / 8 camadas) | `AtlasEvidenceLedger` (já é lei em AAEOS §1.4/R20) | é a espinha de prova — **toda** fase seguinte depende de provar coisas |
| 2 | **Readiness** (115 / 6) | `SelfConstruction/Readiness` | maior massa isolada; 95 das 115 já moram lá |
| 3 | **Merge + Executor** (128 / 7) | `AtlasTaskMergeActuator` + `EliteExecutorKernel` | caminho de land — toca governança, exige o item 1 pronto |
| 4 | **Policy + Contract** (136 / 7) | `Kernel` | mais difuso; último |

### 2.3 Protocolo por capability (obrigatório, sem atalho)

```
1. GOLDEN standalone ANTES (php -r, clock congelado, ≥1 token asserido
   por caminho — return [] nunca passa verde)
2. Dono único recebe a superfície completa
3. Duplicatas viram adapter fino OU delete (com closure de componente-conexo)
4. GOLDEN depois → diff byte-idêntico no comportamento
5. Commit escopado por capability
```

**Anti-armadilha herdada (Núcleo Essencial D6/D8):** `class_exists` estático **não** enxerga `try/catch` nem nullable-default. Rodar sempre **restore-fixpoint** pós-deleção. Reference dentro de string literal **não** é code-ref.

**Gate de saída da Fase 2:** C4 = 0 pares duplicados nas 8 capabilities · goldens verdes antes e depois · nenhum godfile novo.

---

# FASE 3 — CAPACIDADE (AAEOS R106 / R107 / R108)

> **Não reescrever nada.** As slices já existem no MASTER com hard-done conditions. Esta fase é execução do DAG existente.

Ordem binding (do MASTER §0.5):

```
P2g-QOS  (R106) → P2g-CURR (R108 parcial) → P2g-MEAS (R107) → P2g-EVOL (R108)
```

| Slice | Entrega | Hard-done resumido (texto completo no MASTER §3.3–3.5) |
|---|---|---|
| `P2g-QOS` | excelência multi-loop vira **lei do caminho** | profundidade resolvida no servidor (raise-only, sem dial no CLI) · arquitetura ≠ implementação fail-closed · zero `needs_review` técnico de rotina · timeout nunca promove |
| `P2g-CURR` | escada de currículo ligada | `S_sanity`/`S_frontier`/`S_horizon` |
| `P2g-MEAS` | **M ≥ 50× falsificável** | em fronteira **não-saturada** (80–100% = nível escolar, não teto — doc pétreo do currículo) |
| `P2g-EVOL` | loop noturno fechado | promove escola → origina → landa → remede, zero operador no loop |

**Pré-condição dura:** R106 não pode ser reivindicado enquanto R104 estiver aberto (§3.3 item 8). R104 está **GREEN** — logo está liberado.

**Gate de saída da Fase 3:** os 4 `PHASE-P2G-*.json` com `live_proofs[]` não-vazio. `REAL_OPERATION` jamais a partir de PHPUnit.

---

# FASE 4 — LIGAR (a chave é do operador)

Do manifesto do ACOS Max: das 48 caixas abertas, **R1 é a raiz nº 1** e cascateia sozinha a maioria.

| Flag | Estado hoje | Preflight | Destrava |
|---|---|---|---|
| `ATLAS_LOOP_MASTER_ENABLED` | `false` no `.env` | ASI-06 8/8 verde | flywheel gira → enche TODAS as janelas de soak |
| `ATLAS_AUTONOMOUS_AUTO_APPLY` | default `false` | ASI-07 4/4 verde | ponte delta→memória |
| `ATLAS_AI_HERMES_NATIVE_FC_ENABLED` | default `false` | **LIVE L2 provado** | FC nativo permanente (mata o P1 JSON³) |
| `ATLAS_AI_DECISION_RECEIPT_V3_CUTOVER_ENABLED` | default `false` | **LIVE provado** | cutover V3 permanente |

**Fato duro:** heartbeat do scheduler parado desde `2026-07-13T04:05:41Z` — **13 dias**. `served_ratio=46%` sobre 50 ciclos decisivos. Enquanto isso, as janelas de soak do ACOS Max não enchem e o "que falta" só cresce.

**Esta fase não é código. É uma decisão sua.**

---

## 5. SCOREBOARD do programa (done = predicado, nunca LOC)

| # | Predicado | Medição | Baseline | Alvo |
|---|---|---|---:|---:|
| 1 | gates de arquitetura verdes | `tests/Feature/Architecture` | 84/97 | **97/97** |
| 2 | pin de DB à prova de `$_SERVER` | canário `php -r` | ausente | **passa** |
| 3 | dimensões do certify medidas | `measurement_sources` não-vazio | 0/3 | **3/3** |
| 4 | cobertura CODEMAP de façadas públicas | `atlas:codemap:verify` | ~0,6% | **≥95%** |
| 5 | OWNERSHIP accepted em pastas ≥500 LOC | contagem de arquivos | 0% | **100%** |
| 6 | famílias de comando | `artisan list` | 188 | **≤12** |
| 7 | aliases antigos vivos | teste de alias | n/a | **100%** |
| 8 | capabilities com façada duplicada | censo | 8 | **0** |
| 9 | R106/R107/R108 LIVE | `PHASE-P2G-*.json` com `live_proofs[]` | 0/3 | **3/3** |
| 10 | flips do operador | `.env` | 0/4 | decisão do operador |

**Done do programa** = 1–9 verdes **no mesmo corte** + LEDGER commitado + `anti_goodhart: pass`.
**Proibido** declarar vitória por LOC↓ ou file-count↓.

---

## 6. Decisões que só o operador toma (bloqueiam fases)

| # | Decisão | Bloqueia | Contexto |
|---|---|---|---|
| **D-1** | Cluster ACDE quebrado (Núcleo Essencial **D4**) — deletar componente-conexo inteiro **ou** restaurar os 5 deps de `cd018` | Fase 2 item 2 | `AtlasLoopTaskGrinder` injeta deps deletados; ~30 testes `Feature/Loop` pendurados; **não é dead-solto** |
| **D-2** | Token `EXECUTE GOD-DEBULK` | retomada dos 472 buckets restantes | `EXEC-LEDGER` parado em `queue_index: 6` de 478, com `halt_conditions_hit` aberto |
| **D-3** | Reautorizar rodar suíte após o pin (§0.2) | prova por PHPUnit em vez de `php -r` | a lei "nunca rodar a suíte" existe por 2 wipes reais |
| **D-4** | Os 4 flips da Fase 4 | ACOS Max inteiro | preflights já verdes |
| **D-5** | Mapa canônico das ≤12 famílias de comando | Fase 1.3 | a proposta de §1.3 é chute meu; o vocabulário é seu |

---

## 7. Sequência recomendada e custo

| Fase | Esforço | Risco | Ganho |
|---|---|---|---|
| **0** | ~1 dia | baixo (reversível) | destrava toda afirmação posterior |
| **1** | ~3–5 dias | baixo (aditivo + alias) | maior ganho de M por esforço |
| **2** | ~1–2 semanas | **médio** (toca caminho vivo) | o "patamar superior" pedido |
| **3** | multi-semana | alto (capacidade real) | o "mais inteligente" pedido |
| **4** | 4 linhas de `.env` | **alto e seu** | multiplica tudo acima |

**Regra de ouro:** nenhuma fase começa antes do gate de saída da anterior. Fase 0 é inegociável — sem medição honesta, as Fases 1–3 viram as mesmas 3.051 commits dos últimos 20 dias.

---

## 8. DEBTS abertos herdados (não reabrir sem decisão)

- **PRE family full delete** — suíte de caracterização retida (ASDD, NOT_CLAIMED)
- **R105** — autoridade de governor em workspace efêmero (P2 dos problemas conhecidos)
- **R103-PUBLIC cold matrix** — selo formal opcional; DI live já GREEN
- **S-WORLD / Session rename / Readiness forest / OneShot factory** — plateaus multi-semana
- **Full-Pass**: ~15 resíduos de host/IO nominalmente abertos no LEDGER
- **GOD-DEBULK**: `historical_label_mismatch_52fd8598c` (commit com subject `test(core)` para diff de app; canon exige `refactor(core)`)
- **10 falhas** na suíte Feature de certificação (guard de serving rejeita as tags `multi_agent_loop` que o próprio seed cria) — registrado em `EXEC-DEBTS`

---

## 9. Autoverificação deste plano

| Pergunta do filtro de 5 | Resposta |
|---|---|
| Aumenta o wrapper multiplicador composto? | **Sim** — Fases 1 e 2 aumentam o M que o Atlas aplica sobre si mesmo ao se auto-programar; Fase 3 é o M sobre o provider |
| É antifrágil? | **Sim** — Fase 0 transforma medição cega em medição com fonte; erro passa a ser detectável em vez de silencioso |
| Aproxima da execução fim-a-fim em linguagem natural? | **Parcial** — Fase 3 (R108) sim; Fases 0–2 são fundação |
| Destrava substituir uma função/empresa? | **Indireto** — destrava o Atlas se auto-construir com menos erro |
| Preserva soberania local-first? | **Sim** — nada sai da máquina |

**Onde este plano pode estar errado:** o mapa das ≤12 famílias (§1.3) é proposta minha sem seu vocabulário; os donos propostos em §2.2 saem de massa + lei existente, não de censo — por isso §2.1 vem antes. Se o censo contradisser §2.2, **o censo vence**.
