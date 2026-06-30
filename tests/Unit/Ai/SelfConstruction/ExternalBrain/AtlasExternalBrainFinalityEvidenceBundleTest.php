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
            'name'            => 'coverage',
            'is_proven'       => true,
            'is_stale'        => false,
            'is_unwired'      => false,
            'is_contradicted' => false,
            'evidence_refs'   => ['ref:coverage-proof'],
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->bundle()->assemble([]);
        $this->assertSame(AtlasExternalBrainFinalityEvidenceBundle::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('is_final',             $r);
        $this->assertArrayHasKey('blockers',             $r);
        $this->assertArrayHasKey('satisfied_dimensions', $r);
        $this->assertArrayHasKey('bundle_evidence',      $r);
        $this->assertArrayHasKey('finality_summary',     $r);
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
        $blockerTypes = array_column($r['blockers'], 'blocker_type');
        $this->assertContains('missing', $blockerTypes);
        $dims = array_column($r['blockers'], 'dimension');
        $this->assertContains('integration', $dims);
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

    // ── AC2: unproven blocker ─────────────────────────────────────────────────

    public function test_unproven_dimension_blocked(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_proven' => false])],
        ]);
        $this->assertSame('unproven', $r['blockers'][0]['blocker_type']);
    }

    // ── Priority: contradicted > stale > unwired > unproven ──────────────────

    public function test_contradicted_takes_priority_over_stale(): void
    {
        $r = $this->bundle()->assemble([
            'dimensions' => [$this->dim(['is_contradicted' => true, 'is_stale' => true])],
        ]);
        $this->assertSame('contradicted', $r['blockers'][0]['blocker_type']);
    }

    // ── required_dimensions filter ────────────────────────────────────────────

    public function test_only_required_dimensions_evaluated(): void
    {
        // 'extra' is not required → its failure should not block.
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
