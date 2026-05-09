<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiProviderReleaseSourcesApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_lists_provider_release_sources_without_operational_authority(): void
    {
        $this->getJson('/ai/provider-release-sources?provider=anthropic&track=managed_agents', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.provider_release.source_registry.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('mode', 'read_only_registry')
            ->assertJsonPath('authority', 'watchlist_only_no_release_ingestion')
            ->assertJsonPath('source_count', 1)
            ->assertJsonPath('sources.0.id', 'anthropic_news')
            ->assertJsonPath('sources.0.trust.can_create_release_envelope_draft', true)
            ->assertJsonPath('guardrails.network_fetching_enabled', false)
            ->assertJsonPath('guardrails.writes_policy', false)
            ->assertJsonPath('guardrails.changes_routing', false)
            ->assertJsonPath('continuous_ingestion_contract.status', 'planned_fail_closed')
            ->assertJsonPath('continuous_ingestion_contract.network_fetching_enabled', false)
            ->assertJsonPath('continuous_ingestion_contract.changes_decide_policy', false);
    }

    public function test_api_previews_candidate_without_writing_release_envelope(): void
    {
        $this->getJson('/ai/provider-release-sources?url=https://www.anthropic.com/news/finance-agents&title=Anthropic%20Finance%20Agents', $this->headers)
            ->assertOk()
            ->assertJsonPath('mode', 'read_only_candidate_preview')
            ->assertJsonPath('candidate.status', 'candidate')
            ->assertJsonPath('candidate.source.id', 'anthropic_news')
            ->assertJsonPath('candidate.source_trust.tier', 'tier_1_official')
            ->assertJsonPath('candidate.source_trust.can_create_release_envelope_draft', true)
            ->assertJsonPath('candidate.source_trust.decide_signal_allowed', true)
            ->assertJsonPath('candidate.release_type', 'vertical_agents')
            ->assertJsonPath('candidate.recommended_triage_action', 'run_provider_release_review')
            ->assertJsonPath('guardrails.writes_release_envelope', false)
            ->assertJsonPath('continuous_ingestion_contract.mode', 'proposal_only_no_network_no_writes');
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/provider-release-sources')
            ->assertUnauthorized();
    }
}
