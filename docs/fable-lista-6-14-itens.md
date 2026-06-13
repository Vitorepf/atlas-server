# Lista 6 — 14 itens (horizonte far; pensada pelo Fable em 12/06 a pedido do operador)

> **A mudança de pergunta.** Listas 1-5 respondem "o Atlas consegue shipar bom código
> sozinho?". A Lista 6 responde uma pergunta diferente e mais difícil: **"o operador pode
> CONFIAR no Atlas a ponto de parar de revisar cada merge — quando o volume já passou do
> que qualquer humano consegue auditar?"** Esse é o frontier real depois da auto-direção:
> não mais capacidade, mas CONFIANÇA EM ESCALA. Três coisas têm que ficar verdadeiras ao
> mesmo tempo: (1) o loop melhora o próprio jeito de melhorar (meta), (2) a verificação
> fica funda o bastante para um merge não-revisado ser seguro, (3) o cérebro compõe
> inteligência de verdade (ACOS real, multi-agente auto-montado, preditivo). E o teste
> final do N×M: com N FIXO em gpt-5.5/M3 (sem Fable), o Atlas ainda ACELERA — porque o M
> cresce sozinho.
>
> HONESTIDADE DE HORIZONTE (canon anti-over-claim + filtro de 5 perguntas do operador):
> isto é DIRECIONAL, não um plano de execução. Quanto mais longe a lista, maior o risco do
> "teto/perfeito" — a partir daqui, mais listas é, em si, uma forma da armadilha. O teste
> de uma boa Lista 6 não é estar bem escrita; é a Lista 4 rodando ter PROVADO que ela é
> necessária. Re-validar contra evidência real antes de tocar uma linha.

## Bloco A — O loop melhora o PRÓPRIO mecanismo de melhorar (meta-multiplicador)

**L6-1 ⭐ Meta-loop REAL com prova A/B.**
O L3-12 deu o guardrail (o loop pode tocar o harness não-segurança, frozen judge intocável);
aqui ele EXERCITA: propõe melhoria no próprio gerador-de-intent/materializer auxiliar e PROVA
que subiu a taxa de certificação num A/B medido (cohort com vs sem a mudança). Sem prova de
lift, não promove. DoD: 1 melhoria de harness com lift A/B>0 demonstrado e receipt.

**L6-2 ⭐ Juiz que se calibra pela própria falha.**
Todo merge que precisou de fix-forward (canário RED) é um caso em que o juiz DEIXOU PASSAR
algo ruim. O juiz aprende: o padrão da falha vira um verificador novo no painel (gated,
nunca afrouxa, só aperta). O gate fica mais rigoroso com a própria experiência. DoD: um
fix-forward histórico produz um verificador que teria pego o caso, congelado.

**L6-3 — Portfólio de estratégias do explorer (bandido).**
O explorer tenta N cenários com estratégias fixas. Aqui as estratégias COMPETEM: UCB/bandido
escolhe qual abordagem tentar por tipo de alvo, com base em qual historicamente certifica.
O loop fica mais eficiente em tokens a cada semana. DoD: distribuição de estratégia muda por
tipo de alvo, taxa de certificação por token medida subindo.

**L6-4 — Propostas de auto-arquitetura.**
O Atlas lê o PRÓPRIO code-graph e propõe refactors estruturais (god-objects, fragmentação,
acoplamento alto) como obras parked — não mais micro-fixes, mas saúde estrutural do próprio
corpo. DoD: 1 refactor estrutural proposto a partir de métrica de grafo, revisado pelo operador.

## Bloco B — Verificação funda o bastante para confiar sem revisar

**L6-5 ⭐ Property-based + mutation no gate.**
Além do teste-spec, o gate gera inputs adversariais (a família NaN/INF/overflow da memória dos
kernels) e faz mutation testing leve: muta o código aprovado e exige que o teste FIQUE vermelho
— provando que o teste realmente pega a regressão (anti-teste-vazio). DoD: um teste que passa
mas não detecta mutação é REJEITADO pelo gate.

