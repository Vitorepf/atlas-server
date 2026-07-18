<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Builds provider-safe AAEOS HTTP-path phase envelopes.
 *
 * The HTTP facade owns orchestration, cache, blocking and telemetry. This
 * factory owns the repeated envelope shapes so phase wiring stays in one
 * place instead of growing more private emit* branches in the facade.
 */
final class AaeosHttpPathEnvelopeFactory
{
    public const FIELD_ID = 'id';
    public const FIELD_POLICY_STATUS = 'policy_status';
    public const RISK_BAND_FAST_PATH = 'r1_r2_fast_path';

    public const RISK_BAND_R3_PLUS = 'r3_plus';

    public const STATUS_UNKNOWN = 'unknown';

    public const FIELD_BLOCKED = 'blocked';


    public const FIELD_INTENT_HASH = 'intent_hash';

    public const FIELD_SEVERITY = 'severity';

    public const FIELD_OWNER = 'owner';

    public const FIELD_PHASE_IN = 'phase_in';

    public const FIELD_PHASE_OUT = 'phase_out';

    public const FIELD_ACTOR_ID = 'actor_id';

    public const FIELD_SKIP_RECEIPT_ID = 'skip_receipt_id';

    public const FIELD_SKIP_REASON = 'skip_reason';

    public const FIELD_OUTPUTS = 'outputs';

    public const FIELD_REQUIRED_GATE = 'required_gate';

    public const FIELD_RISK_BAND = 'risk_band';

