<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;

/**
 * The next-tier primitive: the conductor authoring a per-task orchestration shape.
 *
 * Today the conductor runs exactly one dispatch -> one execute. This gate lets the
 * model propose a small, bounded, acyclic plan-DAG of EXISTING dispatch nodes
 * (the canonical task_category + role steps), ordered by depends_on edges — the one
 * primitive the conductor cannot express today (composition / ordering of known
 * steps). It deliberately withholds new arm/role types, loops, dynamic fan-width,
 * arbitrary code, and (in this first tier) data-flow between nodes.
 *
 * Safety is structural and governed: every node still flows through the unchanged
 * dispatch() + its per-node Constitutional Kernel + Autonomy Admission gates; on top,
 * this gate runs ONE whole-plan pre-gate — a single kernel validateChange() + a
 * single admission admit() over a composed proposed_effect — which is THE
 * payload-inspecting injection gate (it scans the composed effect for forbidden
 * vocabulary). Structurally it bounds the plan to acyclic, <= MAX_NODES, unique ids,
 * resolvable dependencies, and non-empty task/role.
 */
final class AtlasConductorPlanGate
{
    public const SCHEMA_VERSION = 'atlas.ai.conductor_plan_gate.v1';

    public const MAX_NODES = 8;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $work
     * @return array{ok:bool,ordered:list<array<string,mixed>>,reason:?string,kernel_decision:?string,admission_decision:?string}
     */
    public function validate(array $plan, array $work): array
    {
        $nodes = is_array($plan['nodes'] ?? null) ? array_values($plan['nodes']) : [];

        if ($nodes === []) {
            return $this->block('plan_has_no_nodes');
        }
        if (count($nodes) > self::MAX_NODES) {
            return $this->block('plan_exceeds_max_nodes');
        }

        $ids = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                return $this->block('plan_node_not_object');
            }
            $id = (string) ($node['node_id'] ?? '');
            $task = (string) ($node['task_category'] ?? '');
            $role = (string) ($node['role'] ?? '');
            if ($id === '' || $task === '' || $role === '') {
                return $this->block('plan_node_missing_id_task_or_role');
            }
            if (isset($ids[$id])) {
                return $this->block('plan_duplicate_node_id');
            }
            $ids[$id] = true;
        }

        foreach ($nodes as $node) {
            foreach ((array) ($node['depends_on'] ?? []) as $dep) {
                if (! isset($ids[(string) $dep])) {
                    return $this->block('plan_unresolved_dependency');
                }
            }
        }

        $ordered = $this->topoSort($nodes);
        if ($ordered === null) {
            return $this->block('plan_has_cycle');
        }

        // Whole-plan pre-gate — the single payload-inspecting injection gate.
        // scope may be a string or a structured array (as dispatch() passes it).
        $effect = $this->composeEffect($ordered, $work);
        $scope = $work['scope'] ?? 'global';

        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'conductor_plan',
            'proposed_effect' => $effect,
            'scope' => $scope,
            'actor' => 'ConductorPlanGate',
        ]);
        if (($kernelEnv['decision'] ?? '') === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            return $this->block('plan_kernel_blocked', $kernelEnv['decision'] ?? null, null);
        }

        $admissionEnv = $this->admission->admit([
            'change_kind' => 'conductor_plan',
            'proposed_effect' => $effect,
            'scope' => $scope,
            'actor' => 'ConductorPlanGate',
        ]);
        if (($admissionEnv['decision'] ?? '') === AtlasAutonomyAdmissionService::DECISION_DENY) {
            return $this->block('plan_admission_denied', $kernelEnv['decision'] ?? null, $admissionEnv['decision'] ?? null);
        }

        return [
            'ok' => true,
            'ordered' => $ordered,
            'reason' => null,
            'kernel_decision' => $kernelEnv['decision'] ?? null,
            'admission_decision' => $admissionEnv['decision'] ?? null,
        ];
    }

    /**
     * @return array{ok:bool,ordered:list<array<string,mixed>>,reason:?string,kernel_decision:?string,admission_decision:?string}
     */
    private function block(string $reason, ?string $kernel = null, ?string $admission = null): array
    {
        return ['ok' => false, 'ordered' => [], 'reason' => $reason, 'kernel_decision' => $kernel, 'admission_decision' => $admission];
    }

    /**
     * Kahn topological sort, stable in input order among ready nodes. Null on cycle.
     *
     * @param  list<array<string,mixed>>  $nodes
     * @return list<array<string,mixed>>|null
     */
    private function topoSort(array $nodes): ?array
    {
        $byId = [];
        $indeg = [];
        foreach ($nodes as $node) {
            $id = (string) $node['node_id'];
            $byId[$id] = $node;
            $indeg[$id] = 0;
        }

        $adj = [];
        foreach ($nodes as $node) {
            $id = (string) $node['node_id'];
            foreach ((array) ($node['depends_on'] ?? []) as $dep) {
                $adj[(string) $dep][] = $id;
                $indeg[$id]++;
            }
        }

        $queue = [];
        foreach ($nodes as $node) {
            $id = (string) $node['node_id'];
            if ($indeg[$id] === 0) {
                $queue[] = $id;
            }
        }

        $ordered = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $ordered[] = $byId[$id];
            foreach ($adj[$id] ?? [] as $next) {
                if (--$indeg[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        return count($ordered) === count($nodes) ? $ordered : null;
    }

    /**
     * @param  list<array<string,mixed>>  $ordered
     * @param  array<string,mixed>  $work
     */
    private function composeEffect(array $ordered, array $work): string
    {
        $steps = array_map(
            static fn (array $n): string => (string) $n['task_category'].'/'.(string) $n['role'],
            $ordered,
        );

        return 'conductor plan ordering: '.implode(' -> ', $steps)
            .' for task='.((string) ($work['task_category'] ?? ''))
            .' input='.mb_substr((string) ($work['input'] ?? ''), 0, 400);
    }
}
