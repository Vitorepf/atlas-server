<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Pure, injectable judge-effort escalator. Given a verdict (`{decision, confidence, opposing_confidence,
 * proposal_id, ...}`) and a callable judge adapter, decides whether to request EXACTLY ONE higher-effort
 * re-judge for a low-confidence verdict and returns the resulting verdict with fact-only escalation
 * metadata appended.
 *
 * INVARIANTS:
 *   - When disabled (`enabled = false`) the original verdict is returned UNCHANGED.
 *   - When the verdict's confidence margin is at or above the calibration threshold, the original
 *     verdict is returned UNCHANGED.
 *   - For a low-margin verdict, the adapter is invoked EXACTLY ONCE with a higher effort level and the
 *     retried verdict is returned with a `_escalation` FACT block ({escalated:true, from_effort,
 *     to_effort, original_confidence, original_margin}).
 *   - Retries are capped at ONE per proposal_id within a single escalator instance — a second call for
 *     the same proposal_id returns the previous result unchanged with `_escalation.reason=already_retried`.
 *   - NEVER widens acceptance/scope — the adapter's verdict is the authoritative source; the escalator
 *     only labels and counts.
 *   - NEVER reads config/atlas.php (the threshold comes from AtlasLoopJudgeSelfCalibrationService).
 */
final class AtlasLoopJudgeEffortEscalator
{
    public const EFFORT_DEFAULT = 'normal';

    public const EFFORT_ESCALATED = 'high';

    /** @var array<string,bool> */
    private array $retried = [];

    public function __construct(
        private readonly AtlasLoopJudgeSelfCalibrationService $calibration,
        private readonly bool $enabled = true,
    ) {}

    /**
     * @param  array<string,mixed>  $verdict
     * @param  callable(array<string,mixed>, string):array<string,mixed>  $judgeAdapter
     *         Invoked with ($verdict, $higherEffort) on a single retry attempt; expected to return the
     *         retried verdict (same shape).
     * @return array<string,mixed>
     */
    public function maybeEscalate(array $verdict, callable $judgeAdapter): array
    {
        if (! $this->enabled) {
            return $verdict;
        }

        $proposalId = (string) ($verdict['proposal_id'] ?? '');
        if ($proposalId !== '' && isset($this->retried[$proposalId])) {
            // CAP: one retry per proposal id — never re-escalate.
            $verdict['_escalation'] = [
                'escalated' => false,
                'reason' => 'already_retried',
                'proposal_id' => $proposalId,
            ];

            return $verdict;
        }

        $confidence = (float) ($verdict['confidence'] ?? 0.0);
        $opposing = (float) ($verdict['opposing_confidence'] ?? 0.0);
        $margin = $confidence - $opposing;
        $threshold = $this->calibration->threshold();

        if ($margin >= $threshold) {
            return $verdict;
        }

        $fromEffort = (string) ($verdict['effort'] ?? self::EFFORT_DEFAULT);
        $retried = $judgeAdapter($verdict, self::EFFORT_ESCALATED);
        if (! is_array($retried)) {
            $retried = $verdict;
        }
        // FACT-only escalation metadata. The retried verdict's decision is the SOLE source of truth —
        // we never override it; we only label it with the escalation provenance.
        $retried['_escalation'] = [
            'escalated' => true,
            'from_effort' => $fromEffort,
            'to_effort' => self::EFFORT_ESCALATED,
            'original_confidence' => $confidence,
            'original_margin' => $margin,
            'threshold' => $threshold,
        ];

        if ($proposalId !== '') {
            $this->retried[$proposalId] = true;
        }

        return $retried;
    }
}
