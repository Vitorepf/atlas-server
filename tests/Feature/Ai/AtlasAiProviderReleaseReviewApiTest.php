<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

class AtlasAiProviderReleaseReviewApiTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_api_classifies_provider_release_without_policy_change(): void
    {
        $this->getJson('/ai/provider-release-review?provider=anthropic&title=Anthropic%20Finance%20Agents&type=vertical_agents&domain[]=finance&capability[]=pitch_builder&connector[]=factset', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema_version', 'atlas.provider_release_review.v1')
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('release_envelope.schema_version', 'atlas.provider_release.v1')
            ->assertJsonPath('release_envelope.provider', 'anthropic')
            ->assertJsonPath('release_envelope.release_id', 'anthropic-finance-agents-2026-05')
            ->assertJsonPath('release_envelope.release_type', 'vertical_agents')
            ->assertJsonPath('release_envelope.threat_to_atlas', 'low')
            ->assertJsonPath('release_envelope.potential_multiplier', 'high')
            ->assertJsonPath('recommended_action', 'benchmark')
            ->assertJsonPath('rivals_required', true)
            ->assertJsonPath('decide_signal.signal_only', true)
            ->assertJsonPath('decide_signal.changes_routing', false);
    }

    public function test_api_requires_atlas_token(): void
    {
        $this->getJson('/ai/provider-release-review?title=Anthropic%20Finance%20Agents')
            ->assertUnauthorized();
    }

    public function test_api_requires_title(): void
    {
        $this->getJson('/ai/provider-release-review?provider=anthropic', $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }
}
