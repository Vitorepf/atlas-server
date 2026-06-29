<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the capability-promotion advisor is live at the operator surface: at level 0 it names the L0->L1 next
 * step with its required signal; at a higher level it names a correspondingly higher next_level; the ceiling
 * yields no further promotion.
 */
final class AtlasLoopPromotionAdviceCommandTest extends TestCase
{
    private function advise(int $level, string $signals = '{}'): array
    {
        $exit = Artisan::call('atlas:loop:promotion-advice', [
            '--level' => $level,
            '--signals' => $signals,
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_level_zero_advises_l0_to_l1(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->advise(0, '{}');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction.promotion_step.v1', $d['schema_version']);
        $this->assertSame(1, $d['next_level'], (string) json_encode($d));
        $this->assertSame('L0->L1', $d['next_promotion']);
        $this->assertNotEmpty($d['missing_proof']); // empty signals ⇒ gating signal not yet satisfied
        $this->assertFalse($d['is_promotable_now']);
    }

    public function test_higher_level_advises_higher_next_level(): void
    {
        $low = $this->advise(0)['d'];
        ['exit' => $exit, 'd' => $high] = $this->advise(3);

        $this->assertSame(0, $exit);
        $this->assertGreaterThan($low['next_level'], $high['next_level'], (string) json_encode($high));
        $this->assertSame(4, $high['next_level']);
    }

    public function test_promotable_now_when_gating_signal_present(): void
    {
        ['d' => $d] = $this->advise(0, (string) json_encode(['has_canonical_doc' => true]));

        $this->assertTrue($d['is_promotable_now']);
        $this->assertSame([], $d['missing_proof']);
    }

    public function test_ceiling_has_no_next_promotion(): void
    {
        ['d' => $d] = $this->advise(8);

        $this->assertTrue($d['at_ceiling']);
        $this->assertNull($d['next_level']);
    }

    public function test_invalid_signals_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:promotion-advice', ['--level' => 0, '--signals' => 'nope', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
