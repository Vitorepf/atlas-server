<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Telemetry;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\GenericArtifactPersister;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathTelemetry;
use RuntimeException;

/**
 * Emits FastPathTelemetry exactly once per run. Refuses to emit twice for the
 * same run_id (the underlying ReceiptStorage refuses to overwrite the file,
 * but we surface that as a domain-level "already emitted" check).
 */
final class TelemetryEmitter
{
    public function __construct(
        private readonly GenericArtifactPersister $persister,
    ) {}

    /**
     * @return array{path: string, telemetry_hash: string}
     */
    public function emit(FastPathTelemetry $telemetry): array
    {
        if ($this->persister->storage()->exists($telemetry->runId, ArtifactNames::FAST_PATH_TELEMETRY)) {
            throw new RuntimeException(
                "TelemetryEmitter invariant: telemetry already emitted for run_id '{$telemetry->runId}'. "
                .'Emit is once-per-run.'
            );
        }

        $path = $this->persister->writeTelemetry($telemetry);

        return ['path' => $path, 'telemetry_hash' => $telemetry->telemetryHash];
    }

    public function alreadyEmittedFor(string $runId): bool
    {
        return $this->persister->storage()->exists($runId, ArtifactNames::FAST_PATH_TELEMETRY);
    }

    public function read(string $runId): ?FastPathTelemetry
    {
        return $this->persister->readTelemetry($runId);
    }
}
