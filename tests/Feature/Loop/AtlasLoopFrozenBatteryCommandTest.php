<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Constitution\AtlasLoopFrozenOutcomeBattery;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the frozen outcome battery is live at the operator surface: it emits a non-empty pairs list and a
 * stable integrity root hash (byte-identical across runs).
 */
final class AtlasLoopFrozenBatteryCommandTest extends TestCase
{
    private function dump(): array
    {
        Artisan::call('atlas:loop:frozen-battery', ['--json' => true]);

        return json_decode(trim(Artisan::output()), true);
    }

    public function test_emits_pairs_and_root_hash(): void
    {
        $d = $this->dump();

        $this->assertSame(AtlasLoopFrozenOutcomeBattery::SCHEMA_VERSION, $d['schema']);
        $this->assertGreaterThan(0, $d['pair_count'], (string) json_encode($d));
        $this->assertNotEmpty($d['pairs']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $d['root_hash']);
    }

    public function test_root_hash_is_stable_across_runs(): void
    {
        $a = $this->dump();
        $b = $this->dump();

        $this->assertSame($a['root_hash'], $b['root_hash']);
        $this->assertSame($a['pair_count'], $b['pair_count']);
    }
}
