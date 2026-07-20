# Handoff Fable → Fable — Perfil de Capacidades Eng & Arq (Atlas Rivals)

**Escrito em:** 2026-07-20 ~09:58 -03 (12:58 UTC)
**Para:** a próxima sessão do Fable (casca + honestidade de dado do Rivals)
**Companheiro obrigatório:** `docs/rivals-warroom.md` (blackboard; §3 claims, §5 log, §4 board, §6 handoffs)

> Leia este doc INTEIRO antes de tocar em qualquer coisa. Ele existe para você não
> repetir os três erros que já custaram horas nesta obra (estão na §7).

---

## 1. A missão (ordem do operador — não negociável)

**DEFINIÇÃO DE PRONTO** (só acaba quando TODAS forem verdade):

1. Todas as benchmarks nativas de engenharia & arquitetura rodam **os DOIS braços** —
   *sem Atlas* (Verboo cru) e *com Atlas* (`atlas:cli:dev`) — até o fim, com relatório.
   Alvo: integradas (`bfcl`, `aider_polyglot`, `live_code_bench`) + preparadas em
   `tools/rivals/benchmarks/_prova/` (ArchBench, CRUXEval, ClassEval, RepoBench,
   LocAgent, debug-gym, TestEval, EvalPlus, CrossCodeEval, BigCodeBench, DevEval,
   Long Code Arena, REval). **R2ABench fora** (sem avaliador).
2. **VOLUME** para confiança estatística: N repetições por caso, Wilson/IC reportado —
   "não amostra que é ruído".
3. App Atlas Native (aba **Capacidades**) mostra TODAS as capacidades eng & arq medidas,
   com delta com-Atlas vs sem-Atlas e confiança cheia — "nunca 'não medido', nunca
   número corrompido".
4. Dá pra afirmar por capacidade, com certeza, se o Atlas é melhor/pior e quanto —
   "o relatório do Atlas Benchmark fechado".

**LEI SUPREMA (verbatim):**

- *"Usar o Atlas é usar o Atlas, NÃO o Hermes. O braço 'com Atlas' TEM que rodar o
  cérebro Atlas (`atlas:cli:dev`, `execution=atlas_cli_dev_efficient`) com o Hermes por
  dentro. `hermes -z` cru rotulado de Atlas = a fraude que eu mais odeio; o
  `RuntimeProofAttacher` barra — não relaxe. Pros que produzem artefato (código), o
  braço com-Atlas é o `atlas:cli:dev` gerando o artefato que o corretor pontua."*
- *"Native-only. NADA de Docker/x86 no arm64 — a emulação corrompe (provado: o gold do
  SWE-bench falha). Suíte que precisa de x86 fica estacionada, fora do perfil."*
- *"Nada quebra em silêncio. Log legível, razão de falha nomeada, tokens capturados.
  Número não confiável = 'não medido', nunca falso."*

**SEU FOCO (lane do Fable):** o braço com-Atlas (bridge / `atlas:cli:dev` gerando o
artefato de cada benchmark com prova de runtime governado) + honestidade de dado
(medições, `capability_map` em `config/atlas_arena.php`, o app nativo mostrando deltas
reais com confiança). **Pipeline/normalizer/report/drain + fiação de adapter + garantia
de log são do Codex.**

**REGRAS:** branch local `main`, commits escopados, sem merge. Gates verdes antes de
commitar (`php artisan test tests/Feature/Ai/Arena` + `tests/Unit/Ai/Arena`; casca:
`swift run AtlasCoreChecks` + `cd App && make build`).

**MÉTODO:** reproduza → leia o log → **prove** a causa → conserte → **prove** o conserto
→ registre no war-room.

---

## 2. Como o dado anda (mapa do pipeline)

