<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeCursorCliInvocationDriver;
use App\Services\Ai\Programming\AtlasForgeProviderCommandAllowlistService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationFailureClassifier;
use App\Services\Ai\Programming\AtlasForgeProviderProcessRunner;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasForgeCursorCliDriverTest extends TestCase
{
    private ?string $oldPath = null;

    private ?string $fakeBinDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldPath = getenv('PATH') ?: '';
        putenv('CURSOR_API_KEY');
        config()->set('atlas.ai.providers.cursor_cli.enabled', false);
        config()->set('atlas.ai.providers.cursor_cli.binary', 'cursor-agent');
        config()->set('atlas.ai.providers.cursor_cli.binary_candidates', ['cursor-agent']);
        config()->set('atlas.ai.providers.cursor_cli.auth_mode', 'local_login');
        config()->set('atlas.ai.providers.cursor_cli.output_format', 'stream-json');
        config()->set('atlas.ai.providers.cursor_cli.force', false);
        config()->set('atlas.ai.providers.cursor_cli.billing_mode', 'cursor_account_cli_pool');
        config()->set('atlas.ai.providers.cursor_cli.quota_bucket', 'cursor_account_composer_pool');
    }

    protected function tearDown(): void
    {
        putenv('CURSOR_API_KEY');
        if ($this->oldPath !== null) {
            putenv('PATH='.$this->oldPath);
        }
        if ($this->fakeBinDir !== null) {
            @unlink($this->fakeBinDir.'/cursor-agent');
            @rmdir($this->fakeBinDir);
        }
        @unlink(base_path('storage/framework/testing/cursor-cli-forbidden.txt'));
        parent::tearDown();
    }

    public function test_router_lists_cursor_cli_as_canonical_fail_closed_driver(): void
    {
        $router = app(AtlasForgeProviderInvocationDriverRouter::class);
        $status = $router->driverStatus();
        $providers = array_map(fn (array $driver): string => (string) $driver['provider'], $status['drivers']);

        $this->assertContains('cursor_cli', $providers);
        $this->assertTrue($router->supports('cursor_cli'));
        $this->assertTrue($router->hasRuntimeDriver('cursor_cli'));
        $this->assertFalse($router->isConfigured('cursor_cli'));
    }

    public function test_status_blocks_when_disabled_without_provider_call(): void
    {
        $driver = app(AtlasForgeCursorCliInvocationDriver::class);
        $status = $driver->configured();

        $this->assertFalse($status['configured']);
        $this->assertContains('cursor_cli_disabled', $status['blockers']);
        $this->assertTrue($status['external_provider_call_possible']);
        $this->assertSame('cursor_account_cli_pool', $status['billing_mode']);
    }

    public function test_local_login_mode_can_configure_without_api_key_when_binary_exists(): void
    {
        $this->installFakeCursorAgent();
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);

        $driver = app(AtlasForgeCursorCliInvocationDriver::class);
        $status = $driver->configured();

        $this->assertTrue($status['configured']);
        $this->assertSame('local_login_unverified', $status['auth_state']);
        $this->assertSame([], $status['blockers']);
    }

    public function test_plan_never_calls_provider_and_requires_receipt_model_and_scope(): void
    {
        $this->installFakeCursorAgent();
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);

        $driver = app(AtlasForgeCursorCliInvocationDriver::class);
        $plan = $driver->plan([
            'model' => '',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'scope_contract' => [
                    'allowed_files' => [],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
        ]);

        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertContains('decision_receipt_required', $plan['blockers']);
        $this->assertContains('cursor_cli_model_required', $plan['blockers']);
        $this->assertContains('cursor_cli_allowed_files_required', $plan['blockers']);
    }

    public function test_invoke_uses_cursor_agent_print_mode_and_scope_verification(): void
    {
        $this->installFakeCursorAgent();
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);

        $runner = new AtlasForgeProviderProcessRunner;
        $captured = [];
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout) use (&$captured): Process {
            $captured = compact('argv', 'cwd', 'env', 'timeout');

            return new Process([PHP_BINARY, '-r', 'echo "cursor cli ok";'], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeCursorCliInvocationDriver(
            app(AtlasForgeProviderCommandAllowlistService::class),
            $runner,
            app(AtlasForgeProviderInvocationFailureClassifier::class),
        );

        $result = $driver->invoke($this->validRequest());

        $this->assertTrue($result['provider_called']);
        $this->assertTrue($result['external_provider_call']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['changed_files']);
        $this->assertContains('--print', $captured['argv']);
        $this->assertContains('--output-format', $captured['argv']);
        $this->assertContains('stream-json', $captured['argv']);
        $this->assertContains('--model', $captured['argv']);
        $this->assertContains('composer-latest', $captured['argv']);
        $this->assertNotContains('--force', $captured['argv']);
        $this->assertNotContains('--resume', $captured['argv']);
        $this->assertSame(false, $captured['env']['CURSOR_API_KEY'] ?? null);
    }

    public function test_plan_blocks_cursor_force_and_non_stream_json_output(): void
    {
        $this->installFakeCursorAgent();
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);
        config()->set('atlas.ai.providers.cursor_cli.force', true);
        config()->set('atlas.ai.providers.cursor_cli.output_format', 'json');

        $driver = app(AtlasForgeCursorCliInvocationDriver::class);
        $plan = $driver->plan($this->validRequest());

        $this->assertFalse($plan['provider_called']);
        $this->assertFalse($plan['external_provider_call']);
        $this->assertFalse($plan['provider_tokens_spent']);
        $this->assertContains('cursor_cli_force_mode_forbidden', $plan['blockers']);
        $this->assertContains('cursor_cli_output_format_must_be_stream_json', $plan['blockers']);
        $this->assertFalse($plan['plan_safe']);
    }

    public function test_invoke_blocks_promotion_when_forbidden_file_changes(): void
    {
        $this->installFakeCursorAgent();
        config()->set('atlas.ai.providers.cursor_cli.enabled', true);

        $forbidden = base_path('storage/framework/testing/cursor-cli-forbidden.txt');
        @mkdir(dirname($forbidden), 0777, true);
        @unlink($forbidden);

        $runner = new AtlasForgeProviderProcessRunner;
        $runner->setProcessFactory(function (array $argv, ?string $cwd, ?array $env, int $timeout) use ($forbidden): Process {
            $code = 'file_put_contents('.var_export($forbidden, true).', "bad"); echo "changed";';

            return new Process([PHP_BINARY, '-r', $code], $cwd, $env, null, $timeout);
        });

        $driver = new AtlasForgeCursorCliInvocationDriver(
            app(AtlasForgeProviderCommandAllowlistService::class),
            $runner,
            app(AtlasForgeProviderInvocationFailureClassifier::class),
        );

        $request = $this->validRequest();
        $request['prompt']['scope_contract']['forbidden_files'] = ['storage/framework/testing/cursor-cli-forbidden.txt'];
        $result = $driver->invoke($request);

        $this->assertContains('cursor_cli_forbidden_file_changed', $result['blockers']);
        $this->assertSame(['storage/framework/testing/cursor-cli-forbidden.txt'], $result['forbidden_changed_files']);
        $this->assertSame('cursor_cli_forbidden_file_changed', $result['failure_type']);
    }

    private function installFakeCursorAgent(): void
    {
        $dir = sys_get_temp_dir().'/atlas_cursor_cli_'.str_replace('.', '', uniqid('', true));
        mkdir($dir, 0777, true);
        $binary = $dir.'/cursor-agent';
        file_put_contents($binary, "#!/bin/sh\nexit 0\n");
        chmod($binary, 0755);
        $this->fakeBinDir = $dir;
        putenv('PATH='.$dir.':'.($this->oldPath ?? ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function validRequest(): array
    {
        return [
            'model' => 'composer-latest',
            'prompt' => [
                'schema_version' => 'atlas.forge.provider_invocation_prompt.v1',
                'decision_receipt_id' => 'receipt_1',
                'decision_receipt_hash' => hash('sha256', 'receipt_1'),
                'scope_contract' => [
                    'allowed_files' => ['app/Foo.php'],
                    'forbidden_files' => ['.env'],
                ],
            ],
            'cwd' => base_path(),
            'decision_receipt_id' => 'receipt_1',
            'decision_receipt_hash' => hash('sha256', 'receipt_1'),
            'timeout_seconds' => 5,
        ];
    }
}