    public const FIELD_STATUS = 'status';
    public const FIELD_AAWR_INVOCATION = 'aawr_invocation';
    public const FIELD_BLOCKED_WHEN = 'blocked_when';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_COMMAND_INTENT = 'command_intent';
    public const FIELD_COMPANY_RUNTIME_INVOCATION = 'company_runtime_invocation';
    public const FIELD_DECISION_RECEIPT_V2_INVOCATION = 'decision_receipt_v2_invocation';
    public const FIELD_DEPARTMENT_ROUTE = 'department_route';
    public const FIELD_DOMAIN = 'domain';
    public const FIELD_FLOW = 'flow';
    public const FIELD_FLOW_ID = 'flow_id';
    public const FIELD_GATE_STATUS = 'gate_status';
    public const FIELD_INTENT_ID = 'intent_id';
    public const FIELD_KIND = 'kind';
    public const FIELD_LAYER = 'layer';
    public const FIELD_MISSION_SHOULD_ACTIVATE = 'mission_should_activate';
    public const FIELD_MISSION_SIGNAL_KIND = 'mission_signal_kind';
    public const FIELD_PLACEMENT = 'placement';
    public const FIELD_PLACEMENT_DOMAIN = 'placement_domain';
    public const FIELD_PLACEMENT_LAYER = 'placement_layer';
    public const FIELD_PLACEMENT_FLOW = 'placement_flow';
    public const FIELD_PASSED = 'passed';
    public const FIELD_POLICY_ALLOWED = 'policy_allowed';
    public const FIELD_POLICY_TARGET = 'policy_target';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_RECEIPT = 'receipt';
    public const FIELD_RECEIPT_REQUIRED = 'receipt_required';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_ROUTING = 'routing';
    public const FIELD_ROUTING_TASK = 'routing_task';
    public const FIELD_SPEC = 'spec';
    public const FIELD_SPEC_INVOCATION = 'spec_invocation';
    public const FIELD_SPEC_REQUIRED = 'spec_required';
    public const FIELD_TARGET_DEPARTMENT_DECLARED = 'target_department_declared';
    public const FIELD_TASK_PACK_INVOCATION = 'task_pack_invocation';
    public const FIELD_TASK_PACK_REQUIRED = 'task_pack_required';
    public const FIELD_TASKS = 'tasks';
    public const FIELD_TOPOLOGY = 'topology';
    public const FIELD_TOPOLOGY_REQUIRED = 'topology_required';
    public const FIELD_VERDICT = 'verdict';
    public const FIELD_ATLAS_DEV = 'atlas_dev';
    public const FIELD_ATLAS_FORGE = 'atlas_forge';
    public const FIELD_YES = 'yes';
    public const FIELD_DEFERRED = 'deferred';
    public const FIELD_HIGH = 'high';
    public const FIELD_R1_R2_FAST_PATH_PRESERVED = 'r1_r2_fast_path_preserved';
    public const FIELD_ASSISTED_EXECUTION_NEEDS_CONTEXT = 'assisted_execution_needs_context';
    public const FIELD_CLASSIFICATION_TARGET_DEPARTMENT_MISSING = 'classification_target_department_missing';
    public const FIELD_DECISION_RECEIPT_V2_SIGNED = 'decision_receipt_v2_signed';
    public const FIELD_DEPARTMENT_ROUTE_OWNER_CONFIRMED = 'department_route_owner_confirmed';
    public const FIELD_ENGINEERING_OR_FORGE_PENDING_AAWR = 'engineering_or_forge_pending_aawr';
    public const FIELD_MEDIUM = 'medium';
    public const FIELD_READY_FOR_ASSISTED_EXECUTION = 'ready_for_assisted_execution';
    public const FIELD_SPEC_PACK_ACCEPTANCE_CRITERIA_MIN_3 = 'spec_pack_acceptance_criteria_min_3';
    public const FIELD_SYSTEM = 'system';
    public const FIELD_TASK_PACK_ATOMIC_TRUE_FOR_EACH = 'task_pack_atomic_true_for_each';
    public const FIELD_FORGE = 'forge';
    public const FIELD_ATLAS_AI_ASSISTED_EXECUTION_QUALITY = 'atlas_ai_assisted_execution_quality';
    public const FIELD_ATLAS_AI_ROUTER = 'atlas_ai_router';
    public const FIELD_INTENT_CLARITY_SCORE_MIN_0_8 = 'intent_clarity_score_min_0_8';
    public const FIELD_IS_STRING = 'is_string';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_PLACEMENT_DECISION_FEATURE_PATH_VALID = 'placement_decision_feature_path_valid';
    public const FIELD_POLICY_DECISION_ALLOWED_TRUE = 'policy_decision_allowed_true';
    public const FIELD_SURFACE_CAPTURED_INTENT = 'surface_captured_intent';
    public const FIELD_TOPOLOGY_PLAN_PROVIDERS_MIN_1_AVAILABLE = 'topology_plan_providers_min_1_available';
    public const FIELD_NO = 'no';
    public const FIELD_OBRA = 'obra';
    public const FIELD_PLAN = 'plan';
    public const FIELD_MISSION_FOUNDATION_OPTIONAL_AT_PHASE_1 = 'mission_foundation_optional_at_phase_1';
    public const FIELD_NONE = 'none';
    public const FIELD_NOT_REQUIRED = 'not_required';
    public const FIELD_ATLAS_AI = 'atlas-ai';
    public const FIELD_AAEOS_CLASSIFICATION = 'aaeos.classification';
    public const FIELD_AAEOS_HTTP_PATH_FACADE = 'aaeos.http_path_facade';
    public const FIELD_AAEOS_MISSION_DETECTION = 'aaeos.mission_detection';
    public const FIELD_AAEOS_PLACEMENT = 'aaeos.placement';
    public const FIELD_AAEOS_POLICY_GATE = 'aaeos.policy_gate';
    public const FIELD_AAEOS_RECEIPT = 'aaeos.receipt';
    public const FIELD_AAEOS_ROUTING = 'aaeos.routing';

