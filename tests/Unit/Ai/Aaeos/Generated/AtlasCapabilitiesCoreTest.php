<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCapabilitiesCoreService;
use Tests\TestCase;

/**
 * Pins the decidable contracts of the cognitive Capabilities Core doc:
 * evidence-level defaultability (contested/speculative never default), the
 * Anti-Duplication Core-vs-Domain placement rule, the operational restrictions
 * (Multi-Provider Debate never default / heavy-audited only; Confidence
 * Calibration cadence-limited + forbidden in flow; Identity Tracker read-only +
 * no affirmative push; TMR future/hardware-gated), Worked-Example process
 * fading by Dreyfus stage, and the FSRS-over-SM-2 SRS default. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/cognitive/capabilities-core.md
 */
class AtlasCapabilitiesCoreTest extends TestCase
{
    private function service(): AtlasCapabilitiesCoreService
    {
        return new AtlasCapabilitiesCoreService;
    }

    public function test_evidence_level_blocks_contested_and_speculative_from_default(): void
    {
        $service = $this->service();

        // doc: Dual N-Back is `contested` -> "nunca virara default".
        $dualNBack = $service->isDefaultable('dual_n_back_drill');
        $this->assertSame('contested', $dualNBack['evidence']);
        $this->assertFalse($dualNBack['default_ok'], 'contested evidence must never be default');

        // doc: TMR is `speculative`/future -> never default.
        $tmr = $service->isDefaultable('tmr');
        $this->assertSame('speculative', $tmr['evidence']);
        $this->assertFalse($tmr['default_ok']);

        // doc: Spaced Repetition Engine is `consensus` and unrestricted -> defaultable.
        $srs = $service->isDefaultable('spaced_repetition_engine');
        $this->assertSame('consensus', $srs['evidence']);
        $this->assertTrue($srs['default_ok']);

        // Multi-Provider Debate is `emerging` (strong enough) but carries an explicit
        // never_default restriction, so it is still blocked — restriction wins.
        $debate = $service->isDefaultable('multi_provider_debate_engine');
        $this->assertFalse($debate['default_ok']);
        $this->assertSame('restricted_never_default', $debate['reason']);
    }

    public function test_anti_duplication_core_vs_domain_placement(): void
    {
        $service = $this->service();

        // doc: enters Core if serves >1 surface OR >1 domain.
        $this->assertSame('core', $service->placeCapability('x', 1, 2)['placement'], 'multi-domain -> core');
        $this->assertSame('core', $service->placeCapability('x', 3, 1)['placement'], 'multi-surface -> core');

        // doc: single surface AND single domain -> not Core; first fallback is a
        // learning specialist_profile.
        $single = $service->placeCapability('x', 1, 1);
        $this->assertSame('learning_specialist_profile', $single['placement']);

        // doc: cardinal Atlas-unique capability goes to multiplier-edge, not Core —
        // even when it serves many surfaces/domains.
        $cardinal = $service->placeCapability('x', 5, 5, true);
        $this->assertSame('multiplier_edge', $cardinal['placement']);
    }

    public function test_multi_provider_debate_requires_heavy_audited_decision(): void
    {
        $service = $this->service();

        // Default invocation is forbidden.
        $asDefault = $service->guardRestricted('multi_provider_debate_engine', ['is_default' => true]);
        $this->assertFalse($asDefault['allowed']);
        $this->assertContains('invoked_as_default', $asDefault['violations']);
        $this->assertContains('not_heavy_audited_decision', $asDefault['violations']);

        // Heavy + audited, not default -> allowed.
        $ok = $service->guardRestricted('multi_provider_debate_engine', [
            'is_default' => false,
            'heavy_decision' => true,
            'audited_selection' => true,
        ]);
        $this->assertTrue($ok['allowed'], 'heavy + audited + non-default debate is allowed');
    }

    public function test_confidence_calibration_cadence_and_flow_guard(): void
    {
        $service = $this->service();

        // In continuous flow with a disallowed cadence context -> two violations.
        $blocked = $service->guardRestricted('confidence_calibration_drill', [
            'continuous_flow' => true,
            'cadence_context' => 'daily_plan',
        ]);
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('invoked_in_continuous_flow', $blocked['violations']);
        $this->assertContains('cadence_context_not_allowed', $blocked['violations']);

        // Allowed cadence (mastery_review), not in flow -> allowed.
        $allowed = $service->guardRestricted('confidence_calibration_drill', [
            'continuous_flow' => false,
            'cadence_context' => 'mastery_review',
        ]);
        $this->assertTrue($allowed['allowed']);
    }

    public function test_identity_tracker_is_read_only_and_no_affirmative_push(): void
    {
        $service = $this->service();

        $violating = $service->guardRestricted('identity_tracker', [
            'mutates' => true,
            'affirmative_push' => true,
        ]);
        $this->assertFalse($violating['allowed']);
        $this->assertContains('mutation_forbidden_read_only', $violating['violations']);
        $this->assertContains('affirmative_push_forbidden', $violating['violations']);

        // Pure read-only observation -> allowed.
        $observe = $service->guardRestricted('identity_tracker', [
            'mutates' => false,
            'affirmative_push' => false,
        ]);
        $this->assertTrue($observe['allowed']);
    }

    public function test_worked_example_process_fading_by_dreyfus_stage(): void
    {
        $service = $this->service();

        // doc: explicit step-by-step for novice; partial case for competent; raw case for proficient+.
        $this->assertSame('full_step_by_step', $service->resolveProcessFading('novice')['fade_level']);
        $this->assertFalse($service->resolveProcessFading('novice')['steps_removed']);

        $this->assertSame('partial_case', $service->resolveProcessFading('competent')['fade_level']);

        $proficient = $service->resolveProcessFading('proficient');
        $this->assertSame('raw_case', $proficient['fade_level']);
        $this->assertTrue($proficient['steps_removed'], 'proficient+ gets raw case with steps removed');
    }

    public function test_srs_default_is_fsrs_and_rejects_sm2(): void
    {
        $service = $this->service();

        // doc decision: "SRS default e FSRS (consensus); SM-2 deprecado".
        $default = $service->resolveSrsAlgorithm();
        $this->assertSame('fsrs', $default['algorithm']);
        $this->assertFalse($default['deprecated_rejected']);

        $sm2 = $service->resolveSrsAlgorithm('SM-2');
        $this->assertSame('fsrs', $sm2['algorithm'], 'SM-2 request is forced back to FSRS');
        $this->assertTrue($sm2['deprecated_rejected']);
        $this->assertSame('sm2_deprecated_forced_fsrs', $sm2['reason']);
    }
}
