<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricCollisionAwareBatchPlanner;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricDependencyLadder;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTemplateFarmSimilarityGate;
use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphDraftQualityGate;

/** Read-only admission bridge from proposal arena to existing Task Fabric gates. */
final class AtlasTaskFabricProposalAdmissionGate
{
    public const SCHEMA = 'atlas.task_fabric.proposal_admission_gate.v1';

    public function __construct(
        private readonly ?AtlasTaskFabricTemplateFarmSimilarityGate $templateFarm = null,
        private readonly ?AtlasSelfConstructionTaskGraphDraftQualityGate $quality = null,
        private readonly ?AtlasTaskFabricCollisionAwareBatchPlanner $collision = null,
        private readonly ?AtlasTaskFabricDependencyLadder $dependency = null,
    ) {}

    /** @param list<array<string,mixed>> $candidates */
    public function evaluate(array $candidates, array $options = []): array
    {
        $drafts = array_values(array_map(static fn (array $candidate): array => is_array($candidate['task_packet'] ?? null) ? $candidate['task_packet'] : $candidate, $candidates));
        $applicable = array_values(array_filter($drafts, static fn (array $draft): bool => isset($draft['allowed_files']) || isset($draft['required_evidence']) || isset($draft['expected_delta'])));
        if ($applicable === []) {
            return ['schema' => self::SCHEMA, 'status' => 'not_applicable', 'passed' => true, 'hard_stop' => false, 'rotation_candidate_ids' => [], 'gates' => []];
        }

        $template = ($this->templateFarm ?? new AtlasTaskFabricTemplateFarmSimilarityGate())->assess($applicable);
        $quality = ($this->quality ?? new AtlasSelfConstructionTaskGraphDraftQualityGate())->evaluateBatch($applicable, (array) ($options['queue_facts'] ?? []));
        $collisionCandidates = array_map(static fn (array $draft): array => ['id' => (string) ($draft['task_packet_id'] ?? $draft['candidate_id'] ?? $draft['id'] ?? ''), 'allowed_files' => (array) ($draft['allowed_files'] ?? [])], $applicable);
        $collision = ($this->collision ?? new AtlasTaskFabricCollisionAwareBatchPlanner())->plan($collisionCandidates, array_values(array_map('strval', (array) ($options['colliding_targets'] ?? []))));
        $dependencySpecs = (array) ($options['dependency_specs'] ?? []);
        $dependency = $dependencySpecs === []
            ? ['waves' => [], 'depends_on' => [], 'blockers' => [], 'conflict_reasons' => [], 'blocked_tasks' => [], 'unlock_reason' => null]
            : ($this->dependency ?? new AtlasTaskFabricDependencyLadder())->ladder($dependencySpecs);

        $blockers = [];
        if (($template['blocking'] ?? false) === true) $blockers[] = 'template_farm';
        if (($quality['passed'] ?? true) !== true || ($quality['violations'] ?? []) !== []) $blockers[] = 'quality_gate';
        if (($collision['skipped'] ?? []) !== []) $blockers[] = 'target_collision';
        if (($dependency['blockers'] ?? []) !== []) $blockers[] = 'dependency_gate';

        $mode = strtolower(trim((string) ($options['autonomy_mode'] ?? '')));
        $rotation = [];
        if ($blockers !== [] && in_array($mode, ['dry', 'disabled'], true)) {
            $blockedVeins = array_values(array_unique(array_map('strval', (array) ($options['blocked_leverage_veins'] ?? []))));
            foreach ($candidates as $candidate) {
                $id = (string) ($candidate['candidate_id'] ?? $candidate['id'] ?? '');
                $vein = (string) ($candidate['leverage_vein'] ?? '');
                if ($id !== '' && $vein !== '' && ! in_array($vein, $blockedVeins, true)) $rotation[] = $id;
            }
            $rotation = array_values(array_unique($rotation));
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'passed' => $blockers === [],
            'hard_stop' => $blockers !== [] && $rotation === [],
            'blockers' => $blockers,
            'rotation_candidate_ids' => $rotation,
            'gates' => ['template_farm' => $template, 'quality' => $quality, 'collision' => $collision, 'dependency' => $dependency],
        ];
    }
}
