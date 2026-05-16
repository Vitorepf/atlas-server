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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Ai\Programming\AtlasDev\Http\FakeAtlasOpenBrainService;
use Tests\TestCase;

final class AtlasDevSmokeCommandTest extends TestCase
{
    private string $tmpStorage;

    private string $tmpWorkspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-smoke-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        config()->set('atlas_dev.receipts_path', $this->tmpStorage);
        $this->app->forgetInstance(ReceiptStorage::class);
        $this->app->forgetInstance(AtlasDevFastPathOrchestrator::class);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-smoke-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents($this->tmpWorkspace.'/.git/refs/heads/main', '0123456789abcdef0123456789abcdef01234567');
        file_put_contents($this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php', "<?php\nclass FooServiceTest {}\n");

        $this->app->instance(AtlasOpenBrainService::class, new FakeAtlasOpenBrainService);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpStorage);
        $this->rmrf($this->tmpWorkspace);
        parent::tearDown();
    }

    public function test_plan_only_smoke_never_calls_run_executor(): void
    {
        $fake = $this->bindFakeRunExecutor();

        $this->artisan('atlas:dev:debug:smoke', [
            '--workspace' => $this->tmpWorkspace,
            '--intent' => 'corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertCount(0, $fake->calls);
    }

    public function test_execute_requires_explicit_yes(): void
    {
        $this->bindFakeRunExecutor();

        $this->artisan('atlas:dev:debug:smoke', [
            '--workspace' => $this->tmpWorkspace,
            '--intent' => 'corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            '--execute' => true,
        ])
            ->expectsOutputToContain('--execute requires --yes')
            ->assertExitCode(1);
    }

    public function test_execute_smoke_invokes_run_executor_once_and_redacts_receipt_refs(): void
    {
        $fake = $this->bindFakeRunExecutor();

        $output = $this->captureRun([
            '--workspace' => $this->tmpWorkspace,
            '--intent' => 'corrigir o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            '--execute' => true,
            '--yes' => true,
            '--json' => true,
        ]);

        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('"completion_state": "passed"', $output);
        $this->assertStringContainsString('"persisted_receipt_refs"', $output);
        $this->assertStringNotContainsString($this->tmpStorage, $output);
    }

    private function bindFakeRunExecutor(): SmokeFakeRunExecutor
    {
        $fake = new SmokeFakeRunExecutor(new RunExecutionResult(
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
     * @param  array<string, mixed>  $params
     */
    private function captureRun(array $params, int $expectedExit = 0): string
    {
        $kernel = $this->app->make(Kernel::class);
        $buf = new BufferedOutput;
        $exit = $kernel->call('atlas:dev:debug:smoke', $params, $buf);

        $this->assertSame($expectedExit, $exit, 'Smoke command exit code mismatch.');

        return $buf->fetch();
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

final class SmokeFakeRunExecutor implements RunExecutor
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly RunExecutionResult $result) {}

    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
    ): RunExecutionResult {
        $this->calls[] = $runId;

        return $this->result;
    }
}
