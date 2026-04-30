<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliDevCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dev-command-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        File::put($this->workspace.'/composer.json', json_encode(['scripts' => ['test' => 'php -r "exit(0);"']], JSON_PRETTY_PRINT));
        (new Process(['git', 'init'], $this->workspace))->run();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_plan_only_complete_auto_activates_dev_quality_gate(): void
    {
        $exitCode = Artisan::call('atlas:cli:dev', [
            'task' => ['implementar', 'getter'],
            '--workspace' => $this->workspace,
            '--provider' => 'codex_cli',
            '--plan-only' => true,
            '--complete' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertContains('dev-quality-gate', $payload['activated_skills']);
        $this->assertContains('--skill=dev-quality-gate', $payload['chat_command']);
        $this->assertSame('passed', data_get($payload, 'dev_execution_plan.quality_gate_policy.required_final_status'));
        $this->assertSame('plan_validate_execute', data_get($payload, 'dev_execution_plan.quality_gate_policy.procedure'));
    }
}
