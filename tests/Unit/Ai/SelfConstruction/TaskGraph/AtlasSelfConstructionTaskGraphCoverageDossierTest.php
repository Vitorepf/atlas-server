<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphCoverageDossier;
use Tests\TestCase;

class AtlasSelfConstructionTaskGraphCoverageDossierTest extends TestCase
{
    public function test_ready_dossier_when_coverage_passes_and_planner_empty(): void
    {
        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($this->readyFacts());

        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_READY, $dossier['status']);
        self::assertSame([], $dossier['blockers']);
        self::assertSame(2, $dossier['organ_summary']['total']);
        self::assertSame(2, $dossier['organ_summary']['covered_count']);
        self::assertArrayNotHasKey('score', $dossier);
    }

    public function test_hold_dossier_when_only_refresh_gaps_with_drafts(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['verification_court'];
        $facts['coverage']['organ_coverage'] = ['cortex' => 'covered', 'verification_court' => 'missing'];
        $facts['planner']['drafts'] = [['task_packet_id' => 'coverage-verification_court-missing-v1', 'objective' => 'fill', 'allowed_files' => ['x', 'y'], 'wave' => 'w']];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_HOLD, $dossier['status']);
        self::assertSame([], $dossier['blockers']);
        self::assertSame(1, $dossier['draft_summary']['draft_count']);
        self::assertContains('verification_court', $dossier['missing_organs']);
    }

    public function test_blocked_dossier_when_coverage_has_blocked_organs(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['blocked_organs'] = ['verification_court'];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('coverage_blocked_organ:verification_court', $dossier['blockers']);
    }

    public function test_withheld_gap_details_block_dossier(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['unsafe_no_targets'];
        $facts['planner']['withheld_gaps'] = [['organ_id' => 'unsafe_no_targets', 'reason' => 'safe_targets_unavailable']];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('planner_withheld_gap:unsafe_no_targets', $dossier['blockers']);
        self::assertSame(1, $dossier['draft_summary']['withheld_count']);
        self::assertSame('safe_targets_unavailable', $dossier['draft_summary']['withheld_gaps'][0]['reason']);
    }

    public function test_dossier_id_is_deterministic_for_identical_facts(): void
    {
        $exporter = new AtlasSelfConstructionTaskGraphCoverageDossier();
        $a = $exporter->export($this->readyFacts());
        $b = $exporter->export($this->readyFacts());

        self::assertSame($a['dossier_id'], $b['dossier_id']);
        self::assertStringStartsWith('atlas-coverage-dossier_', $a['dossier_id']);
    }

    public function test_dossier_id_differs_when_status_changes(): void
    {
        $exporter = new AtlasSelfConstructionTaskGraphCoverageDossier();
        $a = $exporter->export($this->readyFacts());
        $b = $exporter->export(array_replace_recursive($this->readyFacts(), ['coverage' => ['passed' => false, 'blocked_organs' => ['x']]]));

        self::assertNotSame($a['dossier_id'], $b['dossier_id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyFacts(): array
    {
        return [
            'coverage' => [
                'passed' => true,
                'organ_coverage' => ['cortex' => 'covered', 'verification_court' => 'covered'],
                'missing_organs' => [],
                'thin_organs' => [],
                'stale_organs' => [],
                'blocked_organs' => [],
            ],
            'planner' => [
                'drafts' => [],
                'withheld_gaps' => [],
            ],
            'organ_map' => [
                'schema_version' => 'atlas.self_construction.final_organ_map.v1',
            ],
        ];
    }

    // ---------- thin / stale organ coverage ----------

    public function test_thin_organs_reflected_in_organ_summary(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['thin_organs'] = ['maestro'];
        $facts['coverage']['organ_coverage']['maestro'] = 'thin';

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_HOLD, $dossier['status']);
        self::assertSame(1, $dossier['organ_summary']['thin_count']);
        self::assertContains('maestro', $dossier['thin_organs']);
    }

    public function test_stale_organs_reflected_in_organ_summary(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['stale_organs'] = ['task_fabric'];
        $facts['coverage']['organ_coverage']['task_fabric'] = 'stale';

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_HOLD, $dossier['status']);
        self::assertSame(1, $dossier['organ_summary']['stale_count']);
        self::assertContains('task_fabric', $dossier['stale_organs']);
    }

    // ---------- rankedNextGaps ----------

    public function test_ranked_next_gaps_empty_for_ready_dossier(): void
    {
        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($this->readyFacts());
        $gaps = (new AtlasSelfConstructionTaskGraphCoverageDossier)->rankedNextGaps($dossier);

        self::assertSame([], $gaps);
    }

    public function test_ranked_next_gaps_priority_order_blocked_then_missing_then_thin_then_stale(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['blocked_organs'] = ['merge_governor'];
        $facts['coverage']['missing_organs'] = ['worker_swarm'];
        $facts['coverage']['thin_organs']    = ['maestro'];
        $facts['coverage']['stale_organs']   = ['task_fabric'];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);
        $gaps    = (new AtlasSelfConstructionTaskGraphCoverageDossier)->rankedNextGaps($dossier);

        self::assertCount(4, $gaps);
        self::assertSame('blocked',            $gaps[0]['gap_kind']);
        self::assertSame('merge_governor',     $gaps[0]['organ_id']);
        self::assertSame('missing_implementation', $gaps[1]['gap_kind']);
        self::assertSame('worker_swarm',       $gaps[1]['organ_id']);
        self::assertSame('missing_tests',      $gaps[2]['gap_kind']);
        self::assertSame('maestro',            $gaps[2]['organ_id']);
        self::assertSame('stale_evidence',     $gaps[3]['gap_kind']);
        self::assertSame('task_fabric',        $gaps[3]['organ_id']);
        // priority_rank is monotonically increasing
        self::assertSame(1, $gaps[0]['priority_rank']);
        self::assertSame(2, $gaps[1]['priority_rank']);
    }

    public function test_draft_recommendation_shape_has_exactly_required_fields(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['verification_court'];
        $facts['coverage']['organ_coverage']['verification_court'] = 'missing';
        $facts['planner']['drafts'] = [[
            'task_packet_id' => 'coverage-verification_court-v1',
            'objective'      => 'Implement verification_court organ.',
            'allowed_files'  => ['app/Services/Ai/SelfConstruction/VerificationCourt.php', 'tests/Unit/Ai/SelfConstruction/VerificationCourtTest.php'],
            'wave'           => 'wave-3',
        ]];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);
        $draft = $dossier['draft_summary']['drafts'][0];

        // Exactly these 4 keys — no extras, no decision-plane fields.
        self::assertSame(['task_packet_id', 'objective', 'allowed_files', 'wave'], array_keys($draft));
        self::assertSame('coverage-verification_court-v1', $draft['task_packet_id']);
        self::assertNotEmpty($draft['objective']);
        self::assertIsArray($draft['allowed_files']);
        self::assertCount(2, $draft['allowed_files']);
        self::assertSame('wave-3', $draft['wave']);
    }

    public function test_ranked_next_gaps_multiple_missing_organs_ordered_by_insertion(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['verification_court', 'knowledge_sync', 'rollback'];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);
        $gaps    = (new AtlasSelfConstructionTaskGraphCoverageDossier)->rankedNextGaps($dossier);

        self::assertCount(3, $gaps);
        self::assertSame(['verification_court', 'knowledge_sync', 'rollback'], array_column($gaps, 'organ_id'));
        foreach ($gaps as $g) {
            self::assertSame('missing_implementation', $g['gap_kind']);
        }
    }

    // ---------- final_95_gap_report ----------

    public function test_output_has_final_95_gap_report_key(): void
    {
        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($this->readyFacts());

        self::assertArrayHasKey('final_95_gap_report', $dossier);
        self::assertIsArray($dossier['final_95_gap_report']);
    }

    public function test_final_95_gap_report_empty_when_ready(): void
    {
        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($this->readyFacts());

        self::assertSame([], $dossier['final_95_gap_report']);
    }

    public function test_final_95_gap_report_blocked_ranks_first(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['blocked_organs'] = ['merge_governor'];
        $facts['coverage']['missing_organs'] = ['worker_swarm'];
        $facts['coverage']['thin_organs']    = ['maestro'];
        $facts['coverage']['stale_organs']   = ['task_fabric'];

        $report = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts)['final_95_gap_report'];

        self::assertCount(4, $report);
        self::assertSame('blocked',                $report[0]['gap_kind']);
        self::assertSame('merge_governor',         $report[0]['organ_id']);
        self::assertSame('missing_implementation', $report[1]['gap_kind']);
        self::assertSame('missing_tests',          $report[2]['gap_kind']);
        self::assertSame('stale_evidence',         $report[3]['gap_kind']);
    }

    public function test_final_95_gap_report_row_has_all_required_fields(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['verification_court'];

        $report = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts)['final_95_gap_report'];

        self::assertCount(1, $report);
        foreach (['organ_id', 'gap_kind', 'proof_required', 'next_task_family'] as $key) {
            self::assertArrayHasKey($key, $report[0]);
        }
        self::assertSame('verification_court',                      $report[0]['organ_id']);
        self::assertSame('missing_implementation',                  $report[0]['gap_kind']);
        self::assertSame('implementation_present_and_test_green',   $report[0]['proof_required']);
        self::assertSame('coverage_implementation',                  $report[0]['next_task_family']);
    }

    public function test_final_95_gap_report_proof_required_per_kind(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['blocked_organs'] = ['b'];
        $facts['coverage']['missing_organs'] = ['m'];
        $facts['coverage']['thin_organs']    = ['t'];
        $facts['coverage']['stale_organs']   = ['s'];

        $report = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts)['final_95_gap_report'];
        $byKind = array_column($report, null, 'gap_kind');

        self::assertSame('unblock_receipt_and_test_green',              $byKind['blocked']['proof_required']);
        self::assertSame('implementation_present_and_test_green',       $byKind['missing_implementation']['proof_required']);
        self::assertSame('test_suite_minimum_3_assertions_and_green',   $byKind['missing_tests']['proof_required']);
        self::assertSame('fresh_evidence_and_index_updated',            $byKind['stale_evidence']['proof_required']);

        self::assertSame('coverage_unblock',         $byKind['blocked']['next_task_family']);
        self::assertSame('coverage_implementation',  $byKind['missing_implementation']['next_task_family']);
        self::assertSame('coverage_test_authoring',  $byKind['missing_tests']['next_task_family']);
        self::assertSame('coverage_evidence_refresh', $byKind['stale_evidence']['next_task_family']);
    }

    // ---------- AC2 + AC4: proxy-covered organs (opt-in via organ_evidence) ----------

    public function test_covered_organ_without_evidence_is_demoted_to_proxy_covered_when_evidence_map_supplied(): void
    {
        $facts = $this->readyFacts();
        $facts['organ_evidence'] = [
            'cortex' => ['has_task_evidence' => true],
            // 'verification_court' deliberately has no evidence entry at all.
        ];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertContains('verification_court', $dossier['proxy_covered_organs']);
        self::assertNotContains('cortex', $dossier['proxy_covered_organs']);
        self::assertSame(1, $dossier['organ_summary']['covered_count']);
        self::assertSame(1, $dossier['organ_summary']['proxy_covered_count']);
        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_BLOCKED, $dossier['status']);
        self::assertContains('proxy_covered_organ:verification_court', $dossier['blockers']);
    }

    public function test_covered_organ_counts_normally_when_organ_evidence_is_not_supplied(): void
    {
        // Backward compatibility: omitting organ_evidence entirely must reproduce the pre-existing
        // behavior exactly -- every 'covered' organ counts, nothing is demoted to proxy.
        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($this->readyFacts());

        self::assertSame([], $dossier['proxy_covered_organs']);
        self::assertSame(2, $dossier['organ_summary']['covered_count']);
        self::assertSame(0, $dossier['organ_summary']['proxy_covered_count']);
        self::assertSame(AtlasSelfConstructionTaskGraphCoverageDossier::STATUS_READY, $dossier['status']);
    }

    public function test_covered_organ_with_test_or_runtime_evidence_alone_still_counts(): void
    {
        $facts = $this->readyFacts();
        $facts['organ_evidence'] = [
            'cortex' => ['has_test_evidence' => true],
            'verification_court' => ['has_runtime_evidence' => true],
        ];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);

        self::assertSame([], $dossier['proxy_covered_organs']);
        self::assertSame(2, $dossier['organ_summary']['covered_count']);
    }

    // ---------- AC3: ranking by autonomy impact, downstream unlocks, proof weakness, implementation risk ----------

    public function test_ranked_next_gaps_reorders_by_composite_impact_when_organ_impact_supplied(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['low-impact-organ'];
        $facts['coverage']['stale_organs'] = ['high-impact-organ'];
        $facts['organ_impact'] = [
            'low-impact-organ' => ['autonomy_impact' => 5, 'downstream_unlocks' => 0, 'proof_weakness' => 0, 'implementation_risk' => 0],
            'high-impact-organ' => ['autonomy_impact' => 90, 'downstream_unlocks' => 80, 'proof_weakness' => 70, 'implementation_risk' => 60],
        ];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);
        $gaps = (new AtlasSelfConstructionTaskGraphCoverageDossier)->rankedNextGaps($dossier);

        // Despite stale_evidence normally ranking below missing_implementation, the
        // overwhelmingly higher composite impact must promote high-impact-organ to rank 1.
        self::assertSame('high-impact-organ', $gaps[0]['organ_id']);
        self::assertSame(1, $gaps[0]['priority_rank']);
        self::assertSame(300, $gaps[0]['composite_impact']);
        self::assertSame('low-impact-organ', $gaps[1]['organ_id']);
        self::assertSame(5, $gaps[1]['composite_impact']);
    }

    public function test_ranked_next_gaps_preserves_kind_priority_when_organ_impact_absent(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['blocked_organs'] = ['merge_governor'];
        $facts['coverage']['missing_organs'] = ['worker_swarm'];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);
        $gaps = (new AtlasSelfConstructionTaskGraphCoverageDossier)->rankedNextGaps($dossier);

        self::assertSame('blocked', $gaps[0]['gap_kind']);
        self::assertSame('missing_implementation', $gaps[1]['gap_kind']);
        self::assertSame(0, $gaps[0]['composite_impact']);
    }

    public function test_ranked_next_gap_row_carries_all_four_impact_dimensions(): void
    {
        $facts = $this->readyFacts();
        $facts['coverage']['passed'] = false;
        $facts['coverage']['missing_organs'] = ['x'];
        $facts['organ_impact'] = ['x' => ['autonomy_impact' => 10, 'downstream_unlocks' => 20, 'proof_weakness' => 30, 'implementation_risk' => 40]];

        $dossier = (new AtlasSelfConstructionTaskGraphCoverageDossier)->export($facts);
        $gap = (new AtlasSelfConstructionTaskGraphCoverageDossier)->rankedNextGaps($dossier)[0];

        self::assertSame(10, $gap['autonomy_impact']);
        self::assertSame(20, $gap['downstream_unlocks']);
        self::assertSame(30, $gap['proof_weakness']);
        self::assertSame(40, $gap['implementation_risk']);
        self::assertSame(100, $gap['composite_impact']);
        self::assertArrayNotHasKey('score', $gap);
    }
}
