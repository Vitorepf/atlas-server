<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * PHP adapter to the REAL Python telemetry-statistics data runtime
 * (runtimes/python/stats_engine). By the runtime_language_boundary canon — and
 * the operator thesis "Python é o melhor para dados; nunca faça em PHP o que
 * deveria ser Python" — the PHP kernel must NOT hand-roll KS / Mann-Whitney /
 * Mann-Kendall / CUSUM / EWMA / Wilson; it invokes this Python runtime (numpy)
 * instead. This client is the governed bridge: it writes a manifest, runs the
 * runtime under its own venv (where numpy lives), parses the receipt, and
 * REFUSES any result that is not real, in-Python numpy
 * (boundary.stats_engine_in_python !== true) — so a PHP stand-in can never pass
 * back through the boundary.
 *
 * Mirrors SemanticRagRuntimeClient EXACTLY (manifest temp-file, single Process
 * invocation of .venv/bin/python main.py, receipt enforcement). There is NO PHP
 * fallback math by design: if the runtime is not set up the client raises
 * explicitly (run scripts/setup-stats-engine-runtime.sh). That is the canon: a
 * real Python engine or an honest failure, never a hand-rolled stand-in.
 *
 * It is BATCH-capable: computeBatch() runs many stats in ONE subprocess so the
 * (CLI/scheduled) report engine pays a single boundary cost per run, not one per
 * metric. The Telemetry report engine is not on a synchronous request hot path,
 * so a per-report subprocess is acceptable per the create-path perf memory.
 */
final class StatsEngineRuntimeClient
{
    private const RUNTIME_ROOT = 'runtimes/python/stats_engine';

    private readonly PythonManifestRuntimeClient $runtime;

    public function __construct(?PythonManifestRuntimeClient $runtime = null)
    {
        $this->runtime = $runtime ?? new PythonManifestRuntimeClient(
            self::RUNTIME_ROOT,
            'atlas-stats-engine',
            'stats_engine Python runtime is not set up — run scripts/setup-stats-engine-runtime.sh. '
                .'The canon forbids a PHP statistics fallback; this is an explicit failure, not a silent hand-rolled stand-in.',
            'stats_engine',
        );
    }

    public function available(): bool
    {
        return $this->runtime->available();
    }

    /**
     * Two-sample Kolmogorov-Smirnov (with Mann-Whitney fallback for small n).
     *
     * @param  array<int,float>  $sample1
     * @param  array<int,float>  $sample2
     * @return array<string,mixed>
     */
    public function ks(array $sample1, array $sample2): array
    {
        return $this->run([
            'operation' => 'ks',
            'sample1' => array_values($sample1),
            'sample2' => array_values($sample2),
        ])['result'] ?? [];
    }

    /**
     * Mann-Kendall trend test + Sen's slope.
     *
     * @param  array<int,float>  $series
     * @return array<string,mixed>
     */
    public function mannKendall(array $series): array
    {
        return $this->run([
            'operation' => 'mann_kendall',
            'series' => array_values($series),
        ])['result'] ?? [];
    }

    /**
     * Two-sided tabular CUSUM change-point detection.
     *
     * @param  array<int,float>  $series
     * @return array<string,mixed>
     */
    public function cusum(array $series): array
    {
        return $this->run([
            'operation' => 'cusum',
            'series' => array_values($series),
        ])['result'] ?? [];
    }

    /**
     * EWMA single-day anomaly detection.
     *
     * @param  array<int,float>  $series
     * @return array<string,mixed>
     */
    public function ewma(
        array $series,
        float $alpha = 0.20,
        float $kSigma = 2.5,
        int $warmupDays = 10,
    ): array {
        return $this->run([
            'operation' => 'ewma',
            'series' => array_values($series),
            'alpha' => $alpha,
            'k_sigma' => $kSigma,
            'warmup_days' => $warmupDays,
        ])['result'] ?? [];
    }

    /**
     * Wilson score confidence interval for a proportion.
     *
     * @return array<string,mixed>
     */
    public function wilson(int $k, int $n, float $z = 1.96): array
    {
        return $this->run([
            'operation' => 'wilson',
            'k' => $k,
            'n' => $n,
            'z' => $z,
        ])['result'] ?? [];
    }

    /**
     * Bootstrap percentile confidence interval for the mean. The resampling is
     * done in numpy (vectorised draw-with-replacement); $seed threads through for
     * reproducibility.
     *
     * @param  array<int,float>  $values
     * @return array<string,mixed>
     */
    public function bootstrapMeanCi(
        array $values,
        int $replications = 500,
        float $alpha = 0.05,
        ?int $seed = null,
    ): array {
        return $this->run([
            'operation' => 'bootstrap_mean',
            'values' => array_values($values),
            'replications' => $replications,
            'alpha' => $alpha,
            'seed' => $seed,
        ])['result'] ?? [];
    }

    /**
     * Bootstrap percentile confidence interval for a quantile (e.g. p95 latency).
     * Resampling is done in numpy; $seed threads through for reproducibility.
     *
     * @param  array<int,float>  $values
     * @return array<string,mixed>
     */
    public function bootstrapPercentileCi(
        array $values,
        float $quantile = 0.95,
        int $replications = 500,
        float $alpha = 0.05,
        ?int $seed = null,
    ): array {
        return $this->run([
            'operation' => 'bootstrap_percentile',
            'values' => array_values($values),
            'quantile' => $quantile,
            'replications' => $replications,
            'alpha' => $alpha,
            'seed' => $seed,
        ])['result'] ?? [];
    }

    /**
     * Compute many stats in a SINGLE subprocess. Each job is
     * ['id' => string, 'op' => 'ks'|'mann_kendall'|'cusum'|'ewma'|'wilson', ...args].
     * Returns ['id' => result, ...] in the same id-keyed shape.
     *
     * @param  array<int,array<string,mixed>>  $jobs
     * @return array<string,array<string,mixed>>
     */
    public function computeBatch(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        $payload = $this->run([
            'operation' => 'batch',
            'jobs' => array_values($jobs),
        ]);

        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];

        /** @var array<string,array<string,mixed>> $results */
        return $results;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function run(array $manifest): array
    {
        $result = $this->runtime->run($manifest);

        // Boundary enforcement: a result is only accepted if it proves real,
        // in-Python numpy stats. This is where a PHP fake would be rejected.
        PythonBoundaryReceiptGuard::assertReal(
            $result,
            ['stats_engine_in_python', 'real_stats'],
            ['fabricated'],
            'stats_engine returned a non-real-stats boundary receipt — refusing (anti-fake guard).',
        );

        return $result;
    }
}
