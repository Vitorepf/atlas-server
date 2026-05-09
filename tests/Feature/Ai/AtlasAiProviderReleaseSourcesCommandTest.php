<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiProviderReleaseSourcesCommandTest extends TestCase
{
    public function test_provider_release_sources_lists_official_watchlist_without_runtime_authority(): void
    {
        $exit = Artisan::call('atlas:ai:provider-release-sources', [
            '--tier' => 'official',
            '--cadence' => 'daily',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.provider_release.source_registry.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('read_only_registry', $payload['mode']);
        $this->assertSame('watchlist_only_no_release_ingestion', $payload['authority']);
        $this->assertFalse(data_get($payload, 'guardrails.network_fetching_enabled'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_release_envelope'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_policy'));
        $this->assertFalse(data_get($payload, 'guardrails.changes_routing'));
        $this->assertSame('atlas.provider_release.continuous_ingestion_contract.v1', data_get($payload, 'continuous_ingestion_contract.schema_version'));
        $this->assertSame('planned_fail_closed', data_get($payload, 'continuous_ingestion_contract.status'));
        $this->assertFalse(data_get($payload, 'continuous_ingestion_contract.network_fetching_enabled'));
        $this->assertFalse(data_get($payload, 'continuous_ingestion_contract.changes_decide_policy'));
        $this->assertContains('background_web_crawler_without_AP', data_get($payload, 'continuous_ingestion_contract.forbidden_shortcuts'));

        $ids = array_column($payload['sources'], 'id');

        $this->assertContains('anthropic_news', $ids);
        $this->assertContains('openai_api_changelog', $ids);
        $this->assertContains('google_gemini_api_changelog', $ids);
        $this->assertGreaterThanOrEqual(19, $payload['source_count']);
    }

    public function test_provider_release_sources_can_preview_candidate_from_primary_source(): void
    {
        $exit = Artisan::call('atlas:ai:provider-release-sources', [
            '--url' => 'https://www.anthropic.com/news/finance-agents',
            '--title' => 'Anthropic Finance Agents',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('read_only_candidate_preview', $payload['mode']);
        $this->assertSame('candidate', data_get($payload, 'candidate.status'));
        $this->assertSame('anthropic_news', data_get($payload, 'candidate.source.id'));
        $this->assertSame('tier_1_official', data_get($payload, 'candidate.source_trust.tier'));
        $this->assertTrue(data_get($payload, 'candidate.source_trust.can_create_release_envelope_draft'));
        $this->assertTrue(data_get($payload, 'candidate.source_trust.decide_signal_allowed'));
        $this->assertSame('vertical_agents', data_get($payload, 'candidate.release_type'));
        $this->assertSame('run_provider_release_review', data_get($payload, 'candidate.recommended_triage_action'));
        $this->assertContains('No Atlas Decide routing changes from source detection.', data_get($payload, 'candidate.non_goals'));
        $this->assertSame('proposal_only_no_network_no_writes', data_get($payload, 'continuous_ingestion_contract.mode'));
    }

    public function test_provider_release_sources_human_output_is_read_only(): void
    {
        $exit = Artisan::call('atlas:ai:provider-release-sources', [
            '--provider' => 'anthropic',
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Provider Release Sources', $output);
        $this->assertStringContainsString('read_only_registry', $output);
        $this->assertStringContainsString('Network fetching', $output);
        $this->assertStringContainsString('Routing changes', $output);
    }
}
