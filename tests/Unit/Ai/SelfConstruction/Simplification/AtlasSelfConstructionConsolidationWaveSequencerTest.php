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

    private function allProofsComplete(): array
    {
        return [
            'boundary' => true,
            'cluster' => true,
            'equivalence' => true,
            'consumer_impact' => true,
            'rewrite' => true,
            'replay' => true,
            'rollback' => true,
        ];
    }

    public function test_execution_wave_is_blocked_until_proven_when_any_proof_wave_incomplete(): void
    {
        $proofStatus = $this->allProofsComplete();
        $proofStatus['replay'] = false;

        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequencePipeline($proofStatus);

        $this->assertSame(
            AtlasSelfConstructionConsolidationWaveSequencer::STATUS_BLOCKED_UNTIL_PROVEN,
            $result['execution_status'],
        );
        $executionWave = collect($result['waves'])->firstWhere('wave', AtlasSelfConstructionConsolidationWaveSequencer::WAVE_EXECUTION);
        $this->assertContains('replay', $executionWave['blocking_proof_waves']);
    }

    public function test_execution_wave_appears_only_after_all_seven_proof_waves(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequencePipeline($this->allProofsComplete());

        $waveNames = array_column($result['waves'], 'wave');
        $executionIndex = array_search(AtlasSelfConstructionConsolidationWaveSequencer::WAVE_EXECUTION, $waveNames, true);

        foreach (['boundary', 'cluster', 'equivalence', 'consumer_impact', 'rewrite', 'replay', 'rollback'] as $proofWave) {
            $proofIndex = array_search($proofWave, $waveNames, true);
            $this->assertNotFalse($proofIndex, "missing proof wave: {$proofWave}");
            $this->assertLessThan($executionIndex, $proofIndex);
        }

        $this->assertSame(
            AtlasSelfConstructionConsolidationWaveSequencer::STATUS_READY,
            $result['execution_status'],
        );
    }

    public function test_knowledge_sync_wave_is_always_after_destructive_execution(): void
    {
        $ready = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequencePipeline($this->allProofsComplete());
        $blocked = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequencePipeline([]);

        foreach ([$ready, $blocked] as $result) {
            $waveNames = array_column($result['waves'], 'wave');
            $executionIndex = array_search(AtlasSelfConstructionConsolidationWaveSequencer::WAVE_EXECUTION, $waveNames, true);
            $syncIndex = array_search(AtlasSelfConstructionConsolidationWaveSequencer::WAVE_KNOWLEDGE_SYNC, $waveNames, true);

            $this->assertNotFalse($executionIndex);
            $this->assertNotFalse($syncIndex);
            $this->assertGreaterThan($executionIndex, $syncIndex);
        }
    }

    // ── AC: low-blast-radius, high-proof waves before runtime-dispatch waves ───

    public function test_static_edit_wave_scheduled_before_runtime_dispatch_wave(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'dispatch', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php'], 'wave_kind' => 'runtime_dispatch'],
                ['id' => 'static', 'risk' => 'high', 'proof_ready' => true, 'allowed_files' => ['b.php'], 'wave_kind' => 'static_edit'],
            ],
        ]);

        $order = array_map(fn (array $w) => $w['task_ids'][0], $result['waves']);
        $this->assertSame(['static', 'dispatch'], $order);
    }

    public function test_wave_kind_defaults_to_static_edit_when_absent(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $this->assertSame('static_edit', $result['waves'][0]['wave_kind']);
    }

    public function test_blast_radius_reflects_candidate_risk(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'high', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $this->assertSame('high', $result['waves'][0]['blast_radius']);
    }

    // ── AC: waves with missing proof are held as blocked_until_proven ──────────

    public function test_wave_status_is_blocked_until_proven_when_not_proof_ready(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'not-proven', 'risk' => 'low', 'proof_ready' => false, 'missing_proof' => 'equivalence_missing', 'allowed_files' => ['a.php']],
            ],
        ]);

        $this->assertSame(
            AtlasSelfConstructionConsolidationWaveSequencer::STATUS_BLOCKED_UNTIL_PROVEN,
            $result['waves'][0]['status'],
        );
    }

    public function test_wave_status_is_ready_when_proof_ready(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $this->assertSame(AtlasSelfConstructionConsolidationWaveSequencer::STATUS_READY, $result['waves'][0]['status']);
    }

    // ── AC: sequence output includes next_unlocks so Task Fabric can enqueue coherently ──

    public function test_next_unlocks_names_the_candidate_that_becomes_ready(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'parent', 'prerequisites' => [], 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
                ['id' => 'child', 'prerequisites' => ['parent'], 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['b.php']],
            ],
        ]);

        $byId = [];
        foreach ($result['waves'] as $wave) {
            $byId[$wave['task_ids'][0]] = $wave;
        }
        $this->assertSame(['child'], $byId['parent']['next_unlocks']);
        $this->assertSame([], $byId['child']['next_unlocks']);
    }

    public function test_next_unlocks_empty_when_no_dependents(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $this->assertSame([], $result['waves'][0]['next_unlocks']);
    }
}
