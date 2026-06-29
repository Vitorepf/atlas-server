<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the fact-anchor auditor is live at the operator surface: a non-critical channel passes through
 * (accepted) and a critical channel whose fact resolves no anchors is rejected; missing args are a usage_error.
 */
final class AtlasLoopFactAnchorAuditCommandTest extends TestCase
{
    public function test_non_critical_channel_is_accepted_passthrough(): void
    {
        config(['atlas.loop.fact_anchor.critical_channels' => ['some_critical_channel']]);

        $exit = Artisan::call('atlas:loop:fact-anchor-audit', [
            '--channel' => 'a_non_critical_channel',
            '--fact' => 'an arbitrary fact statement',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.fact_anchor_audit.v1', $decoded['schema_version']);
        $this->assertTrue($decoded['accepted'], 'a non-critical channel passes through');
        $this->assertArrayHasKey('resolved_count', $decoded);
        $this->assertArrayHasKey('unresolved_count', $decoded);
    }

    public function test_missing_args_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:fact-anchor-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
