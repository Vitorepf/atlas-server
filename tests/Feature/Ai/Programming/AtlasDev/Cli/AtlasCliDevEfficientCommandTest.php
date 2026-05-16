<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Cli;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $buf = new \Symfony\Component\Console\Output\BufferedOutput;
        $exit = $kernel->call('atlas:cli:dev', $params, $buf);

        $this->assertSame($expectedExit, $exit, 'CLI exit code mismatch.');

        return $buf->fetch();
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
    ): RunExecutionResult {
        $this->calls[] = [
            'run_id' => $runId,
            'envelope_hash' => $envelope->envelopeHash,
            'task_contract_hash' => $taskContract->taskContractHash,
        ];

        return $this->result;
    }
}
