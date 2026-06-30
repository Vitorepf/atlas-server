<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneCertificationCoverageReportService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneCertificationCoverageReportServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): AgentControlPlaneCertificationCoverageReportService
    {
        return app(AgentControlPlaneCertificationCoverageReportService::class);
    }

    public function test_report_includes_critical_gap_summary_with_required_keys(): void
    {
        $result = $this->service()->report();

        $this->assertArrayHasKey('critical_gap_summary', $result);
        $summary = $result['critical_gap_summary'];

        foreach (['lowest_coverage_blocks', 'missing_status_flags', 'skipped_inputs', 'recommended_next_focus'] as $key) {
            $this->assertArrayHasKey($key, $summary, "critical_gap_summary missing key: {$key}");
        }
    }

    public function test_lowest_coverage_blocks_are_sorted_ascending_by_value(): void
    {
        $result = $this->service()->report();
        $blocks = $result['critical_gap_summary']['lowest_coverage_blocks'];

        $values = array_column($blocks, 'value');
        $sorted = $values;
        sort($sorted, SORT_NUMERIC);

        $this->assertSame($sorted, $values);
    }

    public function test_recommended_next_focus_matches_first_lowest_block_when_gaps_exist(): void
    {
        $result = $this->service()->report();
        $summary = $result['critical_gap_summary'];

        if ($summary['lowest_coverage_blocks'] !== []) {
            $this->assertSame($summary['lowest_coverage_blocks'][0]['block'], $summary['recommended_next_focus']);
        } else {
            $this->assertSame('none_all_blocks_at_full_coverage', $summary['recommended_next_focus']);
        }
    }

    public function test_skip_simulator_marks_skipped_inputs_explicitly_not_complete(): void
    {
        $result = $this->service()->report(['skip_simulator' => true]);
        $summary = $result['critical_gap_summary'];

        $this->assertContains('simulator', $summary['skipped_inputs']);
        $this->assertTrue($summary['blocked_by_skipped_inputs']);
    }

    public function test_skip_fuzz_marks_skipped_inputs_explicitly_not_complete(): void
    {
        $result = $this->service()->report(['skip_fuzz' => true]);
        $summary = $result['critical_gap_summary'];

        $this->assertContains('fuzz', $summary['skipped_inputs']);
        $this->assertTrue($summary['blocked_by_skipped_inputs']);
    }

    public function test_no_skips_yields_empty_skipped_inputs_and_not_blocked(): void
    {
        $result = $this->service()->report();
        $summary = $result['critical_gap_summary'];

        $this->assertSame([], $summary['skipped_inputs']);
        $this->assertFalse($summary['blocked_by_skipped_inputs']);
    }

    public function test_missing_status_flags_is_a_list_of_flag_strings(): void
    {
        $result = $this->service()->report();
        $flags = $result['critical_gap_summary']['missing_status_flags'];

        $this->assertIsArray($flags);
        foreach ($flags as $flag) {
            $this->assertIsString($flag);
            $this->assertStringStartsWith('--', $flag);
        }
    }

    public function test_report_is_read_only_and_never_executes(): void
    {
        $result = $this->service()->report();

        $this->assertTrue($result['read_only']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['external_provider_call']);
    }
}
