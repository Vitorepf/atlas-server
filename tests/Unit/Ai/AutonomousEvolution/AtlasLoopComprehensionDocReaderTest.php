<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionDocReader;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopComprehensionDocReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        parent::tearDown();
    }

    public function test_matching_gap_bullet_becomes_one_doc_stated_gap_with_exact_line_number(): void
    {
        $dir = $this->docsDir();
        file_put_contents($dir.'/loop-sample.md', "# Phase One\n## Wiring\n- TODO: wire foo\n- working as designed\n");

        $payload = (new AtlasLoopComprehensionDocReader)->read($dir);

        $this->assertCount(1, $payload['doc_stated_gaps']);
        $this->assertStringContainsString('wire foo', $payload['doc_stated_gaps'][0]['text']);
        $this->assertSame(3, $payload['doc_stated_gaps'][0]['line_number']);
        $this->assertSame(['Phase One', 'Wiring'], $payload['doc_stated_gaps'][0]['heading_chain']);
    }

    public function test_doc_without_matching_bullets_returns_empty_gaps_and_non_empty_sections(): void
    {
        $dir = $this->docsDir();
        file_put_contents($dir.'/loop-clean.md', "# Overview\n## Stable\n- working as designed\n");

        $payload = (new AtlasLoopComprehensionDocReader)->read($dir);

        $this->assertSame([], $payload['doc_stated_gaps']);
        $this->assertNotEmpty($payload['sections']);
        $this->assertCount(2, $payload['sections']);
    }

    public function test_reader_only_uses_the_provided_tmp_dir_and_never_needs_real_loop_docs(): void
    {
        $dir = $this->docsDir();
        file_put_contents($dir.'/loop-local.md', "# Local\n- missing local seam\n");

        $payload = (new AtlasLoopComprehensionDocReader)->read($dir);

        $this->assertCount(1, $payload['doc_stated_gaps']);
        $this->assertStringStartsWith($dir, $payload['doc_stated_gaps'][0]['doc_path']);
        $this->assertStringNotContainsString(base_path('docs/loop-canonical-definition.md'), json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function docsDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-doc-reader-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->paths[] = $dir;

        return $dir;
    }
}
