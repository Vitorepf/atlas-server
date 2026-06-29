<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the proxy-drift fact detector is live at the operator surface: the command emits the deterministic
 * proxy-drift facts for a campaign; a missing --campaign is a usage_error.
 */
final class AtlasLoopProxyDriftFactCommandTest extends TestCase
{
    public function test_proxy_drift_facts_emitted_for_campaign(): void
    {
        $exit = Artisan::call('atlas:loop:proxy-drift-facts', ['--campaign' => 'camp-test', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.proxy_drift_fact.v1', $decoded['schema_version']);
        $this->assertSame('camp-test', $decoded['campaign_id']);
        $this->assertArrayHasKey('drifting', $decoded);
        $this->assertIsBool($decoded['drifting']);
        $this->assertArrayHasKey('drift_ratio', $decoded);
        $this->assertIsArray($decoded['fact_evidence']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:proxy-drift-facts', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
