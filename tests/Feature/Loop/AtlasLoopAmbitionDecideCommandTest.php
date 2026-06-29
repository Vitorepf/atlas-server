<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the ambition decider is live at the operator surface: under the convex-payoff strategy a big, lower-
 * probability leap outranks a small safe one, the winner carries a proportionally stronger verification tier,
 * and the ordering is deterministic.
 */
final class AtlasLoopAmbitionDecideCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-ambition-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    public function test_big_risky_leap_outranks_small_safe_one(): void
    {
        file_put_contents($this->input, (string) json_encode([
            ['candidateId' => 'small_safe', 'leap_magnitude' => 1.0, 'p_land' => 0.95],
            ['candidateId' => 'big_risky', 'leap_magnitude' => 10.0, 'p_land' => 0.30],
            ['candidateId' => 'medium', 'leap_magnitude' => 3.5, 'p_land' => 0.60],
        ]));

        $exit = Artisan::call('atlas:loop:ambition-decide', ['--input' => $this->input, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.ambition_decision.v1', $d['schema_version']);
        $this->assertSame('big_risky', $d['winner']['candidateId'], (string) json_encode($d));
        $this->assertSame(['big_risky', 'medium', 'small_safe'], array_column($d['ranked'], 'candidateId'));
        // honest coupling: the enormous leap demands the maximal verification tier
        $this->assertSame('maximal', $d['winner']['required_verification']['tier']);
        $this->assertSame(0.35, $d['risk_tolerance']); // default risk tolerance
    }

    public function test_risk_neutral_tolerance_prefers_high_probability(): void
    {
        file_put_contents($this->input, (string) json_encode([
            ['candidateId' => 'small_safe', 'leap_magnitude' => 1.0, 'p_land' => 0.95],
            ['candidateId' => 'big_risky', 'leap_magnitude' => 2.0, 'p_land' => 0.10],
        ]));

        // risk_tolerance = 1.0 ⇒ pure EV: 1.0*0.95=0.95 beats 2.0*0.10=0.20
        $exit = Artisan::call('atlas:loop:ambition-decide', ['--input' => $this->input, '--risk-tolerance' => 1.0, '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertEqualsWithDelta(1.0, $d['risk_tolerance'], 1e-9);
        $this->assertSame('small_safe', $d['winner']['candidateId'], (string) json_encode($d));
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:ambition-decide', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
