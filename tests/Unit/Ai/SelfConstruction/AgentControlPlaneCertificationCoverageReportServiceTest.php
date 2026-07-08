<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationCoverageReportService;
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

    // ── AC: surfaces, blocking_gaps, next_certification_task ─────────────────

    public function test_report_includes_surfaces_grouped_by_eight_runtime_surfaces(): void
    {
        $result = $this->service()->report();

        $this->assertArrayHasKey('surfaces', $result);
        $surfaces = $result['surfaces'];
        $this->assertCount(8, $surfaces);

        $names = array_column($surfaces, 'name');
        $this->assertContains('runtime', $names);
        $this->assertContains('queue', $names);
        $this->assertContains('scope', $names);
        $this->assertContains('evidence', $names);
        $this->assertContains('workspace', $names);
        $this->assertContains('worker', $names);
        $this->assertContains('release', $names);
        $this->assertContains('rollback', $names);
    }

    public function test_each_surface_has_covered_uncovered_stale_blocked_and_blocked_reasons(): void
    {
        $result = $this->service()->report();

        foreach ($result['surfaces'] as $surface) {
            $this->assertIsArray($surface['covered']);
            $this->assertIsArray($surface['uncovered']);
            $this->assertIsArray($surface['stale']);
            $this->assertIsBool($surface['blocked']);
            $this->assertIsArray($surface['blocked_reasons']);
        }
    }

    public function test_report_includes_blocking_gaps_array(): void
    {
        $result = $this->service()->report();

        $this->assertArrayHasKey('blocking_gaps', $result);
        $this->assertIsArray($result['blocking_gaps']);
    }

    public function test_report_includes_next_certification_task_string(): void
    {
        $result = $this->service()->report();

        $this->assertArrayHasKey('next_certification_task', $result);
        $this->assertIsString($result['next_certification_task']);
    }

    public function test_next_certification_task_resolves_blocking_gap_when_gaps_exist(): void
    {
        $result = $this->service()->report();

        if ($result['blocking_gaps'] !== []) {
            $firstGapSurface = $result['blocking_gaps'][0]['surface'];
            $this->assertSame(
                'resolve_blocking_gap:'.$firstGapSurface,
                $result['next_certification_task'],
            );
        } else {
            $this->assertSame('no_blocking_gaps', $result['next_certification_task']);
        }
    }

    public function test_blocking_gaps_surface_name_matches_a_known_surface(): void
    {
        $result = $this->service()->report();
        $surfaceNames = array_column($result['surfaces'], 'name');

        foreach ($result['blocking_gaps'] as $gap) {
            $this->assertContains($gap['surface'], $surfaceNames);
            $this->assertNotEmpty($gap['reasons']);
        }
    }

    public function test_report_has_no_scalar_only_readiness_claim(): void
    {
        $result = $this->service()->report();

        // The report must not rely on a single scalar score alone — it must
        // also include structured surfaces and blocking_gaps for audit.
        $this->assertArrayHasKey('surfaces', $result);
        $this->assertArrayHasKey('blocking_gaps', $result);
        $this->assertArrayHasKey('coverage_score', $result);
        $this->assertArrayHasKey('coverage_grade', $result);
    }

    public function test_evidence_surface_marks_missing_proof_as_stale_and_blocking(): void
    {
        $result = $this->service()->report();
        $evidenceSurface = null;
        foreach ($result['surfaces'] as $surface) {
            if ($surface['name'] === 'evidence') {
                $evidenceSurface = $surface;
            }
        }
        $this->assertNotNull($evidenceSurface);

        // If proof_bundle is not at full coverage, the evidence surface must
        // surface stale entries and be blocked.
        if ($evidenceSurface['coverage'] < 1.0) {
            $this->assertTrue($evidenceSurface['blocked']);
            $this->assertNotEmpty($evidenceSurface['stale']);
        }
    }

    public function test_rollback_surface_surfaces_missing_proof_when_absent(): void
    {
        $result = $this->service()->report();
        $rollbackSurface = null;
        foreach ($result['surfaces'] as $surface) {
            if ($surface['name'] === 'rollback') {
                $rollbackSurface = $surface;
            }
        }
        $this->assertNotNull($rollbackSurface);

        // If rollback proof is missing, the surface must be blocked with
        // missing_rollback_proof reason.
        if (in_array('rollback_proof', $rollbackSurface['uncovered'])) {
            $this->assertTrue($rollbackSurface['blocked']);
            $this->assertContains('missing_rollback_proof', $rollbackSurface['blocked_reasons']);
        }
    }
}
