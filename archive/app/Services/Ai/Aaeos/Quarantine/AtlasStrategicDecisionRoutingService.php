<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Strategic Decision Domain · route decider.
 *
 * Pure, deterministic enforcement of the Strategic Decision routing contract.
 * The doc says this domain "transforma intenção humana ambígua em decisão
 * segura: qual fluxo usar, qual evidência falta, quando usar Dev, quando
 * escalar para Forge, quando pedir aprovação humana e quando bloquear
 * execução." Given a parsed human intent it returns the single governed route —
 * `programming.dev`, `programming.forge`, `strategic_decision.review` or
 * `blocked` — plus the specific reasons, the missing evidence and the gate the
 * caller still owes, so no execution can start before the runtime gate clears.
 *
 * Concrete rules grounded in the doc:
 *   - "Fluxo" → Pedido humano -> Human Intent -> Product Truth -> Runtime Gate
 *     -> Dev/Forge/Review/Blocked. The route is the LAST stage and is only
 *     emitted once the upstream stages hold.
 *   - capabilities `route_decision` + `dev_forge_escalation` → Dev and Forge are
 *     different proposals and the choice between them MUST be explicit. A
 *     short, single-surface change ("Bug curto em login") routes to
 *     `programming.dev`; a full multi-surface build ("Ecommerce completo")
 *     routes to `programming.forge`.
 *   - decision "Dev e Forge sao propostas diferentes" + risk "Dev tentando fazer
 *     Obra Forge" → a request whose scope is Forge-sized can NEVER stay on Dev;
 *     it is escalated to Forge (or blocked if its Forge prerequisites are
 *     missing).
 *   - "Regras para IA": "Não executa decisão crítica sem evidência" → a
 *     high/critical-risk request with no evidence is `blocked`, never routed to
 *     execution.
 *   - risk "Forge executando sem work packet, gate ou aprovação" → a Forge route
 *     requires a work packet AND operator approval; absent either the route is
 *     `blocked` with the missing prerequisite named.
 *   - "forbidden_changes": "Executar provider antes de runtime gate" → every
 *     route declares `runtime_gate_required: true`; the route is a plan, not an
 *     execution.
 *
 * The service NEVER executes a provider, applies a patch, runs a gate or touches
 * the database. It emits the route plus an audit-shaped envelope; the caller
 * decides whether to admit the flow, ask for evidence/approval or block.
 *
 * @see docs/engineering-knowledge-base/domains/strategic_decision.md
 */
final class AtlasStrategicDecisionRoutingService
{
    /** Stable receipt schema id for the route this service emits. */
    public const SCHEMA = 'atlas.strategic_decision.route.v1';

    /** Canonical routes (closed set), mirroring the doc "Fluxo" terminals. */
    public const ROUTE_DEV = 'programming.dev';
    public const ROUTE_FORGE = 'programming.forge';
    public const ROUTE_REVIEW = 'strategic_decision.review';
    public const ROUTE_BLOCKED = 'blocked';

    /** Canonical risk levels (closed, ordered low..critical). */
    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    /**
     * Upstream "Fluxo" stages that MUST hold before any route is admitted:
     * Pedido humano -> Human Intent Model -> Product Truth Contract -> Runtime Gate.
     *
     * @var list<string>
     */
    public const FLOW_PREREQUISITES = [
        'human_intent_model',
        'product_truth_contract',
        'runtime_gate',
    ];

    /**
     * Decide the single governed route for one parsed strategic-decision request.
     *
     * @param  array<string,mixed>  $request
     * @return array{
     *     schema_version:string,
     *     route:string,
     *     decision:string,
     *     explicit_dev_vs_forge:bool,
     *     runtime_gate_required:bool,
     *     risk:string,
     *     reasons:list<string>,
     *     missing_evidence:list<string>,
     *     missing_prerequisites:list<string>,
     *     requires_human_approval:bool,
     *     requires_work_packet:bool,
     *     no_external_side_effects:bool,
     *     intent:array<string,mixed>
     * }
     */
    public function route(array $request): array
    {
        $risk = $this->risk($request['risk'] ?? null);
        $hasEvidence = $this->bool($request['has_evidence'] ?? null);
        $isExecutable = $this->bool($request['is_executable'] ?? true);
        $scope = $this->scope($request['scope'] ?? null);
        $hasWorkPacket = $this->bool($request['has_work_packet'] ?? null);
        $hasApproval = $this->bool($request['operator_approval'] ?? null);
        $intent = $this->normalizeIntent($request);

        $reasons = [];
        $missingEvidence = [];
        $missingPrereqs = $this->missingPrerequisites($request);

        // Stage 1 — "Fluxo": upstream stages gate everything. The route is the
        // last stage and cannot be emitted while Human Intent / Product Truth /
        // Runtime Gate are incomplete.
        if ($missingPrereqs !== []) {
            return $this->envelope(
                self::ROUTE_BLOCKED,
                'Upstream flow incomplete: '.implode(', ', $missingPrereqs).' must hold before routing.',
                explicitDevVsForge: false,
                risk: $risk,
                reasons: ['flow_prerequisite_missing'],
                missingEvidence: [],
                missingPrereqs: $missingPrereqs,
                requiresApproval: $scope === 'forge',
                requiresWorkPacket: $scope === 'forge',
                intent: $intent,
            );
        }

        // Stage 2 — "Regras para IA": never execute a critical decision without
        // evidence. High/critical risk with no evidence is a hard block.
        if (! $hasEvidence && in_array($risk, ['high', 'critical'], true)) {
            $missingEvidence[] = 'decision_evidence';

            return $this->envelope(
                self::ROUTE_BLOCKED,
                "Risk '{$risk}' requires evidence before execution; none was supplied.",
                explicitDevVsForge: false,
                risk: $risk,
                reasons: ['no_evidence_for_high_risk'],
                missingEvidence: $missingEvidence,
                missingPrereqs: [],
                requiresApproval: $scope === 'forge',
                requiresWorkPacket: $scope === 'forge',
                intent: $intent,
            );
        }

        // Stage 3 — not an execution request at all: it is a route/risk/tradeoff
        // judgement. Send it to the plan-only review flow.
        if (! $isExecutable || $scope === 'review') {
            return $this->envelope(
                self::ROUTE_REVIEW,
                'Request is a decision/tradeoff with no executable scope; routed to plan-only review.',
                explicitDevVsForge: false,
                risk: $risk,
                reasons: ['non_executable_decision'],
                missingEvidence: [],
                missingPrereqs: [],
                requiresApproval: false,
                requiresWorkPacket: false,
                intent: $intent,
            );
        }

        // Stage 4 — explicit Dev vs Forge decision. Forge-sized work can never
        // stay on Dev ("Dev tentando fazer Obra Forge" is a named risk).
        if ($scope === 'forge') {
            return $this->routeForge($risk, $hasWorkPacket, $hasApproval, $intent);
        }

        // Dev scope: short, single-surface change -> Atlas Dev with runtime gate.
        $reasons[] = 'scope_is_dev_sized';

        return $this->envelope(
            self::ROUTE_DEV,
            'Short, single-surface change routes to Atlas Dev behind the runtime gate.',
            explicitDevVsForge: true,
            risk: $risk,
            reasons: $reasons,
            missingEvidence: [],
            missingPrereqs: [],
            requiresApproval: $risk === 'critical',
            requiresWorkPacket: false,
            intent: $intent,
        );
    }

