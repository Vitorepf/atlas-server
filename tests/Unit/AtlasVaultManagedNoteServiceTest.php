<?php

namespace Tests\Unit;

use App\Services\Semantic\AtlasVaultManagedNoteService;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class AtlasVaultManagedNoteServiceTest extends TestCase
{
    private string $vault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vault = sys_get_temp_dir().'/atlas-vault-service-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->vault);
        config()->set('atlas.semantic_memory.vault_path', $this->vault);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vault);

        parent::tearDown();
    }

    public function test_preview_generates_supported_task_note_path_and_link(): void
    {
        $note = app(AtlasVaultManagedNoteService::class)->preview([
            'type' => 'task',
            'id' => 'task_456',
            'title' => 'Follow Up Task',
            'summary' => 'Resumo humano.',
        ]);

        $this->assertSame('Atlas/Tasks/managed/follow-up-task-task_456.md', $note->path);
        $this->assertStringContainsString('- Task: atlas://task/task_456', $note->markdown);
        $this->assertFalse(File::exists($this->vault.'/'.$note->path));
    }

    public function test_preview_rejects_unsafe_note_id_before_path_generation(): void
    {
        $this->expectException(RuntimeException::class);

        app(AtlasVaultManagedNoteService::class)->preview([
            'type' => 'memory_entry',
            'id' => 'bad/id',
            'title' => 'Unsafe',
        ]);
    }

    public function test_preview_rejects_invalid_extra_atlas_links(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AtlasVaultManagedNoteService::class)->preview([
            'type' => 'memory_entry',
            'id' => 'mem_123',
            'title' => 'Unsafe Link',
            'links' => [
                ['label' => 'Bad', 'uri' => 'atlas://task/../secret'],
            ],
        ]);
    }

    public function test_preview_rejects_malformed_programmatic_extra_links(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AtlasVaultManagedNoteService::class)->preview([
            'type' => 'memory_entry',
            'id' => 'mem_123',
            'title' => 'Malformed Link',
            'links' => ['atlas://task/task_456'],
        ]);
    }

    public function test_preview_rejects_content_with_reserved_managed_markers(): void
    {
        $this->expectException(RuntimeException::class);

        app(AtlasVaultManagedNoteService::class)->preview([
            'type' => 'memory_entry',
            'id' => 'mem_123',
            'title' => 'Reserved Marker',
            'content' => 'Texto '.AtlasVaultManagedNoteService::MANAGED_END,
        ]);
    }

    public function test_preview_includes_safe_extra_atlas_link_and_canonical_doc(): void
    {
        $note = app(AtlasVaultManagedNoteService::class)->preview([
            'type' => 'memory_entry',
            'id' => 'mem_123',
            'title' => 'Linked Memory',
            'links' => [
                ['label' => 'Task', 'uri' => 'atlas://task/task_456'],
                ['label' => 'Canonical Doc', 'path' => 'docs/engineering-knowledge-base/open-brain-context-injection.md'],
            ],
        ]);

        $this->assertStringContainsString('- Memory: atlas://memory/mem_123', $note->markdown);
        $this->assertStringContainsString('- Task: atlas://task/task_456', $note->markdown);
        $this->assertStringContainsString(
            '- Canonical Doc: docs/engineering-knowledge-base/open-brain-context-injection.md',
            $note->markdown,
        );
    }
}
