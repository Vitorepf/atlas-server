<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiMission;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use App\Services\Ai\Programming\AtlasDev\Execution\ConfirmedDevRun;
use App\Services\Ai\Programming\AtlasDev\Execution\DevIntent;
use App\Services\Ai\Programming\AtlasDev\Execution\DevPlanRunFacade;
use App\Services\Ai\Programming\AtlasDev\Execution\DevRunResult;
use InvalidArgumentException;

class AtlasDevMissionAdapter
{
    public function __construct(
        private readonly MissionFactoryService $missionFactory,
        private readonly ObjectiveDecomposerService $decomposer,
        private readonly WorkOrderFactoryService $workOrderFactory,
        private readonly MissionLifecycleService $lifecycle,
        private readonly ?DevPlanRunFacade $devFacade = null,
    ) {}

    /**
     * Translate a programming prompt into Mission/Objective/WorkOrder, dispatching
     * to the appropriate `programming.*` capability. Does NOT execute Dev internals;
     * caller is responsible for invoking AtlasDevRuntimeService with the produced
     * WorkOrder.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function adapt(string $rawPrompt, array $options = []): array
    {
        $capability = ProgrammingDomainKernelCanon::classifyDevCapability($rawPrompt);
        $missionType = (string) ($options['mission_type'] ?? $this->inferMissionType($rawPrompt, $capability));
        $autonomyLevel = (string) ($options['autonomy_level'] ?? 'execute_with_approval');
        $riskLevel = (string) ($options['risk_level'] ?? $this->defaultRiskLevelForCapability($capability));

        $mission = $this->missionFactory->create($rawPrompt, [
            'mission_type' => $missionType,
            'autonomy_level' => $autonomyLevel,
            'risk_level' => $riskLevel,
            'primary_domain' => ProgrammingDomainKernelCanon::DOMAIN_ID,
            'secondary_domains' => $options['secondary_domains'] ?? null,
            'context_summary' => 'Atlas Dev programming adapter v1 — capability='.$capability,
        ]);

        $objectives = $this->decomposer->decompose($mission);
        $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'programming_adapter']);

        $workOrders = $this->workOrderFactory->plan($mission);

        $missionContractHash = MissionCanonicalHash::sha256([
            'mission_id' => $mission->id,
            'capability' => $capability,
            'mission_type' => $missionType,
            'risk_level' => $riskLevel,
            'autonomy_level' => $autonomyLevel,
            'objectives' => $objectives->pluck('id')->all(),
            'work_orders' => $workOrders->pluck('id')->all(),
        ]);

        $this->lifecycle->recordEvent(
            $mission,
            'programming.adapter.mission_built',
            'programming_adapter',
            [
                'capability' => $capability,
                'mission_type' => $missionType,
                'risk_level' => $riskLevel,
                'autonomy_level' => $autonomyLevel,
                'task_contract_hash' => $missionContractHash,
            ],
            null,
            $mission->status,
            $missionContractHash,
        );

        return [
            'mission' => $mission,
            'objectives' => $objectives,
            'work_orders' => $workOrders,
            'capability' => $capability,
            'mission_type' => $missionType,
            'risk_level' => $riskLevel,
            'autonomy_level' => $autonomyLevel,
            'task_contract_hash' => $missionContractHash,
        ];
    }

    /**
     * Build the execution payload that AtlasDevRuntimeService (left intact) would consume.
     * The Programming Adapter does NOT call AtlasDevRuntimeService directly here — this
     * method only assembles the contract envelope so the runtime can be invoked later
     * by the appropriate flow without reaching into Atlas Dev internals.
     *
     * @return array<string,mixed>
     */
    public function buildDevExecutionRequest(AiMission $mission, AiObjective $objective, AiWorkOrder $workOrder, string $capability, string $taskContractHash): array
    {
        return [
            'schema' => 'atlas.ai.programming.dev_execution_request.v1',
            'mission_id' => $mission->id,
            'objective_id' => $objective->id,
            'work_order_id' => $workOrder->id,
            'capability' => $capability,
            'risk_level' => $mission->risk_level,
            'autonomy_level' => $mission->autonomy_level,
            'task_contract_hash' => $taskContractHash,
            'instructions' => $workOrder->instructions,
            'expected_artifacts' => $workOrder->expected_artifacts,
            'expected_tests' => $workOrder->expected_tests,
        ];
    }

