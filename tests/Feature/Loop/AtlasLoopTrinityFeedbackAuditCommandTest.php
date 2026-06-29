<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the Trinity feedback auditor is live at the operator surface: a cycle where all three sources emit new
 * facts is not static; a missing --cycle is a usage_error.
 */
final class AtlasLoopTrinityFeedbackAuditCommandTest extends TestCase
{
    public function test_trinity_feedback_audit_emits_result_for_cycle(): void
    {
        $this->app->bind('atlas.loop.trinity.stream', fn (): array => [
            ['factId' => 'f1', 'source' => 'loop', 'cycleId' => 'c1'],
            ['factId' => 'f2', 'source' => 'cortex', 'cycleId' => 'c1'],
            ['factId' => 'f3', 'source' => 'maestro', 'cycleId' => 'c1'],
        ]);

        $exit = Artisan::call('atlas:loop:trinity-feedback-audit', ['--cycle' => 'c1', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.trinity_feedback_audit.v1', $decoded['schema_version']);
        $this->assertSame('c1', $decoded['cycle_id']);
        $this->assertFalse($decoded['is_static'], 'all three sources moved ⇒ not a static cycle');
        $this->assertContains('f1', $decoded['new_loop_fact_ids']);
        $this->assertSame([], $decoded['violations']);
    }

    public function test_missing_cycle_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:trinity-feedback-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
