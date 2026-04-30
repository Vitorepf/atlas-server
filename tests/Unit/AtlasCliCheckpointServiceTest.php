<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliCheckpointService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliCheckpointServiceTest extends TestCase
{
    private string $workspace;

    private string $checkpointDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-checkpoint-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/note.txt', 'current');

        $this->checkpointDir = storage_path('app/ai/checkpoints/20990101-000000-test-'.bin2hex(random_bytes(3)));
        File::ensureDirectoryExists($this->checkpointDir);
        File::put($this->checkpointDir.'/checkpoint.json', json_encode([
            'workspace' => $this->workspace,
            'reason' => 'file.write',
            'created_at' => '2099-01-01T00:00:00Z',
            'files' => [
                ['path' => 'note.txt', 'existed' => true],
            ],
        ], JSON_PRETTY_PRINT));
        File::put($this->checkpointDir.'/note.txt', 'before');

        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.tool_permissions.allow_danger' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::deleteDirectory($this->checkpointDir);

        parent::tearDown();
    }

    public function test_lists_shows_and_restores_workspace_checkpoint(): void
    {
        $service = app(AtlasCliCheckpointService::class);

        $items = $service->list($this->workspace);
        $this->assertNotEmpty($items);
        $this->assertSame('file.write', $items[0]['reason']);
        $this->assertSame('note.txt', $items[0]['files'][0]['path']);

        $shown = $service->show($this->workspace, basename($this->checkpointDir));
        $this->assertSame($this->checkpointDir, $shown['path']);

        $restored = $service->restore($this->workspace, basename($this->checkpointDir), approved: true);

        $this->assertTrue($restored['result']['ok']);
        $this->assertSame('before', File::get($this->workspace.'/note.txt'));
    }
}
