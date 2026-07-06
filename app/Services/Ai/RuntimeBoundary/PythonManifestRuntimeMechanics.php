<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * Shared mechanics of the Python runtime boundary clients (GraphRank /
 * StatsEngine / NearDuplicate / SemanticRag / HonestMetrics): the
 * PythonManifestRuntimeClient construction, available() delegation and the
 * receipt-guarded run(). Byte-identical across all five before extraction
 * (Obra #12 S-04); ONLY the mechanics live here — every boundary semantic
 * (runtime root, manifest prefix, setup message, receipt keys, refusal
 * message) stays declared per client via the consts below.
 *
 * Each using class must declare:
 *  - RUNTIME_ROOT / MANIFEST_PREFIX / SETUP_MESSAGE / RUNTIME_LABEL
 *    (PythonManifestRuntimeClient constructor args), and
 *  - BOUNDARY_REQUIRED_TRUE / BOUNDARY_REQUIRED_FALSE / BOUNDARY_REFUSAL
 *    (PythonBoundaryReceiptGuard::runReal args).
 */
trait PythonManifestRuntimeMechanics
{
    private readonly PythonManifestRuntimeClient $runtime;

    public function __construct(?PythonManifestRuntimeClient $runtime = null)
    {
        $this->runtime = $runtime ?? new PythonManifestRuntimeClient(
            self::RUNTIME_ROOT,
            self::MANIFEST_PREFIX,
            self::SETUP_MESSAGE,
            self::RUNTIME_LABEL,
        );
    }

    public function available(): bool
    {
        return $this->runtime->available();
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
            self::BOUNDARY_REQUIRED_TRUE,
            self::BOUNDARY_REQUIRED_FALSE,
            self::BOUNDARY_REFUSAL,
        );
    }
}
