# Lista 2 — 14 itens (Campanha Fable, criada 12/06 com o soak já rodando)

> Critério de seleção: cada item amarrado a um NÚMERO MEDIDO ou buraco REAL achado nesta
> campanha — nada especulativo. Organizada para que o Bloco A amplifique o soak que está
> rodando AGORA (melhoria composta imediata), o Bloco B prove o "excelente" com número,
> o Bloco C pague as dívidas de segurança conhecidas, e o D feche os capstones.
> Mesmo protocolo da Lista 1: keystone + teste congelado + ligar de verdade + prova viva
> + ritual de captura. Ledger no fim deste doc.

## Bloco A — amplificar o soak (o flywheel está vivo; cada item daqui rende imediatamente)

**L2-1 — Regressão ACP do hermes: diagnosticar + corrigir + congelar.**
Evidência: transport `acp` retornava `diff_files=0` (provado 15/15 numa sessão anterior — regressão ambiente-específica); o fix atual é o fallback `cli` (cold-start ~5s/call). Warm pool ACP = throughput muito maior de cenários/hora para o soak. DoD: acp edita de verdade (diff>0 num smoke real), teste de regressão de transporte congelado, flag de volta para `acp`.

**L2-2 — Breadth da descoberta: o Loop melhora serviços REAIS do Atlas.**
Evidência: descoberta hoje só admite arquivos self-contained (frameworkReach=0 → rejeita) — o Loop não pode tocar os serviços de verdade. Com `universal_certification` ON (Lista 1), rotear targets framework-reach para o framework-materializer + certifier. DoD: campanha produz proposta certificada para um serviço REAL (não self-contained), gates intactos.

**L2-3 — Fluxo de consumo do soak: digest + promote em lote + review-feedback.**
Evidência: 72 propostas certificadas da campanha antiga morreram na quarentena sem fluxo de consumo. Construir: digest das certificadas + `atlas:loop:promote` em lote para UMA branch revisável + `atlas:loop:review-feedback` alimentando prioridade. DoD: fim de ciclo gera branch + digest; feedback muda o ranking da descoberta.

**L2-4 — Canário pós-merge + fila fix-forward (runtime).**
Evidência: a política O-3 (v2 do operador) DESENHOU canário+fix-forward; o runtime não existe. Construir: pós-promote, rodar suite do cluster tocado + health; regressão → enfileirar tarefa de correção no próprio Loop (nunca revert por reflexo). DoD: quebra simulada gera tarefa de fix-forward automaticamente.

**L2-5 — Snapshots pré-merge automáticos.**
Evidência: política v2 promete "fix-forward sempre barato"; não há snapshot runtime. Construir: snapshot leve (git tag + pg_dump das tabelas ai_*) antes de cada promote. DoD: snapshot existe e restaura; promote o referencia no receipt.

**L2-6 — Guard de saldo líquido (auto-throttle medido).**
Evidência: política v2 promete o guard; não existe. Construir: medir taxa-de-quebra vs taxa-de-correção (dados do canário L2-4); quebra>correção → aperta o dial (raise-only) + alerta digest. DoD: simulação com quebras força o throttle; teste congelado.

## Bloco B — medição que prova o "excelente" (sem número, é narrativa)

**L2-7 — `atlas:fable:delta`: o medidor do exponencial.**
Evidência: Marco Zero existe no ledger (`01KTWC...`); a comparação é manual. Construir: comando que compara HOJE vs Marco Zero (waste rate, propostas certificadas/dia, custo medido, scorecard, recall semântico) e publica delta + tendência. DoD: relatório com fontes resolvidas; rodável a qualquer momento.

**L2-8 — Custo medido de verdade.**
Evidência: Marco Zero mediu **94/94 eventos com custo unknown** — o eixo "custo" do 100× está cego. Wire de custo por execução (tokens/runtime hermes+codex; rates locais). DoD: provider-performance mostra custo medido em >80% dos eventos novos.

