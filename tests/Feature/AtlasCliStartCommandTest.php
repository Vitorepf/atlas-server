<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliStartCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-start-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        (new Process(['git', 'init'], $this->workspace))->run();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_start_renders_fresh_briefing_when_no_recent_thread(): void
    {
        $exit = Artisan::call('atlas:cli:start', [
            '--workspace' => $this->workspace,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('contexto', $output);
        $this->assertStringContainsString('proxima acao', $output);
        $this->assertStringContainsString('atlas dev', $output);
    }

    public function test_start_emits_json_payload_with_fresh_action(): void
    {
        $exit = Artisan::call('atlas:cli:start', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame($this->workspace, $payload['workspace']);
        $this->assertIsArray($payload['next_action']);
        $this->assertSame('fresh', $payload['next_action']['kind']);
        $this->assertStringContainsString('atlas dev', (string) $payload['next_action']['command']);
    }

    public function test_start_briefing_ends_with_a_single_concrete_command(): void
    {
        $exit = Artisan::call('atlas:cli:start', [
            '--workspace' => $this->workspace,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $matches = [];
        preg_match_all('/atlas\s+(dev|continue|chat|start)/', $output, $matches);
        $this->assertNotEmpty($matches[0], 'Esperava UM comando concreto no rodape do briefing');
        $this->assertLessThanOrEqual(2, count($matches[0]), 'Briefing deve terminar com UMA acao, nao um menu');
    }
}
