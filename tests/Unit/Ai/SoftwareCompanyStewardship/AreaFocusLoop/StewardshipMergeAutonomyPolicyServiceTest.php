<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipMergeAutonomyPolicyService;
use Tests\TestCase;

final class StewardshipMergeAutonomyPolicyServiceTest extends TestCase
{
    public function test_allows_small_docs_or_tests_without_operator_code_flag(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'docs_and_tests'],
            ['passed' => null],
            ['docs/a.md', 'tests/FooTest.php'],
            1,
            [],
            [],
        );

        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['eligible']);
        $this->assertSame('p3_low_risk_docs_tests', $policy['risk_class']);
        $this->assertSame('ff_only', $policy['merge_mode']);
        $this->assertSame([], $policy['reasons']);
    }

    public function test_allows_bugfix_only_with_operator_flag_and_green_validation(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'bugfix', 'code_or_other_file_count' => 1],
            ['passed' => true],
            ['app/Foo.php'],
            1,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible']);
        $this->assertTrue($policy['code_auto_merge_authorized']);
        $this->assertSame('p2_code_review_boundary', $policy['risk_class']);
        $this->assertSame('Fast-forward only. If accepted and later reverted, use a normal revert commit on the base branch; never reset, rebase, force-push or silently discard branch history.', $policy['rollback_plan']);
    }

    public function test_allows_focused_test_finding_with_minimal_code_fix_when_authorized_and_validated(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'test', 'code_or_other_file_count' => 1],
            ['passed' => true],
            ['app/Foo.php', 'tests/Unit/FooTest.php'],
            1,
            [],
            ['allow_code_auto_merge' => true],
        );

        $this->assertTrue($policy['eligible']);
        $this->assertSame('auto_merge_allowed', $policy['status']);
        $this->assertTrue($policy['code_auto_merge_authorized']);
        $this->assertSame('p2_code_review_boundary', $policy['risk_class']);
        $this->assertTrue($policy['operator_controls']['allow_code_auto_merge_flag_required']);
        $this->assertTrue($policy['operator_controls']['validation_green_required_for_code']);
    }

    public function test_blocks_code_without_operator_flag_or_validation(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'bugfix', 'code_or_other_file_count' => 1],
            ['passed' => null],
            ['app/Foo.php'],
            1,
            [],
            [],
        );

        $this->assertFalse($policy['eligible']);
        $this->assertContains('change_class_requires_operator_review', $policy['reasons']);
        $this->assertSame('operator_review_required', $policy['status']);
        $this->assertTrue($policy['operator_controls']['allow_code_auto_merge_flag_required']);
    }

    public function test_blocks_conflict_or_large_branch_even_when_otherwise_safe(): void
    {
        $policy = app(StewardshipMergeAutonomyPolicyService::class)->decide(
            ['kind' => 'documentation_only'],
            ['passed' => null],
            ['docs/a.md'],
            1,
            ['merge_conflict_detected'],
            [],
        );

        $this->assertFalse($policy['eligible']);
        $this->assertSame('p0_blocked', $policy['risk_class']);
        $this->assertContains('branch_blockers_present', $policy['reasons']);
        $this->assertContains('risk_class_blocks_auto_merge', $policy['reasons']);
    }
}
