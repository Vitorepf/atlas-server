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
}
