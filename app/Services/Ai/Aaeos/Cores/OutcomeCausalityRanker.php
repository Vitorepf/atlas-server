<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

use App\Services\Ai\Support\AiValueNormalizer;

final class OutcomeCausalityRanker
{
    public const SCHEMA_VERSION = 'atlas.aaeos.outcome_causality_ranking.v1';

    public const CAUSE_MISSING_EVIDENCE = 'missing_evidence';

    public const CAUSE_TESTS_FAILED = 'tests_failed';

    public const CAUSE_EXECUTION_FAILED_OR_BLOCKED = 'execution_failed_or_blocked';

    public const CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES = 'context_missing_required_sources';

    public const CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED = 'execution_strategy_likely_succeeded';

    public const WEIGHT_MISSING_EVIDENCE = 0.95;

    public const WEIGHT_TESTS_FAILED = 0.85;

    public const WEIGHT_EXECUTION_FAILED_OR_BLOCKED = 0.70;

    public const WEIGHT_CONTEXT_MISSING_REQUIRED_SOURCES = 0.65;

    public const WEIGHT_EXECUTION_STRATEGY_LIKELY_SUCCEEDED = 0.55;

    public const STATUS_SUCCEEDED = 'succeeded';

    public const CAUSE_SCOPE_OR_CONTRACT_MISMATCH = 'scope_or_contract_mismatch';

    public const CAUSE_PACKET_QUALITY_FAILURE = 'packet_quality_failure';

    public const WEIGHT_SCOPE_OR_CONTRACT_MISMATCH = 0.80;

    public const WEIGHT_PACKET_QUALITY_FAILURE = 0.72;

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_GIVE_BACK = 'give_back';

    public const OUTCOME_POISON = 'poison';

    public const OUTCOME_QUARANTINE = 'quarantine';

    /** @var list<string> */
    public const PRIMARY_CAUSES = [
        self::CAUSE_MISSING_EVIDENCE,
        self::CAUSE_TESTS_FAILED,
        self::CAUSE_EXECUTION_FAILED_OR_BLOCKED,
        self::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES,
        self::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
        self::CAUSE_SCOPE_OR_CONTRACT_MISMATCH,
        self::CAUSE_PACKET_QUALITY_FAILURE,
    ];

    /** @var list<string> */
    public const OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_GIVE_BACK,
        self::OUTCOME_POISON,
        self::OUTCOME_QUARANTINE,
    ];

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

        return $this->finalizeRanking($candidates, $hasEvidenceRefs);
    }

    /**
     * Adapter that ranks causality from a single worker outcome envelope (success,
     * give_back, poison/quarantine, blocked, missing evidence, failed tests, missing
     * context) so the Learning Layer never infers causality from free text.
     *
     * @param  array{
     *     outcome:string,
     *     has_evidence_refs?:bool,
     *     tests_passed?:?bool,
     *     missing_required_sources?:bool,
     *     allowed_files_sufficient?:bool,
     *     packet_quality_failed?:bool,
     * }  $envelope
     * @return array{
     *     schema_version:string,
     *     candidates:list<array{cause:string,weight:float}>,
     *     primary_cause:string,
     *     alternative_explanations:list<array{cause:string,weight:float}>,
     *     attribution_confidence:float,
     *     attribution_blocked:bool
     * }
     */
    public function rankOutcomeEnvelope(array $envelope): array
    {
        $outcome = AiValueNormalizer::lowerTrimmedString($envelope['outcome'] ?? '');
        $hasEvidenceRefs = (AiValueNormalizer::boolOrNull($envelope['has_evidence_refs'] ?? null) ?? false);
        $testsPassed = array_key_exists('tests_passed', $envelope) ? $envelope['tests_passed'] : null;
        $missingRequiredSources = (AiValueNormalizer::boolOrNull($envelope['missing_required_sources'] ?? null) ?? false);
        $allowedFilesSufficient = (bool) ($envelope['allowed_files_sufficient'] ?? true);
        $packetQualityFailed = (AiValueNormalizer::boolOrNull($envelope['packet_quality_failed'] ?? null) ?? false);

        $candidates = $this->buildCandidates(
            $hasEvidenceRefs,
            $outcome === self::OUTCOME_SUCCESS ? self::STATUS_SUCCEEDED : $outcome,
            $missingRequiredSources,
            $testsPassed,
        );

        $order = count($candidates);

        if ($outcome === self::OUTCOME_GIVE_BACK && ! $allowedFilesSufficient) {
            $candidates[] = [
                'cause' => self::CAUSE_SCOPE_OR_CONTRACT_MISMATCH,
                'weight' => self::WEIGHT_SCOPE_OR_CONTRACT_MISMATCH,
                'order' => $order++,
            ];
        }

        if (in_array($outcome, [self::OUTCOME_POISON, self::OUTCOME_QUARANTINE], true) && $packetQualityFailed) {
            $candidates[] = [
                'cause' => self::CAUSE_PACKET_QUALITY_FAILURE,
                'weight' => self::WEIGHT_PACKET_QUALITY_FAILURE,
                'order' => $order++,
            ];
        }

        return $this->finalizeRanking($candidates, $hasEvidenceRefs);
    }

    /**
     * @param  list<array{cause:string,weight:float,order:int}>  $candidates
     * @return array{
     *     schema_version:string,
     *     candidates:list<array{cause:string,weight:float}>,
     *     primary_cause:string,
     *     alternative_explanations:list<array{cause:string,weight:float}>,
     *     attribution_confidence:float,
     *     attribution_blocked:bool
     * }
     */
    private function finalizeRanking(array $candidates, bool $hasEvidenceRefs): array
    {
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
            self::CAUSE_SCOPE_OR_CONTRACT_MISMATCH => 0.84,
            self::CAUSE_EXECUTION_FAILED_OR_BLOCKED, self::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES => 0.78,
            self::CAUSE_PACKET_QUALITY_FAILURE => 0.76,
            self::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED => 0.62,
            default => 0.45,
        };
    }
}
