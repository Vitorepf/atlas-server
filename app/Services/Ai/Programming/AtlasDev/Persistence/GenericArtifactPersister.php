<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Persistence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationDecision;
use App\Services\Ai\Programming\AtlasDev\Schemas\FailureCapsule;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathErrorLedgerEntry;
use App\Services\Ai\Programming\AtlasDev\Schemas\FastPathTelemetry;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;
use InvalidArgumentException;
use RuntimeException;

/**
 * Wrapper around ReceiptStorage that persists Atlas Dev DTOs by their canonical
 * filenames and validates schema_version when reading back. Designed to be
 * append-only: writes never overwrite an existing artifact, and append-only
 * artifacts (failure capsules, error ledger entries) go through monotonic
 * versions.
 */
final class GenericArtifactPersister
{
    public function __construct(
        private readonly ReceiptStorage $storage,
    ) {}

    public function storage(): ReceiptStorage
    {
        return $this->storage;
    }

    public function writeScopeGuard(ScopeGuardReceipt $receipt): string
    {
        return $this->writeOnce($receipt->runId, ArtifactNames::SCOPE_GUARD_RECEIPT, $receipt);
    }

    public function readScopeGuard(string $runId): ?ScopeGuardReceipt
    {
        $payload = $this->readOnce($runId, ArtifactNames::SCOPE_GUARD_RECEIPT, ScopeGuardReceipt::SCHEMA_VERSION);

        return $payload === null ? null : ScopeGuardReceipt::fromArray($payload);
    }

    public function writeVerification(VerificationReceipt $receipt): string
    {
        return $this->writeOnce($receipt->runId, ArtifactNames::VERIFICATION_RECEIPT, $receipt);
    }

    public function readVerification(string $runId): ?VerificationReceipt
    {
        $payload = $this->readOnce($runId, ArtifactNames::VERIFICATION_RECEIPT, VerificationReceipt::SCHEMA_VERSION);

        return $payload === null ? null : VerificationReceipt::fromArray($payload);
    }

    public function writeEscalationDecision(EscalationDecision $decision): string
    {
        return $this->writeOnce($decision->runId, ArtifactNames::ESCALATION_DECISION, $decision);
    }

    public function readEscalationDecision(string $runId): ?EscalationDecision
    {
        $payload = $this->readOnce($runId, ArtifactNames::ESCALATION_DECISION, EscalationDecision::SCHEMA_VERSION);

        return $payload === null ? null : EscalationDecision::fromArray($payload);
    }

    /**
     * Failure capsules are monotonic per attempt. The filename embeds the
     * attempt index so callers can map back to (run_id, attempt_index).
     *
     * @return array{path: string, attempt_index: int}
     */
    public function writeFailureCapsule(FailureCapsule $capsule): array
    {
        $name = ArtifactNames::FAILURE_CAPSULE_BASE.'.'.$capsule->attemptIndex.'.json';
        $path = $this->writeOnce($capsule->runId, $name, $capsule);

        return ['path' => $path, 'attempt_index' => $capsule->attemptIndex];
    }

    public function readFailureCapsule(string $runId, int $attemptIndex): ?FailureCapsule
    {
        if ($attemptIndex < 0) {
            throw new InvalidArgumentException('attempt_index must be non-negative.');
        }
        $name = ArtifactNames::FAILURE_CAPSULE_BASE.'.'.$attemptIndex.'.json';
        $payload = $this->readOnce($runId, $name, FailureCapsule::SCHEMA_VERSION);

        return $payload === null ? null : FailureCapsule::fromArray($payload);
    }

    public function writeTelemetry(FastPathTelemetry $telemetry): string
    {
        return $this->writeOnce($telemetry->runId, ArtifactNames::FAST_PATH_TELEMETRY, $telemetry);
    }

    public function readTelemetry(string $runId): ?FastPathTelemetry
    {
        $payload = $this->readOnce($runId, ArtifactNames::FAST_PATH_TELEMETRY, FastPathTelemetry::SCHEMA_VERSION);

        return $payload === null ? null : FastPathTelemetry::fromArray($payload);
    }

    /**
     * Error ledger entries are append-only via monotonic versions.
     *
     * @return array{path: string, version: int}
     */
    public function appendErrorLedger(FastPathErrorLedgerEntry $entry): array
    {
        return $this->storage->writeMonotonic($entry->runId, ArtifactNames::ERROR_LEDGER_BASE, $entry->toCanonicalArray());
    }

    /**
     * @return list<FastPathErrorLedgerEntry>
     */
    public function readErrorLedger(string $runId): array
    {
        $versions = $this->storage->listVersions($runId, ArtifactNames::ERROR_LEDGER_BASE);
        $entries = [];
        foreach ($versions as $v) {
            $payload = $this->storage->readVersion($runId, ArtifactNames::ERROR_LEDGER_BASE, $v);
            if ($payload === null) {
                continue;
            }
            $this->assertSchemaVersion($payload, FastPathErrorLedgerEntry::SCHEMA_VERSION,
                ArtifactNames::ERROR_LEDGER_BASE.".v{$v}.json");
            $entries[] = FastPathErrorLedgerEntry::fromArray($payload);
        }

        return $entries;
    }

    private function writeOnce(string $runId, string $filename, AtlasDevSchemaContract $artifact): string
    {
        return $this->storage->writeAtomic($runId, $filename, $artifact->toCanonicalArray());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readOnce(string $runId, string $filename, string $expectedSchemaVersion): ?array
    {
        $payload = $this->storage->read($runId, $filename);
        if ($payload === null) {
            return null;
        }
        $this->assertSchemaVersion($payload, $expectedSchemaVersion, $filename);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSchemaVersion(array $payload, string $expected, string $filename): void
    {
        $actual = $payload['schema_version'] ?? null;
        if (! is_string($actual) || $actual !== $expected) {
            throw new RuntimeException(
                "GenericArtifactPersister: schema_version mismatch in '{$filename}'. Expected '{$expected}', got '".(string) ($actual ?? 'null')."'."
            );
        }
    }
}
