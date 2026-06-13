<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use App\Http\Controllers\AtlasDev\Support\RunExecutionResult;
use App\Http\Controllers\AtlasDev\Support\RunExecutor;
use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevSeniorLoopRunCommandTest extends TestCase
{
    private string $receiptsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptsPath = sys_get_temp_dir().'/atlas-dev-senior-loop-run-receipts-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->receiptsPath);

        config()->set('atlas_dev.receipts_path', $this->receiptsPath);
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', true);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('L', 32)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->receiptsPath);

        parent::tearDown();
    }

    public function test_senior_loop_run_strict_executes_and_persists_operational_receipts(): void
    {
        $exit = Artisan::call('atlas:dev:senior-loop:run', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.senior_engineer_loop_execution.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['execution_hash']);
        $this->assertSame('passed', $payload['run_summary']['completion_state']);
        $this->assertSame('passed', $payload['run_summary']['scope_guard_status']);
        $this->assertSame('passed', $payload['run_summary']['verification_status']);
        $this->assertSame('single_attempt_verified_execution', $payload['debug_loop']['mode']);
        $this->assertSame(true, $payload['debug_loop']['recovered']);
        $this->assertSame(false, $payload['learning']['auto_apply']);
        $this->assertSame(false, $payload['learning']['error_ledger_recorded']);

        $runId = (string) $payload['run_id'];
        foreach ([
            'senior_engineer_loop_audit.json',
            'senior_engineer_loop_execution.json',
            'provider_call_result.json',
            'diff_parse_result.json',
            'patch_apply_result.json',
            'scope_guard_receipt.json',
            'verification_receipt.json',
        ] as $artifact) {
            $this->assertFileExists($this->receiptsPath.'/'.$runId.'/'.$artifact);
        }
    }

    public function test_senior_loop_run_failure_persists_failure_capsule_and_learning_handoff(): void
    {
        $this->app->instance(RunExecutor::class, new FailingSeniorLoopRunExecutor);

        $exit = Artisan::call('atlas:dev:senior-loop:run', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('failed', $payload['status']);
        $this->assertSame(['senior_loop_execution_not_passed'], $payload['blockers']);
        $this->assertSame('bounded_repair_triage', $payload['debug_loop']['mode']);
        $this->assertSame(1, $payload['debug_loop']['attempts_executed']);
        $this->assertGreaterThanOrEqual(1, $payload['debug_loop']['attempts_allowed']);
        $this->assertSame(false, $payload['debug_loop']['recovered']);
        $this->assertSame('failure_recorded_for_curator', $payload['learning']['reason']);
        $this->assertSame(true, $payload['learning']['error_ledger_recorded']);
        $this->assertSame(false, $payload['learning']['auto_apply']);

        $capsules = $payload['debug_loop']['failure_capsules'];
        $this->assertIsArray($capsules);
        $this->assertCount(1, $capsules);
        $this->assertSame(0, $capsules[0]['attempt_index']);
        $this->assertSame('retry', $capsules[0]['decision']);
        $this->assertStringStartsWith('sha256:', $capsules[0]['failure_signature']);
        $this->assertSame('receipts/'.$payload['run_id'].'/failure_capsule.0.json', $capsules[0]['ref']);

        $this->assertFileExists($this->receiptsPath.'/'.$payload['run_id'].'/failure_capsule.0.json');
        $this->assertFileExists($this->receiptsPath.'/'.$payload['run_id'].'/error_ledger.v1.json');
        $this->assertFileExists($this->receiptsPath.'/'.$payload['run_id'].'/senior_engineer_loop_execution.json');
    }

    public function test_senior_loop_run_accepts_real_workspace_constraints_instead_of_fixture_scope(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-senior-loop-real-workspace-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/src');
        File::ensureDirectoryExists($workspace.'/.git/refs/heads');
        File::put($workspace.'/.git/HEAD', 'ref: refs/heads/main');
        File::put($workspace.'/.git/refs/heads/main', '0123456789abcdef0123456789abcdef01234567');
        File::put($workspace.'/composer.json', '{"scripts":{"test":"php -l src/CustomSubject.php"}}'.PHP_EOL);
        File::put($workspace.'/src/CustomSubject.php', <<<'PHP'
<?php
namespace Smoke;
final class CustomSubject
{
    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP);

        try {
            $exit = Artisan::call('atlas:dev:senior-loop:run', [
                '--workspace' => $workspace,
                '--intent' => 'Fix src/CustomSubject.php so the greeting returns hello atlas. Change only src/CustomSubject.php.',
                '--allowed-file' => ['src/CustomSubject.php'],
                '--validation-command' => ['php -l src/CustomSubject.php'],
                '--surface-id' => 'atlas_cli_dev',
                '--provider-choice' => 'cursor_cli',
                '--composer-model' => 'composer-2.5-fast',
                '--json' => true,
                '--strict' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exit, Artisan::output());
            $this->assertIsArray($payload);
            $this->assertSame('passed', $payload['status']);
            $this->assertStringContainsString('hello atlas', (string) File::get($workspace.'/src/CustomSubject.php'));
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_force_real_provider_disables_deterministic_shortcut_for_this_run(): void
    {
        config()->set('atlas_dev.efficient.deterministic_fast_path_enabled', true);
        $this->app->instance(RunExecutor::class, new AssertingRealProviderSeniorLoopRunExecutor);

        $exit = Artisan::call('atlas:dev:senior-loop:run', [
            '--provider-choice' => 'hermes_cli',
            '--composer-model' => 'gpt-5.5',
            '--force-real-provider' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('hermes_cli', data_get($payload, 'run_summary.provider_call.provider'));
        $this->assertSame('gpt-5.5', data_get($payload, 'run_summary.provider_call.model_family'));
        $this->assertSame(1, data_get($payload, 'run_summary.provider_call.provider_calls'));
    }
}

final class FailingSeniorLoopRunExecutor implements RunExecutor
{
    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        return new RunExecutionResult(
            completionState: 'failed',
            scopeGuardStatus: 'passed',
            verificationStatus: 'failed',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => 'atlas_test',
                'model_family' => 'fake',
                'provider_calls' => 1,
                'exit_code' => 1,
                'duration_ms' => 7,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'estimated_cost_usd' => 0.0,
                'error_codes' => ['verification_failed'],
                'raw_response_hash' => 'sha256:test',
                'stdout_bytes' => 0,
                'stderr_bytes' => 32,
            ],
            diffParseSummary: [
                'changed_files' => ['src/SmokeSubject.php'],
                'diff_hash' => 'sha256:failed-diff',
            ],
            verificationReceiptHash: 'sha256:verification-failed',
            scopeGuardReceiptHash: 'sha256:scope-passed',
            diffHash: 'sha256:failed-diff',
        );
    }
}

final class AssertingRealProviderSeniorLoopRunExecutor implements RunExecutor
{
    public function execute(
        OperationEnvelope $envelope,
        LightTaskContract $taskContract,
        ProviderPromptProjection $promptProjection,
        string $runId,
        ?string $expectedCompactSddHash = null,
    ): RunExecutionResult {
        if (config('atlas_dev.efficient.deterministic_fast_path_enabled') !== false) {
            throw new \RuntimeException('force-real-provider did not disable deterministic fast path');
        }

        return new RunExecutionResult(
            completionState: 'passed',
            scopeGuardStatus: 'passed',
            verificationStatus: 'passed',
            persistedReceiptPaths: [],
            providerCallSummary: [
                'provider' => $taskContract->providerLock->provider,
                'model_family' => $taskContract->providerLock->modelFamily,
                'provider_calls' => 1,
                'exit_code' => 0,
                'duration_ms' => 42,
                'tokens_in' => 1,
                'tokens_out' => 1,
                'estimated_cost_usd' => 0.0,
                'error_codes' => [],
                'raw_response_hash' => 'sha256:real-provider-test',
                'stdout_bytes' => 120,
                'stderr_bytes' => 0,
            ],
            diffParseSummary: [
                'changed_files' => ['src/SmokeSubject.php'],
                'diff_hash' => 'sha256:real-provider-diff',
            ],
            verificationReceiptHash: 'sha256:verification-passed',
            scopeGuardReceiptHash: 'sha256:scope-passed',
            diffHash: 'sha256:real-provider-diff',
        );
    }
}
