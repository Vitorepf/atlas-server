<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Cli;

use App\Http\Controllers\AtlasDev\Support\CompactSddUnavailableException;
use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenIssue;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenResult;
use App\Services\Ai\Programming\AtlasDev\Security\ConfirmationTokenService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\Feature\Ai\Programming\AtlasDev\Http\FakeAtlasOpenBrainService;
use Tests\TestCase;

/**
 * CLI parity coverage for `atlas:cli:dev --efficient`.
 *
 * The CLI must reuse the same orchestrator / token service / RunExecutor as
 * the HTTP /plan and /run endpoints. These tests guard:
 *   - plan-only (no --yes) never reaches the provider;
 *   - run (with --yes) consumes a token and invokes the executor;
 *   - JSON output never leaks absolute filesystem paths;
 *   - delegate_to_other_flow renders suggested_flow;
 *   - feature flag disabled prints a clean error.
 */
final class AtlasCliDevEfficientCommandTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('K', 32)));

        Http::preventStrayRequests();
        Http::fake();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-cli-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        config()->set('atlas_dev.receipts_path', $this->tmpStorage);
        $this->app->forgetInstance(ReceiptStorage::class);
        $this->app->forgetInstance(AtlasDevFastPathOrchestrator::class);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-cli-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents(
            $this->tmpWorkspace.'/.git/refs/heads/main',
            '0123456789abcdef0123456789abcdef01234567',
        );
        file_put_contents(
            $this->tmpWorkspace.'/app/Services/Foo/FooService.php',
            "<?php\nclass FooService {}\n",
        );
        file_put_contents(
            $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php',
            "<?php\nclass FooServiceTest {}\n",
        );

        $this->app->instance(AtlasOpenBrainService::class, new FakeAtlasOpenBrainService);
        $this->bootstrapTokenTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_dev_run_index');
        Schema::dropIfExists('atlas_dev_confirmation_tokens');
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_efficient_plan_only_without_yes_does_not_call_run_executor(): void
    {
        $fake = $this->bindFakeRunExecutor();

        $exit = $this->artisan('atlas:cli:dev', [
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
        $this->assertCount(0, $fake->calls, 'Plan-only must NEVER call the RunExecutor.');
    }

    public function test_efficient_plan_only_json_output_has_no_absolute_paths(): void
    {
        $this->bindFakeRunExecutor();
        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--json' => true,
        ]);

        $this->assertNoAbsolutePaths($output);
        $this->assertStringContainsString('"run_id"', $output);
        $this->assertStringContainsString('"confirmation_required": true', $output);
    }

    public function test_efficient_run_with_yes_consumes_token_and_invokes_executor(): void
    {
        $fake = $this->bindFakeRunExecutor();

        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
            '--json' => true,
        ]);

        $this->assertCount(1, $fake->calls, 'RunExecutor must be called exactly once when --yes is passed.');
        $this->assertStringContainsString('"completion_state": "passed"', $output);
        $this->assertStringContainsString('"confirmation_token_id"', $output);
        $this->assertNoAbsolutePaths($output);
    }

    public function test_efficient_delegate_route_renders_suggested_flow_in_json(): void
    {
        $this->bindFakeRunExecutor();

        $output = $this->captureJsonRun([
            'task' => ['explique como o FooService resolve workspace'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--json' => true,
        ]);

        $this->assertStringContainsString('"kind": "read_only_answer"', $output);
        $this->assertStringContainsString('"suggested_flow": "atlas_explain"', $output);
    }

    public function test_efficient_router_hints_are_preserved_in_persisted_envelope(): void
    {
        $this->bindFakeRunExecutor();

        $output = $this->captureJsonRun([
            'task' => ['explique como o FooService resolve workspace'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--flow-origin' => 'atlas_ai_router',
            '--command-intent' => 'question',
            '--json' => true,
        ]);

        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $runId = (string) ($payload['run_id'] ?? '');
        $this->assertNotSame('', $runId);

        $envelope = $this->app->make(ReceiptStorage::class)->read($runId, 'operation_envelope.json');
        $this->assertIsArray($envelope);
        $this->assertSame('atlas_ai_router', $envelope['flow_origin'] ?? null);
        $this->assertSame('question', $envelope['command_intent'] ?? null);
    }

    public function test_efficient_review_route_returns_deterministic_diff_finding(): void
    {
        $this->bindFakeRunExecutor();
        $workspace = $this->makeReviewWorkspace();

        try {
            $output = $this->captureJsonRun([
                'task' => ['Review the workspace diff in src/Discount.php and return actionable findings.'],
                '--workspace' => $workspace,
                '--efficient' => true,
                '--flow-origin' => 'atlas_ai_router',
                '--command-intent' => 'review',
                '--json' => true,
            ]);
        } finally {
            $this->rmrf($workspace);
        }

        $this->assertStringContainsString('"kind": "read_only_answer"', $output);
        $this->assertStringContainsString('"kind": "diff_review_findings"', $output);
        $this->assertStringContainsString('Discount calculation was inverted', $output);
        $this->assertStringContainsString('"provider_calls": 0', $output);
        $this->assertNoAbsolutePaths($output);
    }

    public function test_efficient_disabled_by_plan_flag_returns_503_like_error(): void
    {
        config()->set('atlas_dev.efficient.plan_enabled', false);

        $output = $this->captureJsonRun([
            'task' => ['fix foo'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--json' => true,
        ], expectedExit: 2);

        $this->assertStringContainsString('ATLAS_DEV_PLAN_DISABLED', $output);
    }

    public function test_efficient_disabled_by_run_flag_blocks_yes_after_plan(): void
    {
        $this->bindFakeRunExecutor();
        config()->set('atlas_dev.efficient.run_enabled', false);

        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
            '--json' => true,
        ], expectedExit: 2);

        $this->assertStringContainsString('ATLAS_DEV_RUN_DISABLED', $output);
    }

    /**
     * VAL-M1-002: with run_enabled ON (the new default), `atlas dev '<task>'
     * --yes` proceeds past the run_enabled guard into the full pipeline and
     * returns a run result — it does NOT short-circuit with the
     * ATLAS_DEV_RUN_DISABLED flag-disabled error. The fake executor is reached
     * (the gate is open) and a completion_state is surfaced.
     */
    public function test_efficient_yes_proceeds_past_run_enabled_gate_without_run_disabled(): void
    {
        $fake = $this->bindFakeRunExecutor();
        // run_enabled is on (setUp mirrors the new canonical default).
        config()->set('atlas_dev.efficient.run_enabled', true);

        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
            '--json' => true,
        ]);

        $this->assertStringNotContainsString('ATLAS_DEV_RUN_DISABLED', $output, '--yes must not surface the run-disabled error when run_enabled is on.');
        $this->assertStringContainsString('"completion_state": "passed"', $output, '--yes must proceed into the pipeline and surface a completion_state.');
        $this->assertCount(1, $fake->calls, 'The run_enabled gate must be open so the RunExecutor is reached.');
    }

    /**
     * VAL-M1-003: turning the spine ON gates execution, not planning. Without
     * --yes the CLI computes the plan and returns confirmation_required (exit
     * 0) with NO run object and NO ATLAS_DEV_RUN_DISABLED error — the run
     * surface being on by default must never auto-execute an unconfirmed run.
     */
    public function test_efficient_plan_only_without_yes_emits_confirmation_required_and_no_run(): void
    {
        $fake = $this->bindFakeRunExecutor();
        config()->set('atlas_dev.efficient.run_enabled', true);

        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--json' => true,
        ]);

        $this->assertStringContainsString('"confirmation_required": true', $output);
        $this->assertStringNotContainsString('ATLAS_DEV_RUN_DISABLED', $output);
        $this->assertStringNotContainsString('"run": {', $output, 'An unconfirmed plan must not auto-execute and emit a run object.');
        $this->assertStringContainsString('"run_id"', $output, 'The plan body must still be present.');
        $this->assertCount(0, $fake->calls, 'Plan-only must never reach the RunExecutor, regardless of run_enabled.');
    }

    public function test_efficient_without_task_returns_validation_error(): void
    {
        $output = $this->captureJsonRun([
            'task' => [],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--json' => true,
        ], expectedExit: 64);

        $this->assertStringContainsString('ATLAS_DEV_PLAN_FAILED', $output);
        $this->assertStringContainsString('--efficient requires a task description', $output);
    }

    public function test_text_output_includes_run_id_routing_and_suggested_flow(): void
    {
        $this->bindFakeRunExecutor();

        $this->artisan('atlas:cli:dev', [
            'task' => ['explique como o FooService resolve workspace'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
        ])
            ->expectsOutputToContain('Atlas Dev Efficient — plan summary')
            ->expectsOutputToContain('run_id           :')
            ->expectsOutputToContain('routing.kind     : read_only_answer')
            ->expectsOutputToContain('suggested_flow   : atlas_explain')
            ->assertExitCode(0);
    }

    /**
     * Security regression: the confirmation_token plaintext is issued
     * server-side inside the handler and never leaves the process. The CLI
     * must NEVER render it to stdout (json or text). Only the opaque
     * `confirmation_token_id` (DB row id) is operator-visible.
     */
    public function test_confirmation_token_plaintext_never_appears_in_cli_output(): void
    {
        $this->bindFakeRunExecutor();
        $spy = $this->bindTokenSpyWithRealService();

        // JSON output
        $jsonOutput = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
            '--json' => true,
        ]);

        $this->assertNotSame([], $spy->issuedPlaintexts, 'Sanity: handler must have issued a token.');
        foreach ($spy->issuedPlaintexts as $plaintext) {
            $this->assertNotSame('', $plaintext);
            $this->assertStringNotContainsString(
                $plaintext,
                $jsonOutput,
                'JSON output leaked the confirmation_token plaintext.',
            );
        }
        $this->assertStringContainsString('"confirmation_token_id"', $jsonOutput, 'Operator must still see the opaque token_id reference.');

        // Text output uses the same renderer; assert too.
        $spy->issuedPlaintexts = [];
        $textOutput = $this->captureTextRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
        ]);

        foreach ($spy->issuedPlaintexts as $plaintext) {
            $this->assertStringNotContainsString(
                $plaintext,
                $textOutput,
                'Text output leaked the confirmation_token plaintext.',
            );
        }
    }

    /**
     * CLI parity for the F-03 server-side hash pin: when the executor
     * detects compact_sdd.json tampering between Plan and Run, the CLI
     * must surface the typed COMPACT_SDD_TAMPERED error code with exit
     * code 70 (OUTCOME_RUN_FAILED) — and must not leak the run_id-bound
     * token plaintext nor any absolute filesystem path.
     */
    public function test_compact_sdd_tampered_from_executor_surfaces_typed_error_without_leaks(): void
    {
        $tamperedRunId = 'dev-cli-tampered-'.bin2hex(random_bytes(3));
        $exception = CompactSddUnavailableException::tampered(
            $tamperedRunId,
            'compact_sdd_hash does not match the server-side pin issued at plan time',
        );

        $this->app->instance(RunExecutor::class, new TamperingCliRunExecutor($exception));
        $spy = $this->bindTokenSpyWithRealService();

        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
            '--json' => true,
        ], expectedExit: 70);

        $this->assertStringContainsString('"ATLAS_DEV_RUN_FAILED"', $output);
        $this->assertStringContainsString('hash-pin validation', $output);
        foreach ($spy->issuedPlaintexts as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $output, 'Tamper error leaked the token plaintext.');
        }
        $this->assertNoAbsolutePaths($output);
    }

    /**
     * The CLI's internal token issue → consume happens in the same process
     * so a "client-supplied bad token" case is structurally unreachable.
     * But the token service can still emit ok=false for edge cases (e.g.
     * APP_KEY rotated between issue and consume in tests). The CLI must
     * surface a clean CONFIRMATION_TOKEN_REJECTED with a reason and exit
     * code 66 (OUTCOME_TOKEN_FAILED), without leaking the plaintext.
     */
    public function test_token_validation_failure_surfaces_clean_error(): void
    {
        $this->bindFakeRunExecutor();

        $spy = new SpyConfirmationTokenService;
        // Force every consume to fail with INVALID — simulates the
        // post-issue race / config drift path.
        $spy->failConsumeWith = ConfirmationTokenResult::REASON_INVALID;
        $this->app->instance(ConfirmationTokenService::class, $spy);

        $output = $this->captureJsonRun([
            'task' => ['corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php'],
            '--workspace' => $this->tmpWorkspace,
            '--efficient' => true,
            '--yes' => true,
            '--json' => true,
        ], expectedExit: 66);

        $this->assertStringContainsString('"CONFIRMATION_TOKEN_REJECTED"', $output);
        $this->assertStringContainsString('"reason":', $output);
        $this->assertStringContainsString('"invalid_token"', $output);
        foreach ($spy->issuedPlaintexts as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $output, 'Token-failure path leaked plaintext.');
        }
        $this->assertNoAbsolutePaths($output);
    }

    private function bindFakeRunExecutor(): FakeCliRunExecutor
    {
        $fake = new FakeCliRunExecutor(new RunExecutionResult(
            completionState: 'passed',
            scopeGuardStatus: 'passed',
            verificationStatus: 'passed',
            persistedReceiptPaths: [
                'verification_receipt.json' => $this->tmpStorage.'/dev-fake/verification_receipt.json',
            ],
            providerCallSummary: [
                'provider' => 'claude_cli',
                'model_family' => 'sonnet',
                'provider_calls' => 1,
                'exit_code' => 0,
                'duration_ms' => 1200,
                'tokens_in' => 100,
                'tokens_out' => 50,
                'estimated_cost_usd' => 0.01,
                'error_codes' => [],
            ],
            verificationReceiptHash: str_repeat('a', 64),
            scopeGuardReceiptHash: str_repeat('b', 64),
            diffHash: str_repeat('c', 64),
        ));

        $this->app->instance(RunExecutor::class, $fake);

        return $fake;
    }

    /**
     * Run the command once via the kernel, capturing stdout into a buffer.
     *
     * @param  array<string, mixed>  $params
     */
    private function captureJsonRun(array $params, int $expectedExit = 0): string
    {
        $kernel = $this->app->make(Kernel::class);
        $buf = new BufferedOutput;
        $exit = $kernel->call('atlas:cli:dev', $params, $buf);

        $this->assertSame($expectedExit, $exit, 'CLI exit code mismatch.');

        return $buf->fetch();
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function captureTextRun(array $params, int $expectedExit = 0): string
    {
        $kernel = $this->app->make(Kernel::class);
        $buf = new BufferedOutput;
        $exit = $kernel->call('atlas:cli:dev', $params, $buf);

        $this->assertSame($expectedExit, $exit, 'CLI exit code mismatch.');

        return $buf->fetch();
    }

    /**
     * Wraps the real ConfirmationTokenService in a spy that records every
     * issued plaintext. The spy still delegates to the real DB-backed
     * service so the consume path runs against actual tables — only the
     * issue() return value is intercepted for assertion.
     */
    private function bindTokenSpyWithRealService(): SpyConfirmationTokenService
    {
        $spy = new SpyConfirmationTokenService;
        $this->app->instance(ConfirmationTokenService::class, $spy);

        return $spy;
    }

    private function assertNoAbsolutePaths(string $output): void
    {
        @file_put_contents('/tmp/atlas-cli-test-output.json', $output);
        $this->assertStringNotContainsString('/Users/', $output, 'Output leaked /Users/ absolute path.');
        $this->assertStringNotContainsString('/private/var/', $output, 'Output leaked /private/var/ absolute path.');
        $this->assertStringNotContainsString($this->tmpWorkspace, $output, 'Output leaked workspace absolute path.');
        $this->assertStringNotContainsString($this->tmpStorage, $output, 'Output leaked storage absolute path.');
    }

    private function bootstrapTokenTables(): void
    {
        if (! Schema::hasTable('atlas_dev_confirmation_tokens')) {
            Schema::create('atlas_dev_confirmation_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('run_id', 128)->index();
                $table->string('token_hash', 128)->unique();
                $table->string('surface_id', 80)->index();
                $table->string('task_contract_hash', 128)->index();
                $table->string('compact_sdd_hash', 128)->nullable()->index();
                $table->timestamp('issued_at');
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('atlas_dev_run_index')) {
            Schema::create('atlas_dev_run_index', function (Blueprint $table): void {
                $table->string('run_id', 128)->primary();
                $table->string('surface_id', 80)->index();
                $table->string('workspace_hash', 128)->index();
                $table->string('thread_id', 128)->nullable()->index();
                $table->string('routing_decision', 32);
                $table->string('task_kind', 40);
                $table->string('risk_level', 8);
                $table->string('completion_state', 32)->nullable();
                $table->string('last_receipt_hash', 128)->nullable();
                $table->timestamps();
            });
        }
    }

    private function makeReviewWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-cli-review-ws-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/src', 0o755, true);
        mkdir($workspace.'/tests', 0o755, true);
        file_put_contents($workspace.'/src/Discount.php', <<<'PHP'
<?php
namespace Bench;

final class Discount
{
    public function apply(int $cents, int $percent): int
    {
        return (int) round($cents * (100 - $percent) / 100);
    }
}
PHP);
        file_put_contents($workspace.'/tests/DiscountTest.php', "<?php\n// focused test fixture\n");

        $this->mustRun(['git', '-C', $workspace, 'init', '-q']);
        $this->mustRun(['git', '-C', $workspace, 'config', 'user.email', 'atlas@example.test']);
        $this->mustRun(['git', '-C', $workspace, 'config', 'user.name', 'Atlas Test']);
        $this->mustRun(['git', '-C', $workspace, 'add', '.']);
        $this->mustRun(['git', '-C', $workspace, 'commit', '-m', 'baseline', '-q']);

        $path = $workspace.'/src/Discount.php';
        file_put_contents($path, str_replace(
            '100 - $percent',
            '100 + $percent',
            (string) file_get_contents($path),
        ));

        return $workspace;
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command): void
    {
        $process = new Process($command);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}

final class FakeCliRunExecutor implements RunExecutor
{
    /** @var list<array{run_id:string,envelope_hash:string,task_contract_hash:string}> */
    public array $calls = [];

    public function __construct(private readonly RunExecutionResult $result) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        $this->calls[] = [
            'run_id' => $runId,
            'envelope_hash' => $envelope->envelopeHash,
            'task_contract_hash' => $taskContract->taskContractHash,
            'expected_compact_sdd_hash' => $expectedCompactSddHash,
        ];

        return $this->result;
    }
}

