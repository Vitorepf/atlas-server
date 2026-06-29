<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the implementation-priority scorer is live at the operator surface: high additive + low subtractive
 * factors score the top tier (P0, positive); low additive + high subtractive factors score a lower tier.
 */
final class AtlasLoopPriorityScoreCommandTest extends TestCase
{
    private function score(array $factors): array
    {
        $exit = Artisan::call('atlas:loop:priority-score', [
            '--factors' => (string) json_encode($factors),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_high_additive_low_subtractive_is_p0(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->score([
            'strategic_leverage' => 10, 'dependency_unlocks' => 10, 'quality_improvement' => 10,
            'autonomy_enablement' => 10, 'user_value' => 10, 'evidence_confidence' => 10,
            'risk' => 0, 'implementation_size' => 0, 'uncertainty' => 0, 'maintenance_burden' => 0,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('P0', $d['p_level'], (string) json_encode($d));
        $this->assertGreaterThan(0, $d['score']);
        $this->assertSame(60, $d['additive_total']);
        $this->assertSame(0, $d['subtractive_total']);
    }

    public function test_low_additive_high_subtractive_is_lower_tier(): void
    {
        $high = $this->score([
            'strategic_leverage' => 10, 'dependency_unlocks' => 10, 'quality_improvement' => 10,
            'autonomy_enablement' => 10, 'user_value' => 10, 'evidence_confidence' => 10,
            'risk' => 0, 'implementation_size' => 0, 'uncertainty' => 0, 'maintenance_burden' => 0,
        ])['d'];

        ['exit' => $exit, 'd' => $low] = $this->score([
            'strategic_leverage' => 1, 'dependency_unlocks' => 0, 'quality_improvement' => 0,
            'autonomy_enablement' => 0, 'user_value' => 0, 'evidence_confidence' => 0,
            'risk' => 10, 'implementation_size' => 10, 'uncertainty' => 10, 'maintenance_burden' => 10,
        ]);

        $this->assertSame(0, $exit);
        $this->assertNotSame('P0', $low['p_level']);
        $this->assertLessThan($high['score'], $low['score']);
    }

    public function test_empty_factors_option_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:priority-score', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }

    public function test_invalid_json_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:priority-score', ['--factors' => 'not json', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
