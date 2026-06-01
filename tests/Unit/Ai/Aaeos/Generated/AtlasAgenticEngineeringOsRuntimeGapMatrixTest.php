<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgenticEngineeringOsRuntimeGapMatrixService;
use Tests\TestCase;

/**
 * Pins the documented rules of the AAEOS Runtime Gap Matrix: the four-state
 * contract, evidence-before-ready, DOC-L4-is-not-runtime, the high-impact
 * backlog filter, and provider-bypass declaration. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-runtime-gap-matrix.md
 */
class AtlasAgenticEngineeringOsRuntimeGapMatrixTest extends TestCase
{
    private function service(): AtlasAgenticEngineeringOsRuntimeGapMatrixService
    {
        return new AtlasAgenticEngineeringOsRuntimeGapMatrixService;
    }

    public function test_gap_state_can_never_be_claimed_ready_even_with_evidence(): void
    {
        // partial_runtime is a gap; the doc forbids promoting a gap to ready.
        $result = $this->service()->classifyArea('Atlas Forge', 'partial_runtime', [
            ['kind' => 'focused_test', 'ref' => 'SomeTest'],
            ['kind' => 'ap_receipt', 'ref' => 'AP-790'],
        ]);

        $this->assertFalse($result['ready_claim_allowed']);
        $this->assertSame('partial_runtime', $result['state']);
        $this->assertTrue($result['is_backlog_candidate']);
        // Evidence was structurally accepted, yet ready stays blocked by state.
        $this->assertSame(['focused_test', 'ap_receipt'], $result['accepted_evidence']);
        $this->assertStringContainsString('not solid_runtime', $result['blocked_reason']);
    }

    public function test_solid_runtime_is_ready_only_when_accepted_evidence_is_cited(): void
    {
        $svc = $this->service();

        // solid_runtime with NO evidence -> still not ready (evidence required).
        $noEvidence = $svc->classifyArea('AAEOS skeleton', 'solid_runtime', []);
        $this->assertFalse($noEvidence['ready_claim_allowed']);

        // solid_runtime WITH an accepted evidence kind -> ready allowed.
        $withEvidence = $svc->classifyArea('AAEOS skeleton', 'solid_runtime', [
            ['kind' => 'command_path', 'ref' => 'atlas:aaeos:...'],
        ]);
        $this->assertTrue($withEvidence['ready_claim_allowed']);

        // An evidence kind outside the accepted set is rejected and not enough.
        $bogus = $svc->classifyArea('AAEOS skeleton', 'solid_runtime', [
            ['kind' => 'slack_message', 'ref' => 'looks done'],
        ]);
        $this->assertFalse($bogus['ready_claim_allowed']);
        $this->assertSame(['slack_message'], $bogus['rejected_evidence']);
    }

    public function test_doc_l4_alone_is_never_a_ready_claim(): void
    {
        $result = $this->service()->evaluateDocLevelClaim('Atlas Dev', 'DOC L4');

        $this->assertFalse($result['ready_claim_allowed']);
        $this->assertStringContainsString('not runtime evidence', $result['reason']);
    }

    public function test_high_impact_backlog_is_only_in_scope_partial_and_spec_gaps(): void
    {
        $backlog = $this->service()->highImpactBacklog();

        $states = array_values(array_unique(array_column($backlog['backlog'], 'state')));
        sort($states);

        // Only the two gap states ever appear as backlog.
        $this->assertSame(['partial_runtime'], array_values(array_intersect(['partial_runtime'], $states)));
        foreach ($backlog['backlog'] as $item) {
            $this->assertContains($item['state'], ['partial_runtime', 'spec_runtime_gap']);
        }

        // The canonical snapshot has 9 in-scope partial rows as backlog.
        $this->assertSame(9, $backlog['backlog_count']);

        // The out-of-scope buckets and the solid row are excluded, never backlog.
        $excludedAreas = array_column($backlog['excluded'], 'area');
        $this->assertContains('Measurement/Comparison (out of scope)', $excludedAreas);
        $this->assertContains('TEOS/extreme tiers', $excludedAreas);
        $this->assertContains('AAEOS skeleton', $excludedAreas);
    }

    public function test_direct_provider_without_owner_runtime_is_a_bypass(): void
    {
        $svc = $this->service();

        $bypass = $svc->classifyProviderInvocation(false);
        $this->assertSame('provider_bypass', $bypass['classification']);
        $this->assertFalse($bypass['may_claim_full_execution']);

        $governed = $svc->classifyProviderInvocation(true);
        $this->assertSame('governed_owner_runtime', $governed['classification']);
        $this->assertTrue($governed['may_claim_full_execution']);
    }

    public function test_matrix_renders_canonical_counts_with_no_ready_rows_by_default(): void
    {
        $matrix = $this->service()->matrix();

        $this->assertSame(12, $matrix['row_count']);
        $this->assertSame(1, $matrix['solid_count']);
        $this->assertSame(9, $matrix['partial_count']);
        $this->assertSame(2, $matrix['out_of_scope_count']);
        // No evidence supplied in the default render -> zero ready rows.
        $this->assertSame(0, $matrix['ready_count']);
        $this->assertSame(9, $matrix['backlog_count']);
    }
}
