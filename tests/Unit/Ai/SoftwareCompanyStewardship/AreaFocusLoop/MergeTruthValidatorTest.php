<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\MergeTruthValidator;
use PHPUnit\Framework\TestCase;

final class MergeTruthValidatorTest extends TestCase
{
    private MergeTruthValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MergeTruthValidator();
    }

    public function test_real_merge_when_main_advanced(): void
    {
        $r = $this->validator->validate([
            'main_before' => 'aaaa', 'main_after' => 'bbbb',
            'target_ref_before' => 'aaaa', 'target_ref_after' => 'bbbb',
            'merge_target' => MergeTruthValidator::TARGET_MAIN,
            'merge_performed_to_base' => true,
        ]);

        $this->assertTrue($r['merge_real']);
        $this->assertTrue($r['main_advanced']);
        $this->assertSame([], $r['violations']);
    }

    public function test_already_up_to_date_is_not_a_real_merge(): void
    {
        $r = $this->validator->validate([
            'main_before' => 'aaaa', 'main_after' => 'aaaa',
            'target_ref_before' => 'aaaa', 'target_ref_after' => 'aaaa',
            'merge_target' => MergeTruthValidator::TARGET_MAIN,
            'merge_performed_to_base' => false,
        ]);

        $this->assertFalse($r['merge_real']);
        $this->assertFalse($r['main_advanced']);
        $this->assertContains('noop_or_already_up_to_date', $r['violations']);
    }

    public function test_claimed_base_merge_but_main_unchanged_is_violation_and_not_real(): void
    {
        // The false-merge case: governor claims merge_performed_to_base but main
        // did not move => not real + an explicit violation.
        $r = $this->validator->validate([
            'main_before' => 'aaaa', 'main_after' => 'aaaa',
            'target_ref_before' => 'aaaa', 'target_ref_after' => 'cccc',
            'merge_target' => MergeTruthValidator::TARGET_MAIN,
            'merge_performed_to_base' => true,
        ]);

        $this->assertFalse($r['merge_real']);
        $this->assertContains('merge_performed_to_base_claimed_but_main_not_advanced', $r['violations']);
    }

    public function test_lane_only_advance_is_not_a_real_merge(): void
    {
        // The lane moved but main did not — must never count as a real merge.
        $r = $this->validator->validate([
            'main_before' => 'aaaa', 'main_after' => 'aaaa',
            'target_ref_before' => 'aaaa', 'target_ref_after' => 'dddd',
            'merge_target' => MergeTruthValidator::TARGET_LANE,
            'merge_performed_to_base' => false,
        ]);

        $this->assertFalse($r['merge_real']);
        $this->assertTrue($r['target_advanced']);
        $this->assertFalse($r['main_advanced']);
        $this->assertContains('lane_only_advance_not_counted_as_real_merge', $r['violations']);
    }
}
