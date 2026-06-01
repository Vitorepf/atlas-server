<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasBlueprintSurfacesRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Parity Rule of the Engineering Blueprint Surfaces Runbook:
 * an operation on one surface must exist on the others or carry an explicit
 * documented omission reason; the audit always reports the three first-class
 * surfaces (app/cli/api) and the doc's known maturity gap.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/surfaces-runbook.md
 */
class AtlasBlueprintSurfacesRunbookTest extends TestCase
{
    private function service(): AtlasBlueprintSurfacesRunbookService
    {
        return new AtlasBlueprintSurfacesRunbookService();
    }

    /**
     * The documented surfaces (each operation is surface-native) produce parity
     * gaps because the runbook does NOT claim the same operations across App,
     * CLI and API — and with no documented omissions every gap is unexplained,
     * so the audit is not ok. The three surfaces are always reported, and the
     * doc's own maturity gap is surfaced.
     */
    public function test_documented_surfaces_report_three_planes_and_known_maturity_gap(): void
    {
        $result = $this->service()->auditDocumented();

        $this->assertSame(['app', 'cli', 'api'], $result['surfaces']);
        $this->assertSame(
            AtlasBlueprintSurfacesRunbookService::KNOWN_MATURITY_GAP,
            $result['known_maturity_gap'],
        );
        // The three documented inventories are disjoint operation sets, so the
        // union is 3 (app) + 5 (cli) + 5 (api) = 13 distinct operations.
        $this->assertSame(13, $result['counts']['operations']);
        // Every operation is present on exactly one surface => missing from the
        // other two => 13 * 2 = 26 gaps, all unexplained (no omissions given).
        $this->assertSame(26, $result['counts']['gaps']);
        $this->assertSame(26, $result['counts']['unexplained']);
        $this->assertSame(0, $result['counts']['accepted']);
        $this->assertFalse($result['ok']);
    }

    /**
     * Full parity: when every operation exists on all three surfaces there are
     * zero gaps and the audit is ok.
     */
    public function test_full_parity_across_all_surfaces_is_ok(): void
    {
        $one = ['freeze' => 'x'];
        $result = $this->service()->auditParity([
            'app' => $one,
            'cli' => $one,
            'api' => $one,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['freeze'], $result['operations']);
        $this->assertSame(['app', 'cli', 'api'], $result['matrix']['freeze']['present_in']);
        $this->assertSame([], $result['matrix']['freeze']['missing_from']);
        $this->assertSame(0, $result['counts']['gaps']);
        $this->assertSame([], $result['violations']);
    }

    /**
     * Parity Rule core: an operation present on one surface but missing from
     * another, with NO documented omission, is an unexplained violation => not ok.
     */
    public function test_missing_operation_without_documented_reason_is_a_violation(): void
    {
        $result = $this->service()->auditParity([
            'app' => ['freeze' => 'panel'],
            'cli' => ['freeze' => 'atlas eng freeze'],
            'api' => [], // freeze absent from API, no reason
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['counts']['unexplained']);
        $this->assertSame(0, $result['counts']['accepted']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame(
            AtlasBlueprintSurfacesRunbookService::FINDING_UNEXPLAINED_GAP,
            $result['violations'][0]['finding'],
        );
        $this->assertSame('freeze', $result['violations'][0]['operation']);
        $this->assertSame('api', $result['violations'][0]['surface']);
        $this->assertSame(['api'], $result['matrix']['freeze']['missing_from']);
    }

    /**
     * "...unless an omission is explicitly documented": the SAME missing
     * operation, now covered by a non-empty documented reason, becomes an
     * accepted omission rather than a violation => ok again.
     */
    public function test_explicit_documented_omission_converts_gap_to_accepted(): void
    {
        $result = $this->service()->auditParity(
            [
                'app' => ['freeze' => 'panel'],
                'cli' => ['freeze' => 'atlas eng freeze'],
                'api' => [],
            ],
            [
                ['operation' => 'freeze', 'surface' => 'api', 'reason' => 'API freeze handled by run lifecycle endpoint.'],
            ],
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['counts']['unexplained']);
        $this->assertSame(1, $result['counts']['accepted']);
        $this->assertSame([], $result['violations']);
        $this->assertCount(1, $result['accepted_omissions']);
        $this->assertSame(
            AtlasBlueprintSurfacesRunbookService::FINDING_ACCEPTED_OMISSION,
            $result['accepted_omissions'][0]['finding'],
        );
        $this->assertSame('API freeze handled by run lifecycle endpoint.', $result['accepted_omissions'][0]['reason']);
    }

    /**
     * An omission entry with a BLANK reason is not "explicitly documented" — it
     * does not excuse the gap, so the violation stands.
     */
    public function test_blank_omission_reason_does_not_excuse_the_gap(): void
    {
        $result = $this->service()->auditParity(
            [
                'app' => ['freeze' => 'panel'],
                'cli' => [],
                'api' => [],
            ],
            [
                ['operation' => 'freeze', 'surface' => 'cli', 'reason' => '   '],
            ],
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['counts']['unexplained']); // missing from cli (blank reason) + api
        $this->assertSame(0, $result['counts']['accepted']);
    }
}
