<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliInterruptCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-interrupt-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        (new Process(['git', 'init'], $this->workspace))->run();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_interrupt_returns_success_and_no_op_message_when_no_active_trace(): void
    {
        $exit = Artisan::call('atlas:cli:interrupt', [
            '--workspace' => $this->workspace,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('sem execucao ativa', $output);
    }

    public function test_interrupt_emits_json_payload_when_no_active_trace(): void
    {
        $exit = Artisan::call('atlas:cli:interrupt', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['cancelled']);
        $this->assertSame($this->workspace, $payload['workspace']);
    }
}