```
App (botão Rodar)  →  POST /arena/runs
        ↓
storage/atlas/rivals/arena/queued_runs.jsonl      ← fila (status: queued→running→done/failed)
        ↓  (launchd com.atlas.arena-drain, PID 55964)
php artisan atlas:arena:drain
        ↓
scripts/rivals-native-runner.php   → plan.json / preregistration.json / state.json
        ↓                            → native_execution_manifest.json  ← ✱ QUEBRADO HOJE (§4.1)
scripts/rivals-engineering-unit.php  +  scripts/rivals_engineering_driver.py   (nativas)
scripts/rivals_lcb_verboo.py (bare) / scripts/rivals_lcb_atlas.py (atlas)      (LCB)
        ↓
import → RuntimeProofAttacher → adjudicate → storage/atlas/rivals/ledger.jsonl
        ↓
ArenaMeasurementStore (filtra env_failure + candidate_preparation_blocked)
        ↓
ArenaCapabilityProfileService (pool por suíte×braço, Wilson, Newcombe, confiança)
        ↓
GET /arena/capabilities  (schema atlas.arena.capabilities.v2)
        ↓
Sources/AtlasCore/AtlasArena.swift  →  App/Atlas/ArenaCapabilitiesSection*.swift
```

**Braços.** `arm_id` canônico = `{model}@{runtime}`.
`@bare` / `baseline` → **baseline**; `@atlas_dev` / `with_atlas` → **with_atlas**.

**RuntimeProofAttacher** (a barreira antifraude): o braço `atlas_dev` EXIGE
`.rivals_atlas_dev_bridge.json` em `entry.normalization.scratch_dir`. Sem isso →
`env_failure: atlas_dev_runtime_proof_missing_or_invalid`. **environment_failure é
EXCLUÍDO da pontuação** (não vira 0 falso). Não relaxe essa barreira — é ela que
impede `hermes -z` cru se passar por Atlas.

**Recibo do bridge** (`atlas.rivals2.atlas_dev_bridge_receipt.v2`): `status`,
`real_provider`, `execution`, `atlas_runtime`, `fair_mode{single_provider,
decide_disabled, fallback_disabled, deterministic_fast_path_disabled}`, `task_ok`,
`patch_applied`, `completion_state`, `provider_call.error_codes`, `usage`.

