<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\VerificationCommandResult;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGate;
use App\Services\Ai\Programming\AtlasDev\Gate\VerificationGateResult;
use App\Services\Ai\Programming\AtlasDev\Provider\ProviderCallResult;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeContractView;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use App\Services\Ai\Programming\AtlasDev\Schemas\ScopeGuardReceipt;
use App\Services\Ai\Programming\ProgrammingTestImpactAnalyzer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Gate\FakeCommandRunner;
use Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

/**
 * Tests for the M1 Verification Floor that wires ProgrammingTestImpactAnalyzer + php -l + lint
 * into the default hermes path as a MANDATORY verification floor.
 *
 * VAL-M1-001 through VAL-M1-012 assertions.
 */
final class VerificationFloorTest extends TestCase
{
    use AtlasDevProviderFixtures;

    private function makeGate(FakeCommandRunner $runner): VerificationGate
    {
        return new VerificationGate($runner);
    }

    private function callResult(string $provider = 'hermes_cli'): ProviderCallResult
    {
        return ProviderCallResult::fromStdout(
            runId: 'run-floor-test',
            actualProvider: $provider,
            actualModelFamily: 'minimax-m3',
            exitStatus: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 100,
        );
    }

    private function scopeReceiptWithFileDiffs(array $fileDiffs): ScopeGuardReceipt
    {
        $scopeFileDiffs = array_map(
            fn (string $path): ScopeFileDiff => new ScopeFileDiff(
                path: $path,
                added: 5,
                removed: 2,
                fileHashAfter: hash('sha256', $path),
            ),
            $fileDiffs,
        );

        $observed = new ScopeObserved(
            gitDiffHash: hash('sha256', implode(',', $fileDiffs)),
            changedFiles: $fileDiffs,
            changedFilesCount: count($fileDiffs),
            fileDiffs: $scopeFileDiffs,
        );

        return ScopeGuardReceipt::issue(
            runId: 'run-floor-test',
            taskContractHash: 'test-hash',
            baseline: new ScopeBaseline(
                gitStatusBefore: 'clean',
                gitDiffBeforeHash: null,
            ),
            observed: $observed,
            scopeContract: new ScopeContractView(
                allowedFiles: $fileDiffs,
                watchedFiles: [],
                forbiddenFiles: [],
                expectedMaxFiles: 10,
            ),
            violations: [],
            status: ScopeGuardReceipt::STATUS_PASSED,
            statusReason: 'all_files_allowed',
            userPreExistingChanges: [],
        );
    }

    /**
     * VAL-M1-001: Floor discovers impacted existing tests from the run's own diff.
     *
     * On a hermes run whose applied diff touches a production file `app/.../<Name>.php`,
     * the gate is forced to run the convention-derived existing tests discovered via
     * ProgrammingTestImpactAnalyzer::analyze() over changed-file paths from
     * $scopeReceipt->observed->fileDiffs.
     */
    public function test_floor_discovers_impacted_existing_tests_from_observed_diff(): void
    {
        // The analyzer should find tests for this file if they exist
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        // Ensure the test file exists so it gets picked up
        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist for this test"
        );

