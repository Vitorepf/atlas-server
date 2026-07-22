<?php

namespace App\Services\Ai\DomainProfiles;

class PersonalProfileBuilder
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function domains(): array
    {
        return [
            'personal_development' => [
                'id' => 'personal_development',
                'label' => 'Personal Development',
                'status' => 'active',
                'default_flow' => 'personal_development.reflect',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime_family' => 'personal_development',
                'description' => 'Private, non-clinical planning and reflection for habits, routines, focus, energy, learning, personal performance, and life review.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->personalDevelopmentContextPolicy(),
                'memory_policy' => $this->personalDevelopmentMemoryPolicy(),
                'gate_policy' => $this->personalDevelopmentDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=personal_development', 'api:ai/domains', 'app:personal_development', 'mcp:open_brain'],
                    'surface_policy' => [
                        'private_by_default' => true,
                        'plan_and_artifacts_only' => true,
                        'no_auto_calendar_or_task_mutation' => true,
                    ],
                    'learning_policy' => [
                        'accepted_reflections_feed_private_memory' => true,
                        'sensitive_items_require_review_before_promotion' => true,
                    ],
                ],
            ],
            'writing' => [
                'id' => 'writing',
                'label' => 'Writing',
                'status' => 'active',
                'default_flow' => 'writing.draft',
                'orchestrator' => 'AtlasWritingOrchestrator',
                'runtime_family' => 'writing',
                'description' => 'Governed drafting, editing, voice review, publication review, and source-aware writing workflows.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
                'context_policy' => $this->writingContextPolicy(),
                'memory_policy' => $this->writingMemoryPolicy(),
                'gate_policy' => $this->writingDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=writing', 'api:ai/domains', 'app:writing', 'mcp:open_brain'],
                    'surface_policy' => [
                        'draft_until_operator_approval' => true,
                        'external_publish_requires_explicit_human_review' => true,
                        'voice_alignment_gate_required' => true,
                    ],
                    'learning_policy' => [
                        'accepted_voice_feedback_updates_projection' => true,
                        'published_artifact_outcomes_feed_memory' => true,
                        'publication_risk_blocks_feed_self_improvement' => true,
                    ],
                ],
            ],
            'health' => [
                'id' => 'health',
                'label' => 'Health',
                'status' => 'active',
                'default_flow' => 'health.review',
                'orchestrator' => 'AtlasHealthOrchestrator',
                'runtime_family' => 'health',
                'description' => 'Non-clinical wellness review, routine review, recovery review and safety review without diagnosis, treatment, dosage or emergency decisions.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
                'context_policy' => $this->healthContextPolicy(),
                'memory_policy' => $this->healthMemoryPolicy(),
                'gate_policy' => $this->healthDomainGatePolicy(),
                'metadata' => [
                    'registry' => 'static_fallback',
                    'surfaces' => ['cli:atlas ai --domain=health', 'api:ai/domains', 'app:health', 'mcp:open_brain'],
                    'surface_policy' => [
                        'non_clinical_review_only' => true,
                        'professional_review_required_for_risk_flags' => true,
                        'medical_decisions_forbidden' => true,
                    ],
                    'learning_policy' => [
                        'promote_only_reviewed_wellness_patterns' => true,
                        'never_promote_medical_decisions' => true,
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
            ...$this->personalDevelopmentStaticFlows(),
            ...$this->writingStaticFlows(),
            ...$this->healthStaticFlows(),
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function personalDevelopmentStaticFlows(): array
    {
        return [
            'personal_development.reflect' => $this->personalDevelopmentFlow('personal_development.reflect', 'Reflect', 'reflection_runtime', 'Guided non-clinical reflection that turns observations into evidence and small experiments.', ['privacy_review', 'non_clinical_language']),
            'personal_development.daily_review' => $this->personalDevelopmentFlow('personal_development.daily_review', 'Daily Review', 'daily_review_runtime', 'Daily review of routine evidence, focus, energy, commitments, and next experiment.', ['privacy_review', 'evidence_link']),
            'personal_development.weekly_review' => $this->personalDevelopmentFlow('personal_development.weekly_review', 'Weekly Review', 'weekly_review_runtime', 'Weekly review across goals, routines, learning, energy, and personal operating rhythm.', ['privacy_review', 'evidence_link']),
            'personal_development.habit_design' => $this->personalDevelopmentFlow('personal_development.habit_design', 'Habit Design', 'habit_design_runtime', 'Habit design flow that drafts cues, friction changes, minimum viable routines, and review checkpoints.', ['privacy_review', 'routine_experiment']),
            'personal_development.focus_plan' => $this->personalDevelopmentFlow('personal_development.focus_plan', 'Focus Plan', 'focus_plan_runtime', 'Focus planning flow for attention budget, priority evidence, focus blocks, and interruption policy.', ['privacy_review', 'attention_budget']),
            'personal_development.learning_plan' => $this->personalDevelopmentFlow('personal_development.learning_plan', 'Learning Plan', 'learning_plan_runtime', 'Learning plan flow for skill goals, practice loops, evidence, and lightweight review cadence.', ['privacy_review', 'learning_evidence']),
            'personal_development.energy_review' => $this->personalDevelopmentFlow('personal_development.energy_review', 'Energy Review', 'energy_review_runtime', 'Non-clinical energy review focused on routine patterns, load, recovery evidence, and experiments.', ['privacy_review', 'non_clinical_language', 'evidence_link']),
            'personal_development.goal_decomposition' => $this->personalDevelopmentFlow('personal_development.goal_decomposition', 'Goal Decomposition', 'goal_decomposition_runtime', 'Goal decomposition flow that breaks outcomes into projects, next actions, risks, and review evidence.', ['privacy_review', 'bounded_plan']),
            'personal_development.recovery_plan' => $this->personalDevelopmentFlow('personal_development.recovery_plan', 'Recovery Plan', 'recovery_plan_runtime', 'Non-clinical recovery plan for workload, rest routines, boundaries, and review checkpoints.', ['privacy_review', 'non_clinical_language', 'human_review_for_sensitive']),
            'personal_development.forge' => $this->personalDevelopmentFlow('personal_development.forge', 'Forge', 'personal_development_forge_runtime', 'Integrated personal operating plan across goals, habits, focus, learning, energy, recovery, and review loops.', ['privacy_review', 'human_approval', 'non_clinical_language', 'no_auto_calendar_or_task_changes'], true),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function personalDevelopmentFlow(string $id, string $label, string $executor, string $description, array $requiredGates, bool $approvalRequired = false): array
    {
        return [
            'id' => $id,
            'domain_id' => 'personal_development',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
            'runtime' => 'PersonalDevelopmentRuntime',
            'description' => $description,
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->personalDevelopmentContextPolicy(),
            'memory_policy' => $this->personalDevelopmentMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['privacy_review', 'non_clinical_language', 'no_diagnosis'],
                'sensitive_requires' => ['human_review'],
                'autonomy_ceiling' => 'plan_only',
            ],
            'execution_policy' => [
                'executor_preference' => $executor,
                'plan_and_artifacts_only' => true,
                'calendar_mutation' => false,
                'task_mutation' => false,
                'forge_requires_human_approval' => $approvalRequired,
                'sensitive_recommendations_require_review' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_private_reviewed_patterns' => true,
                    'feed_self_improvement' => true,
                ],
                'approval_required' => $approvalRequired,
                'artifacts' => ['structured_plan', 'review_prompts'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personalDevelopmentContextPolicy(): array
    {
        return [
            'preset' => 'personal_development_private_context',
            'require_context_pack' => true,
            'sources' => [
                'operator_goals',
                'habit_notes',
                'daily_review_notes',
                'weekly_review_notes',
                'focus_logs',
                'learning_notes',
                'energy_observations',
                'routine_experiments',
            ],
            'provider_context_requires_redaction' => true,
            'budget' => [
                'max_prompt_tokens' => 10000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personalDevelopmentMemoryPolicy(): array
    {
        return [
            'projection' => 'personal_development',
            'privacy_default' => 'private',
            'provider_safe_default' => false,
            'provider_safe_only_when_redacted' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_reflection', 'routine_experiment_result', 'reviewed_goal_change'],
                'requires_review_for' => ['sensitive_recommendation', 'identity_level_claim', 'life_review_summary'],
                'never_auto_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function personalDevelopmentDomainGatePolicy(): array
    {
        return [
            'required' => ['privacy_review', 'non_clinical_language', 'no_diagnosis', 'evidence_link'],
            'sensitive_requires' => ['human_review'],
            'autonomy_ceiling' => 'plan_only',
            'forbidden' => ['medical_treatment', 'psychological_diagnosis', 'automatic_calendar_or_task_mutation'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function writingStaticFlows(): array
    {
        return [
            'writing.draft' => $this->writingFlow('writing.draft', 'Draft', 'WritingRuntime', 'medium', ['brief_clarity', 'audience_fit', 'voice_alignment']),
            'writing.edit' => $this->writingFlow('writing.edit', 'Edit', 'WritingEditRuntime', 'medium', ['brief_clarity', 'voice_alignment', 'change_rationale']),
            'writing.voice_review' => $this->writingFlow('writing.voice_review', 'Voice Review', 'WritingVoiceRuntime', 'low', ['voice_alignment', 'voice_drift_findings', 'operator_review']),
            'writing.publish_review' => $this->writingFlow('writing.publish_review', 'Publish Review', 'WritingReviewRuntime', 'low', ['publication_review', 'claim_review', 'human_review_required']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function writingFlow(string $id, string $label, string $runtime, string $autonomy, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'writing',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasWritingOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Writing flow.",
            'autonomy' => $autonomy,
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->writingContextPolicy(),
            'memory_policy' => $this->writingMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['brief_clarity', 'voice_alignment', 'human_review_required'],
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'writing_packet_runtime',
                'draft_until_operator_approval' => true,
                'external_publish_allowed' => false,
                'quality_required' => true,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_accepted_voice_feedback' => true,
                    'feed_self_improvement' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writingContextPolicy(): array
    {
        return [
            'preset' => 'governed_writing_context',
            'require_context_pack' => true,
            'sources' => [
                'writing_brief',
                'target_audience',
                'operator_voice_samples',
                'source_material',
                'style_guide',
                'publication_constraints',
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
    private function writingMemoryPolicy(): array
    {
        return [
            'projection' => 'writing',
            'provider_safe_default' => true,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['accepted_draft', 'operator_voice_feedback', 'published_artifact_outcome'],
                'requires_review_for' => ['voice_rule', 'public_claim', 'sensitive_story'],
                'never_auto_publish' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writingDomainGatePolicy(): array
    {
        return [
            'required' => ['brief_clarity', 'audience_fit', 'voice_alignment', 'human_review_required'],
            'publish_requires' => ['operator_approval', 'claim_review', 'sensitive_disclosure_review'],
            'autonomy_ceiling' => 'draft_and_review',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function healthStaticFlows(): array
    {
        return [
            'health.review' => $this->healthFlow('health.review', 'Health Review', 'HealthReviewRuntime', ['health_scope', 'non_clinical_boundary', 'professional_review_notice']),
            'health.routine_review' => $this->healthFlow('health.routine_review', 'Routine Review', 'HealthRoutineRuntime', ['routine_observations', 'constraints', 'professional_review_notice']),
            'health.recovery_review' => $this->healthFlow('health.recovery_review', 'Recovery Review', 'HealthRecoveryRuntime', ['recovery_considerations', 'risk_flags', 'non_clinical_next_steps']),
            'health.safety_review' => $this->healthFlow('health.safety_review', 'Safety Review', 'HealthSafetyRuntime', ['red_flags', 'escalation_notice', 'do_not_delay_care']),
        ];
    }

    /**
     * @param  array<int,string>  $requiredGates
     * @return array<string,mixed>
     */
    private function healthFlow(string $id, string $label, string $runtime, array $requiredGates): array
    {
        return [
            'id' => $id,
            'domain_id' => 'health',
            'label' => $label,
            'status' => 'active',
            'orchestrator' => 'AtlasHealthOrchestrator',
            'runtime' => $runtime,
            'description' => "{$label} canonical Health flow for non-clinical wellness review only.",
            'autonomy' => 'low',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'context_policy' => $this->healthContextPolicy(),
            'memory_policy' => $this->healthMemoryPolicy(),
            'gate_policy' => [
                'required' => $requiredGates,
                'global_required' => ['health_scope', 'non_clinical_boundary', 'professional_review_notice'],
                'autonomy_ceiling' => 'non_clinical_review_only',
                'requires_final_summary' => true,
            ],
            'execution_policy' => [
                'executor_preference' => 'health_review_packet_runtime',
                'non_clinical_review_only' => true,
                'diagnosis_allowed' => false,
                'treatment_allowed' => false,
                'dosage_change_allowed' => false,
                'emergency_decision_allowed' => false,
                'professional_care_replacement_allowed' => false,
            ],
            'metadata' => [
                'registry' => 'static_fallback',
                'surfaces' => ['cli', 'api', 'app', 'mcp'],
                'learning' => [
                    'record_evidence' => true,
                    'promote_reviewed_wellness_patterns' => true,
                    'never_promote_medical_decisions' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthContextPolicy(): array
    {
        return [
            'preset' => 'health_non_clinical_context',
            'require_context_pack' => true,
            'sources' => ['topic', 'goal', 'signals', 'constraints', 'risk_flags', 'operator_notes', 'evidence_refs'],
            'budget' => [
                'max_prompt_tokens' => 8000,
                'reserved_output_tokens' => 3000,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthMemoryPolicy(): array
    {
        return [
            'projection' => 'health',
            'provider_safe_default' => false,
            'record_usage' => true,
            'learning' => [
                'promote_from' => ['reviewed_wellness_pattern', 'accepted_routine_observation'],
                'requires_review_for' => ['health_related_memory', 'sensitive_personal_data', 'risk_flag_pattern'],
                'never_auto_promote_medical_decisions' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function healthDomainGatePolicy(): array
    {
        return [
            'required' => ['health_scope', 'non_clinical_boundary', 'professional_review_notice'],
            'risk_flags_require' => ['professional_review_notice', 'do_not_delay_care', 'no_self_treatment'],
            'autonomy_ceiling' => 'non_clinical_review_only',
            'diagnosis_allowed' => false,
            'treatment_allowed' => false,
            'dosage_change_allowed' => false,
            'emergency_decision_allowed' => false,
            'professional_care_replacement_allowed' => false,
        ];
    }
}
