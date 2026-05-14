<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasPlan;
use App\Models\AtlasSddTask;
use App\Models\AtlasSpec;
use App\Services\Ai\Programming\Governance\ProgrammingSpecCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\PlanCompiler;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use App\Services\Ai\Programming\Sdd\Compilers\TaskCompiler;
use App\Services\Ai\Programming\Sdd\Enums\AutonomyLevel;
use App\Services\Ai\Programming\Sdd\Enums\SpecStatus;
use App\Services\Ai\Programming\Sdd\Pipeline\AtlasSddOutput;
use App\Services\Ai\Programming\Sdd\Pipeline\OperationEnvelope;

/**
 * Master SDD orchestrator. Mirrors data-model-and-services.md:227-273.
 *
 *   intent → context → spec → critic → plan → tasks → receipt → execute →
 *   gates → repair? → evidence → drift → learning
 *
 * Each step is delegated to a focused service. The pipeline never bypasses
 * a Decision Receipt for execution and never widens scope on its own.
 */
class AtlasSddPipeline
{
    public function __construct(
        private readonly IntentRouter $intentRouter,
        private readonly ContextBuilder $contextBuilder,
        private readonly ProgrammingSpecCompiler $specCompiler,
        private readonly SpecCritic $specCritic,
        private readonly PlanCompiler $planCompiler,
        private readonly TaskCompiler $taskCompiler,
        private readonly DecisionEngine $decisionEngine,
        private readonly RuntimeExecutor $runtimeExecutor,
        private readonly RepairLoop $repairLoop,
        private readonly SpecDriftDetector $driftDetector,
        private readonly LearningSignals $learningSignals,
    ) {}

    /**
     * @param  list<array{path:string,contents:string}>  $proposedWrites
     * @param  list<string>  $proposedCommands
     * @param  callable(string):bool|null  $commandRunner
     */
    public function run(
        OperationEnvelope $envelope,
        array $proposedWrites = [],
        array $proposedCommands = [],
        ?callable $commandRunner = null,
        ?AutonomyLevel $autonomy = null,
    ): AtlasSddOutput {
        // 1. Intent + Context
        $intent = $this->intentRouter->route($envelope);
        $operation = $this->intentRouter->persist($envelope, $intent);

        // 1b. Short-circuit when the router itself sees blocking ambiguity
        // (empty input, missing target, irreducible doubt). No spec, no plan,
        // no receipt are persisted.
        if ($intent->confidenceClass->isBlocking()) {
            $operation->forceFill(['status' => 'needs_clarification'])->save();

            return AtlasSddOutput::needsClarification([
                'operation_id' => $operation->id,
                'intent' => $intent->toArray(),
                'critique' => [
                    'schema_version' => 'atlas.sdd_spec_critic.v1',
                    'status' => 'rejected',
                    'has_blocking_questions' => true,
                    'clarification_questions' => [
                        'The intent is too ambiguous to compile a spec. Restate it with the target object and the desired outcome.',
                    ],
                    'blocking_issues' => [
                        ['field' => 'intent', 'severity' => 'high', 'reason' => 'blocking_ambiguity'],
                    ],
                ],
            ]);
        }

        $context = $this->contextBuilder->build($envelope, $intent);

        // 2. Spec compile + critic
        $compiled = $this->specCompiler->compile(
            $this->workItemFromEnvelope($envelope, $intent),
        );
        $critique = $this->specCritic->review($compiled, ['digest' => $context->digest]);

        if ($this->specCritic->hasBlockingQuestions($critique)) {
            $operation->forceFill(['status' => 'needs_clarification'])->save();

            return AtlasSddOutput::needsClarification([
                'operation_id' => $operation->id,
                'intent' => $intent->toArray(),
                'context' => $context->toArray(),
                'critique' => $critique,
            ]);
        }

        // 3. Persist canonical Spec
        $spec = $this->persistSpec($operation, $compiled, $intent);

        // 4. Plan + tasks
        $planPayload = $this->planCompiler->compile($compiled['spec'], $context);
        $plan = $this->persistPlan($spec, $planPayload);
        $tasks = $this->taskCompiler->compile($planPayload);
        $taskRows = $this->persistTasks($spec, $plan, $tasks);

        // 5. Decision Receipt
        $receipt = $this->decisionEngine->createReceipt(
            envelope: $envelope,
            operation: $operation,
            spec: $spec,
            plan: $plan,
            tasks: array_map(
                static fn ($r): array => ['code' => $r->code, 'allowed_files' => $r->allowed_files_json, 'forbidden_files' => $r->forbidden_files_json],
                $taskRows,
            ),
            context: $context,
            intent: $intent,
            requestedAutonomy: $autonomy,
        );

        // 6. Execute (bounded by receipt)
        $execution = $this->runtimeExecutor->execute(
            receipt: $receipt,
            proposedWrites: $proposedWrites,
            proposedCommands: $proposedCommands,
            evidenceRefs: ["receipt:{$receipt->receipt_id}"],
            workspace: $envelope->workspace,
            commandRunner: $commandRunner,
        );

        // 7. Repair if needed (bounded)
        $repaired = $execution->ok() ? $execution : $this->repairLoop->repair(
            receipt: $receipt,
            previous: $execution,
            commandRunner: $commandRunner,
        );

        // 8. Drift inspection + learning proposal
        $driftReport = $this->driftDetector->inspect($spec->refresh(), $operation, source: 'sdd_pipeline');
        $driftRow = $spec->fresh()->relationLoaded('driftReports') ? null : null; // we re-query below
        $learningProposal = $this->learningSignals->proposeIfUseful($operation, $repaired, $driftReport);

        $operation->forceFill(['status' => $repaired->ok() ? 'completed' : 'blocked'])->save();
        if ($repaired->ok()) {
            $spec->forceFill([
                'status' => SpecStatus::Implemented->value,
                'approved_at' => $spec->approved_at ?? now(),
            ])->save();
        }

        return ($repaired->ok() ? AtlasSddOutput::completed(...) : AtlasSddOutput::blocked(...))([
            'operation_id' => $operation->id,
            'intent' => $intent->toArray(),
            'context' => $context->toArray(),
            'spec_id' => $spec->id,
            'plan_id' => $plan->id,
            'task_codes' => array_map(static fn ($t) => $t->code, $taskRows),
            'receipt_id' => $receipt->receipt_id,
            'autonomy_level' => $receipt->autonomy_level,
            'execution' => $repaired->toArray(),
            'drift' => $driftReport,
            'learning_proposal_id' => $learningProposal?->id,
            'critique' => $critique,
        ]);
    }

