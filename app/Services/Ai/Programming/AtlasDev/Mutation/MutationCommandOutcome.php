<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

/**
 * Outcome of a scoped infection invocation.
 *
 * Carries the raw process exit code / stdout / stderr (for failure diagnosis
 * and for VAL-E3-011: surfacing a "no coverage driver" false-fail honestly)
 * PLUS the parsed infection summary JSON, so {@see MutationTestingAdapter}
 * can read the REAL reported MSI (VAL-E3-007: verdict tracks the actual
 * score, never a self-declared one).
 *
 * Fields:
 *   - exitCode:        infection's process exit code (0 = clean run).
 *   - stdout/stderr:   raw subprocess output (audit/diagnostics).
 *   - durationMs:      wall-clock duration of the subprocess.
 *   - summaryPath:     absolute path to the --logger-summary-json file
 *                       infection wrote (or null if it did not write one).
 *   - summaryMsi:      MSI parsed from the summary JSON (0-100, float).
 *                       null when the summary could not be parsed or the
 *                       subprocess did not produce one (the adapter must not
 *                       fabricate a score in that case — VAL-E3-011).
 *   - summaryPayload:  the full decoded summary JSON, retained so downstream
 *                       anti-gaming checks (VAL-E3-005/006: mutator-skipping
 *                       and survivor-exclusion attempts) can inspect the
 *                       totalMutantsCount / killedCount / escapedCount etc.
 *   - perFileStats:    per-source-file MSI breakdown parsed from the
 *                       infection JSON report (--logger-json), used by the
 *                       gate for the anti-dilution check (VAL-E3-013: a weak
 *                       file below threshold cannot be masked by strong
 *                       files in the aggregate). Null when the JSON report
 *                       was not produced or could not be parsed.
 *
 * The summary payload schema is documented at
 * vendor/infection/infection/resources/schema.json (logs.summaryJson) and
 * matches the sample produced by `infection --logger-summary-json=...`.
 */
final class MutationCommandOutcome
{
    /**
     * @param  ?array<string,mixed>  $summaryPayload
     * @param  list<PerFileMutationStats>|null  $perFileStats
     */
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly ?string $summaryPath,
        public readonly ?float $summaryMsi,
        public readonly ?array $summaryPayload,
        public readonly ?array $perFileStats = null,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Honest "no coverage driver" detection. Infection prints a recognisable
     * diagnostic when pcov/xdebug is missing or when coverage collection
     * failed; the adapter surfaces this honestly (failed=true) so the gate
     * can never green over a missing-driver false-pass (VAL-E3-011).
     */
    public function indicatesMissingCoverageDriver(): bool
    {
        $combined = $this->stdout."\n".$this->stderr;

        return str_contains($combined, 'no coverage driver')
            || str_contains($combined, 'coverage driver')
            && str_contains($combined, 'not installed')
            || preg_match('/pcov.*(missing|not enabled)/i', $combined) === 1
            || preg_match('/xdebug.*(missing|not enabled)/i', $combined) === 1;
    }
}
