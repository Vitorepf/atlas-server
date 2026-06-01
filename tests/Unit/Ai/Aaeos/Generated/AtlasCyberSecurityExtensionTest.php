<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCyberSecurityExtensionService;
use Tests\TestCase;

/**
 * Pins the extension-level governance rules from the doc: scope admission
 * (7 Included vs 8 Excluded), non-confusion surface routing, the autonomy
 * "observe not correct" submission gate, and the 9-step promotion gate.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/cyber-security-extension.md
 */
class AtlasCyberSecurityExtensionTest extends TestCase
{
    private function service(): AtlasCyberSecurityExtensionService
    {
        return new AtlasCyberSecurityExtensionService;
    }

    public function test_included_activity_is_admitted_and_payload_generation_flags_human_review(): void
    {
        $bb = $this->service()->classifyScope(['activity' => 'bug_bounty_automation']);
        $this->assertSame(AtlasCyberSecurityExtensionService::SCOPE_ADMIT, $bb['verdict']);
        $this->assertSame('included', $bb['bucket']);
        $this->assertFalse($bb['human_review_required']);

        // Output Contract: a prepared payload always carries the human-review flag.
        $payload = $this->service()->classifyScope(['activity' => 'bb_payload_generation']);
        $this->assertSame(AtlasCyberSecurityExtensionService::SCOPE_ADMIT, $payload['verdict']);
        $this->assertTrue($payload['human_review_required']);
    }

    public function test_hard_excluded_activities_can_never_be_lifted_by_a_clause(): void
    {
        // Excluded items 2,3,4,7,8 are hard refusals with no exception.
        foreach ([
            'mass_targeting_out_of_scope',
            'supply_chain_offensive',
            'pii_exfiltration',
            'unauthorized_target_operation',
            'autonomous_bb_submission',
        ] as $activity) {
            $d = $this->service()->classifyScope([
                'activity' => $activity,
                // Even handing it an unrelated clause must not lift the refusal.
                'clauses' => ['operator_clause', 'red_team_c2', 'dos_explicit_clause'],
            ]);
            $this->assertSame(
                AtlasCyberSecurityExtensionService::SCOPE_REFUSE,
                $d['verdict'],
                "{$activity} must stay refused"
            );
            $this->assertSame('excluded_hard', $d['bucket']);
            $this->assertNull($d['required_clause']);
        }
    }

    public function test_clause_gated_exclusion_requires_the_named_clause_then_admits(): void
    {
        // DoS is excluded "sem clausula explicita" -> requires_clause without it.
        $without = $this->service()->classifyScope(['activity' => 'dos_resource_exhaustion']);
        $this->assertSame(AtlasCyberSecurityExtensionService::SCOPE_REQUIRES_CLAUSE, $without['verdict']);
        $this->assertSame('dos_explicit_clause', $without['required_clause']);

        // With the named clause it is admitted and forced through human review.
        $with = $this->service()->classifyScope([
            'activity' => 'dos_resource_exhaustion',
            'clauses' => ['dos_explicit_clause'],
        ]);
        $this->assertSame(AtlasCyberSecurityExtensionService::SCOPE_ADMIT, $with['verdict']);
        $this->assertTrue($with['human_review_required']);

        // A wrong clause for EDR/WAF bypass does not lift it.
        $wrongClause = $this->service()->classifyScope([
            'activity' => 'edr_waf_bypass',
            'clauses' => ['dos_explicit_clause'],
        ]);
        $this->assertSame(AtlasCyberSecurityExtensionService::SCOPE_REQUIRES_CLAUSE, $wrongClause['verdict']);
        $this->assertSame('red_team_c2', $wrongClause['required_clause']);
    }

    public function test_unknown_activity_is_conservatively_refused(): void
    {
        $d = $this->service()->classifyScope(['activity' => 'plant_ransomware']);
        $this->assertSame(AtlasCyberSecurityExtensionService::SCOPE_REFUSE, $d['verdict']);
        $this->assertSame('unknown', $d['bucket']);
        $this->assertContains('unknown_activity_conservative_refuse', $d['reasons']);
    }

