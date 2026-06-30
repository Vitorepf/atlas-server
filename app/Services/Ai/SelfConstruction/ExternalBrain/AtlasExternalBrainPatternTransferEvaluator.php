<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Evaluates whether decision patterns distilled from frontier or successful scaffolded runs
 * are safe and useful to transfer across task classes before injection into smaller-model runbooks.
 *
 * DECISION PRIORITY (first match wins, per-pattern):
 *   retire               — any target-class outcome exceeds a harm threshold
 *                          (give_back_rate > give_back_threshold
 *                           OR duplicate_rate > duplicate_rate_threshold
 *                           OR weak_acceptance_rate > weak_acceptance_threshold)
 *   needs_more_evidence  — max evidence_count across all outcomes < min_evidence_count
 *   transferable         — all target-class positive_ratios >= positive_outcomes_threshold
 *   local_only           — fallback (works in source class but not across all targets)
 *
 * DEFAULT THRESHOLDS:
 *   give_back_threshold          = 0.30
 *   duplicate_rate_threshold     = 0.20
 *   weak_acceptance_threshold    = 0.40
 *   positive_outcomes_threshold  = 0.70
 *   min_evidence_count           = 3
 *
 * INJECTION RULES (per decision):
 *   transferable         → inject into all target_task_classes runbooks
 *   local_only           → inject only into source_task_classes runbooks
 *   needs_more_evidence  → withhold until evidence_count >= min_evidence_count
 *   retire               → do not inject; remove from pattern registry
 *
 * OUTPUT:
 *   { schema, results: list<{ pattern_id, transfer_decision, source_task_classes,
 *     target_task_classes, evidence_counts, injection_rule }> }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainPatternTransferEvaluator
{
    public const SCHEMA = 'atlas.external_brain.pattern_transfer_evaluator.v1';

    public const DECISION_TRANSFERABLE        = 'transferable';
    public const DECISION_LOCAL_ONLY          = 'local_only';
    public const DECISION_NEEDS_MORE_EVIDENCE = 'needs_more_evidence';
    public const DECISION_RETIRE              = 'retire';

    private const DEFAULT_GIVE_BACK_THRESHOLD         = 0.30;
    private const DEFAULT_DUPLICATE_RATE_THRESHOLD    = 0.20;
    private const DEFAULT_WEAK_ACCEPTANCE_THRESHOLD   = 0.40;
    private const DEFAULT_POSITIVE_OUTCOMES_THRESHOLD = 0.70;
    private const DEFAULT_MIN_EVIDENCE_COUNT          = 3;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $patterns    = is_array($input['patterns'] ?? null) ? $input['patterns'] : [];
        $thresholds  = is_array($input['thresholds'] ?? null) ? $input['thresholds'] : [];

        $giveBackThreshold        = (float) ($thresholds['give_back_threshold'] ?? self::DEFAULT_GIVE_BACK_THRESHOLD);
        $duplicateThreshold       = (float) ($thresholds['duplicate_rate_threshold'] ?? self::DEFAULT_DUPLICATE_RATE_THRESHOLD);
        $weakAcceptanceThreshold  = (float) ($thresholds['weak_acceptance_threshold'] ?? self::DEFAULT_WEAK_ACCEPTANCE_THRESHOLD);
        $positiveThreshold        = (float) ($thresholds['positive_outcomes_threshold'] ?? self::DEFAULT_POSITIVE_OUTCOMES_THRESHOLD);
        $minEvidenceCount         = (int) ($thresholds['min_evidence_count'] ?? self::DEFAULT_MIN_EVIDENCE_COUNT);

        $results = [];

        foreach ($patterns as $pattern) {
            if (! is_array($pattern) || ! isset($pattern['pattern_id'])) {
                continue;
            }

            $patternId        = (string) $pattern['pattern_id'];
            $sourceClasses    = is_array($pattern['source_task_classes'] ?? null) ? $pattern['source_task_classes'] : [];
            $targetClasses    = is_array($pattern['target_task_classes'] ?? null) ? $pattern['target_task_classes'] : [];
            $crossOutcomes    = is_array($pattern['cross_class_outcomes'] ?? null) ? $pattern['cross_class_outcomes'] : [];

            // Collect evidence counts per task class.
            $evidenceCounts   = [];
            foreach ($crossOutcomes as $outcome) {
                if (is_array($outcome) && isset($outcome['task_class'])) {
                    $evidenceCounts[(string) $outcome['task_class']] = (int) ($outcome['evidence_count'] ?? 0);
                }
            }

            // Decision logic.
            $decision = $this->decide(
                $crossOutcomes,
                $giveBackThreshold,
                $duplicateThreshold,
                $weakAcceptanceThreshold,
                $positiveThreshold,
                $minEvidenceCount,
            );

            $results[] = [
                'pattern_id'        => $patternId,
                'transfer_decision' => $decision,
                'source_task_classes' => $sourceClasses,
                'target_task_classes' => $targetClasses,
                'evidence_counts'   => $evidenceCounts,
                'injection_rule'    => $this->injectionRule($decision, $minEvidenceCount),
            ];
        }

        return [
            'schema'  => self::SCHEMA,
            'results' => $results,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $outcomes
     */
    private function decide(
        array $outcomes,
        float $giveBackThreshold,
        float $duplicateThreshold,
        float $weakAcceptanceThreshold,
        float $positiveThreshold,
        int   $minEvidenceCount,
    ): string {
        // 1. Retire: any outcome breaches a harm threshold.
        foreach ($outcomes as $outcome) {
            if (! is_array($outcome)) {
                continue;
            }
            $giveBackRate       = (float) ($outcome['give_back_rate'] ?? 0.0);
            $duplicateRate      = (float) ($outcome['duplicate_rate'] ?? 0.0);
            $weakAcceptanceRate = (float) ($outcome['weak_acceptance_rate'] ?? 0.0);

            if ($giveBackRate > $giveBackThreshold
                || $duplicateRate > $duplicateThreshold
                || $weakAcceptanceRate > $weakAcceptanceThreshold
            ) {
                return self::DECISION_RETIRE;
            }
        }

        // 2. Needs more evidence: no outcome has enough evidence.
        $maxEvidence = 0;
        foreach ($outcomes as $outcome) {
            if (is_array($outcome)) {
                $maxEvidence = max($maxEvidence, (int) ($outcome['evidence_count'] ?? 0));
            }
        }
        if ($maxEvidence < $minEvidenceCount) {
            return self::DECISION_NEEDS_MORE_EVIDENCE;
        }

        // 3. Transferable: all outcomes with sufficient evidence meet the positive threshold.
        if ($outcomes !== []) {
            $allPositive = true;
            foreach ($outcomes as $outcome) {
                if (! is_array($outcome)) {
                    continue;
                }
                $evidenceCount  = (int) ($outcome['evidence_count'] ?? 0);
                $positiveRatio  = (float) ($outcome['positive_ratio'] ?? 0.0);

                if ($evidenceCount >= $minEvidenceCount && $positiveRatio < $positiveThreshold) {
                    $allPositive = false;
                    break;
                }
            }

            if ($allPositive) {
                return self::DECISION_TRANSFERABLE;
            }
        }

        // 4. Local only: fallback.
        return self::DECISION_LOCAL_ONLY;
    }

    private function injectionRule(string $decision, int $minEvidenceCount): string
    {
        return match ($decision) {
            self::DECISION_TRANSFERABLE        => 'inject into all target_task_classes runbooks',
            self::DECISION_LOCAL_ONLY          => 'inject only into source_task_classes runbooks',
            self::DECISION_NEEDS_MORE_EVIDENCE => "withhold until evidence_count >= {$minEvidenceCount}",
            self::DECISION_RETIRE              => 'do not inject; remove from pattern registry',
            default                            => 'unknown',
        };
    }
}
