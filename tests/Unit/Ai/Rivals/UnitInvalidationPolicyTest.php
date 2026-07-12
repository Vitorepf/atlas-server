<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\UnitInvalidationPolicy;
use Tests\TestCase;

class UnitInvalidationPolicyTest extends TestCase
{
    private function rules(): array
    {
        return [
            'frozen_before_unblinding' => true,
            'allowed_reasons' => [
                'contamination_canary_hit',
                'memorized_public_unit',
                'resource_pinning_mismatch',
            ],
        ];
    }

    public function test_preregistered_reason_before_unblinding_invalidates_but_keeps_itt(): void
    {
        $out = (new UnitInvalidationPolicy)->evaluate($this->rules(), [
            'unit_id' => 'u1',
            'reason' => 'memorized_public_unit',
            'observed_after_unblinding' => false,
        ]);

        $this->assertTrue($out['invalidated']);
        $this->assertSame('memorized_public_unit', $out['reason']);
        $this->assertTrue($out['retained_in_itt']);
    }

    public function test_post_unblinding_invalidation_is_forbidden(): void
    {
        $out = (new UnitInvalidationPolicy)->evaluate($this->rules(), [
            'unit_id' => 'u2',
            'reason' => 'memorized_public_unit',
            'observed_after_unblinding' => true,
        ]);

        $this->assertFalse($out['invalidated']);
        $this->assertSame('post_unblinding_invalidation_forbidden', $out['rejected_reason']);
    }

    public function test_reason_outside_preregistration_is_rejected(): void
    {
        $out = (new UnitInvalidationPolicy)->evaluate($this->rules(), [
            'unit_id' => 'u3',
            'reason' => 'i_did_not_like_the_result',
            'observed_after_unblinding' => false,
        ]);

        $this->assertFalse($out['invalidated']);
        $this->assertStringStartsWith('reason_not_preregistered:', (string) $out['rejected_reason']);
    }

    public function test_unfrozen_rules_cannot_invalidate(): void
    {
        $rules = $this->rules();
        $rules['frozen_before_unblinding'] = false;
        $out = (new UnitInvalidationPolicy)->evaluate($rules, [
            'unit_id' => 'u4',
            'reason' => 'contamination_canary_hit',
        ]);

        $this->assertFalse($out['invalidated']);
        $this->assertSame('invalidation_rules_not_frozen', $out['rejected_reason']);
    }

    public function test_rotation_selects_fresh_unused_and_retires_stale_and_used(): void
    {
        $corpus = [
            ['case_id' => 'fresh_a', 'mined_at' => now()->subDays(2)->toIso8601String()],
            ['case_id' => 'fresh_b', 'mined_at' => now()->subDays(3)->toIso8601String()],
            ['case_id' => 'used_c', 'mined_at' => now()->subDays(1)->toIso8601String()],
            ['case_id' => 'stale_d', 'mined_at' => now()->subDays(90)->toIso8601String()],
        ];

        $out = (new UnitInvalidationPolicy)->rotate($corpus, ['used_c'], 2, 30);

        $this->assertSame(['fresh_a', 'fresh_b'], $out['selected']);
        $this->assertContains('used:used_c', $out['retired']);
        $this->assertContains('stale:stale_d', $out['retired']);
        $this->assertTrue($out['sufficient']);
    }
}
