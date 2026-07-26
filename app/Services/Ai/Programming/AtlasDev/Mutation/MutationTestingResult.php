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
 *   - {@see $rawCounts}:  the raw mutant counts from the summary JSON
 *                         (totalMutantsCount, killedCount, escapedCount,
 *                         etc.) for auditability and anti-gaming evidence.
 *
 * Per-file anti-dilution (VAL-E3-013): {@see $perFileStats} carries the
 * per-source-file MSI breakdown so the gate can enforce that NO single
 * source file's MSI falls below the threshold, regardless of the aggregate.
 * A weak file at MSI=40 cannot be masked/diluted by a strong file at MSI=100
 * yielding an aggregate above threshold. The breakdown is carried in EITHER
 * of two interchangeable shapes (the gate handles both):
 *   - an associative array keyed by source-file path, each value
 *     {msi, killed, escaped, total} — produced by the adapter when it parses
 *     the infection --logger-json report's per-status arrays (grouped by
 *     mutator.originalFilePath); or
 *   - a {@see PerFileMutationStats} list — when a runner hands the adapter a
 *     ready-made typed breakdown.
 * Null when the run was skipped/failed (no per-file data to report), or when
 * per-file data was not available from the report (the gate treats null
 * perFileStats as "no per-file check possible" — the aggregate check still
 * applies).
 *
 * The result is a pure data carrier: the gate decides the verdict; the
 * adapter only reports what infection observed.
 */
final class MutationTestingResult
{
    /**
     * @param  ?array<string,mixed>  $rawCounts
     * @param  array<string,array{msi:float,killed:int,escaped:int,total:int}>|list<PerFileMutationStats>|null  $perFileStats
     *                                                                                                                         per-source-file MSI breakdown for the anti-dilution check
     *                                                                                                                         (VAL-E3-013), or null when the run was skipped/failed or
     *                                                                                                                         per-file data was unavailable.
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
        public readonly ?string $reportPath = null,
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

    public static function failed(
        string $reason,
        ?string $summaryPath = null,
        ?MutationScope $scope = null,
        ?array $rawCounts = null,
        ?array $perFileStats = null,
        ?string $reportPath = null,
    ): self {
        return new self(
            skipped: false,
            skipReason: '',
            failed: true,
            failureReason: $reason,
            msi: null,
            summaryPath: $summaryPath,
            scope: $scope,
            rawCounts: $rawCounts,
            perFileStats: $perFileStats,
            reportPath: $reportPath,
        );
    }

    /**
     * @param  ?array<string,mixed>  $rawCounts
     * @param  array<string,array{msi:float,killed:int,escaped:int,total:int}>|list<PerFileMutationStats>|null  $perFileStats
     */
    public static function completed(
        float $msi,
        string $summaryPath,
        MutationScope $scope,
        ?float $realMsi = null,
        ?array $rawCounts = null,
        ?array $perFileStats = null,
        ?string $reportPath = null,
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
            reportPath: $reportPath,
        );
    }
}