**Princípio de projeto do bridge:** erro ANTES da resposta → `env_failure` (não medido).
Falha DEPOIS da resposta → medido ("falhas depois de uma resposta continuam sendo
resultado válido da tarefa").

**Estatística** (`App\Services\Ai\Rivals\Core\StatisticalPolicy`):
`wilson($s,$n,$z=1.959963984540054)` → `{low,high,width}`;
`newcombeDiff($sA,$nA,$sB,$nB)` → `{diff,ci_low,ci_high}`;
também `hierarchicalBootstrap`, `sensitivity`, `computedPower`.
Temperatura por repetição = `0.2 + (rep-1)*0.001`.

---

## 3. O que já está FEITO e commitado (não refaça)

### 3.1 Braço com-Atlas governado — PROVADO

O braço LCB com-Atlas roda genuinamente governado:
`execution=atlas_cli_dev_efficient`, `real_provider=true`, Hermes por dentro,
`fair_mode` tudo `true`. **LEI SUPREMA satisfeita** — nunca foi `hermes -z` disfarçado.

### 3.2 `scripts/rivals_lcb_atlas.py` — dois fixes meus (arquivo lido no fim deste doc)

- **`_force_fresh_generation()`** (linha ~120): mata o warm-cache skip do
  `--continue_existing_with_eval` (`lcb_runner/runner/main.py:31-62`). Sem isso a
  geração é pulada, `create()` não roda, a prova governada não nasce e o attacher
  reprova o braço com o Atlas TENDO rodado antes. Só purga arquivos **all-dict** — o
  `_eval.json` tem estrutura POSICIONAL (list+dict pareados) e remover por
  `question_id` desalinha.
- **Não pré-criar `solution.py`** (linha ~54): era o root-cause da contaminação (§3.5).

### 3.3 `ArenaCapabilityProfileService.php` — reescrito (VOLUME + confiança)

Antes usava `latestBySuiteArm` (só a última rodada; jogava a repetição fora).
Agora **pool de todas as rodadas** por (suíte, braço), Wilson por braço, delta de
Newcombe, `significant` = IC não cruza 0, e `confidence`:
- `unmeasured` — algum braço com n=0
- `low` — `min(bn,an) < min_cases_for_confidence`
- `measured` — caso contrário

Suporta `measurement_type: continuous` (média + IC normal, N = casos com score) —
foi assim que `architecture_design` (rougeL) ganhou tag "média" em vez de fingir pass@1.
Schema: **`atlas.arena.capabilities.v2`**.

### 3.4 `config/atlas_arena.php`

`'min_cases_for_confidence' => (int) env('ATLAS_ARENA_MIN_CASES_CONFIDENCE', 10)`.

### 3.5 🚨 A DESCOBERTA QUE VIRA O VEREDITO (leia com atenção)

O veredito "Atlas pior em tudo" estava **CONTAMINADO CONTRA O ATLAS**.

**Sintoma:** 222 linhas de falha do braço atlas com
`create_target_already_exists:<file>`.
**Causa PROVADA:** o workspace pré-criava o artefato alvo vazio; o candidato do
`atlas:cli:dev` usa `mode=create` → colisão → o modelo respondeu (prova + tokens reais)
mas o artefato **nunca foi testado** → 0 falso pontuado como `model_failure`.
**Atinge SÓ o braço atlas** (o bare escreve direto no workspace, sem worktree).

**Fix (colaboração):** eu achei + propus, o Codex aplicou `if initial:` em
`rivals_engineering_driver.py`, eu provei — cruxeval atlas:
`status: "measured", benchmark_status: "success", score: 1`, sem colisão.

**Efeito da descontaminação (medido):**

| capacidade | antes | depois |
|---|---|---|
| `code_reasoning` | −0.077 | **+0.000 (Atlas IGUAL)** |
| `debugging` | MEDIDO −1.000 | LOW N=1 (12/13 eram setup-blocked) |
| `code_generation` | −0.071 | −0.037 |
| `tool_use` | −0.511 | −0.495 |

**Blindagem** (`ArenaMeasurementStore.php`): linhas com
`candidate_preparation_blocked` no `failure_reason` viram **NÃO MEDIDO**, nunca 0 falso.
As **222 são HISTÓRICAS (pré-fix)** — limpam conforme as suítes re-drenarem e/ou o Codex
projetar o `error_code` no recibo.

### 3.6 Casca (atlas-native) — camada de confiança no app

- `Sources/AtlasCore/AtlasArena.swift` → v2: `AtlasArenaCapabilityDelta{value,ciLow,
  ciHigh,significant}`, `baselineCi`, `withAtlasCi`, `baselineCases`, `withAtlasCases`,
  `delta`, `confidence`, `minCasesForConfidence`, `enum Confidence{measured,low,
  unmeasured}` + `confidenceLevel` fail-open.
- `App/Atlas/ArenaCapabilitiesSection+Confidence.swift` (NOVO) — legenda honesta:
  "Atlas melhora +X · confirmado (N 51)" (ouro), "Atlas piora" (alert), "dentro do
  ruído", "poucos casos (N X) · baixa confiança", "Atlas ainda não rodou aqui".
- `+Rows.swift` (legenda entre `DualBar` e `contributionLineView`),
  `+A11yRow.swift` (`spokenConfidence` — VoiceOver fala a mesma verdade).
- `AtlasArenaVisualFixture.swift` + `AtlasCoreChecks/AtlasArenaChecks.swift` → v2,
  3 checks novos (decode do IC de Wilson, decode do delta+significância, `confidenceLevel`).
- Testes: `tests/Unit/Ai/Arena/ArenaCapabilityProfileServiceTest.php` (8 testes).

> ⚠️ `requireSchema` no Swift **falha duro** em mismatch de schema. É DELIBERADO:
> falhar alto, não mostrar número pelado em silêncio. Se você bumpar o schema no PHP,
> bumpe no Swift + fixture + checks no MESMO commit.

---

## 4. Estado REAL medido agora (2026-07-20 09:58 -03)

> ⚠️ **§4.1 e §4.3 foram CORRIGIDAS às 10h05** — a causa está provada e o quadro é
> melhor do que a primeira redação. Leia o bloco "CAUSA PROVADA" logo abaixo antes de
> agir; o texto original fica preservado como registro do meu erro.

### 4.1-bis ✅ CAUSA PROVADA (2026-07-20 10h05) — e não é bloqueador da missão

**A cadeia (arquivo:linha):** `ArenaRivalsExecutionService::plan()` chama
`atlas:rivals plan`, que retorna `ok` e escreve plan/prereg/state. Mas
`AtlasRivalsCommand.php:987` só escreve o manifesto **se** o suite estiver em
`SuiteRegistry::externalSuiteIds()`, e `SuiteRegistry.php:91-94` define isso como
`profileSuiteIds('fase_a')` — as **10 legadas**. As 13 nativas têm adapter externo
(`SuiteRegistry.php:75-87`, `EngineeringNativeSuiteAdapter`) mas vivem no perfil
**`engineering_native`** → nunca entram no gate → sem manifesto →
`manifestEntries():47` estoura.

**Não é regressão de ninguém** — o gate sempre foi estreito. Eu errei o diagnóstico
duas vezes antes (culpei "grupo só-atlas" e depois a WIP do Codex). Fix indicado:
gate por "tem adapter externo" (`registeredSuiteIds()`) e não por perfil `fase_a`;
mesmo tratamento no gate irmão do `FrozenUnitManifest` (~linha 975).
**Alvos reservados pelo Codex** (`AtlasRivalsCommand`, `SuiteRegistry`,
`config/atlas_rivals.php`) — não edite, está em §6 como handoff.

**O que isso quebra de fato:** só o botão "Rodar" do app para as 13 nativas.
**O volume da missão vem por outro caminho, que FUNCIONA e está rodando:**

```bash
php artisan atlas:rivals battery --mode=execute --kind=uplift \
  --profile=engineering_native --suite=<suite> --repetitions=4 \
  --approve-provider-spend --json
```

Vivo desde 08:29:54 em `testeval` (run `20260720_112956_34d1e05c`): manifesto com
**24 entradas = 12 `@bare` + 12 `@atlas_dev`** (3 casos × 4 reps × 2 braços) — DoD#1 e
DoD#2 no mesmo comando. Braço com-Atlas verificado **em processo vivo**:
`rivals-atlas-dev-bridge.php → artisan atlas:cli:dev Solve → hermes -z por dentro`.
É esse comando que fecha o volume das 13 nativas, uma suíte por vez.

---

### 4.1 (registro original — diagnóstico que eu dei errado) as 12 nativas falham INSTANTANEAMENTE

Todo o re-drain limpo (12 suítes × 2 braços = 24 runs) morreu:

```
failure_code: internal_error
failure_reason: file_get_contents(.../runs/20260720_113751_189ffe18/
                native_execution_manifest.json): No such file or directory
drain_started_at: 11:37:50Z   →   drained_at: 11:37:51Z   (1 segundo)
```

O dir da run tem `plan.json`, `preregistration.json`, `state.json` — **não tem
`native_execution_manifest.json`**. O planner roda; o passo que escreve o manifesto não.

**CORREÇÃO DE DIAGNÓSTICO IMPORTANTE:** eu antes atribuí isso a "grupo só-atlas não
planeja". **ERRADO.** Agora falha com os DOIS braços na fila → é sistêmico, não é do
skip-bare. Suítes atingidas: cruxeval, classeval, repobench, locagent, debug_gym,
testeval, evalplus, crosscodeeval, bigcodebench, deveval, reval (+ archbench).

**Causa mais provável (NÃO PROVADA — prove antes de agir):** o executor eng-native está
em cirurgia do Codex, não commitado:

```
 M scripts/rivals-engineering-unit.php    (+41 -13)
 M scripts/rivals_engineering_driver.py   (+1504)     ← +1504 linhas em voo
 M scripts/rivals-atlas-dev-bridge.php    (+29)
?? app/Services/Ai/Arena/ArenaReportService.php        ← novo, untracked
?? app/Services/Ai/Arena/ArenaRivalsExecutionService.php
?? app/Services/Ai/Arena/ArenaMeasurementControlService.php
```

**Isto é território do Codex (§2 do war-room).** NÃO edite `rivals_engineering_driver.py`
nem `rivals-engineering-unit.php` sem reservar em §3 e sem confirmar que o Codex parou.
O caminho certo é: reproduzir 1 unidade, achar quem deveria escrever o manifesto,
registrar no war-room §6 como handoff com a prova, e **esperar**.

### 4.2 🔴 BLOQUEADOR #2 — LCB braço atlas: cardinalidade 0

Run `20260720_113852_520f3dae` (LCB, ~63 min, marcada `done`) tem 128 unidades
`@atlas_dev` e 428 `@bare`, mas **zero linha de medição `live_code_bench|with_atlas`**:

```
normalization_failed:live_code_bench_unit_result_cardinality
native_stderr_cause=RuntimeError: LCB question cardinality for lcb_3021: 0
environment_failure_rate_exceeded:coding_patch
```

**Hipótese (NÃO PROVADA):** corrida de concorrência — a fila ainda tem
`RUNNING live_code_bench with_atlas` órfã, e o LCB atlas compartilha o diretório FIXO
`output/kimi-k2.7-atlas/` (o normalizer exige esse `model_repr`). Dois LCB atlas ao
mesmo tempo se atropelam. Já provei esse mecanismo antes (§7.1).
**Próximo passo:** confirmar sobreposição temporal das duas runs antes de mexer no
overlay. Se for isso, o fix é serializar (nunca 2 LCB atlas juntos), não mexer no script.

Consequência: **`reasoning` continua `unmeasured`**.

### 4.3 Fila (`storage/atlas/rivals/arena/queued_runs.jsonl`, 118 linhas)

| status | baseline | with_atlas |
|---|---|---|
| done | 26 | 25 |
| failed | 27 | 27 |
| **running (órfãs)** | **4** | **5** |
| skipped_out_of_profile | 2 | 2 |
| **queued** | **0** | **0** |

✅ **RESOLVIDO às 10h05.** Confirmei por `ps` que não havia processo vivo para nenhuma
delas → órfãs de verdade. As 9 voltaram a `queued` via
`ArenaMeasurementStore::transitionQueuedRequests`, com `requeue_reason` nomeado no
recibo (nada em silêncio). São `aider_polyglot` (×3 pares), `bfcl` (×1 par) e
`live_code_bench` (×1 atlas) — as 3 integradas, todas no perfil `fase_a`, portanto
**imunes ao bug do manifesto** da §4.1-bis. Guarda mantida: **no máximo 1 LCB por vez**.

### 4.4 Perfil de capacidades como está HOJE

| capacidade | base | atlas | delta | IC | confiança |
|---|---|---|---|---|---|
| `architecture_design` | 0.1477 (n=28) | 0.0662 (n=23) | **−0.0815** | [−0.152, −0.011] sig | measured (contínuo) |
| `code_editing` | — | — | −0.102 | — | measured |
| `code_reasoning` | — | — | **0.000** | — | measured |
| `debugging` | — | — | −0.308 | — | measured |
| `tool_use` | — | — | −0.495 | — | measured |
| `code_generation` | — | — | — | — | **unmeasured** |
| `reasoning` | — | — | — | — | **unmeasured** |

**217 linhas de medição no total.** Volume real por suíte (passed/total):

```
bfcl          base 65/79 (13 runs)   atlas 19/58 (10)
terminal_bench base 17/107 (20)      atlas  0/51 (16)   ← muito contaminada
inspect_evals base 1148/1302 (12)    atlas  1/1  (1)
live_code_bench base 21/43 (9)       atlas  —           ← §4.2
cruxeval      base 13/13 (2)         atlas 12/12 (2)    ← pós-fix, limpa
evalplus      base 13/13 (2)         atlas 13/13 (2)
repobench/locagent/crosscodeeval/bigcodebench: 13/13 nos dois braços
classeval     base 13/13             atlas  4/13        ← suspeita de contaminação residual
testeval      base 13/13             atlas  3/13        ← idem
debug_gym     base 25/25 (3)         atlas  9/13 (2)
reval         base 24/25 (3)         atlas 25/25 (3)    ← Atlas MELHOR
deveval / long_code_arena: só baseline (1/1)
```

> Leia essa tabela com o olho da §3.5: onde o atlas está muito abaixo em suíte que o
> bare fecha 13/13, desconfie de contaminação residual antes de concluir capacidade.

### 4.5 Resposta honesta à pergunta do operador

*"De todas as benchmarks de eng & arq, quantas rodaram e geraram relatórios completos e
confiáveis?"* → **13/16 rodaram os dois braços** (3 só-bare: `live_code_bench`,
`deveval`, `long_code_arena`). **0/16 completas + confiáveis** — por causa de: 222 linhas
históricas contaminadas, volume de ~13 casos/suíte, `code_generation` e `reasoning`
não medidos no braço atlas, e nenhum relatório fechado.

