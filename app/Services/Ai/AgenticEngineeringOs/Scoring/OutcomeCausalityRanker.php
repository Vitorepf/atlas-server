<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Scoring;

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
    public const FIELD_WEIGHT = 'weight';
    public const FIELD_CAUSE = 'cause';
    public const FIELD_ORDER = 'order';
    public const FIELD_STATUS = 'status';
    public const FIELD_OUTCOME = 'outcome';
    public const FIELD_CAUSES = 'causes';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_ALLOWED_FILES_SUFFICIENT = 'allowed_files_sufficient';
    public const FIELD_ALTERNATIVE_EXPLANATIONS = 'alternative_explanations';
    public const FIELD_ATTRIBUTION_BLOCKED = 'attribution_blocked';
    public const FIELD_ATTRIBUTION_CONFIDENCE = 'attribution_confidence';
    public const FIELD_CANDIDATES = 'candidates';
    public const FIELD_HAS_EVIDENCE_REFS = 'has_evidence_refs';
    public const FIELD_PRIMARY_CAUSE = 'primary_cause';
    public const FIELD_TESTS_PASSED = 'tests_passed';
    public const FIELD_PACKET_QUALITY_FAILED = 'packet_quality_failed';
    public const FIELD_MISSING_REQUIRED_SOURCES = 'missing_required_sources';
    public const FIELD_SUCCEEDED = 'succeeded';
    public const FLOAT_0_84 = 0.84;
    public const FLOAT_0_90 = 0.90;
    public const FLOAT_0_76 = 0.76;
    public const FLOAT_0_78 = 0.78;
    public const FLOAT_0_45 = 0.45;
    public const FLOAT_0_62 = 0.62;

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
     *  - execution_failed_or_blocked(0.70)       when $status !== self::FIELD_SUCCEEDED
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
        $outcome = AiValueNormalizer::lowerTrimmedString($envelope[self::FIELD_OUTCOME] ?? '');
        $hasEvidenceRefs = (AiValueNormalizer::boolOrNull($envelope[self::FIELD_HAS_EVIDENCE_REFS] ?? null) ?? false);
        $testsPassed = array_key_exists(self::FIELD_TESTS_PASSED, $envelope) ? $envelope[self::FIELD_TESTS_PASSED] : null;
        $missingRequiredSources = (AiValueNormalizer::boolOrNull($envelope[self::FIELD_MISSING_REQUIRED_SOURCES] ?? null) ?? false);
        $allowedFilesSufficient = (AiValueNormalizer::boolOrNull($envelope[self::FIELD_ALLOWED_FILES_SUFFICIENT] ?? null) ?? true);
        $packetQualityFailed = (AiValueNormalizer::boolOrNull($envelope[self::FIELD_PACKET_QUALITY_FAILED] ?? null) ?? false);

        $candidates = $this->buildCandidates(
            $hasEvidenceRefs,
            $outcome === self::OUTCOME_SUCCESS ? self::STATUS_SUCCEEDED : $outcome,
            $missingRequiredSources,
            $testsPassed,
        );

        $order = count($candidates);

        if ($outcome === self::OUTCOME_GIVE_BACK && ! $allowedFilesSufficient) {
            $candidates[] = [
                self::FIELD_CAUSE => self::CAUSE_SCOPE_OR_CONTRACT_MISMATCH,
                self::FIELD_WEIGHT => self::WEIGHT_SCOPE_OR_CONTRACT_MISMATCH,
                self::FIELD_ORDER => $order++,
            ];
        }

        if (in_array($outcome, [self::OUTCOME_POISON, self::OUTCOME_QUARANTINE], true) && $packetQualityFailed) {
            $candidates[] = [
                self::FIELD_CAUSE => self::CAUSE_PACKET_QUALITY_FAILURE,
                self::FIELD_WEIGHT => self::WEIGHT_PACKET_QUALITY_FAILURE,
                self::FIELD_ORDER => $order++,
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
            if ($a[self::FIELD_WEIGHT] === $b[self::FIELD_WEIGHT]) {
                return $a[self::FIELD_ORDER] <=> $b[self::FIELD_ORDER];
            }

            return $b[self::FIELD_WEIGHT] <=> $a[self::FIELD_WEIGHT];
        });

        $ranked = [];

        foreach ($candidates as $candidate) {
            $ranked[] = [
                self::FIELD_CAUSE => $candidate[self::FIELD_CAUSE],
                self::FIELD_WEIGHT => $candidate[self::FIELD_WEIGHT],
            ];
        }

        $primaryCause = $ranked[0][self::FIELD_CAUSE];
        $alternativeExplanations = array_values(array_slice($ranked, 1));

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_CANDIDATES => $ranked,
            self::FIELD_PRIMARY_CAUSE => $primaryCause,
            self::FIELD_ALTERNATIVE_EXPLANATIONS => $alternativeExplanations,
            self::FIELD_ATTRIBUTION_CONFIDENCE => $this->attributionConfidence($primaryCause),
            self::FIELD_ATTRIBUTION_BLOCKED => ! $hasEvidenceRefs,
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
                self::FIELD_CAUSE => self::CAUSE_MISSING_EVIDENCE,
                self::FIELD_WEIGHT => self::WEIGHT_MISSING_EVIDENCE,
                self::FIELD_ORDER => $order++,
            ];
        }

        if ($testsPassed === false) {
            $candidates[] = [
                self::FIELD_CAUSE => self::CAUSE_TESTS_FAILED,
                self::FIELD_WEIGHT => self::WEIGHT_TESTS_FAILED,
                self::FIELD_ORDER => $order++,
            ];
        }

        if ($status !== self::STATUS_SUCCEEDED) {
            $candidates[] = [
                self::FIELD_CAUSE => self::CAUSE_EXECUTION_FAILED_OR_BLOCKED,
                self::FIELD_WEIGHT => self::WEIGHT_EXECUTION_FAILED_OR_BLOCKED,
                self::FIELD_ORDER => $order++,
            ];
        }

        if ($missingRequiredSources) {
            $candidates[] = [
                self::FIELD_CAUSE => self::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES,
                self::FIELD_WEIGHT => self::WEIGHT_CONTEXT_MISSING_REQUIRED_SOURCES,
                self::FIELD_ORDER => $order++,
            ];
        }

        if ($candidates === []) {
            $candidates[] = [
                self::FIELD_CAUSE => self::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
                self::FIELD_WEIGHT => self::WEIGHT_EXECUTION_STRATEGY_LIKELY_SUCCEEDED,
                self::FIELD_ORDER => $order,
            ];
        }

        return $candidates;
    }

    private function attributionConfidence(string $primaryCause): float
    {
        return match ($primaryCause) {
            self::CAUSE_MISSING_EVIDENCE, self::CAUSE_TESTS_FAILED => self::FLOAT_0_90,
            self::CAUSE_SCOPE_OR_CONTRACT_MISMATCH => self::FLOAT_0_84,
            self::CAUSE_EXECUTION_FAILED_OR_BLOCKED, self::CAUSE_CONTEXT_MISSING_REQUIRED_SOURCES => self::FLOAT_0_78,
            self::CAUSE_PACKET_QUALITY_FAILURE => self::FLOAT_0_76,
            self::CAUSE_EXECUTION_STRATEGY_LIKELY_SUCCEEDED => self::FLOAT_0_62,
            default => self::FLOAT_0_45,
        };
    }
}
