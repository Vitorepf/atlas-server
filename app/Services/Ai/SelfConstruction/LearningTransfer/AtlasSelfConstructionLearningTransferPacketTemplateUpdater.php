<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\LearningTransfer;

/**
 * Pure in-memory updater. Applies a SAFE context-update plan to a task-packet template ARRAY and returns
 * the updated array. NEVER persists, NEVER writes a file, NEVER calls a provider.
 *
 * The plan is the output of AtlasSelfConstructionLearningTransferContextUpdatePlan::plan(). The updater
 * refuses unless the plan carries rollout_class = 'bounded_proposed' and lesson_class is non-empty.
 *
 * Allowed template fields the updater may touch (anything else is refused):
 *   - acceptance_guidance
 *   - evidence_guidance
 *   - dependency_guidance
 *   - scope_guidance
 *   - give_back_guidance
 *
 * Guidance is added once per (field, lesson_class) — repeated application is idempotent.
 */
final class AtlasSelfConstructionLearningTransferPacketTemplateUpdater
{
    public const SCHEMA = 'atlas.learning_transfer.packet_template_update.v1';

    public const ALLOWED_TEMPLATE_FIELDS = [
        'acceptance_guidance',
        'evidence_guidance',
        'dependency_guidance',
        'scope_guidance',
        'give_back_guidance',
    ];

    public const FORBIDDEN_STEADY_STATE_DEPENDENCIES = [
        'operator', 'human_action', 'claude_code', 'codex', 'cursor', 'network', 'git',
    ];

    private const REQUIRED_TEMPLATE_KEYS = ['allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const MAX_GUIDANCE_TAGS = 5;

    private const CLASS_TO_FIELD = [
        'duplicate_capability' => 'acceptance_guidance',
        'scope_gap' => 'scope_guidance',
        'forbidden_target' => 'scope_guidance',
        'contradictory_acceptance' => 'acceptance_guidance',
        'missing_dependency' => 'dependency_guidance',
        'stale_context' => 'evidence_guidance',
        'insufficient_evidence' => 'evidence_guidance',
    ];

    /**
     * @param  array<string,mixed>  $plan      a context update plan (output of ContextUpdatePlan::plan())
     * @param  array<string,mixed>  $template  in-memory packet template
     * @return array{updated_template:array<string,mixed>, applied:bool, blockers:list<string>, target_field:?string, template_delta_hash:?string, evidence_refs:list<string>}
     */
    public function apply(array $plan, array $template): array
    {
        $fail = function (array $blockers, ?string $field = null) use ($template): array {
            return ['updated_template' => $template, 'applied' => false, 'blockers' => $blockers, 'target_field' => $field, 'template_delta_hash' => null, 'evidence_refs' => []];
        };

        $rollout = (string) ($plan['rollout_class'] ?? '');
        $lessonClass = (string) ($plan['lesson_class'] ?? '');
        $blockers = [];

        if ($rollout !== 'bounded_proposed') {
            $blockers[] = 'plan_not_admitted:'.$rollout;
        }
        if ($lessonClass === '') {
            $blockers[] = 'plan_lesson_class_missing';
        }
        if ($blockers !== []) {
            return $fail($blockers);
        }

        // Required template structure: any packet template must declare these keys.
        foreach (self::REQUIRED_TEMPLATE_KEYS as $key) {
            if (! array_key_exists($key, $template)) {
                $blockers[] = 'template_missing_'.$key;
            }
        }
        if ($blockers !== []) {
            return $fail($blockers);
        }

        // Forbidden steady-state dependencies: no provider/operator/network dependency allowed.
        $proposedDeps = array_values(array_map('strval', (array) ($plan['steady_state_dependencies'] ?? [])));
        foreach ($proposedDeps as $dep) {
            if (in_array($dep, self::FORBIDDEN_STEADY_STATE_DEPENDENCIES, true)) {
                $blockers[] = 'forbidden_steady_state_dependency:'.$dep;
            }
        }
        if ($blockers !== []) {
            return $fail($blockers);
        }

        $field = self::CLASS_TO_FIELD[$lessonClass] ?? null;
        if ($field === null) {
            return $fail(['unknown_lesson_class:'.$lessonClass]);
        }
        if (! in_array($field, self::ALLOWED_TEMPLATE_FIELDS, true)) {
            return $fail(['field_not_in_allowed_template_fields:'.$field]);
        }

        $guidanceTag = 'lesson:'.$lessonClass;
        $existing = is_array($template[$field] ?? null) ? array_values((array) $template[$field]) : [];

        if (in_array($guidanceTag, $existing, true)) {
            return $fail(['already_applied'], $field);
        }

        // Template-farm guard: too many guidance tags = no real capability delta.
        if (count($existing) >= self::MAX_GUIDANCE_TAGS) {
            return $fail(['no_capability_delta'], $field);
        }

        $existing[] = $guidanceTag;
        $template[$field] = $existing;

        $evidenceRefs = array_values(array_map('strval', (array) ($plan['evidence_refs'] ?? [])));
        $deltaHash = hash('sha256', (string) json_encode(['field' => $field, 'tags' => $existing], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ['updated_template' => $template, 'applied' => true, 'blockers' => [], 'target_field' => $field, 'template_delta_hash' => $deltaHash, 'evidence_refs' => $evidenceRefs];
    }
}
