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
            inputs: ['intent_hash' => $intentHash],
            outputs: ['intent_hash' => $intentHash, 'intent_id' => $intentId],
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
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'mission_signal_kind' => $suggestedMissionType,
                'mission_should_activate' => $shouldActivateMissionMode ? 'yes' : 'no',
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
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'placement_layer' => AiValueNormalizer::trimmedString($placementResult['placement']['layer'] ?? 'unknown') ?: 'unknown',
                'placement_domain' => AiValueNormalizer::trimmedString($placementResult['placement']['domain'] ?? 'unknown') ?: 'unknown',
                'placement_flow' => AiValueNormalizer::trimmedString($placementResult['placement']['flow'] ?? 'unknown') ?: 'unknown',
                'gate_status' => AiValueNormalizer::trimmedString($placementResult['gate_status'] ?? 'unknown') ?: 'unknown',
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
        $flowId = AiValueNormalizer::trimmedStringOrNull($router['flow_id'] ?? null) ?? 'unknown';
        $commandIntent = AiValueNormalizer::trimmedStringOrNull($router['command_intent'] ?? null) ?? 'unknown';
        $declared = $flowId !== 'unknown';

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_PLACEMENT,
            phaseOut: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            actor: self::systemActor('aaeos.classification'),
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'flow_id' => $flowId,
                'command_intent' => $commandIntent,
                'target_department_declared' => $declared ? 'yes' : 'no',
            ],
            gates: self::binaryGate('intent_classification_target_department_declared', $declared),
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
        $assisted = self::assistedExecutionQuality($data);
        $target = AiValueNormalizer::trimmedString(data_get($assisted, 'route.target', ''));
        $isDevTarget = $target === 'atlas_dev';
        $status = AiValueNormalizer::trimmedString($assisted['status'] ?? '');
        $allowed = $isDevTarget ? ($status === 'ready_for_assisted_execution') : true;

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_CLASSIFICATION,
            phaseOut: AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            actor: self::systemActor('aaeos.policy_gate'),
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'policy_target' => $target !== '' ? $target : 'none',
                'policy_status' => $status !== '' ? $status : 'not_required',
                'policy_allowed' => $allowed ? 'yes' : 'no',
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
        $intent = AiValueNormalizer::trimmedString(data_get($payload, 'atlas_ai_router.command_intent') ?? '');
        $routingTask = AiValueNormalizer::trimmedString($payload['routing_task'] ?? '');
        $flowId = AiValueNormalizer::trimmedString(data_get($payload, 'atlas_ai_router.flow_id') ?? '');

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
    private const DEFERRED_PHASE_SPECS = [
        'topology' => [
            'phase_in' => AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            'phase_out' => AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            'actor_id' => 'aaeos.topology',
            'skip_receipt_id' => 'rcpt:aaeos.phase3.topology.r1_r2_fast_path',
            'skip_reason' => 'r1_r2_fast_path_preserved',
            'outputs' => [
                'topology_required' => 'yes',
                'aawr_invocation' => 'deferred',
            ],
            'required_gate' => 'topology_plan_providers_min_1_available',
        ],
        'routing' => [
            'phase_in' => AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            'phase_out' => AaeosPhaseHandoffService::PHASE_ROUTING,
            'actor_id' => 'aaeos.routing',
            'skip_receipt_id' => 'rcpt:aaeos.phase3.routing.r1_r2_fast_path',
            'skip_reason' => 'r1_r2_fast_path_preserved',
            'outputs' => [
                'department_route' => 'engineering_or_forge_pending_aawr',
                'company_runtime_invocation' => 'deferred',
            ],
            'required_gate' => 'department_route_owner_confirmed',
        ],
        'spec' => [
            'phase_in' => AaeosPhaseHandoffService::PHASE_ROUTING,
            'phase_out' => AaeosPhaseHandoffService::PHASE_SPEC,
            'actor_id' => 'aaeos.spec',
            'skip_receipt_id' => 'rcpt:aaeos.phase4.spec.r1_r2_fast_path',
            'skip_reason' => 'r1_r2_fast_path_preserved',
            'outputs' => [
                'spec_invocation' => 'deferred',
                'spec_required' => 'yes',
            ],
            'required_gate' => 'spec_pack_acceptance_criteria_min_3',
        ],
        'tasks' => [
            'phase_in' => AaeosPhaseHandoffService::PHASE_SPEC,
            'phase_out' => AaeosPhaseHandoffService::PHASE_TASKS,
            'actor_id' => 'aaeos.tasks',
            'skip_receipt_id' => 'rcpt:aaeos.phase4.tasks.r1_r2_fast_path',
            'skip_reason' => 'r1_r2_fast_path_preserved',
            'outputs' => [
                'task_pack_invocation' => 'deferred',
                'task_pack_required' => 'yes',
            ],
            'required_gate' => 'task_pack_atomic_true_for_each',
        ],
        'receipt' => [
            'phase_in' => AaeosPhaseHandoffService::PHASE_TASKS,
            'phase_out' => AaeosPhaseHandoffService::PHASE_RECEIPT,
            'actor_id' => 'aaeos.receipt',
            'skip_receipt_id' => 'rcpt:aaeos.phase4.receipt.r1_r2_fast_path',
            'skip_reason' => 'r1_r2_fast_path_preserved_legacy_trace_audit',
            'outputs' => [
                'decision_receipt_v2_invocation' => 'deferred',
                'receipt_required' => 'yes',
            ],
            'required_gate' => 'decision_receipt_v2_signed',
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

        return in_array($verdict, ['halt', 'block'], true);
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
            'passed' => $ok ? [$gate] : [],
            'blocked' => $ok ? [] : [$gate],
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
        foreach (AiValueNormalizer::arrayOrEmpty($assisted['blockers'] ?? null) as $blocker) {
            if (is_array($blocker)) {
                $id = AiValueNormalizer::trimmedStringOrNull($blocker['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $severity = AiValueNormalizer::trimmedString($blocker['severity'] ?? 'high');
                $owner = AiValueNormalizer::trimmedString($blocker['owner'] ?? 'atlas-ai');
                $blockers[] = [
                    'id' => $id,
                    'severity' => $severity !== '' ? $severity : 'high',
                    'owner' => $owner !== '' ? $owner : 'atlas-ai',
                ];
            } elseif (($id = AiValueNormalizer::trimmedStringOrNull($blocker)) !== null) {
                $blockers[] = ['id' => $id, 'severity' => 'high', 'owner' => 'atlas-ai'];
            }
        }

        if ($blockers === []) {
            $blockers[] = [
                'id' => 'assisted_execution_needs_context',
                'severity' => 'high',
                'owner' => 'atlas-ai',
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
        $blockedWhen = AiValueNormalizer::arrayOrEmpty($placementResult['blocked_when'] ?? null);

        return array_values(array_map(static fn (string $reason): array => [
            'id' => $reason,
            'severity' => 'high',
            'owner' => 'atlas-ai',
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
            phaseIn: $spec['phase_in'],
            phaseOut: $spec['phase_out'],
            actorId: $spec['actor_id'],
            skipReceiptId: $spec['skip_receipt_id'],
            skipReason: $spec['skip_reason'],
            outputs: $spec['outputs'],
            requiredGate: $spec['required_gate'],
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
            inputs: ['intent_hash' => $intentHash],
            outputs: ['risk_band' => $riskBand] + $outputs,
            gates: self::binaryGate($requiredGate, true),
        );
    }

    /**
     * @return array{kind: string, id: string, provider: null}
     */
    private static function systemActor(string $id): array
    {
        return ['kind' => 'system', 'id' => $id, 'provider' => null];
    }
}
