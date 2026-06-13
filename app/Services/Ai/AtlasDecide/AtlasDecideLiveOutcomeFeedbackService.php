<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use InvalidArgumentException;

/**
 * Atlas Decide Live Outcome Feedback Service — Patamar 4 closed-loop intelligence.
 *
 * Records online outcome of every provider call made via the gateway so ADML
 * can detect degradation of an active learned route and auto-deactivate it
 * BEFORE a costly run completes. Distinct from
 * AtlasForgeRivalsProviderPerformanceLedgerService which holds offline
 * benchmark battery results — that ledger is the cold-start signal source;
 * this one is the warm runtime signal.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-decide-live-outcome-feedback.md
 *
 * Schemas:
 *   - atlas.atlas_decide.live_outcome.v1
 *   - atlas.atlas_decide.live_route_stats.v1
 *   - atlas.atlas_decide.degradation_signal.v1
 *
 * Invariants:
 *   - append-only JSONL local-first;
 *   - never calls a provider;
 *   - degradation thresholds hardcoded (operator changes via PR);
 *   - provider-safe (no benchmark/rivals/superiority claims emitted).
 */
final class AtlasDecideLiveOutcomeFeedbackService
{
    public const OUTCOME_SCHEMA = 'atlas.atlas_decide.live_outcome.v1';

    public const STATS_SCHEMA = 'atlas.atlas_decide.live_route_stats.v1';

    public const SIGNAL_SCHEMA = 'atlas.atlas_decide.degradation_signal.v1';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    public const RESULT_TIMEOUT = 'timeout';

    public const VALID_RESULTS = [self::RESULT_SUCCESS, self::RESULT_FAILURE, self::RESULT_TIMEOUT];

    public const SIGNAL_HEALTHY = 'healthy';

    public const SIGNAL_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    public const SIGNAL_DEGRADING = 'degrading';

    public const SIGNAL_BROKEN = 'broken';

    /** Minimum calls in window before we can decide the route is healthy/degrading. */
    public const MIN_CALLS_FOR_SIGNAL = 5;

    /** Sliding window size (last N calls) used for the aggregated view. */
    public const WINDOW_SIZE = 20;

    /** Below this success rate we mark `degrading`. */
    public const DEGRADATION_THRESHOLD = 0.7;

    /** Below this success rate we mark `broken`. */
    public const BROKEN_THRESHOLD = 0.4;

