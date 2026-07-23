# OBRA NÚCLEO ESSENCIAL — Plano-Mestre v2 (pós-revisão adversarial)

**Data:** 2026-07-23 · **Executor:** Claude (operador-presente) · **Branch:** `main` (pétreo, commits escopados)
**v1 → v2:** revisão adversarial 5-lentes (6 agentes, verificação contra o repo) achou 7 críticas + 9 high + 10 med/low; TODAS incorporadas abaixo. Nota v1: 6,5/10; alvo v2: ~9/10.
**Missão:** continuar a redução agressiva até o núcleo essencial — deletar o morto PROVADO, decidir o dormente, fundir os gigantes em patamar superior, unificar os pipes — estruturado para IA achar/consertar/implementar com perfeição.

---

## 0. Estado de partida (medido 23/07 ~20h, pós Waves 0/1/2a — números verificados por 3 lentes independentes)

| Métrica | Valor |
|---|---|
| Arquivos PHP em `app/` · LOC | 6.807 · 1.655.044 |
| Deletado nesta obra até agora | −38.174 LOC (`b897f21b8`, `945e4141b`) + main consertada (`ae35d8df3`) |
| Godfiles >2000 LOC | **5**: Readiness 10.597 · EvolutionSession 5.132 · EnterpriseFlowFixture 4.055 · AiWorker 3.040 · LedgerReplay 2.240 |
| Massa dos alvos | Readiness 115.950 LOC/117 arq · ControlPlane 53.636/172 · ExternalBrain 85.931/379 · Holding 43.565 · OneShotTick 20.714/75 |
| `AutonomousEvolution/` não-Brain | 257 arq — **RECLASSIFICADO (C4): ~129 VIVAS com consumidor não-teste, ~93 candidatas a prova-de-morte, 35 dead-emaranhadas** |
| Comandos | 1.062 registrados (220 por FQCN no bootstrap; resto auto-discovery) · 46 `Schedule::command` em `routes/console.php` · 39 `Artisan::call` por string |
| Subsistemas confirmados VIVOS (fora de alvo) | Programming, Engineering, Marketing, Finance, Rivals, Vox, Voice, Cognition, Controllers (0 órfãos), Models (~0 morto) |

**Autorizações do operador:** deletar dormentes agressivo · fusão patamar-superior · unificações blueprints.

---

## 1. Invariantes pétreos (todas as waves)

1. **`main` only**; commits escopados (`git add -u`/`-- <paths>`), nunca `-A`, zero merge, push só com OK.
2. **NUNCA rodar a suíte** (`php artisan test`/`phpunit`/`pest`) — 2× wipe do Postgres vivo. Goldens = captura standalone (§2.7), jamais Feature test.
3. **Keep-list reconstruída (C4)**: a lista literal de W1 (30 nomes) está SUBDIMENSIONADA — a verdade são **~129 classes vivas** no dir. Antes da W2: gerar `KEEP-LIST-VERIFIED.md` pela closure real (nome + consumidor-prova por classe) e ancorar no doc canônico `atlas-autonomos-live-system.md`. Nunca deletar por prefixo.
4. Não renomear/tocar produtos irmãos: AAEL, Stewardship Loop, TerminalLoop, Brain/.
5. **Protocolo anti-colisão com a sessão paralela (M7)**: (a) edições no `AppServiceProvider` commitam SOZINHAS; (b) `git log --oneline -3 -- <alvo>` imediatamente ANTES do commit (não só antes de começar); (c) alvos da outra sessão (EvolutionSession, AiWorker, EnterpriseFlow) só depois dela terminar.
6. Rollback: 1 lote = 1 commit revertível.
7. **Anti-Goodhart (H8)**: SEM piso numérico de LOC. Sucesso = closure-limpa (§5). LOC deletada é consequência MEDIDA, nunca alvo.
8. **CI real (C7)**: `quality.yml` roda `pint --test` + `artisan test --coverage --min=80` ×3 random-order NO PUSH. Logo: (a) toda wave que ESCREVE código roda `vendor/bin/pint <paths>` antes do commit; (b) antes de qualquer push, declarar ao operador o risco de coverage-delta da deleção em massa — o push é dele.

