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
