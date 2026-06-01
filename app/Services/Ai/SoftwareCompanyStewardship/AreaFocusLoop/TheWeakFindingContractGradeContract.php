<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Pure data contract grading a self-protection finding before the loop spends
 * provider budget on it. A finding whose evidence refs are synthetic stubs or
 * bare tokens, whose affected paths are empty, whose recommended action is the
 * default placeholder, or whose detail is blank is graded a weak contract and
 * blocked under {@see LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED}.
 */
final class TheWeakFindingContractGradeContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.weak_finding_contract_grade.v1';

    public const CONTRACT_ID = 'weak_finding_contract_grade';

    public const PREFLIGHT_BLOCK_STATUS = LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED;

    public const GRADE_ACTIONABLE = 'actionable_contract';

    public const GRADE_WEAK = 'weak_contract';

    public const DEFICIT_NO_CONCRETE_EVIDENCE_REFS = 'no_concrete_evidence_refs';

    public const DEFICIT_EMPTY_AFFECTED_PATHS = 'empty_affected_paths';

    public const DEFICIT_DEFAULT_RECOMMENDED_ACTION = 'default_recommended_action';

    public const DEFICIT_EMPTY_DETAIL = 'empty_detail';

    private const DEFAULT_RECOMMENDED_ACTION = 'Operator review required.';

    private const DEFAULT_FINDING_TYPE = 'self_protection_finding';

    /**
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $affectedPaths
     */
    private function __construct(
        public readonly string $findingType,
        public readonly array $evidenceRefs,
        public readonly array $affectedPaths,
        public readonly string $recommendedAction,
        public readonly string $detail,
    ) {}

    public static function defaults(
        string $findingType = self::DEFAULT_FINDING_TYPE,
    ): self {
        return new self(
            findingType: $findingType,
            evidenceRefs: [],
            affectedPaths: [],
            recommendedAction: self::DEFAULT_RECOMMENDED_ACTION,
            detail: '',
        );
    }

    /**
     * @param  array<string,mixed>  $finding
     */
    public static function fromArray(array $finding): self
    {
        $evidenceRefs = self::stringList($finding['evidence_refs'] ?? []);
        $affectedPaths = self::stringList($finding['affected_paths'] ?? []);

        return new self(
            findingType: trim((string) ($finding['finding_type'] ?? self::DEFAULT_FINDING_TYPE)),
            evidenceRefs: $evidenceRefs,
            affectedPaths: $affectedPaths,
            recommendedAction: (string) ($finding['recommended_action'] ?? self::DEFAULT_RECOMMENDED_ACTION),
            detail: (string) ($finding['detail'] ?? ''),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $grade = $this->resolveGrade(
            $this->evidenceRefs,
            $this->affectedPaths,
            $this->recommendedAction,
            $this->detail,
        );

        return [
            'schema_version' => self::SCHEMA,
            'contract_id' => self::CONTRACT_ID,
            'preflight_block_status' => self::PREFLIGHT_BLOCK_STATUS,
            'finding_type' => $this->findingType,
            'inputs' => [
                'evidence_refs' => $this->evidenceRefs,
                'affected_paths' => $this->affectedPaths,
                'recommended_action' => $this->recommendedAction,
                'detail' => $this->detail,
            ],
            'outputs' => $grade,
        ];
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @param  list<string>  $affectedPaths
     * @return array{contract_grade: string, blocks_before_spend: bool, deficit_reasons: list<string>, concrete_evidence_ref_count: int}
     */
    private function resolveGrade(
        array $evidenceRefs,
        array $affectedPaths,
        string $recommendedAction,
        string $detail,
    ): array {
        $concreteCount = 0;
        foreach ($evidenceRefs as $ref) {
            if ($this->isConcreteEvidenceRef($ref)) {
                $concreteCount++;
            }
        }

        $deficitReasons = [];

        // R1: no concrete evidence ref survives the synthetic-stub / bare-token filter.
        if ($concreteCount === 0) {
            $deficitReasons[] = self::DEFICIT_NO_CONCRETE_EVIDENCE_REFS;
        }

        // R2: affected paths empty after trim-filter.
        if ($this->trimFilter($affectedPaths) === []) {
            $deficitReasons[] = self::DEFICIT_EMPTY_AFFECTED_PATHS;
        }

        // R3: recommended action is the default placeholder.
        if (trim($recommendedAction) === self::DEFAULT_RECOMMENDED_ACTION) {
            $deficitReasons[] = self::DEFICIT_DEFAULT_RECOMMENDED_ACTION;
        }

        // R4: detail blank after trim.
        if (trim($detail) === '') {
            $deficitReasons[] = self::DEFICIT_EMPTY_DETAIL;
        }

        sort($deficitReasons);
        $deficitReasons = array_values($deficitReasons);

        // R5: grade + spend gate.
        $grade = $deficitReasons === [] ? self::GRADE_ACTIONABLE : self::GRADE_WEAK;

        return [
            'contract_grade' => $grade,
            'blocks_before_spend' => $grade === self::GRADE_WEAK,
            'deficit_reasons' => $deficitReasons,
            // R6: concrete evidence ref count.
            'concrete_evidence_ref_count' => $concreteCount,
        ];
    }

    /**
     * An evidence ref is concrete when it is non-empty AND is neither a synthetic
     * expectation stub (e.g. "replay_expected:true") nor a bare token that carries
     * no file path segment ("/") and no line segment (":<digit>").
     */
    private function isConcreteEvidenceRef(string $ref): bool
    {
        $trimmed = trim($ref);

        if ($trimmed === '') {
            return false;
        }

        if ($this->isSyntheticStub($trimmed)) {
            return false;
        }

        $hasPathSegment = str_contains($trimmed, '/');
        $hasLineSegment = preg_match('/:\d/', $trimmed) === 1;

        if (! $hasPathSegment && ! $hasLineSegment) {
            return false;
        }

        return true;
    }

    private function isSyntheticStub(string $ref): bool
    {
        return preg_match(
            '/^(replay_expected|desktop_expected|dev_forge_routing_expected):true$/',
            $ref,
        ) === 1;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function trimFilter(array $values): array
    {
        return array_values(array_filter(
            $values,
            static fn (string $value): bool => trim($value) !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return array_values(array_map(
            static fn (mixed $entry): string => (string) $entry,
            array_filter(
                (array) $value,
                static fn (mixed $entry): bool => is_scalar($entry) || $entry === null,
            ),
        ));
    }
}
