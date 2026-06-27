<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * L3-12 · Guardrail do meta-loop (o Loop melhora o PRÓPRIO harness — com freio).
 *
 * Deixar o Loop tocar o próprio harness destrava melhoria composta (ele afia seus próprios
 * geradores de intent/materializer auxiliares), mas é a coisa MAIS perigosa que ele pode
 * fazer: se pudesse editar o juiz congelado, os gates ou a camada never-merge, fecharia o
 * próprio olho. Este guard é o invariante anti-runaway:
 *
 *   1. CONJUNTO PROIBIDO (pétreo) — arquivos de SEGURANÇA que NUNCA podem ser alvo do loop,
 *      independentemente de flag, backlog ou score: o frozen judge, os gates de promoção/
 *      merge, o materializer, o guard de saldo, a camada never-merge, e ESTE próprio guard.
 *      Mexer neles é como o loop editar a própria fechadura.
 *   2. ADMISSÃO DE HARNESS (flag-gated) — OUTROS arquivos do harness (não-segurança) só são
 *      admitidos como alvo quando `atlas.loop.meta_harness_targets` está ON. Default OFF.
 *
 * Pétreo por design: o conjunto proibido é uma constante; ampliá-lo é seguro, encolhê-lo
 * exige decisão deliberada (e o teste congelado falha se alguém o encolher).
 */
