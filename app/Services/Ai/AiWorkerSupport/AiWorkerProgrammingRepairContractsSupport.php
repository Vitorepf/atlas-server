<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

use App\Services\Ai\Kernel\Failure\FailureDomain;

/**
 * Pure programming-repair contract / quality / ledger helpers (full-pass peel
 * from ProgrammingRepairSupportSection). No DI, no models.
 */
final class AiWorkerProgrammingRepairContractsSupport
{
    /**
     * @param  array<int,mixed>  $candidates
     * @return array<string,mixed>
     */
    public static function firstNonEmptyArray(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $gateContract
     */
    public static function gateRequiresEvidence(array $gateContract): bool
    {
        $minimum = strtolower(trim((string) ($gateContract['minimum_gate'] ?? '')));

        return (bool) ($gateContract['evidence_required'] ?? false)
            || in_array($minimum, ['strict', 'release'], true);
    }

    /**
     * @param  array<string,mixed>  $toolContract
     */
    public static function allowsWorkspaceWrite(array $toolContract): bool
    {
        if ($toolContract === []) {
            return true;
        }

        $mode = strtolower(trim((string) ($toolContract['mode'] ?? '')));
        if ($mode === 'read_only') {
            return false;
        }

        return (bool) ($toolContract['workspace_write'] ?? in_array($mode, ['workspace_write', 'harness'], true));
    }

    public static function qualityWorsened(string $currentStatus, mixed $previousStatus): bool
    {
        if (! is_string($previousStatus) || trim($previousStatus) === '') {
            return false;
        }

        return self::statusRank($currentStatus) < self::statusRank($previousStatus);
    }

    public static function statusRank(string $status): int
    {
        return match ($status) {
            'passed' => 4,
            'needs_review' => 3,
            'failed' => 2,
            'blocked' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return array<string,mixed>
     */
    public static function evidenceProjection(array $quality): array
    {
        return [
            'status' => $quality['status'] ?? null,
            'score' => $quality['score'] ?? null,
            'decision' => $quality['decision'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'test_status' => data_get($quality, 'tests.status'),
            'lint_status' => data_get($quality, 'lint.status'),
            'typecheck_status' => data_get($quality, 'typecheck.status'),
        ];
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public static function ledgerPayload(array $quality, array $extra = []): array
    {
        return array_merge([
            'quality_status' => $quality['status'] ?? null,
            'quality_score' => $quality['score'] ?? null,
            'quality_decision' => $quality['decision'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'test_command_hash' => is_string($quality['test_command'] ?? null) && $quality['test_command'] !== ''
                ? hash('sha256', $quality['test_command'])
                : null,
            'evidence_hash' => hash('sha256', json_encode(self::evidenceProjection($quality), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        ], $extra);
    }

    public static function failureDomain(string $qualityStatus): FailureDomain
    {
        return match ($qualityStatus) {
            'failed', 'needs_review', 'blocked' => FailureDomain::GateFailed,
            default => FailureDomain::OutputInvalid,
        };
    }

    /**
     * @param  array<string,mixed>  $quality
     * @return list<string>
     */
    public static function evidenceRefs(
        ?string $traceId,
        string $jobId,
        string $attemptId,
        array $quality,
    ): array {
        return array_values(array_filter([
            $traceId ? 'trace://'.$traceId : null,
            'ai-job://'.$jobId,
            'ai-attempt://'.$attemptId,
            is_string($quality['diff_hash'] ?? null) && $quality['diff_hash'] !== ''
                ? 'diff-hash://'.$quality['diff_hash']
                : null,
        ]));
    }
}
