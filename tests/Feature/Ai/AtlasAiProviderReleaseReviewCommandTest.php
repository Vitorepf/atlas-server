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
        $this->assertSame('draft_allowed_from_primary_source', data_get($payload, 'release_envelope.draft_status'));
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
        $this->assertSame('candidate', data_get($payload, 'source_candidate.status'));
        $this->assertSame('anthropic_news', data_get($payload, 'source_candidate.source.id'));
        $this->assertSame('tier_1_official', data_get($payload, 'source_candidate.source_trust.tier'));
        $this->assertSame('primary_source_verified', data_get($payload, 'source_gate.status'));
        $this->assertTrue(data_get($payload, 'source_gate.can_create_release_envelope_draft'));
        $this->assertFalse(data_get($payload, 'source_gate.primary_source_required'));
        $this->assertSame('read_only_context', data_get($payload, 'source_registry_context.mode'));
        $this->assertSame('anthropic_news', data_get($payload, 'source_registry_context.matched_source_id'));
        $this->assertContains('anthropic_news', data_get($payload, 'source_registry_context.provider_source_ids'));
        $this->assertFalse(data_get($payload, 'source_registry_context.guardrails.network_fetching_enabled'));
        $this->assertContains('absorb', $payload['secondary_actions']);
        $this->assertContains('create_domain_skill_pack', $payload['secondary_actions']);
        $this->assertSame('atlas.provider_release.absorption_plan.v1', data_get($payload, 'absorption_plan.schema_version'));
        $this->assertSame('proposal_ready', data_get($payload, 'absorption_plan.status'));
        $this->assertSame('proposal_only_no_routing_change', data_get($payload, 'absorption_plan.mode'));
        $this->assertSame('finance', data_get($payload, 'absorption_plan.primary_domain'));
        $this->assertSame('primary_source_verified', data_get($payload, 'absorption_plan.source_gate_status'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_rules.may_create_ap'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_rules.may_create_skill_pack'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_rules.may_emit_decide_signal'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_change_routing_policy'));
        $this->assertSame(
            ['release_envelope', 'rivals_benchmark', 'domain_skill_pack', 'decide_signal', 'human_review'],
            collect(data_get($payload, 'absorption_plan.stages'))->pluck('id')->all(),
        );
        $this->assertContains('PROVIDER_RELEASE_REVIEWED', data_get($payload, 'absorption_plan.ledger_events_expected'));
        $this->assertTrue($payload['rivals_required']);
        $this->assertTrue(data_get($payload, 'decide_signal.signal_only'));
        $this->assertFalse(data_get($payload, 'decide_signal.changes_routing'));
        $this->assertTrue(data_get($payload, 'decide_signal.source_trust_allows_signal'));
        $this->assertSame('ready_for_rivals_proposal', data_get($payload, 'review_signal.status'));
        $this->assertSame('high', data_get($payload, 'review_signal.severity'));
        $this->assertSame('create_rivals_ap_before_absorption_or_decide_promotion', data_get($payload, 'review_signal.recommended_action'));
        $this->assertTrue(data_get($payload, 'review_signal.stop_the_line_for_routing'));
        $this->assertTrue(data_get($payload, 'review_signal.proposal_allowed'));
        $this->assertSame('proposal_ready', data_get($payload, 'curator_proposal.status'));
        $this->assertTrue(data_get($payload, 'curator_proposal.proposal_only'));
        $this->assertFalse(data_get($payload, 'curator_proposal.auto_apply'));
        $this->assertSame('self_improvement.provider_release_review', data_get($payload, 'curator_proposal.target_flow'));
        $this->assertSame('atlas.provider_release.absorption_plan.v1', data_get($payload, 'curator_proposal.absorption_plan_ref.schema_version'));
        $this->assertSame('proposal_ready', data_get($payload, 'curator_proposal.absorption_plan_ref.status'));
        $this->assertSame(5, data_get($payload, 'curator_proposal.absorption_plan_ref.stage_count'));
        $this->assertSame('release_envelope', data_get($payload, 'curator_proposal.absorption_plan_ref.next_stage'));
        $this->assertContains('auto_change_atlas_decide_routing', data_get($payload, 'curator_proposal.forbidden_actions'));

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

    public function test_provider_release_review_marks_unmatched_source_as_classification_only(): void
    {
        $exit = Artisan::call('atlas:ai:provider-release-review', [
            '--provider' => 'other',
            '--title' => 'Rumor: secret Claude finance mode',
            '--url' => 'https://example.com/rumor/secret-claude-finance-mode',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('classification_only_pending_primary_source', data_get($payload, 'release_envelope.draft_status'));
        $this->assertSame('unmatched_source', data_get($payload, 'source_candidate.status'));
        $this->assertSame('primary_source_required', data_get($payload, 'source_gate.status'));
        $this->assertTrue(data_get($payload, 'source_gate.primary_source_required'));
        $this->assertFalse(data_get($payload, 'source_gate.can_create_release_envelope_draft'));
        $this->assertFalse(data_get($payload, 'decide_signal.source_trust_allows_signal'));
        $this->assertSame('blocked_pending_primary_source', data_get($payload, 'review_signal.status'));
        $this->assertSame('blocked_pending_source_gate', data_get($payload, 'absorption_plan.status'));
        $this->assertSame('source_gate', data_get($payload, 'absorption_plan.stages.0.id'));
        $this->assertSame('blocked', data_get($payload, 'absorption_plan.stages.0.status'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_create_ap'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_emit_decide_signal'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_change_routing_policy'));
        $this->assertSame('attach_primary_source_before_ap_or_decide_signal', data_get($payload, 'review_signal.recommended_action'));
        $this->assertFalse(data_get($payload, 'review_signal.proposal_allowed'));
        $this->assertSame('blocked', data_get($payload, 'curator_proposal.status'));
        $this->assertSame('resolve_review_signal_blocker_before_proposal', data_get($payload, 'curator_proposal.next_action'));
        $this->assertContains('source_not_primary_enough_for_release_envelope', $payload['risks']);
    }
}
