<?php

namespace App\Services\Ai\DomainProfiles;

class KnowledgeProfileBuilder
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function domains(): array
    {
        return [
            'general' => [
                'id' => 'general',
                'label' => 'General Answer',
                'status' => 'active',
                'default_flow' => 'general.answer',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime_family' => 'conversation',
                'description' => 'Governed answer and triage fallback for simple questions; specialized work must hand off to the owning Atlas domain through Decide.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->generalContextPolicy(),
                'memory_policy' => $this->generalMemoryPolicy(),
                'gate_policy' => $this->generalDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ask', 'api:ai/chat', 'app:chat', 'mcp:open_brain'],
                    'surface_policy' => [
                        'answer_or_triage_only' => true,
                        'specialized_requests_must_handoff' => true,
                        'no_policy_bypass' => true,
                    ],
                    'learning_policy' => [
                        'promote_only_reviewed_general_guidance' => true,
                        'handoff_misses_feed_intent_router' => true,
                    ],
                ],
            ],
            'research' => [
                'id' => 'research',
                'label' => 'Research',
                'status' => 'active',
                'default_flow' => 'research.quick',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime_family' => 'research',
                'description' => 'Source-grounded research, learning, synthesis, citation review, contradiction mapping, and knowledge promotion planning.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
                'context_policy' => $this->researchContextPolicy(),
                'memory_policy' => $this->researchMemoryPolicy(),
                'gate_policy' => $this->researchDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=research', 'api:ai/domains', 'app:research', 'mcp:open_brain'],
                    'surface_policy' => [
                        'source_grounded_by_default' => true,
                        'memory_promotion_requires_review' => true,
                        'unsourced_claims_are_blocked' => true,
                    ],
                    'learning_policy' => [
                        'accepted_research_packets_feed_memory' => true,
                        'low_confidence_claims_create_review_items' => true,
                        'contradictions_feed_self_improvement' => true,
                    ],
                ],
            ],
            'learning' => [
                'id' => 'learning',
                'label' => 'Learning',
                'status' => 'active',
                'default_flow' => 'learning.plan',
                'orchestrator' => 'AtlasLearningOrchestrator',
                'runtime_family' => 'learning',
                'description' => 'Human learning domain for study plans, deliberate practice, review, spaced repetition, and mastery evidence without modifying the Core Learning Plane.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
                'context_policy' => $this->learningContextPolicy(),
                'memory_policy' => $this->learningMemoryPolicy(),
                'gate_policy' => $this->learningDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=learning', 'api:ai/domains', 'app:learning', 'mcp:open_brain'],
                    'surface_policy' => [
                        'human_learning_domain_only' => true,
                        'core_learning_plane_not_modified' => true,
                        'calendar_and_task_mutation_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'accepted_mastery_evidence_feeds_memory' => true,
                        'practice_outcomes_feed_review_packets' => true,
                        'domain_findings_feed_self_improvement' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function flows(): array
    {
        return [
            'general.answer' => [
                'id' => 'general.answer',
                'domain_id' => 'general',
                'label' => 'Answer',
                'status' => 'active',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime' => 'StandardAiResponse',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'context_policy' => $this->generalContextPolicy(),
                'memory_policy' => $this->generalMemoryPolicy(),
                'gate_policy' => [
                    'required' => ['question_present', 'answer_or_triage_only', 'domain_handoff_review'],
                    'global_required' => ['no_policy_bypass', 'no_destructive_action'],
                    'autonomy_ceiling' => 'answer_or_triage_only',
                    'requires_final_summary' => true,
                ],
                'execution_policy' => [
                    'executor_preference' => 'general_answer_packet_runtime',
                    'answer_or_triage_only' => true,
                    'specialized_work_allowed' => false,
                    'destructive_action_allowed' => false,
                    'tool_execution_allowed' => false,
                    'provider_override_allowed' => false,
                ],
                'metadata' => ['registry' => 'static_fallback'],
            ],
            'research.quick' => $this->researchFlow(
                'research.quick',
                'Quick Research',
                'medium',
                ['source_refs', 'citation_policy', 'uncertainty_statement']
            ),
            'research.super' => $this->researchFlow(
                'research.super',
                'Super Research',
                'high',
                ['source_refs', 'citation_policy', 'uncertainty_statement', 'contradiction_check', 'promotion_review']
            ),
            ...$this->learningStaticFlows(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generalContextPolicy(): array
    {
        return [
            'preset' => 'general_answer_context',
            'require_context_pack' => false,
            'sources' => ['conversation_context', 'explicit_user_context', 'surface_hints', 'domain_catalog'],
            'budget' => [
                'max_prompt_tokens' => 6000,
                'reserved_output_tokens' => 2000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generalMemoryPolicy(): array
    {
        return [
            'projection' => 'general',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_general_guidance', 'reviewed_intent_handoff'],
                'requires_review_for' => ['new_general_policy', 'domain_handoff_rule'],
                'never_auto_promote_specialized_advice' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generalDomainGatePolicy(): array
    {
        return [
            'required' => ['question_present', 'answer_or_triage_only', 'domain_handoff_review'],
            'specialized_work_requires' => ['atlas_decide', 'domain_profile', 'flow_profile'],
            'autonomy_ceiling' => 'answer_or_triage_only',
            'destructive_action_allowed' => false,
            'tool_execution_allowed' => false,
            'provider_override_allowed' => false,
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function researchFlow(string $id, string $label, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'research',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasResearchOrchestrator',
            'runtime' => 'ResearchRuntime',
            'description' => "{$label} canonical Research flow.",
            'autonomy' => $autonomy,
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->researchContextPolicy(),
            'memory_policy' => $this->researchMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['source_refs', 'citation_policy', 'uncertainty_statement'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'research_packet_runtime',
                'source_grounded' => true,
                'memory_promotion' => 'proposal_only',
                'unsourced_claims_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_packets' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function researchContextPolicy(): array
    {
        return [
            'preset' => 'source_grounded_research_context',
            'require_context_pack' => true,
            'sources' => [
                'user_question',
                'source_refs',
                'atlas_knowledge_base',
                'open_brain_memory',
                'code_intelligence',
                'content_intelligence',
                'external_docs_when_allowed',
                'evidence_ledger',
            ],
            'provider_context_requires_citations' => true,
            'budget' => [
                'max_prompt_tokens' => 18000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function researchMemoryPolicy(): array
    {
        return [
            'projection' => 'research',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_research_packet', 'verified_source_note', 'contradiction_resolved'],
                'requires_review_for' => ['new_domain_knowledge', 'atlas_process_change', 'operator_learning_protocol'],
                'never_auto_promote_unsourced_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function researchDomainGatePolicy(): array
    {
        return [
            'required' => ['source_refs', 'citation_policy', 'uncertainty_statement'],
            'super_research_requires' => ['contradiction_check', 'promotion_review'],
            'autonomy_ceiling' => 'source_grounded_review',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function learningStaticFlows(): array
    {
        return [
            'learning.plan' => $this->learningFlow('learning.plan', 'Learning Plan', 'LearningRuntime', 'medium', ['learning_objective', 'skill_scope', 'target_level']),
            'learning.practice' => $this->learningFlow('learning.practice', 'Practice', 'LearningPracticeRuntime', 'medium', ['practice_loop', 'feedback_loop', 'mastery_rubric']),
            'learning.review' => $this->learningFlow('learning.review', 'Review', 'LearningReviewRuntime', 'low', ['outcome_evidence', 'gap_map', 'next_iteration']),
            'learning.spaced_review' => $this->learningFlow('learning.spaced_review', 'Spaced Review', 'LearningSpacedReviewRuntime', 'low', ['spaced_review', 'retrieval_practice', 'forgetting_risk']),
            'learning.worked_example' => $this->learningFlow('learning.worked_example', 'Worked Example', 'LearningWorkedExampleRuntime', 'medium', ['worked_example_appropriate_for_stage', 'pedagogy_matches_stage', 'mastery_rubric']),
            'learning.pattern_extraction' => $this->learningFlow('learning.pattern_extraction', 'Pattern Extraction', 'LearningPatternExtractionRuntime', 'low', ['pattern_structure_complete', 'pattern_personal_evidence_provider_safe']),
            'learning.process_optimization' => $this->learningFlow('learning.process_optimization', 'Process Optimization', 'LearningProcessOptimizationRuntime', 'medium', ['pattern_structure_complete', 'outcome_evidence']),
            'learning.failure_review' => $this->learningFlow('learning.failure_review', 'Failure Review', 'LearningFailureReviewRuntime', 'low', ['failure_signature_classified', 'failure_signature_provider_safety', 'outcome_evidence']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function learningFlow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'learning',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasLearningOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Learning flow.",
            'autonomy' => $autonomy,
            'background_allowed' => true,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->learningContextPolicy(),
            'memory_policy' => $this->learningMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['learning_objective', 'practice_loop', 'mastery_rubric'],
                'autonomy_ceiling' => 'plan_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'learning_packet_runtime',
                'plan_only_until_operator_acceptance' => true,
                'calendar_mutation' => false,
                'task_mutation' => false,
                'core_learning_plane_mutation' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_mastery_evidence' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningContextPolicy(): array
    {
        return [
            'preset' => 'human_learning_context',
            'require_context_pack' => true,
            'sources' => [
                'learning_goal',
                'current_skill_profile',
                'target_skill_profile',
                'source_material',
                'practice_history',
                'mistake_log',
                'retrieval_practice_results',
                'atlas_vault_curated_notes',
            ],
            'budget' => [
                'max_prompt_tokens' => 14000,
                'reserved_output_tokens' => 5000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningMemoryPolicy(): array
    {
        return [
            'projection' => 'learning',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_learning_plan', 'mastery_evidence', 'practice_outcome', 'reviewed_mistake_pattern'],
                'requires_review_for' => ['skill_profile_change', 'long_term_learning_protocol', 'operator_cognitive_pattern'],
                'never_auto_promote_unreviewed_mastery_claims' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningDomainGatePolicy(): array
    {
        return [
            'required' => ['learning_objective', 'skill_scope', 'target_level', 'practice_loop', 'mastery_rubric'],
            'review_requires' => ['outcome_evidence', 'gap_map', 'next_iteration'],
            'autonomy_ceiling' => 'plan_only',
            'core_learning_plane_mutation' => false,
        ];
    }
}
