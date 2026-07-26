<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\VerificationReceipt;

/**
 * F-03 fail-closed CompactSDD integrity for the live operate-path.
 *
 * Shared by {@see KernelRunExecutor} (live DI) and available for legacy PRE.
 * Missing / invalid / hash-tampered compact_sdd must throw before any provider
 * or kernel spend. {@see RunController} maps these to HTTP 422.
 */
final class CompactSddIntegrityGuard
{
    public function __construct(
        private readonly ReceiptStorage $storage,
    ) {}

    /**
     * @return array{0:string,1:string} [task_kind, risk_level]
     */
    public function resolveTaskKindAndRiskLevel(string $runId, ?string $expectedCompactSddHash = null): array
    {
        $compactSdd = $this->storage->read($runId, ArtifactNames::COMPACT_SDD);
        if (! is_array($compactSdd)) {
            throw CompactSddUnavailableException::missing($runId);
        }

        $taskKind = $compactSdd['task_kind'] ?? null;
        if (! is_string($taskKind) || ! in_array($taskKind, VerificationReceipt::ALLOWED_TASK_KINDS, true)) {
            $observed = is_string($taskKind) ? $taskKind : gettype($taskKind);
            throw CompactSddUnavailableException::invalid(
                $runId,
                "task_kind '{$observed}' is not in VerificationReceipt::ALLOWED_TASK_KINDS",
            );
        }

        $riskLevel = $compactSdd['risk_level'] ?? null;
        if (! is_string($riskLevel) || ! in_array($riskLevel, VerificationReceipt::ALLOWED_RISK_LEVELS, true)) {
            $observed = is_string($riskLevel) ? $riskLevel : gettype($riskLevel);
            throw CompactSddUnavailableException::invalid(
                $runId,
                "risk_level '{$observed}' is not in VerificationReceipt::ALLOWED_RISK_LEVELS",
            );
        }

        $this->assertCompactSddHashPinned($runId, $compactSdd, $expectedCompactSddHash);

        return [$taskKind, $riskLevel];
    }

    /**
     * @param  array<string, mixed>  $compactSdd
     */
    public function assertCompactSddHashPinned(
        string $runId,
        array $compactSdd,
        ?string $expectedCompactSddHash = null,
    ): void {
        $declaredHash = $compactSdd['compact_sdd_hash'] ?? null;
        if (! is_string($declaredHash) || $declaredHash === '') {
            throw CompactSddUnavailableException::invalid($runId, 'compact_sdd_hash is missing');
        }

        try {
            $dto = CompactSdd::fromArray($compactSdd);
        } catch (\Throwable $e) {
            throw CompactSddUnavailableException::invalid($runId, 'compact_sdd cannot be reconstructed: '.$e->getMessage());
        }

        $computedHash = $dto->hash();
        if (! hash_equals($declaredHash, $computedHash)) {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'compact_sdd_hash does not match the current compact_sdd payload',
            );
        }

        if ($expectedCompactSddHash !== null && $expectedCompactSddHash !== '') {
            if (! hash_equals($expectedCompactSddHash, $declaredHash)) {
                throw CompactSddUnavailableException::tampered(
                    $runId,
                    'compact_sdd_hash does not match the server-side pin issued at plan time',
                );
            }
        }

        $miniSpec = $this->storage->read($runId, ArtifactNames::MINI_PROGRAMMING_SPEC);
        $pinnedHash = is_array($miniSpec) ? ($miniSpec['compact_sdd_hash'] ?? null) : null;
        if (! is_string($pinnedHash) || $pinnedHash === '') {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'mini_programming_spec compact_sdd_hash pin is missing',
            );
        }

        if (! hash_equals($pinnedHash, $declaredHash)) {
            throw CompactSddUnavailableException::tampered(
                $runId,
                'compact_sdd_hash does not match the hash pinned by mini_programming_spec',
            );
        }
    }
}
