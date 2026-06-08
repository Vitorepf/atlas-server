<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\Caching\AtlasProviderCostSentinel;
use Closure;

/**
 * Atlas Swarm Production Resolver — Patamar 4 F2.
 *
 * Translates a swarm arm + execution context into a real call against
 * `AiProviderManager::get($arm['provider'])->run($job, $prompt)`, then maps
 * the `AiProviderResult` back into the canonical swarm arm outcome shape:
 *
 *   ['result' => success|failure|timeout, 'latency_ms' => int,
 *    'quality_score' => ?float, 'output' => string]
 *
 * Behaviour:
 *   - Wraps the provider call in a try/catch so a provider throw becomes a
 *     deterministic `failure` outcome (resolver never propagates exceptions
 *     up into the swarm executor — the executor already handles throws but
 *     here we add a circuit breaker layer).
 *   - Per-provider failure counter (rolling, in-memory) — when the count
 *     exceeds the threshold, subsequent arms for that provider short-circuit
 *     to `failure` with `output='circuit_open'` until the cooldown resets.
 *   - Honours per-arm `timeout_ms` (when present) via provider-side metadata.
 *     PHP-level enforced timeouts depend on the underlying transport; this
 *     resolver flags the timeout from the AiProviderResult when present and
 *     never blocks the executor indefinitely.
 *
 * The resolver is opt-in: AppServiceProvider attaches it only when
 * `config('atlas.patamar4.swarm_production_resolver_enabled')` is true.
 * Default is OFF, preserving stub/test behaviour.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-swarm-production-resolver.md
 *
 * Claim policy: provider-safe. Atlas continues to substitute Claude
 * Code/Cursor/Codex as products while using their engines underneath. No
 * benchmark, rivals, or external_rivals claims.
 */
class AtlasSwarmProductionResolverService
{
    public const DEFAULT_CIRCUIT_THRESHOLD = 3;

    public const DEFAULT_CIRCUIT_COOLDOWN_SECONDS = 60;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUS_TIMEOUT = 'timeout';

    /** @var array<string, array{count:int, opened_at:?int}> */
    private array $circuit = [];

    private ?AtlasProviderCostSentinel $costSentinel = null;

