<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\CausalGraph\AtlasLoopDeadIntentDetector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the dead-intent detector is live at the operator surface: only an inventory-grounded, non-forbidden
 * symbol with ZERO live consumers is harvested; symbols with consumers, forbidden targets, and ungrounded
 * bare FQCNs are all refused (fail-closed).
 */
final class AtlasLoopDeadIntentDetectCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-dead-intent-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    public function test_detects_only_grounded_zero_consumer_symbols(): void
    {
        file_put_contents($this->input, (string) json_encode([
            // dead: grounded + zero live consumers
            ['fqcn' => 'App\\Dead', 'rel_path' => 'app/Dead.php', 'live_consumer_count' => 0],
            // alive: has consumers
            ['fqcn' => 'App\\Alive', 'rel_path' => 'app/Alive.php', 'live_consumer_count' => 2],
            // forbidden: never harvested even at zero consumers
            ['fqcn' => 'App\\Frozen', 'rel_path' => 'app/Frozen.php', 'live_consumer_count' => 0, 'is_forbidden' => true],
            // ungrounded bare FQCN: refused
            ['fqcn' => 'App\\Bare', 'live_consumer_count' => 0],
        ]));

        $exit = Artisan::call('atlas:loop:dead-intent-detect', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopDeadIntentDetector::SCHEMA_VERSION, $d['schema']);
        $this->assertSame(1, $d['dead_intent_count'], (string) json_encode($d));
        $this->assertSame('App\\Dead', $d['dead_intent'][0]['fqcn']);
        $this->assertSame('zero_live_consumers', $d['dead_intent'][0]['reason']);
        $this->assertSame(0, $d['dead_intent'][0]['live_consumer_count']);
    }

    public function test_consumers_list_is_counted(): void
    {
        file_put_contents($this->input, (string) json_encode([
            // wired_caller_paths empty ⇒ zero consumers ⇒ dead
            ['fqcn' => 'App\\Orphan', 'rel_path' => 'app/Orphan.php', 'wired_caller_paths' => []],
            // non-empty consumers ⇒ alive
            ['fqcn' => 'App\\Used', 'rel_path' => 'app/Used.php', 'wired_caller_paths' => ['app/X.php']],
        ]));

        Artisan::call('atlas:loop:dead-intent-detect', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(['App\\Orphan'], array_column($d['dead_intent'], 'fqcn'));
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:dead-intent-detect', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
