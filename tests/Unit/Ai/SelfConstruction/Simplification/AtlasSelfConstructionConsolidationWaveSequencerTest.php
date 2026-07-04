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
                ['id' => 'low-risk-low-leverage', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['y.php']],
                ['id' => 'low-risk-high-leverage', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['x.php']],
            ],
        ]);

        $order = array_map(fn (array $w) => $w['task_ids'][0], $result['waves']);
        // low-risk-high-leverage has file overlap with high-risk (both use x.php), so they can't
        // be grouped. low-risk-low-leverage (y.php) has no overlap with either → grouped with
        // low-risk-high-leverage as the leading candidate.
        $this->assertSame(['low-risk-high-leverage', 'high-risk'], $order);
        // Verify the first wave is a parallel-safe lane containing both low-risk tasks.
        $this->assertTrue($result['waves'][0]['parallel_safe_lane']);
        $this->assertCount(2, $result['waves'][0]['task_ids']);
        $this->assertContains('low-risk-low-leverage', $result['waves'][0]['task_ids']);
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
                ['id' => 'dispatch', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['shared.php'], 'wave_kind' => 'runtime_dispatch'],
                ['id' => 'static', 'risk' => 'high', 'proof_ready' => true, 'allowed_files' => ['shared.php'], 'wave_kind' => 'static_edit'],
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

    // ── AC2: parallel-safe lanes — non-overlapping proof-ready candidates ─────

    public function test_proof_ready_candidates_with_disjoint_files_are_grouped_into_parallel_safe_lane(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
                ['id' => 'c2', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['b.php']],
                ['id' => 'c3', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['c.php']],
            ],
        ]);

        $this->assertCount(1, $result['waves'], 'All three non-overlapping proof-ready candidates should be in one wave');
        $wave = $result['waves'][0];
        $this->assertTrue($wave['parallel_safe_lane'], 'Grouped wave must be a parallel-safe lane');
        $this->assertSame(3, $wave['lane_size']);
        $this->assertContains('c1', $wave['task_ids']);
        $this->assertContains('c2', $wave['task_ids']);
        $this->assertContains('c3', $wave['task_ids']);
        $this->assertSame([], $wave['collision_risks'], 'No collision risks when all files are disjoint');
    }

    // ── AC3: overlapping candidates separated, collision risks exposed ────────

    public function test_candidates_with_overlapping_files_not_grouped(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'a', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['shared.php']],
                ['id' => 'b', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['shared.php']],
            ],
        ]);

        // They share 'shared.php' → must be in separate waves
        $this->assertCount(2, $result['waves'], 'Overlapping candidates must be in separate waves');
        $this->assertFalse($result['waves'][0]['parallel_safe_lane'], 'Single-task wave is not a parallel-safe lane');
        $this->assertSame(1, $result['waves'][0]['lane_size']);
        $this->assertNotEmpty($result['waves'][0]['collision_risks']);
    }

    // ── AC4: destructive execution blocked_until_proven ──────────────────────

    public function test_destructive_execution_blocked_until_proven_when_any_proof_incomplete(): void
    {
        $proofStatus = [
            'boundary' => true,
            'cluster' => true,
            'equivalence' => true,
            'consumer_impact' => true,
            'rewrite' => true,
            'replay' => false,
            'rollback' => true,
        ];

        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequencePipeline($proofStatus);

        $this->assertSame(
            AtlasSelfConstructionConsolidationWaveSequencer::STATUS_BLOCKED_UNTIL_PROVEN,
            $result['execution_status'],
        );
    }

    // ── parallel_safe_lane field present ─────────────────────────────────────

    public function test_parallel_safe_lane_field_present_on_all_waves(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['a.php']],
            ],
        ]);

        $this->assertArrayHasKey('parallel_safe_lane', $result['waves'][0]);
        $this->assertArrayHasKey('lane_size', $result['waves'][0]);
        $this->assertFalse($result['waves'][0]['parallel_safe_lane']);
        $this->assertSame(1, $result['waves'][0]['lane_size']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: proof-ready candidates with disjoint allowed_files → parallel_safe_lane
    // ═══════════════════════════════════════════════════════════════════════

    public function test_proof_ready_disjoint_files_grouped_into_parallel_safe_lane(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['app/A.php']],
                ['id' => 'c2', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['app/B.php']],
            ],
        ]);

        $this->assertTrue($result['waves'][0]['parallel_safe_lane'],
            'proof-ready candidates with disjoint files must be in a parallel_safe_lane');
        $this->assertSame(2, $result['waves'][0]['lane_size']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: overlapping allowed_files → separated with collision_risks
    // ═══════════════════════════════════════════════════════════════════════

    public function test_overlapping_allowed_files_separated_and_reports_collision_risk(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['app/Shared.php']],
                ['id' => 'c2', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['app/Shared.php']],
            ],
        ]);

        $this->assertFalse($result['waves'][0]['parallel_safe_lane'],
            'candidates with overlapping files must NOT be in a parallel_safe_lane');
        $this->assertSame(1, $result['waves'][0]['lane_size']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: output contract — parallel_safe_lane, collision_risks, execution_status
    // ═══════════════════════════════════════════════════════════════════════

    public function test_output_includes_parallel_safe_lane_and_collision_risk_keys(): void
    {
        $result = (new AtlasSelfConstructionConsolidationWaveSequencer)->sequence([
            'candidates' => [
                ['id' => 'c1', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['app/A.php', 'app/Shared.php']],
                ['id' => 'c2', 'risk' => 'low', 'proof_ready' => true, 'allowed_files' => ['app/B.php', 'app/Shared.php']],
            ],
        ]);

        $this->assertArrayHasKey('parallel_safe_lane', $result['waves'][0]);
        $this->assertArrayHasKey('collision_risks', $result['waves'][0]);
    }
}
