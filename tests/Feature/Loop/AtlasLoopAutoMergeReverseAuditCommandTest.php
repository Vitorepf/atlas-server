<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Merge\AtlasLoopAutoMergeReverseAuditor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the auto-merge reverse auditor is live at the operator surface: the command emits a valid reverse-audit
 * verdict echoing the supplied SHAs; missing SHAs are a usage_error.
 */
final class AtlasLoopAutoMergeReverseAuditCommandTest extends TestCase
{
    public function test_reverse_audit_emits_verdict(): void
    {
        $exit = Artisan::call('atlas:loop:auto-merge-reverse-audit', ['--pre' => 'abc123', '--merge' => 'def456', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.automerge_reverse_audit.v1', $decoded['schema_version']);
        $this->assertSame('abc123', $decoded['pre_merge_sha']);
        $this->assertSame('def456', $decoded['merge_sha']);
        $this->assertContains($decoded['verdict'], [
            AtlasLoopAutoMergeReverseAuditor::VERDICT_CONFIRMED,
            AtlasLoopAutoMergeReverseAuditor::VERDICT_ROLLED_BACK,
            AtlasLoopAutoMergeReverseAuditor::VERDICT_ABSTAIN,
            AtlasLoopAutoMergeReverseAuditor::VERDICT_REVERT_FAILED,
        ]);
        $this->assertArrayHasKey('gate_diagnostics', $decoded);
    }

    public function test_missing_shas_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:auto-merge-reverse-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
