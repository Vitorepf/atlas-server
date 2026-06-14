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
        // A camada never-merge no banco (CHECK + trigger + a porta governada).
        'database/migrations/2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
        'database/migrations/2026_06_12_000100_governed_merge_door_atlas_loop_proposals.php',
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
