<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevProcessEnvironment;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Production implementation of {@see MutationCommandRunner}.
 *
 * Spawns the scoped infection subprocess in the workspace (repo root),
 * captures its stdout/stderr, and parses the infection summary JSON the
 * adapter told infection to write so the REAL reported MSI can be read
 * (VAL-E3-007). Mirrors SymfonyProcessCommandRunner's safety boundaries:
 *   - workspace must exist;
 *   - UnsafeCommandPolicy is consulted by the adapter before invoking;
 *   - timeout is honored via Symfony Process.
 *
 * The runner does NOT inspect the patch or compute scope: the adapter is
 * the sole source of the scoped command. The runner just runs it and
 * reports what infection observed, honestly (VAL-E3-011: a missing driver
 * is surfaced as a real failure, never a fabricated MSI).
 */
final class SymfonyMutationCommandRunner implements MutationCommandRunner
{
    public function run(string $command, string $workspace, int $timeoutSeconds): MutationCommandOutcome
    {
        if (! is_dir($workspace)) {
            return new MutationCommandOutcome(
                exitCode: 127,
                stdout: '',
                stderr: "MutationCommandRunner: workspace '{$workspace}' does not exist.",
                durationMs: 0,
                summaryPath: null,
                summaryMsi: null,
                summaryPayload: null,
                reportPath: null,
                reportPayload: null,
            );
        }

        $process = Process::fromShellCommandline(
            command: $command,
            cwd: $workspace,
            env: AtlasDevProcessEnvironment::verificationCommandEnv(),
            input: null,
            timeout: max(1, $timeoutSeconds),
        );
        $start = hrtime(true);
        $timedOut = false;
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $exitCode = $process->getExitCode() ?? ($timedOut ? 124 : 1);
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        // Parse the summary JSON path out of the command so we can read the
        // real reported MSI. The adapter always passes
        // --logger-summary-json=<path>; the path is the value of that flag.
        $summaryPath = $this->extractSummaryPath($command);
        $summaryPayload = $summaryPath !== null && is_file($summaryPath)
            ? $this->decodeSummary($summaryPath)
            : null;
        $summaryMsi = $this->extractMsi($summaryPayload);

        // VAL-E3-013: parse the full --logger-json report so per-source-file
        // MSI can be computed for the anti-dilution check (a weak file among
        // strong ones is not masked by a high aggregate). The adapter always
        // passes --logger-json=<path> when the run is non-skipped. We surface
        // BOTH the decoded report payload (so the adapter can group per-file
        // MSI from the per-status arrays — each entry carries
        // mutator.originalFilePath) AND a ready-made PerFileMutationStats list
        // (so a consumer that only wants the typed breakdown does not have to
        // re-parse the report).
        $reportPath = $this->extractReportPath($command);
        $reportPayload = $reportPath !== null && is_file($reportPath)
            ? $this->decodeSummary($reportPath)
            : null;
        $perFileStats = $reportPath !== null && is_file($reportPath)
            ? $this->computePerFileStats($reportPath)
            : null;

        return new MutationCommandOutcome(
            exitCode: $exitCode,
            stdout: $stdout,
            stderr: $stderr,
            durationMs: $durationMs,
            summaryPath: $summaryPath,
            summaryMsi: $summaryMsi,
            summaryPayload: $summaryPayload,
            reportPath: $reportPath,
            reportPayload: $reportPayload,
            perFileStats: $perFileStats,
        );
    }

    /**
     * Extract the --logger-summary-json=<path> value from the command line.
     * Returns null when the flag is absent.
     */
    private function extractSummaryPath(string $command): ?string
    {
        if (preg_match('/--logger-summary-json=([^\s]+)/', $command, $m) !== 1) {
            return null;
        }
        $value = $m[1];
        // Unescape a shell-quoted value if present.
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
            $value = str_replace("'\\''", "'", $value);
        }

        return $value;
    }

    /**
     * Extract the --logger-json=<path> value from the command line.
     * Returns null when the flag is absent.
     */
    private function extractReportPath(string $command): ?string
    {
        if (preg_match('/--logger-json=([^\s]+)/', $command, $m) !== 1) {
            return null;
        }
        $value = $m[1];
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
            $value = str_replace("'\\''", "'", $value);
        }

        return $value;
    }

    /**
     * @return ?array<string,mixed>
     */
    private function decodeSummary(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  ?array<string,mixed>  $payload
     */
    private function extractMsi(?array $payload): ?float
    {
        if ($payload === null) {
            return null;
        }
        $stats = $payload['stats'] ?? null;
        if (! is_array($stats)) {
            return null;
        }
        $msi = $stats['msi'] ?? null;
        if (! is_numeric($msi)) {
            return null;
        }

        return (float) $msi;
    }

    /**
     * Compute per-file mutation stats from the infection JSON report.
     *
     * The JSON report (produced by --logger-json) groups mutants by result
     * category: killed, escaped, errored, syntaxErrors, timeouted, uncovered,
     * ignored. Each entry carries mutator.originalFilePath. We group by file
     * and compute per-file MSI using the same formula as the aggregate:
     * numerator = killed + error + syntaxError + timeout; denominator = total
     * (all categories combined, anti-gaming: never reduced by patch-supplied
     * skips).
     *
     * @return list<PerFileMutationStats>|null
     */
    private function computePerFileStats(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        // Categories that count as "detected" in the MSI numerator (aligned
        // with MutationTestingAdapter::computeRealMsi: killed + error +
        // syntaxError + timeout).
        $detectedCategories = ['killed', 'errored', 'syntaxErrors', 'timeouted'];
        // All categories that carry mutants (every category is a potential
        // mutant source — the denominator is the full applicable population).
        $allCategories = ['killed', 'escaped', 'errored', 'syntaxErrors', 'timeouted', 'uncovered', 'ignored'];

        /** @var array<string, array{total:int, killed:int}> $byFile */
        $byFile = [];
        foreach ($allCategories as $category) {
            $entries = $decoded[$category] ?? null;
            if (! is_array($entries)) {
                continue;
            }
            $isDetected = in_array($category, $detectedCategories, true);
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $filePath = $this->extractFilePath($entry);
                if ($filePath === null) {
                    continue;
                }
                if (! isset($byFile[$filePath])) {
                    $byFile[$filePath] = ['total' => 0, 'killed' => 0];
                }
                $byFile[$filePath]['total']++;
                if ($isDetected) {
                    $byFile[$filePath]['killed']++;
                }
            }
        }

        $stats = [];
        foreach ($byFile as $filePath => $counts) {
            $total = $counts['total'];
            $killed = $counts['killed'];
            $msi = $total > 0 ? round(100.0 * $killed / $total, 2) : 0.0;
            $stats[] = new PerFileMutationStats(
                filePath: $filePath,
                msi: $msi,
                killed: $killed,
                total: $total,
            );
        }

        return $stats === [] ? null : $stats;
    }

    /**
     * Extract the original file path from a mutant entry in the JSON report.
     *
     * @param  array<string,mixed>  $entry
     */
    private function extractFilePath(array $entry): ?string
    {
        $mutator = $entry['mutator'] ?? null;
        if (is_array($mutator)) {
            $path = $mutator['originalFilePath'] ?? null;
            if (is_string($path) && $path !== '') {
                return $path;
            }
        }

        return null;
    }
}
