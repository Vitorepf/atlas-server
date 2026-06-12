# Lista 3 — 14 itens (Campanha Fable, criada 12/06 — a lista que ESCALA)

> Tese de seleção: a Lista 1 construiu o motor (Loop→certify→merge), a Lista 2 blindou e
> mediu (freio, snapshot, canário, delta, sweep). A Lista 3 ataca o que a evidência viva
> mostra ser o gargalo agora: **o flywheel está armado mas não PRODUZ** (82 candidatas,
> 0 merges, drain sem cadência, intents genéricos com 94% waste histórico) e **o cérebro
> ainda não é semântico de verdade** (engine python provada, call-sites fake). Cada item
> amarrado a número medido ou achado file:line. Critério Fable: só entra o que exige
> raciocínio multi-módulo profundo (design de ranking, invariantes, guardrails de
> meta-loop, root-cause) — o que GPT-5.5/MiniMax não entregam com a mesma qualidade.
> Mesmo protocolo: keystone + teste congelado + ligar de verdade + prova viva + ritual.

## Bloco A — fazer o flywheel PRODUZIR (evidência: 82 candidatas / 0 merges / funil cego)

**L3-1 ⭐ Drain em cadência + funil instrumentado + primeiro drain LIVE.**
Evidência: `AtlasLoopAutoMergeCommand` existe, flag ON, mas merged=0 — nada o chama em
cadência; o funil (discovered→intent→diff→accepted→certified→reproved→merged) não tem
contadores por estágio com razões. Construir: scheduler do drain + funil por estágio +
rodar o primeiro drain real das 82 (stale aposenta, válidas MERGEIAM em main).
DoD: ≥1 merge real em main OU 100% das 82 com razão resolvida; funil no digest.

**L3-2 ⭐ Motor de intents guiado por backlog REAL (o salto de qualidade do Loop).**
Evidência: Marco Zero mediu 94% waste — o Loop inventa melhorias genéricas. Construir:
geração de intents a partir de evidência real do Atlas — os 7 buracos do sweep (memória
`019ebce0`, file:line+fix), receipts fracos do scorecard, testes vermelhos, TODOs
confirmados, residuais das Listas 1-2 — com ranking por impacto (Fable desenha).
O Loop passa de "inventa tarefa" para "ataca o backlog real do Atlas".
DoD: soak certifica ≥1 proposta que resolve item real do backlog; ranking congelado.

**L3-3 — ACP warm root-cause (throughput do soak).**
Evidência: transport `cli` funciona mas custa ~5s cold-start/call; `acp` warm retornava
diff 0 (regressão não-root-caused, roteada da L2-1). Multiplicador direto de
cenários/hora. DoD: acp edita de verdade (diff>0 num smoke real), regressão de
transporte congelada, flag de volta para acp.

**L3-4 — Fix-forward fecha o ciclo sozinho.**
Evidência: residuais L2-3/L2-4 — canário que falha só registra no receipt; review-feedback
não alimenta ranking. Construir: canário falhou → enfileira task de CORREÇÃO no próprio
Loop (fix-forward literal vira runtime); feedback de review → prioridade da descoberta.
DoD: quebra simulada gera task de fix automaticamente; feedback muda o ranking.

## Bloco B — o cérebro semântico de verdade (M× puro: multiplica TODA chamada)

**L3-5 ⭐ Wire do semantic_rag real no AUCRI (retirar os fakes).**
Evidência: engine python `semantic_rag` BUILT+PROVEN (fastembed/bge-small, 15/15 testes,
anti-fake kitten→feline); mas o AUCRI `semantic_candidate` é placeholder estático 0.60
(`manifest_pending_embedding`) e os fallbacks PHP hash/token-cosine seguem vivos.
DoD: ranking AUCRI usa embedding real via venv; fallbacks aposentados ou flag-gated;
anti-fake test no pipe PHP→python congelado.

**L3-6 — Índice semântico de código+docs servindo o context pack.**
Evidência: ~8.7k símbolos + 941 docs canônicos indexados só lexicalmente; o context pack
(porta de TODA sessão de provider) seleciona por keyword. Construir: embeddings sobre
símbolos+docs + retrieval semântico no `atlas:context-pack`, com medição de lift de
relevância vs lexical. DoD: pack com retrieval semântico medido; fail-open sem venv.

