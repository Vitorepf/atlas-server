<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for per-failure-class remediation hints from
 * {@see LoopCycleFailureTaxonomyService}. Step 1 of 3: shape only — no
 * taxonomy service wiring in this class.
 *
 * Maps each taxonomy tier_name to a machine-readable remediation hint so the
 * runner and morning inbox can act on the class instead of re-deriving it.
 */
final class PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.per_failure_class_remediation_hints.v1';

    public const STACK_CANONICAL = 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md';

    public const TAXONOMY_REPORT_SCHEMA = LoopCycleFailureTaxonomyService::REPORT_SCHEMA;

    public const HINT_RETRYABLE = 'retryable';

    public const HINT_NEEDS_REPAIR = 'needs_repair';

    public const HINT_NEEDS_AUTHORITY = 'needs_authority';

    public const HINT_TERMINAL = 'terminal';

    /**
     * Canonical tier_name → remediation hint for all five taxonomy classes.
     *
     * @var array<string,string>
     */
    public const TIER_HINT_BY_TIER_NAME = [
        LoopCycleFailureTaxonomyService::TIER_SETUP_NAME => self::HINT_RETRYABLE,
        LoopCycleFailureTaxonomyService::TIER_EXECUTION_NAME => self::HINT_RETRYABLE,
        LoopCycleFailureTaxonomyService::TIER_QUALITY_NAME => self::HINT_NEEDS_REPAIR,
        LoopCycleFailureTaxonomyService::TIER_POLICY_NAME => self::HINT_NEEDS_AUTHORITY,
        LoopCycleFailureTaxonomyService::TIER_BUDGET_NAME => self::HINT_TERMINAL,
    ];

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly int $tier,
        public readonly string $tierName,
        public readonly string $specificReason,
        public readonly string $recoveryAction,
        public readonly bool $classified,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            tier: 0,
            tierName: '',
            specificReason: '',
            recoveryAction: '',
            classified: false,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $tier = max(0, (int) ($input['tier'] ?? 0));
        $tierName = strtolower(trim((string) ($input['tier_name'] ?? '')));

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            tier: $tier,
            tierName: $tierName,
            specificReason: trim((string) ($input['specific_reason'] ?? '')),
            recoveryAction: trim((string) ($input['recovery_action'] ?? '')),
            classified: (bool) ($input['classified'] ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $remediationHint = $this->resolveRemediationHint();
        $hintRecognized = $remediationHint !== null;

        return [
            'schema_version' => self::SCHEMA,
            'taxonomy_report_schema' => self::TAXONOMY_REPORT_SCHEMA,
            'stack_canonical' => self::STACK_CANONICAL,
            'tier_hint_by_tier_name' => self::TIER_HINT_BY_TIER_NAME,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'tier' => $this->tier,
                'tier_name' => $this->tierName,
                'specific_reason' => $this->specificReason,
                'recovery_action' => $this->recoveryAction,
                'classified' => $this->classified,
            ],
            'outputs' => [
                'remediation_hint' => $remediationHint,
                'hint_recognized' => $hintRecognized,
                'actionable_for_runner' => $hintRecognized && $remediationHint !== self::HINT_TERMINAL,
            ],
        ];
    }

    private function resolveRemediationHint(): ?string
    {
        if ($this->tierName === '') {
            return null;
        }

        return self::TIER_HINT_BY_TIER_NAME[$this->tierName] ?? null;
    }
}
