<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasPhaseRouterService;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Mission\MissionDetectionService;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * AAEOS HTTP Path Facade — phase-gated decorator (AP-696..AP-699 / T1.4).
 *
 * Wraps the productive HTTP path (AiInteractionController::store) with the
 * canonical AAEOS phase sequence WITHOUT changing legacy behavior. The
 * facade emits `atlas.aaeos.phase.v1` envelopes for each canonical step
 * the controller already executes, plus the missing P2 (placement) step
 * which is invoked via the canonical AtlasFeaturePlacementService.
 *
 * Live phase scope:
 *   - P0 intent_capture: always emitted from the incoming request.
 *   - P1 disambiguation: emitted via MissionDetectionService when enabled
 *     (config `aaeos.mission_foundation_optional_at_phase_1=true`).
 *   - P2 placement: MANDATORY at Phase 1. Calls AtlasFeaturePlacementService
 *     and blocks the request if the placement gate is blocked. Identical
 *     intents within `placement_cache_ttl_seconds` reuse the decision.
 *   - P3/P4 when `http_path_phase >= 2`: classification + policy gate.
 *   - P5/P6 when `http_path_phase >= 3`: topology/routing envelopes, with
 *     R3+ deferred queue handoff and R1-R2 fast-path skips.
 *   - P7/P8/P9 when `http_path_phase >= 4`: spec/tasks/receipt envelopes.
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
    public const FIELD_ID = 'id';
    public const FIELD_INPUT_TEXT = 'input_text';
    public const STATUS_SCHEMA = 'atlas.aaeos.http_path_status.v1';

    public const REQUEST_SCHEMA = 'atlas.aaeos.http_path_request.v1';

    public const RESULT_OK = 'ok';

    public const RESULT_BLOCKED = 'blocked';

    public const RESULT_UNKNOWN = 'unknown';

    public const FIELD_BLOCKED = 'blocked';
    public const FIELD_INTENT_ID = 'intent_id';
    public const FIELD_REASON = 'reason';
    public const FIELD_ENVELOPES = 'envelopes';
    public const FIELD_PLACEMENT = 'placement';
    public const FIELD_STATUS = 'status';
    public const FIELD_DATA = 'data';
    public const FIELD_BLOCKER = 'blocker';
    public const FIELD_TELEMETRY = 'telemetry';
    public const FIELD_GATE_STATUS = 'gate_status';
    public const FIELD_AAEOS_HTTP_PATH = 'aaeos_http_path';
    public const FIELD_BLOCKED_WHEN = 'blocked_when';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_CANONICAL_CALLS = 'canonical_calls';
    public const FIELD_CODE = 'code';
    public const FIELD_CONFIGURED_PHASE = 'configured_phase';
    public const FIELD_COUNTERS = 'counters';
    public const FIELD_DOMAIN = 'domain';
    public const FIELD_FACADE_ACTIVE = 'facade_active';
    public const FIELD_FLOW = 'flow';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_LATENCY_MS = 'latency_ms';
    public const FIELD_LAYER = 'layer';
    public const FIELD_REQUIRES_AP = 'requires_ap';
    public const FIELD_PAYLOAD = 'payload';
    public const FIELD_HTTP_STATUS = 'http_status';
    public const FIELD_PHASE_ROUTER = 'phase_router';
    public const FIELD_LEGACY_FALLBACK = 'legacy_fallback';

    public const BLOCK_PLACEMENT_GATE_BLOCKED = 'placement_gate_blocked';

    public const BLOCK_POLICY_GATE_BLOCKED = 'policy_gate_blocked';

    public const TELEMETRY_KEY_REQUESTS = 'atlas.aaeos.http_path.requests';

    public const TELEMETRY_KEY_CANONICAL = 'atlas.aaeos.http_path.canonical_calls';

    public const TELEMETRY_KEY_LEGACY_FALLBACK = 'atlas.aaeos.http_path.legacy_fallback';

    public const TELEMETRY_KEY_BLOCKED = 'atlas.aaeos.http_path.blocked';

    public const TELEMETRY_KEY_LATENCY = 'atlas.aaeos.http_path.latency_ms';

    public const MISSION_FOUNDATION_OPTIONAL_CONFIG_KEY = 'atlas.aaeos.mission_foundation_optional_at_phase_1';

    public const DEFAULT_MISSION_FOUNDATION_OPTIONAL = true;

    public const PLACEMENT_CACHE_TTL_CONFIG_KEY = 'atlas.aaeos.placement_cache_ttl_seconds';

    public const DEFAULT_PLACEMENT_CACHE_TTL_SECONDS = 300;

    public const TELEMETRY_ENABLED_CONFIG_KEY = 'atlas.aaeos.telemetry_enabled';

    public const DEFAULT_TELEMETRY_ENABLED = true;
    public const FIELD_REQUESTS = 'requests';
    public const FIELD_SAMPLES = 'samples';
    public const FIELD_MAX = 'max';
    public const FIELD_PHASE_ACTIVE = 'phase_active';
    public const FIELD_PHASE_OUT = 'phase_out';
    public const FIELD_PHASES_EXECUTED = 'phases_executed';
    public const FIELD_PHASES_EXECUTED_COUNT = 'phases_executed_count';
    public const FIELD_PLACEMENT_CACHE_HIT = 'placement_cache_hit';
    public const FIELD_PLACEMENT_DECISION = 'placement_decision';
    public const FIELD_SOURCE_TYPE = 'source_type';
    public const FIELD_SUM = 'sum';
    public const FIELD_VERDICT = 'verdict';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_APP_SURFACE = 'app_surface';
    public const FIELD_CURRENT_MODE = 'current_mode';
    public const FIELD_DOMAIN_ID = 'domain_id';
    public const FIELD_EMPTY_INTENT = 'empty_intent';
    public const FIELD_FLOW_ID = 'flow_id';
    public const FIELD_LEGACY = 'legacy';
    public const FIELD_SURFACE_ID = 'surface_id';
    public const FIELD_AAEOS_HTTP_PATH_PHASE_1_EMPTY_INTENT = 'aaeos_http_path_phase_1_empty_intent';
    public const FIELD_ATLAS_MODE = 'atlas_mode';
    public const FIELD_ROUTING_TASK = 'routing_task';
    public const FIELD_ATLAS_AAEOS_PLACEMENT_ = 'atlas.aaeos.placement.';
    public const FIELD_PAYLOAD_INTENT_ID = 'payload.intent_id';
    public const FIELD_PAYLOAD_PROMPT = 'payload.prompt';
    public const FIELD__COUNT = '.count';
    public const FIELD__MAX = '.max';
    public const FIELD__SUM = '.sum';
    public const FIELD_ATLAS_PLACEMENT_GATE_BLOCKED_THIS_INTENT_BEFORE_PROVIDER_EXECUTION_ = 'Atlas placement gate blocked this intent before provider execution.';
    public const FIELD_ATLAS_POLICY_GATE_BLOCKED_THIS_INTENT_BEFORE_PROVIDER_EXECUTION = 'Atlas policy gate blocked this intent before provider execution';
    public const INT_422 = 422;

    private readonly AaeosHttpPathEnvelopeFactory $envelopeFactory;

    public function __construct(
        AaeosPhaseHandoffService $handoff,
        private readonly AtlasFeaturePlacementService $placement,
        private readonly ?MissionDetectionService $missionDetection,
        private readonly CacheRepository $cache,
        private readonly ?AaeosDeferredPhaseDispatcherService $deferredDispatcher = null,
        ?AaeosHttpPathEnvelopeFactory $envelopeFactory = null,
    ) {
        $this->envelopeFactory = $envelopeFactory ?? new AaeosHttpPathEnvelopeFactory($handoff);
    }

    /**
     * Decide whether the facade is active for the given configured phase.
     */
    public static function isActive(string $configuredPhase): bool
    {
        return AtlasPhaseRouterService::isActivePhase($configuredPhase);
    }

    /**
     * Run the phase-gated canonical sub-sequence over an incoming HTTP request.
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
                self::FIELD_STATUS => self::RESULT_OK,
                self::FIELD_INTENT_ID => $this->newIntentId($data),
                self::FIELD_DATA => $data,
                self::FIELD_ENVELOPES => [],
                self::FIELD_BLOCKER => null,
                self::FIELD_TELEMETRY => $this->telemetry(self::FIELD_LEGACY, $this->elapsedMs($startedAtNs), false),
            ];
        }

        $intentId = $this->newIntentId($data);
        $intentText = $this->extractIntentText($data);
        $intentHash = $this->hashIntent($intentText);
        $envelopes = [];
        $factory = $this->envelopeFactory;

        // ---- P0 intent_capture --------------------------------------------
        $envelopes[] = $factory->intentCapture($intentId, $intentHash);

        // ---- P1 disambiguation (optional at Phase 1) ----------------------
        $missionOptional = (AiValueNormalizer::boolOrNull(config(self::MISSION_FOUNDATION_OPTIONAL_CONFIG_KEY, self::DEFAULT_MISSION_FOUNDATION_OPTIONAL)) ?? self::DEFAULT_MISSION_FOUNDATION_OPTIONAL);
        if (! $missionOptional && $this->missionDetection !== null && $intentText !== '') {
            $signal = $this->missionDetection->detect($intentText);
            $envelopes[] = $factory->disambiguationSignal(
                $intentId,
                $intentHash,
                $signal->suggestedMissionType,
                $signal->shouldActivateMissionMode,
            );
        } else {
            $envelopes[] = $factory->disambiguationSkipped($intentId);
        }

        // ---- P2 placement (MANDATORY at Phase 1) --------------------------
        [$placementResult, $cacheHit] = $this->placeOrCache($intentText, $intentHash, $data);
        $placementOk = ($placementResult[self::FIELD_GATE_STATUS] ?? self::RESULT_UNKNOWN) !== self::RESULT_BLOCKED;

        $envelopes[] = $factory->placement($intentId, $intentHash, $placementResult, $placementOk);

        if (! $placementOk) {
            $this->incrementCounter(self::TELEMETRY_KEY_BLOCKED);

            return $this->blockedResult(
                data: $data,
                intentId: $intentId,
                envelopes: $envelopes,
                placementResult: $placementResult,
                blockerCode: self::BLOCK_PLACEMENT_GATE_BLOCKED,
                reason: self::FIELD_ATLAS_PLACEMENT_GATE_BLOCKED_THIS_INTENT_BEFORE_PROVIDER_EXECUTION_,
                blockedWhen: array_values(AiValueNormalizer::arrayOrEmpty($placementResult[self::FIELD_BLOCKED_WHEN] ?? null)),
                configuredPhase: $configuredPhase,
                startedAtNs: $startedAtNs,
                placementCacheHit: $cacheHit,
            );
        }

        // ---- P3 classification + P4 policy_gate (Phase 2+, AP-697) ---------
        if ($this->phaseAtLeast($configuredPhase, '2')) {
            $envelopes[] = $factory->classification($intentId, $intentHash, $data);
            $policyEnv = $factory->policyGate($intentId, $intentHash, $data);
            $envelopes[] = $policyEnv;

            $advance = $factory->phaseAdvanceVerdict($policyEnv);
            if (in_array($advance[self::FIELD_VERDICT] ?? '', [PhaseAdvanceVerdictClassifier::VERDICT_HALT, PhaseAdvanceVerdictClassifier::VERDICT_BLOCK], true)) {
                $this->incrementCounter(self::TELEMETRY_KEY_BLOCKED);

                $blockedWhen = array_values(array_map(
                    static fn (array $b): string => AiValueNormalizer::trimmedStringOrNull($b[self::FIELD_ID] ?? null) ?? '',
                    AiValueNormalizer::arrayOrEmpty($policyEnv[self::FIELD_BLOCKERS] ?? null),
                ));
                if ($blockedWhen === [] && ($advance[self::FIELD_REASON] ?? '') !== '') {
                    $blockedWhen = [AiValueNormalizer::trimmedStringOrNull($advance[self::FIELD_REASON] ?? null) ?? ''];
                }

                return $this->blockedResult(
                    data: $data,
                    intentId: $intentId,
                    envelopes: $envelopes,
                    placementResult: $placementResult,
                    blockerCode: self::BLOCK_POLICY_GATE_BLOCKED,
                    reason: self::FIELD_ATLAS_POLICY_GATE_BLOCKED_THIS_INTENT_BEFORE_PROVIDER_EXECUTION
                        .(($advance[self::FIELD_REASON] ?? '') !== '' ? ' ('.$advance[self::FIELD_REASON].').' : '.'),
                    blockedWhen: $blockedWhen,
                    configuredPhase: $configuredPhase,
                    startedAtNs: $startedAtNs,
                    placementCacheHit: $cacheHit,
                );
            }
        }

        // ---- P5 topology + P6 routing (Phase 3+, AP-698) -------------------
        if ($this->phaseAtLeast($configuredPhase, '3')) {
            $riskBand = $factory->riskBand($data);
            $envelopes[] = $factory->topology($intentId, $intentHash, $riskBand);
            $envelopes[] = $factory->routing($intentId, $intentHash, $riskBand);
        }

        // ---- P7 spec + P8 tasks + P9 receipt (Phase 4+, AP-699) ------------
        if ($this->phaseAtLeast($configuredPhase, '4')) {
            $riskBand = $riskBand ?? $factory->riskBand($data);
            $envelopes[] = $factory->spec($intentId, $intentHash, $riskBand);
            $envelopes[] = $factory->tasks($intentId, $intentHash, $riskBand);
            $envelopes[] = $factory->receipt($intentId, $intentHash, $riskBand);
        }

        // Auto-enqueue every deferred envelope so the "synchronous_invocation:
        // deferred" markers from Phase 3/4 are durably persisted into the
        // AAEOS deferred queue. Async workers claim and execute them.
        // This closes the "deferred = caller responsibility" caveat.
        if ($this->deferredDispatcher !== null) {
            $this->deferredDispatcher->enqueueFromFacadeResult(
                envelopes: array_map(
                    static fn (array $e) => array_merge($e, [self::FIELD_INTENT_ID => $envelopes[0][self::FIELD_INTENT_ID] ?? '']),
                    $envelopes,
                ),
            );
        }

        $this->incrementCounter(self::TELEMETRY_KEY_CANONICAL);
        $elapsed = $this->elapsedMs($startedAtNs);
        $this->recordLatency($elapsed);

        return [
            self::FIELD_STATUS => self::RESULT_OK,
            self::FIELD_INTENT_ID => $intentId,
            self::FIELD_DATA => $this->mergeFacadeMetadata($data, $intentId, $envelopes, $placementResult),
            self::FIELD_ENVELOPES => $envelopes,
            self::FIELD_BLOCKER => null,
            self::FIELD_TELEMETRY => $this->telemetry($configuredPhase, $elapsed, $cacheHit),
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
            self::FIELD_SCHEMA => self::STATUS_SCHEMA,
            self::FIELD_CONFIGURED_PHASE => $configuredPhase,
            self::FIELD_FACADE_ACTIVE => self::isActive($configuredPhase),
            self::FIELD_PHASE_ROUTER => (new AtlasPhaseRouterService($configuredPhase))->statusSnapshot(),
            self::FIELD_COUNTERS => [
                self::FIELD_REQUESTS => (int) $this->cache->get(self::TELEMETRY_KEY_REQUESTS, 0),
                self::FIELD_CANONICAL_CALLS => (int) $this->cache->get(self::TELEMETRY_KEY_CANONICAL, 0),
                self::FIELD_LEGACY_FALLBACK => (int) $this->cache->get(self::TELEMETRY_KEY_LEGACY_FALLBACK, 0),
                self::FIELD_BLOCKED => (int) $this->cache->get(self::TELEMETRY_KEY_BLOCKED, 0),
            ],
            self::FIELD_LATENCY_MS => [
                self::FIELD_SAMPLES => (int) $this->cache->get(self::TELEMETRY_KEY_LATENCY.self::FIELD__COUNT, 0),
                self::FIELD_SUM => (int) $this->cache->get(self::TELEMETRY_KEY_LATENCY.self::FIELD__SUM, 0),
                self::FIELD_MAX => (int) $this->cache->get(self::TELEMETRY_KEY_LATENCY.self::FIELD__MAX, 0),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function newIntentId(array $data): string
    {
        $existing = AiValueNormalizer::trimmedStringOrNull(data_get($data, self::FIELD_PAYLOAD_INTENT_ID));
        if ($existing !== null) {
            return $existing;
        }

        return 'int-'.Str::ulid()->toBase32();
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function extractIntentText(array $data): string
    {
        $text = $data[self::FIELD_INPUT_TEXT] ?? data_get($data, self::FIELD_PAYLOAD_PROMPT) ?? '';

        return is_string($text) ? AiValueNormalizer::trimmedStringOrNull($text) ?? '' : '';
    }

    private function hashIntent(string $intentText): string
    {
        if ($intentText === '') {
            return 'sha256:'.hash(self::FIELD_SHA256, self::FIELD_EMPTY_INTENT);
        }

        return 'sha256:'.hash(self::FIELD_SHA256, $intentText);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{0: array<string,mixed>, 1: bool}
     */
    private function placeOrCache(string $intentText, string $intentHash, array $data): array
    {
        $ttl = (int) (AiValueNormalizer::finiteFloatOrNull(config(self::PLACEMENT_CACHE_TTL_CONFIG_KEY, self::DEFAULT_PLACEMENT_CACHE_TTL_SECONDS)) ?? self::DEFAULT_PLACEMENT_CACHE_TTL_SECONDS);
        $cacheKey = self::FIELD_ATLAS_AAEOS_PLACEMENT_.hash(self::FIELD_SHA256, $intentHash.'|'.($data[self::FIELD_SOURCE_TYPE] ?? ''));
        $cached = $ttl > 0 ? $this->cache->get($cacheKey) : null;
        if (is_array($cached)) {
            return [$cached, true];
        }

        $feature = $intentText !== '' ? $intentText : self::FIELD_AAEOS_HTTP_PATH_PHASE_1_EMPTY_INTENT;
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
        $payload = self::requestPayload($data);
        $hints = [];
        foreach ([self::FIELD_ATLAS_MODE, self::FIELD_CURRENT_MODE, self::FIELD_FLOW_ID, self::FIELD_DOMAIN_ID, self::FIELD_SURFACE_ID, self::FIELD_APP_SURFACE, self::FIELD_ROUTING_TASK] as $key) {
            $value = AiValueNormalizer::trimmedStringOrNull($payload[$key] ?? null);
            if ($value !== null) {
                $hints[] = $key.'='.$value;
            }
        }

        return $hints;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<array<string,mixed>>  $envelopes
     * @param  array<string,mixed>  $placementResult
     * @return array<string,mixed>
     */
    private function mergeFacadeMetadata(array $data, string $intentId, array $envelopes, array $placementResult): array
    {
        $payload = self::requestPayload($data);
        $payload[self::FIELD_AAEOS_HTTP_PATH] = [
            self::FIELD_SCHEMA => self::REQUEST_SCHEMA,
            self::FIELD_INTENT_ID => $intentId,
            self::FIELD_PHASES_EXECUTED => array_values(array_map(
                static fn (array $env): string => AiValueNormalizer::trimmedStringOrNull($env[self::FIELD_PHASE_OUT] ?? null) ?? self::RESULT_UNKNOWN,
                $envelopes,
            )),
            self::FIELD_PHASES_EXECUTED_COUNT => count($envelopes),
            self::FIELD_PLACEMENT_DECISION => [
                self::FIELD_GATE_STATUS => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_GATE_STATUS] ?? null) ?? self::RESULT_UNKNOWN,
                self::FIELD_LAYER => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_LAYER] ?? null) ?? self::RESULT_UNKNOWN,
                self::FIELD_DOMAIN => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_DOMAIN] ?? null) ?? self::RESULT_UNKNOWN,
                self::FIELD_FLOW => AiValueNormalizer::trimmedStringOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_FLOW] ?? null) ?? self::RESULT_UNKNOWN,
                self::FIELD_REQUIRES_AP => (AiValueNormalizer::boolOrNull($placementResult[self::FIELD_PLACEMENT][self::FIELD_REQUIRES_AP] ?? null) ?? false),
            ],
            self::FIELD_ENVELOPES => $envelopes,
        ];
        $data[self::FIELD_PAYLOAD] = $payload;

        return $data;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<array<string,mixed>>  $envelopes
     * @param  array<string,mixed>  $placementResult
     * @param  list<mixed>  $blockedWhen
     * @return array<string,mixed>
     */
    private function blockedResult(
        array $data,
        string $intentId,
        array $envelopes,
        array $placementResult,
        string $blockerCode,
        string $reason,
        array $blockedWhen,
        string $configuredPhase,
        int $startedAtNs,
        bool $placementCacheHit,
    ): array {
        return [
            self::FIELD_STATUS => self::RESULT_BLOCKED,
            self::FIELD_INTENT_ID => $intentId,
            self::FIELD_DATA => $this->mergeFacadeMetadata($data, $intentId, $envelopes, $placementResult),
            self::FIELD_ENVELOPES => $envelopes,
            self::FIELD_BLOCKER => [
                self::FIELD_CODE => $blockerCode,
                self::FIELD_REASON => $reason,
                self::FIELD_BLOCKED_WHEN => array_values($blockedWhen),
                self::FIELD_HTTP_STATUS => self::INT_422,
            ],
            self::FIELD_TELEMETRY => $this->telemetry($configuredPhase, $this->elapsedMs($startedAtNs), $placementCacheHit),
        ];
    }

    /**
     * @return array{phase_active:string, latency_ms:int, placement_cache_hit:bool}
     */
    private function telemetry(string $phaseActive, int $latencyMs, bool $placementCacheHit): array
    {
        return [
            self::FIELD_PHASE_ACTIVE => $phaseActive,
            self::FIELD_LATENCY_MS => $latencyMs,
            self::FIELD_PLACEMENT_CACHE_HIT => $placementCacheHit,
        ];
    }

    /**
     * Phase comparison: phase numeric value >= threshold. Returns false
     * for legacy.
     */
    private function phaseAtLeast(string $configuredPhase, string $threshold): bool
    {
        return AtlasPhaseRouterService::phaseAtLeast($configuredPhase, $threshold);
    }

    private function elapsedMs(int $startedAtNs): int
    {
        return (int) ((hrtime(true) - $startedAtNs) / 1_000_000);
    }

    private function incrementCounter(string $key): void
    {
        if (! (AiValueNormalizer::boolOrNull(config(self::TELEMETRY_ENABLED_CONFIG_KEY, self::DEFAULT_TELEMETRY_ENABLED)) ?? self::DEFAULT_TELEMETRY_ENABLED)) {
            return;
        }
        $current = (int) $this->cache->get($key, 0);
        $this->cache->forever($key, $current + 1);
    }

    private function recordLatency(int $ms): void
    {
        if (! (AiValueNormalizer::boolOrNull(config(self::TELEMETRY_ENABLED_CONFIG_KEY, self::DEFAULT_TELEMETRY_ENABLED)) ?? self::DEFAULT_TELEMETRY_ENABLED)) {
            return;
        }
        $countKey = self::TELEMETRY_KEY_LATENCY.self::FIELD__COUNT;
        $sumKey = self::TELEMETRY_KEY_LATENCY.self::FIELD__SUM;
        $maxKey = self::TELEMETRY_KEY_LATENCY.self::FIELD__MAX;
        $count = (int) $this->cache->get($countKey, 0);
        $sum = (int) $this->cache->get($sumKey, 0);
        $max = (int) $this->cache->get($maxKey, 0);
        $this->cache->forever($countKey, $count + 1);
        $this->cache->forever($sumKey, $sum + $ms);
        if ($ms > $max) {
            $this->cache->forever($maxKey, $ms);
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function requestPayload(array $data): array
    {
        return AiValueNormalizer::arrayOrEmpty($data[self::FIELD_PAYLOAD] ?? null);
    }
}
