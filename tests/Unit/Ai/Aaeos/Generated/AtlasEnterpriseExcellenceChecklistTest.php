<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasEnterpriseExcellenceChecklistService;
use Tests\TestCase;

/**
 * Pins the executable contract from the doc: the 13-item "Must Have" gate, the 8
 * "State Of Art Targets" (each with its own comparator — >80% strictly, ==0
 * exactly, <24h strictly), the 8-step "Ultra-Enterprise Bar" (all repeatable, and
 * "avoid silent unsafe autonomy" is safety-critical), and the proposal-first
 * autonomy posture from "Current Posture". Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/research-enterprise-excellence-checklist.md
 */
class AtlasEnterpriseExcellenceChecklistTest extends TestCase
{
    private function service(): AtlasEnterpriseExcellenceChecklistService
    {
        return new AtlasEnterpriseExcellenceChecklistService;
    }

    /** Helper: a fully-satisfied 13-item must-have checklist. */
    private function fullMustHave(): array
    {
        $out = [];
        foreach (AtlasEnterpriseExcellenceChecklistService::MUST_HAVE_ITEMS as $item) {
            $out[$item] = true;
        }

        return $out;
    }

    /** Helper: measurements that meet every state-of-art target. */
    private function passingMeasurements(): array
    {
        return [
            'primary_source_ratio_for_critical_claims' => 0.92,
            'hallucinated_source_rate' => 0.0,
            'research_to_doc_promotion_hours_p0' => 9.0,
            'high_risk_implementation_before_doc_or_ap_count' => 0,
            'rework_from_weak_research_trends_downward' => true,
            'self_improvement_proposal_false_positive_trends_downward' => true,
            'retrieval_long_session_improvements_have_evidence' => true,
            'provider_release_absorption_passes_source_gate' => true,
        ];
    }

    /** Helper: all 8 ultra-enterprise capabilities repeatable. */
    private function fullBar(): array
    {
        $out = [];
        foreach (AtlasEnterpriseExcellenceChecklistService::ULTRA_ENTERPRISE_CAPABILITIES as $capability) {
            $out[$capability] = true;
        }

        return $out;
    }

    public function test_must_have_requires_all_thirteen_items(): void
    {
        // Doc "Must Have": 13 items; the cycle is complete only when all hold.
        $svc = $this->service();
        $this->assertCount(13, AtlasEnterpriseExcellenceChecklistService::MUST_HAVE_ITEMS);

        $full = $svc->evaluateMustHave($this->fullMustHave());
        $this->assertTrue($full['complete']);
        $this->assertSame([], $full['missing']);
        $this->assertSame(13, $full['satisfied_count']);

        // Drop the doc-before-code item and blank the diff check -> incomplete,
        // both reported by name.
        $partial = $this->fullMustHave();
        unset($partial['canonical_doc_updated_before_structural_code']);
        $partial['diff_check_clean'] = false;

        $r = $svc->evaluateMustHave($partial);
        $this->assertFalse($r['complete']);
        $this->assertEqualsCanonicalizing(
            ['canonical_doc_updated_before_structural_code', 'diff_check_clean'],
            $r['missing'],
        );
        $this->assertSame(11, $r['satisfied_count']);
    }

    public function test_state_of_art_targets_enforce_their_specific_comparators(): void
    {
        // Doc "State Of Art Targets": ratio strictly >80%, hallucinated rate ==0,
        // P0 promotion strictly <24h. Boundary values must FAIL, not pass.
        $svc = $this->service();
        $this->assertCount(8, AtlasEnterpriseExcellenceChecklistService::STATE_OF_ART_TARGETS);

        $this->assertTrue($svc->evaluateStateOfArtTargets($this->passingMeasurements())['all_targets_met']);

        // Exactly 0.80 is NOT "above 80%" -> unmet.
        $atBoundary = $this->passingMeasurements();
        $atBoundary['primary_source_ratio_for_critical_claims'] = 0.80;
        $ratioBoundary = $svc->evaluateStateOfArtTargets($atBoundary);
        $this->assertFalse($ratioBoundary['all_targets_met']);
        $this->assertContains('primary_source_ratio_for_critical_claims', $ratioBoundary['unmet']);

        // Exactly 24.0h is NOT "below 24h" -> unmet.
        $atHours = $this->passingMeasurements();
        $atHours['research_to_doc_promotion_hours_p0'] = 24.0;
        $hoursBoundary = $svc->evaluateStateOfArtTargets($atHours);
        $this->assertContains('research_to_doc_promotion_hours_p0', $hoursBoundary['unmet']);

        // Any hallucinated source at all (rate > 0) -> unmet; the bar is exactly 0.
        $hall = $this->passingMeasurements();
        $hall['hallucinated_source_rate'] = 0.01;
        $hallResult = $svc->evaluateStateOfArtTargets($hall);
        $this->assertContains('hallucinated_source_rate', $hallResult['unmet']);
    }

