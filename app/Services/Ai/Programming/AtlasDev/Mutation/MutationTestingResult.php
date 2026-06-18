<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * Outcome of a {@see MutationTestingAdapter::run()} call.
 *
 * The result is the structured input the E3 gate (e3-mutation-score-gate +
 * e3-mutation-anti-gaming features) consumes to compute its verdict. The
 * adapter produces the result; the gate applies the threshold / honesty
 * flag / STATUS_FAILED.
 *
 * Channels:
 *   - SKIPPED (no-op):   {@see $skipped} === true with a non-empty
 *                         {@see $skipReason}. MSI is null, scope is null (or
 *                         empty), and no infection subprocess was invoked.
 *                         This is the VAL-E3-008 path (no test files
 *                         touched) and the VAL-E3-010 off-mode path.
 *   - FAILED:            {@see $failed} === true with a non-empty
 *                         {@see $failureReason}. MSI is null (no score is
 *                         fabricated over a failed/unevaluable run —
 *                         VAL-E3-011 honest-ceiling: never a silent green).
 *   - COMPLETED:         {@see $msi} is a non-null float in [0,100] parsed
 *                         from the infection summary JSON
 *                         (VAL-E3-007: real reported MSI, no self-declared
 *                         score), {@see $summaryPath} points at the
 *                         reproducible artifact (VAL-CROSS-016), and
 *                         {@see $scope} carries the exact scope the run was
 *                         scoped to (anti-gaming evidence for VAL-E3-005/006
 *                         and VAL-E3-012/013).
 *
 * Anti-gaming fields (e3-mutation-anti-gaming):
 *
 *   - {@see $realMsi}:    the REAL MSI recomputed over the FULL applicable
 *                         mutant population using totalMutantsCount as the
 *                         denominator (immune to skipped/ignored inflation).
 *                         Infection's own stats.msi subtracts skipped +
 *                         ignored from the denominator (Calculator in
 *                         vendor/infection), so a patch config that marks
 *                         survivors as IGNORED inflates infection's MSI.
 *                         The gate uses realMsi — NEVER infection's reported
 *                         msi — so survivor-exclusion cannot raise the gated
 *                         MSI (VAL-E3-006). Mutator-skipping is defeated by
 *                         the adapter always running the full default
 *                         mutator set (VAL-E3-005).
 *
 *   - {@see $perFileStats}: per-source-file MSI keyed by the source file
 *                         path (VAL-E3-013). Parsed from the --logger-json
 *                         report's per-status arrays (grouped by
 *                         mutator.originalFilePath). A weak file (below
 *                         threshold) among robust ones is NOT masked by a
 *                         high aggregate MSI: the gate trips if ANY source
 *                         file's MSI is below the threshold. Null when the
 *                         report was not available (e.g. a single-file run
 *                         where per-file == aggregate, or a degraded run).
 *
 *   - {@see $rawCounts}:  the raw mutant counts from the summary JSON
 *                         (totalMutantsCount, killedCount, escapedCount,
 *                         etc.) for auditability and anti-gaming evidence.
 *
 * The result is a pure data carrier: the gate decides the verdict; the
 * adapter only reports what infection observed.
 */
final class MutationTestingResult
{
    /**
     * @param  ?array<string,mixed>  $rawCounts
     * @param  ?array<string,array{msi:float,killed:int,escaped:int,total:int}>  $perFileStats
     */
    public function __construct(
        public readonly bool $skipped,
        public readonly string $skipReason,
        public readonly bool $failed,
        public readonly string $failureReason,
        public readonly ?float $msi,
        public readonly ?string $summaryPath,
        public readonly ?MutationScope $scope,
        public readonly ?float $realMsi = null,
        public readonly ?array $rawCounts = null,
        public readonly ?array $perFileStats = null,
    ) {}

    public static function skipped(string $reason): self
    {
        return new self(
            skipped: true,
            skipReason: $reason,
            failed: false,
            failureReason: '',
            msi: null,
            summaryPath: null,
            scope: null,
        );
    }

    public static function failed(string $reason): self
    {
        return new self(
            skipped: false,
            skipReason: '',
            failed: true,
            failureReason: $reason,
            msi: null,
            summaryPath: null,
            scope: null,
        );
    }

    public static function completed(
        float $msi,
        string $summaryPath,
        MutationScope $scope,
        ?float $realMsi = null,
        ?array $rawCounts = null,
        ?array $perFileStats = null,
    ): self {
        return new self(
            skipped: false,
            skipReason: '',
            failed: false,
            failureReason: '',
            msi: $msi,
            summaryPath: $summaryPath,
            scope: $scope,
            realMsi: $realMsi,
            rawCounts: $rawCounts,
            perFileStats: $perFileStats,
        );
    }
}