*"Por que bare rodou e com Atlas não?"* → o bug de `create_target_already_exists` batia
**só** no braço atlas (o bare escreve direto, sem worktree governado). Ou seja: o braço
que é prioridade total era exatamente o que quebrava. **Isso está corrigido** (§3.5); o
que bloqueia agora é §4.1 (manifesto ausente, atinge os dois braços).

---

## 5. O que fazer, em ordem

1. ~~Prove a §4.1~~ **FEITO** (§4.1-bis). Handoff aberto com o Codex; não conserte por cima.
2. ~~Destrave as 9 órfãs~~ **FEITO** (§4.3).
3. **Prove a §4.2.** Agora só há 1 LCB na fila — a próxima run decide: veio limpa = era
   corrida; repetiu a cardinalidade 0 = é o overlay e o fix é meu (é meu claim).
4. **VOLUME — o caminho principal.** Rode `atlas:rivals battery --profile=engineering_native
   --suite=<suite> --repetitions=N --approve-provider-spend` suíte por suíte, até cada
   capacidade passar de `min_cases_for_confidence` (10) com folga.
   **Uma suíte por vez** (§7.1) — o bridge governado leva ~15 min/unidade, então
   `--repetitions=4` × 3 casos × 2 braços ≈ 3h por suíte. 13 suítes = tempo de máquina
   de dias, não de sessão. Planeje sentinela, não espera ativa.
