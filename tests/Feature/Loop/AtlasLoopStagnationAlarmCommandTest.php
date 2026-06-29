<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the stagnation alarm detector is live at the operator surface: the command emits the deterministic
 * stagnation facts for a campaign; a missing --campaign is a usage_error.
 */
final class AtlasLoopStagnationAlarmCommandTest extends TestCase
{
    public function test_stagnation_alarm_emits_facts_for_campaign(): void
    {
        $exit = Artisan::call('atlas:loop:stagnation-alarm', ['--campaign' => 'camp-test', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.stagnation_alarm.v1', $decoded['schema_version']);
        $this->assertSame('camp-test', $decoded['campaign_id']);
        $this->assertArrayHasKey('stagnated', $decoded);
        $this->assertIsBool($decoded['stagnated']);
        $this->assertIsArray($decoded['fact_evidence']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:stagnation-alarm', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
