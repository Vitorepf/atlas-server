<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

final class OutcomeCausalityRanker
{
    private const SCHEMA_VERSION = 'atlas.aaeos.outcome_causality_ranking.v1';

    private const CAUSE_MISSING_EVIDENCE = 'missing_evidence';

    private const CAUSE_TESTS_FAILED = 'tests_failed';

    private const CAUSE_EXECUTION_FAILED_OR_BLOCKED = 'execution_failed_or_blocked';

    private const CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES = 'context_missing_required_sources';

    private const CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED = 'execution_strategy_likely_succeeded';

    private const WEIGHT_MISSING_EVIDENCE = 0.95;

    private const WEIGHT_TESTS_FAILED = 0.85;

    private const WEIGHT_EXECUTION_FAILED_OR_BLOCKED = 0.70;

    private const WEIGHT_CONTEXT_MISSING_REQUIRED_SOURCES = 0.65;

    private const WEIGHT_EXECUTION_STRATEGY_LIKELY_SUCCEEDED = 0.55;

    private const STATUS_SUCCEEDED = 'succeeded';

    /**
     * Rank causal explanations for an execution outcome and derive attribution.
     *
     * Candidate build order (each added only when its scalar guard holds):
     *  - missing_evidence(0.95)                  when !$hasEvidenceRefs
     *  - tests_failed(0.85)                      when $testsPassed === false
     *  - execution_failed_or_blocked(0.70)       when $status !== 'succeeded'
     *  - context_missing_required_sources(0.65)  when $missingRequiredSources
     *  - execution_strategy_likely_succeeded(0.55) fallback when none of the above
     *
     * Candidates are then sorted by weight DESC (build order breaks weight ties).
     *
     * @param  string  $status  one of succeeded/failed/blocked
     * @return array{
     *     schema_version:string,
     *     candidates:list<array{cause:string,weight:float}>,
     *     primary_cause:string,
     *     alternative_explanations:list<array{cause:string,weight:float}>,
     *     attribution_confidence:float,
     *     attribution_blocked:bool
     * }
     */
    public function rank(
        bool $hasEvidenceRefs,
        string $status,
        bool $missingRequiredSources,
        ?bool $testsPassed,
    ): array {
        $candidates = $this->buildCandidates(
            $hasEvidenceRefs,
            $status,
            $missingRequiredSources,
            $testsPassed,
        );

        usort($candidates, function (array $a, array $b): int {
            if ($a['weight'] === $b['weight']) {
                return $a['order'] <=> $b['order'];
            }

            return $b['weight'] <=> $a['weight'];
        });

        $ranked = [];

        foreach ($candidates as $candidate) {
            $ranked[] = [
                'cause' => $candidate['cause'],
                'weight' => $candidate['weight'],
            ];
        }

        $primaryCause = $ranked[0]['cause'];
        $alternativeExplanations = array_values(array_slice($ranked, 1));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidates' => $ranked,
            'primary_cause' => $primaryCause,
            'alternative_explanations' => $alternativeExplanations,
            'attribution_confidence' => $this->attributionConfidence($primaryCause),
            'attribution_blocked' => ! $hasEvidenceRefs,
        ];
    }

    /**
     * @return list<array{cause:string,weight:float,order:int}>
     */
    private function buildCandidates(
        bool $hasEvidenceRefs,
        string $status,
        bool $missingRequiredSources,
        ?bool $testsPassed,
    ): array {
        $candidates = [];
        $order = 0;

        if (! $hasEvidenceRefs) {
            $candidates[] = [
                'cause' => self::CAUSE_MISSING_EVIDENCE,
                'weight' => self::WEIGHT_MISSING_EVIDENCE,
                'order' => $order++,
            ];
        }

        if ($testsPassed === false) {
            $candidates[] = [
                'cause' => self::CAUSE_TESTS_FAILED,
                'weight' => self::WEIGHT_TESTS_FAILED,
                'order' => $order++,
            ];
        }

        if ($status !== self::STATUS_SUCCEEDED) {
            $candidates[] = [
                'cause' => self::CAUSE_EXECUTION_FAILED_OR_BLOCKED,
                'weight' => self::WEIGHT_EXECUTION_FAILED_OR_BLOCKED,
                'order' => $order++,
            ];
        }

        if ($missingRequiredSources) {
            $candidates[] = [
                'cause' => self::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES,
                'weight' => self::WEIGHT_CONTEXT_MISSING_REQUIRED_SOURCES,
                'order' => $order++,
            ];
        }

        if ($candidates === []) {
            $candidates[] = [
                'cause' => self::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
                'weight' => self::WEIGHT_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
                'order' => $order,
            ];
        }

        return $candidates;
    }

    private function attributionConfidence(string $primaryCause): float
    {
        return match ($primaryCause) {
            self::CAUSE_MISSING_EVIDENCE, self::CAUSE_TESTS_FAILED => 0.90,
            self::CAUSE_EXECUTION_FAILED_OR_BLOCKED, self::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES => 0.78,
            self::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED => 0.62,
            default => 0.45,
        };
    }
}