## 2. Mecânica de prova de morte v2 (emendada C1/C2/H1/H2/M1/M8)

**Raízes vivas:** `app/` (fora do alvo), `routes/` (**incl. `routes/console.php` — 46 schedules, C2**), `bootstrap/` (app.php + **providers.php: os 3 providers — AppServiceProvider, AtlasDevServiceProvider, ProgrammingGovernanceServiceProvider — H1**), `database/` (**corpos de migrations pinam services importados — M8**), TODOS os `config/*.php`, `bin/atlas`.

**Pins adicionais obrigatórios:**
1. **Mapa signature→FQCN (C1)**: varrer `$signature`/`$name` de todos os Commands; pinar VIVO todo Command cuja assinatura apareça em `Artisan::call('...')`, `$this->call('...')`, `Schedule::command('...')` (nos DOIS arquivos de cron) ou em config consumida como nome de comando (**`Artisan::call(config(...))` → resolver o default da key, H2**).
2. Dispatch-tables em config = vivas (aprendido W1); FQCNs com barras escapadas `class_exists('App\\\\...')` normalizados no match (M1).
3. Strings FQCN em `storage/atlas/**` (packets queued) → reportar task-veneno, não bloquear.
4. `app/Jobs` fora de escopo (QUEUE=database, jobs serializados).
5. Strip de comentários; wiring (bindings/config) ≠ uso; fixpoint transitivo; classificação de testes (família vs dep-viva); cirurgia statement-aware nos **3 providers**; auditoria do diff de wiring (nenhum binding removido de alvo não-deletado).

**Gate de lote:** `php -l` → `composer dump-autoload -o` (zero PSR-4 app/) → **bind-to-missing linter (novo, C5)**: script que resolve todo `::class` citado nos 3 providers + configs contra o autoload e acusa classe inexistente → boot smoke com **delta assertion (M6)**: nº de comandos esperado = anterior − deletados-intencionais (listar assinaturas no commit) → sweep zero-refs → pint se escreveu código → commit.

**§2.7 Goldens (C6/H4/H7):** captura via `php -r`/script standalone chamando o serviço com inputs congelados (NUNCA phpunit). Determinismo: congelar clock (`Carbon::setTestNow`), fixtures de storage, ou excluir campos time-derived do diff. Timeout curto em toda captura (H7: suites do caminho já travaram >10min — hang = registrar, não esperar). **Anti-vácuo em TODA fusão (H9)**: cada caminho do golden assere ≥1 token esperado — `return []` nunca passa verde.

---

## 3. AS WAVES (v2)

### WAVE 0.5 — Minas pré-existentes no wiring (NOVA, C5/M4) — PRIMEIRO
1. Rodar o bind-to-missing linter no repo: já conhecidos GAP-02 (`AtlasDeadCodeAnalyzer` — `use` ASP:76, injeção no bind do OutOfProcessVerifier já removido por W1; conferir sobras), GAP-03 (`AtlasEvolutionScenarioExplorer` — bind lazy ASP:~412 para classe INEXISTENTE + cluster FrozenJudge/LoopRunner/ScenarioWaveDispatcher), GAP-22 (`config/atlas.php:158` lens `frontier-harvest` cita classe deletada por campanha antiga — verificar por class_exists, não por grep).
2. Para cada mina: remover wiring morto OU restaurar classe (decisão por consumidor real); registrar no LEDGER.
3. Sem isso o guard da W6 nasce vermelho e o boot carrega bombas lazy.

### WAVE 2b — Círculo Readiness ↔ dormentes ⭐ (emendada H3/H4/H6)
1. Partição das ~60 seções {espelha-dormente | projeta-vivo | misto} com prova → `READINESS-PARTITION.md`. **Usar o blueprint `ARCH-BLUEPRINTS/SelfConstructionReadiness.md` (draft-v2) + emenda: 9+ command-generators VIVOS emitem flags do MotherCommand — pinar (H6).**
2. Goldens do subconjunto vivo ANTES (§2.7): `control-plane inspect --facts` (verificado determinístico e sem DB); demais projeções vivas com clock congelado.
3. Deletar pares invoker+seção-espelho, lote a lote, sweep entre lotes.
4. Derreter a mãe: **NÃO há `__call` mágico (H3) — remover delegador = fatal imediato nos callers.** Antes de remover cada método: `rg -w <methodName>` sobre todos os callers; método com caller vivo NÃO sai. Meta mãe ≤2.000 L.
5. `ReadinessFailClosedPolicy`: seção ausente → `not_ready`, nunca fatal — golden disso também.
6. **GAP-30/33/37 (fail-open) moram nos arquivos que esta wave reescreve — corrigir na passada (janela barata, M5); golden de caminho fail-open ≠ "vivo preservado".**

