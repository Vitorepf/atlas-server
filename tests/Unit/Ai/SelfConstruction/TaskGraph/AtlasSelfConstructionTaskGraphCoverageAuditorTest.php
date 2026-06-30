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

    // ── auditGaps tests ───────────────────────────────────────────────────────

    private function organMeta(string $organId, string $lane = 'frontier', int $wave = 1, string $risk = 'high'): array
    {
        return [
            'organ_id' => $organId,
            'required_task_tags' => ['self_construction', $organId],
            'lane' => $lane,
            'dependency_wave' => $wave,
            'maturity_risk' => $risk,
        ];
    }

    public function test_audit_gaps_covered_organs_produce_no_gaps(): void
    {
        $meta = [$this->organMeta('cortex'), $this->organMeta('verification_court', 'lane-governor', 2, 'low')];
        $records = [$this->fullRecord('cortex'), $this->fullRecord('verification_court')];
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps($records, $meta);

        self::assertSame(0, $r['gap_count']);
        self::assertSame([], $r['gaps']);
        self::assertSame(AtlasSelfConstructionTaskGraphCoverageAuditor::GAPS_SCHEMA, $r['schema_version']);
    }

    public function test_audit_gaps_missing_organ_is_unimplemented(): void
    {
        $meta = [$this->organMeta('cortex')];
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([], $meta);

        self::assertSame(1, $r['gap_count']);
        self::assertSame('unimplemented', $r['gaps'][0]['reason']);
        self::assertSame('cortex', $r['gaps'][0]['organ_id']);
    }

    public function test_audit_gaps_thin_organ_is_untested(): void
    {
        $meta = [$this->organMeta('cortex')];
        $thin = $this->fullRecord('cortex');
        $thin['task_packet']['evidence_classes'] = ['implementation']; // missing gate/receipt/cli_or_readiness
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([$thin], $meta);

        self::assertSame('untested', $r['gaps'][0]['reason']);
    }

    public function test_audit_gaps_blocked_organ_has_blocked_reason(): void
    {
        $meta = [$this->organMeta('cortex')];
        $blocked = $this->fullRecord('cortex', 'blocked');
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([$blocked], $meta);

        self::assertSame('blocked', $r['gaps'][0]['reason']);
    }

    public function test_audit_gaps_stale_organ_has_stale_knowledge_reason(): void
    {
        $meta = [$this->organMeta('cortex')];
        $stale = $this->fullRecord('cortex', 'completed_dry_run');
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([$stale], $meta);

        self::assertSame('stale_knowledge', $r['gaps'][0]['reason']);
    }

    public function test_audit_gaps_groups_by_lane(): void
    {
        $meta = [
            $this->organMeta('cortex', 'frontier'),
            $this->organMeta('verification_court', 'frontier'),
            $this->organMeta('task_fabric', 'task-fabric'),
        ];
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([], $meta);

        self::assertArrayHasKey('frontier', $r['by_lane']);
        self::assertContains('cortex', $r['by_lane']['frontier']);
        self::assertContains('verification_court', $r['by_lane']['frontier']);
        self::assertArrayHasKey('task-fabric', $r['by_lane']);
    }

    public function test_audit_gaps_groups_by_dependency_wave(): void
    {
        $meta = [
            $this->organMeta('cortex', 'frontier', 1),
            $this->organMeta('verification_court', 'frontier', 2),
        ];
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([], $meta);

        self::assertArrayHasKey('1', $r['by_wave']);
        self::assertArrayHasKey('2', $r['by_wave']);
        self::assertContains('cortex', $r['by_wave']['1']);
        self::assertContains('verification_court', $r['by_wave']['2']);
    }

    public function test_audit_gaps_groups_by_maturity_risk(): void
    {
        $meta = [
            $this->organMeta('cortex', 'frontier', 1, 'high'),
            $this->organMeta('verification_court', 'frontier', 1, 'low'),
        ];
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([], $meta);

        self::assertArrayHasKey('high', $r['by_maturity_risk']);
        self::assertArrayHasKey('low', $r['by_maturity_risk']);
    }

    public function test_audit_gaps_latest_evidence_ref_extracted_from_matching_record(): void
    {
        $meta = [$this->organMeta('cortex')];
        $rec = $this->fullRecord('cortex', 'blocked');
        $rec['evidence_hash'] = 'hash-abc123';
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([$rec], $meta);

        self::assertSame('hash-abc123', $r['gaps'][0]['latest_evidence_ref']);
    }

    public function test_audit_gaps_latest_evidence_ref_null_when_no_evidence(): void
    {
        $meta = [$this->organMeta('cortex')];
        $auditor = new AtlasSelfConstructionTaskGraphCoverageAuditor;

        $r = $auditor->auditGaps([], $meta);

        self::assertNull($r['gaps'][0]['latest_evidence_ref']);
    }

    // ── auditFinalReadiness tests ───────────────────────────────────────────────

    public function test_full_live_evidence_across_organs_produces_final_ready_true(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a', 'frontier_organ_b']];
        $records = [$this->laneRecord('frontier_organ_a'), $this->laneRecord('frontier_organ_b')];

        $r = $this->laneAuditor()->auditFinalReadiness($records, $lanes);

        self::assertTrue($r['final_ready']);
        self::assertNull($r['final_ready_reason']);
        self::assertEqualsWithDelta(1.0, $r['lane_coverage']['frontier'], 0.0001);
        self::assertSame([], $r['blocked_by_lane']['frontier']);
        self::assertNull($r['next_missing_evidence_class']);
    }

    public function test_completed_dry_run_record_alone_does_not_satisfy_final_ready(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a']];
        $records = [$this->laneRecord('frontier_organ_a', 'completed_dry_run')];

        $r = $this->laneAuditor()->auditFinalReadiness($records, $lanes);

        self::assertFalse($r['final_ready']);
        self::assertNotNull($r['final_ready_reason']);
        self::assertStringContainsString('legacy', $r['final_ready_reason']);
        self::assertContains('frontier_organ_a', $r['blocked_by_lane']['frontier']);
    }

    public function test_legacy_only_metadata_record_alone_does_not_satisfy_final_ready(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a']];
        $record = $this->laneRecord('frontier_organ_a');
        $record['metadata'] = ['legacy_only' => true];

        $r = $this->laneAuditor()->auditFinalReadiness([$record], $lanes);

        self::assertFalse($r['final_ready']);
        self::assertContains('frontier_organ_a', $r['blocked_by_lane']['frontier']);
    }

    public function test_missing_evidence_classes_are_grouped_by_lane_and_expose_next_missing_evidence_class(): void
    {
        $lanes = [
            'frontier' => ['frontier_organ_a'],
            'recovery' => ['recovery_organ_a'],
        ];
        $thin = $this->laneRecord('frontier_organ_a');
        $thin['task_packet']['evidence_classes'] = ['implementation']; // missing gate/receipt/cli_or_readiness

        $r = $this->laneAuditor()->auditFinalReadiness([$thin], $lanes);

        self::assertFalse($r['final_ready']);
        self::assertContains('frontier_organ_a', $r['blocked_by_lane']['frontier']);
        self::assertContains('recovery_organ_a', $r['blocked_by_lane']['recovery']); // missing entirely too
        self::assertSame('gate', $r['next_missing_evidence_class']);
    }

    public function test_productive_vs_stale_counts_records_by_legacy_status(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a']];
        $records = [
            $this->laneRecord('frontier_organ_a'),
            $this->laneRecord('frontier_organ_a', 'completed_dry_run'),
            $this->laneRecord('frontier_organ_a', 'completed_dry_run'),
        ];

        $r = $this->laneAuditor()->auditFinalReadiness($records, $lanes);

        self::assertSame(1, $r['productive_vs_stale']['productive']);
        self::assertSame(2, $r['productive_vs_stale']['stale']);
    }

    public function test_lane_coverage_reflects_partial_coverage(): void
    {
        $lanes = ['compounding' => ['comp_a', 'comp_b']];
        $records = [$this->laneRecord('comp_a')]; // comp_b missing

        $r = $this->laneAuditor()->auditFinalReadiness($records, $lanes);

        self::assertEqualsWithDelta(0.5, $r['lane_coverage']['compounding'], 0.0001);
        self::assertContains('comp_b', $r['blocked_by_lane']['compounding']);
        self::assertFalse($r['final_ready']);
    }

    public function test_absent_lane_with_zero_organs_blocks_final_ready(): void
    {
        $lanes = ['ghost-lane' => []];

        $r = $this->laneAuditor()->auditFinalReadiness([], $lanes);

        self::assertFalse($r['final_ready']);
        self::assertEqualsWithDelta(0.0, $r['lane_coverage']['ghost-lane'], 0.0001);
        self::assertSame([], $r['blocked_by_lane']['ghost-lane']);
    }

    public function test_auditor_remains_pure_facts_only_no_score_key(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a']];
        $records = [$this->laneRecord('frontier_organ_a')];

        $r = $this->laneAuditor()->auditFinalReadiness($records, $lanes);

        self::assertArrayNotHasKey('score', $r);
        self::assertArrayNotHasKey('quality_score', $r);
    }

    public function test_audit_final_readiness_is_deterministic(): void
    {
        $lanes = ['frontier' => ['frontier_organ_a', 'frontier_organ_b']];
        $records = [$this->laneRecord('frontier_organ_a')];

        $a = $this->laneAuditor()->auditFinalReadiness($records, $lanes);
        $b = $this->laneAuditor()->auditFinalReadiness($records, $lanes);

        self::assertSame(json_encode($a), json_encode($b));
    }

    public function test_default_lanes_used_when_no_override_supplied(): void
    {
        $r = $this->laneAuditor()->auditFinalReadiness([]);

        self::assertArrayHasKey('recovery', $r['lane_coverage']);
        self::assertArrayHasKey('final-certifier', $r['lane_coverage']);
        self::assertFalse($r['final_ready']);
    }
}
