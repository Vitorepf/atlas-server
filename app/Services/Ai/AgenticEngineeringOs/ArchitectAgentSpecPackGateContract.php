<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

/**
 * M1 · minimal data contract for the Architect-agent spec pack gate before
 * R4 autonomous work. Step-1 shape only — no runtime wiring.
 */
final class ArchitectAgentSpecPackGateContract
{
    public const SCHEMA = 'atlas.aaeos.architect_agent_spec_pack_gate.v1';

    public const DEPARTMENT_ID = DepartmentContractRuntime::DEPARTMENT_ARCHITECTURE;

    /** Minimum intent risk scope that requires architect-agent spec pack before autonomous execution. */
    public const MIN_AUTONOMOUS_RISK_SCOPE = 'R4';

    /** Professional-risk work additionally requires operator signature. */
    public const OPERATOR_SIGNATURE_REQUIRED_FROM = 'R5';

    public const SPEC_PACK_SCHEMA = 'atlas.spec_pack.v1';

    /**
     * Required spec_pack sections before high-risk autonomous work may proceed.
     *
     * @var list<string>
     */
    public const REQUIRED_SPEC_PACK_ARTIFACTS = [
        'acceptance_criteria',
        'rollback_plan',
        'breaking_change_matrix',
    ];

    /**
     * Gates the architecture lane must satisfy before dev/forge autonomous handoff.
     *
     * @var list<string>
     */
    public const GATES = [
        'adr_published',
        'boundary_validated',
        'spec_acceptance_criteria_complete',
        'breaking_change_documented',
        'rollback_per_slice',
    ];

    /**
     * @var list<string>
     */
    public const EVIDENCE_REQUIRED = [
        'spec_pack_hash',
        'architect_decision_receipt',
    ];

    private function __construct(
        public readonly string $riskScope,
        public readonly string $specPackHash,
        public readonly bool $acceptanceCriteriaPresent,
        public readonly bool $rollbackPlanPresent,
        public readonly bool $breakingChangeMatrixPresent,
        public readonly bool $operatorSignaturePresent,
    ) {}

    public static function defaults(string $riskScope = self::MIN_AUTONOMOUS_RISK_SCOPE): self
    {
        return new self(
            riskScope: $riskScope,
            specPackHash: '',
            acceptanceCriteriaPresent: false,
            rollbackPlanPresent: false,
            breakingChangeMatrixPresent: false,
            operatorSignaturePresent: false,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            riskScope: trim((string) ($input['risk_scope'] ?? self::MIN_AUTONOMOUS_RISK_SCOPE)),
            specPackHash: trim((string) ($input['spec_pack_hash'] ?? '')),
            acceptanceCriteriaPresent: (bool) ($input['acceptance_criteria_present'] ?? false),
            rollbackPlanPresent: (bool) ($input['rollback_plan_present'] ?? false),
            breakingChangeMatrixPresent: (bool) ($input['breaking_change_matrix_present'] ?? false),
            operatorSignaturePresent: (bool) ($input['operator_signature_present'] ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'department_id' => self::DEPARTMENT_ID,
            'min_autonomous_risk_scope' => self::MIN_AUTONOMOUS_RISK_SCOPE,
            'operator_signature_required_from' => self::OPERATOR_SIGNATURE_REQUIRED_FROM,
            'spec_pack_schema' => self::SPEC_PACK_SCHEMA,
            'required_spec_pack_artifacts' => self::REQUIRED_SPEC_PACK_ARTIFACTS,
            'gates' => self::GATES,
            'evidence_required' => self::EVIDENCE_REQUIRED,
            'inputs' => [
                'risk_scope' => $this->riskScope,
                'spec_pack_hash' => $this->specPackHash,
                'acceptance_criteria_present' => $this->acceptanceCriteriaPresent,
                'rollback_plan_present' => $this->rollbackPlanPresent,
                'breaking_change_matrix_present' => $this->breakingChangeMatrixPresent,
                'operator_signature_present' => $this->operatorSignaturePresent,
            ],
        ];
    }
}