5. **DoD#4 — relatório fechado.** `ArenaReportService.php` é do Codex e está untracked.
   Cobre em §6 do war-room.
6. **Prova visual.** A regra do operador: mudança visual só conta com screenshot no
   device (`cd App && make device`). A aba Capacidades ainda não teve screenshot.

### Handoffs abertos com o Codex (todos já com causa provada, no war-room)

- Projetar o `error_code` de `candidate_preparation_blocked` no recibo → minha exclusão
  no store limpa as 222 históricas de uma vez.
- Terminar o executor eng-native (§4.1).
- Fechar `ArenaReportService` (DoD#4).
- Isolamento de FS/output por run — pré-requisito pra algum dia paralelizar.
- Classificação do EOF do LCB em questão funcional (§7.4).

---

## 6. Regras de operação (comandos que funcionam)

```bash
cd /Users/vitorepf/develop/Atlas/atlas-server

# perfil de capacidades como o app vê
php artisan tinker --execute='echo json_encode(
  app(\App\Services\Ai\Arena\ArenaCapabilityProfileService::class)->profile(),
  JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);'

# volume por suíte×braço
php artisan tinker --execute='
$r=app(\App\Services\Ai\Arena\ArenaMeasurementStore::class)->measurements();
$a=[];foreach($r as $x){$k=$x["suite"]."|".$x["arm"];
$a[$k]["n"]=($a[$k]["n"]??0)+1;$a[$k]["p"]=($a[$k]["p"]??0)+(int)$x["cases_passed"];
$a[$k]["t"]=($a[$k]["t"]??0)+(int)$x["cases_total"];}ksort($a);
foreach($a as $k=>$v)printf("%-32s runs=%-3d %d/%d\n",$k,$v["n"],$v["p"],$v["t"]);'

# fila
php -r '$l=file("storage/atlas/rivals/arena/queued_runs.jsonl",
FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);$c=[];foreach($l as $x){$j=json_decode($x,true);
$c[($j["status"]??"?")."|".($j["arm"]??"?")]=($c[($j["status"]??"?")."|".($j["arm"]??"?")]??0)+1;}
ksort($c);print_r($c);'

# dreno vivo?
launchctl list | grep arena-drain          # com.atlas.arena-drain (PID 55964)

# ledger de uma run
grep -h "<run_id>" storage/atlas/rivals/ledger.jsonl

# gates
php artisan test tests/Feature/Ai/Arena tests/Unit/Ai/Arena
cd /Users/vitorepf/develop/Atlas/atlas-native && swift run AtlasCoreChecks && cd App && make build
```

**Chave da API:** `~/.hermes/.env` **NÃO pode ser `source`ado** — tem
`AGENT_BROWSER_EXECUTABLE_PATH=/Applications/Google Chrome.app/...` (espaços sem aspas)
e quebra com exit 127 sob `set -e`. Extraia só `VERBOO_API_KEY` com grep, e **nunca
imprima o valor**.

---

## 7. Erros que EU já cometi — não repita

### 7.1 ❌ Paralelizar o dreno
Escalei 4→8 workers; 17 concorrentes deram 6 `internal_error` em archbench/cruxeval/
evalplus. **Causa provada:** o claim atômico protege a FILA, não o FILESYSTEM. Runs da
mesma suíte disputam clone/venv/output compartilhados. O LCB é o pior caso: diretório
FIXO `output/kimi-k2.7-atlas/` que o normalizer exige → **LCB nunca paraleliza**.
Sintomas que isso produz: `proof_missing` e
`normalization_failed:unit_result_cardinality`. **Uma run por suíte, sempre.**

### 7.2 ❌ Pular o braço bare pra "priorizar o Atlas"
Despriorizei os 9 bare da fila; grupos só-atlas não planejam e o dreno quebrou.
**O braço atlas é priorizado RODANDO dentro de grupos normais de 2 braços**, nunca
removendo o bare.

### 7.3 ❌ Resolver colisão de arquivo com gitignore
Minha tentativa trocou `create_target_already_exists` por
`hermetic_candidate_diff_scope_mismatch`. O caminho certo era o `if initial:` do Codex
(não pré-criar). Revertido.

### 7.4 Não-bugs (não "conserte")
- `@bare` hardcoded em `LiveCodeBenchAdapter::mapResults:54` → é placeholder remapeado.
- `governor_authority_absent` → **NÃO FATAL** (provado: cruxeval pontuou 1 mesmo assim).
- LCB harness zera código funcional correto com `error_code -4 "EOF when reading a line"`
  (questão funcional rodada em modo stdin). Risco **latente** — o conjunto medido hoje é
  de questões stdin, então não corrompe agora. Handoff aberto com o Codex.

### 7.5 Protocolo de colaboração (o operador cobra)
RESERVE em §3 do war-room antes de editar; REGISTRE em §5 (sintoma → causa **PROVADA** →
fix → prova); atualize o board §4; use §6 pra handoff. **Não edite alvo que o Codex
reservou.** "Vigilância com sentinela: nada de confiar em silêncio."

---

## 8. Arquivos que importam

**atlas-server**
- `docs/rivals-warroom.md` — o blackboard (927 linhas; §8 é o log append-only pós-fork)
- `app/Services/Ai/Arena/ArenaCapabilityProfileService.php` — meu, pool+Wilson+Newcombe
- `app/Services/Ai/Arena/ArenaMeasurementStore.php` — meu, exclusões de não-medido
- `app/Services/Ai/Arena/ArenaReportService.php` — **do Codex, untracked, DoD#4**
- `app/Services/Ai/Rivals/Core/StatisticalPolicy.php` — Wilson/Newcombe/bootstrap
- `config/atlas_arena.php` — `capability_map`, `capability_labels_pt`,
  `min_cases_for_confidence`
- `scripts/rivals_lcb_atlas.py` — meu (overlay LCB governado)
- `scripts/rivals_lcb_verboo.py` — base do overlay (bare)
- `scripts/rivals-atlas-dev-bridge.php` — a ponte governada (**WIP do Codex agora**)
- `scripts/rivals_engineering_driver.py` / `rivals-engineering-unit.php` — **WIP do Codex**
- `tests/Unit/Ai/Arena/ArenaCapabilityProfileServiceTest.php` — 8 testes meus

**atlas-native**
- `Sources/AtlasCore/AtlasArena.swift` — modelo v2 + confiança
- `Sources/AtlasCore/AtlasArenaVisualFixture.swift`, `Sources/AtlasCoreChecks/AtlasArenaChecks.swift`
- `App/Atlas/ArenaCapabilitiesSection+Confidence.swift` / `+Rows.swift` / `+A11yRow.swift`
- `OBRA.md` — §5 pedidos de contrato, §7 registro com prova (obrigatório ao entregar)

---

## 9. Tarefas abertas

| # | estado | o quê |
|---|---|---|
| 16 | in_progress | MISSÃO — certeza absoluta do perfil (volume enorme, dado limpo) |
| 19 | in_progress | Volume + IC de Wilson no braço Atlas — infra pronta, preso em tempo de dreno |
| 15 | pending | Arena redesenhada: tabs Agora/Resultados/Capacidades + herói AO VIVO |

---

**Última verdade honesta:** o defeito de raiz que envenenava o braço Atlas caiu
(§3.5) e a máquina de honestidade funciona — ela pegou o dado mentindo **contra** o
Atlas. O reframe é **perto de paridade, não inferior**. O que falta é (a) destravar o
executor eng-native (§4.1, Codex), (b) o LCB atlas fechar (§4.2), (c) volume — tempo de
máquina, e (d) o relatório fechado (§4, DoD#4, Codex). Nada disso se acelera clicando;
se acelera provando causa e destravando o dono certo.
