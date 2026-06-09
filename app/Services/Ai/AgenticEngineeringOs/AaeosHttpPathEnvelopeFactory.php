<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

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

    public function __construct(private readonly AaeosPhaseHandoffService $handoff) {}

    /**
     * @return array<string,mixed>
     */
    public function intentCapture(string $intentId, string $intentHash): array
    {
        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            phaseOut: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            actor: ['kind' => 'system', 'id' => 'aaeos.http_path_facade', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: ['intent_hash' => $intentHash, 'intent_id' => $intentId],
            gates: ['required' => ['surface_captured_intent'], 'passed' => ['surface_captured_intent']],
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
            actor: ['kind' => 'system', 'id' => 'aaeos.mission_detection', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'mission_signal_kind' => $suggestedMissionType,
                'mission_should_activate' => $shouldActivateMissionMode ? 'yes' : 'no',
            ],
            gates: [
                'required' => ['intent_clarity_score_min_0_8'],
                'passed' => ['intent_clarity_score_min_0_8'],
            ],
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
            actor: ['kind' => 'system', 'id' => 'aaeos.placement', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'placement_layer' => (string) ($placementResult['placement']['layer'] ?? 'unknown'),
                'placement_domain' => (string) ($placementResult['placement']['domain'] ?? 'unknown'),
                'placement_flow' => (string) ($placementResult['placement']['flow'] ?? 'unknown'),
                'gate_status' => (string) ($placementResult['gate_status'] ?? 'unknown'),
            ],
            gates: [
                'required' => ['placement_decision_feature_path_valid'],
                'passed' => $placementOk ? ['placement_decision_feature_path_valid'] : [],
                'blocked' => $placementOk ? [] : ['placement_decision_feature_path_valid'],
            ],
            blockers: $placementOk ? [] : self::blockedWhenAsBlockers($placementResult),
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function classification(string $intentId, string $intentHash, array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $router = is_array($payload['atlas_ai_router'] ?? null) ? $payload['atlas_ai_router'] : [];
        $flowId = is_string($router['flow_id'] ?? null) && $router['flow_id'] !== ''
            ? (string) $router['flow_id']
            : 'unknown';
        $commandIntent = is_string($router['command_intent'] ?? null) && $router['command_intent'] !== ''
            ? (string) $router['command_intent']
            : 'unknown';
        $declared = $flowId !== 'unknown';

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_PLACEMENT,
            phaseOut: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            actor: ['kind' => 'system', 'id' => 'aaeos.classification', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'flow_id' => $flowId,
                'command_intent' => $commandIntent,
                'target_department_declared' => $declared ? 'yes' : 'no',
            ],
            gates: [
                'required' => ['intent_classification_target_department_declared'],
                'passed' => $declared ? ['intent_classification_target_department_declared'] : [],
                'blocked' => $declared ? [] : ['intent_classification_target_department_declared'],
            ],
            blockers: $declared
                ? []
                : [['id' => 'classification_target_department_missing', 'severity' => 'medium', 'owner' => 'atlas-ai']],
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function policyGate(string $intentId, string $intentHash, array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $assisted = is_array($payload['atlas_ai_assisted_execution_quality'] ?? null)
            ? $payload['atlas_ai_assisted_execution_quality']
            : null;

        $target = is_array($assisted) ? ((string) data_get($assisted, 'route.target')) : '';
        $isDevTarget = $target === 'atlas_dev';
        $status = is_array($assisted) ? (string) ($assisted['status'] ?? '') : '';
        $allowed = $isDevTarget ? ($status === 'ready_for_assisted_execution') : true;

        $blockers = [];
        if ($isDevTarget && ! $allowed) {
            foreach ((array) ($assisted['blockers'] ?? []) as $blocker) {
                if (is_array($blocker) && isset($blocker['id'])) {
                    $blockers[] = [
                        'id' => (string) $blocker['id'],
                        'severity' => (string) ($blocker['severity'] ?? 'high'),
                        'owner' => (string) ($blocker['owner'] ?? 'atlas-ai'),
                    ];
                } elseif (is_string($blocker) && $blocker !== '') {
                    $blockers[] = ['id' => $blocker, 'severity' => 'high', 'owner' => 'atlas-ai'];
                }
            }
            if ($blockers === []) {
                $blockers[] = [
                    'id' => 'assisted_execution_needs_context',
                    'severity' => 'high',
                    'owner' => 'atlas-ai',
                ];
            }
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            phaseOut: AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            actor: ['kind' => 'system', 'id' => 'aaeos.policy_gate', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'policy_target' => $target !== '' ? $target : 'none',
                'policy_status' => $status !== '' ? $status : 'not_required',
                'policy_allowed' => $allowed ? 'yes' : 'no',
            ],
            gates: [
                'required' => ['policy_decision_allowed_true'],
                'passed' => $allowed ? ['policy_decision_allowed_true'] : [],
                'blocked' => $allowed ? [] : ['policy_decision_allowed_true'],
            ],
            blockers: $blockers,
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return self::RISK_BAND_*
     */
    public function riskBand(array $data): string
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $intent = (string) (data_get($payload, 'atlas_ai_router.command_intent') ?? '');
        $routingTask = (string) ($payload['routing_task'] ?? '');
        $flowId = (string) (data_get($payload, 'atlas_ai_router.flow_id') ?? '');

        if (in_array($intent, ['plan', 'forge', 'obra'], true) || in_array($routingTask, ['plan', 'forge', 'obra'], true)) {
            return self::RISK_BAND_R3_PLUS;
        }
        if ($flowId === 'programming.forge' || $flowId === 'atlas_forge') {
            return self::RISK_BAND_R3_PLUS;
        }

        return self::RISK_BAND_FAST_PATH;
    }

    /**
     * @return array<string,mixed>
     */
    public function topology(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredOrFastPath(
            intentId: $intentId,
            intentHash: $intentHash,
            riskBand: $riskBand,
            phaseIn: AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            phaseOut: AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            actorId: 'aaeos.topology',
            skipReceiptId: 'rcpt:aaeos.phase3.topology.r1_r2_fast_path',
            skipReason: 'r1_r2_fast_path_preserved',
            outputs: [
                'topology_required' => 'yes',
                'aawr_invocation' => 'deferred',
            ],
            requiredGate: 'topology_plan_providers_min_1_available',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function routing(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredOrFastPath(
            intentId: $intentId,
            intentHash: $intentHash,
            riskBand: $riskBand,
            phaseIn: AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            phaseOut: AaeosPhaseHandoffService::PHASE_ROUTING,
            actorId: 'aaeos.routing',
            skipReceiptId: 'rcpt:aaeos.phase3.routing.r1_r2_fast_path',
            skipReason: 'r1_r2_fast_path_preserved',
            outputs: [
                'department_route' => 'engineering_or_forge_pending_aawr',
                'company_runtime_invocation' => 'deferred',
            ],
            requiredGate: 'department_route_owner_confirmed',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function spec(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredOrFastPath(
            intentId: $intentId,
            intentHash: $intentHash,
            riskBand: $riskBand,
            phaseIn: AaeosPhaseHandoffService::PHASE_ROUTING,
            phaseOut: AaeosPhaseHandoffService::PHASE_SPEC,
            actorId: 'aaeos.spec',
            skipReceiptId: 'rcpt:aaeos.phase4.spec.r1_r2_fast_path',
            skipReason: 'r1_r2_fast_path_preserved',
            outputs: [
                'spec_invocation' => 'deferred',
                'spec_required' => 'yes',
            ],
            requiredGate: 'spec_pack_acceptance_criteria_min_3',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function tasks(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredOrFastPath(
            intentId: $intentId,
            intentHash: $intentHash,
            riskBand: $riskBand,
            phaseIn: AaeosPhaseHandoffService::PHASE_SPEC,
            phaseOut: AaeosPhaseHandoffService::PHASE_TASKS,
            actorId: 'aaeos.tasks',
            skipReceiptId: 'rcpt:aaeos.phase4.tasks.r1_r2_fast_path',
            skipReason: 'r1_r2_fast_path_preserved',
            outputs: [
                'task_pack_invocation' => 'deferred',
                'task_pack_required' => 'yes',
            ],
            requiredGate: 'task_pack_atomic_true_for_each',
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function receipt(string $intentId, string $intentHash, string $riskBand): array
    {
        return $this->deferredOrFastPath(
            intentId: $intentId,
            intentHash: $intentHash,
            riskBand: $riskBand,
            phaseIn: AaeosPhaseHandoffService::PHASE_TASKS,
            phaseOut: AaeosPhaseHandoffService::PHASE_RECEIPT,
            actorId: 'aaeos.receipt',
            skipReceiptId: 'rcpt:aaeos.phase4.receipt.r1_r2_fast_path',
            skipReason: 'r1_r2_fast_path_preserved_legacy_trace_audit',
            outputs: [
                'decision_receipt_v2_invocation' => 'deferred',
                'receipt_required' => 'yes',
            ],
            requiredGate: 'decision_receipt_v2_signed',
        );
    }

    /**
     * @param  array<string,mixed>  $policyEnvelope
     */
    public static function policyGateBlocked(array $policyEnvelope): bool
    {
        return in_array(
            'policy_decision_allowed_true',
            (array) ($policyEnvelope['gates']['blocked'] ?? []),
            true,
        );
    }

    /**
     * @param  array<string,mixed>  $placementResult
     * @return list<array{id:string,severity:string,owner:string}>
     */
    private static function blockedWhenAsBlockers(array $placementResult): array
    {
        $blockedWhen = (array) ($placementResult['blocked_when'] ?? []);

        return array_values(array_map(static fn (string $reason): array => [
            'id' => $reason,
            'severity' => 'high',
            'owner' => 'atlas-ai',
        ], array_filter($blockedWhen, 'is_string')));
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
            actor: ['kind' => 'system', 'id' => $actorId, 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: ['risk_band' => $riskBand] + $outputs,
            gates: [
                'required' => [$requiredGate],
                'passed' => [$requiredGate],
            ],
        );
    }
}