**L6-6 ⭐ Verificação semântica cross-file via code-graph.**
O diff de 1 arquivo pode quebrar quem o CONSOME. O gate consulta o code-graph, identifica os
consumers do símbolo mudado e re-verifica que os contratos deles seguem válidos — a segurança
que falta para um merge não-revisado. DoD: um diff que quebraria um consumer é refutado mesmo
com o teste-spec local verde.

**L6-7 — Oráculo de regressão de longo prazo.**
Guarda o comportamento OBSERVADO (não só o testado) e detecta drift sutil entre versões que
nenhum teste-spec cobre. O Atlas percebe "isso mudou de jeito que ninguém pediu". DoD: um drift
de comportamento não-coberto-por-teste é flagueado.

**L6-8 — Invariantes formais-leves nos kernels sensíveis.**
Os pisos sovereign (never-merge, numeric-safety, os guards de segurança) ganham checagem de
invariante mais forte que teste de exemplo — provas leves que não regridem. O núcleo de
confiança fica matematicamente ancorado. DoD: invariante de um kernel sensível provado e
congelado contra regressão.

## Bloco C — O cérebro COMPÕE inteligência (ACOS real, com dado de semanas rodando)

**L6-9 ⭐ ACOS 10/10 com pipeline REAL alimentado.**
A dimensão pipeline (era 5.22, subindo via L3-11) chega a 10/10 não por mint pontual, mas por
SEMANAS de loop rodando alimentando green-run receipts reais. O scorecard vira retrato honesto
de um organismo vivo, não de andaime. DoD: overall ≥9.5 com pipeline resolved-evidence de >30
dias de operação real.

**L6-10 ⭐ Orquestração multi-agente AUTO-COMPOSTA.**
Hoje o painel adversarial é fixo (N verificadores). Aqui o Atlas MONTA a topologia certa por
tipo de problema: debate para decisão de design, tournament para escolher entre N implementações,
panel para verificação — escolhido por ele, não hard-coded. DoD: o Atlas seleciona topologia
diferente para 2 tipos de tarefa e mede qual converge melhor.

**L6-11 — Code intelligence PREDITIVO.**
O grafo deixa de descrever o passado e passa a prever: "este arquivo tem alta probabilidade de
quebrar se tocado" (histórico de falhas + acoplamento + churn) alimentando o ranking de
descoberta e o veredito de risco. O Atlas sabe ONDE o perigo mora antes de entrar. DoD: score
de risco preditivo correlacionado com falhas reais medido.

**L6-12 — Continuidade cross-semana provada.**
O compounding fecha de verdade: o brain compõe contexto de SEMANAS (não de sessão), e o recall
de uma decisão de 3 semanas atrás influencia uma certificação de hoje, medido. O M visível
crescendo no scorecard. DoD: A/B mostrando lift de recall de memória antiga em tasks novas.

## Bloco D — A fronteira custo→capacidade + o ledger que deixa o operador SOLTAR

**L6-13 ⭐ N×M com N FIXO — o teste decisivo pós-Fable.**
O experimento que prova a tese inteira: com o motor TRAVADO em gpt-5.5/M3 (sem Fable, sem
modelo melhor), a capacidade-por-dólar do Atlas AINDA SOBE mês a mês — porque o harness, a
memória e a verificação (o M) melhoram sozinhos. Se isso for verdade, o Atlas é antifrágil de
verdade. DoD: série temporal de capacidade-por-dólar com N constante, tendência positiva.

**L6-14 — Trust ledger: o operador para de revisar cada merge.**
O capstone da Lista 6 e o ponto inteiro dela: um ledger de confiança por classe-de-mudança
(taxa de fix-forward, taxa de reversão, severidade) que, quando uma classe acumula histórico
verde suficiente, REDUZ a fricção de revisão para ela (auto-merge sem parked-review) — sempre
reversível, nunca para a camada never-merge nem para os kernels sensíveis, sempre com o operador
podendo reapertar. O operador deixa de ser o gargalo. DoD: 1 classe-de-mudança ganha
auto-confiança por evidência; uma regressão na classe REVERTE a confiança automaticamente.

