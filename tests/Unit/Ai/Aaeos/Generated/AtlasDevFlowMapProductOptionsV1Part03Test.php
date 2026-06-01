<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part03Service;
use Tests\TestCase;

/**
 * Pins the CLOSED, CONSOLIDATED rules of the flow/product map recorte (Parte 3):
 * the write envelope gate, the R4/R5 plan-only invariant, the three-speed
 * routing, the dedicated-driver pending gate and the post-scope-cut exclusions.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md
 */
class AtlasDevFlowMapProductOptionsV1Part03Test extends TestCase
{
    private function service(): AtlasDevFlowMapProductOptionsV1Part03Service
    {
        return new AtlasDevFlowMapProductOptionsV1Part03Service();
    }

    /**
     * "Decisoes consolidadas": every write needs mini-spec + task contract +
     * scope guard + receipt. A complete envelope at low risk is allowed; an
     * incomplete envelope is blocked and the exact missing parts are reported.
     */
    public function test_write_gate_requires_full_envelope(): void
    {
        $s = $this->service();

        $ok = $s->writeGate([
            'risk_level' => 'R1',
            'has_mini_spec' => true,
            'has_task_contract' => true,
            'has_scope_guard' => true,
            'has_receipt' => true,
        ]);
        $this->assertTrue($ok['write_allowed']);
        $this->assertSame('dev_patch', $ok['mode']);
        $this->assertSame([], $ok['missing_envelope']);

        $bad = $s->writeGate([
            'risk_level' => 'R1',
            'has_mini_spec' => true,
        ]);
        $this->assertFalse($bad['write_allowed']);
        $this->assertSame('blocked_incomplete_envelope', $bad['mode']);
        $this->assertContains('task_contract', $bad['missing_envelope']);
        $this->assertContains('scope_guard', $bad['missing_envelope']);
        $this->assertContains('receipt', $bad['missing_envelope']);
    }

    /**
     * "Risco R4/R5 gera plan-only ... nao patch Dev": even with a perfectly
     * complete envelope, an R4 or R5 write is forced plan-only and never allowed
     * as a Dev patch.
     */
    public function test_r4_r5_write_is_plan_only_even_with_full_envelope(): void
    {
        $s = $this->service();

        foreach (['R4', 'R5'] as $risk) {
            $verdict = $s->writeGate([
                'risk_level' => $risk,
                'has_mini_spec' => true,
                'has_task_contract' => true,
                'has_scope_guard' => true,
                'has_receipt' => true,
            ]);
            $this->assertFalse($verdict['write_allowed'], "{$risk} must not allow a Dev patch");
            $this->assertSame('plan_only_plus_promotion_preview', $verdict['mode']);
            $this->assertSame('risk_r4_or_r5_is_plan_only_not_a_dev_patch', $verdict['reason']);
        }
    }

    /**
     * Three-speed routing: a declared Obra and R4/R5 go to the Forge heavy path
     * (fast path may NOT patch); a plain low-risk task stays on the Atlas Dev
     * fast path (may patch).
     */
    public function test_three_speed_routing(): void
    {
        $s = $this->service();

        $obra = $s->speedFor(['obra_declared' => true]);
        $this->assertSame('forge_heavy_path', $obra['speed']);
        $this->assertFalse($obra['fast_path_may_patch']);

        $r5 = $s->speedFor(['risk_level' => 'R5']);
        $this->assertSame('forge_heavy_path', $r5['speed']);
        $this->assertFalse($r5['fast_path_may_patch']);

        $plain = $s->speedFor(['risk_level' => 'R2']);
        $this->assertSame('atlas_dev_fast_path', $plain['speed']);
        $this->assertTrue($plain['fast_path_may_patch']);
    }

    /**
     * "O que ainda nao existe como driver proprio": with no dedicated driver, a
     * real run of the atlas_dev_light arm blocks honestly with
     * `atlas_dev_light_driver_pending` and reports the real path that does exist.
     * Once the dedicated driver is present, it becomes runnable.
     */
    public function test_driver_run_gate_blocks_until_dedicated_driver_exists(): void
    {
        $s = $this->service();

        $pending = $s->driverRunGate(false);
        $this->assertFalse($pending['runnable']);
        $this->assertTrue($pending['blocks']);
        $this->assertSame('atlas_dev_light_driver_pending', $pending['reason']);
        $this->assertSame(
            ['atlas:cli:dev', 'atlas:ai:chat', 'provider_or_engineering_harness'],
            $pending['real_path']
        );

        $ready = $s->driverRunGate(true);
        $this->assertTrue($ready['runnable']);
        $this->assertFalse($ready['blocks']);
    }

    /**
     * "Decisao atual apos corte de escopo": a frozen item (claim of win) is
     * out of scope; an unrelated engineering artifact is not flagged by this gate.
     */
    public function test_scope_cut_flags_frozen_items_only(): void
    {
        $s = $this->service();

        $frozen = $s->scopeCheck('claim_of_win');
        $this->assertTrue($frozen['out_of_scope']);
        $this->assertSame('excluded_by_post_scope_cut_decision', $frozen['reason']);

        $inScope = $s->scopeCheck('verification_receipt');
        $this->assertFalse($inScope['out_of_scope']);
    }

    /**
     * The consolidated-decisions set is closed and carries the two load-bearing
     * invariants used elsewhere in the flow: passed-needs-evidence and the
     * R4/R5 plan-only rule.
     */
    public function test_consolidated_decisions_are_closed_and_present(): void
    {
        $decisions = $this->service()->consolidatedDecisions();

        $this->assertCount(8, $decisions);
        $this->assertArrayHasKey('passed_needs_evidence', $decisions);
        $this->assertArrayHasKey('r4_r5_is_plan_only', $decisions);
        $this->assertArrayHasKey('every_write_needs_full_envelope', $decisions);
    }
}
