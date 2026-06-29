<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryConfidenceModel;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the delivery-confidence model is live at the operator surface: a behaviour-broken cert is HARD-zero,
 * a fully-green cert clears the default 0.93 gate, and a half-evidenced cert does not.
 */
final class AtlasLoopDeliveryConfidenceCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-delivery-conf-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function estimate(array $signals): array
    {
        file_put_contents($this->input, (string) json_encode($signals));
        $exit = Artisan::call('atlas:loop:delivery-confidence', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_behavior_not_preserved_is_hard_zero(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->estimate([
            'behavior_preserved' => false,
            'diff_earned' => true,
            'sealed_holdout_passed' => true,
            'mutation_kill_ratio' => 1.0,
            'quality_score' => 10,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.delivery_confidence.v1', $d['schema']);
        $this->assertEqualsWithDelta(0.0, $d['confidence'], 1e-9);
        $this->assertFalse($d['passes']);
        $this->assertContains('behavior_not_preserved', $d['reasons']);
    }

    public function test_fully_green_cert_clears_the_gate(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->estimate([
            'behavior_preserved' => true,
            'diff_earned' => true,
            'sealed_holdout_passed' => true,
            'complexity_reduced' => true,
            'cross_file_consumers_ok' => true,
            'mutation_kill_ratio' => 1.0,
            'quality_score' => 10,
            'adversarial_refuted_count' => 0,
        ]);

        $this->assertSame(0, $exit);
        $this->assertGreaterThanOrEqual(AtlasLoopDeliveryConfidenceModel::DEFAULT_THRESHOLD, $d['confidence']);
        $this->assertTrue($d['passes'], (string) json_encode($d));
    }

    public function test_half_evidenced_cert_fails_the_gate(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->estimate([
            'behavior_preserved' => true,
            'diff_earned' => true,
            // everything else absent / zero
        ]);

        $this->assertSame(0, $exit);
        $this->assertLessThan(AtlasLoopDeliveryConfidenceModel::DEFAULT_THRESHOLD, $d['confidence']);
        $this->assertFalse($d['passes']);
        $this->assertNotEmpty($d['reasons']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:delivery-confidence', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