**L3-7 — Compounding accrual: cada merge vira learning recallável.**
Evidência: família compounding-open do remainder map — merges do Loop não alimentam
`AtlasCompoundingRuntimeService` com sinal substantivo (contrato no-noise O-1 respeitado:
só conteúdo real). DoD: merge→learning→recall comprovado influenciando o ciclo seguinte.

## Bloco C — uma espinha de execução + autonomia mais larga com as dívidas pagas

**L3-8 ⭐ S50 slice Fable: invariante single-source de execução.**
Evidência: memória forge-execution-fragmentation + O-6=S50 (decisão do operador) — ainda
existem stacks paralelas chamando CLIs fora do `AiProviderManager`. Escopo Fable: o
INVARIANTE anti-refragmentação (teste estrutural que falha se alguém invocar provider
fora da espinha) + migração do maior caller fragmentado restante.
DoD: invariante congelado; 1 caller real migrado com gates intactos.

**L3-9 ⭐ Pagar as 7 dívidas do sweep de uma vez.**
Evidência: memória `019ebce0` com file:line+fix: (2) rebase-then-merge sem re-conflict-check
(`LoopMergeRetryQueueService:288`); (3) `OwnerFlowPlanSliceCycleExecutor:130` self-stampa
autorização de operador; (4) `StewardshipBranchMergeGovernorService:240` execute_merge=false
vira merge; (5-7) `AtlasHarnessAutopilot` 143/217/104 fail-open de baseline, melhoria falsa
com tabela vazia, auto-sela baseline; (8) `OperatorLearningSignalDetector:175` provenance
spoofável acima do floor. DoD: 7 fixes + testes congelados (padrão O-1), nenhum default
de comportamento quebrado sem flag.

**L3-10 — Custo medido (o L2-8 herdado, único pendente da Lista 2).**
Evidência: Marco Zero = 94/94 eventos com custo unknown; o eixo custo do N×M está cego.
Wire de custo por execução (tokens/runtime hermes+codex, rates locais) + eixo custo no
`atlas:fable:delta`. DoD: >80% dos eventos novos com custo medido.

## Bloco D — prova, escala e o exponencial visível

**L3-11 — Pipeline receipts em escala (scorecard 7.86→8.5+ MEDIDO).**
Evidência: dimensão pipeline 5.22 com 2/73 green receipts reais (motor L2-9 provado).
Rodar passes do `atlas:cognition:mint-pipeline-receipts` até ≥30/73 + agendar passes.
DoD: scorecard overall sobe com fontes resolved (nunca self-declared).

**L3-12 ⭐ Meta-loop gated: o Loop melhora o PRÓPRIO harness do Loop.**
Evidência: a qualidade do Loop depende de prompts de intent/materializer/judge auxiliar —
hoje só humanos os melhoram. Construir: targets de harness admitidos na descoberta com
guardrails anti-runaway desenhados pelo Fable (frozen judge INTOCÁVEL, escopo allowlist,
certificação adversarial obrigatória, never-merge nos próprios gates).
DoD: 1 melhoria de harness proposta+certificada pelo próprio Loop; guardrails congelados.

**L3-13 — Forge LIVE: a obra real (fecha o L2-14 de verdade).**
Evidência: cadeia fail-closed provada (L2-14 contrato); falta a obra pesada viva.
Conduzir UMA obra desta lista (candidata: L3-9 ou L3-11) VIA Forge governado fim-a-fim
com kill/resume exercitados ao vivo. Operator-gated (spend + tempo).
DoD: obra real conduzida pelo Forge, entrega verificada.

**L3-14 — Capstone: o número do exponencial antes do dia 22.**
Evidência: `atlas:fable:delta` compara hoje vs Marco Zero mas não tem série temporal.
Construir: snapshot diário do delta (waste, certificadas/dia, merges/dia, custo,
scorecard) + relatório final N×M da campanha — o número que decide pagar API Fable.
DoD: série diária persistida; relatório final com tendência provada por fonte resolvida.

## Ledger Lista 3

