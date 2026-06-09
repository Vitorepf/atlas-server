<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Aaeos\AtlasAaeosPhaseRouterService;
use App\Services\Ai\Kernel\Architecture\AtlasFeaturePlacementService;
use App\Services\Ai\Mission\MissionDetectionService;
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
    public const RESULT_OK = 'ok';

    public const RESULT_BLOCKED = 'blocked';

    public const BLOCK_PLACEMENT_GATE_BLOCKED = 'placement_gate_blocked';

    public const BLOCK_POLICY_GATE_BLOCKED = 'policy_gate_blocked';

    public const TELEMETRY_KEY_REQUESTS = 'atlas.aaeos.http_path.requests';

    public const TELEMETRY_KEY_CANONICAL = 'atlas.aaeos.http_path.canonical_calls';

    public const TELEMETRY_KEY_LEGACY_FALLBACK = 'atlas.aaeos.http_path.legacy_fallback';

    public const TELEMETRY_KEY_BLOCKED = 'atlas.aaeos.http_path.blocked';

    public const TELEMETRY_KEY_LATENCY = 'atlas.aaeos.http_path.latency_ms';

    private readonly AaeosHttpPathEnvelopeFactory $envelopeFactory;

    public function __construct(
        AaeosPhaseHandoffService $handoff,
        private readonly AtlasFeaturePlacementService $placement,
        private readonly ?MissionDetectionService $missionDetection,
        private readonly CacheRepository $cache,
        private readonly ?AaeosDeferredPhaseDispatcherService $deferredDispatcher = null,
    ) {
        $this->envelopeFactory = new AaeosHttpPathEnvelopeFactory($handoff);
    }

    /**
     * Decide whether the facade is active for the given configured phase.
     */
    public static function isActive(string $configuredPhase): bool
    {
        return AtlasAaeosPhaseRouterService::isActivePhase($configuredPhase);
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
                'status' => self::RESULT_OK,
                'intent_id' => $this->newIntentId($data),
                'data' => $data,
                'envelopes' => [],
                'blocker' => null,
                'telemetry' => $this->telemetry('legacy', $this->elapsedMs($startedAtNs), false),
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
        $missionOptional = (bool) config('atlas.aaeos.mission_foundation_optional_at_phase_1', true);
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
        $placementOk = ($placementResult['gate_status'] ?? 'unknown') !== 'blocked';

        $envelopes[] = $factory->placement($intentId, $intentHash, $placementResult, $placementOk);

        if (! $placementOk) {
            $this->incrementCounter(self::TELEMETRY_KEY_BLOCKED);

            return $this->blockedResult(
                data: $data,
                intentId: $intentId,
                envelopes: $envelopes,
                placementResult: $placementResult,
                blockerCode: self::BLOCK_PLACEMENT_GATE_BLOCKED,
                reason: 'Atlas placement gate blocked this intent before provider execution.',
                blockedWhen: array_values((array) ($placementResult['blocked_when'] ?? [])),
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

            if (AaeosHttpPathEnvelopeFactory::policyGateBlocked($policyEnv)) {
                $this->incrementCounter(self::TELEMETRY_KEY_BLOCKED);

                return $this->blockedResult(
                    data: $data,
                    intentId: $intentId,
                    envelopes: $envelopes,
                    placementResult: $placementResult,
                    blockerCode: self::BLOCK_POLICY_GATE_BLOCKED,
                    reason: 'Atlas policy gate blocked this intent before provider execution.',
                    blockedWhen: array_values(array_map(
                        static fn (array $b): string => (string) $b['id'],
                        (array) $policyEnv['blockers'],
                    )),
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
                    static fn (array $e) => array_merge($e, ['intent_id' => $envelopes[0]['intent_id'] ?? '']),
                    $envelopes,
                ),
            );
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
            'telemetry' => $this->telemetry($configuredPhase, $elapsed, $cacheHit),
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
            'phase_router' => (new AtlasAaeosPhaseRouterService($configuredPhase))->statusSnapshot(),
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
            'phases_executed_count' => count($envelopes),
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
            'status' => self::RESULT_BLOCKED,
            'intent_id' => $intentId,
            'data' => $this->mergeFacadeMetadata($data, $intentId, $envelopes, $placementResult),
            'envelopes' => $envelopes,
            'blocker' => [
                'code' => $blockerCode,
                'reason' => $reason,
                'blocked_when' => array_values($blockedWhen),
                'http_status' => 422,
            ],
            'telemetry' => $this->telemetry($configuredPhase, $this->elapsedMs($startedAtNs), $placementCacheHit),
        ];
    }

    /**
     * @return array{phase_active:string, latency_ms:int, placement_cache_hit:bool}
     */
    private function telemetry(string $phaseActive, int $latencyMs, bool $placementCacheHit): array
    {
        return [
            'phase_active' => $phaseActive,
            'latency_ms' => $latencyMs,
            'placement_cache_hit' => $placementCacheHit,
        ];
    }

    /**
     * Phase comparison: phase numeric value >= threshold. Returns false
     * for legacy.
     */
    private function phaseAtLeast(string $configuredPhase, string $threshold): bool
    {
        return AtlasAaeosPhaseRouterService::phaseAtLeast($configuredPhase, $threshold);
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
