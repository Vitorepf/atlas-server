<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;
use App\Services\Ai\Kernel\Architecture\ProviderReleaseSourceTrustPolicy;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasProviderReleaseSourceRegistryTest extends TestCase
{
    public function test_registry_exposes_daily_tier_one_official_sources_without_runtime_authority(): void
    {
        $summary = app(AtlasProviderReleaseSourceRegistry::class)->summary([
            'tier' => ProviderReleaseSourceTrustPolicy::TIER_PRIMARY,
            'cadence' => 'daily',
        ]);

        $this->assertSame('atlas.provider_release.source_registry.v1', $summary['schema_version']);
        $this->assertSame('ok', $summary['status']);
        $this->assertSame('read_only_registry', $summary['mode']);
        $this->assertSame('watchlist_only_no_release_ingestion', $summary['authority']);
        $this->assertFalse(data_get($summary, 'guardrails.network_fetching_enabled'));
        $this->assertFalse(data_get($summary, 'guardrails.writes_release_envelope'));
        $this->assertFalse(data_get($summary, 'guardrails.writes_policy'));
        $this->assertFalse(data_get($summary, 'guardrails.changes_routing'));
        $this->assertFalse(data_get($summary, 'guardrails.writes_decide_signal'));
        $this->assertFalse(data_get($summary, 'guardrails.auto_ingestion_allowed'));
        $this->assertSame('atlas.provider_release.future_activation_review.v1', data_get($summary, 'future_activation_review_contract.schema_version'));
        $this->assertSame('blocked_until_dedicated_AP', data_get($summary, 'future_activation_review_contract.status'));
        $this->assertFalse(data_get($summary, 'future_activation_review_contract.network_fetching_enabled'));
        $this->assertFalse(data_get($summary, 'future_activation_review_contract.auto_decide_signal_allowed'));
        $this->assertContains('dedicated_AP_for_fetch_runtime', data_get($summary, 'future_activation_review_contract.requires'));
        $this->assertContains('human_review_before_any_decide_or_policy_signal', data_get($summary, 'future_activation_review_contract.requires'));
        $this->assertContains('background_web_crawler', data_get($summary, 'future_activation_review_contract.blocked_targets'));
        $this->assertContains('direct_decide_signal', data_get($summary, 'future_activation_review_contract.blocked_targets'));
        $this->assertSame(
            data_get($summary, 'future_activation_review_contract'),
            data_get($summary, 'continuous_ingestion_contract.future_activation_review_contract'),
        );

        $ids = array_column($summary['sources'], 'id');

        $this->assertContains('anthropic_research', $ids);
        $this->assertContains('anthropic_news', $ids);
        $this->assertContains('openai_api_changelog', $ids);
        $this->assertContains('google_gemini_api_changelog', $ids);
        $this->assertContains('cursor_changelog', $ids);
        $this->assertContains('github_copilot_changelog', $ids);
        $this->assertGreaterThanOrEqual(19, $summary['source_count']);
    }

    public function test_registry_filters_by_provider_and_track(): void
    {
        $summary = app(AtlasProviderReleaseSourceRegistry::class)->summary([
            'provider' => 'anthropic',
            'track' => 'managed_agents',
        ]);

        $this->assertSame(1, $summary['source_count']);
        $this->assertSame('anthropic_news', data_get($summary, 'sources.0.id'));
        $this->assertSame('https://www.anthropic.com/news', data_get($summary, 'sources.0.url'));
        $this->assertTrue(data_get($summary, 'sources.0.trust.can_create_release_envelope_draft'));
        $this->assertFalse(data_get($summary, 'sources.0.trust.primary_source_required'));
    }

    public function test_candidate_from_primary_source_can_only_create_review_draft(): void
    {
        $candidate = app(AtlasProviderReleaseSourceRegistry::class)->candidateFromDetection(
            url: 'https://www.anthropic.com/news/finance-agents',
            title: 'Anthropic Finance Agents',
            contentHash: 'hash-fixture',
            publishedAt: '2026-05-05T00:00:00Z',
        );

        $this->assertSame('atlas.provider_release.source_candidate.v1', $candidate['schema_version']);
        $this->assertSame('candidate', $candidate['status']);
        $this->assertSame('read_only_candidate', $candidate['mode']);
        $this->assertSame('anthropic_news', data_get($candidate, 'source.id'));
        $this->assertSame('tier_1_official', data_get($candidate, 'source_trust.tier'));
        $this->assertTrue(data_get($candidate, 'source_trust.can_create_release_envelope_draft'));
        $this->assertFalse(data_get($candidate, 'source_trust.primary_source_required'));
        $this->assertSame('vertical_agents', $candidate['release_type']);
        $this->assertSame('https://www.anthropic.com/news/finance-agents', $candidate['canonical_url']);
        $this->assertContains('finance_vertical_agent', $candidate['possible_capabilities']);
        $this->assertSame('run_provider_release_review', $candidate['recommended_triage_action']);
        $this->assertContains('No Atlas Decide routing changes from source detection.', $candidate['non_goals']);
    }

    public function test_unmatched_or_weak_signal_requires_primary_source_and_cannot_affect_decide(): void
    {
        $candidate = app(AtlasProviderReleaseSourceRegistry::class)->candidateFromDetection(
            url: 'https://example.com/rumor/claude-secret-model',
            title: 'Rumor: Claude secret model',
        );

        $this->assertSame('unmatched_source', $candidate['status']);
        $this->assertSame('unmatched_source', data_get($candidate, 'source.id'));
        $this->assertSame('tier_3_weak_signal', data_get($candidate, 'source_trust.tier'));
        $this->assertFalse(data_get($candidate, 'source_trust.can_create_release_envelope_draft'));
        $this->assertTrue(data_get($candidate, 'source_trust.primary_source_required'));
        $this->assertFalse(data_get($candidate, 'source_trust.decide_signal_allowed'));
        $this->assertSame('treat_as_unconfirmed_signal', $candidate['recommended_triage_action']);
        $this->assertContains('policy_patch', data_get($candidate, 'source_trust.prohibited_outputs'));
        $this->assertContains('provider_routing_change', data_get($candidate, 'source_trust.prohibited_outputs'));
    }

    public function test_trust_policy_keeps_source_watch_outputs_non_operational(): void
    {
        $policy = app(ProviderReleaseSourceTrustPolicy::class);
        $tierOne = $policy->evaluate('official');
        $tierTwo = $policy->evaluate('technical');
        $tierThree = $policy->evaluate('newsletter');

        $this->assertSame('tier_1_official', $tierOne['tier']);
        $this->assertSame('tier_2_technical', $tierTwo['tier']);
        $this->assertSame('tier_3_weak_signal', $tierThree['tier']);
        $this->assertContains('provider_release_envelope_draft', $tierOne['allowed_outputs']);
        $this->assertNotContains('provider_release_envelope_draft', $tierTwo['allowed_outputs']);
        $this->assertNotContains('provider_release_envelope_draft', $tierThree['allowed_outputs']);

        foreach (['code_change', 'policy_patch', 'provider_routing_change', 'memory_core_promotion'] as $blockedOutput) {
            $this->assertContains($blockedOutput, $tierOne['prohibited_outputs']);
            $this->assertContains($blockedOutput, $tierTwo['prohibited_outputs']);
            $this->assertContains($blockedOutput, $tierThree['prohibited_outputs']);
        }
    }

    public function test_offline_candidate_fixture_corpus_matches_registry_policy(): void
    {
        $registry = app(AtlasProviderReleaseSourceRegistry::class);
        $fixtures = json_decode(
            File::get(base_path('tests/Fixtures/Ai/provider-release-source-candidates.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($fixtures as $fixture) {
            $candidate = $registry->candidateFromDetection(
                url: $fixture['url'],
                title: $fixture['title'],
                contentHash: $fixture['content_hash'],
                publishedAt: $fixture['published_at'],
            );

            $this->assertSame($fixture['expected']['source_id'], data_get($candidate, 'source.id'), $fixture['name']);
            $this->assertSame($fixture['expected']['tier'], data_get($candidate, 'source_trust.tier'), $fixture['name']);
            $this->assertSame($fixture['expected']['release_type'], $candidate['release_type'], $fixture['name']);
            $this->assertSame($fixture['expected']['recommended_triage_action'], $candidate['recommended_triage_action'], $fixture['name']);
            $this->assertSame($fixture['expected']['can_create_release_envelope_draft'], data_get($candidate, 'source_trust.can_create_release_envelope_draft'), $fixture['name']);
            $this->assertSame($fixture['expected']['primary_source_required'], data_get($candidate, 'source_trust.primary_source_required'), $fixture['name']);
            $this->assertSame($fixture['content_hash'], $candidate['content_hash'], $fixture['name']);
        }
    }
}
