<?php

namespace Tests\Unit;

use App\Services\Semantic\AtlasVaultLinkService;
use InvalidArgumentException;
use Tests\TestCase;

class AtlasVaultLinkServiceTest extends TestCase
{
    public function test_generates_and_parses_atlas_links(): void
    {
        $service = new AtlasVaultLinkService;

        $uris = [
            'atlas://memory/mem_123' => ['memory', 'mem_123'],
            'atlas://verbatim-memory/verbatim_123' => ['verbatim-memory', 'verbatim_123'],
            'atlas://semantic-note/note_123' => ['semantic-note', 'note_123'],
            'atlas://task/task_456' => ['task', 'task_456'],
            'atlas://project/proj_789' => ['project', 'proj_789'],
            'atlas://engineering-run/run_123' => ['engineering-run', 'run_123'],
            'atlas://open-brain/audit/audit_123' => ['open-brain/audit', 'audit_123'],
            'atlas://trace/trace_123' => ['trace', 'trace_123'],
        ];

        foreach ($uris as $uri => [$type, $id]) {
            $this->assertSame($uri, $service->generate($type, $id));
            $this->assertSame($type, $service->parse($uri)['type']);
            $this->assertSame($id, $service->parse($uri)['id']);
        }
    }

    public function test_generates_links_markdown_block(): void
    {
        $block = (new AtlasVaultLinkService)->markdownBlock([
            ['label' => 'Memory', 'type' => 'memory', 'id' => 'mem_123'],
            ['label' => 'Task', 'type' => 'task', 'id' => 'task_456'],
            ['label' => 'Canonical Doc', 'path' => 'docs/engineering-knowledge-base/open-brain-context-injection.md'],
        ]);

        $this->assertStringContainsString('- Memory: atlas://memory/mem_123', $block);
        $this->assertStringContainsString('- Task: atlas://task/task_456', $block);
        $this->assertStringContainsString('- Canonical Doc: docs/engineering-knowledge-base/open-brain-context-injection.md', $block);
    }

    public function test_rejects_path_traversal_link_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultLinkService)->generate('memory', '../secret');
    }

    public function test_rejects_ids_with_unsupported_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultLinkService)->generate('memory', 'bad id');
    }

    public function test_markdown_block_rejects_invalid_atlas_uri(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultLinkService)->markdownBlock([
            ['label' => 'Memory', 'uri' => 'atlas://memory/../secret'],
        ]);
    }

    public function test_markdown_block_normalizes_multiline_labels(): void
    {
        $block = (new AtlasVaultLinkService)->markdownBlock([
            ['label' => "Task\nLink", 'uri' => 'atlas://task/task_456'],
        ]);

        $this->assertStringContainsString('- Task Link: atlas://task/task_456', $block);
    }

    public function test_markdown_block_rejects_empty_labels(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultLinkService)->markdownBlock([
            ['label' => '', 'uri' => 'atlas://task/task_456'],
        ]);
    }

    public function test_markdown_block_rejects_unsafe_canonical_path(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AtlasVaultLinkService)->markdownBlock([
            ['label' => 'Canonical Doc', 'path' => '../secret.md'],
        ]);
    }

    public function test_exposes_supported_link_types(): void
    {
        $this->assertSame([
            'memory',
            'verbatim-memory',
            'semantic-note',
            'task',
            'project',
            'engineering-run',
            'open-brain/audit',
            'trace',
        ], (new AtlasVaultLinkService)->supportedTypes());
    }
}
