<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasVoxOperationalThinkingInterfaceService;
use Tests\TestCase;

final class AtlasVoxOperationalThinkingInterfaceTest extends TestCase
{
    private AtlasVoxOperationalThinkingInterfaceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasVoxOperationalThinkingInterfaceService();
    }

    public function testLadderHasElevenOrderedLevelsV0ThroughV10WithFreezeBoundaryAtV4(): void
    {
        $ladder = $this->service->ladder();

        // The "Escada Vox V0-V10" table has exactly 11 rows, ordered V0..V10.
        $this->assertCount(11, $ladder);
        $this->assertSame(range(0, 10), array_column($ladder, 'level'));

        // V0 is the dictation baseline; V10 is the "Sovereign Voice OS" objetivo final.
        $this->assertSame('Dictation', $ladder[0]['name']);
        $this->assertSame('Sovereign Voice OS', $ladder[10]['name']);
        $this->assertTrue($ladder[10]['is_final']);

        // Lei 0.9 freeze boundary: V0..V3 not frozen, V4..V10 frozen by GATE V3.
        foreach ($ladder as $row) {
            $this->assertSame(
                $row['level'] >= 4,
                $row['frozen_by_gate_v3'],
                "level V{$row['level']} freeze flag must match the V4+ boundary"
            );
        }

        // "V4/V5 definem 'outro patamar'."
        $this->assertTrue($ladder[4]['is_other_patamar']);
        $this->assertTrue($ladder[5]['is_other_patamar']);
        $this->assertFalse($ladder[3]['is_other_patamar']);
    }

    public function testPromotionClimbsExactlyOneRungAndForbidsSkipping(): void
    {
        // V3 -> V4 is one rung (allowed shape, even if gated below).
        $oneRung = $this->service->canPromoteTo(4, 3, true);
        $this->assertNotSame(
            AtlasVoxOperationalThinkingInterfaceService::PROMOTION_SKIP_BLOCKED,
            $oneRung['decision']
        );

        // V3 -> V6 skips rungs: blocked regardless of GATE V3 colour.
        $skip = $this->service->canPromoteTo(6, 3, true);
        $this->assertFalse($skip['allowed']);
        $this->assertSame(
            AtlasVoxOperationalThinkingInterfaceService::PROMOTION_SKIP_BLOCKED,
            $skip['decision']
        );
    }

    public function testV4PlusIsFrozenUntilGateV3GreenAndAlwaysNeedsVitorApproval(): void
    {
        // Lei 0.9: V3 -> V4 with GATE V3 NOT green is frozen.
        $frozen = $this->service->canPromoteTo(4, 3, false);
        $this->assertFalse($frozen['allowed']);
        $this->assertSame(
            AtlasVoxOperationalThinkingInterfaceService::PROMOTION_FROZEN,
            $frozen['decision']
        );
        $this->assertTrue($frozen['gate_v3_required']);
        $this->assertTrue($frozen['requires_explicit_vitor_approval']);

        // GATE V3 green unfreezes the rung, but Vitor approval is STILL required
        // (the gate only recommends).
        $green = $this->service->canPromoteTo(4, 3, true);
        $this->assertTrue($green['allowed']);
        $this->assertSame(
            AtlasVoxOperationalThinkingInterfaceService::PROMOTION_ALLOWED,
            $green['decision']
        );
        $this->assertTrue($green['requires_explicit_vitor_approval']);
    }

    public function testPromotionWithinAuthorizedV0V3PhaseNeedsNoGateAndNoVitorApproval(): void
    {
        // V2 -> V3 is inside the authorised local-first phase: no GATE V3, no approval.
        $within = $this->service->canPromoteTo(3, 2, false);
        $this->assertTrue($within['allowed']);
        $this->assertSame(
            AtlasVoxOperationalThinkingInterfaceService::PROMOTION_ALLOWED,
            $within['decision']
        );
        $this->assertFalse($within['gate_v3_required']);
        $this->assertFalse($within['requires_explicit_vitor_approval']);

        // Going to the same/lower level never counts as a promotion.
        $noop = $this->service->canPromoteTo(3, 3, true);
        $this->assertFalse($noop['allowed']);
        $this->assertSame(
            AtlasVoxOperationalThinkingInterfaceService::PROMOTION_NOOP,
            $noop['decision']
        );
    }

    public function testNegativeMultiplierAndSafetyBreachesTriggerStopTheLine(): void
    {
        // Lei 10: a multiplier below 1.0 (Vox worse than direct/dictation) stops the line.
        $negative = $this->service->evaluateStopTheLine(['voice_multiplier' => 0.8]);
        $this->assertTrue($negative['stop_the_line']);
        $this->assertSame('lei_vox_10', $negative['violations'][0]['law']);

        // Hard-zero breaches (audio cru persisted, destructive without receipt,
        // confirmation bypass, provider direto) each stop the line.
        $breach = $this->service->evaluateStopTheLine([
            'destructive_action_without_receipt' => 1,
            'raw_audio_persisted_count' => 2,
            'confirmation_bypass_count' => 1,
            'provider_direct_call_count' => 1,
        ]);
        $this->assertTrue($breach['stop_the_line']);
        $violatedLaws = array_column($breach['violations'], 'law');
        $this->assertContains('lei_vox_2', $violatedLaws);
        $this->assertContains('lei_vox_3', $violatedLaws);
        $this->assertContains('lei_vox_4', $violatedLaws);
        $this->assertContains('lei_vox_6', $violatedLaws);

        // The documented safe/zero baseline does NOT stop the line.
        $clean = $this->service->evaluateStopTheLine();
        $this->assertFalse($clean['stop_the_line']);
        $this->assertSame([], $clean['violations']);
    }

    public function testFlowAndMinimumEventsMatchTheDocumentedContract(): void
    {
        // "Fluxo": ten ordered stages, fala -> ... -> output.
        $flow = $this->service->flow();
        $this->assertSame(10, $flow['count']);
        $this->assertSame('fala', $flow['stages'][0]);
        $this->assertSame('output', $flow['stages'][9]);
        $this->assertContains('receipt', $flow['stages']);

        // "Eventos Minimos": the twelve canonical VOX_* events.
        $events = $this->service->minimumEvents();
        $this->assertSame(12, $events['count']);
        $this->assertContains('VOX_SESSION_STARTED', $events['events']);
        $this->assertContains('VOX_ACTION_BLOCKED', $events['events']);
        $this->assertContains('VOX_ECLIPSE_ACTIVATED', $events['events']);
    }
}
