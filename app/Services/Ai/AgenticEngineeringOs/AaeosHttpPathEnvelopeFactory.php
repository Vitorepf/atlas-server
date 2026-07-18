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
            actor: self::systemActor('aaeos.http_path_facade'),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [self::FIELD_INTENT_HASH => $intentHash, self::FIELD_INTENT_ID => $intentId],
            gates: self::binaryGate('surface_captured_intent', true),
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
            actor: self::systemActor('aaeos.mission_detection'),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_MISSION_SIGNAL_KIND => $suggestedMissionType,
                self::FIELD_MISSION_SHOULD_ACTIVATE => $shouldActivateMissionMode ? 'yes' : 'no',
            ],
            gates: self::binaryGate('intent_clarity_score_min_0_8', true),
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
            reason: 'mission_foundation_optional_at_phase_1',
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
            actor: self::systemActor('aaeos.placement'),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_PLACEMENT_LAYER => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_LAYER] ?? null) ?? self::STATUS_UNKNOWN,
                self::FIELD_PLACEMENT_DOMAIN => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_DOMAIN] ?? null) ?? self::STATUS_UNKNOWN,
                self::FIELD_PLACEMENT_FLOW => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_FLOW] ?? null) ?? self::STATUS_UNKNOWN,
                self::FIELD_GATE_STATUS => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_GATE_STATUS] ?? null) ?? self::STATUS_UNKNOWN,
            ],
            gates: self::binaryGate('placement_decision_feature_path_valid', $placementOk),
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
            actor: self::systemActor('aaeos.classification'),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                self::FIELD_FLOW_ID => $flowId,
                self::FIELD_COMMAND_INTENT => $commandIntent,
                'target_department_declared' => $declared ? 'yes' : 'no',
            ],
            gates: self::binaryGate('intent_classification_target_department_declared', $declared),
            blockers: $declared
                ? []
                : [['id' => 'classification_target_department_missing', self::FIELD_SEVERITY => 'medium', self::FIELD_OWNER => 'atlas-ai']],
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
        $isDevTarget = $target === 'atlas_dev';
        $status = AiValueNormalizer::trimmedStringOrNull($assisted[self::FIELD_STATUS] ?? null) ?? '';
        $allowed = $isDevTarget ? ($status === 'ready_for_assisted_execution') : true;

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            phaseOut: AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            actor: self::systemActor('aaeos.policy_gate'),
            inputs: [self::FIELD_INTENT_HASH => $intentHash],
            outputs: [
                'policy_target' => $target !== '' ? $target : 'none',
                'policy_status' => $status !== '' ? $status : 'not_required',
                self::FIELD_POLICY_ALLOWED => $allowed ? 'yes' : 'no',
            ],
            gates: self::binaryGate('policy_decision_allowed_true', $allowed),
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
        $routingTask = AiValueNormalizer::trimmedStringOrNull($payload['routing_task'] ?? null) ?? '';
        $flowId = AiValueNormalizer::trimmedStringOrNull(data_get($payload, 'atlas_ai_router.flow_id')) ?? '';

        if (in_array($intent, ['plan', 'forge', 'obra'], true) || in_array($routingTask, ['plan', 'forge', 'obra'], true)) {
            return self::RISK_BAND_R3_PLUS;
        }
        if ($flowId === 'programming.forge' || $flowId === 'atlas_forge') {
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
        'topology' => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            self::FIELD_ACTOR_ID => 'aaeos.topology',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase3.topology.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => 'r1_r2_fast_path_preserved',
            self::FIELD_OUTPUTS => [
                'topology_required' => 'yes',
                self::FIELD_AAWR_INVOCATION => 'deferred',
            ],
            self::FIELD_REQUIRED_GATE => 'topology_plan_providers_min_1_available',
        ],
        'routing' => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_ROUTING,
            self::FIELD_ACTOR_ID => 'aaeos.routing',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase3.routing.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => 'r1_r2_fast_path_preserved',
            self::FIELD_OUTPUTS => [
                self::FIELD_DEPARTMENT_ROUTE => 'engineering_or_forge_pending_aawr',
                self::FIELD_COMPANY_RUNTIME_INVOCATION => 'deferred',
            ],
            self::FIELD_REQUIRED_GATE => 'department_route_owner_confirmed',
        ],
        'spec' => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_ROUTING,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_SPEC,
            self::FIELD_ACTOR_ID => 'aaeos.spec',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase4.spec.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => 'r1_r2_fast_path_preserved',
            self::FIELD_OUTPUTS => [
                'spec_invocation' => 'deferred',
                'spec_required' => 'yes',
            ],
            self::FIELD_REQUIRED_GATE => 'spec_pack_acceptance_criteria_min_3',
        ],
        'tasks' => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_SPEC,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_TASKS,
            self::FIELD_ACTOR_ID => 'aaeos.tasks',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase4.tasks.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => 'r1_r2_fast_path_preserved',
            self::FIELD_OUTPUTS => [
                'task_pack_invocation' => 'deferred',
                'task_pack_required' => 'yes',
            ],
            self::FIELD_REQUIRED_GATE => 'task_pack_atomic_true_for_each',
        ],
        'receipt' => [
            self::FIELD_PHASE_IN => AaeosPhaseHandoffService::PHASE_TASKS,
            self::FIELD_PHASE_OUT => AaeosPhaseHandoffService::PHASE_RECEIPT,
            self::FIELD_ACTOR_ID => 'aaeos.receipt',
            self::FIELD_SKIP_RECEIPT_ID => 'rcpt:aaeos.phase4.receipt.r1_r2_fast_path',
            self::FIELD_SKIP_REASON => 'r1_r2_fast_path_preserved_legacy_trace_audit',
            self::FIELD_OUTPUTS => [
                self::FIELD_DECISION_RECEIPT_V2_INVOCATION => 'deferred',
                'receipt_required' => 'yes',
            ],
            self::FIELD_REQUIRED_GATE => 'decision_receipt_v2_signed',
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
        $verdict = $this->phaseAdvance->classify($policyEnvelope)['verdict'] ?? '';

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
            'required' => [$gate],
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
        return self::arrayAt($data, 'payload');
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function routerFromData(array $data): array
    {
        return self::arrayAt(self::requestPayload($data), 'atlas_ai_router');
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function assistedExecutionQuality(array $data): array
    {
        return self::arrayAt(self::requestPayload($data), 'atlas_ai_assisted_execution_quality');
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
                $id = AiValueNormalizer::trimmedStringOrNull($blocker['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $severity = AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_SEVERITY] ?? null) ?? 'high';
                $owner = AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_OWNER] ?? null) ?? 'atlas-ai';
                $blockers[] = [
                    'id' => $id,
                    self::FIELD_SEVERITY => $severity !== '' ? $severity : 'high',
                    self::FIELD_OWNER => $owner !== '' ? $owner : 'atlas-ai',
                ];
            } elseif (($id = AiValueNormalizer::trimmedStringOrNull($blocker)) !== null) {
                $blockers[] = ['id' => $id, self::FIELD_SEVERITY => 'high', self::FIELD_OWNER => 'atlas-ai'];
            }
        }

        if ($blockers === []) {
            $blockers[] = [
                'id' => 'assisted_execution_needs_context',
                self::FIELD_SEVERITY => 'high',
                self::FIELD_OWNER => 'atlas-ai',
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
            'id' => $reason,
            self::FIELD_SEVERITY => 'high',
            self::FIELD_OWNER => 'atlas-ai',
        ], array_filter($blockedWhen, 'is_string')));
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
        return [self::FIELD_KIND => 'system', 'id' => $id, 'provider' => null];
    }
}
