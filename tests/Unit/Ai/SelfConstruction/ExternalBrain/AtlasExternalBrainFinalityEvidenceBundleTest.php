<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFinalityEvidenceBundle;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainFinalityEvidenceBundleTest extends TestCase
{
    private function bundle(): AtlasExternalBrainFinalityEvidenceBundle
    {
        return new AtlasExternalBrainFinalityEvidenceBundle;
    }

    private function dim(array $overrides = []): array
    {
        return array_merge([
            'name'                => 'coverage',
            'is_proven'           => true,
            'is_stale'            => false,
            'is_unwired'          => false,
            'is_contradicted'     => false,
            'is_unintegrated'     => false,
            'is_undocumented'     => false,
            'is_queue_unsafe'     => false,
            'has_outcome_learning' => true,
            'evidence_refs'       => ['ref:coverage-proof'],
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->bundle()->assemble([]);
        $this->assertSame(AtlasExternalBrainFinalityEvidenceBundle::SCHEMA, $r['schema_version']);
        foreach (['is_final', 'blockers', 'satisfied_dimensions', 'bundle_evidence', 'finality_score', 'readiness_band',
                  'readiness_percent', 'missing_categories', 'next_highest_leverage_gap', 'finality_summary'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    public function test_readiness_percent_is_finality_score_times_100(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['name' => 'autonomy']), $this->dim(['name' => 'queue_health', 'is_proven' => false])],
        ]);

        $this->assertSame(50.0, $r['readiness_percent']);
    }

    public function test_missing_categories_lists_blocked_dimension_names(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['name' => 'autonomy']), $this->dim(['name' => 'provider_independence', 'is_stale' => true])],
        ]);

        $this->assertSame(['provider_independence'], $r['missing_categories']);
    }

    public function test_missing_categories_empty_when_final(): void
    {
        $r = $this->bundle()->assemble(['dimensions' => [$this->dim()]]);

        $this->assertSame([], $r['missing_categories']);
        $this->assertNull($r['next_highest_leverage_gap']);
    }

    public function test_next_highest_leverage_gap_is_first_blocked_dimension(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['name' => 'task_quality', 'is_unwired' => true]),
                $this->dim(['name' => 'certification', 'is_undocumented' => true]),
            ],
        ]);

        $this->assertSame('task_quality', $r['next_highest_leverage_gap']);
    }

    public function test_self_declared_proven_without_evidence_blocks_95_readiness(): void
    {
        // is_proven=true but no evidence_refs — a self-declared claim with no proof.
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['name' => 'simplification', 'is_proven' => true, 'evidence_refs' => []])],
        ]);

        $this->assertFalse($r['is_final']);
        $this->assertLessThan(95.0, $r['readiness_percent']);
        $this->assertContains('simplification', $r['missing_categories']);
    }

    // ── is_final=true path ────────────────────────────────────────────────────

    public function test_all_passing_dimensions_yields_final(): void
    {
        $r = $this->bundle()->assemble(['dimensions' => [$this->dim()]]);
        $this->assertTrue($r['is_final']);
        $this->assertEmpty($r['blockers']);
        $this->assertContains('coverage', $r['satisfied_dimensions']);
    }

    public function test_bundle_evidence_collects_refs_from_satisfied(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['evidence_refs' => ['ref:A', 'ref:B']]),
            ],
        ]);
        $this->assertContains('ref:A', $r['bundle_evidence']);
        $this->assertContains('ref:B', $r['bundle_evidence']);
    }

    // ── AC2: missing blocker ──────────────────────────────────────────────────

    public function test_required_dimension_not_present_is_missing_blocker(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions'          => [$this->dim(['name' => 'coverage'])],
            'required_dimensions' => ['coverage', 'integration'],
        ]);
        $this->assertFalse($r['is_final']);
        $this->assertContains('missing', array_column($r['blockers'], 'blocker_type'));
        $this->assertContains('integration', array_column($r['blockers'], 'dimension'));
    }

    // ── AC2: contradicted blocker ─────────────────────────────────────────────

    public function test_contradicted_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_contradicted' => true])],
        ]);
        $this->assertSame('contradicted', $r['blockers'][0]['blocker_type']);
        $this->assertFalse($r['is_final']);
    }

    // ── AC2: stale blocker ────────────────────────────────────────────────────

    public function test_stale_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_stale' => true])],
        ]);
        $this->assertSame('stale', $r['blockers'][0]['blocker_type']);
    }

    // ── AC2: unwired blocker ──────────────────────────────────────────────────

    public function test_unwired_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_unwired' => true])],
        ]);
        $this->assertSame('unwired', $r['blockers'][0]['blocker_type']);
    }

    // ── AC2: unintegrated blocker ─────────────────────────────────────────────

    public function test_unintegrated_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_unintegrated' => true])],
        ]);
        $this->assertSame('unintegrated', $r['blockers'][0]['blocker_type']);
        $this->assertFalse($r['is_final']);
    }

    // ── AC2: undocumented blocker ─────────────────────────────────────────────

    public function test_undocumented_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_undocumented' => true])],
        ]);
        $this->assertSame('undocumented', $r['blockers'][0]['blocker_type']);
        $this->assertFalse($r['is_final']);
    }

    // ── AC2: queue_unsafe blocker ─────────────────────────────────────────────

    public function test_queue_unsafe_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_queue_unsafe' => true])],
        ]);
        $this->assertSame('queue_unsafe', $r['blockers'][0]['blocker_type']);
        $this->assertFalse($r['is_final']);
    }

    // ── AC2: no_outcome_learning blocker ─────────────────────────────────────

    public function test_no_outcome_learning_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['has_outcome_learning' => false])],
        ]);
        $this->assertSame('no_outcome_learning', $r['blockers'][0]['blocker_type']);
        $this->assertFalse($r['is_final']);
    }

    // ── AC2: unproven blocker ─────────────────────────────────────────────────

    public function test_unproven_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_proven' => false])],
        ]);
        $this->assertSame('unproven', $r['blockers'][0]['blocker_type']);
    }

    public function test_empty_evidence_refs_blocks_as_unproven_even_when_proven_flag_true(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_proven' => true, 'evidence_refs' => []])],
        ]);
        $this->assertSame('unproven', $r['blockers'][0]['blocker_type']);
        $this->assertFalse($r['is_final']);
    }

    // ── Blocker priority order ────────────────────────────────────────────────

    public function test_contradicted_takes_priority_over_stale(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_contradicted' => true, 'is_stale' => true])],
        ]);
        $this->assertSame('contradicted', $r['blockers'][0]['blocker_type']);
    }

    public function test_stale_takes_priority_over_unwired(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_stale' => true, 'is_unwired' => true])],
        ]);
        $this->assertSame('stale', $r['blockers'][0]['blocker_type']);
    }

    public function test_unwired_takes_priority_over_unintegrated(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_unwired' => true, 'is_unintegrated' => true])],
        ]);
        $this->assertSame('unwired', $r['blockers'][0]['blocker_type']);
    }

    public function test_no_outcome_learning_takes_priority_over_unproven(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['has_outcome_learning' => false, 'is_proven' => false])],
        ]);
        $this->assertSame('no_outcome_learning', $r['blockers'][0]['blocker_type']);
    }

    // ── finality_score and readiness_band ─────────────────────────────────────

    public function test_finality_score_is_one_when_all_satisfied(): void
    {
        $r = $this->bundle()->assemble(['dimensions' => [$this->dim()]]);
        $this->assertSame(1.0, $r['finality_score']);
        $this->assertSame('final', $r['readiness_band']);
    }

    public function test_finality_score_reflects_ratio_of_satisfied(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['name' => 'a']),
                $this->dim(['name' => 'b']),
                $this->dim(['name' => 'c', 'is_stale' => true]),
                $this->dim(['name' => 'd', 'is_stale' => true]),
            ],
        ]);
        $this->assertSame(0.5, $r['finality_score']);
        $this->assertSame('developing', $r['readiness_band']);
    }

    public function test_readiness_band_near_final_when_score_above_80(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['name' => 'a']),
                $this->dim(['name' => 'b']),
                $this->dim(['name' => 'c']),
                $this->dim(['name' => 'd']),
                $this->dim(['name' => 'e', 'is_stale' => true]),
            ],
        ]);
        $this->assertSame('near_final', $r['readiness_band']);
    }

    public function test_readiness_band_incomplete_when_score_below_50(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['name' => 'a']),
                $this->dim(['name' => 'b', 'is_stale' => true]),
                $this->dim(['name' => 'c', 'is_stale' => true]),
                $this->dim(['name' => 'd', 'is_stale' => true]),
            ],
        ]);
        $this->assertSame('incomplete', $r['readiness_band']);
    }

    // ── required_dimensions filter ────────────────────────────────────────────

    public function test_only_required_dimensions_evaluated(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['name' => 'coverage']),
                $this->dim(['name' => 'extra', 'is_proven' => false]),
            ],
            'required_dimensions' => ['coverage'],
        ]);
        $this->assertTrue($r['is_final']);
    }

    // ── satisfied + not satisfied split ───────────────────────────────────────

    public function test_satisfied_and_blocked_split_correctly(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [
                $this->dim(['name' => 'alpha']),
                $this->dim(['name' => 'beta', 'is_stale' => true]),
            ],
        ]);
        $this->assertContains('alpha', $r['satisfied_dimensions']);
        $this->assertNotContains('beta', $r['satisfied_dimensions']);
        $this->assertSame('beta', $r['blockers'][0]['dimension']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'dimensions' => [
                $this->dim(['name' => 'a', 'evidence_refs' => ['r1']]),
                $this->dim(['name' => 'b', 'is_stale' => true]),
            ],
        ];
        $a = $this->bundle()->assemble($facts);
        $b = $this->bundle()->assemble($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
