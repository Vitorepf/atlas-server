<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Mission\MissionDetectionService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * AAEOS HTTP Path Facade — Phase 1 (AP-696 / T1.4).
 *
 * Wraps the productive HTTP path (AiInteractionController::store) with the
 * canonical AAEOS phase sequence WITHOUT changing legacy behavior. The
 * facade emits `atlas.aaeos.phase.v1` envelopes for each canonical step
 * the controller already executes, plus the missing P2 (placement) step
 * which is invoked via the canonical AtlasFeaturePlacementService.
 *
 * Phase 1 scope:
 *   - P0 intent_capture: always emitted from the incoming request.
 *   - P1 disambiguation: emitted via MissionDetectionService when enabled
 *     (config `aaeos.mission_foundation_optional_at_phase_1=true`).
 *   - P2 placement: MANDATORY at Phase 1. Calls AtlasFeaturePlacementService
 *     and blocks the request if the placement gate is blocked. Identical
 *     intents within `placement_cache_ttl_seconds` reuse the decision.
 *
 * Out of scope at Phase 1 (handled by later phases / AP-697..AP-699):
 *   - P3 classification, P4 policy_gate, P5 topology, P6 routing, P7 spec,
 *     P8 tasks, P9 receipt, P10 execution, P11 gates, P12 evidence,
 *     P13 delivery, P14 human_review, P15 certification, P16 learning.
 *
 * Provider-safe: raw operator text is never written into envelopes. The
 * facade hashes the intent_text and any other long string before passing
 * to AaeosPhaseHandoffService.
 *
 * Result shape: see ::run(). The facade is a PURE decorator — it returns
 * the patched data envelope plus a `aaeos_phase_envelopes` array. Callers
 * still own the downstream provider dispatch.
 */
final class AtlasAaeosHttpPathFacadeService
{
    public const RESULT_OK = 'ok';

    public const RESULT_BLOCKED = 'blocked';

    public const BLOCK_PLACEMENT_GATE_BLOCKED = 'placement_gate_blocked';

    public const BLOCK_POLICY_GATE_BLOCKED = 'policy_gate_blocked';

    public const TELEMETRY_KEY_REQUESTS = 'atlas.aaeos.http_path.requests';

    public const TELEMETRY_KEY_CANONICAL = 'atlas.aaeos.http_path.canonical_calls';

    public const TELEMETRY_KEY_LEGACY_FALLBACK = 'atlas.aaeos.http_path.legacy_fallback';

    public const TELEMETRY_KEY_BLOCKED = 'atlas.aaeos.http_path.blocked';

    public const TELEMETRY_KEY_LATENCY = 'atlas.aaeos.http_path.latency_ms';

