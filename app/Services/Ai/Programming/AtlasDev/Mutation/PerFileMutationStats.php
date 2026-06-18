<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * Per-source-file mutation statistics for the E3 anti-dilution check
 * (VAL-E3-013).
 *
 * The aggregate union MSI can mask a weak test file when the scope spans
 * multiple source files: one file at MSI=40 can be diluted by another at
 * MSI=100, yielding an aggregate above threshold. This DTO carries the
 * per-file breakdown so {@see MutationScoreGate} can enforce that NO single
 * source file's MSI falls below the threshold, regardless of the aggregate
 * (VAL-E3-013: anti-dilution).
 *
 * The MSI here follows the SAME formula as the aggregate
 * {@see MutationTestingAdapter::computeRealMsi()}: numerator = killed +
 * error + syntaxError + timeout (Infection default timeoutsAsEscaped =
 * false); denominator = total (the FULL applicable mutant population for
 * that file, never reduced by patch-supplied skips — anti-gaming downward-
 * only divergence, VAL-E3-005/006).
 *
 * Fields:
 *  - $filePath:  repo-relative path of the mutated source file (app/...).
 *  - $msi:       per-file MSI in [0,100], computed over the file's mutants.
 *  - $killed:    numerator count (killed + error + syntaxError + timeout).
 *  - $total:     denominator count (total mutants for this file).
 */
final class PerFileMutationStats
{
    public function __construct(
        public readonly string $filePath,
        public readonly float $msi,
        public readonly int $killed,
        public readonly int $total,
    ) {}
}
