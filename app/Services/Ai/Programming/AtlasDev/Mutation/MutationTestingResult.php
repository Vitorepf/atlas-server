<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * Outcome of a {@see MutationTestingAdapter::run()} call.
 *
 * The result is the structured input the E3 gate (e3-mutation-score-gate
 * feature) consumes to compute its verdict. This feature
 * (e3-infection-adapter-scoping) produces the result; the next feature
 * applies the MSI threshold / honesty flag / STATUS_FAILED.
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
 * The result is a pure data carrier: the gate decides the verdict; the
 * adapter only reports what infection observed.
 */
final class MutationTestingResult
{
    public function __construct(
        public readonly bool $skipped,
        public readonly string $skipReason,
        public readonly bool $failed,
        public readonly string $failureReason,
        public readonly ?float $msi,
        public readonly ?string $summaryPath,
        public readonly ?MutationScope $scope,
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
    ): self {
        return new self(
            skipped: false,
            skipReason: '',
            failed: false,
            failureReason: '',
            msi: $msi,
            summaryPath: $summaryPath,
            scope: $scope,
        );
    }
}
