<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAutonomyAndClarificationPolicyService;
use Tests\TestCase;

final class AtlasAutonomyAndClarificationPolicyTest extends TestCase
{
    private AtlasAutonomyAndClarificationPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAutonomyAndClarificationPolicyService();
    }

    public function testActOnlyWhenEveryDocumentedActPreconditionHolds(): void
    {
        // Doc "Act when": all five preconditions present, no ask/block trigger.
        $act = $this->service->evaluateRequest([
            'target_context_known' => true,
            'business_object_clear' => true,
            'design_api_rules_known' => true,
            'risk_low_or_medium' => true,
            'gates_can_run' => true,
        ]);
        $this->assertSame(AtlasAutonomyAndClarificationPolicyService::DECISION_ACT, $act['decision']);
        $this->assertTrue($act['may_act']);
        $this->assertSame([], $act['unmet_act_conditions']);

        // Drop a single precondition -> the gate must fall back to ASK, never ACT.
        $partial = $this->service->evaluateRequest([
            'target_context_known' => true,
            'business_object_clear' => true,
            'design_api_rules_known' => true,
            'risk_low_or_medium' => true,
            // gates_can_run missing
        ]);
        $this->assertSame(AtlasAutonomyAndClarificationPolicyService::DECISION_ASK, $partial['decision']);
        $this->assertFalse($partial['may_act']);
        $this->assertSame(['gates_can_run'], $partial['unmet_act_conditions']);
    }

    public function testBlockDominatesEvenWhenAllActPreconditionsHold(): void
    {
        // Doc "Block when": a destructive request without approval must BLOCK,
        // and safety precedence means it wins over an otherwise act-ready request.
        $blocked = $this->service->evaluateRequest([
            'target_context_known' => true,
            'business_object_clear' => true,
            'design_api_rules_known' => true,
            'risk_low_or_medium' => true,
            'gates_can_run' => true,
            'destructive_without_explicit_approval' => true,
        ]);
        $this->assertSame(AtlasAutonomyAndClarificationPolicyService::DECISION_BLOCK, $blocked['decision']);
        $this->assertFalse($blocked['may_act']);
        $this->assertContains('requires destructive action without explicit approval', $blocked['reasons']);

        // Bypassing validation/security is a block too.
        $bypass = $this->service->evaluateRequest(['bypasses_validation_or_security' => true]);
        $this->assertSame(AtlasAutonomyAndClarificationPolicyService::DECISION_BLOCK, $bypass['decision']);
    }

    public function testAskDominatesActButYieldsToBlock(): void
    {
        // Doc "Ask when": ambiguous business object -> ASK, even if act flags set.
        $ask = $this->service->evaluateRequest([
            'target_context_known' => true,
            'business_object_clear' => true,
            'design_api_rules_known' => true,
            'risk_low_or_medium' => true,
            'gates_can_run' => true,
            'business_object_ambiguous' => true,
        ]);
        $this->assertSame(AtlasAutonomyAndClarificationPolicyService::DECISION_ASK, $ask['decision']);
        $this->assertContains('business object is ambiguous', $ask['reasons']);

        // When both an ask and a block trigger fire, BLOCK wins.
        $both = $this->service->evaluateRequest([
            'business_object_ambiguous' => true,
            'modifies_critical_policy_or_runtime_without_ap' => true,
        ]);
        $this->assertSame(AtlasAutonomyAndClarificationPolicyService::DECISION_BLOCK, $both['decision']);
    }

    public function testLevelsTableMatchesDocumentedMeaningAndUse(): void
    {
        $levels = $this->service->levels();
        $this->assertSame(6, $levels['count']);
        $this->assertSame(['L0', 'L1', 'L2', 'L3', 'L4', 'L5'], array_column($levels['levels'], 'level'));

        $l0 = $this->service->levelFor('L0');
        $this->assertTrue($l0['known']);
        $this->assertSame('manual', $l0['key']);
        $this->assertSame('Spec/plan only', $l0['meaning']);
        $this->assertSame('critical systems, unclear risk', $l0['use']);

        $l3 = $this->service->levelFor('l3'); // case-insensitive
        $this->assertSame('auto_pr', $l3['key']);
        $this->assertSame('Atlas opens PR with evidence', $l3['meaning']);

        $unknown = $this->service->levelFor('L9');
        $this->assertFalse($unknown['known']);
        $this->assertNull($unknown['meaning']);
    }

    public function testRiskMapsToTheLevelTableUseColumn(): void
    {
        // "critical systems, unclear risk" -> L0.
        $this->assertSame('L0', $this->service->recommendAutonomyLevel('critical')['recommended_level']);
        $this->assertSame('L0', $this->service->recommendAutonomyLevel('unclear')['recommended_level']);

        // "medium/high risk" -> L1 (human approves implementation).
        $this->assertSame('L1', $this->service->recommendAutonomyLevel('high')['recommended_level']);
        $this->assertSame('L1', $this->service->recommendAutonomyLevel('medium', false)['recommended_level']);

        // "low-risk localized change" -> L2 scoped patch (no gates).
        $this->assertSame('L2', $this->service->recommendAutonomyLevel('low', false)['recommended_level']);

        // "low/medium risk with gates" -> L3 open PR with evidence.
        $this->assertSame('L3', $this->service->recommendAutonomyLevel('low', true)['recommended_level']);
        $this->assertSame('L3', $this->service->recommendAutonomyLevel('medium', true)['recommended_level']);
    }

    public function testUnknownRiskCollapsesToSafestLevel(): void
    {
        // A novel/unnamed risk band must not unlock autonomy: treat as unclear.
        $rec = $this->service->recommendAutonomyLevel('catastrophic-novel-band', true);
        $this->assertFalse($rec['risk_known']);
        $this->assertSame('L0', $rec['recommended_level']);
        $this->assertSame(0, $rec['rank']);
    }
}