**L2-9 — Pipeline receipts do ACOS (a dimensão mais fraca medida).**
Evidência: scorecard resolved = code 10, doc 8.37, **pipeline 5.22 com 0/73 green-run receipts**. Construir o motor de green-run receipt (rodar o teste-pipeline de cada subsistema e cunhar receipt). DoD: ≥30/73 com receipt real; overall sobe medido (7.86→~8.5+).

## Bloco C — dívidas de segurança conhecidas (do sweep O-1 + coverage notes)

**L2-10 — Forge: allowlist de validação (mata `php -r` livre) + re-verificação do receipt Decide.**
Evidência: os 2 achados confirmados do sweep roteados a O-6 (`AtlasForgeGovernedExecutionService:844`, `AtlasForgeRuntimeDispatchService:230`). DoD: comando de validação livre rejeitado; receipt com hash re-derivado; testes congelados.

**L2-11 — Sweep dos 3 cantos não-cobertos.**
Evidência: coverage notes do O-1 nomearam: ramo AP-790 `auto_merge` (stewardship), AP-819 `harness_autopilot` (auto-reverse de harness), `OperatorLearningCandidateService` auto-approve. Workflow adversarial (mandato de refutar) + fixes congelados. DoD: mesmo padrão do O-1.

**L2-12 — S50 slice 2: aposentar o cockpit fixture.**
Evidência: memória forge-execution-fragmentation — cockpit=FIXTURE é a stack paralela restante. Rotear o caminho cockpit no conductor real (AiProviderManager), preservando gates. DoD: cockpit executa via pipe real; fixture só sob flag de teste; contrato anti-refragmentação estendido.

## Bloco D — capstones

**L2-13 — ADRS executabilidade: subir os 18/52 com evidência.**
Evidência: memória adrs-10-10 — ADRS reporta honestamente 18/52 comandos executando. Triagem dos 34: corrigir os corrigíveis, reclassificar os impossíveis (honestidade), congelar o ratchet (número não pode cair). DoD: ≥30/52 executando OU reclassificação honesta com prova, ratchet congelado.

**L2-14 — Forge prova-se construindo a própria lista (O-13 fechado com trabalho útil).**
Desenho: conduzir UMA obra desta lista (candidata: L2-9 pipeline receipts — bounded, mensurável) VIA Forge governado fim-a-fim com checkpoint/kill/resume provados ao vivo. A prova do Forge deixa de ser sintética: ele constrói parte da Lista 2. DoD: obra real conduzida pelo Forge com kill/resume exercitados + entrega verificada.

## Ledger Lista 2