    /**
     * Execute a Mission work order through the canonical Dev plan/run facade.
     *
     * The Mission layer owns lifecycle/evidence; the Dev facade owns planning,
     * authority binding, provider access and Kernel execution. This bridge only
     * translates the already-created Mission contract into the typed Dev intent.
     */
    public function executeViaDevFacade(
        AiMission $mission,
        AiObjective $objective,
        AiWorkOrder $workOrder,
        string $capability,
        string $taskContractHash,
        string $workspace,
        string $operatorId,
        string $authorityHash,
        bool $mutate = false,
    ): DevRunResult {
        foreach ([
            'task_contract_hash' => $taskContractHash,
            'authority_hash' => $authorityHash,
        ] as $name => $hash) {
            if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('mission_dev_'.$name.'_invalid');
            }
        }
        if (trim($workspace) === '' || trim($operatorId) === '') {
            throw new InvalidArgumentException('mission_dev_workspace_operator_required');
        }

        $isObra = $mission->mission_type === MissionFactoryService::TYPE_OBRA;
        $intent = DevIntent::fromArray([
            'raw_goal' => trim((string) ($workOrder->instructions ?: $mission->raw_prompt ?: $objective->description ?: $capability)),
            'workspace' => trim($workspace),
            'operator_id' => trim($operatorId),
            'product_intent_hash' => MissionCanonicalHash::sha256([
                'mission_id' => $mission->id,
                'objective_id' => $objective->id,
                'capability' => $capability,
            ]),
            'spec_hash' => $taskContractHash,
            'world_model_snapshot_hash' => MissionCanonicalHash::sha256([
                'workspace' => trim($workspace),
                'mission_id' => $mission->id,
                'work_order_id' => $workOrder->id,
                'receipt_hash' => $workOrder->receipt_hash,
            ]),
            'authority_hash' => $authorityHash,
            'risk_class' => $this->riskClass($mission->risk_level),
            'duration_regime' => $isObra ? 'obra' : 'durable_task',
            'topology' => $isObra ? 'DAG' : 'workcell',
            'mutate' => $mutate,
            'constraints' => array_values(array_filter([
                ...((array) $workOrder->expected_tests),
                ...((array) $workOrder->rollback_plan),
            ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')),
        ]);

        $facade = $this->devFacade ?? app(DevPlanRunFacade::class);
        $plan = $facade->plan($intent);
        $run = ConfirmedDevRun::fromIntent($intent, trim($operatorId), $authorityHash);

        return $facade->run($run, $plan);
    }

    private function defaultRiskLevelForCapability(string $capability): string
    {
        return match ($capability) {
            'programming.refactor',
            'programming.database',
            'programming.security' => 'high',
            default => 'medium',
        };
    }

    private function riskClass(?string $riskLevel): string
    {
        return match (strtolower(trim((string) $riskLevel))) {
            'low' => 'R1',
            'medium' => 'R3',
            'high', 'critical' => 'R5',
            default => 'R5',
        };
    }

    private function inferMissionType(string $rawPrompt, string $capability): string
    {
        $escalation = ProgrammingDomainKernelCanon::shouldEscalateToForge($rawPrompt);
        if ($escalation !== null) {
            return MissionFactoryService::TYPE_OBRA;
        }

        $base = $this->missionFactory->classify($rawPrompt);
        if ($base === MissionFactoryService::TYPE_TRIVIAL) {
            return MissionFactoryService::TYPE_TASK;
        }

        return $base;
    }
}