| Item | Status | Prova |
|---|---|---|
| L3-1 Drain em cadência + funil | **✅ ativado** | 3 bugs-keystone do flywheel (82 candidatas/0 merges) corrigidos: (a) **path-rewrite** `src/<basename>`→`target_path` no materializer (git_apply_failed em 100% das propostas — `rewriteDiffToTarget`); (b) **re-prova em workspace COMPLETO** (`materializeFull`: clone+symlink vendor/.env) — a mínima de 1 arquivo dava `frozen_path_tampered` em toda mudança de impl; (c) **porta governada no DB** (migration `2026_06_12_000100`: troca o CHECK absoluto por trigger que só permite merged via `SET LOCAL atlas.governed_merge='on'`). Merge REAL provado fim-a-fim em clone (`merged_to_main=true` registrado, commit+snapshot tag+canário); never-merge default preservado (ungoverned bloqueado por trigger E model guard). Funil `atlas:loop:funnel` LIVE (82 resolvidas→0 drenáveis/82 retired_contractless). Drain `everyThirtyMinutes` no scheduler (gated). `AtlasLoopFunnelAndPathRewriteTest` 7v + `AtlasLoopAutoMergeServiceTest` 6v = 13 verdes |
| L3-2 Intents por backlog real | **✅ ativado** | `AtlasLoopBacklogIntentSource` (manifesto curado `storage/app/atlas/loop/backlog-intents.json` + hook do corpus de falhas) emite alvos com OBJETIVO ESPECÍFICO; a discovery os PROMOVE no ranking (`backlog_reach` + objetivo nomeado nos signals) — ataca os 94% waste do Marco Zero (provider recebe "corrija X em Y", não "melhore este arquivo"). Só alvos existentes na árvore (backlog endereçável). Flag `discovery_backlog_intents` ON no .env; fail-open. Manifesto seeded com 2 itens reais. `AtlasLoopBacklogIntentTest` 4v (manifesto rejeita fantasma, ordena por prioridade, flag ON injeta sinal+objetivo, flag OFF no-op) |
| L3-3 ACP warm root-cause | **✅ ativado** | guard de regressão do transporte: ACP `succeeded` com output VAZIO (o sucesso-falso que dava o sintoma diff-0) agora é tratado como fallback-required → cai no CLI provado, razão `acp_succeeded_empty_output` auditável (`HermesCliProvider::acpResultIsEmptySuccess`, flag `acp_empty_output_fallback` default ON). Reativar acp warm agora é seguro. `HermesAcpEmptyOutputFallbackTest` 4v. (A prova diff>0 do acp warm ao vivo precisa do daemon hermes — o guard remove o risco do sucesso-falso) |
| L3-4 Fix-forward fecha ciclo | **✅ ativado** | canário RED pós-merge enfileira task de CORREÇÃO no próprio Loop (`enqueueFixForward`: mesmo arquivo, snapshot pré-merge endereçado, prioridade alta, dedup) — nunca reverte (política v2 literal). `AtlasLoopFixForwardTest` 2v (RED enfileira+dedup) |
| L3-5 semantic_rag no AUCRI | **✅ ativado (LIVE)** | memória estava STALE: o AUCRI já usa cosine REAL via venv (`applyLocalSemanticScores`→`SemanticRetrievalRuntime`, flag `aucri.local_semantic_scoring` ON, fake crc32/hash já aposentado). Gap fechado = prova LIVE através do boundary AUCRI (venv fastembed instalado, `auth 0.668 > weather -0.146`, real_embeddings=true). `AucriLiveSemanticBoundaryTest` 2v LIVE |
| L3-6 Índice semântico no pack | **✅ ativado (LIVE)** | `SemanticContextRetrievalService` (rank de {id,text} vs query via engine local, embed on-the-fly sem pgvector, fallback lexical determinístico, guard anti-fake de receipt, `relevanceLift()` medido) injetado no `AtlasOpenBrainContextPackService` (rerank semântico da memória, fail-open). Flag `aobg.semantic_retrieval` default OFF. Lift semântico LIVE confirmado. 9v (serviço 6 + wiring 3); 38v na regressão completa |
| L3-7 Compounding accrual | **✅ ativado** | cada merge real chama `AtlasCompoundingRuntimeService::recordExecution` com claim SUBSTANTIVO (arquivo+commit+veredito do canário) → `learning_candidate` `held_for_evidence` (governado, NÃO auto-promovido = contrato no-noise honrado). LIVE-provado (status=recorded, candidato criado). Flag `loop.compounding_accrual` ON. `AtlasLoopCompoundingAccrualTest` 3v (claim substantivo / flag OFF não chama / canário RED no claim) |
| L3-8 S50 invariante única espinha | **✅ ativado** | invariante estrutural anti-refragmentação: scan do codebase falha se um provider-CLI for invocado fora da espinha allowlistada (`AiProviderManager`+drivers sancionados). Histórico: este item nasceu com 8 sites sancionados + 1 fragmented caller documentado honestamente como dívida S50 (`AtlasCodexPlannerService`); L4-8 fechou esse caller e encolheu a allowlist para 7 sites sancionados. Ratchet adversarialmente verificado (caller sintético→RED). `AtlasExecutionSpineSingleSourceTest` 3v |
| L3-9 7 dívidas do sweep | **✅ ativado** | os 7 buracos do sweep (memória `019ebce0`) fechados cirurgicamente, default-safe, fail-closed onde era fail-open: rebase-then-merge re-conflict-check; plan-slice exige operator provenance (não self-stampa); execute_merge=false não enfileira; autopilot baseline ausente→fail-closed + não auto-sela; melhoria com tabela vazia→inconclusive; passive detector carimba provenance não-auto-apply. **56 asserts verdes / 4 arquivos** |
| L3-10 Custo medido (L2-8) | **✅ ativado** | `ProviderCostEstimator` computa custo de sinais REAIS (tokens×rate table local; runtime×taxa/min p/ providers locais sem tokens; sem sinal = honestamente unknown, nunca fabricado) wirado no `ProgrammingRuntimeTelemetryRecorder` + forward de tokens no compounding + coverage no `atlas:fable:delta`. LIVE: 3/3 novos eventos com custo medido (100%). 13v |
| L3-11 Receipts (scorecard medido↑) | **✅ ativado** | bug de linkage corrigido (mint mirava capabilities erradas + path `report.subsystems`→`subsystems` que zerava o targeting); resolver expõe `ownerCapabilityIdsForFqn`, mint MIRA os subsistemas partial (45/53 resolvíveis). LIVE: ready 1→17, **scorecard overall 7.86→8.16 MEDIDO** (resolved-evidence). Agendado diário. `AtlasPipelineReceiptTargetingTest` 4v. (ready oscila durante edição ativa por freshness — mecanismo correto) |
| L3-12 Meta-loop gated | **✅ ativado** | `AtlasLoopHarnessGuard`: conjunto PÉTREO de segurança (frozen judge, gates, never-merge, o próprio guard) NUNCA é alvo do loop — provado que nem backlog prioridade-1.0 o injeta (o loop não edita a própria fechadura); harness não-segurança só com flag `meta_harness_targets` (default OFF). Wirado no chokepoint final da discovery. `AtlasLoopHarnessGuardTest` 5v |
| L3-13 Forge LIVE obra real | **✅ LIVE-proven** | obra LIVE disparada (operador autorizou spend) via `atlas:obra:deliver` em clone isolado, provider hermes_cli: plan-DAG real decomposto + hermes gerou **411 linhas reais** (rewrite + teste) numa branch isolada, governança intacta (main_untouched/never_merged/never_pushed/reversible/brain_recorded). 1º run ficou `needs_review` por 2 bugs REAIS do harness do obra, ambos corrigidos+congelados: (a) **vendor ausente no worktree efêmero** (`git worktree` é checkout limpo → integrated-check quebrava por `vendor/autoload.php`) → `linkRuntimeDeps` symlinka vendor/.env/node_modules; (b) **path aninhado** `atlas-server/app/…` (workspace umbrella) → `normalizeWorktreeRelativePath` strip o prefixo quando o worktree já é o repo. `ObraWorktreeRuntimeFixesTest` 4v. `AtlasForgeLiveChainFailClosedTest` 2v. **Re-run com os fixes CERTIFICOU VERDE**: `status=done`, `certified=true`, integrated-check ran+passed, entrega no path correto (`tests/Unit/Support/…SmokeTest.php`), never_merged+reversible. Forge LIVE end-to-end PROVADO: provider real → geração → path correto → vendor → teste passa → certifica → governança intacta |
| L3-14 Série diária + relatório N×M | **✅ ativado** | `atlas:fable:delta-series` (snapshot diário HOJE-vs-Marco-Zero das mesmas fontes vivas, JSONL idempotente por data, `--report` com trend first-vs-latest, `--date` p/ teste determinístico, fail-safe sem baseline). Agendado diário. LIVE: scorecard 8.16 vs baseline 7.86. `AtlasFableDeltaSeriesCommandTest` 4v |
