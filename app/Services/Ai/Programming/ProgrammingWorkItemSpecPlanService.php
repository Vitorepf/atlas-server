<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\PlanCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\TaskCompiler;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Support\AiStringListNormalizer;
use Throwable;

final class ProgrammingWorkItemSpecPlanService
{
    public function __construct(
        private readonly ProgrammingGovernanceService $governance,
        private readonly ProgrammingSpecCompiler $specCompiler,
        private readonly PlanCompiler $planCompiler,
        private readonly TaskCompiler $taskCompiler,
    ) {}

    public function compile(
        AtlasProject $project,
        string $workItem,
        array $data = [],
    ): array {

        try {
            $item = $this->governance->find($workItem);
        } catch (\RuntimeException) {
            return ['http_status' => 404, 'payload' => ProgrammingWorkItemContractSupport::blockResponse($project, null, ['work_item_not_found'], 'work_item_not_found')];
        }

        if (! ProgrammingWorkItemContractSupport::workItemBelongsToProject($project, $item)) {
            return ['http_status' => 403, 'payload' => ProgrammingWorkItemContractSupport::blockResponse($project, $item, ['work_item_not_bound_to_obra'], 'work_item_not_bound_to_obra')];
        }

        if ($item->spec_hash !== null && $item->plan_hash !== null && (array) $item->tasks_json !== []) {
            return ['http_status' => 200, 'payload' => $this->specPlanPayload(
                $project,
                $this->governance,
                $item->refresh(),
                status: 'already_planned',
                compiled: false,
            )];
        }

        try {
            $spec = ProgrammingWorkItemContractSupport::specForWorkItem($item, $this->specCompiler, $data);
            $critique = $this->specCompiler->critique(['spec' => $spec]);
        } catch (Throwable $e) {
            return ['http_status' => 500, 'payload' => ProgrammingWorkItemContractSupport::blockResponse($project, $item, [
                'spec_compiler_failed',
            ], $e->getMessage())];
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
            return ['http_status' => 422, 'payload' => ProgrammingWorkItemContractSupport::blockResponse($project, $item, $blockers, 'spec_or_context_insufficient', [
                'critique' => $critique,
                'spec' => $spec,
            ])];
        }

        if ($item->spec_hash === null) {
            $this->governance->attachSpec($item, $spec);
            $item = $item->refresh();
        } else {
            $spec = (array) $item->spec_json;
        }

        $contextPack = $this->contextPackFor($project, $item, $spec, $data);
        $plan = $this->planCompiler->compile($spec, $contextPack);
        $compiledTasks = $this->taskCompiler->compile($plan);
        $tasks = ProgrammingWorkItemContractSupport::taskContractsFromCompiledTasks($compiledTasks, $plan, $spec, $item, $data);

        if ($tasks === []) {
            return ['http_status' => 422, 'payload' => ProgrammingWorkItemContractSupport::blockResponse($project, $item, [
                'plan_compiler_produced_no_tasks',
            ], 'plan_compiler_produced_no_tasks', [
                'plan' => $plan,
            ])];
        }

        $plan['context_pack'] = $contextPack->toArray();
        $plan['source_authority'] = 'ProgrammingSpecCompiler + SDD PlanCompiler + SDD TaskCompiler';

        $this->governance->attachPlan($item, $plan, $tasks);
        $item = $this->clearGap($item->refresh(), 'plan_autogeneration');

        return ['http_status' => 201, 'payload' => $this->specPlanPayload(
            $project,
            $this->governance,
            $item,
            status: 'planned',
            compiled: true,
            contextPack: $contextPack->toArray(),
            critique: $critique,
        )];
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
            'source_authority' => 'ProgrammingWorkItemSpecPlanService::compile',
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
        $snapshot = $this->governance->snapshot($workItem);

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
