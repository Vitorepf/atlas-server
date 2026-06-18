---
title: AP-820 Quality Ascent — métrica escalar de qualidade de implementação + catraca + cadeia de avanço de baseline (estilo autoresearch, governado)
status: implemented
owner: atlas-ai / autonomous-evolution / quality
line_limit: 240
related_paths:
  - app/Services/Ai/AutonomousEvolution/Quality/AtlasImplementationQualityScorer.php
  - app/Services/Ai/AutonomousEvolution/Quality/AtlasQualityAscentRunner.php
  - app/Console/Commands/AtlasLoopQualityScoreCommand.php
  - app/Console/Commands/AtlasQualityAscentCommand.php
  - app/Http/Controllers/AtlasLoopQualityController.php
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionScenarioExplorer.php
  - app/Services/Ai/AutonomousEvolution/AtlasEvolutionLoopRunner.php
  - app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php
  - app/Services/Ai/AutonomousEvolution/Persistence/AtlasLoopStore.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FinalDeliveryQualityGateService.php
  - config/atlas.php
  - routes/api.php
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.


# [AP-820] Quality Ascent — o val_bpb do código + catraca + avanço de baseline

## 1. Propósito + fonte verificada

Capturar o mecanismo do **autoresearch** (Karpathy, github.com/karpathy/autoresearch
— **lido e verificado**): superfície de edição limitada (`train.py`), orçamento fixo
por experimento (5 min), **UMA métrica escalar honesta** (val_bpb), regra
"melhorou estritamente → mantém, senão → descarta", rodando indefinidamente.

**Achado código-verificado que moldou a obra:** o Atlas JÁ tinha ~90% dessa forma.
`AtlasEvolutionFrozenJudge` é o juiz autoresearch (canal escalar
METRIC_MAXIMIZE/MINIMIZE + `isStrictlyBetter`, 4 guardas anti-trapaça:
TAMPER/SCOPE/RE-PROOF/DIFF-EARNED); `AtlasEvolutionScenarioExplorer` é o best-of-N
(3..12 cenários, paciência, argmax). O que faltava: (a) **nenhuma tarefa de
engenharia usava o canal escalar** (todas hardcodavam gate binário; só finanças usava
maximize); (b) **nenhum escore de qualidade computado do código** (phpstan level 5
instalado e nunca invocado por gate; pass-rate nunca parseado); (c) métricas por
tentativa descartadas (viravam contagens); (d) **sem avanço de baseline** (rodada
N+1 nunca partia do vencedor da rodada N).

## 2. Decisões de desenho (pós-crítica adversarial)

A crítica adversarial pré-build derrubou metade do desenho original — registrar
para não regredir:

1. **Scorer roda CANONICAL-SIDE, nunca dentro do workspace candidato.** Rodar
   `php artisan` do candidato deixaria código candidato imprimir
   `QUALITY_SCORE=...` no boot (o juiz lê primeiro match do stdout, excerpt 4k).
   Padrão copiado do P3 (`AtlasP3FindingDispatcher:213` — artisan do repo contra o
   workspace como dado). Stdout do comando: EXATAMENTE uma linha.
