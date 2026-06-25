<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

/**
 * Immutable value object — one canonical task-class proposal.
 *
 * Status is ALWAYS `pending` at construction. Activation requires an explicit
 * AtlasLoopTaskClassRegistry::approve(class_id, operator_token) call.
 */
final readonly class AtlasLoopTaskClassProposal
{
    public const SCHEMA = 'atlas.loop.task_class_proposal.v1';

    public const STATUS_PENDING = 'pending';

    /**
     * @param  list<string>  $shapeRules
     * @param  list<string>  $defaultAcceptanceCriteriaTemplate
     * @param  list<string>  $defaultRequiredEvidenceKinds
     * @param  list<string>  $defaultAllowedFilesGlobs
     * @param  array<string,mixed>  $justificationFacts
     */
    public function __construct(
        public string $classId,
        public string $humanLabel,
        public array $shapeRules,
        public array $defaultAcceptanceCriteriaTemplate,
        public array $defaultRequiredEvidenceKinds,
        public array $defaultAllowedFilesGlobs,
        public array $justificationFacts,
        public string $status = self::STATUS_PENDING,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'class_id' => $this->classId,
            'human_label' => $this->humanLabel,
            'shape_rules' => $this->shapeRules,
            'default_acceptance_criteria_template' => $this->defaultAcceptanceCriteriaTemplate,
            'default_required_evidence_kinds' => $this->defaultRequiredEvidenceKinds,
            'default_allowed_files_globs' => $this->defaultAllowedFilesGlobs,
            'justification_facts' => $this->justificationFacts,
            'status' => $this->status,
        ];
    }
}
