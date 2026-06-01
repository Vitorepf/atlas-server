<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDomainOnboardingService;
use Tests\TestCase;

/**
 * Pins the executable rules from the Domain Onboarding doc's "## Domain Test":
 * a candidate is a `domain` only when all seven distinctness criteria are met
 * AND its family is one of the nine canonical families; a pure
 * customer/company/project/product is forced to `business_context`; a distinct
 * but unfamilied candidate needs family review; and a freshly-accepted domain is
 * born on the first maturity rung (scaffold) and may not self-promote. Pure, no
 * DB.
 *
 * @see docs/engineering-knowledge-base/master-architecture/domain-onboarding.md
 */
class AtlasDomainOnboardingTest extends TestCase
{
    private function service(): AtlasDomainOnboardingService
    {
        return new AtlasDomainOnboardingService;
    }

    /**
     * A candidate with all seven criteria distinct and no business-context flags.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function distinctCandidate(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Marketing',
            'intents_and_flows' => true,
            'context_model' => true,
            'specialist_profiles' => true,
            'tools_or_runtimes' => true,
            'gates_and_evidence' => true,
            'memory_projection' => true,
            'learning_loop' => true,
            'family' => 'Marketing',
        ], $overrides);
    }

    public function test_fully_distinct_candidate_in_canonical_family_is_a_domain(): void
    {
        $decision = $this->service()->classify($this->distinctCandidate());

        $this->assertSame(AtlasDomainOnboardingService::VERDICT_DOMAIN, $decision['verdict']);
        $this->assertTrue($decision['is_domain']);
        // All 7 of 7 criteria met, none missing.
        $this->assertSame(7, $decision['criteria_met']);
        $this->assertSame(7, $decision['criteria_total']);
        $this->assertSame([], $decision['missing_criteria']);
        $this->assertTrue($decision['family']['valid']);
        $this->assertSame('marketing', $decision['family']['canonical']);
        // A newborn domain starts on the first rung, never above it.
        $this->assertSame('scaffold', $decision['initial_maturity']);
    }

    public function test_missing_one_criterion_makes_it_not_a_domain_and_names_the_gap(): void
    {
        // Drop the learning loop only: 6 of 7 -> still not a domain.
        $decision = $this->service()->classify($this->distinctCandidate(['learning_loop' => false]));

        $this->assertSame(AtlasDomainOnboardingService::VERDICT_NOT_A_DOMAIN, $decision['verdict']);
        $this->assertFalse($decision['is_domain']);
        $this->assertSame(6, $decision['criteria_met']);
        $this->assertSame(['learning_loop'], $decision['missing_criteria']);
        // Not accepted -> no maturity rung assigned.
        $this->assertNull($decision['initial_maturity']);
    }

    public function test_pure_company_is_forced_to_business_context_even_when_criteria_pass(): void
    {
        // Doc: "If it is only a customer, company, project or product, it is
        // Business Context." The override beats a fully-distinct checklist.
        $decision = $this->service()->classify($this->distinctCandidate([
            'name' => 'Blackink',
            'is_company' => true,
        ]));

        $this->assertSame(AtlasDomainOnboardingService::VERDICT_BUSINESS_CONTEXT, $decision['verdict']);
        $this->assertFalse($decision['is_domain']);
        $this->assertTrue($decision['business_context_override']);
        $this->assertContains('is_company', $decision['business_context_signals']);
        $this->assertNull($decision['initial_maturity']);
    }

    public function test_distinct_candidate_outside_canonical_families_needs_review(): void
    {
        // All 7 criteria distinct but family is not one of the nine canonical
        // families -> not silently accepted.
        $decision = $this->service()->classify($this->distinctCandidate([
            'name' => 'Astrology',
            'family' => 'astrology',
        ]));

        $this->assertSame(AtlasDomainOnboardingService::VERDICT_NEEDS_FAMILY_REVIEW, $decision['verdict']);
        $this->assertFalse($decision['is_domain']);
        $this->assertSame([], $decision['missing_criteria']);
        $this->assertFalse($decision['family']['valid']);
        $this->assertNull($decision['family']['canonical']);
    }

    public function test_family_display_aliases_resolve_to_the_closed_set(): void
    {
        // The doc spells two families with slashes/dashes; both must resolve.
        $cognitive = $this->service()->classify($this->distinctCandidate(['family' => 'Cognitive/Learning']));
        $this->assertSame(AtlasDomainOnboardingService::VERDICT_DOMAIN, $cognitive['verdict']);
        $this->assertSame('cognitive_learning', $cognitive['family']['canonical']);

        $curator = $this->service()->classify($this->distinctCandidate(['family' => 'Self-Improvement/Curator']));
        $this->assertSame(AtlasDomainOnboardingService::VERDICT_DOMAIN, $curator['verdict']);
        $this->assertSame('self_improvement_curator', $curator['family']['canonical']);
    }

    public function test_maturity_ladder_clamps_new_domain_to_scaffold_and_normalises_garbage(): void
    {
        $svc = $this->service();

        // A new domain asking for "enterprise" is clamped down to scaffold.
        $newEnterprise = $svc->resolveMaturity('enterprise', isNewDomain: true);
        $this->assertSame('scaffold', $newEnterprise['normalized']);
        $this->assertSame(0, $newEnterprise['index']);
        $this->assertTrue($newEnterprise['clamped_to_initial']);

        // An existing domain may legitimately sit at "ready" (rung index 2).
        $existingReady = $svc->resolveMaturity('ready', isNewDomain: false);
        $this->assertSame('ready', $existingReady['normalized']);
        $this->assertSame(2, $existingReady['index']);
        $this->assertFalse($existingReady['clamped_to_initial']);

        // An unknown rung normalises to the first valid rung.
        $garbage = $svc->resolveMaturity('superstar', isNewDomain: false);
        $this->assertSame('scaffold', $garbage['normalized']);
        $this->assertSame(0, $garbage['index']);
    }
}
