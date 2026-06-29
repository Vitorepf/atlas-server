<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the battery genesis is live at the operator surface and emits deterministic facts: the generated
 * cases include the known-bad anti-patterns (expected REFUTE) and the known-good obligations (expected
 * CERTIFY); every bad case expects REFUTE and every good case expects CERTIFY.
 */
final class AtlasLoopBatteryGenesisCommandTest extends TestCase
{
    public function test_emits_known_bad_and_good_battery_cases(): void
    {
        $exit = Artisan::call('atlas:loop:battery-genesis', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.battery_genesis.v1', $decoded['schema']);
        $this->assertGreaterThan(0, $decoded['count']);

        $byId = array_column($decoded['cases'], null, 'id');
        $this->assertArrayHasKey('bad-fake_green_noop', $byId);
        $this->assertSame('REFUTE', $byId['bad-fake_green_noop']['expected_verdict']);
        $this->assertArrayHasKey('good-genuine_red_to_green', $byId);
        $this->assertSame('CERTIFY', $byId['good-genuine_red_to_green']['expected_verdict']);

        foreach ($decoded['cases'] as $case) {
            $expected = $case['kind'] === 'good' ? 'CERTIFY' : 'REFUTE';
            $this->assertSame($expected, $case['expected_verdict']);
        }
    }
}
