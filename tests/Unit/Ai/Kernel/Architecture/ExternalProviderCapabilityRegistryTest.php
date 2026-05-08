<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\ExternalProviderCapabilityRegistry;
use Tests\TestCase;

class ExternalProviderCapabilityRegistryTest extends TestCase
{
    public function test_registry_lists_external_capabilities_without_runtime_authority(): void
    {
        $summary = app(ExternalProviderCapabilityRegistry::class)->summary();

        $this->assertSame('atlas.external_provider_capability_registry.v1', $summary['schema_version']);
        $this->assertSame('ok', $summary['status']);
        $this->assertSame('read_only_registry', $summary['mode']);
        $this->assertSame('capability_inventory_only_no_runtime_execution', $summary['authority']);
        $this->assertFalse(data_get($summary, 'guardrails.network_fetching_enabled'));
        $this->assertFalse(data_get($summary, 'guardrails.runtime_execution_enabled'));
        $this->assertFalse(data_get($summary, 'guardrails.writes_policy'));
        $this->assertFalse(data_get($summary, 'guardrails.changes_routing'));
        $this->assertFalse(data_get($summary, 'guardrails.writes_memory_core'));
        $this->assertGreaterThanOrEqual(5, $summary['capability_count']);

        $ids = array_column($summary['capabilities'], 'id');

        $this->assertContains('anthropic_claude_dreams', $ids);
        $this->assertContains('anthropic_finance_agents', $ids);
        $this->assertContains('openai_realtime_agents', $ids);
        $this->assertContains('google_gemini_long_context', $ids);
        $this->assertContains('cursor_coding_agent_modes', $ids);
    }

    public function test_dreams_capability_is_proposal_only_and_memory_gated(): void
    {
        $candidate = app(ExternalProviderCapabilityRegistry::class)->adoptionCandidate('anthropic_claude_dreams');

        $this->assertSame('atlas.external_provider_capability_adoption_candidate.v1', $candidate['schema_version']);
        $this->assertSame('candidate', $candidate['status']);
        $this->assertSame('anthropic_claude_dreams', data_get($candidate, 'capability.id'));
        $this->assertSame('provider_dream_memory', data_get($candidate, 'capability.capability_type'));
        $this->assertSame('research_preview', data_get($candidate, 'capability.status'));
        $this->assertSame('proposal_only_atlas_remains_authority', data_get($candidate, 'capability.authority'));
        $this->assertTrue($candidate['review_required']);
        $this->assertFalse($candidate['changes_routing']);
        $this->assertFalse($candidate['writes_policy']);
        $this->assertFalse($candidate['writes_memory_core']);
        $this->assertContains('availability_confirmation', $candidate['promotion_requires']);
        $this->assertContains('memory_diff_review', $candidate['promotion_requires']);
        $this->assertContains('decision_receipt_before_promotion', $candidate['promotion_requires']);
        $this->assertSame('wait_for_operational_access_then_create_proposal_only_adapter', $candidate['recommended_action']);
    }

    public function test_available_vertical_agent_requires_benchmark_before_absorption(): void
    {
        $candidate = app(ExternalProviderCapabilityRegistry::class)->adoptionCandidate('anthropic_finance_agents');

        $this->assertSame('candidate', $candidate['status']);
        $this->assertSame('vertical_agent', data_get($candidate, 'capability.capability_type'));
        $this->assertSame('available', data_get($candidate, 'capability.status'));
        $this->assertContains('finance', data_get($candidate, 'capability.domains'));
        $this->assertSame('benchmark_and_absorb_as_adapter', $candidate['recommended_action']);
        $this->assertContains('rivals_or_domain_benchmark', $candidate['promotion_requires']);
        $this->assertContains('provider_release_review', $candidate['promotion_requires']);
        $this->assertFalse($candidate['changes_routing']);
        $this->assertFalse($candidate['writes_policy']);
    }

    public function test_registry_filters_by_provider_status_type_and_domain(): void
    {
        $registry = app(ExternalProviderCapabilityRegistry::class);

        $anthropic = $registry->summary(['provider' => 'anthropic']);
        $available = $registry->summary(['status' => 'available']);
        $vertical = $registry->summary(['capability_type' => 'vertical_agent']);
        $memory = $registry->summary(['domain' => 'memory']);

        $this->assertSame(2, $anthropic['capability_count']);
        $this->assertGreaterThanOrEqual(3, $available['capability_count']);
        $this->assertSame(1, $vertical['capability_count']);
        $this->assertGreaterThanOrEqual(2, $memory['capability_count']);
        $this->assertSame('anthropic_finance_agents', data_get($vertical, 'capabilities.0.id'));
    }

    public function test_unknown_capability_adoption_fails_closed(): void
    {
        $candidate = app(ExternalProviderCapabilityRegistry::class)->adoptionCandidate('unknown_future_agent');

        $this->assertSame('not_found', $candidate['status']);
        $this->assertTrue($candidate['review_required']);
        $this->assertSame('register_capability_before_review', $candidate['recommended_action']);
        $this->assertFalse($candidate['changes_routing']);
        $this->assertFalse($candidate['writes_policy']);
    }
}
