<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliFixCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-fix-command-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode(['scripts' => ['test' => 'php -r "exit(0);"']], JSON_PRETTY_PRINT));
        (new Process(['git', 'init'], $this->workspace))->run();

        config(['atlas.cli.php_binary' => '/opt/homebrew/bin/php']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_fix_plan_only_uses_dev_repair_kernel_flow(): void
    {
        $exitCode = Artisan::call('atlas:cli:fix', [
            'description' => ['teste', 'quebrado'],
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--max-iterations' => '4',
            '--auto-test' => true,
            '--allow-write' => true,
            '--plan-only' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('repair', data_get($payload, 'dev_execution_plan.operator_options.programming_intent'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.complete'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.operator_options.auto_test'));
        $this->assertSame(4, data_get($payload, 'dev_execution_plan.quality_gate_policy.max_iterations'));
        $this->assertSame('programming.repair', data_get($payload, 'dev_execution_plan.kernel_pipeline.input.safe_hints.flow'));
        $this->assertSame('repair', data_get($payload, 'dev_execution_plan.kernel_pipeline.input.safe_hints.task'));
        $this->assertSame('one_shot', data_get($payload, 'dev_execution_plan.kernel_pipeline.surface_binding.input_mode'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'dev_execution_plan.kernel_pipeline.input.surface_id'));
        $this->assertSame('atlas.cli_fix.contract.v1', data_get($payload, 'dev_execution_plan.fix_contract.schema_version'));
        $this->assertSame('atlas_cli_fix', data_get($payload, 'dev_execution_plan.fix_contract.surface'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'dev_execution_plan.fix_contract.canonical_surface'));
        $this->assertSame('atlas_cli_dev', data_get($payload, 'dev_execution_plan.fix_contract.target_surface'));
        $this->assertSame('programming.repair', data_get($payload, 'dev_execution_plan.fix_contract.flow'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.fix_contract.dev_flags.repair'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.fix_contract.dev_flags.plan_only'));
        $this->assertFalse(data_get($payload, 'dev_execution_plan.kernel_pipeline.provider_execution_allowed'));
        $this->assertTrue(data_get($payload, 'dev_execution_plan.kernel_pipeline_contract.required'));
        $this->assertContains('--auto-test', $payload['chat_command']);
    }
}