    /**
     * Forge branch: a Forge route requires a work packet AND operator approval
     * ("Forge executando sem work packet, gate ou aprovação" is forbidden).
     *
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function routeForge(string $risk, bool $hasWorkPacket, bool $hasApproval, array $intent): array
    {
        $missing = [];
        if (! $hasWorkPacket) {
            $missing[] = 'work_packet';
        }
        if (! $hasApproval) {
            $missing[] = 'operator_approval';
        }

        if ($missing !== []) {
            return $this->envelope(
                self::ROUTE_BLOCKED,
                'Forge-sized work blocked: '.implode(' + ', $missing).' required before Forge may run.',
                explicitDevVsForge: true,
                risk: $risk,
                reasons: ['forge_prerequisite_missing'],
                missingEvidence: [],
                missingPrereqs: $missing,
                requiresApproval: true,
                requiresWorkPacket: true,
                intent: $intent,
            );
        }

        return $this->envelope(
            self::ROUTE_FORGE,
            'Full multi-surface build with work packet and operator approval routes to Forge.',
            explicitDevVsForge: true,
            risk: $risk,
            reasons: ['scope_is_forge_sized', 'work_packet_present', 'operator_approval_present'],
            missingEvidence: [],
            missingPrereqs: [],
            requiresApproval: true,
            requiresWorkPacket: true,
            intent: $intent,
        );
    }

    /**
     * Which upstream "Fluxo" prerequisites are not yet satisfied.
     *
     * @param  array<string,mixed>  $request
     * @return list<string>
     */
    public function missingPrerequisites(array $request): array
    {
        $missing = [];
        foreach (self::FLOW_PREREQUISITES as $stage) {
            if (! $this->bool($request[$stage] ?? null)) {
                $missing[] = $stage;
            }
        }

        return $missing;
    }

    /**
     * Build the closed-shape route envelope.
     *
     * @param  list<string>  $reasons
     * @param  list<string>  $missingEvidence
     * @param  list<string>  $missingPrereqs
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function envelope(
        string $route,
        string $decision,
        bool $explicitDevVsForge,
        string $risk,
        array $reasons,
        array $missingEvidence,
        array $missingPrereqs,
        bool $requiresApproval,
        bool $requiresWorkPacket,
        array $intent,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'route' => $route,
            'decision' => $decision,
            'explicit_dev_vs_forge' => $explicitDevVsForge,
            // Forbidden change: "Executar provider antes de runtime gate" — every
            // route is a plan that still owes the runtime gate before execution.
            'runtime_gate_required' => true,
            'risk' => $risk,
            'reasons' => $reasons,
            'missing_evidence' => $missingEvidence,
            'missing_prerequisites' => $missingPrereqs,
            'requires_human_approval' => $requiresApproval,
            'requires_work_packet' => $requiresWorkPacket,
            'no_external_side_effects' => true,
            'intent' => $intent,
        ];
    }

    /**
     * @param  array<string,mixed>  $request
     * @return array<string,mixed>
     */
    private function normalizeIntent(array $request): array
    {
        return [
            'title' => trim((string) ($request['title'] ?? '')),
            'scope' => $this->scope($request['scope'] ?? null),
            'risk' => $this->risk($request['risk'] ?? null),
        ];
    }

    private function risk(mixed $value): string
    {
        $risk = strtolower(trim((string) $value));

        return in_array($risk, self::RISK_LEVELS, true) ? $risk : 'medium';
    }

    /**
     * Scope class drives the explicit Dev/Forge decision. `dev` = short
     * single-surface change; `forge` = full multi-surface build; `review` = no
     * executable scope, a pure decision/tradeoff.
     */
    private function scope(mixed $value): string
    {
        $scope = strtolower(trim((string) $value));

        return in_array($scope, ['dev', 'forge', 'review'], true) ? $scope : 'dev';
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        return (bool) $value;
    }
}
