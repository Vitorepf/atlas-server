<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionConsolidationWaveSequencer;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionConsolidationWaveSequencerTest extends TestCase
{
    public function test_wave_entries_carry_required_fields(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'prerequisites' => [], 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $wave = $result['waves'][0];
        $this->assertSame(['c1'], $wave['task_ids']);
        $this->assertArrayHasKey('prerequisites', $wave);
        $this->assertArrayHasKey('collision_risks', $wave);
        $this->assertArrayHasKey('proof_requirements', $wave);
        $this->assertArrayHasKey('why_now', $wave);
    }

    public function test_independent_candidates_ordered_by_risk_then_leverage(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'high-risk', 'risk' => 'high', 'proof_ready' => true, 'allowed_files' => ['x.php']],
                ['id' => 'low-risk-low-leverage', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['y.php'], 'expected_leverage' => 1],
                ['id' => 'low-risk-high-leverage', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['z.php'], 'expected_leverage' => 9],
            ],
        ]);

        $order = array_map(fn (array $w) => $w['task_ids'][0], $result['waves']);
        $this->assertSame(['low-risk-high-leverage', 'low-risk-low-leverage', 'high-risk'], $order);
    }

    public function test_dependent_candidate_sequenced_after_its_prerequisite(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'child', 'prerequisites' => ['parent'], 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['b.php']],
                ['id' => 'parent', 'prerequisites' => [], 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $order = array_map(fn (array $w) => $w['task_ids'][0], $result['waves']);
        $this->assertSame(['parent', 'child'], $order);
    }

    public function test_overlapping_allowed_files_recorded_as_collision_risk(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['shared.php']],
                ['id' => 'c2', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['shared.php']],
            ],
        ]);

        $byId = [];
        foreach ($result['waves'] as $wave) {
            $byId[$wave['task_ids'][0]] = $wave;
        }
        $this->assertSame(['c2'], $byId['c1']['collision_risks']);
        $this->assertSame(['c1'], $byId['c2']['collision_risks']);
    }

    public function test_high_risk_candidate_without_proof_placed_behind_proof_ready_candidate(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'not-proven', 'risk' => 'low', 'proof_ready' => false, 'missing_proof' => 'tests_missing', 'allowed_files' => ['a.php']],
                ['id' => 'proven', 'risk' => 'high', 'proof_ready' => true, 'allowed_files' => ['b.php']],
            ],
        ]);

        $order = array_map(fn (array $w) => $w['task_ids'][0], $result['waves']);
        $this->assertSame(['proven', 'not-proven'], $order);

        $byId = [];
        foreach ($result['waves'] as $wave) {
            $byId[$wave['task_ids'][0]] = $wave;
        }
        $this->assertSame(['tests_missing'], $byId['not-proven']['proof_requirements']);
        $this->assertSame([], $byId['proven']['proof_requirements']);
    }

    public function test_candidate_with_unmet_prerequisite_is_blocked(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'orphan', 'prerequisites' => ['ghost'], 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => []],
            ],
        ]);

        $this->assertSame([], $result['waves']);
        $this->assertSame('orphan', $result['blocked'][0]['id']);
        $this->assertSame('unmet_or_cyclic_prerequisites', $result['blocked'][0]['reason']);
    }

    public function test_no_candidates_returns_empty_result(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([]);

        $this->assertSame([], $result['waves']);
        $this->assertSame([], $result['blocked']);
        $this->assertSame('atlas.self_construction.simplification.consolidation_wave_sequencer.v1', $result['schema']);
    }
}