    public function __construct(
        private readonly AaeosPhaseHandoffService $handoff,
        private readonly PhaseAdvanceVerdictClassifier $phaseAdvance = new PhaseAdvanceVerdictClassifier,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function intentCapture(string $intentId, string $intentHash): array
    {
        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            phaseOut: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            actor: self::systemActor(self::FIELD_AAEOS_HTTP_PATH_FACADE),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [self::FIELD_INTENT_HASH => $intentHash, self::FIELD_INTENT_ID => $intentId],
            gates: self::binaryGate(self::FIELD_SURFACE_CAPTURED_INTENT, true),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function disambiguationSignal(
        string $intentId,
        string $intentHash,
        string $suggestedMissionType,
        bool $shouldActivateMissionMode,
    ): array {
        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            phaseOut: AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
            actor: self::systemActor(self::FIELD_AAEOS_MISSION_DETECTION),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_MISSION_SIGNAL_KIND => $suggestedMissionType,
                self::FIELD_MISSION_SHOULD_ACTIVATE => $shouldActivateMissionMode ? self::FIELD_YES : self::FIELD_NO,
            ],
            gates: self::binaryGate(self::FIELD_INTENT_CLARITY_SCORE_MIN_0_8, true),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function disambiguationSkipped(string $intentId): array
    {
        return $this->handoff->skip(
            intentId: $intentId,
            phase: AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
            receiptId: 'rcpt:aaeos.phase1.disambiguation.optional',
            reason: self::FIELD_MISSION_FOUNDATION_OPTIONAL_AT_PHASE_1,
        );
    }

    /**
     * @param  array<string,mixed>  $placementResult
     * @return array<string,mixed>
     */
    public function placement(string $intentId, string $intentHash, array $placementResult, bool $placementOk): array
    {
        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
            phaseOut: AaeosPhaseHandoffService::PHASE_PLACEMENT,
            actor: self::systemActor(self::FIELD_AAEOS_PLACEMENT),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_PLACEMENT_LAYER => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_LAYER] ?? null) ?? self::STATUS_UNKNOWN,
                self::FIELD_PLACEMENT_DOMAIN => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_DOMAIN] ?? null) ?? self::STATUS_UNKNOWN,
                self::FIELD_PLACEMENT_FLOW => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_FLOW] ?? null) ?? self::STATUS_UNKNOWN,
                self::FIELD_GATE_STATUS => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_GATE_STATUS] ?? null) ?? self::STATUS_UNKNOWN,
            ],
            gates: self::binaryGate(self::FIELD_PLACEMENT_DECISION_FEATURE_PATH_VALID, $placementOk),
            blockers: $placementOk ? [] : self::blockedWhenAsBlockers($placementResult),
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function classification(string $intentId, string $intentHash, array $data): array
    {
        $router = self::routerFromData($data);
        $flowId = AiValueNormalizer::trimmedStringOrNull($router[self::FIELD_FLOW_ID] ?? null) ?? self::STATUS_UNKNOWN;
        $commandIntent = AiValueNormalizer::trimmedStringOrNull($router[self::FIELD_COMMAND_INTENT] ?? null) ?? self::STATUS_UNKNOWN;
        $declared = $flowId !== self::STATUS_UNKNOWN;

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_PLACEMENT,
            phaseOut: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            actor: self::systemActor(self::FIELD_AAEOS_CLASSIFICATION),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_FLOW_ID => $flowId,
                self::FIELD_COMMAND_INTENT => $commandIntent,
                self::FIELD_TARGET_DEPARTMENT_DECLARED => $declared ? self::FIELD_YES : self::FIELD_NO,
            ],
            gates: self::binaryGate('intent_classification_target_department_declared', $declared),
            blockers: $declared
                ? []
                : [[self::FIELD_ID => self::FIELD_CLASSIFICATION_TARGET_DEPARTMENT_MISSING, self::FIELD_SEVERITY => self::FIELD_MEDIUM, self::FIELD_OWNER => self::FIELD_ATLAS_AI]],
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function policyGate(string $intentId, string $intentHash, array $data): array
    {
        $assisted = self::assistedExecutionQuality($data);
        $target = AiValueNormalizer::trimmedStringOrNull(data_get($assisted, 'route.target', null)) ?? '';
        $isDevTarget = $target === self::FIELD_ATLAS_DEV;
        $status = AiValueNormalizer::trimmedStringOrNull($assisted[self::FIELD_STATUS] ?? null) ?? '';
        $allowed = $isDevTarget ? ($status === self::FIELD_READY_FOR_ASSISTED_EXECUTION) : true;

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            phaseOut: AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            actor: self::systemActor(self::FIELD_AAEOS_POLICY_GATE),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_POLICY_TARGET => $target !== '' ? $target : self::FIELD_NONE,
                self::FIELD_POLICY_STATUS => $status !== '' ? $status : self::FIELD_NOT_REQUIRED,
                self::FIELD_POLICY_ALLOWED => $allowed ? self::FIELD_YES : self::FIELD_NO,
            ],
            gates: self::binaryGate(self::FIELD_POLICY_DECISION_ALLOWED_TRUE, $allowed),
            blockers: self::assistedExecutionBlockers($assisted, $isDevTarget, $allowed),
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return self::RISK_BAND_*
     */
    public function riskBand(array $data): string
    {
        $payload = self::requestPayload($data);
        $intent = AiValueNormalizer::trimmedStringOrNull(data_get($payload, 'atlas_ai_router.command_intent')) ?? '';
        $routingTask = AiValueNormalizer::trimmedStringOrNull($payload[self::FIELD_ROUTING_TASK] ?? null) ?? '';
        $flowId = AiValueNormalizer::trimmedStringOrNull(data_get($payload, 'atlas_ai_router.flow_id')) ?? '';

        if (in_array($intent, [self::FIELD_PLAN, self::FIELD_FORGE, self::FIELD_OBRA], true) || in_array($routingTask, [self::FIELD_PLAN, self::FIELD_FORGE, self::FIELD_OBRA], true)) {
            return self::RISK_BAND_R3_PLUS;
        }
        if ($flowId === 'programming.forge' || $flowId === self::FIELD_ATLAS_FORGE) {
            return self::RISK_BAND_R3_PLUS;
        }

        return self::RISK_BAND_FAST_PATH;
    }

    /**
     * Deferred/fast-path phase specs (topology→receipt). One map instead of five
     * near-identical deferredOrFastPath call sites.
     *
     * @var array<string, array{
     *   phase_in: string,
     *   phase_out: string,
     *   actor_id: string,
     *   skip_receipt_id: string,
     *   skip_reason: string,
     *   outputs: array<string,string>,
     *   required_gate: string
     * }>
     */
    public const DEFERRED_PHASE_SPECS = [
        self::FIELD_TOPOLOGY => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            self::FIELD_ACTOR_ID => 'aaeos.topology',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase3.topology.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => self::FIELD_R1_R2_FAST_PATH_PRESERVED,
            self::FIELD_OUTPUTS => [
                self::FIELD_TOPOLOGY_REQUIRED => self::FIELD_YES,
                self::FIELD_AAWR_INVOCATION => self::FIELD_DEFERRED,
            ],
            self::FIELD_REQUIRED_GATE => self::FIELD_TOPOLOGY_PLAN_PROVIDERS_MIN_1_AVAILABLE,
        ],
        self::FIELD_ROUTING => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_ROUTING,
            self::FIELD_ACTOR_ID => self::FIELD_AAEOS_ROUTING,
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase3.routing.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => self::FIELD_R1_R2_FAST_PATH_PRESERVED,
            self::FIELD_OUTPUTS => [
                self::FIELD_DEPARTMENT_ROUTE => self::FIELD_ENGINEERING_OR_FORGE_PENDING_AAWR,
                self::FIELD_COMPANY_RUNTIME_INVOCATION => self::FIELD_DEFERRED,
            ],
            self::FIELD_REQUIRED_GATE => self::FIELD_DEPARTMENT_ROUTE_OWNER_CONFIRMED,
        ],
        self::FIELD_SPEC => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_ROUTING,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_SPEC,
            self::FIELD_ACTOR_ID => 'aaeos.spec',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase4.spec.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => self::FIELD_R1_R2_FAST_PATH_PRESERVED,
            self::FIELD_OUTPUTS => [
                self::FIELD_SPEC_INVOCATION => self::FIELD_DEFERRED,
                self::FIELD_SPEC_REQUIRED => self::FIELD_YES,
            ],
            self::FIELD_REQUIRED_GATE => self::FIELD_SPEC_PACK_ACCEPTANCE_CRITERIA_MIN_3,
        ],
        self::FIELD_TASKS => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_SPEC,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_TASKS,
            self::FIELD_ACTOR_ID => 'aaeos.tasks',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase4.tasks.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => self::FIELD_R1_R2_FAST_PATH_PRESERVED,
            self::FIELD_OUTPUTS => [
                self::FIELD_TASK_PACK_INVOCATION => self::FIELD_DEFERRED,
                self::FIELD_TASK_PACK_REQUIRED => self::FIELD_YES,
            ],
            self::FIELD_REQUIRED_GATE => self::FIELD_TASK_PACK_ATOMIC_TRUE_FOR_EACH,
        ],
        self::FIELD_RECEIPT => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_TASKS,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_RECEIPT,
            self::FIELD_ACTOR_ID => self::FIELD_AAEOS_RECEIPT,
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase4.receipt.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => 'r1_r2_fast_path_preserved_legacy_trace_audit',
            self::FIELD_OUTPUTS => [
                self::FIELD_DECISION_RECEIPT_V2_INVOCATION => self::FIELD_DEFERRED,
                self::FIELD_RECEIPT_REQUIRED => self::FIELD_YES,
            ],
            self::FIELD_REQUIRED_GATE => self::FIELD_DECISION_RECEIPT_V2_SIGNED,
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function topology(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredPhase('topology', $intentId, $intentHash, $riskBand);
    }

    /**
     * @return array<string,mixed>
     */
    public function routing(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredPhase('routing', $intentId, $intentHash, $riskBand);
    }

    /**
     * @return array<string,mixed>
     */
    public function spec(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredPhase('spec', $intentId, $intentHash, $riskBand);
    }

    /**
     * @return array<string,mixed>
     */
    public function tasks(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredPhase('tasks', $intentId, $intentHash, $riskBand);
    }

    /**
     * @return array<string,mixed>
     */
    public function receipt(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredPhase('receipt', $intentId, $intentHash, $riskBand);
    }

    /**
     * True when the phase-advance classifier says the HTTP path must stop
     * (halt or block). Uses {@see PhaseAdvanceVerdictClassifier} so policy
     * halt, high-severity blockers, and missing decision tokens share one
     * precedence table instead of a single blocked-gate membership check.
     *
     * @param  array<string,mixed>  $policyEnvelope
     */
    public function policyGateBlocked(array $policyEnvelope): bool
    {
        $verdict = $this->phaseAdvance->classify($policyEnvelope)[self::FIELD_VERDICT] ?? '';

        return in_array($verdict, [PhaseAdvanceVerdictClassifier::VERDICT_HALT, PhaseAdvanceVerdictClassifier::VERDICT_BLOCK], true);
    }

    /**
     * @param  array<string,mixed>  $phaseEnvelope
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     reason: string,
     *     missing_gates: list<string>,
     *     blocked_gates: list<string>,
     *     high_blocker_ids: list<string>
     * }
     */
    public function phaseAdvanceVerdict(array $phaseEnvelope): array
    {
        return $this->phaseAdvance->classify($phaseEnvelope);
    }

    /**
     * Single-gate pass/block projection shared by HTTP-path phase envelopes.
     *
     * @return array{required: list<string>, passed: list<string>, blocked: list<string>}
     */
    private static function binaryGate(string $gate, bool $ok): array
    {
        return [
            self::FIELD_REQUIRED => [$gate],
            self::FIELD_PASSED => $ok ? [$gate] : [],
            self::FIELD_BLOCKED => $ok ? [] : [$gate],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function requestPayload(array $data): array
    {
        return self::arrayAt($data, self::FIELD_PAYLOAD);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function routerFromData(array $data): array
    {
        return self::arrayAt(self::requestPayload($data), self::FIELD_ATLAS_AI_ROUTER);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function assistedExecutionQuality(array $data): array
    {
        return self::arrayAt(self::requestPayload($data), self::FIELD_ATLAS_AI_ASSISTED_EXECUTION_QUALITY);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function arrayAt(array $data, string $key): array
    {
        return AiValueNormalizer::arrayOrEmpty($data[$key] ?? null);
    }

    /**
     * @param  array<string,mixed>  $assisted
     * @return list<array{id:string,severity:string,owner:string}>
     */
    private static function assistedExecutionBlockers(array $assisted, bool $isDevTarget, bool $allowed): array
    {
        if (! $isDevTarget || $allowed) {
            return [];
        }

        $blockers = [];
        foreach (AiValueNormalizer::arrayOrEmpty($assisted[self::FIELD_BLOCKERS] ?? null) as $blocker) {
            if (is_array($blocker)) {
                $id = AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_ID] ?? null);
                if ($id === null) {
                    continue;
                }
                $severity = AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_SEVERITY] ?? null) ?? self::FIELD_HIGH;
                $owner = AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_OWNER] ?? null) ?? self::FIELD_ATLAS_AI;
                $blockers[] = [
                    self::FIELD_ID => $id,
                    self::FIELD_SEVERITY => $severity !== '' ? $severity : 'high',
                    self::FIELD_OWNER => $owner !== '' ? $owner : self::FIELD_ATLAS_AI,
                ];
            } elseif (($id = AiValueNormalizer::trimmedStringOrNull($blocker)) !== null) {
                $blockers[] = [self::FIELD_ID => $id, self::FIELD_SEVERITY => self::FIELD_HIGH, self::FIELD_OWNER => self::FIELD_ATLAS_AI];
            }
        }

        if ($blockers === []) {
            $blockers[] = [
                self::FIELD_ID => self::FIELD_ASSISTED_EXECUTION_NEEDS_CONTEXT,
                self::FIELD_SEVERITY => self::FIELD_HIGH,
                self::FIELD_OWNER => self::FIELD_ATLAS_AI,
            ];
        }

        return $blockers;
    }

    /**
     * @param  array<string,mixed>  $placementResult
     * @return list<array{id:string,severity:string,owner:string}>
     */
    private static function blockedWhenAsBlockers(array $placementResult): array
    {
        $blockedWhen = AiValueNormalizer::arrayOrEmpty($placementResult[self::FIELD_BLOCKED_WHEN] ?? null);

        return array_values(array_map(static fn (string $reason): array => [
            self::FIELD_ID => $reason,
            self::FIELD_SEVERITY => self::FIELD_HIGH,
            self::FIELD_OWNER => self::FIELD_ATLAS_AI,
        ], array_filter($blockedWhen, self::FIELD_IS_STRING)));
    }

    /**
     * @return array<string,mixed>
     */
    private function deferredPhase(string $key, string $intentId, string $intentHash, string $riskBand): array
    {
        $spec = self::DEFERRED_PHASE_SPECS[$key] ?? null;
        if ($spec === null) {
            throw new \InvalidArgumentException("Unknown deferred HTTP-path phase: {$key}");
        }

        return $this->deferredOrFastPath(
            intentId: $intentId,
            intentHash: $intentHash,
            riskBand: $riskBand,
            phaseIn: $spec[self::FIELD_PHASE_IN],
            phaseOut: $spec[self::FIELD_PHASE_OUT],
            actorId: $spec[self::FIELD_ACTOR_ID],
            skipReceiptId: $spec[self::FIELD_SKIP_RECEIPT_ID],
            skipReason: $spec[self::FIELD_SKIP_REASON],
            outputs: $spec[self::FIELD_OUTPUTS],
            requiredGate: $spec[self::FIELD_REQUIRED_GATE],
        );
    }

    /**
     * @param  array<string,string>  $outputs
     * @return array<string,mixed>
     */
    private function deferredOrFastPath(
        string $intentId,
        string $intentHash,
        string $riskBand,
        string $phaseIn,
        string $phaseOut,
        string $actorId,
        string $skipReceiptId,
        string $skipReason,
        array $outputs,
        string $requiredGate,
    ): array {
        if ($riskBand === self::RISK_BAND_FAST_PATH) {
            return $this->handoff->skip(
                intentId: $intentId,
                phase: $phaseOut,
                receiptId: $skipReceiptId,
                reason: $skipReason,
            );
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: $phaseIn,
            phaseOut: $phaseOut,
            actor: self::systemActor($actorId),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [self::FIELD_RISK_BAND => $riskBand] + $outputs,
            gates: self::binaryGate($requiredGate, true),
        );
    }

    /**
     * @return array{kind: string, id: string, provider: null}
     */
    private static function systemActor(string $id): array
    {
        return [self::FIELD_KIND => self::FIELD_SYSTEM, self::FIELD_ID => $id, self::FIELD_PROVIDER => null];
    }
}
