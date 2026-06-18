<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationCommandOutcome;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Mutation\PerFileMutationStats;

/**
 * In-memory runner for MutationTestingAdapter tests.
 *
 * Records every command/workspace/timeout call and returns a pre-queued
 * MutationCommandOutcome. Mirrors the FakeCommandRunner pattern from the
 * VerificationGate harness so the E3 adapter is exercised without ever
 * spawning a real infection subprocess in unit tests.
 *
 * Real-infection behaviour (pcov driver, scoped MSI) is proven by an
 * end-to-end manual check (see the handoff interactiveChecks); unit tests
 * assert the command shape + scope computation.
 */
final class FakeMutationCommandRunner implements MutationCommandRunner
{
    /** @var list<array{command:string,workspace:string,timeout:int}> */
    public array $calls = [];

    /** @var list<MutationCommandOutcome> */
    private array $queue = [];

    public function queue(MutationCommandOutcome $result): void
    {
        $this->queue[] = $result;
    }

    /**
     * Queue a successful infection run that wrote the given MSI to its
     * summary JSON at $summaryPath.
     *
     * The fake produces a payload whose raw counts are INTERNALLY CONSISTENT
     * with the queued MSI (m3-e3 scrutiny Defect 2: computeRealMsi recomputes
     * from the counts, so the counts must back the reported MSI for an honest
     * run). The default fixture is an all-killed honest run at the requested
     * MSI: we pick a small total and compute the killed count so the recomputed
     * MSI rounds to the queued value.
     *
     * @param  list<PerFileMutationStats>|null  $perFileStats
     */
    public function queueOk(float $msi, string $summaryPath, ?array $perFileStats = null): void
    {
        // Pick the smallest denominator (up to a sane cap) that represents
        // the queued MSI within rounding, so the recomputed MSI (Defect 2:
        // computeRealMsi derives from counts) is internally consistent with
        // the reported MSI for an honest run.
        $total = 1;
        $killed = 0;
        for ($candidate = 1; $candidate <= 1000; $candidate++) {
            $k = (int) round($msi * $candidate / 100.0);
            $recomputed = $candidate > 0 ? round(100.0 * $k / $candidate, 2) : 0.0;
            if (abs($recomputed - $msi) < 0.005) {
                $total = $candidate;
                $killed = $k;
                break;
            }
        }
        $escaped = $total - $killed;
        $recomputed = $total > 0 ? round(100.0 * $killed / $total, 2) : 0.0;

        $this->queue(new MutationCommandOutcome(
            exitCode: 0,
            stdout: 'infection ok',
            stderr: '',
            durationMs: 0,
            summaryPath: $summaryPath,
            summaryMsi: $recomputed,
            summaryPayload: [
                'stats' => [
                    'totalMutantsCount' => $total,
                    'killedCount' => $killed,
                    'notCoveredCount' => 0,
                    'escapedCount' => $escaped,
                    'errorCount' => 0,
                    'syntaxErrorCount' => 0,
                    'skippedCount' => 0,
                    'ignoredCount' => 0,
                    'timeOutCount' => 0,
                    'msi' => $recomputed,
                    'mutationCodeCoverage' => 100,
                    'coveredCodeMsi' => $recomputed,
                ],
            ],
            perFileStats: $perFileStats,
        ));
    }

    /**
     * Queue a successful infection run with an EXACT raw-stats payload.
     *
     * Use this when a test needs the counts to back a specific MSI that the
     * default {@see queueOk()} fixture cannot represent (e.g. a payload with
     * syntax-error or skipped mutants). The MSI is recomputed from the counts
     * (m3-e3 scrutiny Defect 2) and MUST be consistent with them.
     *
     * @param  array<string,mixed>  $stats  the raw infection summary stats.
     */
    public function queueOkWithStats(array $stats, string $summaryPath): void
    {
        $total = is_numeric($stats['totalMutantsCount'] ?? 0) ? (int) $stats['totalMutantsCount'] : 0;
        $killed = is_numeric($stats['killedCount'] ?? 0) ? (int) $stats['killedCount'] : 0;
        $error = is_numeric($stats['errorCount'] ?? 0) ? (int) $stats['errorCount'] : 0;
        $syntax = is_numeric($stats['syntaxErrorCount'] ?? 0) ? (int) $stats['syntaxErrorCount'] : 0;
        $timeout = is_numeric($stats['timeOutCount'] ?? 0) ? (int) $stats['timeOutCount'] : 0;
        $msi = $total > 0 ? round(100.0 * ($killed + $error + $syntax + $timeout) / $total, 2) : 0.0;
        $stats['msi'] = $msi;

        $this->queue(new MutationCommandOutcome(
            exitCode: 0,
            stdout: 'infection ok',
            stderr: '',
            durationMs: 0,
            summaryPath: $summaryPath,
            summaryMsi: $msi,
            summaryPayload: ['stats' => $stats],
        ));
    }

    /**
     * Queue a failing infection run (non-zero exit) with the given stdout.
     */
    public function queueFailure(string $stdout): void
    {
        $this->queue(new MutationCommandOutcome(
            exitCode: 1,
            stdout: $stdout,
            stderr: '',
            durationMs: 0,
            summaryPath: null,
            summaryMsi: null,
            summaryPayload: null,
        ));
    }

    public function run(string $command, string $workspace, int $timeoutSeconds): MutationCommandOutcome
    {
        $this->calls[] = [
            'command' => $command,
            'workspace' => $workspace,
            'timeout' => $timeoutSeconds,
        ];

        $rejected = UnsafeCommandPolicy::reasonIfUnsafe($command);
        if ($rejected !== null) {
            return new MutationCommandOutcome(
                exitCode: 126,
                stdout: '',
                stderr: 'rejected by fake runner: '.$rejected,
                durationMs: 0,
                summaryPath: null,
                summaryMsi: null,
                summaryPayload: null,
            );
        }

        if ($this->queue === []) {
            return new MutationCommandOutcome(
                exitCode: 0,
                stdout: 'ok (default success)',
                stderr: '',
                durationMs: 0,
                summaryPath: '/tmp/fake-summary.json',
                summaryMsi: 100.0,
                summaryPayload: ['stats' => ['msi' => 100.0]],
            );
        }

        return array_shift($this->queue);
    }
}
