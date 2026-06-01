<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiGovernedBacklogService;
use Tests\TestCase;

final class AtlasAiGovernedBacklogTest extends TestCase
{
    private AtlasAiGovernedBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasAiGovernedBacklogService();
    }

    public function testStateMachineHasExactlyTheFiveDocumentedStates(): void
    {
        $states = $this->service->states();
        $this->assertSame(5, $states['count']);

        $names = array_column($states['states'], 'state');
        // The "Estados" table, in lifecycle order.
        $this->assertSame([
            'source_material',
            'candidate',
            'promoted',
            'rejected',
            'archived',
        ], $names);

        // promoted is a terminal state.
        $promoted = $states['states'][2];
        $this->assertSame('promoted', $promoted['state']);
        $this->assertTrue($promoted['terminal']);
        $this->assertSame([], $promoted['next']);
    }

    public function testTriageCannotBeSkippedSourceMaterialMayNotJumpToPromoted(): void
    {
        // An item must pass through candidate; a raw idea cannot be promoted.
        $illegal = $this->service->transition('source_material', 'promoted');
        $this->assertFalse($illegal['allowed']);
        $this->assertSame('illegal_transition_not_in_state_machine', $illegal['reason']);

        // The legal first step is triage into a candidate.
        $legal = $this->service->transition('source_material', 'candidate');
        $this->assertTrue($legal['allowed']);
        $this->assertSame('transition_allowed', $legal['reason']);

        // A promoted item is terminal.
        $terminal = $this->service->transition('promoted', 'candidate');
        $this->assertFalse($terminal['allowed']);
        $this->assertSame('state_is_terminal', $terminal['reason']);

        // Unknown states fail closed.
        $unknown = $this->service->transition('source_material', 'shipped');
        $this->assertFalse($unknown['allowed']);
        $this->assertSame('unknown_state', $unknown['reason']);
    }

    public function testPersonalSensorClassRequiresRedactionAndPrivacyReview(): void
    {
        // "HealthKit, atividade digital, relacoes, estado mental e sensores
        //  pessoais exigem redaction e privacy review."
        $sensor = $this->service->classifyItem('personal_sensor');
        $this->assertTrue($sensor['class_known']);
        $this->assertTrue($sensor['requires_redaction']);
        $this->assertTrue($sensor['requires_privacy_review']);
        $this->assertTrue($sensor['private_by_default']);

        // "Personal Development continua privado, non-clinical e plan-only."
        $pd = $this->service->classifyItem('personal_development');
        $this->assertTrue($pd['private_by_default']);
        $this->assertTrue($pd['non_clinical']);
        $this->assertTrue($pd['plan_only']);
        $this->assertFalse($pd['requires_human_confirmation']);

        // "Executive action, Calendar, Reminders e mutacoes externas exigem
        //  confirmacao humana e capability gate."
        $exec = $this->service->classifyItem('executive_action');
        $this->assertTrue($exec['requires_human_confirmation']);
        $this->assertTrue($exec['requires_capability_gate']);
    }

    public function testUnknownClassFailsClosedToTheMostRestrictiveObligations(): void
    {
        // A novel sensitive idea must not slip through unnamed.
        $unknown = $this->service->classifyItem('mind-reading-feed');
        $this->assertFalse($unknown['class_known']);
        $this->assertTrue($unknown['requires_redaction']);
        $this->assertTrue($unknown['requires_privacy_review']);
        $this->assertTrue($unknown['requires_human_confirmation']);
        $this->assertTrue($unknown['requires_capability_gate']);
        $this->assertTrue($unknown['plan_only']);
    }

    public function testPromotionRequiresAllFiveDeclarations(): void
    {
        // "Todo item promovido precisa declarar domain, flow, safety boundary,
        //  evidence e owner." A general item with only some fields is blocked.
        $partial = $this->service->evaluatePromotion('general', [
            'domain' => true,
            'flow' => true,
        ]);
        $this->assertFalse($partial['promotable']);
        $this->assertContains('missing_declarations', $partial['blockers']);
        $this->assertSame([
            'safety_boundary',
            'evidence',
            'owner',
        ], $partial['missing_declarations']);

        // All five present, general class (no extra obligations) -> promotable.
        $full = $this->service->evaluatePromotion('general', [
            'domain' => true,
            'flow' => true,
            'safety_boundary' => true,
            'evidence' => true,
            'owner' => true,
        ]);
        $this->assertTrue($full['promotable']);
        $this->assertSame([], $full['missing_declarations']);
        $this->assertSame([], $full['blockers']);
        $this->assertNull($full['reason']);
    }

    public function testPromotionBlocksSensorItemUntilPrivacyControlsAreSatisfied(): void
    {
        $declarations = [
            'domain' => true,
            'flow' => true,
            'safety_boundary' => true,
            'evidence' => true,
            'owner' => true,
        ];

        // Declarations complete, but the sensor's redaction + privacy review are
        // not satisfied -> still blocked.
        $blocked = $this->service->evaluatePromotion('personal_sensor', $declarations, [
            'plan_only' => true,
            'non_clinical' => true,
        ]);
        $this->assertFalse($blocked['promotable']);
        $this->assertContains('unmet_privacy_or_capability_obligations', $blocked['blockers']);
        $this->assertContains('redaction', $blocked['unmet_obligations']);
        $this->assertContains('privacy_review', $blocked['unmet_obligations']);

        // With every obligation satisfied -> promotable.
        $ok = $this->service->evaluatePromotion('personal_sensor', $declarations, [
            'plan_only' => true,
            'non_clinical' => true,
            'redaction' => true,
            'privacy_review' => true,
        ]);
        $this->assertTrue($ok['promotable']);
        $this->assertSame([], $ok['unmet_obligations']);
    }

    public function testHighRoiCannotBypassTheSpine(): void
    {
        // "Backlog de ROI nao pode bypassar Kernel, Master Architecture ou
        //  Domain Specs." A high-ROI item missing spine alignment is blocked
        //  even with all declarations present.
        $declarations = [
            'domain' => true,
            'flow' => true,
            'safety_boundary' => true,
            'evidence' => true,
            'owner' => true,
        ];

        $bypass = $this->service->evaluatePromotion('general', $declarations, [], true, [
            'kernel' => true,
            'master_architecture' => false,
            'domain_specs' => true,
        ]);
        $this->assertFalse($bypass['promotable']);
        $this->assertContains('roi_bypasses_spine', $bypass['blockers']);
        $this->assertTrue($bypass['roi']['bypasses_spine']);
        $this->assertSame(['master_architecture'], $bypass['roi']['missing_alignment']);

        // Fully aligned with the spine -> ROI no longer bypasses anything.
        $aligned = $this->service->evaluatePromotion('general', $declarations, [], true, [
            'kernel' => true,
            'master_architecture' => true,
            'domain_specs' => true,
        ]);
        $this->assertTrue($aligned['promotable']);
        $this->assertFalse($aligned['roi']['bypasses_spine']);
    }
}