    /**
     * Build a transient AtlasProgrammingWorkItem-like input expected by the
     * existing ProgrammingSpecCompiler. We do not persist this — the pipeline
     * persists the canonical AtlasSpec directly.
     */
    private function workItemFromEnvelope(OperationEnvelope $envelope, $intent): \App\Models\AtlasProgrammingWorkItem
    {
        $item = new \App\Models\AtlasProgrammingWorkItem();
        $item->id = (string) \Illuminate\Support\Str::uuid();
        $item->code = 'SDD-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $item->intent_text = $envelope->rawInput;
        $item->intent_type = $intent->type;
        $item->scope_mode = $intent->harnessRequired ? 'structural' : 'compact';
        $item->risk_level = $intent->riskLevel;
        $item->workspace = $envelope->workspace;
        $item->status = 'spec_required';
        $item->current_stage = 'spec';
        $item->placement_json = [];
        $item->code_intelligence_json = [];
        $item->spec_json = [];
        $item->plan_json = [];
        $item->tasks_json = [];
        $item->evidence_refs_json = [];
        $item->gaps_json = [];
        $item->metadata_json = ['classification_signals' => $intent->metadata['classification_signals'] ?? []];

        return $item;
    }

    /**
     * @param  array<string,mixed>  $compiled
     */
    private function persistSpec($operation, array $compiled, $intent): AtlasSpec
    {
        $contentJson = (array) ($compiled['spec'] ?? []);
        $contentHash = hash('sha256', json_encode($contentJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return AtlasSpec::query()->create([
            'operation_id' => $operation->id,
            'project_id' => $operation->project_id,
            'work_item_id' => $operation->work_item_id,
            'title' => substr((string) ($contentJson['objective'] ?? $operation->raw_input), 0, 240),
            'type' => $intent->type,
            'status' => SpecStatus::Approved->value,
            'version' => 1,
            'risk_level' => $intent->riskLevel,
            'content_hash' => $contentHash,
            'content_json' => $contentJson,
        ]);
    }

    /**
     * @param  array<string,mixed>  $planPayload
     */
    private function persistPlan(AtlasSpec $spec, array $planPayload): AtlasPlan
    {
        return AtlasPlan::query()->create([
            'spec_id' => $spec->id,
            'content_hash' => (string) ($planPayload['content_hash'] ?? hash('sha256', json_encode($planPayload) ?: '')),
            'content_json' => $planPayload,
            'target_files_json' => array_values((array) ($planPayload['target_files'] ?? [])),
            'forbidden_files_json' => array_values((array) ($planPayload['forbidden_files'] ?? [])),
            'hot_file_ownership_json' => (array) ($planPayload['hot_file_ownership'] ?? []),
            'test_plan_json' => array_values((array) ($planPayload['test_plan'] ?? [])),
            'rollback_plan_json' => (array) ($planPayload['rollback_plan'] ?? []),
            'status' => 'draft',
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<AtlasSddTask>
     */
    private function persistTasks(AtlasSpec $spec, AtlasPlan $plan, array $tasks): array
    {
        $rows = [];
        foreach ($tasks as $i => $task) {
            $rows[] = AtlasSddTask::query()->create([
                'plan_id' => $plan->id,
                'spec_id' => $spec->id,
                'code' => (string) ($task['code'] ?? sprintf('T-%02d', $i + 1)),
                'type' => (string) ($task['type'] ?? 'implement'),
                'title' => (string) ($task['title'] ?? 'Task'),
                'description' => $task['description'] ?? null,
                'depends_on_json' => array_values((array) ($task['depends_on'] ?? [])),
                'allowed_files_json' => array_values((array) ($task['allowed_files'] ?? [])),
                'forbidden_files_json' => array_values((array) ($task['forbidden_files'] ?? [])),
                'acceptance_refs_json' => array_values((array) ($task['acceptance_refs'] ?? [])),
                'status' => 'pending',
                'order_index' => (int) ($task['order_index'] ?? ($i + 1)),
            ]);
        }

        return $rows;
    }
}
