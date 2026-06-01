<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part07Service;
use Tests\TestCase;

/**
 * Pins the "Fatia 7" comparison-arm entry gate and the three-part "Regra Final"
 * continuation logic.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md
 */
final class AtlasDevFlowMapProductOptionsV1Part07Test extends TestCase
{
    private AtlasDevFlowMapProductOptionsV1Part07Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasDevFlowMapProductOptionsV1Part07Service;
    }

    /**
     * Every documented entry step satisfied with complete sub-evidence opens the gate.
     */
    public function test_fully_ready_payload_opens_the_entry_gate(): void
    {
        $verdict = $this->service->evaluateEntryGate($this->readyPayload());

        $this->assertSame('open', $verdict['gate']);
        $this->assertTrue($verdict['can_enter']);
        $this->assertTrue($verdict['ordering_ok']);
        $this->assertSame([], $verdict['missing']);
        $this->assertSame([], $verdict['blocked_on']);
        $this->assertSame('atlas.dev.flow_map_part07.arm_entry_gate.v1', $verdict['schema']);
    }

    /**
     * The hard ordering rule "so depois dos resultados locais": with no local
     * results the gate is blocked even when every other box is ticked, and the
     * ordering breach leads the blocked_on list.
     */
    public function test_no_local_results_blocks_gate_on_ordering_even_if_rest_done(): void
    {
        $verdict = $this->service->evaluateEntryGate($this->mutateReady(['local_results_present' => false]));

        $this->assertSame('blocked', $verdict['gate']);
        $this->assertFalse($verdict['can_enter']);
        $this->assertFalse($verdict['ordering_ok']);
        $this->assertContains('ordering:local_results_required_first', $verdict['blocked_on']);
        $this->assertContains('local_results_present', $verdict['missing']);
    }

    /**
     * An incomplete metric set ("registrar custo, tempo, qualidade e falhas") and
     * a single pure baseline ("rodar contra Sonnet puro E Opus puro") each keep
     * their step unmet — the flag alone does not pass them.
     */
    public function test_incomplete_metrics_and_single_baseline_keep_steps_unmet(): void
    {
        // Only two of four metrics registered; only one of two baselines run.
        $verdict = $this->service->evaluateEntryGate($this->mutateReady([
            'metrics_registered_set' => ['cost', 'time'],
            'baselines_run_set' => ['sonnet_pure'],
        ]));

        $this->assertFalse($verdict['can_enter']);
        $this->assertSame(['quality', 'failures'], $verdict['metrics_missing']);
        $this->assertSame(['opus_pure'], $verdict['baselines_missing']);
        $this->assertContains('metrics_registered', $verdict['missing']);
        $this->assertContains('pure_baselines_run', $verdict['missing']);
    }

    /**
     * "Regra Final" precedence: an imminent risk escalates to Forge BEFORE the
     * risk materialises — and it wins even when the task is otherwise common work.
     */
    public function test_imminent_risk_escalates_to_forge_before_risk(): void
    {
        $verdict = $this->service->classifyContinuation([
            'risk_imminent' => true,
            'common_work' => true, // would otherwise proceed — escalation must dominate
        ]);

        $this->assertSame('escalate_to_forge', $verdict['outcome']);
        $this->assertTrue($verdict['escalated_before_risk']);
    }

    /**
     * The three documented outcomes are reachable: stop early when it should not
     * continue, proceed on confident common work, and (conservative default) stop
     * early on non-common, non-escalated work.
     */
    public function test_stop_early_proceed_and_conservative_default(): void
    {
        $stop = $this->service->classifyContinuation(['should_not_continue' => true]);
        $this->assertSame('stop_early', $stop['outcome']);

        $lowConfidence = $this->service->classifyContinuation(['common_work' => true, 'confidence' => 'low']);
        $this->assertSame('stop_early', $lowConfidence['outcome']); // low confidence stops despite common work

        $proceed = $this->service->classifyContinuation(['common_work' => true, 'confidence' => 'high']);
        $this->assertSame('proceed', $proceed['outcome']);

        $neither = $this->service->classifyContinuation([]); // not common, not escalated
        $this->assertSame('stop_early', $neither['outcome']);
    }

    /**
     * The success criterion of Atlas Dev Light is explicitly NOT "do everything
     * Forge does, cheaper" — that framing is rejected; the three legitimate
     * criteria are accepted.
     */
    public function test_cheaper_forge_success_criterion_is_rejected(): void
    {
        $rejected = $this->service->judgeSuccessCriterion('fazer tudo que o Forge faz, mais barato');
        $this->assertFalse($rejected['accepted']);
        $this->assertSame('rejected_cheaper_forge_framing', $rejected['classification']);

        $rejectedEn = $this->service->judgeSuccessCriterion('do everything Forge does but cheaper');
        $this->assertFalse($rejectedEn['accepted']);

        $allowed = $this->service->judgeSuccessCriterion('escalate to Forge before becoming a risk');
        $this->assertTrue($allowed['accepted']);
        $this->assertSame('allowed', $allowed['classification']);
    }

    /**
     * The mode taxonomy keeps Atlas Dev (efficient daily) and Forge (maximum
     * governance) as distinct products that a comparison measures separately.
     */
    public function test_mode_taxonomy_keeps_dev_and_forge_distinct(): void
    {
        $taxonomy = $this->service->modeTaxonomy();

        $this->assertSame('efficient daily mode', $taxonomy['modes']['atlas_dev']['role']);
        $this->assertSame('maximum-governance mode', $taxonomy['modes']['forge']['role']);
        $this->assertTrue($taxonomy['measured_as_different_products']);

        $manifest = $this->service->manifest();
        $this->assertSame(['cost', 'time', 'quality', 'failures'], $manifest['required_metrics']);
        $this->assertSame(['sonnet_pure', 'opus_pure'], $manifest['required_baselines']);
        $this->assertCount(5, $manifest['entry_steps']);
    }

    /**
     * The doc's full "Fatia 7" ready payload.
     *
     * @return array<string,mixed>
     */
    private function readyPayload(): array
    {
        return [
            'local_results_present' => true,
            'real_arm_active' => true,
            'contract_frozen' => true,
            'pure_baselines_run' => true,
            'baselines_run_set' => ['sonnet_pure', 'opus_pure'],
            'metrics_registered' => true,
            'metrics_registered_set' => ['cost', 'time', 'quality', 'failures'],
        ];
    }

    /**
     * Start from the ready payload and override the given keys.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function mutateReady(array $overrides): array
    {
        return array_merge($this->readyPayload(), $overrides);
    }
}