final class AtlasLoopHarnessGuard
{
    /**
     * Caminhos (substring match, repo-relativos) que NUNCA podem ser alvo do loop —
     * a segurança do próprio loop vive aqui. Substring para cobrir o arquivo inteiro.
     *
     * @var list<string>
     */
    public const FORBIDDEN_SELF_TARGETS = [
        'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalMaterializer.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopNetDirectionGuard.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
        // §5 ABSTAIN-AND-ASK — the frontier honesty cerca. The loop can NEVER edit its own abstain gate (it
        // could lower the confidence floor / drop the novelty trigger to fake-proceed on a greenfield decision
        // it should have asked about). Same principle as the grounding-veto and the priorizadores. Só-adiciona.
        'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php',
        // §0 MASTER ON/OFF SWITCH — the loop can NEVER edit its own master switch (it could never turn itself
        // back on, nor weaken the fail-closed gate). The flag is operator-only. Same principle as the judge:
        // the réu never edits the gate that decides whether it is allowed to run at all. Aperto só-adiciona.
        'app/Services/Ai/AutonomousEvolution/AtlasLoopMasterSwitch.php',
        // EXTERNAL BRAIN core — the brain seeds work into the serving queue but can NEVER edit its OWN gate:
        // its master switch, the 3 thin commands, the seed quality gate, and the cycle-progress verdict. Same
        // principle as the loop master switch: the réu never edits the lock that decides whether/what it runs.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainMasterSwitch.php',
        'app/Console/Commands/AtlasBrainNextCommand.php',
        'app/Console/Commands/AtlasBrainSeedCommand.php',
        'app/Console/Commands/AtlasBrainStateCommand.php',
        'app/Console/Commands/AtlasBrainHealthDoctorCommand.php',
        'app/Console/Commands/AtlasBrainAuditCommand.php',
        'app/Console/Commands/AtlasBrainSummaryCommand.php',
        'app/Console/Commands/AtlasBrainHistoryCommand.php',
        'app/Console/Commands/AtlasBrainSnapshotCommand.php',
        'app/Console/Commands/AtlasBrainTrendCommand.php',
        'app/Console/Commands/AtlasBrainPlanCommand.php',
        'app/Console/Commands/AtlasBrainProvenanceCommand.php',
        'app/Console/Commands/AtlasBrainScopesCommand.php',
        'app/Console/Commands/AtlasBrainFindingsCommand.php',
        'app/Console/Commands/AtlasBrainMetricsCommand.php',
        'app/Console/Commands/AtlasBrainPerceptionCommand.php',
        'app/Console/Commands/AtlasBrainCatalogCommand.php',
        'app/Console/Commands/AtlasBrainWorkerPromptCommand.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSeedQualityGate.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCycleProgressVerdict.php',
        // RECURSIVE-TOTAL FLOOR — meta_harness is ON for the autonomous scope, so the brain CAN target its own
        // engine. These organs decide whether/what/how-long the brain runs and what it SEES; the réu can never
        // weaken its own STOP (dry-probe), anti-proxy (classifier), dedup memory (done-set), scope reach
        // (scope-registry) nor its perception (comprehension builder/model). Pétreo, ignores every flag.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeDryProbe.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainEvolutionLevelClassifier.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainDoneSetLedger.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeRegistry.php',
        // The brain's MEMORY (Reflexion stream) and its CAUSAL promotion gate — the réu can never edit the
        // memory it recalls from nor the test that decides if a path's win is real (self-confirmation trap).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainReflectionStream.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCausalEffectGate.php',
        // The brain's STRUCTURAL PERCEPTION — the digest of multi-file leverage signals (orphans/clones/doc-
        // stated gaps) the next origination reads. Pétreo for the same reason as the comprehension model:
        // the réu never edits what it perceives (else it'd shape its own input to forge the "right" leap).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainStructuralSignalDigest.php',
        // The PORTFOLIO ROUTER — same priorizador principle as NextWorkDecider + LeverageSelector: the réu
        // never edits the organ that recommends which self-improvement path to take (else it'd always route
        // toward whichever path it wanted to "win" next, collapsing the portfolio rotation).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPortfolioRouter.php',
        // The ADVERSARIAL AUDITOR — the réu NEVER edits the battery that grades its own gate's coverage,
        // else it'd hand-prune attacks until "no holes" was structurally guaranteed (the classic
        // self-confirmation trap the cert-chain pétreo block was created to prevent).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainGateAdversarialAuditor.php',
        // The FRONTIER SOURCE REGISTRY — pure-read substrate for the frontier-harvest path. Editable input
        // ⇒ the réu could pre-seed itself with whichever frontier idea it wanted to "win", collapsing the
        // path's discriminating power. Same principle as the comprehension model + reflection stream.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainFrontierSourceRegistry.php',
        // The COMPOUNDING DIGEST — the summary the brain reads to see its own track record. Editable ⇒ the
        // réu could shape its OWN apparent success streak to fake a compounding lift. Pétreo for the same
        // reason as the cert chain (réu never edits the organ that decides whether its delivery counts).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCompoundingDigest.php',
        // The METRIC SNAPSHOT — the DECLARATION of which measurable facts the brain should be optimizing.
        // Editable ⇒ the réu would drop "recent_refusal_count" the moment it became inconvenient (the
        // Goodhart-on-the-meta-objective trap the V4 pétreo block was created to prevent).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainMetricSnapshot.php',
        // The SPEC SIMULATION TWIN — pre-flight predictor. Editable ⇒ the réu could return "passes_clean"
        // for whatever it wanted to win, collapsing the candidate-exploration's discriminating power.
        // Pétreo for the same priorizador principle as the cert chain.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSpecSimulationTwin.php',
        // The LEVERAGE BRIEF — the integration organ that consolidates all signals into ONE action hint.
        // Editable ⇒ the réu would always recommend whichever leap it wanted to "win" next, collapsing
        // the entire rotation. Same priorizador principle as the router + NextWorkDecider.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainLeverageBrief.php',
        // The ORPHAN SPEC DRAFTER — brain-as-author embryo. Editable ⇒ the réu would shape its own candidate
        // specs to whatever passes inspection regardless of merit (Goodhart on the candidate-shaping step).
        // Pétreo for the same reason as every author≠judge organ above it.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOrphanSpecDrafter.php',
        // The PATH CATALOG — single-source lookup over config('atlas.brain.paths'). Editable ⇒ the réu could
        // remap a path id to whatever executor it wanted (priorizador-mapping trap).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathCatalog.php',
        // The SPEC REPAIR HINTS — deficiency→repair map. Editable ⇒ the réu would map a deficiency to
        // "ignore it", weakening the inspector's discriminating power.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSpecRepairHints.php',
        // The BRIEF HISTOGRAM — pure read over prior_briefs emitting the action_hint distribution.
        // Editable ⇒ the réu would shape its own distribution to "look balanced", masking perseveration.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainBriefHistogram.php',
        // The SEED-GATE ADVERSARIAL AUDITOR — adversarial vetting of the seed gate's promote-set. Editable ⇒
        // the réu would drop attacks until "no holes" was structurally guaranteed (same self-confirmation trap
        // the inspector auditor's pétreo entry was created for, here applied to the seed boundary).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSeedGateAdversarialAuditor.php',
        // The CASCADE-RULE OUTCOME ANALYZER — joins reflection stream + done-set to score each action_hint.
        // Editable ⇒ the réu would relabel statuses or pad served counts to inflate its own win-rate.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCascadeRuleOutcomeAnalyzer.php',
        // The HINT TRANSITION MATRIX — markov-style read of adjacent (prev, next) action_hint pairs.
        // Editable ⇒ the réu would flatten transitions to look "balanced" while oscillating in practice.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintTransitionMatrix.php',
        // The HINT ENTROPY — Shannon-bits scalar over the action_hint distribution. Editable ⇒ the réu
        // would dampen the metric (e.g. log-base swap, clipping) to mask perseveration with a high number.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintEntropy.php',
        // The RESULT-KIND HISTOGRAM — distribution of cycle outcomes (blocked/exhausted/stagnated/note/...).
        // Editable ⇒ the réu would mislabel kinds to mask starvation.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainResultKindHistogram.php',
        // The TREND ANALYZER — split-window deltas. Editable ⇒ the réu would flip the delta sign to mask
        // a worsening trend, hiding the moment to rotate.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTrendAnalyzer.php',
        // The COHORT SCOPE COMPARATOR — ranks scopes by composite health. Editable ⇒ the réu would
        // reorder rows to keep its current scope on top regardless of evidence.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCohortScopeComparator.php',
        // The HEALTH SCORE — 0..100 composite. Editable ⇒ the réu would re-tune weights so its current
        // state always scores high (the lying-mirror trap).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHealthScore.php',
        // The EVIDENCE FRESHNESS — age of newest reflection. Editable ⇒ the réu would fake recency.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainEvidenceFreshness.php',
        // The HEALTH-SCORE LEDGER — append-only NDJSON time-series. Editable ⇒ the réu would rewrite
        // history to look better (Goodhart, lying mirror over its own past performance).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHealthScoreLedger.php',
        // The PLAN ADVISER — picks the top recommended finding via explicit priority table. Editable ⇒
        // the réu would re-rank to promote nudges it's already good at (Goodhart on its own scorecard).
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPlanAdviser.php',
        // The PROVENANCE LEDGER — append-only NDJSON of per-seed lineage. Editable ⇒ the réu would
        // rewrite history to credit itself for seeds it didn't actually originate.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainProvenanceLedger.php',
        // The STALE SCOPE DETECTOR — multi-scope freshness over threshold. Editable ⇒ the réu would
        // exclude its own silent scope from the stale list.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainStaleScopeDetector.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeCatalogSnapshot.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintToPathTranslator.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathStarvationDetector.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainNextPathSuggester.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainOriginationGapDetector.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainProvenanceAttributionAnalyzer.php',
        // The PERCEPTION BUNDLE — single façade calling every perception organ. Editable ⇒ réu could
        // re-order or omit signals to hide its own weaknesses from downstream consumers.
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPerceptionBundle.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainTopChurnHintDetector.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainScopeFlagAuditor.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPlanAdviserRedTeam.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathYieldMomentum.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainNextCycleProjector.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathDiversityScore.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathOscillationDetector.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainFrontierMethodCatalog.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCompoundingVelocity.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHintFrequencyDriftAlarm.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCrossScopePatternXref.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainCriticalConsensusGate.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainHypotheticalTailAppender.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathSignalAggregator.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPathPriorityRank.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSchemaContractRegistry.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainGateFalsePositiveEstimator.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainProjectionCalibrationScore.php',
        'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainReflectionProvenanceChain.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopScopeComprehensionModelBuilder.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopScopeComprehensionModel.php',
        'app/Models/AtlasLoopProposal.php',
        // A cadeia de CERTIFICAÇÃO fora de AutonomousEvolution/ (achado 12/06: o soak
        // certificou proposta editando o PRÓPRIO painel-juiz — réu editando o juiz).
        'app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php',
        'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
        // O TERMÔMETRO HONESTO + sua fonte de verdade (achado adversarial 14/06: a régua
        // ungameable e o resolvedor de callers que ela re-resolve fresco estavam só
        // flag-gated, não pétreos — um flip da flag meta e o réu poderia editar o próprio
        // termômetro). O juiz NUNCA pode ser editável a um flip de distância; pétreo aqui.
        'app/Services/Ai/AutonomousEvolution/AtlasLoopUtilityGradeService.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopWiredCallerService.php',
        // O DECISOR "o quê a seguir" (a prioridade ungameable). Mesmo princípio do termômetro:
        // o réu NUNCA pode editar o próprio priorizador — senão lavaria qualquer alvo para o topo
        // da fila. Pétreo: o decisor re-resolve leverage do git/grafo fresco e a SHAPE-band domina.
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNextWorkDecider.php',
        // A camada never-merge no banco (CHECK + trigger + a porta governada).
        'database/migrations/2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
        'database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php',
        // ACDE O1: o PRÓPRIO produtor de origination é pétreo — o originador nunca pode ser originado-contra
        // (senão o réu reescreveria quem decide o que propor). Aperto sancionado (só adiciona, nunca afrouxa).
        'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationProducer.php',
        // ARBOR-GRAFT: a camada de seleção/árvore que decide O QUE explorar é pétrea pelo mesmo princípio do
        // NextWorkDecider — o réu nunca pode editar o próprio priorizador/substrato de candidatos.
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopIdeaTreeAccessor.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopSelectAdjuster.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopHypothesisTreeProducer.php',
        // LOOP-OS · FASE 4 · cérebro EV (Slices 6/7/7.5): o vetor de eixo-sistema, o produtor de
        // touches_axes e o decisor de Expected-Value FORMAM o priorizador de "salto mais exponencial".
        // Mesmo princípio do NextWorkDecider: o réu nunca pode editar o próprio cérebro de decisão —
        // senão lavaria qualquer alvo trivial para o topo cegando a detecção do gargalo (o eixo binding).
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopSystemAxisService.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopTouchesAxesProducer.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopExpectedValueDecider.php',

        // ── LOOP-OS · FASE 1 · SLICE -1 (2026-06-18): CONGELAR A PORTA antes de construir a
        // Constituição. Hoje o loop JÁ auto-mergeia self-edits (flag ON) — a porta está ABERTA.
        // Estes descem para `property_gated` quando a ConstitutionGate existir (Slice 4); até lá
        // são pétreos. Ref: docs/loop-os-architecture.md §7 (zero-window) + §12 (Fase 1).
        //
        // (a) CLOSURE TRANSITIVA do cert-chain: o veredito é DELEGADO — proteger só o Certifier
        //     NÃO protege o juiz (achado R4). Closure reflection-derivada de 17 classes (§3.3);
        //     16 loop-local + AdversarialProofPanelService (já pétreo acima).
        'app/Services/Ai/AutonomousEvolution/Verify/AtlasEngineeringHonestyGate.php',
        'app/Services/Ai/AutonomousEvolution/Verify/AtlasDeadCodeAnalyzer.php',
        'app/Services/Ai/AutonomousEvolution/Verify/AtlasDeadCodeAnalyzerSupport.php',
        'app/Services/Ai/AutonomousEvolution/Verify/AtlasLoopSignalAnalyzer.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopBehavioralEquivalenceGate.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopChangedSymbolCoverageCensus.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopQualityGrader.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopDeliveryConfidenceModel.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopCompletenessGate.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopJudgeConsensusGate.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopHeldOutDeltaCertifier.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopMetricHarness.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopMutationAdequacyGateService.php',
        'app/Services/Ai/AutonomousEvolution/AtlasLoopCrossFileConsumerGateService.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopCompletenessCriteriaResolver.php',
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNodeInterfaceExtractor.php',

        // (b) Vocabulário de mutação (o moat de detecção). Vira property_gated no Slice 4.
        'app/Services/Ai/AutonomousEvolution/AtlasLoopMutationOperators.php',

        // LOOP-OS Fase 2 · Slice 9: o motor de PROJEÇÃO produz o CONTRATO de obrigações que a cadeia de
        // cert exige (content-fixpoint sobre tuplas tipadas). O réu nunca pode editá-lo — senão afrouxaria
        // o próprio contrato de qualidade. Pétreo pelo mesmo princípio do cert-chain.
        'app/Services/Ai/AutonomousEvolution/AtlasLoopProjectionEngine.php',

        // ── FASE ARQUITETO (2026-06-21): os ÓRGÃOS DE QUALIDADE/DECISÃO desta obra. Pelo MESMO princípio do
        // cert-chain + do priorizador (NextWorkDecider): o réu NUNCA pode editar o próprio crítico de design,
        // o gate de admissão, o contrato de prova por tipo, a ponte de veto, a crítica cross-model nem o
        // decisor de alavancagem — senão a "fase que garante a qualidade" voltaria a ser teatro a um self-edit
        // de distância. Aperto sancionado pelo engenheiro (só adiciona, nunca afrouxa).
        'app/Services/Ai/AutonomousEvolution/AtlasLoopGroundedProjectionRoles.php',     // o crítico que morde os chamadores reais
        'app/Services/Ai/AutonomousEvolution/AtlasLoopArchitectPhaseGate.php',          // o gate de admissão "projetar antes de moer"
        'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkTypeContract.php',            // a prova anti-Goodhart obrigatória por tipo
        'app/Services/Ai/AutonomousEvolution/AtlasLoopProjectionObligationContracts.php', // a ponte que torna o contrato um VETO real
        'app/Services/Ai/AutonomousEvolution/AtlasLoopModelProjectionCritic.php',       // a crítica cross-model (só aprofunda, nunca afrouxa)
        'app/Services/Ai/AutonomousEvolution/AtlasLoopLeverageSelector.php',            // o decisor "o MAIOR passo" (mesmo princípio do NextWorkDecider)

        // (c) A subárvore INTEIRA da Constituição — arquivos nascem pétreos por substring,
        //     então a fundação (actuator/battery/runner/gate/sentinel) é inviolável ao ser escrita.
        'app/Services/Ai/AutonomousEvolution/Constitution/',

        // (d) Back-door de config: o loop NÃO pode rebaixar os próprios safety-gates (SEV-2;
        //     vira property_gated no Slice 4) — e o gatilho EXTERNO de respawn fica fora do alcance.
        'config/atlas.php',
        'bin/atlas-loop-watchdog.sh',

        // LOOP-OS Fase 3 · Slice 4.5: a suíte-SENTINELA da Constituição (§3.6) É a descarga build-time que
        // admite (ou rejeita) um self-edit property_gated. Se o loop pudesse editá-la, enfraqueceria o próprio
        // portão de admissão e um blinder passaria. tests/ não estava em FORBIDDEN (furo SEV-1 que o doc deixou
        // aberto) — a subárvore de testes da Constituição vira pétrea atômica com a criação da sentinela.
        'tests/Feature/Loop/Constitution/',

        // ── FASE 0 · PISO DE AUTONOMIA RESPONSÁVEL (2026-06-24): antes de o Loop poder rodar com
        // autonomia plena (meta_harness ON sobre AutonomousEvolution/), a SUPERFÍCIE DE REWARD-HACK
        // precisa ser pétrea — senão o réu edita o próprio oráculo / atribuição / falsificação /
        // meta-objetivo e forja o próprio sucesso (o que matou o Sakana DGM e o CUDA Engineer). Mesmo
        // princípio do termômetro (UtilityGradeService) e do priorizador (NextWorkDecider): o réu NUNCA
        // edita o que o julga, credita ou autoriza. Aperto só-adiciona (ampliar é seguro; encolher falha
        // o teste-sentinela). Verificado: nenhuma task claimable/claimed toca estes caminhos hoje —
        // os órgãos já estão construídos (completed_dry_run); fechar o piso sobre eles não atropela worker.
        //
        // (i) O MÚSCULO ENDÓGENO (R7) inteiro — oráculo de outcome, gate de promoção, registry, corpus,
        //     eval, training. O réu treinar/promover o próprio músculo na própria métrica é o reward-hack
        //     de livro-texto; toda a árvore nasce pétrea por substring.
        'app/Services/Ai/AutonomousEvolution/SelfModel/',
        // (ii) A ATRIBUIÇÃO causal (R3) — o gradiente que credita as próprias shapes. Se editável, o réu
        //      infla o crédito do comportamento que quiser reforçar (Goodhart sobre a própria régua).
        'app/Services/Ai/AutonomousEvolution/Attribution/',
        // (iii) O GATE DE FALSIFICAÇÃO (R8.3) — bug só conta com RED reproduzível. Se editável, o réu
        //       rebaixa a própria barra de "bug real" e fabrica entregas a partir de falso-positivo.
        'app/Services/Ai/AutonomousEvolution/Defect/',
        // (iv) O META-OBJETIVO + AUTO-ARQUITETURA (V4) — quem PROPÕE e GATEIA a evolução da própria
        //      maquinaria + a sentinela de auto-arquitetura. O réu nunca edita o que decide o que ele
        //      mesmo deve perseguir, nem o gate frozen que dispõe sobre a própria meta-proposta.
        'app/Services/Ai/AutonomousEvolution/V4/',
        // (v) O VETO DE GROUNDING — refuta citação-fantasma na originação (material só nasce ancorado no
        //     inventário real). Cerca de honestidade; pétreo pelo mesmo princípio do abstain-and-ask.
        'app/Services/Ai/AutonomousEvolution/Verify/AtlasLoopComprehensionGroundingGate.php',
        // (vi) O DECISOR DE AMBIÇÃO — o LeverageSelector já era pétreo, mas o AmbitionDecider que escolhe
        //      o salto não estava. Mesmo princípio do priorizador: o réu não edita o que decide a própria
        //      ambição (senão lavaria um alvo trivial/proxy para "o maior salto").
        'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopAmbitionDecider.php',
        // (vii) A GOVERNANÇA DE AGENTES inteira — reconciler / desired-state / fleet-driver que ORIGINAM e
        //       AUTORIZAM campanha. Achado: o réu poderia forçar o hard-gate de execução para sempre-true e
        //       originar campanha mesmo com o master switch OFF, SEM jamais tocar o arquivo pétreo do switch.
        //       Autorizar a própria execução é a fechadura externa; pétrea pelo mesmo princípio do master switch.
        'app/Services/Ai/AgentGovernance/',
        // (viii) FASE 1 (24/06): a CADEIA DE MEDIÇÃO do Δ de comportamento — snapshotter + computer +
        //       o recorder pai-vs-merge — é o TERMÔMETRO que alimenta o grade pétreo. Mesmo princípio do
        //       UtilityGradeService + WiredCallerService: o réu nunca edita o medidor (senão forjaria o
        //       próprio net_behavior_delta). O grade re-computa o Δ fresco do commit via este recorder.
        'app/Services/Ai/AutonomousEvolution/BehaviorDelta/',
    ];