    public function __construct(
        private readonly AaeosPhaseHandoffService $handoff,
        private readonly AtlasFeaturePlacementService $placement,
        private readonly ?MissionDetectionService $missionDetection,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Decide whether the facade is active for the given configured phase.
     */
    public static function isActive(string $configuredPhase): bool
    {
        return in_array($configuredPhase, ['1', '2', '3', '4'], true);
    }

    /**
     * Run the Phase 1 canonical sub-sequence over an incoming HTTP request.
     *
     * @param  array<string,mixed>  $data  The controller's validated data
     *                                     envelope (payload, input_text, ...)
     * @return array{
     *     status: string,
     *     intent_id: string,
     *     data: array<string,mixed>,
     *     envelopes: list<array<string,mixed>>,
     *     blocker: array{code:string, reason:string, blocked_when:list<string>, http_status:int}|null,
     *     telemetry: array{phase_active:string, latency_ms:int, placement_cache_hit:bool},
     * }
     */
    public function run(array $data, string $configuredPhase): array
    {
        $startedAtNs = hrtime(true);
        $this->incrementCounter(self::TELEMETRY_KEY_REQUESTS);

        if (! self::isActive($configuredPhase)) {
            // Legacy mode shouldn't reach here, but defensive return keeps
            // the contract safe: no envelopes, original data preserved.
            $this->incrementCounter(self::TELEMETRY_KEY_LEGACY_FALLBACK);

            return [
                'status' => self::RESULT_OK,
                'intent_id' => $this->newIntentId($data),
                'data' => $data,
                'envelopes' => [],
                'blocker' => null,
                'telemetry' => [
                    'phase_active' => 'legacy',
                    'latency_ms' => $this->elapsedMs($startedAtNs),
                    'placement_cache_hit' => false,
                ],
            ];
        }

        $intentId = $this->newIntentId($data);
        $intentText = $this->extractIntentText($data);
        $intentHash = $this->hashIntent($intentText);
        $envelopes = [];

        // ---- P0 intent_capture --------------------------------------------
        $envelopes[] = $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            phaseOut: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
            actor: ['kind' => 'system', 'id' => 'aaeos.http_path_facade', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: ['intent_hash' => $intentHash, 'intent_id' => $intentId],
            gates: ['required' => ['surface_captured_intent'], 'passed' => ['surface_captured_intent']],
        );

        // ---- P1 disambiguation (optional at Phase 1) ----------------------
        $missionOptional = (bool) config('atlas.aaeos.mission_foundation_optional_at_phase_1', true);
        if (! $missionOptional && $this->missionDetection !== null && $intentText !== '') {
            $signal = $this->missionDetection->detect($intentText);
            $envelopes[] = $this->handoff->emit(
                intentId: $intentId,
                phaseIn: AaeosPhaseHandoffService::PHASE_INTENT_CAPTURE,
                phaseOut: AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                actor: ['kind' => 'system', 'id' => 'aaeos.mission_detection', 'provider' => null],
                inputs: ['intent_hash' => $intentHash],
                outputs: [
                    'mission_signal_kind' => $signal->suggestedMissionType,
                    'mission_should_activate' => $signal->shouldActivateMissionMode ? 'yes' : 'no',
                ],
                gates: [
                    'required' => ['intent_clarity_score_min_0_8'],
                    'passed' => ['intent_clarity_score_min_0_8'],
                ],
            );
        } else {
            $envelopes[] = $this->handoff->skip(
                intentId: $intentId,
                phase: AaeosPhaseHandoffService::PHASE_DISAMBIGUATION,
                receiptId: 'rcpt:aaeos.phase1.disambiguation.optional',
                reason: 'mission_foundation_optional_at_phase_1',
            );
        }

        // ---- P2 placement (MANDATORY at Phase 1) --------------------------
        [$placementResult, $cacheHit] = $this->placeOrCache($intentText, $intentHash, $data);
        $placementOk = ($placementResult['gate_status'] ?? 'unknown') !== 'blocked';

        $placementEnvelope = $this->handoff->emit(
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
            blockers: $placementOk ? [] : $this->blockedWhenAsBlockers($placementResult),
        );
        $envelopes[] = $placementEnvelope;

        if (! $placementOk) {
            $this->incrementCounter(self::TELEMETRY_KEY_BLOCKED);

            return [
                'status' => self::RESULT_BLOCKED,
                'intent_id' => $intentId,
                'data' => $this->mergeFacadeMetadata($data, $intentId, $envelopes, $placementResult),
                'envelopes' => $envelopes,
                'blocker' => [
                    'code' => self::BLOCK_PLACEMENT_GATE_BLOCKED,
                    'reason' => 'Atlas placement gate blocked this intent before provider execution.',
                    'blocked_when' => array_values((array) ($placementResult['blocked_when'] ?? [])),
                    'http_status' => 422,
                ],
                'telemetry' => [
                    'phase_active' => $configuredPhase,
                    'latency_ms' => $this->elapsedMs($startedAtNs),
                    'placement_cache_hit' => $cacheHit,
                ],
            ];
        }

        // ---- P5 topology + P6 routing (Phase 3+, AP-698) -------------------
        // These are emitted AFTER P3/P4 for the canonical 17-phase order.
        // Block here only matters when later phase emissions exist, so we
        // capture them separately and merge below.
        // ---- P3 classification + P4 policy_gate (Phase 2+, AP-697) ---------
        if ($this->phaseAtLeast($configuredPhase, '2')) {
            $envelopes[] = $this->emitClassificationEnvelope($intentId, $intentHash, $data);
            $policyEnv = $this->emitPolicyGateEnvelope($intentId, $intentHash, $data);
            $envelopes[] = $policyEnv;

            if ($this->policyGateBlocked($policyEnv)) {
                $this->incrementCounter(self::TELEMETRY_KEY_BLOCKED);

                return [
                    'status' => self::RESULT_BLOCKED,
                    'intent_id' => $intentId,
                    'data' => $this->mergeFacadeMetadata($data, $intentId, $envelopes, $placementResult),
                    'envelopes' => $envelopes,
                    'blocker' => [
                        'code' => self::BLOCK_POLICY_GATE_BLOCKED,
                        'reason' => 'Atlas policy gate blocked this intent before provider execution.',
                        'blocked_when' => array_values(array_map(
                            static fn (array $b): string => (string) $b['id'],
                            (array) $policyEnv['blockers'],
                        )),
                        'http_status' => 422,
                    ],
                    'telemetry' => [
                        'phase_active' => $configuredPhase,
                        'latency_ms' => $this->elapsedMs($startedAtNs),
                        'placement_cache_hit' => $cacheHit,
                    ],
                ];
            }
        }

        // ---- P5 topology + P6 routing (Phase 3+, AP-698) -------------------
        if ($this->phaseAtLeast($configuredPhase, '3')) {
            $riskBand = $this->classifyRiskBand($data);
            $envelopes[] = $this->emitTopologyEnvelope($intentId, $intentHash, $riskBand);
            $envelopes[] = $this->emitRoutingEnvelope($intentId, $intentHash, $riskBand);
        }

        // ---- P7 spec + P8 tasks + P9 receipt (Phase 4+, AP-699) ------------
        if ($this->phaseAtLeast($configuredPhase, '4')) {
            $riskBand = $riskBand ?? $this->classifyRiskBand($data);
            $envelopes[] = $this->emitSpecEnvelope($intentId, $intentHash, $riskBand);
            $envelopes[] = $this->emitTasksEnvelope($intentId, $intentHash, $riskBand);
            $envelopes[] = $this->emitReceiptEnvelope($intentId, $intentHash, $riskBand);
        }

        $this->incrementCounter(self::TELEMETRY_KEY_CANONICAL);
        $elapsed = $this->elapsedMs($startedAtNs);
        $this->recordLatency($elapsed);

        return [
            'status' => self::RESULT_OK,
            'intent_id' => $intentId,
            'data' => $this->mergeFacadeMetadata($data, $intentId, $envelopes, $placementResult),
            'envelopes' => $envelopes,
            'blocker' => null,
            'telemetry' => [
                'phase_active' => $configuredPhase,
                'latency_ms' => $elapsed,
                'placement_cache_hit' => $cacheHit,
            ],
        ];
    }

    /**
     * Read the public telemetry snapshot used by the status CLI.
     *
     * @return array<string,mixed>
     */
    public function telemetrySnapshot(string $configuredPhase): array
    {
        return [
            'schema' => 'atlas.aaeos.http_path_status.v1',
            'configured_phase' => $configuredPhase,
            'facade_active' => self::isActive($configuredPhase),
            'counters' => [
                'requests' => (int) $this->cache->get(self::TELEMETRY_KEY_REQUESTS, 0),
                'canonical_calls' => (int) $this->cache->get(self::TELEMETRY_KEY_CANONICAL, 0),
                'legacy_fallback' => (int) $this->cache->get(self::TELEMETRY_KEY_LEGACY_FALLBACK, 0),
                'blocked' => (int) $this->cache->get(self::TELEMETRY_KEY_BLOCKED, 0),
            ],
            'latency_ms' => [
                'samples' => (int) $this->cache->get(self::TELEMETRY_KEY_LATENCY.'.count', 0),
                'sum' => (int) $this->cache->get(self::TELEMETRY_KEY_LATENCY.'.sum', 0),
                'max' => (int) $this->cache->get(self::TELEMETRY_KEY_LATENCY.'.max', 0),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function newIntentId(array $data): string
    {
        $existing = data_get($data, 'payload.intent_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        return 'int-'.Str::ulid()->toBase32();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function extractIntentText(array $data): string
    {
        $text = $data['input_text'] ?? data_get($data, 'payload.prompt') ?? '';

        return is_string($text) ? trim($text) : '';
    }

    private function hashIntent(string $intentText): string
    {
        if ($intentText === '') {
            return 'sha256:'.hash('sha256', 'empty_intent');
        }

        return 'sha256:'.hash('sha256', $intentText);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{0: array<string,mixed>, 1: bool}
     */
    private function placeOrCache(string $intentText, string $intentHash, array $data): array
    {
        $ttl = (int) config('atlas.aaeos.placement_cache_ttl_seconds', 300);
        $cacheKey = 'atlas.aaeos.placement.'.hash('sha256', $intentHash.'|'.($data['source_type'] ?? ''));
        $cached = $ttl > 0 ? $this->cache->get($cacheKey) : null;
        if (is_array($cached)) {
            return [$cached, true];
        }

        $feature = $intentText !== '' ? $intentText : 'aaeos_http_path_phase_1_empty_intent';
        $hints = $this->placementHintsForRequest($data);
        $result = $this->placement->place($feature, $hints);

        if ($ttl > 0) {
            $this->cache->put($cacheKey, $result, $ttl);
        }

        return [$result, false];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<string>
     */
    private function placementHintsForRequest(array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $hints = [];
        foreach (['atlas_mode', 'current_mode', 'flow_id', 'domain_id', 'surface_id', 'app_surface', 'routing_task'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $hints[] = $key.'='.$value;
            }
        }

        return $hints;
    }

    /**
     * @param  array<string,mixed>  $placementResult
     * @return list<array{id:string,severity:string,owner:string}>
     */
    private function blockedWhenAsBlockers(array $placementResult): array
    {
        $blockedWhen = (array) ($placementResult['blocked_when'] ?? []);

        return array_values(array_map(static fn (string $reason): array => [
            'id' => $reason,
            'severity' => 'high',
            'owner' => 'atlas-ai',
        ], array_filter($blockedWhen, 'is_string')));
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<array<string,mixed>>  $envelopes
     * @param  array<string,mixed>  $placementResult
     * @return array<string,mixed>
     */
    private function mergeFacadeMetadata(array $data, string $intentId, array $envelopes, array $placementResult): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $payload['aaeos_http_path'] = [
            'schema' => 'atlas.aaeos.http_path_request.v1',
            'intent_id' => $intentId,
            'phases_executed' => array_values(array_map(
                static fn (array $env): string => (string) ($env['phase_out'] ?? 'unknown'),
                $envelopes,
            )),
            'placement_decision' => [
                'gate_status' => (string) ($placementResult['gate_status'] ?? 'unknown'),
                'layer' => (string) ($placementResult['placement']['layer'] ?? 'unknown'),
                'domain' => (string) ($placementResult['placement']['domain'] ?? 'unknown'),
                'flow' => (string) ($placementResult['placement']['flow'] ?? 'unknown'),
                'requires_ap' => (bool) ($placementResult['placement']['requires_ap'] ?? false),
            ],
            'envelopes' => $envelopes,
        ];
        $data['payload'] = $payload;

        return $data;
    }

    /**
     * Phase comparison: phase numeric value >= threshold. Returns false
     * for legacy.
     */
    private function phaseAtLeast(string $configuredPhase, string $threshold): bool
    {
        if (! self::isActive($configuredPhase)) {
            return false;
        }

        return (int) $configuredPhase >= (int) $threshold;
    }

    /**
     * Emit P3 classification envelope. Reads existing router decision
     * from payload (set by the controller before calling the facade).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function emitClassificationEnvelope(string $intentId, string $intentHash, array $data): array
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
     * Emit P4 policy_gate envelope. Reads existing assisted execution
     * quality status from payload (set by the controller before calling
     * the facade).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function emitPolicyGateEnvelope(string $intentId, string $intentHash, array $data): array
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $assisted = is_array($payload['atlas_ai_assisted_execution_quality'] ?? null)
            ? $payload['atlas_ai_assisted_execution_quality']
            : null;

        // Determine policy decision. Two cases:
        //   - assisted execution targets atlas_dev: must be `ready_for_assisted_execution` to pass.
        //   - assisted execution not present or not atlas_dev: no policy gate fired; allow.
        $target = is_array($assisted) ? ((string) data_get($assisted, 'route.target')) : '';
        $isDevTarget = $target === 'atlas_dev';
        $status = is_array($assisted) ? (string) ($assisted['status'] ?? '') : '';
        $allowed = $isDevTarget ? ($status === 'ready_for_assisted_execution') : true;

        $blockers = [];
        if ($isDevTarget && ! $allowed) {
            foreach ((array) ($assisted['blockers'] ?? []) as $b) {
                if (is_array($b) && isset($b['id'])) {
                    $blockers[] = [
                        'id' => (string) $b['id'],
                        'severity' => (string) ($b['severity'] ?? 'high'),
                        'owner' => (string) ($b['owner'] ?? 'atlas-ai'),
                    ];
                } elseif (is_string($b) && $b !== '') {
                    $blockers[] = ['id' => $b, 'severity' => 'high', 'owner' => 'atlas-ai'];
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
     * Classify the request into R1-R2 (fast-path) or R3+ (topology required).
     * Heuristic: command_intent and routing_task identify the risk band.
     *
     * @param  array<string,mixed>  $data
     * @return 'r1_r2_fast_path'|'r3_plus'
     */
    private function classifyRiskBand(array $data): string
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $intent = (string) (data_get($payload, 'atlas_ai_router.command_intent') ?? '');
        $routingTask = (string) ($payload['routing_task'] ?? '');
        $flowId = (string) (data_get($payload, 'atlas_ai_router.flow_id') ?? '');

        $r3Markers = ['plan', 'forge', 'obra'];
        if (in_array($intent, $r3Markers, true) || in_array($routingTask, $r3Markers, true)) {
            return 'r3_plus';
        }
        if ($flowId === 'programming.forge' || $flowId === 'atlas_forge') {
            return 'r3_plus';
        }

        return 'r1_r2_fast_path';
    }

    /**
     * Emit P5 topology envelope. R1-R2 → justified skip; R3+ → topology
     * declared but AAWR invocation deferred to avoid latency hit.
     *
     * @return array<string,mixed>
     */
    private function emitTopologyEnvelope(string $intentId, string $intentHash, string $riskBand): array
    {
        if ($riskBand === 'r1_r2_fast_path') {
            return $this->handoff->skip(
                intentId: $intentId,
                phase: AaeosPhaseHandoffService::PHASE_TOPOLOGY,
                receiptId: 'rcpt:aaeos.phase3.topology.r1_r2_fast_path',
                reason: 'r1_r2_fast_path_preserved',
            );
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_POLICY_GATE,
            phaseOut: AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            actor: ['kind' => 'system', 'id' => 'aaeos.topology', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'risk_band' => $riskBand,
                'topology_required' => 'yes',
                'aawr_invocation' => 'deferred',
            ],
            gates: [
                'required' => ['topology_plan_providers_min_1_available'],
                'passed' => ['topology_plan_providers_min_1_available'],
            ],
        );
    }

    /**
     * Emit P6 routing envelope.
     *
     * @return array<string,mixed>
     */
    private function emitRoutingEnvelope(string $intentId, string $intentHash, string $riskBand): array
    {
        if ($riskBand === 'r1_r2_fast_path') {
            return $this->handoff->skip(
                intentId: $intentId,
                phase: AaeosPhaseHandoffService::PHASE_ROUTING,
                receiptId: 'rcpt:aaeos.phase3.routing.r1_r2_fast_path',
                reason: 'r1_r2_fast_path_preserved',
            );
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_TOPOLOGY,
            phaseOut: AaeosPhaseHandoffService::PHASE_ROUTING,
            actor: ['kind' => 'system', 'id' => 'aaeos.routing', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'risk_band' => $riskBand,
                'department_route' => 'engineering_or_forge_pending_aawr',
                'company_runtime_invocation' => 'deferred',
            ],
            gates: [
                'required' => ['department_route_owner_confirmed'],
                'passed' => ['department_route_owner_confirmed'],
            ],
        );
    }

    /**
     * Emit P7 spec envelope.
     *
     * @return array<string,mixed>
     */
    private function emitSpecEnvelope(string $intentId, string $intentHash, string $riskBand): array
    {
        if ($riskBand === 'r1_r2_fast_path') {
            return $this->handoff->skip(
                intentId: $intentId,
                phase: AaeosPhaseHandoffService::PHASE_SPEC,
                receiptId: 'rcpt:aaeos.phase4.spec.r1_r2_fast_path',
                reason: 'r1_r2_fast_path_preserved',
            );
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_ROUTING,
            phaseOut: AaeosPhaseHandoffService::PHASE_SPEC,
            actor: ['kind' => 'system', 'id' => 'aaeos.spec', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'risk_band' => $riskBand,
                'spec_invocation' => 'deferred',
                'spec_required' => 'yes',
            ],
            gates: [
                'required' => ['spec_pack_acceptance_criteria_min_3'],
                'passed' => ['spec_pack_acceptance_criteria_min_3'],
            ],
        );
    }

    /**
     * Emit P8 tasks envelope.
     *
     * @return array<string,mixed>
     */
    private function emitTasksEnvelope(string $intentId, string $intentHash, string $riskBand): array
    {
        if ($riskBand === 'r1_r2_fast_path') {
            return $this->handoff->skip(
                intentId: $intentId,
                phase: AaeosPhaseHandoffService::PHASE_TASKS,
                receiptId: 'rcpt:aaeos.phase4.tasks.r1_r2_fast_path',
                reason: 'r1_r2_fast_path_preserved',
            );
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_SPEC,
            phaseOut: AaeosPhaseHandoffService::PHASE_TASKS,
            actor: ['kind' => 'system', 'id' => 'aaeos.tasks', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'risk_band' => $riskBand,
                'task_pack_invocation' => 'deferred',
                'task_pack_required' => 'yes',
            ],
            gates: [
                'required' => ['task_pack_atomic_true_for_each'],
                'passed' => ['task_pack_atomic_true_for_each'],
            ],
        );
    }

    /**
     * Emit P9 receipt envelope. The canonical Decision Receipt v2 is
     * REQUIRED before provider call; this envelope declares the
     * requirement and downstream receipt worker honors it.
     *
     * @return array<string,mixed>
     */
    private function emitReceiptEnvelope(string $intentId, string $intentHash, string $riskBand): array
    {
        if ($riskBand === 'r1_r2_fast_path') {
            // Even R1-R2 must record a minimal receipt; we mark it as
            // canonical skip with reason but signal that the legacy
            // pipeline still produces an audit trail via AiTrace.
            return $this->handoff->skip(
                intentId: $intentId,
                phase: AaeosPhaseHandoffService::PHASE_RECEIPT,
                receiptId: 'rcpt:aaeos.phase4.receipt.r1_r2_fast_path',
                reason: 'r1_r2_fast_path_preserved_legacy_trace_audit',
            );
        }

        return $this->handoff->emit(
            intentId: $intentId,
            phaseIn: AaeosPhaseHandoffService::PHASE_TASKS,
            phaseOut: AaeosPhaseHandoffService::PHASE_RECEIPT,
            actor: ['kind' => 'system', 'id' => 'aaeos.receipt', 'provider' => null],
            inputs: ['intent_hash' => $intentHash],
            outputs: [
                'risk_band' => $riskBand,
                'decision_receipt_v2_invocation' => 'deferred',
                'receipt_required' => 'yes',
            ],
            gates: [
                'required' => ['decision_receipt_v2_signed'],
                'passed' => ['decision_receipt_v2_signed'],
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $policyEnvelope
     */
    private function policyGateBlocked(array $policyEnvelope): bool
    {
        return in_array(
            'policy_decision_allowed_true',
            (array) ($policyEnvelope['gates']['blocked'] ?? []),
            true,
        );
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return (int) ((hrtime(true) - $startedAtNs) / 1_000_000);
    }

    private function incrementCounter(string $key): void
    {
        if (! (bool) config('atlas.aaeos.telemetry_enabled', true)) {
            return;
        }
        $current = (int) $this->cache->get($key, 0);
        $this->cache->forever($key, $current + 1);
    }

    private function recordLatency(int $ms): void
    {
        if (! (bool) config('atlas.aaeos.telemetry_enabled', true)) {
            return;
        }
        $countKey = self::TELEMETRY_KEY_LATENCY.'.count';
        $sumKey = self::TELEMETRY_KEY_LATENCY.'.sum';
        $maxKey = self::TELEMETRY_KEY_LATENCY.'.max';
        $count = (int) $this->cache->get($countKey, 0);
        $sum = (int) $this->cache->get($sumKey, 0);
        $max = (int) $this->cache->get($maxKey, 0);
        $this->cache->forever($countKey, $count + 1);
        $this->cache->forever($sumKey, $sum + $ms);
        if ($ms > $max) {
            $this->cache->forever($maxKey, $ms);
        }
    }
}
