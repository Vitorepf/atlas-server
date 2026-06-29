<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the doc-claim analyzer is live at the operator surface: the command audits a doc and emits the
 * deterministic doc-claim facts (phantoms + checked counts); a missing --file is a usage_error.
 */
final class AtlasLoopDocClaimAuditCommandTest extends TestCase
{
    public function test_doc_claim_audit_emits_phantom_facts(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'atlas_doc_claim_').'.md';
        file_put_contents($tmp, "# Doc\n\nPlain documentation with no code or command claims.\n");

        try {
            $exit = Artisan::call('atlas:loop:doc-claim-audit', ['--file' => $tmp, '--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exit);
            $this->assertSame('atlas.loop.doc_claim.v1', $decoded['schema_version']);
            $this->assertTrue($decoded['readable']);
            $this->assertIsArray($decoded['phantoms']);
            $this->assertArrayHasKey('commands', $decoded['checked']);
            $this->assertArrayHasKey('classes', $decoded['checked']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_missing_file_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:doc-claim-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
