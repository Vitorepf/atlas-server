<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphCoverageAuditor;
use Tests\TestCase;

class AtlasSelfConstructionTaskGraphCoverageAuditorTest extends TestCase
{
    private function organs(): array
    {
        return [
            ['organ_id' => 'cortex', 'required_task_tags' => ['self_construction', 'cortex']],
            ['organ_id' => 'verification_court', 'required_task_tags' => ['self_construction', 'verification_court']],
        ];
    }

    private function auditor(): AtlasSelfConstructionTaskGraphCoverageAuditor
    {
        return new AtlasSelfConstructionTaskGraphCoverageAuditor(organMap: null, organsOverride: $this->organs());
    }

    private function fullRecord(string $organId, string $status = 'claimable'): array
    {
        return [
            'status' => $status,
            'task_packet' => [
                'task_packet_id' => $organId.'-pkt',
                'tags' => ['self_construction', $organId],
                'acceptance_criteria' => ['noop'],
                'required_evidence' => ['tests_or_gates_result'],
                'evidence_classes' => ['implementation', 'gate', 'receipt', 'cli_or_readiness'],
            ],
        ];
    }

    public function test_full_coverage_passes_with_no_blockers(): void
    {
        $records = [
            $this->fullRecord('cortex'),
            $this->fullRecord('verification_court'),
        ];

        $verdict = $this->auditor()->audit($records);

        self::assertTrue($verdict['passed']);
        self::assertSame('covered', $verdict['status']);
        self::assertSame([], $verdict['blockers']);
        self::assertSame('covered', $verdict['organ_coverage']['cortex']);
        self::assertSame('covered', $verdict['organ_coverage']['verification_court']);
    }

    public function test_missing_organ_when_no_records_match(): void
    {
        $verdict = $this->auditor()->audit([$this->fullRecord('cortex')]);

        self::assertFalse($verdict['passed']);
        self::assertContains('verification_court', $verdict['missing_organs']);
        self::assertContains('missing_organ:verification_court', $verdict['blockers']);
    }

    public function test_thin_organ_when_evidence_classes_incomplete(): void
    {
        $thin = $this->fullRecord('verification_court');
        $thin['task_packet']['evidence_classes'] = ['implementation']; // missing gate/receipt/cli_or_readiness

        $records = [$this->fullRecord('cortex'), $thin];

        $verdict = $this->auditor()->audit($records);

        self::assertFalse($verdict['passed']);
        self::assertSame('thin', $verdict['organ_coverage']['verification_court']);
        self::assertContains('thin_organ:verification_court', $verdict['blockers']);
        $thinRow = $verdict['thin_organs'][0];
        self::assertContains('gate', $thinRow['missing_evidence_classes']);
    }

    public function test_stale_organ_when_only_legacy_records_match(): void
    {
        $legacy = $this->fullRecord('verification_court', 'completed_dry_run');

        $records = [$this->fullRecord('cortex'), $legacy];

        $verdict = $this->auditor()->audit($records);

        self::assertSame('stale', $verdict['organ_coverage']['verification_court']);
        self::assertContains('verification_court', $verdict['stale_organs']);
        self::assertContains('stale_organ:verification_court', $verdict['blockers']);
    }

    public function test_blocked_organ_when_only_blocked_records_match(): void
    {
        $blocked = $this->fullRecord('verification_court', 'blocked');

        $records = [$this->fullRecord('cortex'), $blocked];

        $verdict = $this->auditor()->audit($records);

        self::assertSame('blocked', $verdict['organ_coverage']['verification_court']);
        self::assertContains('verification_court', $verdict['blocked_organs']);
        self::assertContains('blocked_organ:verification_court', $verdict['blockers']);
    }

    public function test_mixed_statuses_only_self_sufficient_record_drives_coverage(): void
    {
        $blocked = $this->fullRecord('verification_court', 'blocked');
        $live = $this->fullRecord('verification_court', 'claimed');

        $records = [$this->fullRecord('cortex'), $blocked, $live];

        $verdict = $this->auditor()->audit($records);

        self::assertTrue($verdict['passed']);
        self::assertSame('covered', $verdict['organ_coverage']['verification_court']);
    }

    public function test_legacy_full_plus_live_empty_yields_thin_not_covered(): void
    {
        // legacy record has full evidence_classes; live record has none
        $legacy = $this->fullRecord('verification_court', 'completed_dry_run');
        $live = $this->fullRecord('verification_court'); // status=claimable (live)
        $live['task_packet']['evidence_classes'] = [];

        $records = [$this->fullRecord('cortex'), $legacy, $live];

        $verdict = $this->auditor()->audit($records);

        self::assertSame('thin', $verdict['organ_coverage']['verification_court'],
            'legacy evidence_classes must not mask a live record with no classes');
        self::assertContains('thin_organ:verification_court', $verdict['blockers']);
    }

