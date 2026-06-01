<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingFrontendCoverageAuditService;
use Tests\TestCase;

/**
 * Pins the load-bearing rules of the Impeccable Teardown Coverage Audit doc:
 * the completeness gate (every important area must be covered), the
 * documental != runtime refusal, and the audit-window re-audit guard. Pure,
 * no DB.
 *
 * @see docs/engineering-knowledge-base/domains/programming-frontend-impeccable-coverage-audit.md
 */
class AtlasProgrammingFrontendCoverageAuditTest extends TestCase
{
    private function service(): AtlasProgrammingFrontendCoverageAuditService
    {
        return new AtlasProgrammingFrontendCoverageAuditService;
    }

    public function test_canonical_matrix_is_fully_covered_and_complete(): void
    {
        $matrix = $this->service()->matrix();

        // The doc's Contratos table lists 18 area rows, all "covered".
        $this->assertSame(18, $matrix['area_count']);
        $this->assertSame(18, $matrix['covered_count']);
        $this->assertSame(AtlasProgrammingFrontendCoverageAuditService::PINNED_COMMIT, $matrix['pinned_commit']);

        foreach ($matrix['matrix'] as $row) {
            $this->assertSame('covered', $row['status'], "Area '{$row['area']}' must be covered");
            $this->assertNotSame('', $row['atlas_coverage'], "Area '{$row['area']}' must name an Atlas owner doc");
        }

        // Default evaluation over its own area set is complete.
        $completeness = $this->service()->evaluateCompleteness();
        $this->assertTrue($completeness['complete']);
        $this->assertSame([], $completeness['missing_areas']);
        $this->assertSame('teardown_coverage_complete_documented', $completeness['conclusion']);
    }

    public function test_an_uncovered_important_area_makes_the_teardown_incomplete(): void
    {
        // doc decisions[0] + Rule 1: a single important area absent from the
        // matrix means the dissection cannot be called complete.
        $important = ['repo/file inventory', 'skill source', 'service worker telemetry'];

        $result = $this->service()->evaluateCompleteness($important);

        $this->assertFalse($result['complete']);
        $this->assertSame(['service worker telemetry'], $result['missing_areas']);
        $this->assertSame('teardown_coverage_incomplete_cannot_claim_complete', $result['conclusion']);
        $this->assertContains('repo/file inventory', $result['covered']);
    }

    public function test_coverage_never_asserts_runtime_readiness(): void
    {
        // forbidden_changes + Rules 2 & 4: the matrix proves documentation, not
        // implementation, and must refuse any runtime-ready claim.
        $runtime = $this->service()->assertRuntimeReadiness();

        $this->assertFalse($runtime['runtime_ready']);
        $this->assertFalse($runtime['allowed']);
        $this->assertSame('documented_in_atlas_not_implemented_in_atlas', $runtime['meaning_of_covered']);

        // Even a "complete" evaluation never flips runtime-ready true.
        $this->assertFalse($this->service()->evaluateCompleteness()['asserts_runtime_ready']);
    }

    public function test_audit_window_requires_re_audit_on_a_different_commit(): void
    {
        // doc Rule 3 + Riscos: a different external commit invalidates the window.
        $valid = $this->service()->validateAuditWindow(AtlasProgrammingFrontendCoverageAuditService::PINNED_COMMIT);
        $this->assertTrue($valid['audit_window_valid']);
        $this->assertSame('audit_window_valid_coverage_claims_trustable', $valid['action']);

        $stale = $this->service()->validateAuditWindow('0000000000000000000000000000000000000000');
        $this->assertFalse($stale['audit_window_valid']);
        $this->assertSame('re_audit_required_recount_and_rebuild_matrix_for_new_commit', $stale['action']);
    }

    public function test_documented_flow_terminates_in_the_coverage_matrix(): void
    {
        // The Fluxo block: coverage matrix is the terminal step, never first.
        $flow = $this->service()->flow();

        $this->assertSame('clone external repo', $flow[0]);
        $this->assertSame('coverage matrix', $flow[array_key_last($flow)]);
        $this->assertCount(7, $flow);
    }
}
