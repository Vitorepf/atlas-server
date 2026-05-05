<?php

namespace App\Services\Ai\PersonalDevelopment;

use Illuminate\Support\Str;

class PersonalDevelopmentRuntime
{
    public function __construct(
        private readonly PersonalDevelopmentSafetyPolicy $safety,
        private readonly PersonalDevelopmentInputNormalizer $inputNormalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function execute(string $flow, array $input = [], array $options = []): array
    {
        $flow = AtlasPersonalDevelopmentOrchestrator::normalizeFlowName($flow);
        $normalized = $this->inputNormalizer->normalize($input);
        $runtimeInput = $normalized['input'];
        $inputContract = $normalized['contract'];
        $sensitivity = $this->safety->sensitivity($input);
        $approved = (bool) ($options['human_approved'] ?? false);
        $approvalRequired = $this->safety->approvalRequired($flow, $sensitivity);
        $status = $this->safety->runtimeStatus($approvalRequired, $approved);

        return [
            'schema_version' => 1,
            'runtime_id' => (string) Str::orderedUuid(),
            'runtime' => 'PersonalDevelopmentRuntime',
            'domain' => 'personal_development',
            'flow' => $flow,
            'status' => $status,
            'approval_required' => $approvalRequired,
            'approval_reasons' => $approvalRequired ? $this->safety->approvalReasons($flow, $sensitivity) : [],
            'input_contract' => $inputContract,
            'sensitivity' => $sensitivity,
            'safety_contract' => $this->safety->safetyContract(),
            'plan' => $this->planForFlow($flow, $runtimeInput, $status, $inputContract),
            'artifacts' => $this->artifactsForFlow($flow, $runtimeInput, $inputContract),
            'evidence_refs' => $this->evidenceRefs($options),
            'blocked_actions' => $this->safety->blockedActions(),
            'side_effects' => $this->safety->sideEffects(),
            'completed_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function planForFlow(string $flow, array $input, string $status, array $inputContract): array
    {
        $contract = PersonalDevelopmentFlowCatalog::get($flow);
        $intent = trim((string) ($input['intent'] ?? $input['goal'] ?? $input['desired_outcome'] ?? ''));

        return [
            'type' => $contract['type'],
            'status' => $status,
            'intent' => $intent !== '' ? $intent : null,
            'input_ready' => (bool) ($inputContract['has_intent'] ?? false),
            'focus_areas' => $contract['focus'],
            'steps' => [
                ['name' => 'capture_context', 'mode' => 'reflection', 'side_effect' => false],
                ['name' => 'identify_evidence', 'mode' => 'evidence', 'side_effect' => false],
                ['name' => 'draft_routine_experiment', 'mode' => 'plan', 'side_effect' => false],
                ['name' => 'define_review_checkpoint', 'mode' => 'experiment', 'side_effect' => false],
            ],
            'output_rules' => [
                'avoid_diagnosis' => true,
                'avoid_medical_advice' => true,
                'use_operational_language' => true,
                'propose_changes_as_drafts' => true,
            ],
            'review_checkpoint' => [
                'cadence' => $contract['cadence'],
                'question' => 'What evidence changed, and what routine experiment should be adjusted?',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function artifactsForFlow(string $flow, array $input, array $inputContract): array
    {
        $contract = PersonalDevelopmentFlowCatalog::get($flow);

        return [
            [
                'id' => $contract['artifact'],
                'kind' => 'structured_plan',
                'flow' => $flow,
                'privacy_class' => 'private',
                'provider_safe_requires_redaction' => true,
                'content' => [
                    'inputs_used' => array_keys($input),
                    'input_schema' => PersonalDevelopmentInputNormalizer::allowedKeys(),
                    'ignored_input_keys' => $inputContract['ignored_keys'] ?? [],
                    'focus_areas' => $contract['focus'],
                    'routine_experiment' => 'Draft one bounded experiment and review evidence before changing systems.',
                    'no_automatic_changes' => true,
                ],
            ],
            [
                'id' => 'review_prompts',
                'kind' => 'reflection_prompts',
                'flow' => $flow,
                'privacy_class' => 'private',
                'content' => [
                    'What happened?',
                    'What evidence supports that interpretation?',
                    'What small routine experiment is worth trying next?',
                ],
            ],
            [
                'id' => 'human_review_packet',
                'kind' => 'approval_context',
                'flow' => $flow,
                'privacy_class' => 'private',
                'content' => [
                    'required_for_sensitive_recommendations' => true,
                    'required_for_forge' => $flow === 'personal_development.forge',
                    'input_ready' => (bool) ($inputContract['has_intent'] ?? false),
                    'decision' => 'approve, revise, or discard the draft plan before applying it anywhere',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<int,array<string,string>>
     */
    private function evidenceRefs(array $options): array
    {
        $refs = $options['evidence_refs'] ?? [];

        if (! is_array($refs)) {
            return [];
        }

        return array_values(array_filter($refs, fn (mixed $ref): bool => is_array($ref)
            && is_string($ref['type'] ?? null)
            && is_string($ref['id'] ?? null)));
    }
}
