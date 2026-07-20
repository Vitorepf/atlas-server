# GOAL: RIVALS PONTA-A-PONTA — só parar com o Atlas Benchmark entregue e confiável

> Fonte canônica da missão em goal do operador (2026-07-20). A condição compacta no
> /goal aponta para este doc. Leia INTEIRO, depois `docs/rivals-handoff-fable-20260720.md`
> e `docs/rivals-warroom.md` (§3 claims, §5/§8 log, §6 handoffs).

## MISSÃO (ordem do operador — não negociável)
Deixar o Rivals (Atlas Benchmark) funcionando de ponta a ponta: rodar as benchmarks
nativas de Engenharia & Arquitetura nos DOIS braços (sem Atlas = Verboo cru via Hermes;
com Atlas = `atlas:cli:dev` com Hermes/Verboo por dentro), acumular volume estatístico,
e o app nativo (aba Capacidades) mostrar TODAS as capacidades medidas com delta
com-Atlas vs sem-Atlas em que dá pra confiar. Se o Rivals disser que um braço é melhor,
ele TEM que ser melhor de verdade — veredito falso é o único fracasso inaceitável.

## DEFINIÇÃO DE PRONTO (checklist — só pare quando TODAS forem verdade, com prova)
1. As 13 suítes nativas (`archbench, cruxeval, classeval, repobench, locagent,
   debug_gym, testeval, evalplus, crosscodeeval, bigcodebench, deveval,
   long_code_arena, reval`) + `bfcl`, `aider_polyglot`, `live_code_bench` rodaram os
   2 braços até o fim, com receipts e razão de falha nomeada quando falhar.
2. TODAS as 7 capacidades do `capability_map` (`config/atlas_arena.php`) estão
   `measured` — nenhuma `unmeasured`, nenhuma `low` — com N ≥ 10 casos POR BRAÇO
   (`min_cases_for_confidence`), vindos de dado LIMPO (zero linha contaminada por
   `create_target_already_exists`/`candidate_preparation_blocked` no pool).
3. "Confiança 100%" operacional: todo delta exibido tem IC de Wilson/Newcombe; o app
   marca "confirmado" SÓ quando o IC não cruza zero; quando cruza, diz "dentro do
   ruído"; quando falta dado, diz "não medido". NUNCA número falso, NUNCA 0 falso,
   NUNCA "não medido" falso. Re-meça classeval/testeval (dado pré-fix contaminado
   contra o Atlas) antes de aceitar qualquer veredito que dependa delas.
4. Relatório consolidado fechado por capacidade (melhor/pior/empate + quanto + N + IC),
   gerado por comando reproduzível e commitado.
5. PROVA final: screenshot da aba Capacidades no device real (`cd App && make device`),
   perfil JSON (`ArenaCapabilityProfileService::profile()`) anexado no war-room, e os
   gates verdes: `php artisan test tests/Feature/Ai/Arena tests/Unit/Ai/Arena` +
   `swift run AtlasCoreChecks` + `cd App && make build`.

## LEI SUPREMA (verbatim — viola isso e o resultado é lixo)
- Usar o Atlas é usar o Atlas: braço com-Atlas = `atlas:cli:dev`
  (`execution=atlas_cli_dev_efficient`) com Hermes por dentro. `hermes -z` cru rotulado
  de Atlas é fraude; o `RuntimeProofAttacher` barra — NUNCA relaxe essa barreira.
- SÓ modelos Verboo via runtime Hermes nos dois braços (tokens ilimitados; a battery já
  recusa outra coisa — não contorne). Nada de Anthropic/OpenAI/API paga nas medições.
- Native-only no Mac arm64. Nada de Docker/x86 (emulação corrompe resultado).
- Nada quebra em silêncio: processo de medição SEMPRE detached (nohup + log em
  `storage/atlas/rivals/battery_*.log`) + sentinela vigiando receipts e morte de pid.
  Status que mente (live_status "running" com pid morto) é bug — registre.