    public function test_state_of_art_missing_measurement_counts_as_unmet(): void
    {
        // Absence of evidence cannot pass the bar: an unsupplied target is unmet
        // and listed as missing-measurement.
        $svc = $this->service();

        $partial = $this->passingMeasurements();
        unset($partial['retrieval_long_session_improvements_have_evidence']);

        $r = $svc->evaluateStateOfArtTargets($partial);
        $this->assertFalse($r['all_targets_met']);
        $this->assertContains('retrieval_long_session_improvements_have_evidence', $r['missing_measurement']);
        $this->assertContains('retrieval_long_session_improvements_have_evidence', $r['unmet']);
    }

    public function test_ultra_enterprise_bar_needs_all_eight_and_flags_safety_breach(): void
    {
        // Doc "Ultra-Enterprise Bar": all 8 capabilities must be repeatable.
        $svc = $this->service();
        $this->assertCount(8, AtlasEnterpriseExcellenceChecklistService::ULTRA_ENTERPRISE_CAPABILITIES);

        $reached = $svc->evaluateUltraEnterpriseBar($this->fullBar());
        $this->assertTrue($reached['bar_reached']);
        $this->assertFalse($reached['safety_breach']);

        // A non-safety capability missing -> bar not reached, but not a breach.
        $bar = $this->fullBar();
        $bar['measure_effect'] = false;
        $notReached = $svc->evaluateUltraEnterpriseBar($bar);
        $this->assertFalse($notReached['bar_reached']);
        $this->assertFalse($notReached['safety_breach']);
        $this->assertContains('measure_effect', $notReached['not_repeatable']);

        // "avoid silent unsafe autonomy" missing -> bar not reached AND safety breach.
        $unsafe = $this->fullBar();
        $unsafe['avoid_silent_unsafe_autonomy'] = false;
        $breach = $svc->evaluateUltraEnterpriseBar($unsafe);
        $this->assertFalse($breach['bar_reached']);
        $this->assertTrue($breach['safety_breach']);
    }

    public function test_autonomy_gate_is_proposal_first_and_gated_on_required_next_step(): void
    {
        // Doc "Current Posture": autonomy may not run until a read-only research
        // packet/schema AND a source gate exist; even then it is proposal-first.
        $svc = $this->service();

        // Missing source gate -> blocked pending the required next step.
        $blocked = $svc->evaluateAutonomyGate([
            'read_only_research_packet_schema_exists' => true,
            'source_gate_exists' => false,
        ], proposalFirst: true);
        $this->assertFalse($blocked['may_run']);
        $this->assertSame('blocked_pending_required_next_step', $blocked['posture']);
        $this->assertContains('source_gate_exists', $blocked['missing_preconditions']);

        // Both preconditions met + proposal-first -> allowed in proposal-first mode.
        $allowed = $svc->evaluateAutonomyGate([
            'read_only_research_packet_schema_exists' => true,
            'source_gate_exists' => true,
        ], proposalFirst: true);
        $this->assertTrue($allowed['may_run']);
        $this->assertSame('proposal_first', $allowed['posture']);

        // Preconditions met but a request for silent (non-proposal) autonomy is
        // denied as unsafe autonomy.
        $unsafe = $svc->evaluateAutonomyGate([
            'read_only_research_packet_schema_exists' => true,
            'source_gate_exists' => true,
        ], proposalFirst: false);
        $this->assertFalse($unsafe['may_run']);
        $this->assertSame('blocked_unsafe_autonomy', $unsafe['posture']);
    }

    public function test_verdict_is_ultra_enterprise_only_when_all_gates_pass(): void
    {
        // End-to-end: must-have complete + every target met + bar reached -> ultra.
        $svc = $this->service();

        $ultra = $svc->verdict(
            mustHave: $this->fullMustHave(),
            measurements: $this->passingMeasurements(),
            repeatable: $this->fullBar(),
            autonomyPre: ['read_only_research_packet_schema_exists' => true, 'source_gate_exists' => true],
            proposalFirst: true,
        );
        $this->assertTrue($ultra['at_ultra_enterprise_level']);
        $this->assertSame('ultra_enterprise', $ultra['verdict']);

        // Weaken one target (ratio at the 0.80 boundary) -> not ultra-enterprise.
        $weakTargets = $this->passingMeasurements();
        $weakTargets['primary_source_ratio_for_critical_claims'] = 0.79;
        $notUltra = $svc->verdict(
            mustHave: $this->fullMustHave(),
            measurements: $weakTargets,
            repeatable: $this->fullBar(),
        );
        $this->assertFalse($notUltra['at_ultra_enterprise_level']);
        $this->assertSame('not_ultra_enterprise', $notUltra['verdict']);

        // A silent-autonomy safety breach dominates the verdict even if everything
        // else is perfect.
        $unsafeBar = $this->fullBar();
        $unsafeBar['avoid_silent_unsafe_autonomy'] = false;
        $breach = $svc->verdict(
            mustHave: $this->fullMustHave(),
            measurements: $this->passingMeasurements(),
            repeatable: $unsafeBar,
        );
        $this->assertFalse($breach['at_ultra_enterprise_level']);
        $this->assertSame('safety_breach', $breach['verdict']);
    }
}
