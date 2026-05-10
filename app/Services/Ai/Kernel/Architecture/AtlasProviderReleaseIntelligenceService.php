<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Str;

final class AtlasProviderReleaseIntelligenceService
{
    public function __construct(
        private readonly AtlasProviderReleaseSourceRegistry $sourceRegistry = new AtlasProviderReleaseSourceRegistry,
    ) {}

    private const PROVIDERS = [
        'anthropic',
        'openai',
        'google',
        'gemini',
        'codex',
        'cursor',
        'apple',
        'meta',
        'xai',
    ];

    private const RELEASE_TYPES = [
        'vertical_agents',
        'model',
        'connector',
        'tool_use',
        'realtime',
        'memory',
        'coding',
        'design',
        'marketing',
        'finance',
        'capability_update',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function review(array $input): array
    {
        $title = $this->cleanString($input['title'] ?? null) ?: 'untitled-provider-release';
        $url = $this->cleanString($input['url'] ?? null);
        $sourceCandidate = $url !== null
            ? $this->sourceRegistry->candidateFromDetection(
                url: $url,
                title: $title,
                contentHash: $this->cleanString($input['content_hash'] ?? null),
                publishedAt: $this->cleanString($input['published_at'] ?? null),
            )
            : null;
        $provider = $this->provider($input['provider'] ?? null, $title.' '.$url);
        $releaseType = $this->releaseType($input['type'] ?? null, $title.' '.$url);
        $domains = $this->domains((array) ($input['domains'] ?? []), $title.' '.$url.' '.$releaseType);
        $capabilities = $this->uniqueStrings((array) ($input['capabilities'] ?? []));
        $connectors = $this->uniqueStrings((array) ($input['connectors'] ?? []));
        $releaseId = $this->releaseId($provider, $title);
        $recommendedAction = $this->recommendedAction($releaseType, $domains, $capabilities, $connectors);
        $secondaryActions = $this->secondaryActions($recommendedAction, $releaseType);
        $ownerDocs = $this->ownerDocs($domains, $releaseType);
        $rivalsRequired = $this->rivalsRequired($releaseType, $domains);
        $sourceGate = $this->sourceGate($sourceCandidate);
        $reviewSignal = $this->reviewSignal($recommendedAction, $rivalsRequired, $sourceGate);
        $suggestedAps = $this->suggestedAps($provider, $releaseId, $releaseType, $domains, $recommendedAction);
        $absorptionPlan = $this->absorptionPlan(
            provider: $provider,
            releaseId: $releaseId,
            releaseType: $releaseType,
            domains: $domains,
            recommendedAction: $recommendedAction,
            rivalsRequired: $rivalsRequired,
            sourceGate: $sourceGate,
        );

        return [
            'schema_version' => 'atlas.provider_release_review.v1',
            'status' => 'ok',
            'generated_at' => now()->toIso8601String(),
            'release_envelope' => [
                'schema_version' => 'atlas.provider_release.v1',
                'draft_status' => $this->envelopeDraftStatus($sourceCandidate),
                'provider' => $provider,
                'release_id' => $releaseId,
                'title' => $title,
                'url' => $url,
                'release_type' => $releaseType,
                'affected_domains' => $domains,
                'affected_surfaces' => $this->affectedSurfaces($releaseType, $domains),
                'affected_runtimes' => $this->affectedRuntimes($releaseType),
                'capabilities' => $capabilities,
                'connectors' => $connectors,
                'threat_to_wrappers' => $this->threatToWrappers($releaseType),
                'threat_to_atlas' => $this->threatToAtlas($releaseType, $domains),
                'potential_multiplier' => $this->potentialMultiplier($releaseType, $domains, $capabilities, $connectors),
                'recommended_action' => $recommendedAction,
            ],
            'source_candidate' => $sourceCandidate,
            'source_gate' => $sourceGate,
            'source_registry_context' => $this->sourceRegistryContext($provider, $sourceCandidate),
            'classification' => [
                'provider_category' => $provider === 'other' ? 'unknown_or_emerging_lab' : 'known_provider',
                'release_family' => $this->releaseFamily($releaseType),
                'atlas_positioning' => 'provider_capability_becomes_signal_adapter_skill_pack_or_benchmark_never_direct_channel',
                'wrapper_market_impact' => $this->wrapperMarketImpact($releaseType),
            ],
            'anti_wrapper_contract' => $this->antiWrapperContract($provider, $releaseType, $domains, $rivalsRequired),
            'recommended_action' => $recommendedAction,
            'secondary_actions' => $secondaryActions,
            'owner_docs' => $ownerDocs,
            'suggested_aps' => $suggestedAps,
            'absorption_plan' => $absorptionPlan,
            'rivals_required' => $rivalsRequired,
            'decide_signal' => [
                'schema_version' => 'atlas.decide.provider_release_signal.v1',
                'signal_only' => true,
                'promotion_allowed' => false,
                'changes_routing' => false,
                'default_model_change_allowed' => false,
                'manual_override_only_until_promoted' => true,
                'source_trust_allows_signal' => (bool) data_get($sourceCandidate, 'source_trust.decide_signal_allowed', $sourceCandidate === null),
                'manual_override_required_for_critical_use' => true,
                'promotion_requires' => ['AP-99 evidence', 'Rivals benchmark', 'owner doc update', 'human review'],
            ],
            'review_signal' => $reviewSignal,
            'curator_proposal' => $this->curatorProposal($releaseId, $recommendedAction, $reviewSignal, $ownerDocs, $suggestedAps, $absorptionPlan),
            'risks' => $this->risks($releaseType, $recommendedAction, $sourceCandidate),
            'required_validation' => [
                'php artisan atlas:ai:architecture-validate --json',
                'atlas engineering knowledge docs-health',
                'atlas engineering knowledge sync --prune',
                'Rivals benchmark when rivals_required=true',
            ],
            'non_goals' => [
                'No direct provider channel outside Atlas.',
                'No hardcoded routing policy from a press release.',
                'No domain maturity promotion without evidence.',
                'No connector credential storage in this review command.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $sourceGate
     * @return array<string,mixed>
     */
    private function reviewSignal(string $recommendedAction, bool $rivalsRequired, array $sourceGate): array
    {
        $sourceVerified = (bool) ($sourceGate['can_create_release_envelope_draft'] ?? false);
        if (! $sourceVerified) {
            return [
                'schema_version' => 'atlas.provider_release.review_signal.v1',
                'status' => 'blocked_pending_primary_source',
                'severity' => 'medium',
                'recommended_action' => 'attach_primary_source_before_ap_or_decide_signal',
                'stop_the_line_for_routing' => true,
                'proposal_allowed' => false,
                'evidence_required' => ['primary_source_url', 'source_gate.primary_source_verified'],
            ];
        }

        if ($recommendedAction === 'bypass') {
            return [
                'schema_version' => 'atlas.provider_release.review_signal.v1',
                'status' => 'archive_or_monitor',
                'severity' => 'low',
                'recommended_action' => 'archive_source_material_without_policy_change',
                'stop_the_line_for_routing' => false,
                'proposal_allowed' => true,
                'evidence_required' => ['archive_reason'],
            ];
        }

        return [
            'schema_version' => 'atlas.provider_release.review_signal.v1',
            'status' => $rivalsRequired ? 'ready_for_rivals_proposal' : 'ready_for_absorption_proposal',
            'severity' => $rivalsRequired ? 'high' : 'medium',
            'recommended_action' => $rivalsRequired
                ? 'create_rivals_ap_before_absorption_or_decide_promotion'
                : 'create_absorption_ap_with_owner_doc_update',
            'stop_the_line_for_routing' => true,
            'proposal_allowed' => true,
            'evidence_required' => $rivalsRequired
                ? ['Provider Release Envelope', 'Rivals benchmark AP', 'AP-99 calibration', 'human review']
                : ['Provider Release Envelope', 'owner doc update', 'human review'],
        ];
    }

    /**
     * @param  array<int,array{path:string,exists:bool,reason:string}>  $ownerDocs
     * @param  array<int,array<string,string>>  $suggestedAps
     * @param  array<string,mixed>  $reviewSignal
     * @param  array<string,mixed>  $absorptionPlan
     * @return array<string,mixed>
     */
    private function curatorProposal(string $releaseId, string $recommendedAction, array $reviewSignal, array $ownerDocs, array $suggestedAps, array $absorptionPlan): array
    {
        $proposalAllowed = (bool) ($reviewSignal['proposal_allowed'] ?? false);

        return [
            'schema_version' => 'atlas.provider_release.curator_proposal.v1',
            'proposal_only' => true,
            'auto_apply' => false,
            'status' => $proposalAllowed ? 'proposal_ready' : 'blocked',
            'release_id' => $releaseId,
            'target_flow' => 'self_improvement.provider_release_review',
            'recommended_action' => $recommendedAction,
            'review_signal_status' => (string) ($reviewSignal['status'] ?? 'unknown'),
            'required_human_review' => true,
            'target_owner_docs' => collect($ownerDocs)
                ->pluck('path')
                ->values()
                ->all(),
            'suggested_ap_ids' => collect($suggestedAps)
                ->pluck('id')
                ->values()
                ->all(),
            'absorption_plan_ref' => [
                'schema_version' => (string) ($absorptionPlan['schema_version'] ?? 'atlas.provider_release.absorption_plan.v1'),
                'status' => (string) ($absorptionPlan['status'] ?? 'unknown'),
                'stage_count' => count((array) ($absorptionPlan['stages'] ?? [])),
                'next_stage' => (string) data_get($absorptionPlan, 'stages.0.id', 'none'),
            ],
            'promotion_review_ref' => [
                'schema_version' => (string) data_get($absorptionPlan, 'promotion_gate.review_packet.schema_version', 'atlas.provider_release.promotion_review_packet.v1'),
                'status' => (string) data_get($absorptionPlan, 'promotion_gate.review_packet.status', 'unknown'),
                'required_human_decision' => (string) data_get($absorptionPlan, 'promotion_gate.review_packet.required_human_decision', 'approve_or_reject_provider_release_absorption'),
                'required_decision_receipt' => (bool) data_get($absorptionPlan, 'promotion_gate.review_packet.required_decision_receipt', true),
                'rollback_plan_required' => (bool) data_get($absorptionPlan, 'promotion_gate.review_packet.rollback_plan_required', true),
            ],
            'forbidden_actions' => [
                'auto_change_atlas_decide_routing',
                'auto_store_provider_credentials',
                'declare_domain_implemented_without_rivals',
                'call_provider_vertical_directly_outside_atlas',
            ],
            'next_action' => $proposalAllowed
                ? 'open_reviewable_curator_item_or_create_ap_from_suggested_ids'
                : 'resolve_review_signal_blocker_before_proposal',
        ];
    }

    /**
     * @param  array<int,string>  $domains
     * @param  array<string,mixed>  $sourceGate
     * @return array<string,mixed>
     */
    private function absorptionPlan(string $provider, string $releaseId, string $releaseType, array $domains, string $recommendedAction, bool $rivalsRequired, array $sourceGate): array
    {
        $blocked = (bool) ($sourceGate['can_create_release_envelope_draft'] ?? false) === false;
        $primaryDomain = $domains[0] ?? 'general';

        return [
            'schema_version' => 'atlas.provider_release.absorption_plan.v1',
            'status' => $blocked ? 'blocked_pending_source_gate' : 'proposal_ready',
            'mode' => 'proposal_only_no_routing_change',
            'provider' => $provider,
            'release_id' => $releaseId,
            'release_type' => $releaseType,
            'primary_domain' => $primaryDomain,
            'recommended_action' => $recommendedAction,
            'rivals_required' => $rivalsRequired,
            'source_gate_status' => (string) ($sourceGate['status'] ?? 'unknown'),
            'stages' => $this->absorptionStages($releaseType, $primaryDomain, $recommendedAction, $rivalsRequired, $blocked),
            'promotion_rules' => [
                'may_create_ap' => ! $blocked,
                'may_create_skill_pack' => ! $blocked && in_array($recommendedAction, ['benchmark', 'absorb', 'exploit_gap'], true),
                'may_emit_decide_signal' => ! $blocked,
                'promotion_allowed' => false,
                'may_change_routing_policy' => false,
                'may_change_default_model' => false,
                'may_change_domain_maturity' => false,
                'may_store_provider_credentials' => false,
                'may_call_provider_vertical_directly' => false,
                'routing_policy_requires' => ['human_review', 'AP-99 evidence', 'Rivals benchmark when required', 'Decision Receipt'],
            ],
            'promotion_gate' => [
                'schema_version' => 'atlas.provider_release.promotion_gate.v1',
                'promotion_allowed' => false,
                'routing_promotion_allowed_now' => false,
                'domain_maturity_promotion_allowed_now' => false,
                'credential_activation_allowed_now' => false,
                'provider_direct_channel_allowed_now' => false,
                'requires' => [
                    'primary_source_verified',
                    'owner_doc_updated',
                    'Rivals benchmark when rivals_required=true',
                    'AP-99 outcome evidence for affected domain/flow/task_type',
                    'human review approval',
                    'new Decision Receipt after approval',
                ],
                'blocked_shortcuts' => [
                    'press_release_to_default_model',
                    'provider_vertical_agent_to_domain_ready',
                    'provider_connector_to_stored_credentials',
                    'provider_app_to_direct_user_channel',
                    'secondary_source_to_decide_policy',
                ],
                'review_packet' => $this->promotionReviewPacket($releaseType, $primaryDomain, $rivalsRequired, $blocked),
            ],
            'ledger_events_expected' => [
                'PROVIDER_RELEASE_REVIEWED',
                'RIVALS_BENCHMARK_RECORDED',
                'PROVIDER_DECIDE_SIGNAL_PROPOSED',
                'PROVIDER_CAPABILITY_ABSORPTION_REVIEWED',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionReviewPacket(string $releaseType, string $primaryDomain, bool $rivalsRequired, bool $blocked): array
    {
        return [
            'schema_version' => 'atlas.provider_release.promotion_review_packet.v1',
            'status' => $blocked ? 'blocked_until_primary_source_and_review' : 'blocked_until_human_review_and_benchmarks',
            'scope' => [
                'release_type' => $releaseType,
                'primary_domain' => $primaryDomain,
                'rivals_required' => $rivalsRequired,
            ],
            'required_human_decision' => 'approve_or_reject_provider_release_absorption',
            'required_decision_receipt' => true,
            'rollback_plan_required' => true,
            'policy_patch_review_required' => true,
            'evidence_required' => [
                'primary_source_verified',
                'owner_doc_diff',
                'AP-99 provider performance evidence',
                'Rivals benchmark when rivals_required=true',
                'human review decision',
                'new Decision Receipt after approval',
                'rollback plan for affected routing/domain/runtime policy',
            ],
            'rollback_required' => [
                'remove_decide_signal_candidate',
                'revert_routing_policy_patch',
                'disable_connector_or_runtime_adapter',
                'restore_previous_domain_maturity',
                'archive_skill_pack_candidate',
            ],
            'forbidden_until_review' => [
                'change_default_model',
                'change_atlas_decide_routing_policy',
                'promote_domain_maturity',
                'store_provider_credentials',
                'call_provider_vertical_directly_outside_atlas',
                'mark_skill_pack_implemented',
                'write_provider_release_to_memory_core_as_truth',
            ],
        ];
    }

    /**
     * @param  array<int,string>  $domains
     * @return array<string,mixed>
     */
    private function antiWrapperContract(string $provider, string $releaseType, array $domains, bool $rivalsRequired): array
    {
        return [
            'schema_version' => 'atlas.provider_release.anti_wrapper_contract.v1',
            'status' => 'active',
            'posture' => 'atlas_substitutes_direct_provider_channels_by_orchestrating_them',
            'provider' => $provider,
            'release_type' => $releaseType,
            'affected_domains' => $domains,
            'rivals_required' => $rivalsRequired,
            'provider_release_effect' => 'external_provider_improvement_must_make_atlas_stronger_or_be_archived',
            'allowed_absorption_paths' => [
                'benchmark_against_direct_provider_baseline',
                'extract_domain_skill_pack',
                'create_connector_or_runtime_adapter',
                'emit_decide_signal_candidate',
                'open_curator_proposal',
                'archive_with_reason',
            ],
            'forbidden_paths' => [
                'direct_provider_channel_as_primary_product',
                'hardcode_provider_as_default_from_release',
                'mark_domain_ready_from_provider_marketing',
                'copy_provider_vertical_agent_without_atlas_policy_gates',
                'bypass_evidence_ledger_or_decision_receipt',
            ],
            'success_condition' => 'Atlas+provider must beat direct provider use by Rivals/AP-99 or remain proposal-only.',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function absorptionStages(string $releaseType, string $primaryDomain, string $recommendedAction, bool $rivalsRequired, bool $blocked): array
    {
        if ($blocked) {
            return [[
                'id' => 'source_gate',
                'owner' => 'provider_evolution',
                'status' => 'blocked',
                'action' => 'attach_primary_source_before_any_absorption',
                'writes_policy' => false,
            ]];
        }

        $stages = [[
            'id' => 'release_envelope',
            'owner' => 'provider_evolution',
            'status' => 'ready',
            'action' => 'keep_provider_release_envelope_as_source_of_truth',
            'writes_policy' => false,
        ]];

        if ($rivalsRequired) {
            $stages[] = [
                'id' => 'rivals_benchmark',
                'owner' => 'rivals',
                'status' => 'required',
                'action' => 'create_or_run_rivals_suite_before_absorption',
                'writes_policy' => false,
            ];
        }

        if (in_array($recommendedAction, ['benchmark', 'absorb', 'exploit_gap'], true)) {
            $stages[] = [
                'id' => $releaseType === 'vertical_agents' ? 'domain_skill_pack' : 'capability_adapter',
                'owner' => $primaryDomain,
                'status' => 'proposal_only',
                'action' => $releaseType === 'vertical_agents'
                    ? 'extract_flows_tools_gates_and_evidence_schema'
                    : 'map_capability_to_existing_core_or_runtime_adapter',
                'writes_policy' => false,
            ];
        }

        $stages[] = [
            'id' => 'decide_signal',
            'owner' => 'atlas_decide',
            'status' => $recommendedAction === 'bypass' ? 'archive_only' : 'candidate_signal',
            'action' => $recommendedAction === 'bypass'
                ? 'archive_without_decide_signal'
                : 'propose_temporary_signal_without_routing_change',
            'writes_policy' => false,
        ];

        $stages[] = [
            'id' => 'human_review',
            'owner' => 'operator',
            'status' => 'required',
            'action' => 'approve_ap_skill_pack_or_archive_decision',
            'writes_policy' => false,
        ];

        return $stages;
    }

    /**
     * @param  array<string,mixed>|null  $sourceCandidate
     * @return array<string,mixed>
     */
    private function sourceRegistryContext(string $provider, ?array $sourceCandidate): array
    {
        $summary = $this->sourceRegistry->summary([
            'provider' => $provider === 'other' ? null : $provider,
        ]);

        return [
            'schema_version' => 'atlas.provider_release.source_registry_context.v1',
            'mode' => 'read_only_context',
            'matched_source_id' => data_get($sourceCandidate, 'source.id'),
            'provider_source_count' => $summary['source_count'],
            'provider_source_ids' => array_values(array_map(
                fn (array $source): string => (string) $source['id'],
                array_slice($summary['sources'], 0, 12),
            )),
            'guardrails' => $summary['guardrails'],
            'continuous_ingestion_contract' => $summary['continuous_ingestion_contract'],
            'future_activation_review_contract' => $summary['future_activation_review_contract'],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $sourceCandidate
     */
    private function envelopeDraftStatus(?array $sourceCandidate): string
    {
        if ($sourceCandidate === null) {
            return 'manual_review_no_source_candidate';
        }

        return data_get($sourceCandidate, 'source_trust.can_create_release_envelope_draft') === true
            ? 'draft_allowed_from_primary_source'
            : 'classification_only_pending_primary_source';
    }

    /**
     * @param  array<string,mixed>|null  $sourceCandidate
     * @return array<string,mixed>
     */
    private function sourceGate(?array $sourceCandidate): array
    {
        if ($sourceCandidate === null) {
            return [
                'schema_version' => 'atlas.provider_release.source_gate.v1',
                'status' => 'manual_input_without_source_candidate',
                'can_create_release_envelope_draft' => false,
                'primary_source_required' => true,
                'next_action' => 'attach_primary_source_url_or_human_review',
                'prohibited_outputs' => ['code_change', 'policy_patch', 'provider_routing_change', 'memory_core_promotion'],
            ];
        }

        $trust = (array) data_get($sourceCandidate, 'source_trust', []);

        return [
            'schema_version' => 'atlas.provider_release.source_gate.v1',
            'status' => data_get($trust, 'can_create_release_envelope_draft') === true
                ? 'primary_source_verified'
                : 'primary_source_required',
            'source_id' => data_get($sourceCandidate, 'source.id'),
            'source_tier' => data_get($trust, 'tier'),
            'can_create_release_envelope_draft' => (bool) data_get($trust, 'can_create_release_envelope_draft'),
            'primary_source_required' => (bool) data_get($trust, 'primary_source_required', true),
            'next_action' => (string) data_get($sourceCandidate, 'recommended_triage_action'),
            'prohibited_outputs' => (array) data_get($trust, 'prohibited_outputs', []),
        ];
    }

    private function provider(mixed $raw, string $haystack): string
    {
        $candidate = Str::of((string) $raw)->lower()->trim()->replaceMatches('/[^a-z0-9_-]+/', '_')->value();
        if (in_array($candidate, self::PROVIDERS, true)) {
            return $candidate === 'gemini' ? 'google' : $candidate;
        }

        $normalizedHaystack = Str::lower($haystack);
        foreach (self::PROVIDERS as $provider) {
            if (str_contains($normalizedHaystack, $provider)) {
                return $provider === 'gemini' ? 'google' : $provider;
            }
        }

        return 'other';
    }

    private function releaseType(mixed $raw, string $haystack): string
    {
        $candidate = Str::of((string) $raw)->lower()->trim()->replaceMatches('/[^a-z0-9_-]+/', '_')->value();
        if (in_array($candidate, self::RELEASE_TYPES, true)) {
            return $candidate;
        }

        $text = Str::lower($haystack);

        return match (true) {
            str_contains($text, 'finance') || str_contains($text, 'financial') => 'vertical_agents',
            str_contains($text, 'agent') || str_contains($text, 'vertical') => 'vertical_agents',
            str_contains($text, 'realtime') || str_contains($text, 'voice') || str_contains($text, 'audio') => 'realtime',
            str_contains($text, 'connector') || str_contains($text, 'mcp') || str_contains($text, 'integration') => 'connector',
            str_contains($text, 'tool') || str_contains($text, 'computer use') => 'tool_use',
            str_contains($text, 'memory') || str_contains($text, 'context') => 'memory',
            str_contains($text, 'code') || str_contains($text, 'coding') || str_contains($text, 'developer') => 'coding',
            str_contains($text, 'design') || str_contains($text, 'frontend') || str_contains($text, 'front end') => 'design',
            str_contains($text, 'marketing') || str_contains($text, 'ads') || str_contains($text, 'creative') => 'marketing',
            str_contains($text, 'model') || str_contains($text, 'gpt') || str_contains($text, 'claude') || str_contains($text, 'gemini') => 'model',
            default => 'capability_update',
        };
    }

    /**
     * @param  array<int,mixed>  $rawDomains
     * @return array<int,string>
     */
    private function domains(array $rawDomains, string $haystack): array
    {
        $domains = $this->uniqueStrings($rawDomains);
        $text = Str::lower($haystack);

        $keywordDomains = [
            'finance' => ['finance', 'financial', 'bank', 'market', 'risk', 'kyc'],
            'marketing' => ['marketing', 'ads', 'creative', 'copy', 'campaign'],
            'programming' => ['code', 'coding', 'developer', 'frontend', 'software', 'bug'],
            'learning' => ['learning', 'study', 'cognitive', 'education'],
            'personal_development' => ['habit', 'health', 'productivity', 'routine'],
            'self_improvement' => ['curator', 'self-improvement', 'self improvement', 'autonomous'],
        ];

        foreach ($keywordDomains as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $domains[] = $domain;
                    break;
                }
            }
        }

        $domains = $this->uniqueStrings($domains);

        return $domains === [] ? ['general'] : $domains;
    }

    /**
     * @return array<int,string>
     */
    private function affectedSurfaces(string $releaseType, array $domains): array
    {
        $surfaces = ['cli', 'api'];
        if ($releaseType === 'realtime') {
            $surfaces[] = 'mobile';
            $surfaces[] = 'voice_realtime';
        }
        if (in_array('finance', $domains, true)) {
            $surfaces[] = 'office';
        }
        if (in_array('marketing', $domains, true) || $releaseType === 'design') {
            $surfaces[] = 'app';
        }

        return $this->uniqueStrings($surfaces);
    }

    /**
     * @return array<int,string>
     */
    private function affectedRuntimes(string $releaseType): array
    {
        $runtimes = ['provider_driver', 'atlas_decide'];
        if (in_array($releaseType, ['connector', 'vertical_agents', 'tool_use'], true)) {
            $runtimes[] = 'connector_registry';
            $runtimes[] = 'super_tool_runtime';
        }
        if (in_array($releaseType, ['realtime', 'memory'], true)) {
            $runtimes[] = 'python_ai_data';
        }
        if ($releaseType === 'realtime') {
            $runtimes[] = 'swift_native_mac';
            $runtimes[] = 'mobile_native_edge';
        }

        return $this->uniqueStrings($runtimes);
    }

    private function recommendedAction(string $releaseType, array $domains, array $capabilities, array $connectors): string
    {
        if ($releaseType === 'capability_update' && $capabilities === [] && $connectors === []) {
            return 'bypass';
        }

        if (in_array($releaseType, ['vertical_agents', 'realtime', 'coding', 'design', 'marketing'], true)) {
            return 'benchmark';
        }

        if ($releaseType === 'connector' || $connectors !== []) {
            return 'absorb';
        }

        if (in_array('general', $domains, true) && $capabilities === []) {
            return 'bypass';
        }

        return 'exploit_gap';
    }

    /**
     * @return array<int,string>
     */
    private function secondaryActions(string $primary, string $releaseType): array
    {
        $actions = match ($primary) {
            'benchmark' => ['absorb', 'exploit_gap'],
            'absorb' => ['benchmark'],
            'replace' => ['benchmark'],
            'exploit_gap' => ['benchmark'],
            default => ['archive_source_material'],
        };

        if ($releaseType === 'vertical_agents') {
            $actions[] = 'create_domain_skill_pack';
        }

        return $this->uniqueStrings($actions);
    }

    /**
     * @return array<int,array{path:string,exists:bool,reason:string}>
     */
    private function ownerDocs(array $domains, string $releaseType): array
    {
        $paths = [
            'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md' => 'release protocol owner',
            'docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md' => 'channel and multiplier thesis',
            'docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md' => 'decide signal owner',
            'docs/engineering-knowledge-base/atlas-ai-governed-backlog.md' => 'AP/backlog owner',
        ];

        foreach ($domains as $domain) {
            $path = 'docs/engineering-knowledge-base/domains/'.str_replace('_', '-', $domain).'.md';
            $paths[$path] = 'affected domain owner';
        }

        if ($releaseType === 'realtime') {
            $paths['docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md'] = 'realtime surface owner';
        }

        return collect($paths)
            ->map(fn (string $reason, string $path): array => [
                'path' => $path,
                'exists' => is_file(base_path($path)),
                'reason' => $reason,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function suggestedAps(string $provider, string $releaseId, string $releaseType, array $domains, string $action): array
    {
        $slug = Str::of($releaseId)->after($provider.'-')->slug('-')->value();
        $domain = $domains[0] ?? 'general';

        return [
            [
                'id' => 'AP-PROVIDER-'.Str::upper($provider).'-'.Str::upper($slug).'-RIVALS',
                'title' => 'Rivals benchmark for '.$releaseId,
                'kind' => 'benchmark',
                'owner' => 'docs/ap/',
            ],
            [
                'id' => 'AP-PROVIDER-'.Str::upper($provider).'-'.Str::upper($slug).'-SKILL-PACK',
                'title' => 'Domain Skill Pack extraction for '.$domain,
                'kind' => $releaseType === 'vertical_agents' ? 'domain_skill_pack' : 'capability_absorption',
                'owner' => 'docs/ap/',
            ],
            [
                'id' => 'AP-PROVIDER-'.Str::upper($provider).'-'.Str::upper($slug).'-DECIDE-SIGNAL',
                'title' => 'Atlas Decide signal and AP-99 calibration',
                'kind' => $action === 'bypass' ? 'archive_decision' : 'decide_signal',
                'owner' => 'docs/ap/',
            ],
        ];
    }

    private function threatToWrappers(string $releaseType): string
    {
        return in_array($releaseType, ['vertical_agents', 'realtime', 'coding', 'design', 'marketing', 'connector'], true) ? 'high' : 'medium';
    }

    private function threatToAtlas(string $releaseType, array $domains): string
    {
        if ($releaseType === 'realtime') {
            return 'medium';
        }

        if (in_array('general', $domains, true)) {
            return 'medium';
        }

        return 'low';
    }

    private function potentialMultiplier(string $releaseType, array $domains, array $capabilities, array $connectors): string
    {
        if ($capabilities !== [] || $connectors !== []) {
            return 'high';
        }

        if (in_array($releaseType, ['vertical_agents', 'realtime', 'connector', 'coding', 'design', 'marketing', 'finance'], true)) {
            return 'high';
        }

        return in_array('general', $domains, true) ? 'medium' : 'high';
    }

    private function releaseFamily(string $releaseType): string
    {
        return match ($releaseType) {
            'vertical_agents', 'finance', 'marketing' => 'vertical_specialization',
            'realtime' => 'ambient_realtime_surface',
            'connector', 'tool_use' => 'tool_and_connector_expansion',
            'coding', 'design' => 'creation_workflow_acceleration',
            'memory' => 'context_memory_expansion',
            'model' => 'base_model_capability',
            default => 'capability_update',
        };
    }

    private function wrapperMarketImpact(string $releaseType): string
    {
        return $this->threatToWrappers($releaseType) === 'high'
            ? 'fragile_wrappers_may_die_at_this_layer'
            : 'monitor_for_absorption_or_archive';
    }

    private function rivalsRequired(string $releaseType, array $domains): bool
    {
        return in_array($releaseType, ['vertical_agents', 'realtime', 'coding', 'design', 'marketing', 'finance'], true)
            || ! in_array('general', $domains, true);
    }

    /**
     * @return array<int,string>
     */
    /**
     * @param  array<string,mixed>|null  $sourceCandidate
     * @return array<int,string>
     */
    private function risks(string $releaseType, string $recommendedAction, ?array $sourceCandidate = null): array
    {
        $risks = ['press_release_hype_without_evidence', 'hardcoded_provider_routing', 'surface_bypass_of_kernel'];

        if ($sourceCandidate !== null && data_get($sourceCandidate, 'source_trust.primary_source_required') === true) {
            $risks[] = 'source_not_primary_enough_for_release_envelope';
        }
        if ($recommendedAction === 'benchmark') {
            $risks[] = 'benchmark_debt_if_no_rivals_suite_is_created';
        }
        if ($releaseType === 'realtime') {
            $risks[] = 'privacy_regression_if_audio_raw_is_persisted';
        }
        if ($releaseType === 'vertical_agents') {
            $risks[] = 'domain_maturity_lie_if_skill_pack_is_declared_implemented_without_evidence';
        }

        return $this->uniqueStrings($risks);
    }

    private function releaseId(string $provider, string $title): string
    {
        $titleSlug = Str::of($title)->slug('-')->value();
        $titleSlug = Str::of($titleSlug)->startsWith($provider.'-')
            ? Str::of($titleSlug)->after($provider.'-')->value()
            : $titleSlug;

        return Str::of($provider.'-'.$titleSlug.'-'.now()->format('Y-m'))->slug('-')->value();
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function uniqueStrings(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => Str::of((string) $value)->lower()->trim()->replaceMatches('/[^a-z0-9_-]+/', '_')->value())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
