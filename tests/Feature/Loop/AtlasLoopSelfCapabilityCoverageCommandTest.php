<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the self-capability-coverage reporter is live at the operator surface: the command emits the
 * facts-only coverage map (schema_version) over the app tree with a non-empty capabilities map.
 */
final class AtlasLoopSelfCapabilityCoverageCommandTest extends TestCase
{
    public function test_command_emits_self_capability_coverage_facts(): void
    {
        $exit = Artisan::call('atlas:loop:self-capability-coverage', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded);
        $this->assertSame('atlas.loop.self_capability_coverage_facts.v1', $decoded['schema_version']);
        $this->assertIsArray($decoded['capabilities']);
        $this->assertNotEmpty($decoded['capabilities']);
    }
}
