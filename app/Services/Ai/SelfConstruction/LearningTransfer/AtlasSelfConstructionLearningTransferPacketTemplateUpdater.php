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
     * @return array{updated_template:array<string,mixed>, applied:bool, blockers:list<string>, target_field:?string}
     */
    public function apply(array $plan, array $template): array
    {
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
            return ['updated_template' => $template, 'applied' => false, 'blockers' => $blockers, 'target_field' => null];
        }

        $field = self::CLASS_TO_FIELD[$lessonClass] ?? null;
        if ($field === null) {
            return [
                'updated_template' => $template,
                'applied' => false,
                'blockers' => ['unknown_lesson_class:'.$lessonClass],
                'target_field' => null,
            ];
        }
        if (! in_array($field, self::ALLOWED_TEMPLATE_FIELDS, true)) {
            return [
                'updated_template' => $template,
                'applied' => false,
                'blockers' => ['field_not_in_allowed_template_fields:'.$field],
                'target_field' => null,
            ];
        }

        $guidanceTag = 'lesson:'.$lessonClass;
        $existing = is_array($template[$field] ?? null) ? array_values((array) $template[$field]) : [];
        if (in_array($guidanceTag, $existing, true)) {
            // Idempotent.
            return ['updated_template' => $template, 'applied' => false, 'blockers' => ['already_applied'], 'target_field' => $field];
        }

        $existing[] = $guidanceTag;
        $template[$field] = $existing;

        return ['updated_template' => $template, 'applied' => true, 'blockers' => [], 'target_field' => $field];
    }
}