## A nota honesta que fecha a escada
Listas 1→6 sobem uma escada de autonomia: construir → blindar → produzir → auto-manter →
auto-dirigir → **auto-confiar**. A Lista 6 é onde o trabalho do Fable se torna invisível: se
funcionar, o operador não vê mais merges individuais, vê um organismo confiável reportando.
Mas o canon do próprio Atlas avisa: o teto não é uma lista melhor, é código rodando alimentado
por dado real. A melhor Lista 6 possível é inútil até a Lista 4 PROVAR, com número, que o
flywheel sustenta qualidade por semanas. Até lá, isto é uma bússola — não um destino.

## Ledger Lista 6

Base comum de revalidacao em 13/06: recibos em `storage/app/atlas/evidence/l6-*`.
AOBG provider-safe (`context_pack_hash=75a868...`). Evidencia viva: `atlas:loop:funnel
--json` => 272 descobertas, 246 tentadas, 282 propostas, 278 certificadas, 111 merges em
main, 1 drainable, 166 stale, conversao certified->merged 0.3993; `atlas:loop:morning-digest
--json` => 100 merges/24h, 6 canarios vermelhos, 0 propostas parked, custo segue sem medicao
em dolares. `atlas:cognition:scorecard --json` => overall 8.1, code 10, docs 8.37,
pipeline 5.93, `benchmark_claim_allowed=false`; `atlas:fable:delta-series --report --json`
=> serie de 2 dias, scorecard +0.05, merges +91, impact coverage +20.5pp, semantic recall
real flat true. `atlas:aobg:semantic-lift --json` => lift semantico positivo em fixture
L4-11 (10/10 medidos, 8 positivos, lift medio 0.8), mas nao A/B de tasks reais.
Bundle de substrato L6: `php artisan test tests/Unit/Ai/Compounding/AtlasLearningMutationRuntimeServiceTest.php tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AtomicBacklog/SddMutationApprovalGateEvaluatorTest.php tests/Unit/Ai/Governance/AtlasConstitutionalKernelServiceTest.php tests/Unit/Ai/Governance/AtlasAutonomyAdmissionTrustLadderTest.php tests/Unit/Ai/Governance/AtlasChangeClassTrustLadderTest.php tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/L9InvariantProofResultVerifierTest.php tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TrustLedgerStabilityGateTest.php tests/Unit/CodeGraph/CodeGraphRegressionDetectorTest.php tests/Unit/Engineering/CodeGraph/CodeGraphPrConflictAnalyzerTest.php tests/Feature/Engineering/AtlasSoftwareTwinPredictiveSimulatorTest.php`
= 103 passed / 400 assertions. Apos atualizar Lista 5, foi cumprido o ritual de captura:
`atlas engineering knowledge sync --prune` => 107 docs updated, 0 failed; `atlas engineering
knowledge index-code --workspace=/Users/vitorepf/develop/Atlas/atlas-server --prune
--summary-only --json` => 23 modules, 119040 symbols, 170936 doc links, 601 routes, 982
commands, 23398 tests; `atlas:engineering:knowledge code-gate --strict --json` voltou
`status=ready`, blockers `[]`, drift 0, 11 consumers, 0 missing.

