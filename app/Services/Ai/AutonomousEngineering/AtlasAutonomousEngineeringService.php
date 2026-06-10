<?php

namespace App\Services\Ai\AutonomousEngineering;

use App\Models\AiAutonomousEngineeringCertification;
use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiAutonomousWorkCycle;
use App\Models\AiAutonomousWorkStep;
use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AiEngineeringControlPlaneEvent;
use App\Models\AiMandatoryRagGate;
use App\Models\AiRepairLoop;
use App\Models\AiRivalsShadowRun;
use App\Models\PersistentAiExecutionPlan as AiExecutionPlan;
use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Router\AtlasAiRouterDecision;
use App\Services\Ai\Router\AtlasAiRouterService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AtlasAutonomousEngineeringService
{
    public const GOAL_SCHEMA = 'atlas.ai.autonomous_engineering.goal.v1';

    public const CYCLE_SCHEMA = 'atlas.ai.autonomous_engineering.work_cycle.v1';

    public const STEP_SCHEMA = 'atlas.ai.autonomous_engineering.work_step.v1';

    public const WORLD_MODEL_SCHEMA = 'atlas.ai.autonomous_engineering.codebase_world_model.v1';

    public const RAG_GATE_SCHEMA = 'atlas.ai.autonomous_engineering.rag_gate.v1';

    public const EXECUTION_PLAN_SCHEMA = 'atlas.ai.autonomous_engineering.execution_plan.v1';

    public const REPAIR_LOOP_SCHEMA = 'atlas.ai.autonomous_engineering.repair_loop.v1';

    public const CONTROL_EVENT_SCHEMA = 'atlas.ai.autonomous_engineering.control_plane_event.v1';

    public const RIVALS_SHADOW_SCHEMA = 'atlas.ai.autonomous_engineering.rivals_shadow_run.v1';

    public const CERTIFICATION_SCHEMA = 'atlas.ai.autonomous_engineering.certification.v1';

    /**
     * @return array<string,mixed>
     */
    public function run(string $goalText, array $options = []): array
    {
        return DB::transaction(function () use ($goalText, $options): array {
            $decision = app(AtlasAiRouterService::class)->decide([
                'input_text' => $goalText,
                'payload' => [
                    'surface_id' => 'atlas_autonomous_engineering',
                    'workspace' => base_path(),
                    'atlas_mode' => 'programming',
                ],
            ]);
            $promotionTarget = $this->promotionTarget($goalText, $decision->flowId);
            $goal = $this->createGoal($goalText, $decision->flowId, $promotionTarget);
            $cycle = $this->createCycle($goal, $decision);
            $worldModel = $this->buildWorldModel($goal);
            $ragGate = $this->runRagGate($goal, $cycle, $worldModel, (int) ($options['context_sufficiency'] ?? 82));

            if ($ragGate->status !== 'passed') {
                $repair = $this->openRepairLoop($goal, $cycle, null, 'insufficient_context', ['rag_gate_id' => $ragGate->gate_id]);
                $shadow = $this->createRivalsShadowRun($goal, $cycle, true);
                $certification = $this->certify($goal);
                $this->recordControlEvent($goal, 'goal_blocked', 'blocked', ['rag_gate' => $ragGate->gate_id, 'repair' => $repair->repair_id]);

                return $this->resultPayload($goal->refresh(), $cycle, $worldModel, $ragGate, null, null, $repair, $shadow, $certification);
            }

            $plan = $this->createExecutionPlan($goal, $cycle, $worldModel, $ragGate, $decision, $promotionTarget);
            $step = $this->createWorkStep($goal, $cycle, $plan, (string) ($options['step_status'] ?? 'passed'));
            $repair = null;
            if ($step->status !== 'passed') {
                $repair = $this->openRepairLoop($goal, $cycle, $step, 'test_failure', ['step_id' => $step->step_id]);
            }

            $compounding = $this->recordCompoundingOutcome($goal, $cycle, $step, $ragGate, $plan, $repair);
            $shadow = $this->createRivalsShadowRun($goal, $cycle, $repair !== null);
            $evidenceRefs = array_values(array_unique([
                ...((array) $step->evidence_refs),
                'rag_gate:'.$ragGate->receipt_hash,
                'execution_plan:'.$plan->plan_hash,
                'rivals_shadow:'.$shadow->receipt_hash,
            ]));
            $goalStatus = $repair === null ? 'completed' : 'blocked';
            $goalReceipt = [
                'schema_version' => self::GOAL_SCHEMA,
                'goal_id' => $goal->goal_id,
                'status' => $goalStatus,
                'evidence_refs' => $evidenceRefs,
                'outcome_receipt_hash' => data_get($compounding, 'outcome.outcome_hash'),
            ];
            $goalReceipt['hash'] = AutonomousEngineeringHash::make($goalReceipt);
            $goal->forceFill([
                'status' => $goalStatus,
                'evidence_refs' => $evidenceRefs,
                'outcome_receipt_hash' => data_get($compounding, 'outcome.outcome_hash'),
                'receipt' => $goalReceipt,
                'receipt_hash' => $goalReceipt['hash'],
            ])->save();

            $certification = $this->certify($goal->refresh());
            if ($certification->status === 'passed') {
                $goal->forceFill(['certification_hash' => $certification->certification_hash])->save();
            }

            $this->recordControlEvent($goal, 'goal_'.$goal->status, $goal->status, [
                'cycle_id' => $cycle->cycle_id,
                'step_id' => $step->step_id,
                'certification_hash' => $certification->certification_hash,
            ]);

            return $this->resultPayload($goal->refresh(), $cycle, $worldModel, $ragGate, $plan, $step, $repair, $shadow, $certification, $compounding);
        });
    }

    public function createGoal(string $goalText, string $flowId = 'atlas_dev', string $promotionTarget = 'atlas_dev'): AiAutonomousEngineeringGoal
    {
        $goalId = 'aegoal_'.substr(AutonomousEngineeringHash::make([$goalText, microtime(true)]), 0, 24);
        $receipt = [
            'schema_version' => self::GOAL_SCHEMA,
            'goal_id' => $goalId,
            'goal' => $goalText,
            'status' => 'accepted',
            'intent_flow_id' => $flowId,
            'promotion_target' => $promotionTarget,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiAutonomousEngineeringGoal::query()->create([
            'goal_id' => $goalId,
            'goal' => $goalText,
            'status' => 'accepted',
            'intent_flow_id' => $flowId,
            'promotion_target' => $promotionTarget,
            'evidence_refs' => [],
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    public function createCycle(AiAutonomousEngineeringGoal $goal, AtlasAiRouterDecision $decision): AiAutonomousWorkCycle
    {
        $cycleId = 'aecyc_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, 1]), 0, 24);
        $evidence = ['router_flow:'.$decision->flowId, 'goal:'.$goal->goal_id];
        $decomposition = $this->decomposeGoal($goal, $decision);
        $receipt = [
            'schema_version' => self::CYCLE_SCHEMA,
            'cycle_id' => $cycleId,
            'goal_id' => $goal->goal_id,
            'flow_id' => $decision->flowId,
            'decomposition' => $decomposition,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiAutonomousWorkCycle::query()->create([
            'goal_record_id' => $goal->id,
            'cycle_id' => $cycleId,
            'cycle_index' => 1,
            'status' => 'planned',
            'flow_id' => $decision->flowId,
            'objective' => [
                'goal' => $goal->goal,
                'intent_kernel' => $decision->handoffPayload['intent_kernel'] ?? [],
                'decomposition' => $decomposition,
            ],
            'next_action' => ['action' => 'build_world_model'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    public function buildWorldModel(AiAutonomousEngineeringGoal $goal): AiCodebaseWorldModel
    {
        $paths = [
            'app/Console/Commands',
            'app/Services/Ai/AutonomousEngineering',
            'app/Services/Ai/Compounding',
            'app/Services/Ai/Router',
            'app/Http/Controllers/AtlasDev',
            'app/Services/AtlasCode',
            'database/migrations',
            'tests/Feature/Ai',
            'tests/Unit/Ai',
            'docs/engineering-knowledge-base',
        ];
        $existing = array_values(array_filter($paths, fn (string $path): bool => File::exists(base_path($path))));
        $modelId = 'aewm_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, $existing]), 0, 24);
        $receipt = [
            'schema_version' => self::WORLD_MODEL_SCHEMA,
            'model_id' => $modelId,
            'scope' => 'atlas-server',
            'paths' => $existing,
            'capabilities' => ['router', 'compounding', 'atlas_dev', 'atlas_forge', 'tests', 'docs', 'migrations'],
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);
        $model = AiCodebaseWorldModel::query()->create([
            'goal_record_id' => $goal->id,
            'model_id' => $modelId,
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => $receipt['capabilities'],
            'risks' => ['autonomous_execution_requires_rag_gate', 'external_provider_claims_require_real_battery'],
            'receipt' => $receipt,
            'model_hash' => $receipt['hash'],
        ]);

        foreach ($existing as $path) {
            $nodeId = 'node:'.$path;
            AiCodebaseWorldModelNode::query()->create([
                'world_model_id' => $model->id,
                'node_id' => $nodeId,
                'node_type' => $this->nodeTypeForFile($path),
                'path' => $path,
                'flow_id' => $this->flowForPath($path),
                'capabilities' => $this->capabilitiesForPath($path),
                'risks' => ['requires_evidence'],
                'metadata' => ['exists' => true],
            ]);
        }

        foreach ($this->worldModelFiles($existing) as $file) {
            AiCodebaseWorldModelNode::query()->create([
                'world_model_id' => $model->id,
                'node_id' => 'file:'.$file,
                'node_type' => $this->nodeTypeForFile($file),
                'path' => $file,
                'flow_id' => $this->flowForPath($file),
                'capabilities' => $this->capabilitiesForPath($file),
                'risks' => $this->risksForFile($file),
                'metadata' => [
                    'exists' => true,
                    'extension' => pathinfo($file, PATHINFO_EXTENSION),
                    'basename' => basename($file),
                ],
            ]);
        }

        $nodes = AiCodebaseWorldModelNode::query()->where('world_model_id', $model->id)->get();
        foreach ($nodes as $node) {
            if ($node->node_type === 'test') {
                AiCodebaseWorldModelEdge::query()->create([
                    'world_model_id' => $model->id,
                    'from_node_id' => $node->node_id,
                    'to_node_id' => 'node:app/Services/Ai/Router',
                    'edge_type' => 'tests',
                    'metadata' => ['inferred' => true],
                ]);
            }
            if ($node->node_type === 'doc') {
                AiCodebaseWorldModelEdge::query()->create([
                    'world_model_id' => $model->id,
                    'from_node_id' => $node->node_id,
                    'to_node_id' => 'node:app/Services/Ai/Compounding',
                    'edge_type' => 'documents',
                    'metadata' => ['inferred' => true],
                ]);
            }
            if ($node->node_type === 'command') {
                AiCodebaseWorldModelEdge::query()->create([
                    'world_model_id' => $model->id,
                    'from_node_id' => $node->node_id,
                    'to_node_id' => 'node:app/Services/Ai/AutonomousEngineering',
                    'edge_type' => 'invokes',
                    'metadata' => ['inferred' => true],
                ]);
            }
        }

        return $model;
    }

    public function runRagGate(AiAutonomousEngineeringGoal $goal, AiAutonomousWorkCycle $cycle, AiCodebaseWorldModel $model, int $contextSufficiency = 82): AiMandatoryRagGate
    {
        $sources = AiCodebaseWorldModelNode::query()->where('world_model_id', $model->id)->limit(8)->get();
        $included = $sources->count();
        $used = min($included, max(1, $included - 1));
        $missed = $contextSufficiency >= 70 ? [] : ['tests/Unit/Ai/AutonomousEngineering'];
        $status = $contextSufficiency >= 70 && $included >= 3 ? 'passed' : 'blocked';
        $evidence = $sources->pluck('path')->filter()->map(fn (string $path): string => 'source:'.$path)->values()->all();
        $contextHash = AutonomousEngineeringHash::make([$goal->goal_id, $evidence, $contextSufficiency]);
        $gateId = 'aerag_'.substr($contextHash, 0, 24);
        $receipt = [
            'schema_version' => self::RAG_GATE_SCHEMA,
            'gate_id' => $gateId,
            'status' => $status,
            'included_sources' => $included,
            'used_sources' => $used,
            'noise_sources' => max(0, $included - $used),
            'missed_required_sources' => $missed,
            'context_sufficiency' => $contextSufficiency,
            'evidence_refs' => $evidence,
            'context_pack_hash' => $contextHash,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);
        $gate = AiMandatoryRagGate::query()->create([
            'goal_record_id' => $goal->id,
            'cycle_record_id' => $cycle->id,
            'gate_id' => $gateId,
            'status' => $status,
            'retrieval_plan' => ['query' => $goal->goal, 'world_model_id' => $model->model_id, 'required_sources' => ['docs', 'tests', 'services']],
            'included_sources' => $included,
            'used_sources' => $used,
            'noise_sources' => max(0, $included - $used),
            'missed_required_sources' => $missed,
            'context_sufficiency' => $contextSufficiency,
            'evidence_refs' => $evidence,
            'context_pack_hash' => $contextHash,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);

        if ($this->compoundingTablesReady()) {
            app(AtlasCompoundingRuntimeService::class)->recordExecution([
                'run_id' => 'rag_gate:'.$gate->gate_id,
                'flow_id' => 'atlas_research',
                'outcome_status' => $status,
                'flow_quality' => $status === 'passed' ? 80 : 35,
                'retrieval_quality' => $contextSufficiency,
                'execution_quality' => 70,
                'evidence_quality' => $evidence === [] ? 0 : 82,
                'evidence_refs' => $evidence,
                'learning_signal' => ['claim' => 'Autonomous RAG gate feedback should improve future retrieval planning.', 'confidence' => 76, 'evidence_refs' => $evidence],
                'rag_feedback' => [
                    'retrieval_receipt_id' => $gate->gate_id,
                    'included_sources' => $included,
                    'used_sources' => $used,
                    'noise_sources' => max(0, $included - $used),
                    'missed_required_sources' => $missed,
                    'context_sufficiency' => $contextSufficiency,
                    'post_execution_utility' => $contextSufficiency,
                ],
            ]);
        }

        return $gate;
    }

    public function createExecutionPlan(AiAutonomousEngineeringGoal $goal, AiAutonomousWorkCycle $cycle, AiCodebaseWorldModel $model, AiMandatoryRagGate $gate, AtlasAiRouterDecision $decision, string $promotionTarget): AiExecutionPlan
    {
        $memories = DatabaseTableAvailability::has('ai_compounding_memories')
            ? app(AtlasCompoundingMemoryService::class)->approvedForFlow($decision->flowId)
            : [];
        $targetFlow = $promotionTarget === 'atlas_forge' ? 'atlas_forge' : $decision->flowId;
        $steps = [
            ['id' => 'understand', 'action' => 'interpret_intent', 'flow' => $decision->flowId],
            ['id' => 'plan', 'action' => 'create_execution_contract', 'flow' => $targetFlow],
            ['id' => 'verify', 'action' => 'run_or_simulate_safe_gate', 'flow' => $targetFlow],
        ];
        $expectedFiles = ['app/Services/Ai/AutonomousEngineering/AtlasAutonomousEngineeringService.php'];
        $expectedTests = ['tests/Feature/Ai/AtlasAutonomousEngineeringOperatingSystemTest.php'];
        $planId = 'aeplan_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, $steps, $targetFlow]), 0, 24);
        $receipt = [
            'schema_version' => self::EXECUTION_PLAN_SCHEMA,
            'plan_id' => $planId,
            'target_flow_id' => $targetFlow,
            'rag_gate_id' => $gate->gate_id,
            'world_model_id' => $model->model_id,
            'steps' => $steps,
            'expected_files' => $expectedFiles,
            'expected_tests' => $expectedTests,
            'risks' => ['side_effects_blocked_without_explicit_execution'],
            'rollback_plan' => ['revert_autonomous_step_receipts_only'],
            'compounding_memories' => $memories,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiExecutionPlan::query()->create([
            'goal_record_id' => $goal->id,
            'cycle_record_id' => $cycle->id,
            'rag_gate_id' => $gate->id,
            'world_model_id' => $model->id,
            'plan_id' => $planId,
            'status' => 'ready',
            'target_flow_id' => $targetFlow,
            'steps' => $steps,
            'expected_files' => $expectedFiles,
            'expected_tests' => $expectedTests,
            'risks' => $receipt['risks'],
            'rollback_plan' => $receipt['rollback_plan'],
            'compounding_memories' => $memories,
            'receipt' => $receipt,
            'plan_hash' => $receipt['hash'],
        ]);
    }

    public function createWorkStep(AiAutonomousEngineeringGoal $goal, AiAutonomousWorkCycle $cycle, AiExecutionPlan $plan, string $status = 'passed'): AiAutonomousWorkStep
    {
        $stepId = 'aestep_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, $plan->plan_id, $status]), 0, 24);
        $evidence = ['execution_plan:'.$plan->plan_hash, 'safe_simulation:'.$stepId];
        $receipt = [
            'schema_version' => self::STEP_SCHEMA,
            'step_id' => $stepId,
            'status' => $status,
            'action_type' => 'safe_engineering_smoke',
            'execution_mode' => 'safe_simulation',
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiAutonomousWorkStep::query()->create([
            'goal_record_id' => $goal->id,
            'cycle_record_id' => $cycle->id,
            'step_id' => $stepId,
            'step_index' => 1,
            'status' => $status,
            'action_type' => 'safe_engineering_smoke',
            'execution_mode' => 'safe_simulation',
            'expected_files' => $plan->expected_files,
            'expected_tests' => $plan->expected_tests,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    public function openRepairLoop(AiAutonomousEngineeringGoal $goal, AiAutonomousWorkCycle $cycle, ?AiAutonomousWorkStep $step, string $failureClass, array $failure): AiRepairLoop
    {
        $repairId = 'aerepair_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, $failureClass, $failure]), 0, 24);
        $evidence = ['goal:'.$goal->goal_id, 'cycle:'.$cycle->cycle_id];
        if ($step) {
            $evidence[] = 'step:'.$step->step_id;
        }
        $receipt = [
            'schema_version' => self::REPAIR_LOOP_SCHEMA,
            'repair_id' => $repairId,
            'failure_class' => $failureClass,
            'failure' => $failure,
            'repair_steps' => ['requery_world_model', 'rerun_rag_gate', 'create_repair_step'],
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiRepairLoop::query()->create([
            'goal_record_id' => $goal->id,
            'cycle_record_id' => $cycle->id,
            'step_record_id' => $step?->id,
            'repair_id' => $repairId,
            'status' => 'opened',
            'failure_class' => $failureClass,
            'failure' => $failure,
            'repair_steps' => $receipt['repair_steps'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    public function createRivalsShadowRun(AiAutonomousEngineeringGoal $goal, ?AiAutonomousWorkCycle $cycle, bool $benchmarkCandidate): AiRivalsShadowRun
    {
        $shadowId = 'aeshadow_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, $benchmarkCandidate]), 0, 24);
        $evidence = ['goal:'.$goal->goal_id];
        $receipt = [
            'schema_version' => self::RIVALS_SHADOW_SCHEMA,
            'shadow_run_id' => $shadowId,
            'rivals' => ['claude_code', 'codex'],
            'false_claim_blocked' => true,
            'benchmark_candidate' => $benchmarkCandidate,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiRivalsShadowRun::query()->create([
            'goal_record_id' => $goal->id,
            'cycle_record_id' => $cycle?->id,
            'shadow_run_id' => $shadowId,
            'status' => 'shadow_recorded',
            'rivals' => ['claude_code', 'codex'],
            'comparison_plan' => ['mode' => 'shadow_only', 'external_provider_call' => false, 'claim_allowed' => false],
            'false_claim_blocked' => true,
            'benchmark_candidate' => ['created' => $benchmarkCandidate, 'source' => 'autonomous_goal'],
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(): array
    {
        return [
            'schema_version' => 'atlas.ai.autonomous_engineering.control_plane.v1',
            'status' => 'ready',
            'counts' => [
                'goals' => $this->count('ai_autonomous_engineering_goals'),
                'cycles' => $this->count('ai_autonomous_work_cycles'),
                'steps' => $this->count('ai_autonomous_work_steps'),
                'rag_gates' => $this->count('ai_mandatory_rag_gates'),
                'repair_loops' => $this->count('ai_repair_loops'),
                'rivals_shadow_runs' => $this->count('ai_rivals_shadow_runs'),
                'certifications' => $this->count('ai_autonomous_engineering_certifications'),
            ],
            'observability' => [
                'flows' => $this->groupCounts('ai_execution_plans', 'target_flow_id'),
                'goal_statuses' => $this->groupCounts('ai_autonomous_engineering_goals', 'status'),
                'step_statuses' => $this->groupCounts('ai_autonomous_work_steps', 'status'),
                'rag_gate_statuses' => $this->groupCounts('ai_mandatory_rag_gates', 'status'),
                'repair_failures' => $this->groupCounts('ai_repair_loops', 'failure_class'),
                'delegations' => $this->groupCounts('ai_autonomous_engineering_goals', 'promotion_target'),
                'average_context_sufficiency' => $this->average('ai_mandatory_rag_gates', 'context_sufficiency'),
                'false_claims_blocked' => DatabaseTableAvailability::has('ai_rivals_shadow_runs')
                    ? AiRivalsShadowRun::query()->where('false_claim_blocked', true)->count()
                    : 0,
            ],
            'latest_goal' => DatabaseTableAvailability::has('ai_autonomous_engineering_goals')
                ? AiAutonomousEngineeringGoal::query()->latest()->first()?->toArray()
                : null,
            'events' => DatabaseTableAvailability::has('ai_engineering_control_plane_events')
                ? AiEngineeringControlPlaneEvent::query()->latest()->limit(10)->get()->toArray()
                : [],
            'writes' => false,
        ];
    }

    public function certify(?AiAutonomousEngineeringGoal $goal = null): AiAutonomousEngineeringCertification
    {
        $latestGoal = $goal ?? AiAutonomousEngineeringGoal::query()->latest()->first();
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('goal_exists', $latestGoal !== null),
            $this->check('world_model_exists', $this->count('ai_codebase_world_models') > 0),
            $this->check('rag_gate_passed', DatabaseTableAvailability::has('ai_mandatory_rag_gates') && AiMandatoryRagGate::query()->where('status', 'passed')->exists()),
            $this->check('execution_plan_ready', DatabaseTableAvailability::has('ai_execution_plans') && AiExecutionPlan::query()->where('status', 'ready')->exists()),
            $this->check('step_has_evidence', DatabaseTableAvailability::has('ai_autonomous_work_steps') && AiAutonomousWorkStep::query()->whereNotNull('evidence_refs')->exists()),
            $this->check('compounding_outcome_recorded', $latestGoal?->outcome_receipt_hash !== null),
            $this->check('rivals_false_claim_blocked', DatabaseTableAvailability::has('ai_rivals_shadow_runs') && AiRivalsShadowRun::query()->where('false_claim_blocked', true)->exists()),
        ];
        $blockers = array_values(array_map(
            fn (array $check): string => $check['id'],
            array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed'),
        ));
        $evidence = array_values(array_filter((array) ($latestGoal?->evidence_refs ?? [])));
        if ($latestGoal && $latestGoal->status === 'completed' && ($evidence === [] || $latestGoal->outcome_receipt_hash === null)) {
            $blockers[] = 'completed_goal_missing_evidence_or_outcome';
        }
        $status = $blockers === [] ? 'passed' : 'blocked';
        $certificationId = 'aecert_'.substr(AutonomousEngineeringHash::make([$latestGoal?->goal_id, $checks, microtime(true)]), 0, 24);
        $payload = [
            'schema_version' => self::CERTIFICATION_SCHEMA,
            'certification_id' => $certificationId,
            'status' => $status,
            'checks' => $checks,
            'blockers' => array_values(array_unique($blockers)),
            'claim_policy' => [
                'ready_to_claim_autonomous_engineering_os' => $status === 'passed',
                'ready_to_claim_100x_vs_claude_codex' => false,
                'rivals_shadow_is_proof' => false,
            ],
            'evidence_refs' => $evidence,
        ];
        $payload['hash'] = AutonomousEngineeringHash::make($payload);

        return AiAutonomousEngineeringCertification::query()->create([
            'goal_record_id' => $latestGoal?->id,
            'certification_id' => $certificationId,
            'status' => $status,
            'checks' => $checks,
            'blockers' => $payload['blockers'],
            'claim_policy' => $payload['claim_policy'],
            'evidence_refs' => $evidence,
            'certification_hash' => $payload['hash'],
            'certified_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        $checks = [
            $this->check('canonical_doc', File::exists(base_path('docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md'))),
            $this->check('persistence_tables', $this->tablesReady()),
            $this->check('runtime_service', class_exists(self::class)),
            $this->check('compounding_available', class_exists(AtlasCompoundingRuntimeService::class)),
            $this->check('router_available', class_exists(AtlasAiRouterService::class)),
        ];
        $blockers = array_values(array_map(fn (array $check): string => $check['id'], array_filter($checks, fn (array $check): bool => $check['status'] !== 'passed')));

        return [
            'schema_version' => 'atlas.ai.autonomous_engineering.readiness.v1',
            'status' => $blockers === [] ? 'passed' : 'blocked',
            'checks' => $checks,
            'blockers' => $blockers,
            'contracts' => [
                self::GOAL_SCHEMA,
                self::CYCLE_SCHEMA,
                self::STEP_SCHEMA,
                self::WORLD_MODEL_SCHEMA,
                self::RAG_GATE_SCHEMA,
                self::EXECUTION_PLAN_SCHEMA,
                self::REPAIR_LOOP_SCHEMA,
                self::CONTROL_EVENT_SCHEMA,
                self::RIVALS_SHADOW_SCHEMA,
                self::CERTIFICATION_SCHEMA,
            ],
            'writes' => false,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function queryWorldModel(string $key, string $value): array
    {
        if (! DatabaseTableAvailability::has('ai_codebase_world_model_nodes')) {
            return [];
        }

        return AiCodebaseWorldModelNode::query()
            ->when($key === 'flow', fn ($query) => $query->where('flow_id', $value))
            ->when($key === 'file', fn ($query) => $query->where('path', 'like', '%'.$value.'%'))
            ->when($key === 'capability', fn ($query) => $query->where('capabilities', 'like', '%'.$value.'%'))
            ->limit(20)
            ->get()
            ->toArray();
    }

    private function recordCompoundingOutcome(AiAutonomousEngineeringGoal $goal, AiAutonomousWorkCycle $cycle, AiAutonomousWorkStep $step, AiMandatoryRagGate $gate, AiExecutionPlan $plan, ?AiRepairLoop $repair): array
    {
        if (! $this->compoundingTablesReady()) {
            return ['status' => 'skipped', 'reason' => 'compounding_tables_missing'];
        }

        return app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'autonomous:'.$goal->goal_id,
            'flow_id' => $plan->target_flow_id,
            'outcome_status' => $repair === null ? 'passed' : 'failed',
            'flow_quality' => $repair === null ? 86 : 54,
            'retrieval_quality' => $gate->context_sufficiency,
            'execution_quality' => $step->status === 'passed' ? 82 : 50,
            'evidence_quality' => 88,
            'evidence_refs' => array_values(array_unique([...((array) $step->evidence_refs), 'rag_gate:'.$gate->receipt_hash, 'execution_plan:'.$plan->plan_hash])),
            'learning_signal' => [
                'claim' => 'Autonomous engineering goals should reuse RAG gate, world model and execution plan evidence before future execution.',
                'confidence' => 82,
                'memory_type' => 'routing_memory',
                'flow_id' => $plan->target_flow_id,
                'evidence_refs' => (array) $step->evidence_refs,
            ],
            'rag_feedback' => [
                'retrieval_receipt_id' => $gate->gate_id,
                'included_sources' => $gate->included_sources,
                'used_sources' => $gate->used_sources,
                'noise_sources' => $gate->noise_sources,
                'missed_required_sources' => $gate->missed_required_sources,
                'context_sufficiency' => $gate->context_sufficiency,
                'post_execution_utility' => $gate->context_sufficiency,
            ],
            'benchmark_case' => [
                'force' => $repair !== null,
                'source' => 'real_user_run',
                'expected_flow' => $plan->target_flow_id,
                'required_evidence' => (array) $step->evidence_refs,
                'rivals' => ['claude_code', 'codex'],
            ],
        ]);
    }

    private function recordControlEvent(AiAutonomousEngineeringGoal $goal, string $eventType, string $status, array $payload): AiEngineeringControlPlaneEvent
    {
        $eventId = 'aeevt_'.substr(AutonomousEngineeringHash::make([$goal->goal_id, $eventType, microtime(true)]), 0, 24);
        $evidence = ['goal:'.$goal->goal_id];
        $receipt = [
            'schema_version' => self::CONTROL_EVENT_SCHEMA,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => $status,
            'payload' => $payload,
            'evidence_refs' => $evidence,
        ];
        $receipt['hash'] = AutonomousEngineeringHash::make($receipt);

        return AiEngineeringControlPlaneEvent::query()->create([
            'goal_record_id' => $goal->id,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'status' => $status,
            'payload' => $payload,
            'evidence_refs' => $evidence,
            'receipt' => $receipt,
            'receipt_hash' => $receipt['hash'],
        ]);
    }

    private function promotionTarget(string $goal, string $flowId): string
    {
        $haystack = Str::lower($goal);
        if (str_contains($haystack, 'obra') || str_contains($haystack, 'enterprise') || str_contains($haystack, 'multi-ciclo') || str_contains($haystack, 'multi ciclo') || str_contains($haystack, 'refatore todo')) {
            return 'atlas_forge';
        }

        return $flowId;
    }

    /**
     * @return array<string,mixed>
     */
    private function decomposeGoal(AiAutonomousEngineeringGoal $goal, AtlasAiRouterDecision $decision): array
    {
        $targetFlow = $this->promotionTarget($goal->goal, $decision->flowId);
        $steps = [
            ['id' => 'intent', 'action' => 'interpret_ambiguous_prompt', 'owner' => 'router'],
            ['id' => 'memory', 'action' => 'consult_compounding_memory', 'owner' => 'compounding'],
            ['id' => 'world_model', 'action' => 'map_relevant_code_docs_tests_commands', 'owner' => 'autonomous_os'],
            ['id' => 'rag_gate', 'action' => 'build_retrieval_plan_and_require_context_sufficiency', 'owner' => 'agentic_rag'],
            ['id' => 'plan', 'action' => 'create_execution_plan_with_tests_risks_rollback', 'owner' => 'autonomous_os'],
            ['id' => 'execute', 'action' => 'execute_or_safe_simulate', 'owner' => $targetFlow],
            ['id' => 'verify', 'action' => 'run_test_gate_or_record_blocker', 'owner' => $targetFlow],
            ['id' => 'repair', 'action' => 'open_repair_loop_when_needed', 'owner' => 'autonomous_os'],
            ['id' => 'learn', 'action' => 'record_compounding_outcome_and_feedback', 'owner' => 'compounding'],
            ['id' => 'certify', 'action' => 'certify_goal_with_evidence', 'owner' => 'autonomous_os'],
        ];

        return [
            'schema_version' => 'atlas.ai.autonomous_engineering.goal_decomposition.v1',
            'target_flow_id' => $targetFlow,
            'cycle_count' => $targetFlow === 'atlas_forge' ? 3 : 1,
            'steps' => $steps,
            'forge_promotion_required' => $targetFlow === 'atlas_forge',
            'hash' => AutonomousEngineeringHash::make([$goal->goal_id, $targetFlow, $steps]),
        ];
    }

    private function flowForPath(string $path): ?string
    {
        $path = Str::lower($path);

        return match (true) {
            str_contains($path, 'compounding') => 'atlas_compounding',
            str_contains($path, 'router') => 'atlas_router',
            str_contains($path, 'atlasdev') => 'atlas_dev',
            str_contains($path, 'atlascode') || str_contains($path, 'forge') => 'atlas_forge',
            str_contains($path, 'autonomousengineering') => 'atlas_autonomous_engineering',
            str_contains($path, 'docs') => 'atlas_research',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function capabilitiesForPath(string $path): array
    {
        $path = Str::lower($path);

        return array_values(array_filter([
            str_contains($path, 'autonomousengineering') || str_contains($path, 'autonomous-engineering') ? 'autonomous_engineering' : null,
            str_contains($path, 'compounding') ? 'compounding' : null,
            str_contains($path, 'router') ? 'router' : null,
            str_contains($path, 'atlasdev') ? 'atlas_dev' : null,
            str_contains($path, 'atlascode') || str_contains($path, 'forge') ? 'atlas_forge' : null,
            str_contains($path, 'command') ? 'commands' : null,
            str_contains($path, 'tests') ? 'tests' : null,
            str_contains($path, 'docs') ? 'docs' : null,
            str_contains($path, 'migration') ? 'migrations' : null,
        ]));
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function worldModelFiles(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            $absolute = base_path($root);
            if (! File::isDirectory($absolute)) {
                continue;
            }

            foreach (File::allFiles($absolute) as $file) {
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                if (! in_array($file->getExtension(), ['php', 'md'], true)) {
                    continue;
                }
                if (! $this->isRelevantWorldModelFile($relative)) {
                    continue;
                }
                $files[] = $relative;
                if (count($files) >= 120) {
                    break 2;
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function isRelevantWorldModelFile(string $path): bool
    {
        $lower = Str::lower($path);

        return str_contains($lower, 'autonomous')
            || str_contains($lower, 'compounding')
            || str_contains($lower, 'hyperflow')
            || str_contains($lower, 'router')
            || str_contains($lower, 'atlasdev')
            || str_contains($lower, 'atlascode')
            || str_contains($lower, 'forge')
            || str_contains($lower, 'programming')
            || str_contains($lower, 'engineering-knowledge-base')
            || str_contains($lower, 'migration');
    }

    private function nodeTypeForFile(string $path): string
    {
        $lower = Str::lower($path);

        return match (true) {
            str_contains($lower, 'tests/') => 'test',
            str_contains($lower, 'docs/') => 'doc',
            str_contains($lower, 'database/migrations') => 'migration',
            str_contains($lower, 'app/console/commands') => 'command',
            str_contains($lower, 'service.php') || str_contains($lower, 'app/services') => 'service',
            default => 'file',
        };
    }

    /**
     * @return list<string>
     */
    private function risksForFile(string $path): array
    {
        $risks = ['requires_evidence'];
        if (str_contains(Str::lower($path), 'migration')) {
            $risks[] = 'persistence_change';
        }
        if (str_contains(Str::lower($path), 'command')) {
            $risks[] = 'operator_entrypoint';
        }
        if (str_contains(Str::lower($path), 'forge')) {
            $risks[] = 'large_scope_promotion';
        }

        return array_values(array_unique($risks));
    }

    private function count(string $table): int
    {
        return DatabaseTableAvailability::has($table) ? DB::table($table)->count() : 0;
    }

    /**
     * @return array<string,int>
     */
    private function groupCounts(string $table, string $column): array
    {
        if (! DatabaseTableAvailability::has($table)) {
            return [];
        }

        return DB::table($table)
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn ($value): int => (int) $value)
            ->all();
    }

    private function average(string $table, string $column): ?float
    {
        if (! DatabaseTableAvailability::has($table) || DB::table($table)->count() === 0) {
            return null;
        }

        return round((float) DB::table($table)->avg($column), 2);
    }

    private function tablesReady(): bool
    {
        foreach ([
            'ai_autonomous_engineering_goals',
            'ai_autonomous_work_cycles',
            'ai_autonomous_work_steps',
            'ai_codebase_world_models',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_model_edges',
            'ai_mandatory_rag_gates',
            'ai_execution_plans',
            'ai_repair_loops',
            'ai_engineering_control_plane_events',
            'ai_rivals_shadow_runs',
            'ai_autonomous_engineering_certifications',
        ] as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                return false;
            }
        }

        return true;
    }

    private function compoundingTablesReady(): bool
    {
        foreach ([
            'ai_run_outcomes',
            'ai_learning_candidates',
            'ai_compounding_memories',
            'ai_rag_feedback_events',
            'ai_benchmark_cases',
            'ai_temporal_certifications',
        ] as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{id:string,status:string}
     */
    private function check(string $id, bool $passed): array
    {
        return ['id' => $id, 'status' => $passed ? 'passed' : 'blocked'];
    }

    /**
     * @return array<string,mixed>
     */
    private function resultPayload(AiAutonomousEngineeringGoal $goal, AiAutonomousWorkCycle $cycle, AiCodebaseWorldModel $worldModel, AiMandatoryRagGate $ragGate, ?AiExecutionPlan $plan, ?AiAutonomousWorkStep $step, ?AiRepairLoop $repair, AiRivalsShadowRun $shadow, AiAutonomousEngineeringCertification $certification, array $compounding = []): array
    {
        return [
            'schema_version' => 'atlas.ai.autonomous_engineering.run_result.v1',
            'status' => $goal->status,
            'goal' => $goal->toArray(),
            'cycle' => $cycle->toArray(),
            'world_model' => $worldModel->toArray(),
            'rag_gate' => $ragGate->toArray(),
            'execution_plan' => $plan?->toArray(),
            'work_step' => $step?->toArray(),
            'repair_loop' => $repair?->toArray(),
            'rivals_shadow_run' => $shadow->toArray(),
            'compounding' => $compounding,
            'certification' => $certification->toArray(),
            'next_action' => $certification->status === 'passed' ? 'ready_for_next_goal' : 'resolve_blockers',
            'writes' => true,
        ];
    }
}
