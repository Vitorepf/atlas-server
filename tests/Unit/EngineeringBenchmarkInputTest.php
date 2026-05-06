<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringBenchmarkInput;
use Tests\TestCase;

class EngineeringBenchmarkInputTest extends TestCase
{
    public function test_normalizes_engineering_benchmark_limits(): void
    {
        $input = new EngineeringBenchmarkInput;

        $this->assertSame(EngineeringBenchmarkInput::DEFAULT_PROMOTE_RECENT_RUNS_LIMIT, $input->promoteRecentRunsLimit(null));
        $this->assertSame(EngineeringBenchmarkInput::MAX_PROMOTE_RECENT_RUNS_LIMIT, $input->promoteRecentRunsLimit(999));
        $this->assertSame(EngineeringBenchmarkInput::MAX_TREND_LIMIT, $input->trendLimit(999));
        $this->assertSame(EngineeringBenchmarkInput::MAX_FAIR_CLAUDE_REPORT_LIMIT, $input->fairClaudeReportLimit(999));
        $this->assertSame(EngineeringBenchmarkInput::MAX_CALIBRATE_SUITE_LIMIT, $input->calibrateSuiteLimit(999));
        $this->assertSame(1, $input->calibrateSuiteLimit(-10));
        $this->assertSame(EngineeringBenchmarkInput::DEFAULT_TREND_LIMIT, $input->trendLimit('bad'));
        $this->assertSame(EngineeringBenchmarkInput::MAX_FAIR_CLAUDE_COMPARISONS, $input->fairClaudeComparisonTakeLimit(999));
        $this->assertSame(250, $input->fairClaudeScanLimit(1));
        $this->assertSame(100, $input->fairClaudeBatchSize(999));
        $this->assertSame(100, $input->minSourceScore(999));
    }
}
