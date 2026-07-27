<?php

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Runtime for the AAEOS Cross-Department Choreography — the state machine the
 * canonical doc describes (handoffs, vetos, repair loops, escalation) but lists
 * only as "Escopo de Implementacao". Pure decision logic: given a veto source or
 * a repair iteration it computes which departments pause, where control returns,
 * and when to escalate. No ad-hoc inter-department communication is modelled —
 * every transition goes through a typed handoff envelope.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
 */
class AtlasCrossDepartmentChoreographyService
{
    public const FIELD_ESCALATE_TO = 'escalate_to';
    public const FIELD_FROM_DEPARTMENT = 'from_department';
    public const HANDOFF_SCHEMA = 'atlas.aaeos.cross_dept.handoff.v1';

    public const VETO_SLA_SECONDS = 10;

    public const REPAIR_MAX_ITERATIONS = 3;

    /**
     * @var array<int,string>
     */
    public const HANDOFF_KIND_DELEGATION = 'delegation';

    public const HANDOFF_KIND_ESCALATION = 'escalation';

    public const HANDOFF_KIND_VETO = 'veto';

    public const HANDOFF_KIND_REPAIR = 'repair';

    public const HANDOFF_KIND_REVIEW_REQUEST = 'review_request';

    public const HANDOFF_KINDS = [
        self::HANDOFF_KIND_DELEGATION,
        self::HANDOFF_KIND_ESCALATION,
        self::HANDOFF_KIND_VETO,
        self::HANDOFF_KIND_REPAIR,
        self::HANDOFF_KIND_REVIEW_REQUEST,
    ];

    public const ACTION_NOOP = 'noop';

    public const ACTION_PAUSE_DOWNSTREAM = 'pause_downstream';

    public const ACTION_RETURN_UPSTREAM = 'return_upstream';

    public const ACTION_OVERRIDE = 'override';

    public const TARGET_ARCHITECT = 'architect';

    public const TARGET_OPERATOR = 'operator';

    public const TARGET_PRODUCT = 'product';
    public const FIELD_ACTION = 'action';
    public const FIELD_RETURN_TO = 'return_to';
    public const FIELD_PROPAGATES_TO = 'propagates_to';
    public const FIELD_FINAL = 'final';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_KIND = 'kind';
    public const FIELD_FROM = 'from';
    public const FIELD_TO = 'to';
    public const FIELD_RECOGNIZED = 'recognized';
    public const FIELD_VETOING_DEPARTMENT = 'vetoing_department';
    public const FIELD_SECURITY = 'security';
    public const FIELD_REVIEW = 'review';
    public const FIELD_REASON = 'reason';
    public const FIELD_PAUSED_DEPARTMENTS = 'paused_departments';
    public const FIELD_FINAL_OVERRIDE = 'final_override';
    public const FIELD_PAUSE_SLA_SECONDS = 'pause_sla_seconds';
    public const FIELD_DECISION = 'decision';
    public const FIELD_ESCALATE = 'escalate';
    public const FIELD_ITERATION = 'iteration';
    public const FIELD_MAX_ITERATIONS = 'max_iterations';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_REMAINING_REPAIRS = 'remaining_repairs';
    public const FIELD_REQUIRES_OPERATOR_RECEIPT = 'requires_operator_receipt';
    public const FIELD_TO_DEPARTMENT = 'to_department';
    public const FIELD_VALID_KIND = 'valid_kind';
    public const FIELD_DEV_OR_FORGE = 'dev_or_forge';
    public const FIELD_FORGE = 'forge';
    public const FIELD_DEV = 'dev';
    public const FIELD_DELIVERY = 'delivery';
    public const FIELD_UNKNOWN_VETOING_DEPARTMENT__ONLY_SECURITY_ARCHITECT_REVIEW_OPERATOR_CAN_VETO = 'unknown vetoing department; only security/architect/review/operator can veto';

    /**
     * Veto propagation rules keyed by the vetoing department.
     *
     * @var array<string,array{propagates_to:array<int,string>, action:string, return_to:?string, final:bool}>
     */
    public const VETO_RULES = [
        self::FIELD_SECURITY => [self::FIELD_PROPAGATES_TO => [self::FIELD_DEV, self::FIELD_FORGE, self::FIELD_DELIVERY], self::FIELD_ACTION => self::ACTION_PAUSE_DOWNSTREAM, self::FIELD_RETURN_TO => null, self::FIELD_FINAL => false],
        self::TARGET_ARCHITECT => [self::FIELD_PROPAGATES_TO => [self::TARGET_PRODUCT], self::FIELD_ACTION => self::ACTION_RETURN_UPSTREAM, self::FIELD_RETURN_TO => self::TARGET_PRODUCT, self::FIELD_FINAL => false],
        self::FIELD_REVIEW => [self::FIELD_PROPAGATES_TO => [self::FIELD_DEV, self::FIELD_FORGE], self::FIELD_ACTION => self::ACTION_RETURN_UPSTREAM, self::FIELD_RETURN_TO => self::FIELD_DEV_OR_FORGE, self::FIELD_FINAL => false],
        self::TARGET_OPERATOR => [self::FIELD_PROPAGATES_TO => ['*'], self::FIELD_ACTION => self::ACTION_OVERRIDE, self::FIELD_RETURN_TO => null, self::FIELD_FINAL => true],
    ];

