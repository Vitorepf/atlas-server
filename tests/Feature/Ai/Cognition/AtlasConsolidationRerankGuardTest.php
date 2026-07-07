<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasConsolidationRerankGuard;
use Tests\TestCase;

/**
 * T4-S7: the re-ranker guard enforces "precision@k NUNCA regride". A
 * consolidation only promotes when precision@k is >= the frozen baseline;
 * a drop is blocked; missing metric (venv absent) is `unmeasured`, never a
 * fabricated pass.
 */
class AtlasConsolidationRerankGuardTest extends TestCase
{
    private function guard(): AtlasConsolidationRerankGuard
    {
        $path = tempnam(sys_get_temp_dir(), 'rerank_baseline_').'.json';
        @unlink($path);

        return new AtlasConsolidationRerankGuard(null, $path);
    }

    public function test_evaluate_never_fabricates_a_pass(): void
    {
        $g = $this->guard();

        $this->assertSame('no_baseline', $g->evaluate(0.9, null));   // nothing frozen yet
        $this->assertSame('unmeasured', $g->evaluate(null, 0.7));    // no metric (venv absent)
        $this->assertSame('promote_allowed', $g->evaluate(0.7, 0.7)); // equal = non-regression
        $this->assertSame('promote_allowed', $g->evaluate(0.85, 0.7)); // improved
        $this->assertSame('blocked_regression', $g->evaluate(0.5, 0.7)); // regressed → blocked
    }

    public function test_freeze_then_verdict_blocks_regression(): void
    {
        $g = $this->guard();

        $frozen = $g->freeze(0.80);
        $this->assertTrue($frozen['ok']);
        $this->assertSame(0.8, $frozen['precision_at_k']);

        $this->assertTrue($g->verdict(0.82)['promote_allowed'], 'melhora deve promover');
        $this->assertFalse($g->verdict(0.60)['promote_allowed'], 'regressão deve bloquear');
        $this->assertSame('blocked_regression', $g->verdict(0.60)['verdict']);
    }

    public function test_cannot_freeze_without_a_real_metric(): void
    {
        // No corpus injected + no override → nothing to freeze (venv absent).
        $result = $this->guard()->freeze();

        $this->assertFalse($result['ok']);
    }

    public function test_verdict_is_unmeasured_without_baseline_or_metric(): void
    {
        $this->assertSame('no_baseline', $this->guard()->verdict()['verdict']);
    }
}
