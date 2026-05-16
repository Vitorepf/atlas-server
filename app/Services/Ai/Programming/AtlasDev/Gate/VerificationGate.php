<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;

/**
 * Runs the LightTaskContract.validation_commands list and packages the result.
 *
 * Profiles supported:
 *   - php_laravel — runs the commands (typically `composer test ...`);
 *   - ts_react    — same exec path, used as a tag in evidence;
 *   - generic_no_test — accepts an empty validation_commands list iff the
 *     contract declared no_test_reason; otherwise emits honesty flag.
 *
 * Aggregate status:
 *   - empty + reason ok → passed (profile=generic_no_test);
 *   - empty + no reason → needs_review (honesty: test_skipped_no_reason);
 *   - all ok → passed;
 *   - some ok + some failed → failed;
 *   - all failed → failed;
 *   - scope_guard failed pre-empts → still runs but flags
 *     gate_run_with_scope_failed.
 */
final class VerificationGate
{
    public const PROFILE_PHP_LARAVEL = 'php_laravel';
    public const PROFILE_TS_REACT = 'ts_react';
    public const PROFILE_GENERIC_NO_TEST = 'generic_no_test';

    public const ALLOWED_PROFILES = [
        self::PROFILE_PHP_LARAVEL,
        self::PROFILE_TS_REACT,
        self::PROFILE_GENERIC_NO_TEST,
    ];

    public function __construct(
        private readonly VerificationCommandRunner $runner,
        private readonly ?ReceiptStorageAdapter $storage = null,
    ) {}

    public function run(
        LightTaskContract $taskContract,
        ProviderCallResult $callResult,
        ScopeGuardReceipt $scopeReceipt,
        string $workspace,
        int $timeoutSeconds = 300,
        string $profile = self::PROFILE_PHP_LARAVEL,
    ): VerificationGateResult {
        $profile = in_array($profile, self::ALLOWED_PROFILES, true) ? $profile : self::PROFILE_PHP_LARAVEL;
        $commands = $taskContract->validationCommands;
        $honestyFlags = [];

        if ($scopeReceipt->status === ScopeGuardReceipt::STATUS_FAILED) {
            $honestyFlags[] = 'verification_ran_with_scope_guard_failed';
        }
        if (! $callResult->ok()) {
            $honestyFlags[] = 'verification_ran_with_provider_errors';
        }

        if ($commands === []) {
            return $this->noCommandsResult($taskContract, $honestyFlags);
        }

        $tests = [];
        $evidenceRefs = [];
        $failed = 0;
        $passed = 0;
        $rejected = 0;

        foreach ($commands as $command) {
            $commandStr = trim($command);
            if ($commandStr === '') {
                $honestyFlags[] = 'verification_skipped_empty_command';
                continue;
            }

            $result = $this->runner->run($commandStr, $workspace, $timeoutSeconds);
            $outputPath = $this->persistOutput($callResult->runId, $tests, $result);
            $outputHash = hash('sha256', $result->combinedOutput());

            $tests[] = new TestRun(
                command: $commandStr,
                ok: $result->ok(),
                exitCode: $result->exitCode,
                durationMs: $result->durationMs,
                outputHash: $outputHash,
                outputPath: $outputPath,
            );

            $evidenceRefs[] = new EvidenceRef(
                kind: 'test_log',
                path: $outputPath ?? 'inline://test_log/'.$outputHash,
                hash: $outputHash,
            );

            if ($result->rejectedReason !== null) {
                $rejected++;
                $honestyFlags[] = 'verification_command_rejected:'.$result->rejectedReason;
                continue;
            }
            if ($result->timedOut) {
                $honestyFlags[] = 'verification_command_timed_out';
            }
            if ($result->ok()) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $aggregate = $this->aggregate($passed, $failed, $rejected);

        $gates = [
            new GateOutcome(
                name: 'verification_gate',
                status: $this->gateStatusFromAggregate($aggregate),
                required: true,
                evidenceRef: $tests !== [] ? ($tests[0]->outputPath ?? null) : null,
                fresh: true,
                waiverReason: null,
            ),
        ];

        $honestyFlags = array_values(array_unique($honestyFlags));

        return new VerificationGateResult(
            tests: $tests,
            gates: $gates,
            aggregateStatus: $aggregate,
            honestyFlags: $honestyFlags,
            evidenceRefs: $evidenceRefs,
            profile: $profile,
        );
    }

    /**
     * @param  list<string>  $honestyFlags
     */
    private function noCommandsResult(LightTaskContract $taskContract, array $honestyFlags): VerificationGateResult
    {
        $reason = $taskContract->noTestReason;
        if (is_string($reason) && trim($reason) !== '') {
            $gate = new GateOutcome(
                name: 'verification_gate',
                status: GateOutcome::STATUS_SKIPPED,
                required: false,
                evidenceRef: null,
                fresh: true,
                waiverReason: null,
            );

            return new VerificationGateResult(
                tests: [],
                gates: [$gate],
                aggregateStatus: VerificationGateResult::STATUS_PASSED,
                honestyFlags: array_values(array_unique([...$honestyFlags, 'no_tests_explicit_reason:'.trim($reason)])),
                evidenceRefs: [],
                profile: self::PROFILE_GENERIC_NO_TEST,
            );
        }

        $gate = new GateOutcome(
            name: 'verification_gate',
            status: GateOutcome::STATUS_NEEDS_REVIEW,
            required: true,
            evidenceRef: null,
            fresh: true,
            waiverReason: null,
        );

        return new VerificationGateResult(
            tests: [],
            gates: [$gate],
            aggregateStatus: VerificationGateResult::STATUS_NEEDS_REVIEW,
            honestyFlags: array_values(array_unique([...$honestyFlags, 'test_skipped_no_reason'])),
            evidenceRefs: [],
            profile: self::PROFILE_GENERIC_NO_TEST,
        );
    }

    private function aggregate(int $passed, int $failed, int $rejected): string
    {
        if ($failed > 0 || $rejected > 0) {
            return VerificationGateResult::STATUS_FAILED;
        }
        if ($passed === 0) {
            return VerificationGateResult::STATUS_NEEDS_REVIEW;
        }

        return VerificationGateResult::STATUS_PASSED;
    }

    private function gateStatusFromAggregate(string $aggregate): string
    {
        return match ($aggregate) {
            VerificationGateResult::STATUS_PASSED => GateOutcome::STATUS_PASSED,
            VerificationGateResult::STATUS_FAILED => GateOutcome::STATUS_FAILED,
            default => GateOutcome::STATUS_NEEDS_REVIEW,
        };
    }

    /**
     * Optional persistence: when ReceiptStorageAdapter is provided, we write
     * each command's combined output under storage/atlas-dev/receipts/<run_id>/
     * and link via outputPath. With no storage adapter we keep outputs in
     * memory (test mode).
     *
     * @param  list<TestRun>  $tests  used to compute monotonic suffix
     */
    private function persistOutput(string $runId, array $tests, VerificationCommandResult $result): ?string
    {
        if ($this->storage === null) {
            return null;
        }

        $index = count($tests) + 1;

        return $this->storage->writeTestLog($runId, $index, $result->combinedOutput());
    }
}
