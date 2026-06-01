<?php

namespace App\Services\Ai\Aaeos;

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
    public const HANDOFF_SCHEMA = 'atlas.aaeos.cross_dept.handoff.v1';

    public const VETO_SLA_SECONDS = 10;

    public const REPAIR_MAX_ITERATIONS = 3;

    /**
     * @var array<int,string>
     */
    public const HANDOFF_KINDS = ['delegation', 'escalation', 'veto', 'repair', 'review_request'];

    /**
     * Veto propagation rules keyed by the vetoing department.
     *
     * @var array<string,array{propagates_to:array<int,string>, action:string, return_to:?string, final:bool}>
     */
    private const VETO_RULES = [
        'security' => ['propagates_to' => ['dev', 'forge', 'delivery'], 'action' => 'pause_downstream', 'return_to' => null, 'final' => false],
        'architect' => ['propagates_to' => ['product'], 'action' => 'return_upstream', 'return_to' => 'product', 'final' => false],
        'review' => ['propagates_to' => ['dev', 'forge'], 'action' => 'return_upstream', 'return_to' => 'dev_or_forge', 'final' => false],
        'operator' => ['propagates_to' => ['*'], 'action' => 'override', 'return_to' => null, 'final' => true],
    ];

    /**
     * Evaluate a veto raised by $vetoingDepartment: which departments pause, where
     * control returns, whether it is a final override, and the pause SLA.
     *
     * @return array<string,mixed>
     */
    public function evaluateVeto(string $vetoingDepartment): array
    {
        $dept = strtolower(trim($vetoingDepartment));
        $rule = self::VETO_RULES[$dept] ?? null;
        if ($rule === null) {
            return [
                'schema_version' => self::HANDOFF_SCHEMA,
                'kind' => 'veto',
                'recognized' => false,
                'vetoing_department' => $dept,
                'action' => 'noop',
                'reason' => 'unknown vetoing department; only security/architect/review/operator can veto',
            ];
        }

        return [
            'schema_version' => self::HANDOFF_SCHEMA,
            'kind' => 'veto',
            'recognized' => true,
            'vetoing_department' => $dept,
            'action' => $rule['action'],
            'paused_departments' => $rule['propagates_to'],
            'return_to' => $rule['return_to'],
            'final_override' => $rule['final'],
            'pause_sla_seconds' => self::VETO_SLA_SECONDS,
            'requires_operator_receipt' => $dept === 'operator',
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
            'schema_version' => self::HANDOFF_SCHEMA,
            'kind' => $escalate ? 'escalation' : 'repair',
            'iteration' => $iteration,
            'max_iterations' => $maxIterations,
            'decision' => $escalate ? 'escalate' : 'repair',
            'escalate' => $escalate,
            'escalate_to' => $escalate ? ['architect', 'operator'] : [],
            'remaining_repairs' => $escalate ? 0 : max(0, $maxIterations - $iteration),
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
            'schema_version' => self::HANDOFF_SCHEMA,
            'kind' => in_array($kind, self::HANDOFF_KINDS, true) ? $kind : 'delegation',
            'from_department' => strtolower(trim($from)),
            'to_department' => strtolower(trim($to)),
            'valid_kind' => in_array($kind, self::HANDOFF_KINDS, true),
            'payload' => $payload,
        ];
    }
}