/**
 * Test-only RunExecutor that throws a typed CompactSddUnavailableException
 * so the CLI's error-rendering path can be exercised without spinning up the
 * real PipelineRunExecutor + provider stack.
 */
final class TamperingCliRunExecutor implements RunExecutor
{
    public int $calls = 0;

    public function __construct(private readonly CompactSddUnavailableException $exception) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        $this->calls++;
        throw $this->exception;
    }
}

/**
 * Records the plaintext returned by issue() so assertions can prove the CLI
 * never echoes it back. When `failConsumeWith` is set, validateAndConsume()
 * forces a failure with that reason regardless of the underlying token row;
 * used to drive the OUTCOME_TOKEN_FAILED branch deterministically.
 *
 * Skips the parent constructor — ConfirmationTokenService has no required
 * dependencies and the parent ctor is intentionally empty.
 */
final class SpyConfirmationTokenService extends ConfirmationTokenService
{
    /** @var list<string> */
    public array $issuedPlaintexts = [];

    public ?string $failConsumeWith = null;

    public function __construct()
    {
        // skip parent: avoid coupling to internal DB bootstrap
    }

    public function issue(
        string $runId,
        string $taskContractHash,
        string $surfaceId,
        ?string $compactSddHash = null,
    ): ConfirmationTokenIssue {
        // Generate a plaintext that DOES NOT touch the DB. The handler's
        // immediate validateAndConsume below is stubbed to return ok=true
        // with the pinned hash, so we don't need a persisted row.
        $plaintext = 'spy-plaintext-'.bin2hex(random_bytes(16));
        $this->issuedPlaintexts[] = $plaintext;
        $now = Carbon::now();

        return new ConfirmationTokenIssue(
            tokenId: 'spy-token-'.bin2hex(random_bytes(4)),
            plaintext: $plaintext,
            issuedAt: $now,
            expiresAt: $now->copy()->addSeconds(300),
        );
    }

    public function validateAndConsume(
        string $runId,
        string $taskContractHash,
        string $plaintext,
    ): ConfirmationTokenResult {
        if ($this->failConsumeWith !== null) {
            return ConfirmationTokenResult::fail($this->failConsumeWith);
        }

        // Healthy path returns ok with the (unknown-to-spy) compact_sdd_hash
        // pinned at issue time. Tests asserting the executor sees this can
        // inspect FakeCliRunExecutor->calls.
        return ConfirmationTokenResult::ok(
            tokenId: 'spy-token-id',
            expectedCompactSddHash: null,
        );
    }
}
