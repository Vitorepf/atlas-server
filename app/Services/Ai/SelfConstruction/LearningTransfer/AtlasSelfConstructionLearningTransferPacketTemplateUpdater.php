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
 *   - allowed_files_guidance
 *   - poison_prevention_guidance
 *
 * Guidance is added once per (field, lesson_class) — repeated application is idempotent.
 *
 * lesson_strength (optional, float 0..1 on the plan): a plan with an explicit strength below
 * MIN_LESSON_STRENGTH is refused with lesson_too_weak — weak lessons must not update the template.
 * Omitted entirely ⇒ no-op (treated as strong), preserving prior behavior for every plan that never
 * declared a strength.
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
        'allowed_files_guidance',
        'poison_prevention_guidance',
    ];

    public const FORBIDDEN_STEADY_STATE_DEPENDENCIES = [
        'operator', 'human_action', 'claude_code', 'codex', 'cursor', 'network', 'git',
    ];

    public const MIN_LESSON_STRENGTH = 0.5;

    private const REQUIRED_TEMPLATE_KEYS = ['allowed_files', 'acceptance_criteria', 'required_evidence'];

    private const MAX_GUIDANCE_TAGS = 5;

    private const RUNNABLE_ACCEPTANCE_MARKERS = ['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'];

    private const CLASS_TO_FIELD = [
        'duplicate_capability' => 'acceptance_guidance',
        'scope_gap' => 'scope_guidance',
        'forbidden_target' => 'scope_guidance',
        'contradictory_acceptance' => 'acceptance_guidance',
        'missing_dependency' => 'dependency_guidance',
        'stale_context' => 'evidence_guidance',
        'insufficient_evidence' => 'evidence_guidance',
        'allowed_files_gap' => 'allowed_files_guidance',
        'poison_pattern' => 'poison_prevention_guidance',
    ];

    /**
     * @param  array<string,mixed>  $plan      a context update plan (output of ContextUpdatePlan::plan())
     * @param  array<string,mixed>  $template  in-memory packet template
     * @return array{updated_template:array<string,mixed>, applied:bool, blockers:list<string>, target_field:?string, template_delta_hash:?string, evidence_refs:list<string>, reason:?string}
     */
    public function apply(array $plan, array $template): array
    {
        $fail = function (array $blockers, ?string $field = null) use ($template): array {
            return ['updated_template' => $template, 'applied' => false, 'blockers' => $blockers, 'target_field' => $field, 'template_delta_hash' => null, 'evidence_refs' => [], 'reason' => null];
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
        $evidenceRefs = array_values(array_filter(array_map('strval', (array) ($plan['evidence_refs'] ?? []))));
        if ($evidenceRefs === []) {
            $blockers[] = 'plan_evidence_refs_missing';
        }
        if (array_key_exists('lesson_strength', $plan) && (float) $plan['lesson_strength'] < self::MIN_LESSON_STRENGTH) {
            $blockers[] = 'lesson_too_weak:'.((float) $plan['lesson_strength']);
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
        $mutatedTemplate = $template;
        $mutatedTemplate[$field] = $existing;

        // A learned mutation must never leave the packet unclaimable: implementation scope, test
        // scope, a runnable acceptance proof, and required evidence must all survive the change.
        $contractViolations = $this->claimableContractViolations($mutatedTemplate);
        if ($contractViolations !== []) {
            return $fail(array_map(static fn (string $v): string => 'claimable_contract_violated:'.$v, $contractViolations), $field);
        }

        $evidenceRefs = array_values(array_map('strval', (array) ($plan['evidence_refs'] ?? [])));
        $deltaHash = hash('sha256', (string) json_encode(['field' => $field, 'tags' => $existing], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'updated_template' => $mutatedTemplate,
            'applied' => true,
            'blockers' => [],
            'target_field' => $field,
            'template_delta_hash' => $deltaHash,
            'evidence_refs' => $evidenceRefs,
            'reason' => 'claimable_contract_preserved',
        ];
    }

    /**
     * @param  array<string,mixed>  $template
     * @return list<string>
     */
    private function claimableContractViolations(array $template): array
    {
        $violations = [];

        $allowedFiles = array_values(array_map('strval', (array) ($template['allowed_files'] ?? [])));
        $implementationFiles = array_values(array_filter($allowedFiles, static fn (string $f): bool => ! self::isTestPath($f)));
        $testFiles = array_values(array_filter($allowedFiles, [self::class, 'isTestPath']));

        if ($implementationFiles === []) {
            $violations[] = 'missing_implementation_scope';
        }
        if ($testFiles === []) {
            $violations[] = 'missing_test_scope';
        }

        $acceptanceCriteria = array_values(array_map('strval', (array) ($template['acceptance_criteria'] ?? [])));
        $hasRunnableAcceptance = false;
        foreach ($acceptanceCriteria as $criterion) {
            $lower = strtolower($criterion);
            foreach (self::RUNNABLE_ACCEPTANCE_MARKERS as $marker) {
                if (str_contains($lower, $marker)) {
                    $hasRunnableAcceptance = true;

                    break 2;
                }
            }
        }
        if (! $hasRunnableAcceptance) {
            $violations[] = 'missing_runnable_acceptance';
        }

        $requiredEvidence = array_values(array_map('strval', (array) ($template['required_evidence'] ?? [])));
        if ($requiredEvidence === []) {
            $violations[] = 'missing_required_evidence';
        }

        return $violations;
    }

    private static function isTestPath(string $path): bool
    {
        $norm = ltrim(str_replace('\\', '/', trim($path)), '/');

        return str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/') || str_ends_with($norm, 'Test.php');
    }
}
