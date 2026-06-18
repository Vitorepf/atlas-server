<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Mutation;

use App\Services\Ai\Programming\AtlasDev\Gate\UnsafeCommandPolicy;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationCommandRunner;
use App\Services\Ai\Programming\AtlasDev\Mutation\MutationCommandOutcome;

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
     */
    public function queueOk(float $msi, string $summaryPath): void
    {
        $this->queue(new MutationCommandOutcome(
            exitCode: 0,
            stdout: 'infection ok',
            stderr: '',
            durationMs: 0,
            summaryPath: $summaryPath,
            summaryMsi: $msi,
            summaryPayload: [
                'stats' => [
                    'totalMutantsCount' => 2,
                    'killedCount' => 2,
                    'notCoveredCount' => 0,
                    'escapedCount' => 0,
                    'errorCount' => 0,
                    'syntaxErrorCount' => 0,
                    'skippedCount' => 0,
                    'ignoredCount' => 0,
                    'timeOutCount' => 0,
                    'msi' => $msi,
                    'mutationCodeCoverage' => 100,
                    'coveredCodeMsi' => $msi,
                ],
            ],
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