    private ?string $costLogPath = null;

    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly int $circuitThreshold = self::DEFAULT_CIRCUIT_THRESHOLD,
        private readonly int $circuitCooldownSeconds = self::DEFAULT_CIRCUIT_COOLDOWN_SECONDS,
    ) {}

    /**
     * Resolver closure compatible with AtlasSwarmExecutorService::setResolver.
     */
    public function asClosure(): Closure
    {
        return function (array $arm, array $context): array {
            return $this->resolve($arm, $context);
        };
    }

    /**
     * Wire the spread-anywhere cost sentinel at the spend boundary (opt-in). When
     * unset (default) behaviour is unchanged. When set, every real provider call is
     * pre-assessed: the assessment is always recorded as cost telemetry (for
     * calibration), and the call is refused ONLY when the operator has configured a
     * positive hard ceiling that the pre-cost exceeds.
     */
    public function setCostSentinel(?AtlasProviderCostSentinel $sentinel, ?string $logPath = null): void
    {
        $this->costSentinel = $sentinel;
        $this->costLogPath = $logPath;
    }

    /**
     * @param  array<string,mixed>  $assessment
     */
    private function recordCostTelemetry(string $providerKey, array $assessment): void
    {
        if ($this->costLogPath === null) {
            return;
        }

        $line = json_encode([
            'schema_version' => 'atlas.ai.provider_cost_telemetry.v1',
            'provider' => $providerKey,
            'pre_cost_units' => $assessment['pre_cost_units'] ?? null,
            'soft_warn' => $assessment['soft_warn'] ?? null,
            'hard_blocked' => $assessment['hard_blocked'] ?? null,
            'flow_id' => $assessment['flow_id'] ?? null,
            'risk_level' => $assessment['risk_level'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents($this->costLogPath, ($line === false ? '{}' : $line).PHP_EOL, FILE_APPEND);
    }

    /**
     * @param  array<string,mixed>  $arm
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function resolve(array $arm, array $context): array
    {
        $providerKey = (string) ($arm['provider'] ?? '');
        if ($providerKey === '') {
            return $this->failure(0, 'arm_missing_provider');
        }

        if ($this->isCircuitOpen($providerKey)) {
            return $this->failure(0, 'circuit_open');
        }

        $prompt = (string) ($context['input'] ?? '');
        if ($prompt === '') {
            return $this->failure(0, 'context_missing_input');
        }

        $startedAt = microtime(true);

        try {
            $provider = $this->providers->get($providerKey);
        } catch (\Throwable $e) {
            $this->recordFailure($providerKey);

            return $this->failure(
                (int) ((microtime(true) - $startedAt) * 1000),
                'provider_resolve_error: '.substr($e->getMessage(), 0, 80),
            );
        }

        if (! $provider instanceof AiProvider) {
            $this->recordFailure($providerKey);

            return $this->failure(
                (int) ((microtime(true) - $startedAt) * 1000),
                'provider_not_ai_provider_contract',
            );
        }

        $job = $this->ephemeralJob($arm, $context, $prompt);

        if ($this->costSentinel !== null) {
            $assessment = $this->costSentinel->assess($job, $prompt);
            $this->recordCostTelemetry($providerKey, $assessment);
            if (($assessment['hard_blocked'] ?? false) === true) {
                $this->recordFailure($providerKey);

                return $this->failure((int) ((microtime(true) - $startedAt) * 1000), 'cost_ceiling_exceeded');
            }
        }

        try {
            $result = $provider->run($job, $prompt);
        } catch (\Throwable $e) {
            $this->recordFailure($providerKey);

            return $this->failure(
                (int) ((microtime(true) - $startedAt) * 1000),
                'provider_run_error: '.substr($e->getMessage(), 0, 80),
            );
        }

        $latencyMs = isset($result->durationMs) ? max(0, (int) $result->durationMs) : (int) ((microtime(true) - $startedAt) * 1000);
        $ok = (bool) ($result->ok ?? false);
        $errorCode = (string) ($result->errorCode ?? '');
        $isTimeout = $ok === false && str_contains(strtolower($errorCode), 'timeout');

        if ($ok) {
            $this->recordSuccess($providerKey);

            return [
                'result' => self::STATUS_SUCCESS,
                'latency_ms' => $latencyMs,
                'quality_score' => $this->qualityFromResult($result),
                'output' => (string) ($result->output ?? ''),
            ];
        }

        $this->recordFailure($providerKey);

        return [
            'result' => $isTimeout ? self::STATUS_TIMEOUT : self::STATUS_FAILURE,
            'latency_ms' => $latencyMs,
            'quality_score' => null,
            'output' => $errorCode !== '' ? $errorCode : 'provider_returned_not_ok',
        ];
    }

    /**
     * @return array<string,array{count:int, opened_at:?int}>
     */
    public function circuitState(): array
    {
        return $this->circuit;
    }

    public function resetCircuit(?string $provider = null): void
    {
        if ($provider === null) {
            $this->circuit = [];

            return;
        }
        unset($this->circuit[$provider]);
    }

    // ---------- internals ----------

    private function isCircuitOpen(string $providerKey): bool
    {
        $state = $this->circuit[$providerKey] ?? null;
        if ($state === null) {
            return false;
        }
        if (($state['count'] ?? 0) < $this->circuitThreshold) {
            return false;
        }
        $openedAt = $state['opened_at'] ?? null;
        if ($openedAt === null) {
            return true;
        }
        if (time() - $openedAt >= $this->circuitCooldownSeconds) {
            // Cooldown elapsed — half-open: clear state, allow one probe.
            unset($this->circuit[$providerKey]);

            return false;
        }

        return true;
    }

    private function recordFailure(string $providerKey): void
    {
        $state = $this->circuit[$providerKey] ?? ['count' => 0, 'opened_at' => null];
        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        if ($state['count'] >= $this->circuitThreshold && $state['opened_at'] === null) {
            $state['opened_at'] = time();
        }
        $this->circuit[$providerKey] = $state;
    }

    private function recordSuccess(string $providerKey): void
    {
        unset($this->circuit[$providerKey]);
    }

    /**
     * @param  array<string,mixed>  $arm
     * @param  array<string,mixed>  $context
     */
    private function ephemeralJob(array $arm, array $context, string $prompt): AiJob
    {
        $job = new AiJob;
        $job->kind = (string) ($context['task_category'] ?? 'swarm_arm');
        $job->provider = (string) ($arm['provider'] ?? '');
        $job->model = (string) ($arm['model'] ?? '');
        $job->prompt = $prompt;
        $job->input_text = $prompt;
        $job->priority = (int) ($arm['rank'] ?? 0);
        $job->metadata = [
            'swarm_arm_id' => $arm['arm_id'] ?? null,
            'swarm_origin' => $arm['origin'] ?? null,
            'task_category' => $context['task_category'] ?? null,
            'role' => $context['role'] ?? null,
            'framework' => $context['framework'] ?? null,
            'privacy_class' => $context['privacy_class'] ?? null,
        ];
        $job->timeout_seconds = (int) ($arm['timeout_seconds'] ?? $context['timeout_seconds'] ?? 60);

        return $job;
    }

    /**
     * @param  array<string,mixed>|null  $result  (AiProviderResult instance)
     */
    private function qualityFromResult(mixed $result): ?float
    {
        if (! is_object($result)) {
            return null;
        }
        // Allow providers to publish quality_score in metadata.
        $meta = $result->metadata ?? null;
        if (is_array($meta) && isset($meta['quality_score'])) {
            $q = (float) $meta['quality_score'];

            return max(0.0, min(1.0, $q));
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function failure(int $latencyMs, string $output): array
    {
        return [
            'result' => self::STATUS_FAILURE,
            'latency_ms' => max(0, $latencyMs),
            'quality_score' => null,
            'output' => $output,
        ];
    }
}
