<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelEscalationEconomyPolicy;
use Tests\TestCase;

final class AtlasExternalBrainModelEscalationEconomyPolicyTest extends TestCase
{
    private AtlasExternalBrainModelEscalationEconomyPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AtlasExternalBrainModelEscalationEconomyPolicy;
    }

    // ── AC1: low-risk well-scaffolded → small_model_with_scaffold + scaffold_contract ──

    public function test_low_risk_scaffolded_work_stays_on_small_model_with_scaffold(): void
    {
        // ambiguity=0.5: above low threshold (0.30) so rule 5 doesn't fire → falls to rule 6
        $result = $this->policy->decide([
            'ambiguity_score'    => 0.5,
            'evidence_quality'   => 0.9,
            'scaffold_confidence' => 0.8,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SMALL_MODEL_WITH_SCAFFOLD, $result['decision']);
        $this->assertArrayHasKey('scaffold_contract', $result);
        $this->assertIsArray($result['scaffold_contract']);
    }

    public function test_scaffold_contract_has_required_fields(): void
    {
        $result = $this->policy->decide([
            'ambiguity_score'    => 0.4,
            'evidence_quality'   => 0.8,
            'scaffold_confidence' => 0.7,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SMALL_MODEL_WITH_SCAFFOLD, $result['decision']);
        $this->assertArrayHasKey('requires_test_verification', $result['scaffold_contract']);
        $this->assertArrayHasKey('max_file_mutations', $result['scaffold_contract']);
        $this->assertArrayHasKey('confidence_floor', $result['scaffold_contract']);
    }

    // ── AC2: proxy leaks or low confidence → frontier_model with evidence ────

    public function test_repeated_proxy_leaks_trigger_frontier_escalation(): void
    {
        $result = $this->policy->decide([
            'ambiguity_score'             => 0.3,
            'evidence_quality'            => 0.8,
            'scaffold_confidence'         => 0.8,
            'repeated_proxy_leaks_count'  => 5, // > default threshold of 2
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertArrayHasKey('escalation_reason', $result);
        $this->assertStringContainsString('proxy_leaks', $result['escalation_reason']);
    }

    public function test_low_output_confidence_triggers_frontier_escalation(): void
    {
        $result = $this->policy->decide([
            'ambiguity_score'    => 0.3,
            'evidence_quality'   => 0.8,
            'scaffold_confidence' => 0.8,
            'output_confidence'  => 0.2, // < default floor of 0.40
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertArrayHasKey('escalation_reason', $result);
        $this->assertStringContainsString('output_confidence', $result['escalation_reason']);
    }

    public function test_high_architectural_risk_ambiguity_triggers_frontier(): void
    {
        $result = $this->policy->decide([
            'ambiguity_score'    => 0.85,
            'evidence_quality'   => 0.8,
            'scaffold_confidence' => 0.8,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertArrayHasKey('escalation_reason', $result);
    }

    // ── AC3: escalation denied when only reason is quota pressure or aesthetic ─

    public function test_quota_pressure_alone_does_not_trigger_escalation(): void
    {
        $result = $this->policy->decide([
            'ambiguity_score'                  => 0.3,
            'evidence_quality'                 => 0.9,
            'scaffold_confidence'              => 0.8,
            'escalation_reason_quota_pressure' => true,
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_SMALL_MODEL_WITH_SCAFFOLD, $result['decision']);
        $this->assertTrue($result['escalation_denied'] ?? false);
        $this->assertStringContainsString('quota_pressure', $result['escalation_denied_reason'] ?? '');
    }

    public function test_aesthetic_preference_alone_does_not_trigger_escalation(): void
    {
        $result = $this->policy->decide([
            'ambiguity_score'             => 0.3,
            'evidence_quality'            => 0.9,
            'scaffold_confidence'         => 0.8,
            'escalation_reason_aesthetic' => true,
        ]);

        $this->assertNotSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertTrue($result['escalation_denied'] ?? false);
    }

    public function test_quota_pressure_does_not_override_real_trigger(): void
    {
        // When there IS a real trigger (proxy leaks), escalation proceeds even with quota pressure flag
        $result = $this->policy->decide([
            'ambiguity_score'                  => 0.3,
            'evidence_quality'                 => 0.9,
            'scaffold_confidence'              => 0.8,
            'repeated_proxy_leaks_count'       => 5,
            'escalation_reason_quota_pressure' => true,
        ]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::DECISION_FRONTIER_MODEL, $result['decision']);
        $this->assertArrayNotHasKey('escalation_denied', $result);
    }

    // ── AC4: deterministic, schema present ───────────────────────────────────

    public function test_schema_is_present(): void
    {
        $result = $this->policy->decide([]);

        $this->assertSame(AtlasExternalBrainModelEscalationEconomyPolicy::SCHEMA, $result['schema']);
    }

    public function test_output_is_deterministic(): void
    {
        $input = ['ambiguity_score' => 0.5, 'evidence_quality' => 0.7, 'scaffold_confidence' => 0.7];

        $this->assertSame(
            $this->policy->decide($input)['decision'],
            $this->policy->decide($input)['decision'],
        );
    }
}
