<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiProviderReleaseReviewCommandTest extends TestCase
{
    public function test_provider_release_review_classifies_anthropic_finance_agents(): void
    {
        // release_id embeds now()->format('Y-m'); pin the clock so the asserted month stays valid.
        Carbon::setTestNow(Carbon::parse('2026-05-15T12:00:00Z'));

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
        $this->assertFalse(data_get($payload, 'source_registry_context.guardrails.writes_decide_signal'));
        $this->assertFalse(data_get($payload, 'source_registry_context.guardrails.auto_ingestion_allowed'));
        $this->assertSame('proposal_only_no_network_no_writes', data_get($payload, 'source_registry_context.continuous_ingestion_contract.mode'));
        $this->assertFalse(data_get($payload, 'source_registry_context.continuous_ingestion_contract.writes_decide_signal'));
        $this->assertFalse(data_get($payload, 'source_registry_context.continuous_ingestion_contract.auto_promotion_allowed'));
        $this->assertFalse(data_get($payload, 'source_registry_context.continuous_ingestion_contract.promotion_allowed'));
        $this->assertSame('atlas.provider_release.future_activation_review.v1', data_get($payload, 'source_registry_context.future_activation_review_contract.schema_version'));
        $this->assertSame('blocked_until_dedicated_AP', data_get($payload, 'source_registry_context.future_activation_review_contract.status'));
        $this->assertFalse(data_get($payload, 'source_registry_context.future_activation_review_contract.auto_decide_signal_allowed'));
        $this->assertFalse(data_get($payload, 'source_registry_context.future_activation_review_contract.promotion_allowed'));
        $this->assertContains('human_review_before_any_decide_or_policy_signal', data_get($payload, 'source_registry_context.future_activation_review_contract.requires'));
        $this->assertContains('default_model_change', data_get($payload, 'source_registry_context.future_activation_review_contract.blocked_targets'));
        $this->assertContains('absorb', $payload['secondary_actions']);
        $this->assertContains('create_domain_skill_pack', $payload['secondary_actions']);
        $this->assertSame('atlas.provider_release.anti_wrapper_contract.v1', data_get($payload, 'anti_wrapper_contract.schema_version'));
        $this->assertSame('atlas_substitutes_direct_provider_channels_by_orchestrating_them', data_get($payload, 'anti_wrapper_contract.posture'));
        $this->assertSame('external_provider_improvement_must_make_atlas_stronger_or_be_archived', data_get($payload, 'anti_wrapper_contract.provider_release_effect'));
        $this->assertContains('benchmark_against_direct_provider_baseline', data_get($payload, 'anti_wrapper_contract.allowed_absorption_paths'));
        $this->assertContains('direct_provider_channel_as_primary_product', data_get($payload, 'anti_wrapper_contract.forbidden_paths'));
        $this->assertStringContainsString('Rivals/AP-99', data_get($payload, 'anti_wrapper_contract.success_condition'));
        $this->assertSame('atlas.provider_release.absorption_plan.v1', data_get($payload, 'absorption_plan.schema_version'));
        $this->assertSame('proposal_ready', data_get($payload, 'absorption_plan.status'));
        $this->assertSame('proposal_only_no_routing_change', data_get($payload, 'absorption_plan.mode'));
        $this->assertSame('finance', data_get($payload, 'absorption_plan.primary_domain'));
        $this->assertSame('primary_source_verified', data_get($payload, 'absorption_plan.source_gate_status'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_rules.may_create_ap'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_rules.may_create_skill_pack'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_rules.may_emit_decide_signal'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_change_routing_policy'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_change_default_model'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_change_domain_maturity'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_store_provider_credentials'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_rules.may_call_provider_vertical_directly'));
        $this->assertSame('atlas.provider_release.promotion_gate.v1', data_get($payload, 'absorption_plan.promotion_gate.schema_version'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_gate.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_gate.routing_promotion_allowed_now'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_gate.domain_maturity_promotion_allowed_now'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_gate.credential_activation_allowed_now'));
        $this->assertFalse(data_get($payload, 'absorption_plan.promotion_gate.provider_direct_channel_allowed_now'));
        $this->assertContains('AP-99 outcome evidence for affected domain/flow/task_type', data_get($payload, 'absorption_plan.promotion_gate.requires'));
        $this->assertContains('provider_vertical_agent_to_domain_ready', data_get($payload, 'absorption_plan.promotion_gate.blocked_shortcuts'));
        $this->assertSame('atlas.provider_release.promotion_review_packet.v1', data_get($payload, 'absorption_plan.promotion_gate.review_packet.schema_version'));
        $this->assertSame('blocked_until_human_review_and_benchmarks', data_get($payload, 'absorption_plan.promotion_gate.review_packet.status'));
        $this->assertSame('approve_or_reject_provider_release_absorption', data_get($payload, 'absorption_plan.promotion_gate.review_packet.required_human_decision'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_gate.review_packet.required_decision_receipt'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_gate.review_packet.rollback_plan_required'));
        $this->assertTrue(data_get($payload, 'absorption_plan.promotion_gate.review_packet.policy_patch_review_required'));
        $this->assertContains('AP-99 provider performance evidence', data_get($payload, 'absorption_plan.promotion_gate.review_packet.evidence_required'));
        $this->assertContains('revert_routing_policy_patch', data_get($payload, 'absorption_plan.promotion_gate.review_packet.rollback_required'));
        $this->assertContains('change_atlas_decide_routing_policy', data_get($payload, 'absorption_plan.promotion_gate.review_packet.forbidden_until_review'));
        $this->assertSame(
            ['release_envelope', 'rivals_benchmark', 'domain_skill_pack', 'decide_signal', 'human_review'],
            collect(data_get($payload, 'absorption_plan.stages'))->pluck('id')->all(),
        );
        $this->assertContains('PROVIDER_RELEASE_REVIEWED', data_get($payload, 'absorption_plan.ledger_events_expected'));
        $this->assertTrue($payload['rivals_required']);
        $this->assertTrue(data_get($payload, 'decide_signal.signal_only'));
        $this->assertFalse(data_get($payload, 'decide_signal.promotion_allowed'));
        $this->assertFalse(data_get($payload, 'decide_signal.changes_routing'));
        $this->assertFalse(data_get($payload, 'decide_signal.default_model_change_allowed'));
        $this->assertTrue(data_get($payload, 'decide_signal.manual_override_only_until_promoted'));
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
        $this->assertSame('atlas.provider_release.promotion_review_packet.v1', data_get($payload, 'curator_proposal.promotion_review_ref.schema_version'));
        $this->assertSame('blocked_until_human_review_and_benchmarks', data_get($payload, 'curator_proposal.promotion_review_ref.status'));
        $this->assertSame('approve_or_reject_provider_release_absorption', data_get($payload, 'curator_proposal.promotion_review_ref.required_human_decision'));
        $this->assertTrue(data_get($payload, 'curator_proposal.promotion_review_ref.required_decision_receipt'));
        $this->assertTrue(data_get($payload, 'curator_proposal.promotion_review_ref.rollback_plan_required'));
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
        $this->assertSame('blocked_until_primary_source_and_review', data_get($payload, 'absorption_plan.promotion_gate.review_packet.status'));
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
