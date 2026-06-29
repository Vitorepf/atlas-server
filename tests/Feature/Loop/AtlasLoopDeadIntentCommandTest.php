<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopDeadIntentDetector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the dead-intent detector is live at the operator surface (via the atlas:loop:dead-intent command):
 * a grounded symbol with zero live consumers is flagged with reason zero_live_consumers; a symbol with live
 * consumers is NOT flagged.
 */
final class AtlasLoopDeadIntentCommandTest extends TestCase
{
    private function detect(array $symbols): array
    {
        $exit = Artisan::call('atlas:loop:dead-intent', [
            '--symbols' => (string) json_encode($symbols),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_zero_consumer_grounded_symbol_is_flagged(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->detect([
            ['fqcn' => 'App\\Dead', 'rel_path' => 'app/Dead.php', 'live_consumer_count' => 0],
            ['fqcn' => 'App\\Alive', 'rel_path' => 'app/Alive.php', 'live_consumer_count' => 3],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopDeadIntentDetector::SCHEMA_VERSION, $d['schema']);
        $this->assertSame(1, $d['dead_intent_count'], (string) json_encode($d));
        $this->assertSame('App\\Dead', $d['dead_intent'][0]['fqcn']);
        $this->assertSame('zero_live_consumers', $d['dead_intent'][0]['reason']);
        $this->assertSame(0, $d['dead_intent'][0]['live_consumer_count']);
    }

    public function test_symbol_with_consumers_is_not_flagged(): void
    {
        ['d' => $d] = $this->detect([
            ['fqcn' => 'App\\Alive', 'rel_path' => 'app/Alive.php', 'live_consumer_count' => 2],
        ]);

        $this->assertSame(0, $d['dead_intent_count']);
        $this->assertSame([], $d['dead_intent']);
    }

    public function test_missing_symbols_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:dead-intent', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