    /** Prefixos que identificam um arquivo do harness do loop (candidato a meta-target). */
    private const HARNESS_PREFIXES = [
        'app/Services/Ai/AutonomousEvolution/',
    ];

    /**
     * Um arquivo de segurança que NUNCA pode ser alvo do loop — pétreo, ignora flags.
     */
    public function isForbiddenSelfTarget(string $repoRelPath): bool
    {
        $path = ltrim($repoRelPath, '/');
        foreach (self::FORBIDDEN_SELF_TARGETS as $forbidden) {
            if (str_contains($path, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Um arquivo do harness do loop (não-segurança) — só admissível como alvo quando a
     * flag meta-harness está ON. Arquivos proibidos NUNCA são harness-admissíveis.
     */
    public function isHarnessTarget(string $repoRelPath): bool
    {
        if ($this->isForbiddenSelfTarget($repoRelPath)) {
            return false;
        }
        $path = ltrim($repoRelPath, '/');
        foreach (self::HARNESS_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decisão de admissão de um alvo, dado o estado das flags. Retorna:
     *   - 'forbidden'        => pétreo, jamais (segurança do loop);
     *   - 'harness_gated'    => é harness e a flag meta está OFF => rejeitar;
     *   - 'admissible'       => pode prosseguir (não-harness, ou harness com flag ON).
     */
    public function admit(string $repoRelPath, bool $metaHarnessEnabled): string
    {
        if ($this->isForbiddenSelfTarget($repoRelPath)) {
            return 'forbidden';
        }
        if ($this->isHarnessTarget($repoRelPath) && ! $metaHarnessEnabled) {
            return 'harness_gated';
        }

        return 'admissible';
    }
}
