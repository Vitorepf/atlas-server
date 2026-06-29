<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the doc-structure analyzer is live at the operator surface: the command audits a markdown doc and emits
 * its deterministic structure facts; a missing --file is a usage_error.
 */
final class AtlasLoopDocStructureAuditCommandTest extends TestCase
{
    public function test_doc_structure_audit_emits_structure_facts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas_doc_').'.md';
        file_put_contents($tmp, "# Title\n\n## Section A\n\nbody text\n");

        try {
            $exit = Artisan::call('atlas:loop:doc-structure-audit', ['--file' => $tmp, '--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exit);
            $this->assertSame('atlas.loop.doc_structure.v1', $decoded['schema_version']);
            $this->assertSame($tmp, $decoded['path']);
            $this->assertArrayHasKey('is_module', $decoded);
            $this->assertIsArray($decoded['violations']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_missing_file_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:doc-structure-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