    /**
     * Evaluate a veto raised by $vetoingDepartment: which departments pause, where
     * control returns, whether it is a final override, and the pause SLA.
     *
     * @return array<string,mixed>
     */
    public function evaluateVeto(string $vetoingDepartment): array
    {
        $dept = $this->departmentId($vetoingDepartment);
        $rule = self::VETO_RULES[$dept] ?? null;
        if ($rule === null) {
            return [
                self::FIELD_SCHEMA_VERSION => self::HANDOFF_SCHEMA,
                self::FIELD_KIND => self::HANDOFF_KIND_VETO,
                self::FIELD_RECOGNIZED => false,
                self::FIELD_VETOING_DEPARTMENT => $dept,
                self::FIELD_ACTION => self::ACTION_NOOP,
                self::FIELD_REASON => self::FIELD_UNKNOWN_VETOING_DEPARTMENT__ONLY_SECURITY_ARCHITECT_REVIEW_OPERATOR_CAN_VETO,
            ];
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::HANDOFF_SCHEMA,
            self::FIELD_KIND => self::HANDOFF_KIND_VETO,
            self::FIELD_RECOGNIZED => true,
            self::FIELD_VETOING_DEPARTMENT => $dept,
            self::FIELD_ACTION => $rule[self::FIELD_ACTION],
            self::FIELD_PAUSED_DEPARTMENTS => $rule[self::FIELD_PROPAGATES_TO],
            self::FIELD_RETURN_TO => $rule[self::FIELD_RETURN_TO],
            self::FIELD_FINAL_OVERRIDE => $rule[self::FIELD_FINAL],
            self::FIELD_PAUSE_SLA_SECONDS => self::VETO_SLA_SECONDS,
            self::FIELD_REQUIRES_OPERATOR_RECEIPT => $dept === self::TARGET_OPERATOR,
        ];
    }

    /**
     * Decide whether a repair loop continues or escalates. Up to
     * REPAIR_MAX_ITERATIONS repairs are allowed; the next one escalates to
     * Architect + Operator (the 4th iteration in the runbook).
     *
     * @return array<string,mixed>
     */
    public function evaluateRepairLoop(int $iteration, int $maxIterations = self::REPAIR_MAX_ITERATIONS): array
    {
        $iteration = max(0, $iteration);
        $escalate = $iteration > $maxIterations;

        return [
            self::FIELD_SCHEMA_VERSION => self::HANDOFF_SCHEMA,
            self::FIELD_KIND => $escalate ? self::HANDOFF_KIND_ESCALATION : self::HANDOFF_KIND_REPAIR,
            self::FIELD_ITERATION => $iteration,
            self::FIELD_MAX_ITERATIONS => $maxIterations,
            self::FIELD_DECISION => $escalate ? self::FIELD_ESCALATE : self::HANDOFF_KIND_REPAIR,
            self::FIELD_ESCALATE => $escalate,
            self::FIELD_ESCALATE_TO => $escalate ? [self::TARGET_ARCHITECT, self::TARGET_OPERATOR] : [],
            self::FIELD_REMAINING_REPAIRS => $escalate ? 0 : max(0, $maxIterations - $iteration),
        ];
    }

    /**
     * Build a typed handoff envelope between two departments.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function handoffEnvelope(string $from, string $to, string $kind, array $payload = []): array
    {
        return [
            self::FIELD_SCHEMA_VERSION => self::HANDOFF_SCHEMA,
            self::FIELD_KIND => in_array($kind, self::HANDOFF_KINDS, true) ? $kind : self::HANDOFF_KIND_DELEGATION,
            self::FIELD_FROM_DEPARTMENT => $this->departmentId($from),
            self::FIELD_TO_DEPARTMENT => $this->departmentId($to),
            self::FIELD_VALID_KIND => in_array($kind, self::HANDOFF_KINDS, true),
            self::FIELD_PAYLOAD => $payload,
        ];
    }

    private function departmentId(string $value): string
    {
        return AiValueNormalizer::lowerTrimmedString($value);
    }
}