2. **Componentes cortados por estarem mortos ou duplicados:** pass-rate (juiz já
   rejeita teste vermelho — sem poder discriminante), TestMeaningfulnessScorer
   (produtores congelam tests/** — diff de teste vazio para sempre), penalidade de
   tamanho de diff (duplica o desempate smaller-diff do explorer).
3. **O que sobrou com sinal real:** delta de phpstan nos arquivos mudados
   (workspace vs baseline extraído de `git show HEAD:`, ruído de autoload cancela
   no delta) + guarda anti-supressão (`@phpstan-ignore` adicionado conta como
   erro) + pint dirty. Gates duro-zero (não pesos): marcadores de placeholder
   (FinalDeliveryQualityGateService, baseline-aware) e vocabulário proibido.
4. **Catraca antes de argmax.** Seleção continua gate binário + smaller-diff
   (provada). O escore entra como pós-gate SÓ NO VENCEDOR no certify path do
   grinder: `score >= floor (0.5 neutro)` ou proposta rejeitada
   (`quality_ratchet_below_floor`). 1/24 do custo, mesma curva longitudinal.
   Flip para maximize in-loop = fase 2, SÓ se a curva da catraca mostrar
   amplitude dinâmica real (honestidade: delta-phpstan satura por arquivo;
   o residual escalável de verdade é mutation score via infection — fase 2).
5. **Cadeia de avanço de baseline fora do grinder.** O grinder hardcoda
   `base_path()` (linhas ~68/72) e o `diff_text` persistido é truncado a 20k +
   apêndice raw de untracked (não é patch válido). A cadeia dirige
   materializer+explorer diretamente com o CLONE como base e captura patch
   íntegro (`git add -N` + `git diff --binary`, sem limite) do workspace vencedor.

## 3. O que foi construído

- **`AtlasImplementationQualityScorer`** (`atlas.loop.implementation_quality.v1`)
  + `atlas:loop:quality-score {--workspace=} {--report=}`. Fórmula: gates duro-zero;
  senão `clamp(0,1, 0.5 − 0.10·max(0,Δ)+supressões + 0.05·min(corrigidos,6) −
  0.02·pint_dirty)`. Neutro exato = 0.5. Fail-open honesto para 0.5 com nota se
  phpstan indisponível (catraca nunca bloqueia por outage de ferramenta).
- **Persistência por tentativa:** `atlas_loop_explorations.attempt_metrics`
  (lean: scenario/passed/metric/metric_finite/diff files+lines, cap 24, NUNCA
  stdout/diff_text) + `atlas_loop_proposals.quality` (verdito completo do scorer).
- **Catraca no grinder** (`gateFrameworkImplementationProposals`): modos
  off|observe|enforce em `atlas.loop.quality_ratchet` (env
  `ATLAS_LOOP_QUALITY_RATCHET`, floor 0.5). Exceção do scorer nunca mata o grind
  (fail-open com `quality.error`). phpunit.xml pina off.
- **Painel do app:** `GET /ai/loop/quality` (atlas.token) — ratchet config,
  propostas recentes com quality, explorations com attempt_metrics, score_curve
  cronológica, cadeias de ascent. (Namespace /ai/quality já era ocupado.)
- **`AtlasQualityAscentRunner`** + `atlas:quality:ascent`: clone dedicado em
  `storage/atlas/quality_ascent/<chain>/repo`; por rodada: explore best-of-N com
  clone como base → patch íntegro do vencedor → scorer (catraca ≥0.5) →
  `git apply + commit` NO CLONE → rodada seguinte parte do baseline avançado.
  Caps: rounds (≤10), linhas cumulativas (default 3000 → `frozen_for_review`),
  paciência 2 rodadas secas, kill/pause switches do loop respeitados. Asserts:
  clone nunca realpath-igual a base_path(); git mutante só com `-C <clone|ws>`.
  Entregável = `cumulative.patch` + `state.json` (propose-only; operador revisa).

## 4. Invariantes (não regredir)

- O scorer fica **FORA da Harness Surface** (G1: o autopilot nunca edita o sinal
  que o julga). frozen_globs de tarefas quality devem incluir os arquivos do scorer.
- Propose-only intacto: a cadeia avança baseline SÓ no clone; main, working tree
  do operador e refs canônicos nunca são escritos; never-merge×3 inalterado.
- Neutro = 0.5 por construção: catraca = não-regressão estática monotônica.
- Comparabilidade de escore só dentro do mesmo task/chain (acceptance_hash pina o
  contrato; flip de flag re-keya dedup de proposta — esperado, cosmético).

## 5. Fase 2 (nomeada, não construída)

- Mutation score (infection, instalado) winner-only como métrica escalável real.
- Flip seletivo de tarefas framework para METRIC_MAXIMIZE com o scorer como
  último comando frozen (`metric_pattern /QUALITY_SCORE=([0-9.]+)/`) — só com
  evidência de amplitude dinâmica na curva da catraca.
- Paralelização dos cenários (hoje serial por design do explorer).