## ESTADO NO INÍCIO DO GOAL (2026-07-20 ~10h40 -03; confirme antes de agir)
- Battery testeval detached rodando (4 reps, run `20260720_132831_d45aaa56`, pid no
  live_status; log `storage/atlas/rivals/battery_20260720_testeval_relaunch.log`).
- Dreno da Arena vivo (launchd `com.atlas.arena-drain`) mastigando aider→bfcl→LCB.
- `reasoning` não medido: LCB braço atlas fechou com cardinality 0; a run decisiva está
  na fila — prove corrida (concorrência no diretório fixo `output/kimi-k2.7-atlas/`)
  vs bug do overlay (`scripts/rivals_lcb_atlas.py`, claim Claude).
- classeval/testeval contaminadas pré-fix (atlas 4/13 e 3/13 vs bare 13/13).
- deveval/long_code_arena sem braço atlas.
- Botão "Rodar" do app quebrado pras 13 nativas: gate `fase_a` em
  `AtlasRivalsCommand.php:990` (+ gate irmão ~975) vs `externalSuiteIds()` em
  `SuiteRegistry.php:91` — causa provada no war-room; alvos reservados pelo Codex.
- Run órfã `20260720_112956_34d1e05c` presa em `native_running` (battery morta por
  SIGHUP às 10:15; 14 receipts preservados).

## MODO DE OPERAÇÃO
- Volume vem da battery, uma suíte por vez até N≥10/braço em cada capacidade:
  ```bash
  nohup php artisan atlas:rivals battery --mode=execute --kind=uplift \
    --profile=engineering_native --suite=<suite> --repetitions=4 \
    --approve-provider-spend --json \
    > storage/atlas/rivals/battery_<data>_<suite>.log 2>&1 &
  ```
  Ordem: testeval → classeval → deveval → long_code_arena → demais nativas → volume
  extra onde o IC ainda cruza zero. ~3h/suíte — sentinela, NUNCA espera ativa.
- NUNCA duas runs da mesma suíte ao mesmo tempo; NUNCA 2 LCB (diretório de output fixo).
- Método pétreo: reproduza → leia o log → PROVE a causa → conserte → PROVE o conserto →
  registre no war-room (sintoma → causa provada → fix → prova). Diagnóstico sem prova
  não autoriza edição.
- Colaboração: RESERVE em §3 do war-room antes de editar; NÃO pise em claim ativo do
  Codex. EXCEÇÃO autorizada pelo operador neste goal: se um alvo reservado bloquear a
  DEFINIÇÃO DE PRONTO e o dono não se mexer em 12h, assuma o alvo — registrando prova
  do bloqueio e o takeover no war-room antes de editar, fix mínimo, testes verdes.
- Branch local `main` SEMPRE, commits escopados (`git add -- <arquivos>`), zero merge.
  Schema PHP↔Swift: se bumpar de um lado, bumpa do outro + fixture + checks no MESMO
  commit (o `requireSchema` falha duro de propósito).

## PROIBIÇÕES ABSOLUTAS
Fraude ou atalho que infle o Atlas; relaxar RuntimeProofAttacher/exclusões do
MeasurementStore; apagar/editar medição pra "melhorar" número; paralelizar mesma suíte;
modelo não-Verboo; declarar capacidade "measured" com dado que você sabe contaminado;
parar porque "está quase" — quase não é a definição de pronto.

## CADÊNCIA
Placar em PT-BR a cada ciclo de sentinela (~10 min de verificação quando houver evento):
capacidades measured/low/unmeasured, N por braço, suíte em execução, bloqueadores com
dono. Se um bloqueador ficar >2h sem caminho, escreva a investigação no war-room e
ataque o próximo item da fila — a fila nunca para por causa de UM item.

## PARADA
Só quando o checklist da DEFINIÇÃO DE PRONTO estiver 100% com as provas anexadas
(screenshot device + relatório + perfil JSON + gates verdes) e o veredito final por
capacidade escrito no war-room. Aí escreva o handoff de encerramento e pare.
