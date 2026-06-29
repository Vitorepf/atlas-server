<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the comprehension-doc gap miner is live at the operator surface: over a fixture docs dir the command
 * emits sections and a doc_stated_gaps entry carrying the matched gap_marker_hit.
 */
final class AtlasLoopComprehensionDocsCommandTest extends TestCase
{
    private string $docsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docsDir = sys_get_temp_dir().'/atlas-comprehension-docs-'.bin2hex(random_bytes(5));
        mkdir($this->docsDir, 0o775, true);
        file_put_contents(
            $this->docsDir.'/loop-fixture.md',
            "# Loop Fixture\n\n## Gaps\n\n- falta wiring do organ X\n",
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->docsDir.'/loop-fixture.md');
        @rmdir($this->docsDir);
        parent::tearDown();
    }

    public function test_command_emits_sections_and_doc_stated_gaps(): void
    {
        $exit = Artisan::call('atlas:loop:comprehension-docs', ['--docs-dir' => $this->docsDir, '--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sections', $decoded);
        $this->assertIsArray($decoded['doc_stated_gaps']);
        $this->assertNotEmpty($decoded['doc_stated_gaps']);
        $this->assertSame('falta', $decoded['doc_stated_gaps'][0]['gap_marker_hit']);
    }
}
