<?php

namespace Tests\Unit\Ai\PersonalDevelopment;

use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentFlowCatalog;
use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentInputNormalizer;
use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentSafetyPolicy;
use Tests\TestCase;

class PersonalDevelopmentPolicyTest extends TestCase
{
    public function test_flow_catalog_exposes_complete_stable_flow_metadata(): void
    {
        $flows = PersonalDevelopmentFlowCatalog::all();

        $this->assertCount(10, $flows);

        foreach ($flows as $id => $contract) {
            $this->assertStringStartsWith('personal_development.', $id);
            $this->assertNotEmpty($contract['type']);
            $this->assertNotEmpty($contract['cadence']);
            $this->assertNotEmpty($contract['risk']);
            $this->assertNotEmpty($contract['artifact']);
            $this->assertIsArray($contract['focus']);
            $this->assertNotEmpty($contract['focus']);
        }
    }

    public function test_input_normalizer_keeps_allowed_operational_context_and_ignores_unknown_payloads(): void
    {
        $normalized = app(PersonalDevelopmentInputNormalizer::class)->normalize([
            'intent' => '  Build a calmer daily review.  ',
            'observations' => [' Focus was better after lunch. ', ['nested' => 'ignored']],
            'raw_diary_export' => 'Ignored by artifacts.',
        ]);

        $this->assertSame('Build a calmer daily review.', data_get($normalized, 'input.intent'));
        $this->assertSame(['Focus was better after lunch.'], data_get($normalized, 'input.observations'));
        $this->assertSame(['intent', 'observations'], data_get($normalized, 'contract.accepted_keys'));
        $this->assertContains('raw_diary_export', data_get($normalized, 'contract.ignored_keys'));
        $this->assertTrue((bool) data_get($normalized, 'contract.has_intent'));
        $this->assertFalse((bool) data_get($normalized, 'contract.raw_input_retained'));
    }

    public function test_safety_policy_requires_review_for_sensitive_or_forge_inputs(): void
    {
        $policy = app(PersonalDevelopmentSafetyPolicy::class);
        $sensitivity = $policy->sensitivity([
            'intent' => 'Criar plano de rotina com ansiedade no contexto.',
        ]);

        $this->assertTrue((bool) $sensitivity['requires_review']);
        $this->assertContains('ansiedade', $sensitivity['markers']);
        $this->assertTrue($policy->approvalRequired('personal_development.reflect', $sensitivity));
        $this->assertTrue($policy->approvalRequired('personal_development.forge', ['requires_review' => false]));
        $this->assertSame('needs_human_review', $policy->runtimeStatus(true, false));
        $this->assertSame('planned', $policy->runtimeStatus(true, true));
        $this->assertContains('clinical_diagnosis', $policy->blockedActions());
        $this->assertFalse($policy->sideEffects()['calendar_mutations']);
    }
}
