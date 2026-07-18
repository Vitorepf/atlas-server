<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * M1 · minimal data contract for the Architect-agent spec pack gate before
 * R4 autonomous work. Step-1 shape only — no runtime wiring.
 */
final class ArchitectAgentSpecPackGateContract
{
    public const FIELD_EVIDENCE_REQUIRED = 'evidence_required';
    public const FIELD_GATES = 'gates';
    public const SCHEMA = 'atlas.aaeos.architect_agent_spec_pack_gate.v1';

    public const DEPARTMENT_ID = DepartmentContractRuntime::DEPARTMENT_ARCHITECTURE;

    /** Minimum intent risk scope that requires architect-agent spec pack before autonomous execution. */
    public const MIN_AUTONOMOUS_RISK_SCOPE = 'R4';

    /** Professional-risk work additionally requires operator signature. */
    public const OPERATOR_SIGNATURE_REQUIRED_FROM = 'R5';

    public const SPEC_PACK_SCHEMA = 'atlas.spec_pack.v1';
    public const FIELD_ACCEPTANCE_CRITERIA_PRESENT = 'acceptance_criteria_present';
    public const FIELD_BREAKING_CHANGE_MATRIX_PRESENT = 'breaking_change_matrix_present';
    public const FIELD_OPERATOR_SIGNATURE_PRESENT = 'operator_signature_present';
    public const FIELD_RISK_SCOPE = 'risk_scope';
    public const FIELD_ROLLBACK_PLAN_PRESENT = 'rollback_plan_present';
    public const FIELD_SPEC_PACK_HASH = 'spec_pack_hash';
    public const FIELD_DEPARTMENT_ID = 'department_id';
    public const FIELD_MIN_AUTONOMOUS_RISK_SCOPE = 'min_autonomous_risk_scope';
    public const FIELD_OPERATOR_SIGNATURE_REQUIRED_FROM = 'operator_signature_required_from';
    public const FIELD_SPEC_PACK_SCHEMA = 'spec_pack_schema';
    public const FIELD_INPUTS = 'inputs';
    public const FIELD_REQUIRED_SPEC_PACK_ARTIFACTS = 'required_spec_pack_artifacts';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_ARCHITECT_DECISION_RECEIPT = 'architect_decision_receipt';
    public const FIELD_BOUNDARY_VALIDATED = 'boundary_validated';
    public const FIELD_ROLLBACK_PER_SLICE = 'rollback_per_slice';
    public const FIELD_ROLLBACK_PLAN = 'rollback_plan';
    public const FIELD_BREAKING_CHANGE_MATRIX = 'breaking_change_matrix';
    public const FIELD_SPEC_ACCEPTANCE_CRITERIA_COMPLETE = 'spec_acceptance_criteria_complete';
    public const FIELD_BREAKING_CHANGE_DOCUMENTED = 'breaking_change_documented';
    public const FIELD_ACCEPTANCE_CRITERIA = 'acceptance_criteria';
    public const FIELD_ADR_PUBLISHED = 'adr_published';

    /**
     * Required spec_pack sections before high-risk autonomous work may proceed.
     *
     * @var list<string>
     */
    public const REQUIRED_SPEC_PACK_ARTIFACTS = [
        self::FIELD_ACCEPTANCE_CRITERIA,
        self::FIELD_ROLLBACK_PLAN,
        self::FIELD_BREAKING_CHANGE_MATRIX,
    ];

    /**
     * Gates the architecture lane must satisfy before dev/forge autonomous handoff.
     *
     * @var list<string>
     */
    public const GATES = [
        self::FIELD_ADR_PUBLISHED,
        self::FIELD_BOUNDARY_VALIDATED,
        self::FIELD_SPEC_ACCEPTANCE_CRITERIA_COMPLETE,
        self::FIELD_BREAKING_CHANGE_DOCUMENTED,
        self::FIELD_ROLLBACK_PER_SLICE,
    ];

    /**
     * @var list<string>
     */
    public const EVIDENCE_REQUIRED = [
        self::FIELD_SPEC_PACK_HASH,
        self::FIELD_ARCHITECT_DECISION_RECEIPT,
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
            riskScope: AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_RISK_SCOPE] ?? null) ?? self::MIN_AUTONOMOUS_RISK_SCOPE,
            specPackHash: AiValueNormalizer::trimmedStringOrNull($input[self::FIELD_SPEC_PACK_HASH] ?? null) ?? '',
            acceptanceCriteriaPresent: (AiValueNormalizer::boolOrNull($input[self::FIELD_ACCEPTANCE_CRITERIA_PRESENT] ?? null) ?? false),
            rollbackPlanPresent: (AiValueNormalizer::boolOrNull($input[self::FIELD_ROLLBACK_PLAN_PRESENT] ?? null) ?? false),
            breakingChangeMatrixPresent: (AiValueNormalizer::boolOrNull($input[self::FIELD_BREAKING_CHANGE_MATRIX_PRESENT] ?? null) ?? false),
            operatorSignaturePresent: (AiValueNormalizer::boolOrNull($input[self::FIELD_OPERATOR_SIGNATURE_PRESENT] ?? null) ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_DEPARTMENT_ID => self::DEPARTMENT_ID,
            self::FIELD_MIN_AUTONOMOUS_RISK_SCOPE => self::MIN_AUTONOMOUS_RISK_SCOPE,
            self::FIELD_OPERATOR_SIGNATURE_REQUIRED_FROM => self::OPERATOR_SIGNATURE_REQUIRED_FROM,
            self::FIELD_SPEC_PACK_SCHEMA => self::SPEC_PACK_SCHEMA,
            self::FIELD_REQUIRED_SPEC_PACK_ARTIFACTS => self::REQUIRED_SPEC_PACK_ARTIFACTS,
            self::FIELD_GATES => self::GATES,
            self::FIELD_EVIDENCE_REQUIRED => self::EVIDENCE_REQUIRED,
            self::FIELD_INPUTS => [
                self::FIELD_RISK_SCOPE => $this->riskScope,
                self::FIELD_SPEC_PACK_HASH => $this->specPackHash,
                self::FIELD_ACCEPTANCE_CRITERIA_PRESENT => $this->acceptanceCriteriaPresent,
                self::FIELD_ROLLBACK_PLAN_PRESENT => $this->rollbackPlanPresent,
                self::FIELD_BREAKING_CHANGE_MATRIX_PRESENT => $this->breakingChangeMatrixPresent,
                self::FIELD_OPERATOR_SIGNATURE_PRESENT => $this->operatorSignaturePresent,
            ],
        ];
    }
}