    private ?string $logPathOverride = null;

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'live_outcomes.jsonl';
    }

    /**
     * Append one outcome receipt. Idempotent in spirit — every call writes a new line.
     *
     * @param  array{
     *     task_category:string,
     *     role:string,
     *     framework?:?string,
     *     provider:string,
     *     model?:?string,
     *     result:string,
     *     latency_ms?:int,
     *     quality_score?:float,
     *     cost_usd?:float,
     *     tokens_used?:int,
     *     input_tokens?:int,
     *     output_tokens?:int,
     *     actor?:string
     * }  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $taskCategory = (string) ($input['task_category'] ?? '');
        $role = (string) ($input['role'] ?? '');
        $provider = (string) ($input['provider'] ?? '');
        $result = (string) ($input['result'] ?? '');

        if ($taskCategory === '' || $role === '' || $provider === '') {
            throw new InvalidArgumentException('task_category, role and provider are required.');
        }
        if (! in_array($result, self::VALID_RESULTS, true)) {
            throw new InvalidArgumentException("result must be one of: ".implode(',', self::VALID_RESULTS));
        }

        $framework = $input['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }
        $model = $input['model'] ?? null;
        $latency = isset($input['latency_ms']) ? max(0, (int) $input['latency_ms']) : null;
        $quality = isset($input['quality_score']) ? max(0.0, min(1.0, (float) $input['quality_score'])) : null;
        $costUsd = $this->positiveFloatOrNull($input['cost_usd'] ?? null);
        $inputTokens = $this->positiveIntOrNull($input['input_tokens'] ?? null);
        $outputTokens = $this->positiveIntOrNull($input['output_tokens'] ?? null);
        $tokensUsed = $this->positiveIntOrNull($input['tokens_used'] ?? null);
        if ($tokensUsed === null && ($inputTokens !== null || $outputTokens !== null)) {
            $tokensUsed = ($inputTokens ?? 0) + ($outputTokens ?? 0);
        }
        $actor = (string) ($input['actor'] ?? 'ai_gateway');

        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        $entry = [
            'schema_version' => self::OUTCOME_SCHEMA,
            'recorded_at' => $at,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'provider' => $provider,
            'model' => $model,
            'result' => $result,
            'latency_ms' => $latency,
            'quality_score' => $quality,
            'cost_usd' => $costUsd,
            'tokens_used' => $tokensUsed,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'actor' => $actor,
        ];
        $entry['entry_hash'] = 'sha256:'.hash('sha256', json_encode([
            'recorded_at' => $at,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'provider' => $provider,
            'model' => $model,
            'result' => $result,
            'cost_usd' => $costUsd,
            'tokens_used' => $tokensUsed,
            'quality_score' => $quality,
            'actor' => $actor,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $entry);

        return $entry;
    }

    /**
     * Aggregated stats for a (task_category, role, framework) scope across all
     * providers seen in the last WINDOW_SIZE calls per provider.
     *
     * @return array<string,mixed>
     */
    public function routeStats(string $taskCategory, string $role, ?string $framework = null): array
    {
        $framework = $framework === '' ? null : $framework;
        $matches = [];
        foreach (AppendOnlyJsonlStore::read($this->logPath()) as $entry) {
            if (($entry['task_category'] ?? null) !== $taskCategory) {
                continue;
            }
            if (($entry['role'] ?? null) !== $role) {
                continue;
            }
            if (($entry['framework'] ?? null) !== $framework) {
                continue;
            }
            $matches[] = $entry;
        }

        // Group by provider, keep last WINDOW_SIZE per provider.
        /** @var array<string,list<array<string,mixed>>> $byProvider */
        $byProvider = [];
        foreach ($matches as $m) {
            $p = (string) ($m['provider'] ?? '');
            if ($p === '') {
                continue;
            }
            $byProvider[$p] ??= [];
            $byProvider[$p][] = $m;
        }

        $providers = [];
        foreach ($byProvider as $p => $rows) {
            $window = array_slice($rows, -self::WINDOW_SIZE);
            $n = count($window);
            $success = 0;
            $failure = 0;
            $timeout = 0;
            $latencySum = 0;
            $latencyCount = 0;
            $qualitySum = 0.0;
            $qualityCount = 0;
            $costSum = 0.0;
            $costCount = 0;
            $tokenSum = 0;
            $tokenCount = 0;
            foreach ($window as $w) {
                $r = (string) ($w['result'] ?? '');
                match ($r) {
                    self::RESULT_SUCCESS => $success++,
                    self::RESULT_FAILURE => $failure++,
                    self::RESULT_TIMEOUT => $timeout++,
                    default => null,
                };
                if (isset($w['latency_ms']) && is_int($w['latency_ms'])) {
                    $latencySum += $w['latency_ms'];
                    $latencyCount++;
                }
                if (isset($w['quality_score']) && (is_int($w['quality_score']) || is_float($w['quality_score']))) {
                    $qualitySum += (float) $w['quality_score'];
                    $qualityCount++;
                }
                if (isset($w['cost_usd']) && is_numeric($w['cost_usd']) && (float) $w['cost_usd'] > 0.0) {
                    $costSum += (float) $w['cost_usd'];
                    $costCount++;
                }
                if (isset($w['tokens_used']) && is_numeric($w['tokens_used']) && (int) $w['tokens_used'] > 0) {
                    $tokenSum += (int) $w['tokens_used'];
                    $tokenCount++;
                }
            }
            $successRate = $n > 0 ? round($success / $n, 4) : null;
            $providers[$p] = [
                'provider' => $p,
                'window_size' => $n,
                'success' => $success,
                'failure' => $failure,
                'timeout' => $timeout,
                'success_rate' => $successRate,
                'avg_latency_ms' => $latencyCount > 0 ? (int) round($latencySum / $latencyCount) : null,
                'avg_quality_score' => $qualityCount > 0 ? round($qualitySum / $qualityCount, 4) : null,
                'avg_cost_usd' => $costCount > 0 ? round($costSum / $costCount, 6) : null,
                'cost_sample_count' => $costCount,
                'avg_tokens_used' => $tokenCount > 0 ? (int) round($tokenSum / $tokenCount) : null,
                'token_sample_count' => $tokenCount,
            ];
        }
        ksort($providers);

        return [
            'schema_version' => self::STATS_SCHEMA,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'total_calls_observed' => count($matches),
            'providers' => array_values($providers),
        ];
    }

    /**
     * Detect degradation of a specific (task_category, role, framework, provider, model)
     * route. Returns a signal envelope the caller (typically ADML.autoDeactivateOnDegradation)
     * uses to decide whether to deactivate the route.
     *
     * @return array<string,mixed>
     */
    public function degradationSignal(
        string $taskCategory,
        string $role,
        ?string $framework,
        string $provider,
        ?string $model = null
    ): array {
        $framework = $framework === '' ? null : $framework;
        $entries = [];
        foreach (AppendOnlyJsonlStore::read($this->logPath()) as $entry) {
            if (($entry['task_category'] ?? null) !== $taskCategory) {
                continue;
            }
            if (($entry['role'] ?? null) !== $role) {
                continue;
            }
            if (($entry['framework'] ?? null) !== $framework) {
                continue;
            }
            if (($entry['provider'] ?? null) !== $provider) {
                continue;
            }
            if ($model !== null && ($entry['model'] ?? null) !== $model) {
                continue;
            }
            $entries[] = $entry;
        }
        $window = array_slice($entries, -self::WINDOW_SIZE);
        $n = count($window);
        if ($n < self::MIN_CALLS_FOR_SIGNAL) {
            return $this->signalEnvelope(
                $taskCategory, $role, $framework, $provider, $model,
                self::SIGNAL_INSUFFICIENT_EVIDENCE, $n, null
            );
        }
        $success = 0;
        foreach ($window as $w) {
            if (($w['result'] ?? null) === self::RESULT_SUCCESS) {
                $success++;
            }
        }
        $rate = $success / $n;

        $signal = self::SIGNAL_HEALTHY;
        if ($rate < self::BROKEN_THRESHOLD) {
            $signal = self::SIGNAL_BROKEN;
        } elseif ($rate < self::DEGRADATION_THRESHOLD) {
            $signal = self::SIGNAL_DEGRADING;
        }

        return $this->signalEnvelope(
            $taskCategory, $role, $framework, $provider, $model,
            $signal, $n, round($rate, 4)
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listOutcomes(): array
    {
        return AppendOnlyJsonlStore::read($this->logPath());
    }

    // ---------- internals ----------

    private function positiveFloatOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $float = (float) $value;

        return $float > 0.0 ? $float : null;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function signalEnvelope(
        string $taskCategory,
        string $role,
        ?string $framework,
        string $provider,
        ?string $model,
        string $signal,
        int $sampleSize,
        ?float $successRate
    ): array {
        $envelope = [
            'schema_version' => self::SIGNAL_SCHEMA,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'provider' => $provider,
            'model' => $model,
            'signal' => $signal,
            'sample_size' => $sampleSize,
            'success_rate' => $successRate,
            'thresholds' => [
                'min_calls_for_signal' => self::MIN_CALLS_FOR_SIGNAL,
                'degradation_threshold' => self::DEGRADATION_THRESHOLD,
                'broken_threshold' => self::BROKEN_THRESHOLD,
                'window_size' => self::WINDOW_SIZE,
            ],
        ];
        $envelope['envelope_hash'] = 'sha256:'.hash('sha256', json_encode([
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'provider' => $provider,
            'model' => $model,
            'signal' => $signal,
            'sample_size' => $sampleSize,
            'success_rate' => $successRate,
        ], JSON_THROW_ON_ERROR));

        return $envelope;
    }
}
