<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiProviderReleaseReviewCommandTest extends TestCase
{
    public function test_provider_release_review_classifies_anthropic_finance_agents(): void
    {
        $exit = Artisan::call('atlas:ai:provider-release-review', [
            '--provider' => 'anthropic',
            '--title' => 'Anthropic Finance Agents',
            '--url' => 'https://www.anthropic.com/news/finance-agents',
            '--type' => 'vertical_agents',
            '--domain' => ['finance'],
            '--capability' => ['pitch_builder', 'kyc_screener'],
            '--connector' => ['factset', 'capital_iq'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.provider_release_review.v1', $payload['schema_version']);
        $this->assertSame('atlas.provider_release.v1', data_get($payload, 'release_envelope.schema_version'));
        $this->assertSame('anthropic', data_get($payload, 'release_envelope.provider'));
        $this->assertSame('anthropic-finance-agents-2026-05', data_get($payload, 'release_envelope.release_id'));
        $this->assertSame('vertical_agents', data_get($payload, 'release_envelope.release_type'));
        $this->assertContains('finance', data_get($payload, 'release_envelope.affected_domains'));
        $this->assertContains('office', data_get($payload, 'release_envelope.affected_surfaces'));
        $this->assertContains('connector_registry', data_get($payload, 'release_envelope.affected_runtimes'));
        $this->assertContains('pitch_builder', data_get($payload, 'release_envelope.capabilities'));
        $this->assertContains('factset', data_get($payload, 'release_envelope.connectors'));
        $this->assertSame('high', data_get($payload, 'release_envelope.threat_to_wrappers'));
        $this->assertSame('low', data_get($payload, 'release_envelope.threat_to_atlas'));
        $this->assertSame('high', data_get($payload, 'release_envelope.potential_multiplier'));
        $this->assertSame('benchmark', data_get($payload, 'release_envelope.recommended_action'));
        $this->assertSame('benchmark', $payload['recommended_action']);
        $this->assertContains('absorb', $payload['secondary_actions']);
        $this->assertContains('create_domain_skill_pack', $payload['secondary_actions']);
        $this->assertTrue($payload['rivals_required']);
        $this->assertTrue(data_get($payload, 'decide_signal.signal_only'));
        $this->assertFalse(data_get($payload, 'decide_signal.changes_routing'));

        $ownerPaths = array_column($payload['owner_docs'], 'path');
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md', $ownerPaths);
        $this->assertContains('docs/engineering-knowledge-base/domains/finance.md', $ownerPaths);

        $apKinds = array_column($payload['suggested_aps'], 'kind');
        $this->assertContains('benchmark', $apKinds);
        $this->assertContains('domain_skill_pack', $apKinds);
        $this->assertContains('decide_signal', $apKinds);
    }

    public function test_provider_release_review_human_output_is_actionable(): void
    {
        $exit = Artisan::call('atlas:ai:provider-release-review', [
            '--provider' => 'openai',
            '--title' => 'Realtime voice model update',
            '--type' => 'realtime',
            '--domain' => ['programming'],
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Atlas Provider Release Review', $output);
        $this->assertStringContainsString('Provider', $output);
        $this->assertStringContainsString('Rivals required', $output);
        $this->assertStringContainsString('atlas-ai-provider-evolution-intelligence.md', $output);
    }
}
