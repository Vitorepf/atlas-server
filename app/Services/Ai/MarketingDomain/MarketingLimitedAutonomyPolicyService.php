<?php

namespace App\Services\Ai\MarketingDomain;

use App\Services\Ai\Mission\MissionCanonicalHash;

class MarketingLimitedAutonomyPolicyService
{
    public const SCHEMA = 'atlas.ai.marketing_domain.limited_autonomy_policy.v1';

    /**
     * @return array<string,mixed>
     */
    public function policyPacket(): array
    {
        $packet = [
            'schema' => self::SCHEMA,
            'company_id' => MarketingDomainCanon::DOMAIN_ID,
            'autonomy_level' => 'limited_internal_autonomy',
            'status' => 'active_with_hard_external_gates',
            'allowed_without_human_approval' => [
                'draft_internal_positioning_variant',
                'draft_internal_copy_variant',
                'draft_internal_creative_brief',
                'analyze_marketing_control_plane_snapshot',
                'prepare_approval_gate_request',
            ],
            'requires_human_approval' => [
                MarketingDomainCanon::GATE_PUBLISH,
                MarketingDomainCanon::GATE_PAID_MEDIA,
                MarketingDomainCanon::GATE_SEND_EMAIL,
                MarketingDomainCanon::GATE_LAUNCH_EXPERIMENT,
            ],
            'budget' => [
                'currency' => 'USD',
                'internal_autonomy_monthly_ceiling' => 250,
                'external_spend_ceiling_without_approval' => 0,
                'paid_media_spend_without_approval' => false,
            ],
            'stop_conditions' => [
                'any_external_publish_attempt',
                'any_paid_spend_attempt',
                'any_email_send_attempt',
                'missing_evidence_pack_hash',
                'certification_failed',
                'approval_gate_missing_for_sensitive_action',
            ],
            'rollback_plan' => [
                'mark_generated_artifacts_rejected',
                'expire_pending_sensitive_gates',
                'restore_previous_positioning_or_campaign_pack',
                'emit_operator_review_packet_before_next_run',
            ],
            'review_packet' => [
                'review_required_for_external_effect' => true,
                'minimum_evidence' => [
                    'certification_hash',
                    'evidence_pack_hash',
                    'approval_gate_receipt_hash',
                    'control_plane_snapshot',
                ],
                'recommended_review_cadence' => 'weekly',
            ],
            'invariants' => [
                'no_auto_publish' => true,
                'no_auto_spend' => true,
                'no_auto_email' => true,
                'no_external_tool_execution' => true,
                'approval_gates_remain_required' => true,
                'rollback_required' => true,
                'operator_review_required_for_external_effects' => true,
            ],
        ];

        $packet['policy_hash'] = MissionCanonicalHash::sha256($packet);

        return $packet;
    }
}