| Item | Status | Prova |
|---|---|---|
| L6-1 Meta-loop A/B | **🟡 medidor A/B ligado; live bloqueado por 0 casos meta-harness e 1 alvo proibido historico** | Revalidacao em 13/06, recibos em `storage/app/atlas/evidence/l6-1/`: AOBG provider-safe (`aobg-context-l6-1-meta-loop-ab-2026-06-13.json`), bootstrap/placement reusam o spine Loop/harness existente. Keystone implementado sem runtime paralelo: `AtlasLoopMetaHarnessAbLiftService` + `atlas:loop:meta-harness-ab-lift` medem, em read-only, tasks reais em dois braços (`meta_harness` = alvo admitido por `AtlasLoopHarnessGuard` e `ordinary` = demais tasks), calculam taxa de certificação por braço e exigem lift positivo antes de qualquer claim; qualquer alvo de `FORBIDDEN_SELF_TARGETS` no window bloqueia completion. Flags ON em `config/atlas.php`/`.env`: `ATLAS_LOOP_META_HARNESS_TARGETS=true` (alvos harness nao-seguranca liberados; frozen judge/gates/HarnessGuard seguem proibidos), `ATLAS_LOOP_META_HARNESS_AB_LIFT_ENABLED=true`, schedule diario 06:15, janela 168h, min 3 casos/braco, min lift 0.01. Schedule provado em `meta-harness-ab-lift-schedule-2026-06-13.txt`; apos tocar flag de pipeline, supervisor do soak foi reiniciado em `tmux`, e `soak-supervisor-meta-harness-restart-status-2026-06-13.json` mostra campaign `019ebc6e-3861-701a-9435-07e1dedbf57b` running, heartbeat atualizado, `meta_harness_targets=true`, `ab_lift_enabled=true`. Prova congelada: `/opt/homebrew/bin/php artisan test tests/Feature/Loop/AtlasLoopMetaHarnessAbLiftTest.php tests/Feature/Loop/AtlasLoopHarnessGuardTest.php` = 8 passed / 64 assertions; cobre lift positivo com casos reais nos dois braços, bloqueio por alvo proibido mesmo com lift positivo, e schedule. Prova viva: `meta-harness-ab-lift-live-2026-06-13.json` => `status=insufficient_live_ab_evidence`, braço `ordinary` com 300 casos / 271 certificados / taxa 0.9033, braço `meta_harness` com 0 casos, `lift.certification_rate_delta=null`, `completion_claim_allowed=false`; blocker honesto `meta_harness_cases_below_min` e blocker de segurança `forbidden_self_targets_seen_in_window` com 1 alvo historico (`AdversarialProofPanelService.php`). Strict salvo em `meta-harness-ab-lift-strict-2026-06-13.json` saiu com exit 1 esperado. Anti-overclaim: o medidor e a flag estao vivos, mas o DoD pleno (1 melhoria de harness com lift A/B>0 em tasks reais) ainda nao esta provado. |
| L6-2 Juiz auto-calibrado | **🟢 provado: fix-forward historico gerou verificadores congelados RED** | Revalidacao/implementacao em 13/06, recibos em `storage/app/atlas/evidence/l6-2/`: AOBG provider-safe (`aobg-context-l6-2-judge-calibration-2026-06-13.json`, hash `563a18...`), session bootstrap e placement (`flow=learning.failure_review`) antes do codigo. Keystone diagnosticado no caminho real: `AtlasLoopAutoMergeService` persiste canario RED em `quality._canary`, enfileira task `source=fix_forward` com `payload.origin=fix_forward_canary_red`, `snapshot_tag` e `canary_target`; `AtlasLoopIntentVerifierFactory` ja compila verifier RED com `acceptance.revert_recheck=true`. Implementacao default-safe: `AtlasLoopJudgeSelfCalibrationService` + `atlas:loop:judge-calibration` cruzam tasks fix-forward historicas, recusam alvo proibido via `AtlasLoopHarnessGuard`, exigem teste-canario existente em `tests/`, compilam pacote com atom `command_output` (`php artisan test <canary>`, espera `PASS`) e so contam quando `red_preflight.status=red` + `revert_recheck=true`; escreve apenas manifest/packets de evidencia, `provider_calls_made=false`, `workspace_mutated=false`, `never_merge_changed=false`, `merge_gate_changed=false`. Flags ON em `config/atlas.php`/`.env`: `ATLAS_LOOP_JUDGE_SELF_CALIBRATION_ENABLED=true`, schedule ON diario 06:20, janela 168h, max 8 casos, write packets true; schedule provado em `judge-self-calibration-schedule-2026-06-13.txt`. Teste congelado: `/opt/homebrew/bin/php artisan test tests/Feature/Loop/AtlasLoopJudgeSelfCalibrationTest.php tests/Feature/Loop/AtlasLoopFixForwardTest.php tests/Feature/Loop/AtlasLoopCompileVerifierCommandTest.php tests/Unit/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactoryTest.php tests/Feature/Loop/AtlasLoopMetaHarnessAbLiftTest.php tests/Feature/Loop/AtlasLoopHarnessGuardTest.php` = 26 passed / 146 assertions; cobre fix-forward historico -> verifier congelado RED, bloqueio de alvo proibido, schedule, fix-forward e compiler. Prova viva: `judge-self-calibration-live-2026-06-13.json` => `status=verifier_candidates_ready`, 8 casos historicos, 7 `ready_verifier_candidates`, 1 blocked (`compiled_verifier_not_red_on_baseline`), `completion_claim_allowed=true`; packets escritos em `storage/app/atlas/evidence/judge-self-calibration-packets/`, incluindo hashes `080e8e...`, `385e51...`, `fe7404...`, `f72e03...`, `1f6d75...`, `5d04cf...`, `fee14d...`, todos com `red_preflight_status=red` e `acceptance_revert_recheck=true`. Strict salvo em `judge-self-calibration-strict-2026-06-13.json` saiu `exit_code=0`. Anti-overclaim: o item L6-2 esta provado pelo DoD (>=1 fix-forward historico gerou verifier que teria pego o caso e ficou congelado), sem liberar merge nao-revisado nem alterar a porta never-merge. |
| L6-3 Portfolio de estrategias | **🔴 bloqueado; ADML segue advisory, sem bandit ativo** | `atlas:atlas-decide:meta-learning --map --json` => 7 segmentos, `advisory_only=true`, `routing_effect=none`, `should_update_provider_topology=false`, `provider_tokens_spent=false`, repeat readiness `insufficient_evidence` (8 buckets ready / 441 not ready), `claim_ready=false`, `external_claim_allowed=false`. `atlas:atlas-decide:routing-table --json` => `active_entries=0`, `shadow_entries=0`. Nao ha UCB/bandit mudando estrategia por alvo nem taxa por token subindo. |
| L6-4 Auto-arquitetura | **🟡 code-graph/twin prontos; refactor estrutural parked nao executado** | Apos sync/index: `atlas:engineering:knowledge code-gate --strict --json` => `status=ready`, blockers `[]`, 23 modules, 119040 symbols, 170936 doc links, drift 0, 11 consumers, 0 missing. Bundle verde inclui `AtlasSoftwareTwinPredictiveSimulatorTest`, `CodeGraphPrConflictAnalyzerTest` e `CodeGraphRegressionDetectorTest`. Ainda nao ha 1 refactor estrutural proposto por metrica de grafo e revisado pelo operador. |
| L6-5 Property-based + mutation | **🟡 mutation/admission substrato provado; gate de teste mutante ainda nao controla merges** | `atlas:learning:mutation --action=list-evaluations --json` => 1 avaliacao (`test_prop_1`) com `recommendation=safe_to_apply`, `admission_decision=allow_with_approval`, claim policy `auto_apply_allowed=false`, `requires_operator_approval=true`; bundle L6 verde cobre mutation/admission. Isso prova mutacao governada, nao property-based + mutation testing no gate de merge. DoD exige rejeitar teste que passa mas nao mata mutacao; sem prova viva. |
| L6-6 Verificacao cross-file | **🟡 code-graph/consumers prontos; refutacao semantica cross-file nao provada** | Code-gate pos-sync => `ready`, drift 0, `consumer_count=11`, `consumer_missing_count=0`; testes de graph/PR conflict passaram no bundle. Falta a prova especifica: diff com teste local verde sendo refutado porque quebraria consumer via code-graph. |
| L6-7 Oraculo de regressao | **🟡 detectores existem; comportamento observado ainda nao vira oraculo** | `atlas:predict metrics fable-lista-6 --json` => `status=ok`, calibration metrics computed, mas `total_insertions=0`, `outcomes_recorded=0`, `avg_calibration_error=null`, `brier_score=null`. Bundle cobre `CodeGraphRegressionDetectorTest`, mas nao ha drift de comportamento observado entre versoes flageado fora do teste-spec. |
| L6-8 Invariantes formais-leves | **🟡 invariantes locais provadas; nao promovidas como capstone operacional** | Bundle L6 verde provou `AtlasConstitutionalKernelServiceTest`, `AtlasAutonomyAdmissionTrustLadderTest`, `AtlasChangeClassTrustLadderTest`, `L9InvariantProofResultVerifierTest` e `TrustLedgerStabilityGateTest`. Isso e fundacao real, mas DoD pede kernel sensivel com invariante formal-leve congelada como gate operacional para merge nao-revisado; ainda nao ha evidencia dessa promocao. |
| L6-9 ACOS 10/10 real | **🔴 bloqueado por score/pipeline/janela temporal** | `atlas:cognition:scorecard --json` => overall 8.1, code 10, docs 8.37, pipeline 5.93, `benchmark_claim_allowed=false`; `atlas:fable:delta-series --report --json` => serie de 2 dias, nao >30 dias. DoD exige overall >=9.5 com pipeline resolved-evidence por mais de 30 dias; longe disso. |
| L6-10 Multi-agente auto-composto | **🔴 bloqueado; swarm existe, mas sem topologia efetiva/comparada** | `atlas:swarm --action=list --limit=10 --json` => 1566 dispatches historicos; tail exemplo `task_category=reasoning`, `requested_parallelism=2`, `effective_parallelism=0`, `admission_decision=deny`, arms `[]`, aggregate/rivals claims proibidos. ADML segue advisory com `routing_effect=none`. Nao ha 2 classes de tarefa com topologias escolhidas automaticamente e convergencia medida. |
| L6-11 Code intelligence preditivo | **🟡 preditivo local existe; correlacao com falhas reais nao medida** | `atlas:aemor:risk-predict --json` => `status=clear`; code-gate pos-sync => ready, drift 0, 119040 symbols; bundle inclui `AtlasSoftwareTwinPredictiveSimulatorTest`. Tambem `atlas:predict metrics` tem 0 insertions/outcomes, entao nao ha correlacao medida entre score preditivo e falhas reais. |
| L6-12 Continuidade cross-semana | **🔴 bloqueado; sem continuation pack/replay manifest e sem A/B de memoria antiga** | `atlas:long-horizon:continuity-certify --scope-type=long_horizon --scope-id=fable-lista-6 --json --strict-replay` saiu com exit 1 e JSON `status=blocked`: 5 P0 blockers (`continuation_pack_exists`, `context_pack_hash_present`, `freshness_gate`, `replay_manifest_available`, `evidence_present_for_completion`). Semantic lift atual e fixture L4-11, nao A/B de decisao de 3 semanas afetando certificacao de hoje. |
| L6-13 N×M com N fixo | **🔴 bloqueado por janela temporal/custo/provider N** | `atlas:compounding:antifragility-metric --json` => `wrapper_multiplier_m=0.9094`, componentes `m_scorecard=0.8`, `m_observability=1`, `m_governance=0.8551`, `m_density=1`, mas claim policy declara `provider_capability_estimated=false`: N nao e medido. Delta-series tem 2 dias e morning digest custo medido 0%; DoD exige serie mensal de capacidade-por-dolar com N constante. |
| L6-14 Trust ledger (operador solta) | **🟡 trust canonico subiu; classe/friccao ainda nao liberadas** | `atlas:aaeos:trust-ledger-canonical --json` => score 0.9168, `eligible_level=6`, `gate_l4.allowed=true`, append-only ok. Mas `atlas:self-improvement:trust-ledger --json` => `entry_count=0`, `trust_band=insufficient_data`; `atlas:autonomy:admit --list --json` tail segue `decision=allow_with_approval`, `requires_human_approval=true`; nenhuma classe ganhou auto-confianca com regressao revertendo automaticamente. DoD capstone ainda nao cumprido. |
