<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\EvidenceRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GateOutcome;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\TestRun;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;

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
        private readonly AtlasDevVerificationCommandRunnerContract $runner,
        private readonly ?ReceiptStorageAdapter $storage = null,
    ) {}

    /**
     * Run the verification gate.
     *
     * E5 caller-test selection (VAL-E5-006/007/008): the optional
     * `$codeGraph` payload carries `related_tests` (tests of direct callers
     * of changed symbols, resolved from the CodeGraph read-model by
     * {@see CallerTestSelectionService}). When non-empty, the floor's
     * {@see ProgrammingTestImpactAnalyzer} merges those tests into
     * `selected_existing_tests`, widening the floor so a patch that breaks a
     * caller's test T_C runs T_C and surfaces the failure. When empty or
     * absent (the default), the floor is byte-identical to pre-E5
     * (conventional impacted-tests selection only).
     *
     * @param  array<string,mixed>  $codeGraph  E5 caller-test selection
     *                                          payload (empty = conventional floor, byte-identical to pre-E5).
     */
    public function run(
        LightTaskContract $taskContract,
        ProviderCallResult $callResult,
        ScopeGuardReceipt $scopeReceipt,
        string $workspace,
        int $timeoutSeconds = 300,
        string $profile = self::PROFILE_PHP_LARAVEL,
        array $codeGraph = [],
    ): VerificationGateResult {
        $profile = in_array($profile, self::ALLOWED_PROFILES, true) ? $profile : self::PROFILE_PHP_LARAVEL;
        $honestyFlags = [];

        if ($scopeReceipt->status === ScopeGuardReceipt::STATUS_FAILED) {
            $honestyFlags[] = 'verification_ran_with_scope_guard_failed';
        }
        if (! $callResult->ok()) {
            $honestyFlags[] = 'verification_ran_with_provider_errors';
        }

        // M1: Compute the mandatory verification floor from observed diff.
        // E5: $codeGraph['related_tests'] widens the floor's impacted-tests
        // selection to include tests of direct callers (VAL-E5-006/007/008).
        $floorCommands = $this->computeFloorCommands($scopeReceipt, $workspace, $codeGraph);

        // Union of caller commands + floor commands, deduped
        $commands = $this->mergeCommands($taskContract->validationCommands, $floorCommands);

        // If no commands after floor computation and no impacted tests,
        // fall back to legacy noCommandsResult behavior (preserving honesty)
        if ($commands === [] && $floorCommands === []) {
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

        $honestyFlags = AtlasDevStringListNormalizer::uniqueTrimmedStrings($honestyFlags);

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
                honestyFlags: AtlasDevStringListNormalizer::uniqueTrimmedStrings([
                    ...$honestyFlags,
                    'no_tests_explicit_reason:'.trim($reason),
                ]),
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
            honestyFlags: AtlasDevStringListNormalizer::uniqueTrimmedStrings([
                ...$honestyFlags,
                'test_skipped_no_reason',
            ]),
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

    /**
     * M1: Compute the mandatory verification floor from observed diff.
     *
     * The floor includes:
     * - Impacted existing tests discovered via ProgrammingTestImpactAnalyzer
     *   (using selected_existing_tests, NOT all candidates, to avoid false failures)
     * - E5 caller-test selection: when `$codeGraph['related_tests']` is
     *   populated, the analyzer widens selected_existing_tests to include
     *   tests of direct callers (VAL-E5-006/007/008). Empty codeGraph =>
     *   byte-identical to pre-E5 conventional floor.
     * - `php -l` per touched .php file
     * - Configured lint (pint) - only if pint exists in the workspace
     *
     * @param  array<string,mixed>  $codeGraph  E5 caller-test selection payload.
     * @return list<string>
     */
    private function computeFloorCommands(ScopeGuardReceipt $scopeReceipt, string $workspace, array $codeGraph = []): array
    {
        $commands = [];

        // Extract changed file paths from the observed diff
        $changedFiles = array_map(
            static fn (ScopeFileDiff $diff): string => $diff->path,
            $scopeReceipt->observed->fileDiffs,
        );

        if ($changedFiles === []) {
            return [];
        }

        // Get impacted tests via ProgrammingTestImpactAnalyzer (REUSE, do not rebuild).
        // E5: $codeGraph flows related_tests (caller tests) into the analyzer so it
        // widens selected_existing_tests (VAL-E5-008: through the analyzer, not a
        // side channel). Empty codeGraph => conventional selection (byte-identical).
        $analyzer = new ProgrammingTestImpactAnalyzer;
        $impact = $analyzer->analyze($changedFiles, $codeGraph);

        // Add test commands for selected EXISTING tests only
        // This ensures only existing test files are forced (VAL-M1-008)
        $selectedExistingTests = $impact['selected_existing_tests'] ?? [];
        foreach ($selectedExistingTests as $testFile) {
            if (is_string($testFile) && trim($testFile) !== '') {
                $commands[] = '/opt/homebrew/bin/php artisan test '.trim($testFile);
            }
        }

        // Add php -l per touched .php file
        foreach ($changedFiles as $file) {
            if (str_ends_with(strtolower($file), '.php')) {
                $commands[] = 'php -l '.$file;
            }
        }

        // Add configured lint command (pint for PHP/Laravel) only if pint exists in workspace.
        // This prevents false failures on isolated fixture/test workspaces that don't have
        // full composer dependencies installed.
        $hasPhpFiles = count(array_filter(
            $changedFiles,
            static fn (string $f): bool => str_ends_with(strtolower($f), '.php'),
        )) > 0;

        $pintPath = rtrim($workspace, '/').'/vendor/bin/pint';
        if ($hasPhpFiles && file_exists($pintPath)) {
            $commands[] = './vendor/bin/pint --test';
        }

        return array_values(array_unique($commands));
    }

    /**
     * Merge caller commands with floor commands, deduped.
     *
     * Caller commands are never dropped; floor commands are added.
     *
     * @param  list<string>  $callerCommands
     * @param  list<string>  $floorCommands
     * @return list<string>
     */
    private function mergeCommands(array $callerCommands, array $floorCommands): array
    {
        $merged = [];

        // Add caller commands first (preserving their order)
        foreach ($callerCommands as $cmd) {
            $trimmed = trim($cmd);
            if ($trimmed !== '' && ! in_array($trimmed, $merged, true)) {
                $merged[] = $trimmed;
            }
        }

        // Add floor commands (deduped)
        foreach ($floorCommands as $cmd) {
            $trimmed = trim($cmd);
            if ($trimmed !== '' && ! in_array($trimmed, $merged, true)) {
                $merged[] = $trimmed;
            }
        }

        return $merged;
    }
}