### WAVE 2c — ExternalBrain advisory (emendada H5)
- Manter: Spine, seam `brain:seed`→enqueue, audits agendados, tudo que Brain/Replenisher importa.
- **GAP-09/10 (bridges ExternalBrain→TaskFabric/Maestro): 0-caller MAS marcados "LIGAR destrava" no ledger — decisão explícita do operador ligar-vs-deletar; descarte vai pro DEBTS (H5).**
- Organs advisory deletados levam junto seus Commands `atlas:external-brain:*` + linha de cron (nos 2 arquivos) no MESMO commit.

### WAVE 2d — 35 dead-emaranhados + tests/Archive (emendada M2)
- Cirurgia por teste (remover só métodos/imports que tocam cadáver) OU deletar teste inteiro se a cobertura viva remanescente for trivial (registrar).
- **`tests/Archive/` (17.206 LOC, fora de qualquer testsuite, SUT vivo tem teste duplicado no Unit): deleção livre (M2).**

### WAVE 2e — Cerimônia NativeImplementation + Maestro
- `HumanCompletionReceipt*` (10+), gates de completion empilhados → 1 gate canônico por invariante; Maestro sub-dirs sem consumidor do serving.
- Preservar intactos: `atlas:land`, native-release `preflight|apply|verify|rollback`.

### WAVE 2f — Data-layer loop morto
- Models `AtlasLoop*` sem consumidor vivo → deletar; `AtlasLoopTask`/`DecompositionOutcome` etc. com consumidor → ficam (verificado).
- Migrations FICAM (histórico; e M8: seus imports pinam services). Tabelas Postgres: não dropar (fora de escopo).

### WAVE 2g — Re-home das vivas do dir morto (NOVA, C4)
- As ~129 classes vivas de `AutonomousEvolution/` não-Brain moram num dir rotulado MORTO — risco permanente de deleção por engano.
- Re-home para namespaces dos consumidores (SelfConstruction/, Brain/, Aael/) usando o blueprint **`RootSinglesRehome.md`** (risco nº1 documentado: refs same-namespace sem `use` — o blueprint tem a receita).
- Ao final: `AutonomousEvolution/` = Brain/ + Aael/ (produto vivo) + NADA, e o dir pode ser renomeado/extinto.

### WAVE 3 — Fusão patamar superior (emendada C3/C6/H9)

| Alvo | Estratégia v2 |
|---|---|
| 3.1 `EnterpriseFlowFixtureActionRuntimeService` 4.055 | **PREMISSA v1 FALSA (C3): Holding é VIVO — instanciado por 8 domain-commands (`atlas:ai:finance-domain` +7), a superfície dos 15 Domains.** Rodar closure §2 a partir dos 8; SÓ ENTÃO decidir com operador (fundir p/ tabela é o default agora, não deletar). Golden: capturar os 59 pares standalone via `php -r` (C6) — o Feature test committado é só re-verificação de CI. |
| 3.2 `AtlasLedgerReplayService` 2.240 | Fusão data-table por tipo de evento; Evidence é vivo — golden §2.7 + anti-vácuo (H9). |
| 3.3 `AiWorker` 3.040 · 3.4 `EvolutionSession` 5.132 | **Sessão paralela no meio — NÃO TOCAR até ela terminar; re-checar `git log` na hora.** |
| 3.5 Readiness-mãe | resolvida na W2b. |
| 3.6 BlogEditorial dedup | refazer direito a dedup abandonada; golden = 10 métodos públicos do ContextService, standalone. |

