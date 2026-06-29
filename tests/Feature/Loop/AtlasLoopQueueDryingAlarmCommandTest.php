<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the queue-drying alarm detector is live at the operator surface: the command emits the deterministic
 * alarm facts for a campaign; a missing --campaign is a usage_error.
 */
final class AtlasLoopQueueDryingAlarmCommandTest extends TestCase
{
    public function test_queue_drying_alarm_emits_facts_for_campaign(): void
    {
        $exit = Artisan::call('atlas:loop:queue-drying-alarm', ['--campaign' => 'camp-test', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.queue_drying_alarm.v1', $decoded['schema_version']);
        $this->assertSame('camp-test', $decoded['campaign_id']);
        $this->assertArrayHasKey('drying', $decoded);
        $this->assertIsBool($decoded['drying']);
        $this->assertIsArray($decoded['fact_evidence']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:queue-drying-alarm', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
