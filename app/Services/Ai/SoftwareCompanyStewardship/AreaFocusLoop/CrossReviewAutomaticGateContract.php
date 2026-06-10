<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the cross-review automatic gate in
 * {@see StewardshipBranchReviewPacketService}. Step 1 of 3: shape only — no
 * packet service wiring in this class.
 */
final class CrossReviewAutomaticGateContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.cross_review_automatic_gate.v1';

    public const MATURITY_MATRIX_CANONICAL = 'docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md';

    public const GATE_ID = 'cross_review_automatic_gate';

    public const DEPARTMENT_ID = 'review';

    /** Review department L2→R4: automatic cross-review when cross-system scope is detected. */
    public const MATURITY_TARGET = 'R4';

    public const MANDATORY_SECONDARY_REVIEW_ON_CROSS_SYSTEM = true;

    public const AUTOMATIC_SECONDARY_REVIEW_ROUTE = 'cross_review_lane';

    private function __construct(
        public readonly string $areaId,
        public readonly bool $crossSystem,
        /** @var list<string> */
        public readonly array $changedFiles,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
    ): self {
        return new self(
            areaId: $areaId,
            crossSystem: false,
            changedFiles: [],
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $changedFiles = AreaFocusStringListNormalizer::coercedStringValues($input['changed_files'] ?? []);

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            crossSystem: (bool) ($input['cross_system'] ?? false),
            changedFiles: $changedFiles,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $requiresMandatorySecondaryReview = $this->crossSystem
            && self::MANDATORY_SECONDARY_REVIEW_ON_CROSS_SYSTEM;

        return [
            'schema_version' => self::SCHEMA,
            'gate_id' => self::GATE_ID,
            'maturity_matrix_canonical' => self::MATURITY_MATRIX_CANONICAL,
            'department_id' => self::DEPARTMENT_ID,
            'maturity_target' => self::MATURITY_TARGET,
            'mandatory_secondary_review_on_cross_system' => self::MANDATORY_SECONDARY_REVIEW_ON_CROSS_SYSTEM,
            'area_id' => $this->areaId,
            'inputs' => [
                'cross_system' => $this->crossSystem,
                'changed_files' => $this->changedFiles,
            ],
            'outputs' => [
                'cross_system' => $this->crossSystem,
                'requires_mandatory_secondary_review' => $requiresMandatorySecondaryReview,
                'automatic_secondary_review_route' => $requiresMandatorySecondaryReview
                    ? self::AUTOMATIC_SECONDARY_REVIEW_ROUTE
                    : null,
            ],
        ];
    }
}
