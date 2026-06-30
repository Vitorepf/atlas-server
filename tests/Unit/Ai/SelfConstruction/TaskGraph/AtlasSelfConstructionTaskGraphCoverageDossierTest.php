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
}