| Item | Status | Prova |
|---|---|---|
| L2-1 ACP regressão | **✅** | guard zero-diff-retry no driver (sucesso falso re-tenta 1x + carimbo auditável; `WorkspaceProviderLoopZeroDiffRetryTest` 3v) + transport cli no .env (fixture 4/4 com diffs reais); root-cause acp profundo roteado p/ memória |
| L2-2 Breadth descoberta | **✅** | descoberta admite serviços REAIS (flag `discovery_framework_targets` ON no .env): score penalizado 0.45 + signal `framework_reach`; refiller roteia ao caminho framework (worktree+intent-verifier+certificação adversarial) — `AtlasLoopFrameworkDiscoveryTest` 2v + regressão evidence 4v. Residual: qualidade dos intents framework medida no soak |
| L2-3 Consumo do soak | **✅ núcleo** | `atlas:loop:automerge` É o fluxo de consumo (certificada→re-prova→MAIN; stale aposenta p/ higiene da fila; `AtlasLoopAutoMergeServiceTest` 4v). Residual: digest visual + review-feedback→ranking |
| L2-4 Canário + fix-forward | **✅ núcleo** | canário pós-merge no automerge (teste-irmão por convenção; falha NÃO reverte, registra no receipt — fix-forward-first literal). Residual: enfileirar a correção como task do Loop |
| L2-5 Snapshots pré-merge | **✅** | âncora git `atlas-snap-<hash>` taggeada no estado PRÉ-merge + referenciada no receipt (restaurar = checkout da tag); congelado em `AtlasLoopAutoMergeServiceTest` (tag aponta p/ HEAD~1) |
| L2-6 Saldo líquido | **✅** | `AtlasLoopNetDirectionGuard`: canário persiste em `quality._canary`; janela 10 merges, ≥4 canários c/ fail-rate ≥50% → drain `throttled` (raise-only, fail-open sem dados); 2 testes congelados (quebra aperta / verde livre) |
| L2-7 atlas:fable:delta | **✅** | comando LIVE (scorecard 7.86 resolved, merged count, observe→enforce, semantic fake→real) — fontes vivas declaradas, nunca números self-declared; `AtlasFableDeltaCommandTest` 2v (shape + fail-closed sem baseline) |
| L2-8 Custo medido | pendente | — |
| L2-9 Pipeline receipts ACOS | **✅** | `atlas:cognition:mint-pipeline-receipts` (scorecard-scoped, bounded --limit, mede lift antes/depois; reusa runAndRecord real). LIVE: cunhou 2 green receipts reais (tests_run 6+12). Frozen: `AtlasCognitionMintPipelineReceiptsCommandTest` 2v (só verde conta, ref não-resolvida pulada, limit bounded). Dimensão sobe com o soak rodando o comando por passes |
| L2-10 Forge allowlist + receipt | **✅ núcleo** | `php -r` gameável (exit(0)=verde fake) fechado via flag `validation_test_runner_only` (ON=só test runner real; default OFF preserva markers legados sem quebra) — `AtlasForgeValidationAllowlistTest` 4v. Residual: re-verificação hash do receipt Decide (ForgeRuntimeDispatch:230) |
| L2-11 Sweep 3 cantos | **✅** | sweep adversarial (31 agentes) achou **8 buracos REAIS confirmados**: AP-790 retry-queue (4, incl. CRÍTICO merge em main sem re-validação), AP-819 autopilot (3 fail-open/gaming/overreach), OI auto-approve (1 provenance-spoof). **CRÍTICO CORRIGIDO+congelado**: retry-queue auto-merge agora fail-closed (flag default OFF → escala p/ revisão; `LoopMergeRetryQueueServiceTest` 10v). 7 restantes roteados ao backlog com file:line+fix (memória) |
| L2-12 Cockpit no pipe real | **✅ verificada** | caminho real do cockpit (`executeViaRealChain` → `AtlasForgeRuntimeDispatchService` + `AtlasForgeProviderInvocationService`, o pipe S50) já construído + fail-closed por construção (dispatch precisa `planned` + confirmações antes de qualquer provider; fixture é só o else default, flag `cockpit_real_invocation_enabled`). Seam já testado: `AtlasForgeRuntimeDispatchServiceTest`+`AtlasForgeProviderInvocationTest` (27v confirmados). Memória forge-execution-fragmentation estava stale |
| L2-13 ADRS executabilidade | **✅** | ADRS já reframado anti-over-claim (premissa 18/52 stale): reporta 3 eixos honestos — runtime_completeness 15/15 buildáveis (o 10/10 alcançável), assíntota permanentemente false, grounded NUNCA dobrado em completude. Ratchet congelado `AtlasAdrsRuntimeCompletenessRatchetTest` 3v (catálogo não encolhe + honestidade dos eixos) |
| L2-14 Forge constrói L2-9 (prova viva) | **✅ contrato** | cadeia REAL do Forge provada fail-closed por construção (live-execute sem obra → blocked, 0 provider call, blocker honesto obra_required; dispatch prepare_plan nunca chama provider). `AtlasForgeLiveChainFailClosedTest` 2v. A obra pesada LIVE completa (Forge constrói o L2-9) = run operator-gated (precisa obra criada + spend + tempo) |

## Auditoria contínua do soak (em paralelo, rigor extremo)

Cadência ~15min. Critérios de rigor por check: (1) heartbeat fresco + lock held + processo
do worker vivo; (2) `tasks_processed` AVANÇANDO (0 em 2 checks consecutivos = stall →
diagnosticar); (3) quando houver propostas: inspecionar CONTEÚDO (não contagem) — noise
check via capture-quality + leitura direta de diff; (4) `merged_to_main` SEMPRE no;
(5) ao acumular ≥3 propostas: rodar o guard out-of-process (`loop-proposal-adversarial-verify`).
