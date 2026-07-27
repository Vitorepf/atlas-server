<?php

declare(strict_types=1);

namespace App\Http\Controllers;

// Gap5.F2 wiring marker — Programming-adjacent controller.
// Canonical route_decision schema: atlas.dual_core.route_decision.v1
// Method-level emission to be wired per AP per family.

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Ai\Programming\ProgrammingWorkItemBindingService;
use App\Services\Ai\Programming\ProgrammingWorkItemContractSupport;
use App\Services\Ai\Programming\Sdd\Compilers\PlanCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\TaskCompiler;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Support\AiStringListNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Atlas Code -> Programming Governance WorkItem binding.
 *
 * A professional Forge cockpit cannot depend on a side-channel to create the
 * governed WorkItem. This endpoint creates one from the selected Obra and
 * stores the binding in both records.
 */
final class AtlasCodeProgrammingWorkItemController extends Controller
{
    public function store(
        Request $request,
        AtlasProject $project,
        ProgrammingGovernanceService $governance,
        ProgrammingWorkItemBindingService $binding,
    ): JsonResponse {
        $data = $request->validate([
            'intent' => ['nullable', 'string', 'max:2000'],
            'owner' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:40'],
            'mode' => ['nullable', Rule::in(['compact', 'structural'])],
            'risk' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
        ]);

        // Thin HTTP adapter: business binding lives in ProgrammingWorkItemBindingService (ASDD D4).
        $result = $binding->bindOrCreate(
            $project,
            is_string($data['intent'] ?? null) ? $data['intent'] : null,
            is_string($data['owner'] ?? null) ? $data['owner'] : null,
            is_string($data['type'] ?? null) ? $data['type'] : null,
            is_string($data['mode'] ?? null) ? $data['mode'] : null,
            is_string($data['risk'] ?? null) ? $data['risk'] : null,
        );

        return response()->json($result['payload'], (int) $result['http_status']);
    }

