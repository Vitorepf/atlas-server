<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomeLearningMatrix;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMuscleOutcomeLearningMatrixTest extends TestCase
{
    private function matrix(): AtlasExternalBrainMuscleOutcomeLearningMatrix
    {
        return new AtlasExternalBrainMuscleOutcomeLearningMatrix;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'task_family' => 'refactor',
            'worker_id'   => 'w1',
            'model_tier'  => 'small_model',
            'outcome'     => 'success',
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->matrix()->analyze([]);
        $this->assertSame(AtlasExternalBrainMuscleOutcomeLearningMatrix::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('family_matrix',   $r);
        $this->assertArrayHasKey('worker_matrix',   $r);
        $this->assertArrayHasKey('tier_matrix',     $r);
        $this->assertArrayHasKey('respec_families', $r);
        $this->assertArrayHasKey('supply_families', $r);
        $this->assertArrayHasKey('matrix_summary',  $r);
    }

    // ── AC2: success_rate / poison_rate / failure_rate ────────────────────────

    public function test_success_rate_computed_per_family(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.6667, $r['family_matrix']['refactor']['success_rate'], 0.001);
        $this->assertEqualsWithDelta(0.3333, $r['family_matrix']['refactor']['failure_rate'], 0.001);
        $this->assertSame(3, $r['family_matrix']['refactor']['total']);
    }

    public function test_poison_rate_computed_per_family(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'poison']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['family_matrix']['refactor']['poison_rate'], 0.001);
    }

    // ── AC3: family signals ───────────────────────────────────────────────────

    public function test_poison_prone_signal_when_poison_rate_above_threshold(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'    => [
                $this->row(['outcome' => 'poison']),
                $this->row(['outcome' => 'poison']),
                $this->row(['outcome' => 'success']),
            ],
            'poison_threshold' => 0.20,
        ]);
        $this->assertSame('poison_prone', $r['family_matrix']['refactor']['signal']);
        $this->assertContains('refactor', $r['respec_families']);
        $this->assertNotContains('refactor', $r['supply_families']);
    }

    public function test_high_success_signal_when_above_threshold(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'      => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
            'success_threshold' => 0.80,
        ]);
        $this->assertSame('high_success', $r['family_matrix']['refactor']['signal']);
        $this->assertContains('refactor', $r['supply_families']);
    }

    public function test_poison_prone_takes_priority_over_high_success(): void
    {
        // Both thresholds met: poison_prone wins.
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'poison']),
            ],
            'poison_threshold'  => 0.10, // 1/5=0.20 >= 0.10 → poison_prone
            'success_threshold' => 0.70, // 4/5=0.80 >= 0.70 → but second
        ]);
        $this->assertSame('poison_prone', $r['family_matrix']['refactor']['signal']);
    }

    public function test_normal_signal_when_nothing_triggered(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
            'poison_threshold'  => 0.20,
            'success_threshold' => 0.80,
        ]);
        $this->assertSame('normal', $r['family_matrix']['refactor']['signal']);
    }

    // ── Worker reliability ────────────────────────────────────────────────────

    public function test_worker_reliable_when_above_floor(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'    => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
            ],
            'worker_rely_floor' => 0.70,
        ]);
        $this->assertTrue($r['worker_matrix']['w1']['reliable']);
    }

    public function test_worker_unreliable_below_floor(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'    => [
                $this->row(['outcome' => 'failure']),
                $this->row(['outcome' => 'failure']),
            ],
            'worker_rely_floor' => 0.70,
        ]);
        $this->assertFalse($r['worker_matrix']['w1']['reliable']);
    }

    // ── Tier matrix ───────────────────────────────────────────────────────────

    public function test_tier_matrix_groups_by_model_tier(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['model_tier' => 'small',    'outcome' => 'success']),
                $this->row(['model_tier' => 'small',    'outcome' => 'failure']),
                $this->row(['model_tier' => 'frontier', 'outcome' => 'success']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['tier_matrix']['small']['success_rate'],    0.001);
        $this->assertEqualsWithDelta(1.00, $r['tier_matrix']['frontier']['success_rate'], 0.001);
    }

    // ── matrix_summary ────────────────────────────────────────────────────────

    public function test_matrix_summary_counts_correctly(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['task_family' => 'a', 'worker_id' => 'w1', 'model_tier' => 't1']),
                $this->row(['task_family' => 'b', 'worker_id' => 'w2', 'model_tier' => 't2']),
            ],
        ]);
        $s = $r['matrix_summary'];
        $this->assertSame(2, $s['total_rows']);
        $this->assertSame(2, $s['families']);
        $this->assertSame(2, $s['workers']);
        $this->assertSame(2, $s['tiers']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'outcome_rows' => [
                $this->row(['task_family' => 'x', 'outcome' => 'poison']),
                $this->row(['task_family' => 'y', 'outcome' => 'success']),
            ],
        ];
        $a = $this->matrix()->analyze($facts);
        $b = $this->matrix()->analyze($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
