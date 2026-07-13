<?php

namespace Tests\Feature\Ai\Rivals;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasDevBridgeTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/rivals_atlas_dev_bridge_'.uniqid();
        File::ensureDirectoryExists($this->workspace);
        file_put_contents($this->workspace.'/.rivals_task.md', 'Fix the scoped test.');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_bridge_plan_locks_model_and_disables_decide_and_fallback(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-atlas-dev-bridge.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=claude-sonnet-5',
            '--dry-run',
        ], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertTrue($payload['fair_mode']['single_provider']);
        $this->assertTrue($payload['fair_mode']['decide_disabled']);
        $this->assertTrue($payload['fair_mode']['fallback_disabled']);
        $this->assertTrue($payload['fair_mode']['deterministic_fast_path_disabled']);
        $this->assertContains('--single-provider', $payload['argv']);
        $this->assertContains('--no-decide', $payload['argv']);
        $this->assertContains('--fallback-disabled', $payload['argv']);
        $this->assertContains('--provider=claude_cli', $payload['argv']);
    }

    public function test_bridge_routes_verboo_kimi_through_hermes_without_provider_fallback(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-atlas-dev-bridge.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=kimi-k2.7',
            '--dry-run',
        ], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertSame('hermes', $payload['ai']);
        $this->assertSame('hermes_cli_oneshot', $payload['execution']);
        $this->assertNull($payload['provider']);
        $this->assertSame('hermes', $payload['argv'][0] ?? null);
        $this->assertContains('-z', $payload['argv']);
        $this->assertContains('--provider', $payload['argv']);
        $this->assertContains('verboo', $payload['argv']);
        $this->assertNotContains('--provider=claude_cli', $payload['argv']);
        $this->assertNotContains('--provider=codex_cli', $payload['argv']);
    }

    public function test_bare_bridge_locks_verboo_provider_and_same_kimi_model(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('scripts/rivals-hermes-bare.php'),
            '--workspace='.$this->workspace,
            '--prompt-file='.$this->workspace.'/.rivals_task.md',
            '--model=kimi-k2.7',
            '--dry-run',
        ], base_path());
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $payload = json_decode($process->getOutput(), true);

        $this->assertSame('verboo', $payload['provider']);
        $this->assertSame('kimi-k2.7', $payload['model']);
        $this->assertContains('--provider', $payload['argv']);
        $this->assertContains('verboo', $payload['argv']);
        $this->assertContains('kimi-k2.7', $payload['argv']);
    }
}
