<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Escalation;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EscalationSignals;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

/**
 * Turns a deterministic escalation score into either a `forge` decision,
 * an `obra_candidate` decision, or no escalation at all.
 *
 * Thresholds (doc principal §20 + decision_locked invariants of
 * {@see EscalationDecision}):
 *   - target = forge           when score >= 7  OR risk_level >= R4
 *   - target = obra_candidate  when 4 <= score < 7
 *   - no decision (null)       when score < 4
 *
 * `forge` decisions always set `human_action_required = true` — Atlas Dev
 * NEVER auto-creates an Obra; the human accepts via the Attention queue.
 */
final class EscalationDecisionEngine
{
    public const THRESHOLD_FORGE = 7;
    public const THRESHOLD_OBRA = 4;

    public function __construct(
        private readonly EscalationSignalScorer $scorer,
    ) {}

    public function decide(
        EscalationSignalsInput $input,
        string $runId,
        string $taskContractHash,
        string $triggeredAtIso,
        ?string $previewArtifactPath = null,
    ): ?EscalationDecision {
        $scored = $this->scorer->score($input);
        $score = (int) $scored['score'];
        $contributions = (array) $scored['contributions'];

        $riskIndex = $input->riskIndex();
        $forceForge = $riskIndex >= 4;

        if ($score < self::THRESHOLD_OBRA && ! $forceForge) {
            return null;
        }

        $target = ($score >= self::THRESHOLD_FORGE || $forceForge)
            ? EscalationDecision::TARGET_FORGE
            : EscalationDecision::TARGET_OBRA_CANDIDATE;

        $reasons = $this->reasons($contributions, $input, $target, $forceForge);
        $humanActionRequired = $target === EscalationDecision::TARGET_FORGE;

        $signals = new EscalationSignals(
            fileCount: $input->fileCount,
            layersTouched: $input->layersTouched,
            riskKeywords: array_values($input->riskKeywords),
            contextRequiredChars: $input->contextRequiredChars,
            threadMessages: $input->threadMessages,
            priorFailureCount: $input->priorFailureCount,
        );

        return EscalationDecision::issue(
            runId: $runId,
            taskContractHash: $taskContractHash,
            triggeredAt: $triggeredAtIso,
            target: $target,
            reasons: $reasons,
            signals: $signals,
            score: $score,
            riskLevel: $input->riskLevel,
            humanActionRequired: $humanActionRequired,
            previewArtifactPath: $previewArtifactPath,
        );
    }

    /**
     * @param  array<string,int>  $contributions
     * @return list<string>
     */
    private function reasons(array $contributions, EscalationSignalsInput $input, string $target, bool $forceForge): array
    {
        $reasons = [];
        if ($forceForge) {
            $reasons[] = 'risk_level_'.strtolower($input->riskLevel).'_forces_forge';
        }
        foreach ($contributions as $key => $weight) {
            // `risk_keywords` was already aggregated as one entry; expand it
            // with the actual keywords so reviewers see the trigger words.
            if ($key === 'risk_keywords') {
                $reasons[] = 'risk_keywords:'.implode(',', $input->riskKeywords);

                continue;
            }
            $reasons[] = $key.'(+'.(int) $weight.')';
        }
        foreach ($input->loopEscalationSignalDelta as $signal) {
            $reasons[] = 'repair_loop_signal:'.$signal;
        }
        if ($reasons === []) {
            // Should never happen because the engine only emits a decision
            // when at least one contribution exists, but keep a safety
            // fallback so the DTO invariant (reasons non-empty) never fires.
            $reasons[] = $target === EscalationDecision::TARGET_FORGE
                ? 'forge_threshold_reached'
                : 'obra_candidate_threshold_reached';
        }

        return AtlasDevStringListNormalizer::uniqueSortedStrings($reasons);
    }
}