### WAVE 4 — Unificações (emendada H6/M9)
1. **ProviderPipeUnification** (fecha bypass claude_cli; keep-separate provado para codex/gemini/minimax respeitado).
2. **KernelTriad** conforme blueprint.
3. **EnterpriseExecutorUnification** (sem forçar autônomo nos 4 engines Stewardship — veredito do review).
4. **CLI**: regra CAUSAL (M9) — deletar Commands de organs mortos + agrupar SÓ onde há coesão real (`{action}`); o número cai como consequência (sem alvo "≤600"). Todo Command deletado: mapa signature→FQCN confere que nada o chama por string (C1).
5. **Mapear TODAS as waves aos 8 blueprints draft-v2 sobreviventes** (H6) — nenhum blueprint pronto fica órfão sem decisão.

### WAVE 5 — Consertos de correção/segurança
5.1 `TerminalHookRunner:58` trust-gate (HIGH) · 5.2 explore≠danger (MED) · 5.3 golden F0 anti-vácuo (MED) · 5.4 `__GODDEBULK_SPAN_*` + dead delegators + helpers duplicados→trait (LOW) · 5.5 DI cruft do Decide (LOW) · 5.6 `scratchpad_*.py` da raiz (LOW).

### WAVE 6 — Fecho: executabilidade-IA + conhecimento (emendada M4/M10)
1. **Guard estrutural** (>2.000 L falha) + **bind-to-missing linter permanente** — pré-requisito: Wave 0.5 zerou as minas (M4).
2. **Camada de navegação-IA (M10)**: AGENTS.md/CLAUDE.md por módulo-mãe tocado (entrypoints, contratos, o-que-é-vivo), mapa de entrypoints por subsistema.
3. Ledger da obra em `docs/evidence/2026-07-23-nucleo-essencial/` (LEDGER + DEBTS + prova por lote + assinaturas de comandos deletados por commit, M6).
4. Sync: `knowledge sync --prune` + `index-code --prune` + `atlas:brief --generate` + projeção de memória + gravar learnings (dispatch-table, wiring≠uso, círculo Readiness, signature→FQCN).

## 4. Fora de escopo (registrar, não executar)
Scheduler stale 10d + `loop/STOP` · drop de tabelas loop · **rotação do vazamento `.env.bak` (🔴 URGENTE, paralelo)** · TerminalDev WIP + stash mobile · higiene top-level (archive/ 793, frozen/, dissecar/, resolver-o-que-vale-a-pena/, droid-wiki/, outputs/, atlas-visual-report/) — proposta separada · P1-P14 (obras de produto).

## 5. Critérios de sucesso v2 (H8: closure-limpa, sem alvo de LOC)
1. Zero classe VIVA em diretório rotulado morto (W2g) e zero classe MORTA-provada em `app/` (closure §2 limpa).
2. Zero ref órfã · zero bind-to-missing · zero PSR-4 · boot smoke com delta-assertion batendo por commit.
3. Zero arquivo >2.000 L em `app/` com guard LIGADO.
4. Goldens vivos: idênticos antes/depois, com anti-vácuo, capturados standalone.
5. Keep-list verificada 100% intacta; Brain/serving/committer/keep-129 preservados (ou re-homed com prova).
6. Ledger + DEBTS honestos (residuais nomeados, sem headline inflado — lição do god-debulk).
7. LOC total deletada: REPORTADA ao final como medição, nunca perseguida como alvo.

## 6. Sequenciamento v2
```
W0.5 (minas wiring)  → PRIMEIRO, destrava o linter e o guard
W2b ⭐ → W3.5        W2c (com decisão GAP-09/10)        W2d · W2e · W2f
W2g (re-home 129)   → depois de 2b-2f esvaziarem o dir
W3.1 (closure Holding → PERGUNTAR c/ fatos verdadeiros)   W3.2/3.6 livres
W3.3/3.4 → SÓ pós-sessão-paralela
W4 → pós-podas      W5.1 → quando operador priorizar (HIGH standalone)
W6 → último
```

**Perguntas abertas ao operador:** (1) W3.1 Holding: fundir p/ tabela (novo default) ou ainda quer avaliar deleção após a closure real? (2) W2c GAP-09/10: ligar os bridges ou deletar? (3) W5.1 trust-gate do TerminalHookRunner: aplicar sobre o WIP não-commitado da outra frente?
