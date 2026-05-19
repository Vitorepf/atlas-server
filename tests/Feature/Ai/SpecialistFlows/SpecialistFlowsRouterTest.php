<?php

namespace Tests\Feature\Ai\SpecialistFlows;

use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsCanon;
use App\Services\Ai\SpecialistFlows\SpecialistFlowsRouter;
use Tests\TestCase;

class SpecialistFlowsRouterTest extends TestCase
{
    public function test_research_prompt_routes_to_atlas_research_not_programming_dev(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: [
                'flow_id' => RouterRuntimeCanon::FLOW_RESEARCH,
                'intent' => ['intent_type' => RouterRuntimeCanon::INTENT_RESEARCH],
                'domain' => ['id' => 'research'],
                'flow_origin' => 'router_auto',
            ],
            payload: ['surface_id' => 'atlas_desktop_ai'],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_RESEARCH, $contract['flow_id']);
        $this->assertNotSame(RouterRuntimeCanon::FLOW_DEV, $contract['flow_id'], 'research MUST NOT fall into atlas_dev');
        $this->assertSame('source_grounded_answer', $contract['execution_mode']);
        $this->assertContains('treat_unsourced_claim_as_fact', $contract['forbidden_actions']);
        $this->assertContains('policy:source_grounded_claims_only', $contract['policy_refs']);
    }

    public function test_finance_prompt_requires_policy_and_evidence_and_blocks_live_trade_by_default(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: [
                'flow_id' => RouterRuntimeCanon::FLOW_FINANCE,
                'intent' => ['intent_type' => RouterRuntimeCanon::INTENT_FINANCE],
                'domain' => ['id' => 'finance'],
            ],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_FINANCE, $contract['flow_id']);
        // Policy + evidence anchors are mandatory.
        $this->assertContains('policy:no_real_trade_without_operator_approval', $contract['policy_refs']);
        $this->assertContains('data_snapshot_or_missing_data_statement', $contract['required_evidence']);
        // Default is FORBIDDEN side effects until operator approves.
        $this->assertFalse($contract['live_trade_authorized']);
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN, $contract['side_effect_policy']);
        $this->assertContains('execute_live_trade_without_operator_approval', $contract['forbidden_actions']);
    }

    public function test_finance_with_operator_approval_promotes_side_effect_to_requires_approval(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_FINANCE],
            payload: ['operator_approvals' => ['finance_live_trade_approved']],
        );

        $this->assertTrue($contract['live_trade_authorized']);
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_REQUIRES_APPROVAL, $contract['side_effect_policy']);
        // Even with approval, the canonical forbidden_actions still ship —
        // the runtime gate still applies on every execution attempt.
        $this->assertContains('execute_live_trade_without_operator_approval', $contract['forbidden_actions']);
    }

    public function test_marketing_prompt_has_its_own_flow_not_atlas_plan(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_MARKETING],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_MARKETING, $contract['flow_id']);
        $this->assertNotSame(RouterRuntimeCanon::FLOW_PLAN, $contract['flow_id']);
        $this->assertSame('campaign_plan', $contract['execution_mode']);
        $this->assertFalse($contract['publish_authorized']);
        $this->assertFalse($contract['spend_authorized']);
        $this->assertContains('publish_campaign_without_operator_approval', $contract['forbidden_actions']);
    }

    public function test_cyber_prompt_runs_defensive_only_by_default_and_forbids_offensive_action(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_CYBER],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_CYBER, $contract['flow_id']);
        $this->assertSame('defensive_advisory', $contract['execution_mode']);
        $this->assertFalse($contract['offensive_actions_allowed']);
        $this->assertFalse($contract['execute_authorized']);
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN, $contract['side_effect_policy']);
        $this->assertContains('run_offensive_tooling_without_signed_roe', $contract['forbidden_actions']);
        $this->assertContains('exfiltrate_data', $contract['forbidden_actions']);
    }

    public function test_cyber_with_signed_roe_promotes_mode_but_keeps_execute_gated(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_CYBER],
            payload: ['operator_approvals' => ['cyber_roe_signed']],
        );

        $this->assertSame('offensive_with_roe', $contract['execution_mode']);
        $this->assertTrue($contract['offensive_actions_allowed']);
        $this->assertFalse($contract['execute_authorized'], 'RoE signed alone does NOT authorize execution');
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN, $contract['side_effect_policy']);
    }

    public function test_automation_separates_plan_from_execute_and_defaults_to_plan(): void
    {
        $planContract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_AUTOMATION],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_AUTOMATION, $planContract['flow_id']);
        $this->assertSame('plan', $planContract['stage']);
        $this->assertSame('workflow_plan_only', $planContract['execution_mode']);
        $this->assertFalse($planContract['execute_authorized']);
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_FORBIDDEN, $planContract['side_effect_policy']);

        $execContract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_AUTOMATION],
            payload: ['operator_approvals' => ['automation_execute_approved']],
        );

        $this->assertSame('execute', $execContract['stage']);
        $this->assertSame('workflow_execute_with_approval', $execContract['execution_mode']);
        $this->assertTrue($execContract['execute_authorized']);
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_REQUIRES_APPROVAL, $execContract['side_effect_policy']);
    }

    public function test_conversation_flow_does_not_require_workspace(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: [
                'flow_id' => RouterRuntimeCanon::FLOW_CONVERSATION,
                'handoff_payload' => ['workspace_present' => false],
            ],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_CONVERSATION, $contract['flow_id']);
        $this->assertFalse($contract['workspace_required']);
        $this->assertFalse($contract['workspace_present']);
        $this->assertContains('no_workspace_assumption', $contract['conversation_invariants']);
    }

    public function test_personal_development_keeps_non_clinical_boundary(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT, $contract['flow_id']);
        $this->assertSame('non_clinical_only', $contract['clinical_boundary']);
        $this->assertContains('non_clinical_boundary_required', $contract['personal_development_invariants']);
        $this->assertContains('diagnose_medical_or_psychiatric_condition', $contract['forbidden_actions']);
    }

    public function test_strategy_flow_is_read_only_and_documents_assumptions(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => RouterRuntimeCanon::FLOW_STRATEGY],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_STRATEGY, $contract['flow_id']);
        $this->assertSame('decision_memo', $contract['execution_mode']);
        $this->assertSame(SpecialistFlowsCanon::SIDE_EFFECT_READ_ONLY, $contract['side_effect_policy']);
        $this->assertContains('document_assumptions_explicitly', $contract['strategy_invariants']);
    }

    public function test_ambiguous_router_envelope_falls_back_explicitly_to_conversation(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: [], // no flow_id at all
            payload: ['surface_id' => 'atlas_desktop_ai'],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_CONVERSATION, $contract['flow_id']);
        $this->assertTrue($contract['fallback']['used']);
        $this->assertSame('router_envelope_did_not_carry_flow_id', $contract['fallback']['reason']);
        // The audit trail makes the fallback explicit; no silent rerouting.
    }

    public function test_unknown_flow_id_falls_back_to_conversation_with_audit_trail(): void
    {
        $contract = $this->router()->buildSpecialistFlowRuntime(
            router: ['flow_id' => 'atlas_made_up_flow'],
            payload: [],
        );

        $this->assertSame(RouterRuntimeCanon::FLOW_CONVERSATION, $contract['flow_id']);
        $this->assertTrue($contract['fallback']['used']);
        $this->assertSame('atlas_made_up_flow', $contract['fallback']['requested_flow_id']);
        $this->assertSame(
            'requested_flow_id_not_owned_by_specialist_flows_registry',
            $contract['fallback']['reason'],
        );
    }

    public function test_programming_anchored_flow_is_delegated_to_programming_adapter_not_handled_locally(): void
    {
        foreach (RouterRuntimeCanon::PROGRAMMING_FLOW_IDS as $flowId) {
            $contract = $this->router()->buildSpecialistFlowRuntime(
                router: ['flow_id' => $flowId],
                payload: [],
            );

            $this->assertSame($flowId, $contract['flow_id']);
            $this->assertSame('programming_adapter', $contract['owner']);
            $this->assertSame('delegate_to_programming_adapter', $contract['delegation']['status']);
            $this->assertSame($flowId, $contract['delegation']['target_flow_id']);
        }
    }

    public function test_no_specialist_handler_delegates_to_atlas_dev_or_forge(): void
    {
        $nonProgrammingFlowIds = [
            RouterRuntimeCanon::FLOW_RESEARCH,
            RouterRuntimeCanon::FLOW_FINANCE,
            RouterRuntimeCanon::FLOW_MARKETING,
            RouterRuntimeCanon::FLOW_STRATEGY,
            RouterRuntimeCanon::FLOW_CYBER,
            RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT,
            RouterRuntimeCanon::FLOW_AUTOMATION,
            RouterRuntimeCanon::FLOW_CONVERSATION,
        ];

        foreach ($nonProgrammingFlowIds as $flowId) {
            $contract = $this->router()->buildSpecialistFlowRuntime(
                router: ['flow_id' => $flowId, 'handoff_payload' => ['workspace_present' => true]],
                payload: [],
            );

            $this->assertNotSame('delegate_to_other_flow', $contract['delegation']['status']);
            $this->assertSame('not_delegated', $contract['delegation']['status']);
            $this->assertContains(RouterRuntimeCanon::FLOW_DEV, $contract['delegation']['forbidden_targets']);
            $this->assertContains(RouterRuntimeCanon::FLOW_FORGE, $contract['delegation']['forbidden_targets']);
        }
    }

    public function test_receipt_has_deterministic_hash_and_canonical_shape(): void
    {
        $router = ['flow_id' => RouterRuntimeCanon::FLOW_FINANCE];
        $payload = ['surface_id' => 'atlas_desktop_ai'];

        $a = $this->router()->buildSpecialistFlowRuntime($router, $payload);
        $b = $this->router()->buildSpecialistFlowRuntime($router, $payload);

        $this->assertSame($a['receipt']['contract_hash'], $b['receipt']['contract_hash']);
        $this->assertSame(64, strlen($a['receipt']['contract_hash']));
        $this->assertStringStartsWith('sfr_', $a['receipt']['receipt_id']);
        $this->assertSame(SpecialistFlowsCanon::RECEIPT_SCHEMA_VERSION, $a['receipt']['schema_version']);
        $this->assertSame(RouterRuntimeCanon::FLOW_FINANCE, $a['receipt']['flow_id']);
        $this->assertNotEmpty($a['receipt']['policy_refs']);

        $json = json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
    }

    public function test_canonical_flow_id_list_matches_router_runtime_canon(): void
    {
        $this->assertSame(
            [
                RouterRuntimeCanon::FLOW_RESEARCH,
                RouterRuntimeCanon::FLOW_FINANCE,
                RouterRuntimeCanon::FLOW_MARKETING,
                RouterRuntimeCanon::FLOW_STRATEGY,
                RouterRuntimeCanon::FLOW_CYBER,
                RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT,
                RouterRuntimeCanon::FLOW_AUTOMATION,
                RouterRuntimeCanon::FLOW_CONVERSATION,
            ],
            $this->router()->ownedFlowIds(),
            'SpecialistFlowsRouter must own exactly the 8 specialist flow_ids (research/finance/marketing/strategy/cyber/personal_development/automation/conversation).',
        );
    }

    public function test_intent_to_flow_routes_each_intent_to_dedicated_flow_not_atlas_plan(): void
    {
        // After the canon update, every non-Plan domain has its own flow_id.
        $this->assertSame(RouterRuntimeCanon::FLOW_FINANCE, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_FINANCE]);
        $this->assertSame(RouterRuntimeCanon::FLOW_MARKETING, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_MARKETING]);
        $this->assertSame(RouterRuntimeCanon::FLOW_STRATEGY, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_STRATEGY]);
        $this->assertSame(RouterRuntimeCanon::FLOW_CYBER, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_CYBER]);
        $this->assertSame(RouterRuntimeCanon::FLOW_PERSONAL_DEVELOPMENT, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT]);
        $this->assertSame(RouterRuntimeCanon::FLOW_AUTOMATION, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_AUTOMATION]);
        // Truly unknown intent still falls to conversation (never dev).
        $this->assertSame(RouterRuntimeCanon::FLOW_CONVERSATION, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_UNKNOWN]);
        $this->assertNotSame(RouterRuntimeCanon::FLOW_DEV, RouterRuntimeCanon::INTENT_TO_FLOW[RouterRuntimeCanon::INTENT_UNKNOWN]);
    }

    private function router(): SpecialistFlowsRouter
    {
        return app(SpecialistFlowsRouter::class);
    }
}