    public function test_non_confusion_surface_routing(): void
    {
        $svc = $this->service();

        // Defensive-only review -> security domain (not the extension).
        $defensive = $svc->routeSurface(['intent' => 'threat_review', 'offensive' => false]);
        $this->assertSame(AtlasCyberSecurityExtensionService::SURFACE_SECURITY_DOMAIN, $defensive['surface']);
        $this->assertFalse($defensive['is_extension']);

        // Security review of own code -> programming.security (not the extension).
        $own = $svc->routeSurface(['target_is_own_code' => true, 'external_authorized_target' => false]);
        $this->assertSame(AtlasCyberSecurityExtensionService::SURFACE_PROGRAMMING_SECURITY, $own['surface']);

        // Offensive work on an authorized external target -> this extension.
        $offensive = $svc->routeSurface([
            'intent' => 'pentest',
            'offensive' => true,
            'external_authorized_target' => true,
        ]);
        $this->assertSame(AtlasCyberSecurityExtensionService::SURFACE_EXTENSION, $offensive['surface']);
        $this->assertTrue($offensive['is_extension']);
    }

    public function test_submission_gate_blocks_autonomous_submit_until_human_approves(): void
    {
        $svc = $this->service();

        $prepared = $svc->submissionGate(['payload_prepared' => true, 'human_approved' => false]);
        $this->assertFalse($prepared['may_submit']);
        $this->assertSame('route_to_human_review', $prepared['action']);
        $this->assertContains('autonomous_submission_forbidden_requires_human_approval', $prepared['reasons']);

        $approved = $svc->submissionGate(['payload_prepared' => true, 'human_approved' => true]);
        $this->assertTrue($approved['may_submit']);
        $this->assertSame('submit_after_approval', $approved['action']);
    }

    public function test_promotion_gate_requires_all_nine_conditions(): void
    {
        $svc = $this->service();

        // Nothing satisfied -> stays scaffold, all 9 missing.
        $empty = $svc->evaluatePromotion([]);
        $this->assertSame(AtlasCyberSecurityExtensionService::STATUS_SCAFFOLD, $empty['status']);
        $this->assertFalse($empty['promotable']);
        $this->assertSame(9, $empty['total']);
        $this->assertCount(9, $empty['missing']);

        // 8 of 9 satisfied is still NOT promotable.
        $eight = $svc->evaluatePromotion([
            'place_feature_gate_ok' => true,
            'skills_candidate_with_evals' => true,
            'dedicated_orchestrator' => true,
            'profile_registry_migration' => true,
            'onboarding_scorecard_9_of_9' => true,
            'canonical_spec_domain_doc' => true,
            'domains_readme_updated' => true,
            'provider_projections_regenerated' => true,
            // architecture_validate_passes missing
        ]);
        $this->assertSame(AtlasCyberSecurityExtensionService::STATUS_SCAFFOLD, $eight['status']);
        $this->assertFalse($eight['promotable']);
        $this->assertSame(['architecture_validate_passes'], $eight['missing']);

        // All 9 satisfied -> promotes to the cyber domain.
        $all = $svc->evaluatePromotion([
            'place_feature_gate_ok' => true,
            'skills_candidate_with_evals' => true,
            'dedicated_orchestrator' => true,
            'profile_registry_migration' => true,
            'onboarding_scorecard_9_of_9' => true,
            'canonical_spec_domain_doc' => true,
            'domains_readme_updated' => true,
            'provider_projections_regenerated' => true,
            'architecture_validate_passes' => true,
        ]);
        $this->assertSame(AtlasCyberSecurityExtensionService::STATUS_DOMAIN, $all['status']);
        $this->assertTrue($all['promotable']);
        $this->assertSame(9, $all['satisfied_count']);
    }

    public function test_manifest_pins_scope_and_promotion_counts(): void
    {
        $m = $this->service()->manifest();

        $this->assertSame('scaffold', $m['status']);
        $this->assertSame(7, $m['included_count']);
        $this->assertSame(8, $m['excluded_count']);
        // Excluded items 1 and 5 and 6 are clause-gated; 2,3,4,7,8 are hard.
        $this->assertSame(5, $m['hard_excluded_count']);
        $this->assertSame(3, $m['clause_gated_excluded_count']);
        $this->assertSame(9, $m['promotion_condition_count']);
        $this->assertSame('atlas_observes_does_not_auto_submit', $m['autonomy_invariant']);
    }
}
