<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverageSelector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the leverage selector is live at the operator surface: the model's in-range pick is reordered to the
 * front (a permutation, never a mutation), and a malformed pick fail-closes to the producer's original order.
 */
final class AtlasLoopLeverageSelectCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-leverage-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($this->input, (string) json_encode([
            ['candidateId' => 'a', 'summary' => 'small refactor'],
            ['candidateId' => 'b', 'summary' => 'big capability leap'],
            ['candidateId' => 'c', 'summary' => 'doc tidy'],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function bindSelector(?string $response): void
    {
        $this->app->instance(
            AtlasLoopLeverageSelector::class,
            new AtlasLoopLeverageSelector(fn (string $provider, string $prompt): ?string => $response),
        );
    }

    public function test_model_pick_is_reordered_to_front(): void
    {
        $this->bindSelector("<<<PICK>>>\n1"); // pick index 1 ('b')

        $exit = Artisan::call('atlas:loop:leverage-select', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.leverage_selection.v1', $d['schema']);
        $this->assertSame('b', $d['winner']['candidateId'], (string) json_encode($d));
        $this->assertSame(['b', 'a', 'c'], array_column($d['ranked'], 'candidateId')); // permutation, rest stable
        $this->assertSame(3, $d['count']);
    }

    public function test_malformed_pick_fails_closed_to_original_order(): void
    {
        $this->bindSelector('no marker here'); // malformed ⇒ pickIndex null ⇒ unchanged

        $exit = Artisan::call('atlas:loop:leverage-select', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(['a', 'b', 'c'], array_column($d['ranked'], 'candidateId'));
    }

    public function test_out_of_range_pick_fails_closed(): void
    {
        $this->bindSelector("<<<PICK>>>\n9"); // out of [0,3) ⇒ rejected ⇒ unchanged

        Artisan::call('atlas:loop:leverage-select', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(['a', 'b', 'c'], array_column($d['ranked'], 'candidateId'));
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:leverage-select', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