        $runner = new FakeCommandRunner;
        // Queue results for floor commands that should be run
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 0,
            stdout: 'OK (5 tests)',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [], // Caller provided no commands
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // The floor should have discovered and run the impacted test
        $this->assertNotEmpty($result->tests, 'Floor should have discovered impacted tests');

        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, $expectedTestFile))) > 0,
            "Floor should have run the convention test {$expectedTestFile}"
        );
    }

    /**
     * VAL-M1-002: KEYSTONE — a diff breaking a caller-unlisted impacted test makes the gate fail.
     *
     * Craft a run whose diff edits a production file so it breaks an existing test
     * the caller did NOT list in validationCommands. The aggregateStatus must be failed.
     */
    public function test_keystone_caller_unlisted_broken_impacted_test_fails_gate(): void
    {
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist for this test"
        );

        $runner = new FakeCommandRunner;
        // The impacted test fails
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 1, // FAILS
            stdout: 'FAILURES! Tests: 5, Assertions: 10, Failures: 1.',
            stderr: '',
            durationMs: 200,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [], // Caller listed NOTHING
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // KEYSTONE: The gate MUST fail because the floor discovered and ran the broken test
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->aggregateStatus,
            'KEYSTONE: A broken caller-unlisted impacted test must fail the gate'
        );
    }

    /**
     * VAL-M1-003: `php -l` is run per touched `.php` file and a syntax error fails the gate.
     */
    public function test_php_lint_runs_per_touched_php_file_and_syntax_error_fails_gate(): void
    {
        $touchedPhpFile = 'app/Services/BrokenSyntax.php';

        $runner = new FakeCommandRunner;
        // php -l fails with syntax error
        $runner->queue(new VerificationCommandResult(
            command: 'php -l '.$touchedPhpFile,
            exitCode: 255, // Syntax error
            stdout: '',
            stderr: 'Parse error: syntax error, unexpected token',
            durationMs: 50,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$touchedPhpFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // Gate must fail due to syntax error
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->aggregateStatus,
            'A parse error in a touched .php file must fail the gate'
        );

        // Verify php -l was actually run
        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, 'php -l'))) > 0,
            'Floor should have run php -l on the touched .php file'
        );
    }

    /**
     * VAL-M1-004: Configured lint is part of the floor and runs regardless of caller list.
     */
    public function test_configured_lint_runs_as_part_of_floor_even_with_empty_caller_list(): void
    {
        $touchedFile = 'app/Services/Ai/SomeService.php';

        $runner = new FakeCommandRunner;
        // Queue results for lint and any impacted tests
        $runner->queue(new VerificationCommandResult(
            command: './vendor/bin/pint --test',
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$touchedFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [], // Empty caller list
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        // Use base_path() so the floor can detect that pint exists in vendor/bin/
        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: base_path(),
        );

        // Lint command should be present in executed commands
        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, 'pint'))) > 0,
            'Configured lint should run as part of the floor even with empty caller list'
        );
    }

    /**
     * VAL-M1-005: Anti-gaming — empty validationCommands cannot yield passed/skip when impacted existing tests are present.
     */
    public function test_empty_validation_commands_cannot_skip_when_impacted_tests_present(): void
    {
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        // The impacted test fails
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 1,
            stdout: 'FAILURES!',
            stderr: '',
            durationMs: 200,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [], // Empty list
            'no_test_reason' => null, // No reason either
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // MUST NOT take the legacy noCommandsResult() branch
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->aggregateStatus,
            'Empty validationCommands cannot yield passed when impacted tests are present and broken'
        );
        $this->assertNotSame(
            'generic_no_test',
            $result->profile,
            'Profile must not be generic_no_test when impacted tests ran'
        );
        $this->assertNotEmpty($result->tests, 'Tests must have been executed');
    }

    /**
     * VAL-M1-006: Anti-gaming — a no_test_reason cannot waive the floor when impacted existing tests exist.
     */
    public function test_no_test_reason_cannot_waive_floor_when_impacted_tests_exist(): void
    {
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        // The impacted test fails
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 1,
            stdout: 'FAILURES!',
            stderr: '',
            durationMs: 200,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'no_test_reason' => 'documentation_only_change', // Caller claims no tests needed
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // The floor must still run and fail
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->aggregateStatus,
            'no_test_reason cannot waive the floor when impacted existing tests exist and are broken'
        );
    }

    /**
     * VAL-M1-007: Floor commands are the UNION of caller and analyzer-selected commands, deduped.
     */
    public function test_floor_commands_are_union_of_caller_and_analyzer_deduped(): void
    {
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';
        $callerTestFile = 'tests/Unit/SomeOtherTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        // Queue results for both caller and analyzer commands
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$callerTestFile,
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => ['/opt/homebrew/bin/php artisan test '.$callerTestFile],
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);

        // Caller command must be present
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, $callerTestFile))) > 0,
            'Caller-listed commands must still run'
        );

        // Analyzer command must also be present
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, $expectedTestFile))) > 0,
            'Analyzer-discovered commands must also run'
        );

        // No duplicates
        $this->assertSame(
            count($executedCommands),
            count(array_unique($executedCommands)),
            'Commands must be deduped'
        );
    }

    /**
     * VAL-M1-008: Only existing tests are forced — non-existent convention paths never produce a false failure.
     */
    public function test_only_existing_tests_are_forced_no_false_failure_from_nonexistent(): void
    {
        // Use a production file with NO existing convention test
        $productionFile = 'app/Services/Ai/FileWithNoTest.php';

        $runner = new FakeCommandRunner;
        // Queue results for floor commands (php -l and pint will run for .php files)
        $runner->queue(new VerificationCommandResult(
            command: 'php -l '.$productionFile,
            exitCode: 0,
            stdout: 'No syntax errors',
            stderr: '',
            durationMs: 50,
        ));
        $runner->queue(new VerificationCommandResult(
            command: './vendor/bin/pint --test',
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'no_test_reason' => 'no_tests_for_this_file',
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // Check what commands were run
        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);

        // Should pass because php -l and pint pass, and no convention tests exist
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->aggregateStatus,
            'Gate should pass when no convention tests exist and php -l + pint pass'
        );

        // Verify no test command for the non-existent file was run (only php -l and pint)
        $this->assertEmpty(
            array_filter($executedCommands, fn ($c) => str_contains($c, 'FileWithNoTestTest')),
            'Non-existent test files should not be run'
        );

        // Verify php -l and pint were run (floor always runs these for .php files)
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, 'php -l'))) > 0,
            'Floor should run php -l for .php files'
        );
    }

    /**
     * VAL-M1-009: A changed tests/ file is itself run as an impacted test.
     */
    public function test_changed_tests_file_is_run_directly(): void
    {
        $changedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($changedTestFile)),
            "Test file {$changedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$changedTestFile,
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$changedTestFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, $changedTestFile))) > 0,
            'A changed test file should be run directly'
        );
    }

    /**
     * VAL-M1-010: Floor does not fabricate commands when diff touches no production code and no test exists.
     *
     * When the diff touches only documentation (no .php files), the floor should not
     * fabricate any php -l or pint commands. Legacy noCommandsResult honesty semantics
     * are preserved.
     */
    public function test_floor_does_not_fabricate_commands_for_doc_only_diff(): void
    {
        $docOnlyFile = 'docs/README.md'; // Not a .php file

        $runner = new FakeCommandRunner;
        // No commands should be queued because no .php files touched

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$docOnlyFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'no_test_reason' => 'documentation_only',
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // Legacy honesty semantics should be preserved - no .php files means
        // no floor commands, so legacy noCommandsResult applies
        $this->assertSame(
            VerificationGateResult::STATUS_PASSED,
            $result->aggregateStatus,
            'Doc-only diff with reason should pass (legacy noCommandsResult semantics)'
        );

        // Floor should not have fabricated any commands for non-.php files
        $this->assertEmpty($result->tests, 'No floor commands should be fabricated for doc-only diff');
    }

    /**
     * VAL-M1-011: Honesty flags reflect that impacted tests were actually run (no false skip flag).
     */
    public function test_honesty_flags_exclude_test_skipped_no_reason_when_floor_tests_ran(): void
    {
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        $task = $this->taskContractFixture([
            'validation_commands' => [],
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        $this->assertNotContains(
            'test_skipped_no_reason',
            $result->honestyFlags,
            'Honesty flags must not contain test_skipped_no_reason when floor tests actually ran'
        );
        $this->assertNotEmpty($result->tests, 'Tests must have been executed');
    }

    /**
     * VAL-M1-012: Floor uses the observed diff, not caller-declared expected files.
     */
    public function test_floor_uses_observed_diff_not_caller_declared_files(): void
    {
        // The observed diff contains a file NOT in caller's allowedFiles
        $observedFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        // The impacted test for the observed file fails
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 1,
            stdout: 'FAILURES!',
            stderr: '',
            durationMs: 200,
        ));

        // Observed diff includes the file (this is what matters)
        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$observedFile]);

        $task = $this->taskContractFixture([
            'allowed_files' => ['some/other/file.php'], // Caller declared a DIFFERENT file
            'validation_commands' => [],
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // The floor should have used the OBSERVED diff, not caller-declared
        $this->assertSame(
            VerificationGateResult::STATUS_FAILED,
            $result->aggregateStatus,
            'Floor must use observed diff, not caller-declared files'
        );

        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, $expectedTestFile))) > 0,
            'Floor should discover impacted test from the observed diff path'
        );
    }

    /**
     * VAL-CROSS-001: The elevated rungs are wired on the DEFAULT hermes path, not behind a special flag.
     */
    public function test_floor_is_active_on_default_hermes_run_with_no_flag(): void
    {
        $productionFile = 'app/Services/Ai/Scheduling/ScheduleParser.php';
        $expectedTestFile = 'tests/Unit/ScheduleParserTest.php';

        $this->assertTrue(
            File::exists(base_path($expectedTestFile)),
            "Expected test file {$expectedTestFile} must exist"
        );

        $runner = new FakeCommandRunner;
        $runner->queue(new VerificationCommandResult(
            command: '/opt/homebrew/bin/php artisan test '.$expectedTestFile,
            exitCode: 0,
            stdout: 'OK',
            stderr: '',
            durationMs: 100,
        ));

        $scopeReceipt = $this->scopeReceiptWithFileDiffs([$productionFile]);

        // Standard default-configured task contract - NO special flags
        $task = $this->taskContractFixture([
            'validation_commands' => [], // Empty, relies on floor
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'minimax-m3'],
        ]);

        $result = $this->makeGate($runner)->run(
            taskContract: $task,
            callResult: $this->callResult(),
            scopeReceipt: $scopeReceipt,
            workspace: '/tmp/atlas-workspace',
        );

        // Floor should be active by default
        $this->assertNotEmpty(
            $result->tests,
            'Floor must be active on default hermes run with no flag set'
        );

        $executedCommands = array_map(fn ($t) => $t->command, $result->tests);
        $this->assertTrue(
            count(array_filter($executedCommands, fn ($c) => str_contains($c, $expectedTestFile))) > 0,
            'Floor should run impacted tests on default hermes path'
        );
    }
}
