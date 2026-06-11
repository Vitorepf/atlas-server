<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * PHP adapter to the REAL Python near-duplicate-memory data runtime
 * (runtimes/python/near_duplicate). By the runtime_language_boundary canon — and
 * the operator thesis "Python é o melhor para dados; nunca faça em PHP o que
 * deveria ser Python" — the PHP kernel must NOT hand-roll the O(n^2) pairwise
 * shingle-Jaccard double loop + transitive-closure clustering; it invokes this
 * Python runtime (numpy) instead. This client is the governed bridge: it writes a
 * manifest, runs the runtime under its own venv (where numpy lives), parses the
 * receipt, and REFUSES any result that is not real, in-Python numpy
 * (boundary.near_duplicate_in_python !== true) — so a PHP stand-in can never pass
 * back through the boundary.
 *
 * Mirrors StatsEngineRuntimeClient EXACTLY (manifest temp-file, single Process
 * invocation of .venv/bin/python main.py, receipt enforcement). There is NO PHP
 * fallback math by design: if the runtime is not set up the client raises
 * explicitly (run scripts/setup-near-duplicate-runtime.sh). That is the canon: a
 * real Python engine or an honest failure, never a hand-rolled stand-in.
 *
 * Memory governance dedup runs off the synchronous request hot path (CLI /
 * scheduled maintenance), so a per-invocation subprocess is acceptable per the
 * create-path perf memory; the whole detection is computed in ONE subprocess.
 */
final class NearDuplicateRuntimeClient
{
    private const RUNTIME_ROOT = 'runtimes/python/near_duplicate';

    private readonly PythonManifestRuntimeClient $runtime;

    public function __construct(?PythonManifestRuntimeClient $runtime = null)
    {
        $this->runtime = $runtime ?? new PythonManifestRuntimeClient(
            self::RUNTIME_ROOT,
            'atlas-near-duplicate',
            'near_duplicate Python runtime is not set up — run scripts/setup-near-duplicate-runtime.sh. '
                .'The canon forbids a PHP near-duplicate fallback; this is an explicit failure, not a silent hand-rolled stand-in.',
            'near_duplicate',
        );
    }

    public function available(): bool
    {
        return $this->runtime->available();
    }

    /**
     * Detect near-duplicate memory rows via vectorised token-shingle Jaccard,
     * clustering and canonical selection computed entirely in numpy.
     *
     * Each row is ['id' => int|string, 'tokens' => list<string>, 'memory_type' =>
     * string, 'scope' => string, 'priority' => int, 'importance' => int, 'recency'
     * => int]. Returns the full near-duplicate result (schema_version, threshold,
     * evaluated, clusters[...], cluster_count, duplicate_count) — exactly the shape
     * the removed PHP hand-rolled detector returned, minus the internal boundary
     * receipt (which is verified and stripped here).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function detect(array $rows, float $threshold = 0.82): array
    {
        $result = $this->run([
            'operation' => 'detect',
            'rows' => array_values($rows),
            'threshold' => $threshold,
        ]);

        // The boundary receipt has been verified in run(); strip it so callers see
        // the same array shape the old PHP detector returned (no boundary key).
        unset($result['boundary']);

        return $result;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function run(array $manifest): array
    {
        return PythonBoundaryReceiptGuard::runReal(
            $this->runtime,
            $manifest,
            ['near_duplicate_in_python', 'real_jaccard'],
            ['fabricated'],
            'near_duplicate returned a non-real-engine boundary receipt — refusing (anti-fake guard).',
        );
    }
}
