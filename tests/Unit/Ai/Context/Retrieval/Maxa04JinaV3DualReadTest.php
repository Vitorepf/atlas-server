<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Retrieval;

use App\Services\Ai\Context\Retrieval\Maxa04JinaV3DualReadService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Maxa04JinaV3DualReadTest extends TestCase
{
    #[Test]
    public function plan_declares_candidate_rollback_and_no_default_promotion(): void
    {
        $plan = (new Maxa04JinaV3DualReadService)->plan();

        $this->assertSame('atlas.semantic.jina_v3_dual_read.v1', $plan['schema_version']);
        $this->assertSame('MAXA-04', $plan['slice']);
        $this->assertFalse($plan['default_promoted']);
        $this->assertFalse($plan['applied_to_live']);
        $this->assertSame('jina_v3_dual_read_benchmark_window', $plan['pending_window']);
        $this->assertSame('jinaai/jina-embeddings-v3', $plan['candidate_model']['model']);
        $this->assertNotEmpty($plan['rollback']['handle']);
        $this->assertNotEmpty($plan['reembed_path']['command']);
    }

    #[Test]
    public function empty_dual_read_window_is_insufficient_signal_and_disallows_promotion(): void
    {
        $report = (new Maxa04JinaV3DualReadService)->evaluate([]);

        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame('no_dual_read_cases', $report['reason']);
        $this->assertFalse($report['promotion']['allowed']);
        $this->assertFalse($report['promotion']['default_promoted']);
        $this->assertSame('pending_window', $report['promotion']['basis']);
    }

    #[Test]
    public function better_candidate_metrics_still_do_not_claim_ab_green_or_promote_live(): void
    {
        $report = (new Maxa04JinaV3DualReadService)->evaluate([
            [
                'query_id' => 'q1',
                'current_recall_at_5' => 0.50,
                'candidate_recall_at_5' => 0.70,
                'current_precision_at_5' => 0.40,
                'candidate_precision_at_5' => 0.60,
                'targets_available' => 1,
            ],
        ]);

        $this->assertSame('pending_window', $report['status']);
        $this->assertSame('candidate_non_regression_observed', $report['window_basis']);
        $this->assertFalse($report['ab_green_claimed']);
        $this->assertFalse($report['promotion']['allowed']);
        $this->assertFalse($report['promotion']['default_promoted']);
        $this->assertSame(1, $report['summary']['cases']);
        $this->assertSame(1, $report['summary']['targets_available']);
    }
}
