<?php

namespace Tests\Unit\Ai\Cognitive\PersonalWorkedExample;

use App\Services\Ai\Cognitive\PersonalWorkedExample\PersonalWorkedExampleQualityFilter;
use Tests\TestCase;

class PersonalWorkedExampleQualityFilterTest extends TestCase
{
    public function test_programming_pr_requires_tests_no_regression_and_explanation(): void
    {
        $filter = app(PersonalWorkedExampleQualityFilter::class);

        $blocked = $filter->evaluate([
            'source_type' => 'programming_pr',
            'domain' => 'programming',
            'quality_signals' => [
                'tests_passed' => true,
                'no_regression_30d' => true,
            ],
        ]);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('quality_pr_no_explanation', $blocked['reason']);

        $passed = $filter->evaluate([
            'source_type' => 'programming_pr',
            'domain' => 'programming',
            'quality_signals' => [
                'tests_passed' => true,
                'no_regression_30d' => true,
                'commit_explanation' => 'Explains the reproducible engineering pattern.',
            ],
        ]);

        $this->assertSame('passed', $passed['status']);
    }
}