    public function test_inspected_count_matches_record_count_and_proof_summary_is_present(): void
    {
        $records = [
            $this->fullRecord('cortex'),
            $this->fullRecord('verification_court'),
        ];

        $verdict = $this->auditor()->audit($records);

        self::assertSame(2, $verdict['inspected_count']);
        self::assertStringContainsString('organs=2', $verdict['proof_summary']);
        self::assertArrayNotHasKey('score', $verdict);
    }

    // ─── auditByLane tests ──────────────────────────────────────────────────────

    private function laneAuditor(): AtlasSelfConstructionTaskGraphCoverageAuditor
    {
        return new AtlasSelfConstructionTaskGraphCoverageAuditor;
    }

    private function laneRecord(string $organId, string $status = 'claimable'): array
    {
        return [
            'status' => $status,
            'task_packet' => [
                'tags' => [$organId],
                'organ_id' => $organId,
                'acceptance_criteria' => ['noop'],
                'required_evidence' => ['proof'],
                'evidence_classes' => ['implementation', 'gate', 'receipt', 'cli_or_readiness'],
            ],
        ];
    }

    public function test_all_seven_final_brain_lanes_are_defined(): void
    {
        $lanes = AtlasSelfConstructionTaskGraphCoverageAuditor::FINAL_BRAIN_LANES;

        $expectedLanes = ['recovery', 'lane-governor', 'task-fabric', 'maestro-feedback', 'frontier', 'compounding', 'final-certifier'];
        foreach ($expectedLanes as $lane) {
            self::assertArrayHasKey($lane, $lanes, "lane {$lane} must be defined in FINAL_BRAIN_LANES");
            self::assertNotEmpty($lanes[$lane], "lane {$lane} must have at least one organ id");
        }
        self::assertCount(7, $lanes);
    }

    public function test_lane_with_all_organs_covered_reports_full_coverage_pct(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a', 'frontier_organ_b']];
        $records = [$this->laneRecord('frontier_organ_a'), $this->laneRecord('frontier_organ_b')];

        $result = $this->laneAuditor()->auditByLane($records, $lanes);

        self::assertTrue($result['lanes']['frontier']['passed']);
        self::assertEqualsWithDelta(1.0, $result['lanes']['frontier']['coverage_pct'], 0.0001);
        self::assertEmpty($result['lanes']['frontier']['missing']);
    }

    public function test_lane_with_missing_organ_reports_partial_coverage_pct(): void
    {
        $lanes = ['compounding' => ['comp_organ_a', 'comp_organ_b']];
        $records = [$this->laneRecord('comp_organ_a')]; // comp_organ_b missing

        $result = $this->laneAuditor()->auditByLane($records, $lanes);

        self::assertFalse($result['lanes']['compounding']['passed']);
        self::assertEqualsWithDelta(0.5, $result['lanes']['compounding']['coverage_pct'], 0.0001);
        self::assertContains('comp_organ_b', $result['lanes']['compounding']['missing']);
    }

    public function test_absent_lane_zero_organs_does_not_produce_hidden_pass(): void
    {
        $lanes = ['ghost-lane' => []];

        $result = $this->laneAuditor()->auditByLane([], $lanes);

        self::assertFalse($result['lanes']['ghost-lane']['passed']);
        self::assertTrue($result['lanes']['ghost-lane']['lane_absent']);
        self::assertEqualsWithDelta(0.0, $result['lanes']['ghost-lane']['coverage_pct'], 0.0001);
        self::assertFalse($result['passed']);
    }

    public function test_audit_by_lane_passed_false_when_any_lane_incomplete(): void
    {
        $lanes = [
            'recovery'  => ['rec_organ'],
            'frontier'  => ['front_organ'],
        ];
        $records = [$this->laneRecord('rec_organ')]; // front_organ missing

        $result = $this->laneAuditor()->auditByLane($records, $lanes);

        self::assertFalse($result['passed']);
        self::assertTrue($result['lanes']['recovery']['passed']);
        self::assertFalse($result['lanes']['frontier']['passed']);
    }

    public function test_audit_by_lane_coverage_pct_is_deterministic(): void
    {
        $lanes = ['compounding' => ['organ_x', 'organ_y', 'organ_z']];
        $records = [$this->laneRecord('organ_x'), $this->laneRecord('organ_y')]; // organ_z missing

        $a = $this->laneAuditor()->auditByLane($records, $lanes);
        $b = $this->laneAuditor()->auditByLane($records, $lanes);

        self::assertSame($a['lanes']['compounding']['coverage_pct'], $b['lanes']['compounding']['coverage_pct']);
        self::assertEqualsWithDelta(2 / 3, $a['lanes']['compounding']['coverage_pct'], 0.001);
    }
}
