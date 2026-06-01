<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasExperimentationEngineService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Experimentation Engine contract.
 *
 * @see docs/engineering-knowledge-base/atlas-experimentation-engine.md
 */
class AtlasExperimentationEngineTest extends TestCase
{
    private function service(): AtlasExperimentationEngineService
    {
        return new AtlasExperimentationEngineService();
    }

    /** A complete, valid experiment that satisfies all 4 quality gates. */
    private function validExperiment(): array
    {
        return [
            'hypothesis' => 'A shorter checkout raises completed-purchase rate.',
            'primary_metric' => [
                'name' => 'completed_purchase_rate',
                'target' => 0.12,
                'observed' => 0.15,
                'business_metric' => 'completed_purchase_rate',
                'is_vanity' => false,
            ],
            'sample_size' => 1200,
            'required_sample' => 800,
        ];
    }

    /**
     * Quality gates: the 4 documented gates are enforced in order, and an
     * experiment with no metric + no defined sample is opinion, not validation
     * ("Nao chamar opiniao de validacao").
     */
    public function test_quality_gates_and_opinion_is_not_validation(): void
    {
        $this->assertSame(
            ['hypothesis-written', 'metric-selected', 'sample-defined', 'result-recorded'],
            AtlasExperimentationEngineService::QUALITY_GATES,
        );

        $ready = $this->service()->evaluateReadiness($this->validExperiment());
        $this->assertTrue($ready['ready_to_report']);
        $this->assertSame([], $ready['missing_gates']);
        $this->assertTrue($ready['is_validation']);

        // A bare hypothesis with no metric and no sample is opinion only.
        $opinion = $this->service()->evaluateReadiness([
            'hypothesis' => 'I think this offer will sell well.',
        ]);
        $this->assertFalse($opinion['ready_to_report']);
        $this->assertFalse($opinion['is_validation']);
        $this->assertSame(
            ['metric-selected', 'sample-defined', 'result-recorded'],
            $opinion['missing_gates'],
        );
    }

    /**
     * Vanity-metric guard: "Nao otimizar metrica de vaidade quando metrica de
     * negocio existe." A vanity metric is blocked when a business metric exists,
     * but a proxy metric is allowed when no business metric is available.
     */
    public function test_vanity_metric_blocked_when_business_metric_exists(): void
    {
        $blocked = $this->service()->evaluateMetricChoice([
            'name' => 'impressions',
            'is_vanity' => true,
            'business_metric' => 'revenue',
        ]);
        $this->assertFalse($blocked['allowed']);
        $this->assertTrue($blocked['business_metric_available']);
        $this->assertSame('revenue', $blocked['must_optimize']);

        // No business metric => a proxy/vanity metric is allowed (but flagged).
        $allowedProxy = $this->service()->evaluateMetricChoice([
            'name' => 'signups',
            'is_vanity' => true,
        ]);
        $this->assertTrue($allowedProxy['allowed']);
        $this->assertFalse($allowedProxy['business_metric_available']);
    }

    /**
     * Insufficient sample => inconclusive, never a conclusion ("Conclusao sem
     * amostra suficiente") and it must be surfaced ("Nao esconder experimento
     * inconclusivo"). The safe floor is 30.
     */
    public function test_underpowered_sample_is_inconclusive_and_surfaced(): void
    {
        $this->assertSame(30, AtlasExperimentationEngineService::MIN_SAMPLE_FLOOR);

        $weak = $this->service()->evaluateSampleSufficiency(12, 500);
        $this->assertFalse($weak['sufficient']);
        $this->assertSame('inconclusive', $weak['verdict']);
        $this->assertSame(488, $weak['shortfall']);
        $this->assertTrue($weak['must_surface']);

        $strong = $this->service()->evaluateSampleSufficiency(900, 500);
        $this->assertTrue($strong['sufficient']);
        $this->assertSame('conclusive_sample', $strong['verdict']);
    }

    /**
     * Step-8 decision policy: escalate requires BOTH the minimum result met AND
     * an approved budget ("Nao escalar campanha sem resultado minimo e budget
     * aprovado"). A met result without an approved budget may only continue.
     */
    public function test_escalate_requires_result_and_approved_budget(): void
    {
        $base = ['observed' => 0.15, 'target' => 0.12, 'sample_size' => 1000, 'required_sample' => 500];

        // Result met + budget approved => escalate.
        $escalate = $this->service()->decide($base, ['approved' => true]);
        $this->assertSame(AtlasExperimentationEngineService::DECISION_ESCALATE, $escalate['decision']);

        // Result met but budget NOT approved => continue, never escalate.
        $continue = $this->service()->decide($base, ['approved' => false]);
        $this->assertSame(AtlasExperimentationEngineService::DECISION_CONTINUE, $continue['decision']);
        $this->assertTrue($continue['result_met']);
    }

    /**
     * Step-8 decision policy: a sufficient sample where the result is NOT met
     * routes to pivot; an underpowered sample stops as inconclusive regardless of
     * the observed value.
     */
    public function test_decision_pivot_and_stop_paths(): void
    {
        // Sufficient sample, target missed => pivot.
        $pivot = $this->service()->decide(
            ['observed' => 0.05, 'target' => 0.12, 'sample_size' => 1000, 'required_sample' => 500],
            ['approved' => true],
        );
        $this->assertSame(AtlasExperimentationEngineService::DECISION_PIVOT, $pivot['decision']);

        // Even a "good looking" observed value stops when the sample is too small.
        $stop = $this->service()->decide(
            ['observed' => 0.99, 'target' => 0.12, 'sample_size' => 5, 'required_sample' => 500],
            ['approved' => true],
        );
        $this->assertSame(AtlasExperimentationEngineService::DECISION_STOP, $stop['decision']);
        $this->assertTrue($stop['inconclusive']);
        $this->assertTrue($stop['must_surface']);
    }

    /**
     * Full loop: gates not passed => the engine refuses to report a decision and
     * stops, treating the result as opinion rather than validation. A complete,
     * winning experiment with approved budget reports an escalate decision.
     */
    public function test_run_experiment_blocks_opinion_and_reports_validation(): void
    {
        $opinion = $this->service()->runExperiment([
            'hypothesis' => 'This will work.',
        ]);
        $this->assertSame(AtlasExperimentationEngineService::DECISION_STOP, $opinion['decision']);
        $this->assertFalse($opinion['reportable']);
        $this->assertSame('gates_not_passed_result_is_opinion_not_validation', $opinion['reason']);

        $validated = $this->service()->runExperiment($this->validExperiment(), ['approved' => true]);
        $this->assertTrue($validated['reportable']);
        $this->assertSame(AtlasExperimentationEngineService::DECISION_ESCALATE, $validated['decision']);
        $this->assertTrue($validated['human_review_required']);
        $this->assertCount(9, $validated['flow_steps']);
    }
}
