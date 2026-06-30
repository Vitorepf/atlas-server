<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAcceptanceStrengthBacktester;
use Tests\TestCase;

final class AtlasTaskFabricAcceptanceStrengthBacktesterTest extends TestCase
{
    private function backtester(): AtlasTaskFabricAcceptanceStrengthBacktester
    {
        return new AtlasTaskFabricAcceptanceStrengthBacktester;
    }

    public function test_exit_code_only_criterion_scores_weak_with_required_improvements(): void
    {
        $result = $this->backtester()->backtest(['/opt/homebrew/bin/php artisan test --filter=FooTest exits 0']);

        $this->assertSame(AtlasTaskFabricAcceptanceStrengthBacktester::TIER_WEAK, $result['tier']);
        $this->assertContains('exit_code_only', $result['findings']);
        $this->assertNotEmpty($result['required_improvements']);
        $this->assertFalse($result['admit']);
    }

    public function test_schema_only_criterion_scores_weak_with_required_improvements(): void
    {
        $result = $this->backtester()->backtest(['Output matches schema_version atlas.foo.v1']);

        $this->assertSame(AtlasTaskFabricAcceptanceStrengthBacktester::TIER_WEAK, $result['tier']);
        $this->assertContains('schema_only', $result['findings']);
        $this->assertNotEmpty($result['required_improvements']);
    }

    public function test_strong_criteria_with_positive_negative_and_runnable_command_scores_strong(): void
    {
        $result = $this->backtester()->backtest([
            '/opt/homebrew/bin/php artisan test --filter=FooTest exits 0, produces the expected output for valid input, and rejects invalid input by throwing a validation exception',
        ]);

        $this->assertSame(AtlasTaskFabricAcceptanceStrengthBacktester::TIER_STRONG, $result['tier']);
        $this->assertGreaterThanOrEqual(80, $result['strength_score']);
        $this->assertTrue($result['admit']);
    }

    public function test_human_review_phrase_forces_human_dependency_finding(): void
    {
        $result = $this->backtester()->backtest([
            'Requires human review before this is considered done',
        ]);

        $this->assertContains('human_dependency', $result['findings']);
        $this->assertFalse($result['admit']);
    }

    public function test_operator_manual_approval_phrase_forces_human_dependency_finding(): void
    {
        $result = $this->backtester()->backtest([
            'Operator must manually verify and sign-off on the output before merge',
        ]);

        $this->assertContains('human_dependency', $result['findings']);
    }

    public function test_snapshot_only_criterion_is_flagged_weak(): void
    {
        $result = $this->backtester()->backtest([
            'Output matches the stored snapshot file exactly',
        ]);

        $this->assertContains('snapshot_only', $result['findings']);
        $this->assertSame(AtlasTaskFabricAcceptanceStrengthBacktester::TIER_WEAK, $result['tier']);
    }

    public function test_implementation_blind_criterion_is_flagged_weak(): void
    {
        $result = $this->backtester()->backtest([
            'The implementation should be complete and fully functional',
        ]);

        $this->assertContains('implementation_blind', $result['findings']);
    }

    public function test_high_risk_task_without_negative_case_is_flagged_and_not_admitted(): void
    {
        $result = $this->backtester()->backtest([
            '/opt/homebrew/bin/php artisan test --filter=FooTest exits 0 and returns the expected payload',
        ], 'high');

        $this->assertContains('high_risk_missing_negative_case', $result['findings']);
        $this->assertFalse($result['admit']);
    }

    public function test_high_risk_task_with_negative_case_is_not_flagged(): void
    {
        $result = $this->backtester()->backtest([
            '/opt/homebrew/bin/php artisan test --filter=FooTest exits 0 and returns the expected payload',
            'An unauthorized caller is rejected with a 403 and the action never executes',
        ], 'high');

        $this->assertNotContains('high_risk_missing_negative_case', $result['findings']);
    }

    public function test_output_includes_all_five_required_fields(): void
    {
        $result = $this->backtester()->backtest(['some criterion']);

        $this->assertArrayHasKey('strength_score', $result);
        $this->assertArrayHasKey('tier', $result);
        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('required_improvements', $result);
        $this->assertArrayHasKey('admit', $result);
    }

    public function test_empty_criteria_is_weak_and_not_admitted(): void
    {
        $result = $this->backtester()->backtest([]);

        $this->assertSame(AtlasTaskFabricAcceptanceStrengthBacktester::TIER_WEAK, $result['tier']);
        $this->assertFalse($result['admit']);
        $this->assertContains('no_acceptance_criteria', $result['findings']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $backtester = $this->backtester();
        $criteria = ['/opt/homebrew/bin/php artisan test --filter=FooTest exits 0 and returns the expected payload'];

        $this->assertSame($backtester->backtest($criteria, 'low'), $backtester->backtest($criteria, 'low'));
    }
}