    public function compileSpecPlan(
        Request $request,
        AtlasProject $project,
        string $workItem,
        ProgrammingGovernanceService $governance,
        ProgrammingSpecCompiler $specCompiler,
        PlanCompiler $planCompiler,
        TaskCompiler $taskCompiler,
    ): JsonResponse {
        $data = $request->validate([
            'likely_files' => ['nullable', 'array', 'min:1', 'max:80'],
            'likely_files.*' => ['string', 'max:500'],
            'validation_commands' => ['nullable', 'array', 'min:1', 'max:20'],
            'validation_commands.*' => ['string', 'max:500'],
            'acceptance_criteria' => ['nullable', 'array', 'min:1', 'max:30'],
            'acceptance_criteria.*' => ['string', 'max:500'],
            'evidence_required' => ['nullable', 'array', 'min:1', 'max:20'],
            'evidence_required.*' => ['string', 'max:120'],
            'context_stack' => ['nullable', 'string', 'max:80'],
            'context_packages' => ['nullable', 'array', 'max:20'],
            'context_packages.*' => ['string', 'max:120'],
        ]);

        try {
            $item = $governance->find($workItem);
        } catch (\RuntimeException) {
            return response()->json(ProgrammingWorkItemContractSupport::blockResponse($project, null, [
                'work_item_not_found',
            ], 'work_item_not_found'), 404);
        }

        if (! ProgrammingWorkItemContractSupport::workItemBelongsToProject($project, $item)) {
            return response()->json(ProgrammingWorkItemContractSupport::blockResponse($project, $item, [
                'work_item_not_bound_to_obra',
            ], 'work_item_not_bound_to_obra'), 403);
        }

        if ($item->spec_hash !== null && $item->plan_hash !== null && (array) $item->tasks_json !== []) {
            return response()->json($this->specPlanPayload(
                $project,
                $governance,
                $item->refresh(),
                status: 'already_planned',
                compiled: false,
            ));
        }

        try {
            $spec = ProgrammingWorkItemContractSupport::specForWorkItem($item, $specCompiler, $data);
            $critique = $specCompiler->critique(['spec' => $spec]);
        } catch (Throwable $e) {
            return response()->json(ProgrammingWorkItemContractSupport::blockResponse($project, $item, [
                'spec_compiler_failed',
            ], $e->getMessage()), 500);
        }

        $scopeBlockers = ProgrammingWorkItemContractSupport::specScopeBlockers($spec);
        $critiqueBlockers = collect((array) ($critique['blocking_issues'] ?? []))
            ->map(fn (mixed $issue): string => is_array($issue)
                ? (string) (($issue['field'] ?? 'spec').':'.($issue['reason'] ?? 'invalid'))
                : 'spec_critic_blocked')
            ->values()
            ->all();
        $blockers = array_values(array_unique(array_merge($scopeBlockers, $critiqueBlockers)));
        if ($blockers !== []) {
            return response()->json(ProgrammingWorkItemContractSupport::blockResponse($project, $item, $blockers, 'spec_or_context_insufficient', [
                'critique' => $critique,
                'spec' => $spec,
            ]), 422);
        }

        if ($item->spec_hash === null) {
            $governance->attachSpec($item, $spec);
            $item = $item->refresh();
        } else {
            $spec = (array) $item->spec_json;
        }

        $contextPack = $this->contextPackFor($project, $item, $spec, $data);
        $plan = $planCompiler->compile($spec, $contextPack);
        $compiledTasks = $taskCompiler->compile($plan);
        $tasks = ProgrammingWorkItemContractSupport::taskContractsFromCompiledTasks($compiledTasks, $plan, $spec, $item, $data);

        if ($tasks === []) {
            return response()->json(ProgrammingWorkItemContractSupport::blockResponse($project, $item, [
                'plan_compiler_produced_no_tasks',
            ], 'plan_compiler_produced_no_tasks', [
                'plan' => $plan,
            ]), 422);
        }

        $plan['context_pack'] = $contextPack->toArray();
        $plan['source_authority'] = 'ProgrammingSpecCompiler + SDD PlanCompiler + SDD TaskCompiler';

        $governance->attachPlan($item, $plan, $tasks);
        $item = $this->clearGap($item->refresh(), 'plan_autogeneration');

        return response()->json($this->specPlanPayload(
            $project,
            $governance,
            $item,
            status: 'planned',
            compiled: true,
            contextPack: $contextPack->toArray(),
            critique: $critique,
        ), 201);
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $data
     */
    private function contextPackFor(AtlasProject $project, AtlasProgrammingWorkItem $workItem, array $spec, array $data): ContextPack
    {
        $packages = array_values(array_unique(array_merge([
            'atlas.code.forge.v1',
            'atlas.programming.governance.v1',
            'atlas.programming.sdd_compilers.v1',
            $workItem->risk_level === 'high' || $workItem->risk_level === 'critical'
                ? 'atlas.risk.high.v1'
                : 'atlas.risk.standard.v1',
        ], AiStringListNormalizer::trimmedStringsFromArrayCast($data['context_packages'] ?? []))));

        $payload = [
            'schema_version' => 'atlas.code.programming_spec_plan_context.v1',
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'spec_hash' => $workItem->spec_hash,
            'likely_files' => AiStringListNormalizer::trimmedStringsFromArrayCast($spec['likely_files'] ?? []),
            'validation_commands' => AiStringListNormalizer::trimmedStringsFromArrayCast($spec['tests'] ?? []),
            'source_authority' => 'AtlasCodeProgrammingWorkItemController::compileSpecPlan',
        ];
        $digest = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return new ContextPack(
            stack: (string) ($data['context_stack'] ?? 'atlas-code-forge'),
            packages: $packages,
            payload: $payload,
            digest: $digest,
        );
    }

    private function clearGap(AtlasProgrammingWorkItem $workItem, string $gapName): AtlasProgrammingWorkItem
    {
        $gaps = collect((array) $workItem->gaps_json)
            ->reject(fn (mixed $gap): bool => is_array($gap) && ($gap['name'] ?? null) === $gapName)
            ->values()
            ->all();

        $workItem->forceFill(['gaps_json' => $gaps])->save();

        return $workItem->refresh();
    }

    /**
     * @return array<string,mixed>
     */
    private function responsePayload(
        AtlasProject $project,
        ProgrammingGovernanceService $governance,
        AtlasProgrammingWorkItem $workItem,
        bool $created,
    ): array {
        $snapshot = $governance->snapshot($workItem);

        return [
            'schema_version' => 'atlas.code.programming_work_item_binding_response.v1',
            'work_id' => (string) $project->getKey(),
            'created' => $created,
            'status' => 'bound',
            'binding' => [
                'schema_version' => 'atlas.code.programming_work_item_binding.v1',
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'source_authority' => 'AtlasProject.metadata.programming_work_item_id',
            ],
            'work_item' => $snapshot,
            'programming_governance' => [
                'schema_version' => 'atlas.code.programming_governance_snapshot.v1',
                'source_authority' => 'atlas_programming_work_items',
                'work_item' => $snapshot,
                'spec' => $snapshot['spec'] === [] ? null : $snapshot['spec'],
                'plan' => $snapshot['plan'] === [] ? null : $snapshot['plan'],
                'tasks' => $snapshot['tasks'],
                'gate_runs' => [],
                'reviews' => [],
                'evidence_refs' => $snapshot['evidence_refs'],
                'artifacts' => [],
                'degraded' => false,
                'degraded_reason' => null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $contextPack
     * @param  array<string,mixed>|null  $critique
     * @return array<string,mixed>
     */
    private function specPlanPayload(
        AtlasProject $project,
        ProgrammingGovernanceService $governance,
        AtlasProgrammingWorkItem $workItem,
        string $status,
        bool $compiled,
        ?array $contextPack = null,
        ?array $critique = null,
    ): array {
        $snapshot = $governance->snapshot($workItem);

        return [
            'schema_version' => 'atlas.code.programming_work_item_spec_binding_response.v1',
            'work_id' => (string) $project->getKey(),
            'obra_id' => (string) $project->getKey(),
            'status' => $status,
            'compiled' => $compiled,
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'spec_hash' => $workItem->spec_hash,
            'plan_hash' => $workItem->plan_hash,
            'tasks_count' => count((array) $workItem->tasks_json),
            'gate_requirements' => $snapshot['required_gates'],
            'blockers' => [],
            'next_action' => 'run_forge_live_execution',
            'context_pack' => $contextPack ?? data_get($workItem->plan_json, 'context_pack'),
            'critique' => $critique,
            'evidence_refs' => $snapshot['evidence_refs'],
            'programming_governance' => [
                'schema_version' => 'atlas.code.programming_governance_snapshot.v1',
                'source_authority' => 'atlas_programming_work_items',
                'work_item' => $snapshot,
                'spec' => $snapshot['spec'] === [] ? null : $snapshot['spec'],
                'plan' => $snapshot['plan'] === [] ? null : $snapshot['plan'],
                'tasks' => $snapshot['tasks'],
                'gate_runs' => [],
                'reviews' => [],
                'evidence_refs' => $snapshot['evidence_refs'],
                'artifacts' => [],
                'degraded' => false,
                'degraded_reason' => null,
            ],
        ];
    }
}
